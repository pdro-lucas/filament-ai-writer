<?php

namespace PdroLucas\FilamentAiWriter\Usage;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use PdroLucas\FilamentAiWriter\Data\GenerateResult;
use PdroLucas\FilamentAiWriter\Data\GenerationContext;
use PdroLucas\FilamentAiWriter\Exceptions\GenerationFailure;
use PdroLucas\FilamentAiWriter\Models\UsageAttempt;
use PdroLucas\FilamentAiWriter\Support\ProviderResponse;
use Throwable;

class UsageRecorder
{
  public function begin(GenerationContext $context): void
  {
    try {
      $query = $this->query();
      if ($query->getConnection()->transactionLevel() !== 0) {
        throw new GenerationFailure('transaction');
      }
      $query->insert([
        'operation_id' => $context->operationId, 'attempt_number' => 1,
        'panel_id' => $context->panelId, 'actor_type' => $context->actorType, 'actor_id' => $context->actorId,
        'field' => $context->field, 'action_name' => $context->actionName,
        'started_at' => $context->startedAt, 'status' => 'pending',
        'provider' => $context->configuredProvider, 'requested_model' => $context->requestedModel,
      ]);
    } catch (GenerationFailure $failure) {
      throw $failure;
    } catch (UniqueConstraintViolationException) {
      throw new GenerationFailure('duplicate');
    } catch (Throwable) {
      throw new GenerationFailure('storage');
    }
  }

  /** Freeze the first received response before attempting any field mutation. */
  public function received(GenerationContext $context, array $quote): bool
  {
    try {
      return $this->scoped($context)->where('status', 'pending')->update([
        ...$this->metadata($quote), 'status' => 'received', 'received_at' => CarbonImmutable::now('UTC'),
      ]) === 1;
    } catch (Throwable) {
      throw new GenerationFailure('storage');
    }
  }

  /** HTTP has already finished; this transaction contains only the local delivery and final write. */
  public function delivered(GenerationContext $context, GenerateResult $result, Closure $deliver, Closure $restore): bool
  {
    $applied = false;
    try {
      return $this->query()->getConnection()->transaction(function () use ($context, $result, $deliver, &$applied): bool {
        if (!$this->scoped($context)->where('status', 'received')->lockForUpdate()->first()) {
          return false;
        }
        $applied = true;
        try {
          $deliver($result);
        } catch (GenerationFailure $failure) {
          throw $failure;
        } catch (Throwable) {
          throw new GenerationFailure('delivery', result: $result);
        }
        $now = CarbonImmutable::now('UTC');
        if ($this->scoped($context)->where('status', 'received')->update([
          'status' => 'delivered', 'completed_at' => $now, 'delivered_at' => $now,
          'local_duration_ms' => $context->localDuration($result->durationMs),
        ]) !== 1) {
          throw new GenerationFailure('storage');
        }
        return true;
      });
    } catch (Throwable $error) {
      if ($applied) {
        try {
          $restore();
        } catch (Throwable) {
          throw new GenerationFailure('delivery', result: $result);
        }
      }
      throw $error instanceof GenerationFailure ? $error : new GenerationFailure('storage', result: $result);
    }
  }

  public function failed(GenerationContext $context, GenerationFailure $failure, ?array $quote = null): bool
  {
    try {
      return $this->query()->getConnection()->transaction(function () use ($context, $failure, $quote): bool {
        $row = $this->scoped($context)->whereIn('status', ['pending', 'received'])->lockForUpdate()->first();
        if ($row === null) {
          return false;
        }
        $metadata = $row->status === 'pending' && ($quote !== null || $failure->result !== null)
          ? $this->metadata($quote ?? app(CostCalculator::class)->quote($failure->result)) : [];
        return $this->scoped($context)->whereIn('status', ['pending', 'received'])->update([
          ...$metadata, 'status' => 'failed', 'failure_code' => $failure->failureCode,
          'http_status' => $failure->httpStatus, 'outcome_unknown' => $failure->outcomeUnknown,
          'completed_at' => CarbonImmutable::now('UTC'),
          'duration_ms' => $row->duration_ms ?? $failure->result?->durationMs ?? $failure->durationMs,
          'local_duration_ms' => $context->localDuration($row->duration_ms ?? $failure->result?->durationMs ?? $failure->durationMs),
          'request_id' => $row->request_id ?? $metadata['request_id'] ?? ProviderResponse::label($failure->requestId),
          'response_id' => $row->response_id ?? $metadata['response_id'] ?? ProviderResponse::label($failure->responseId),
        ]) === 1;
      });
    } catch (Throwable) {
      throw new GenerationFailure('storage');
    }
  }

  protected function query(): Builder
  {
    $model = new UsageAttempt();
    return $model->getConnection()->table($model->getTable());
  }

  private function scoped(GenerationContext $context): Builder
  {
    return $this->query()->where('operation_id', $context->operationId)->where('attempt_number', 1)
      ->where('panel_id', $context->panelId)->where('actor_type', $context->actorType)->where('actor_id', $context->actorId)
      ->where('field', $context->field)->where('action_name', $context->actionName);
  }

  private function metadata(array $quote): array
  {
    $result = $quote['result'];
    return [
      'provider' => $result->provider, 'requested_model' => ProviderResponse::label($result->requestedModel),
      'model' => ProviderResponse::label($result->model), 'outcome' => $result->outcome,
      'finish_reason' => ProviderResponse::label($result->finishReason),
      'response_id' => ProviderResponse::label($result->responseId), 'request_id' => ProviderResponse::label($result->requestId),
      'input_tokens' => $result->inputTokens, 'output_tokens' => $result->outputTokens,
      'cached_input_tokens' => $result->cachedInputTokens, 'cache_write_input_tokens' => $result->cacheWriteInputTokens,
      'reasoning_output_tokens' => $result->reasoningOutputTokens, 'duration_ms' => $result->durationMs,
      'cost_usd' => $result->costUsd, 'cost_source' => $result->costSource,
      'input_rate_usd_per_million' => $quote['input_rate'], 'output_rate_usd_per_million' => $quote['output_rate'],
    ];
  }
}
