<?php

namespace PdroLucas\FilamentAiWriter\Generation;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Contracts\AiProvider;
use PdroLucas\FilamentAiWriter\Data\GenerateResult;
use PdroLucas\FilamentAiWriter\Data\GenerationContext;
use PdroLucas\FilamentAiWriter\Usage\UsageRecorder;
use PdroLucas\FilamentAiWriter\Usage\CostCalculator;
use PdroLucas\FilamentAiWriter\Exceptions\GenerationFailure;
use PdroLucas\FilamentAiWriter\Support\FailureCause;
use Throwable;

class Generation
{
  public function __construct(private UsageRecorder $recorder, private CostCalculator $costs) {}

  /** Context is resolved on the server. Restore must undo delivery if its final ledger write fails. */
  public function run(string $systemPrompt, string $userInput, Closure $deliver, Closure $restore, string $field, ?string $operationId = null, ?string $actionName = null): GenerateResult
  {
    $plugin = AiWriterPlugin::current();
    if ($plugin !== null && !$plugin->canUseAi()) {
      throw new AuthorizationException('You are not authorized to use AI Writer.');
    }
    if ($plugin === null || !$plugin->isUsageTrackingEnabled()) {
      return $this->runCore($systemPrompt, $userInput, $deliver, $restore);
    }
    if ($plugin->isControlsEnabled()) {
      // Availability, blocks, credits and abuse protection are admitted under one scope lock.
      $admission = $plugin->controlsService()->admit();
      $context = new GenerationContext($operationId ?? (string) \Illuminate\Support\Str::uuid(), $admission->panelId,
        $admission->actor->type, $admission->actor->id, $field, $actionName);
      $this->recorder->begin($context, $admission);
    } else {
      // Ledger only: deduplicated and deadline-fenced, without reading or reserving controls state.
      $context = GenerationContext::capture($field, $operationId, $actionName);
      $this->recorder->begin($context, fenced: true);
    }
    $result = null;
    $quote = null;
    try {
      $this->recorder->assertLive($context);
      $quote = $this->costs->quote(app(AiProvider::class)->generate($systemPrompt, $userInput));
      $result = $quote['result'];
      if (!$this->recorder->received($context, $quote)) {
        throw new GenerationFailure('duplicate');
      }
      if ($result->outcome !== GenerateResult::TEXT) {
        throw new GenerationFailure($result->outcome, requestId: $result->requestId, responseId: $result->responseId, durationMs: $result->durationMs, result: $result);
      }
      if (!$this->recorder->delivered($context, $result, $deliver, $restore)) {
        throw new GenerationFailure('duplicate');
      }
      return $result;
    } catch (GenerationFailure $failure) {
      if ($failure->result === null && $result !== null) {
        $original = $failure;
        $failure = new GenerationFailure($failure->failureCode, $failure->httpStatus, $failure->requestId, $failure->responseId, $result->durationMs, $failure->outcomeUnknown, $result, $failure->renewsAt);
        FailureCause::transfer($original, $failure);
      }
      $this->recorder->failed($context, $failure, $quote);
      throw $failure;
    } catch (Throwable $error) {
      $failure = FailureCause::remember(new GenerationFailure('provider', outcomeUnknown: true), $error);
      $this->recorder->failed($context, $failure, $quote);
      throw $failure;
    }
  }

  /** Standalone path: provider call and delivery only, without plugin storage. */
  private function runCore(string $systemPrompt, string $userInput, Closure $deliver, Closure $restore): GenerateResult
  {
    try {
      $result = app(AiProvider::class)->generate($systemPrompt, $userInput);
    } catch (GenerationFailure $failure) {
      throw $failure;
    } catch (Throwable $error) {
      throw FailureCause::remember(new GenerationFailure('provider', outcomeUnknown: true), $error);
    }
    if ($result->outcome !== GenerateResult::TEXT) {
      throw new GenerationFailure($result->outcome, requestId: $result->requestId, responseId: $result->responseId, durationMs: $result->durationMs, result: $result);
    }
    try {
      $deliver($result);
    } catch (Throwable $error) {
      $restore();
      throw $error instanceof GenerationFailure ? $error : new GenerationFailure('delivery', result: $result);
    }
    return $result;
  }
}
