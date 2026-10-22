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
use Throwable;

class Generation
{
  public function __construct(private UsageRecorder $recorder, private CostCalculator $costs) {}

  /** Context is resolved on the server. Restore must undo delivery if its final ledger write fails. */
  public function run(string $systemPrompt, string $userInput, Closure $deliver, Closure $restore, string $field, ?string $operationId = null, ?string $actionName = null): GenerateResult
  {
    if (!AiWriterPlugin::get()->canUseAi()) {
      throw new AuthorizationException('You are not authorized to use AI Writer.');
    }
    $admission = AiWriterPlugin::get()->controls()->admit();
    $context = new GenerationContext($operationId ?? (string) \Illuminate\Support\Str::uuid(), $admission->panelId,
      $admission->actor->type, $admission->actor->id, $field, $actionName);
    $this->recorder->begin($context);
    $result = null;
    $quote = null;
    try {
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
        $failure = new GenerationFailure($failure->failureCode, $failure->httpStatus, $failure->requestId, $failure->responseId, $result->durationMs, $failure->outcomeUnknown, $result);
      }
      $this->recorder->failed($context, $failure, $quote);
      throw $failure;
    } catch (Throwable) {
      $failure = new GenerationFailure('provider', outcomeUnknown: true);
      $this->recorder->failed($context, $failure, $quote);
      throw $failure;
    }
  }
}
