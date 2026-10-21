<?php

namespace PdroLucas\FilamentAiWriter\Generation;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Contracts\AiProvider;
use PdroLucas\FilamentAiWriter\Data\GenerateResult;
use PdroLucas\FilamentAiWriter\Exceptions\GenerationFailure;
use Throwable;

class Generation
{
  /** The form integration supplies only its conversion/delivery step; provider and usage work belongs here. */
  public function run(string $systemPrompt, string $userInput, Closure $deliver): GenerateResult
  {
    if (!AiWriterPlugin::get()->canUseAi()) {
      throw new AuthorizationException('You are not authorized to use AI Writer.');
    }
    $result = app(AiProvider::class)->generate($systemPrompt, $userInput);
    if ($result->outcome !== GenerateResult::TEXT) {
      throw new GenerationFailure($result->outcome, requestId: $result->requestId, responseId: $result->responseId, durationMs: $result->durationMs, result: $result->withoutText());
    }
    try {
      $deliver($result);
    } catch (GenerationFailure $failure) {
      throw $failure;
    } catch (Throwable) {
      throw new GenerationFailure('delivery', requestId: $result->requestId, responseId: $result->responseId, durationMs: $result->durationMs, result: $result->withoutText());
    }
    return $result;
  }
}
