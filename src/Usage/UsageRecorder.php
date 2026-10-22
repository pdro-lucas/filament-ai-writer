<?php

namespace PdroLucas\FilamentAiWriter\Usage;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use PdroLucas\FilamentAiWriter\Data\GenerateResult;
use PdroLucas\FilamentAiWriter\Data\GenerationContext;
use PdroLucas\FilamentAiWriter\Exceptions\GenerationFailure;
use PdroLucas\FilamentAiWriter\Models\UsageAttempt;
use PdroLucas\FilamentAiWriter\Support\ProviderResponse;
use Throwable;

class UsageRecorder
{
  /** Direct ledger callers get durable deduplication, but do not admit generation or reserve quota. */
  public function begin(GenerationContext $context, ?\PdroLucas\FilamentAiWriter\Controls\ControlContext $admission = null): void
  {
    $this->credits()->start($context, $admission, function () use ($context): void {
      $this->query()->insert([
        'operation_id' => $context->operationId, 'attempt_number' => 1,
        'panel_id' => $context->panelId, 'actor_type' => $context->actorType, 'actor_id' => $context->actorId,
        'field' => $context->field, 'action_name' => $context->actionName,
        'started_at' => $context->startedAt, 'status' => 'pending',
        'provider' => $context->configuredProvider, 'requested_model' => $context->requestedModel,
      ]);
    });
  }

  /** Managed generations check the durable deadline again before contacting a provider. */
  public function assertLive(GenerationContext $context): void
  {
    if (!$this->credits()->transition($context, 'check', fn () => true)) {
      throw new GenerationFailure('duplicate');
    }
  }

  /** Freeze the first received response before attempting any field mutation. */
  public function received(GenerationContext $context, array $quote): bool
  {
    try {
      return $this->credits()->transition($context, 'received', fn (): bool => $this->scoped($context)->where('status', 'pending')->update([
        ...$this->metadata($quote), 'status' => 'received', 'received_at' => CarbonImmutable::now('UTC'),
      ]) === 1);
    } catch (Throwable $error) {
      if ($error instanceof GenerationFailure && $error->failureCode === 'expired') {
        $this->lateMetadata($context, [...$this->metadata($quote), 'outcome_unknown' => false]);
      }
      throw $error instanceof GenerationFailure ? $error : new GenerationFailure('storage');
    }
  }

  /** HTTP has already finished; this transaction contains only the local delivery and final write. */
  public function delivered(GenerationContext $context, GenerateResult $result, Closure $deliver, Closure $restore): bool
  {
    if ($this->credits()->connection()->transactionLevel() !== 0) {
      throw new GenerationFailure('transaction');
    }
    $applied = false;
    try {
      return $this->credits()->transition($context, 'delivered', function () use ($context, $result, $deliver, &$applied): bool {
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
      $accepted = $this->credits()->transition($context, 'failed', function () use ($context, $failure, $quote): bool {
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
      $lateQuote = $quote ?? ($failure->result === null ? null : app(CostCalculator::class)->quote($failure->result));
      $this->lateMetadata($context, [
        ...($lateQuote === null ? [] : $this->metadata($lateQuote)),
        'http_status' => $failure->httpStatus, 'request_id' => ProviderResponse::label($failure->requestId ?? $failure->result?->requestId),
        'response_id' => ProviderResponse::label($failure->responseId ?? $failure->result?->responseId),
        'duration_ms' => $failure->result?->durationMs ?? $failure->durationMs,
        'local_duration_ms' => $context->localDuration($failure->result?->durationMs ?? $failure->durationMs),
        'outcome_unknown' => $failure->outcomeUnknown,
      ]);
      return $accepted;
    } catch (Throwable $error) {
      throw $error instanceof GenerationFailure ? $error : new GenerationFailure('storage');
    }
  }

  /** First known late response may enrich existing expired history, never recreate it. */
  private function lateMetadata(GenerationContext $context, array $metadata): void
  {
    try {
      $connection = $this->credits()->connection();
      $operation = $connection->table('filament_ai_writer_operations')->where('operation_id', $context->operationId)->first();
      if ($operation === null || $operation->status !== 'recovered' || $operation->panel_id !== $context->panelId
        || $operation->actor_type !== $context->actorType || $operation->actor_id !== $context->actorId
        || $operation->field !== $context->field || $operation->action_name !== $context->actionName) {
        return;
      }
      $this->scoped($context)->where('status', 'failed')->where('failure_code', 'expired')->whereNull('received_at')->update([
        ...$metadata, 'received_at' => CarbonImmutable::now('UTC'),
      ]);
    } catch (Throwable) {
      throw new GenerationFailure('storage');
    }
  }

  private function credits(): \PdroLucas\FilamentAiWriter\Credits\Credits
  {
    try {
      $credits = app(\PdroLucas\FilamentAiWriter\Credits\Credits::class);
      if ($this->query()->getConnection() !== $credits->connection()) {
        throw new GenerationFailure('storage');
      }
      return $credits;
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
