<?php

namespace PdroLucas\FilamentAiWriter\Credits;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Support\Reason;
use PdroLucas\FilamentAiWriter\Controls\ActorIdentity;
use PdroLucas\FilamentAiWriter\Controls\ControlContext;
use PdroLucas\FilamentAiWriter\Data\GenerationContext;
use PdroLucas\FilamentAiWriter\Exceptions\GenerationFailure;
use PdroLucas\FilamentAiWriter\Support\FailureCause;
use PdroLucas\FilamentAiWriter\Models\ControlAudit;
use PdroLucas\FilamentAiWriter\Models\UsageAttempt;
use Throwable;

/** Durable quota accounting. Every mutation locks scope before balance and operation. */
final class Credits
{
  private const PREFIX = 'filament_ai_writer_';
  // Portable unsigned INTEGER range; arithmetic uses PHP 64-bit integers.
  public const MAX_CREDITS = 2147483647;

  public function connection(): Connection
  {
    $connection = DB::connection();
    if ((new UsageAttempt())->getConnection() !== $connection || (new ControlAudit())->getConnection() !== $connection) {
      throw new GenerationFailure('storage');
    }
    return $connection;
  }

  private function table(string $table): \Illuminate\Database\Query\Builder
  {
    return $this->connection()->table(self::PREFIX . $table);
  }

  /** Internal shared admission lock; caller must own a transaction. */
  public function lockScope(string $key, string $scope): object
  {
    // Lock an existing row first: on MySQL, INSERT IGNORE over an existing key takes a shared lock,
    // and concurrent shared-to-exclusive upgrades deadlock. Insert only when the row is missing.
    $policy = $this->table('policies')->where('scope_key', $key)->lockForUpdate()->first();
    if ($policy === null) {
      $this->table('policies')->insertOrIgnore(['scope_key' => $key, 'scope' => $scope, 'enabled' => true]);
      $policy = $this->table('policies')->where('scope_key', $key)->lockForUpdate()->first();
    }
    if ($policy === null || $policy->scope !== $scope) {
      throw new GenerationFailure('storage');
    }
    return $policy;
  }

  /** Internal ledger seam: admission and begin share this durable transaction. */
  public function start(GenerationContext $generation, ?ControlContext $context, Closure $begin, bool $fenced = false): void
  {
    try {
      if ($this->connection()->transactionLevel() !== 0) {
        throw new GenerationFailure('transaction');
      }
      $refusal = $this->connection()->transaction(function () use ($generation, $context, $begin, $fenced): ?string {
        $balance = null;
        $deadline = $context === null && $fenced ? $this->deadline() : null;
        if ($context !== null) {
          if ($context->panelId !== $generation->panelId || $context->actor->type !== $generation->actorType || $context->actor->id !== $generation->actorId) {
            throw new GenerationFailure('controls');
          }
          $policy = $this->lockScope($context->scopeKey(), $context->scope);
          $decisionAt = CarbonImmutable::now('UTC');
          AiWriterPlugin::get()->controlsService()->assertAdmission($context);
          if ($this->table('operations')->where('operation_id', $generation->operationId)->exists()) {
            throw new GenerationFailure('duplicate');
          }
          $this->recoverExpiredActor($context, $decisionAt);
          $refusal = AiWriterPlugin::get()->abuse()->admissionFailure($policy, $context, $decisionAt);
          if ($refusal !== null) {
            return $refusal; // Commit recovery even when this admission is refused.
          }
          $deadline = $this->deadline();
          if ((bool) $policy->quota_enabled) {
            $this->validPolicy($policy);
            $balance = $this->currentBalance($policy, $context->actor);
            $snapshot = $this->snapshot($policy, $context->actor, $balance);
            if ($snapshot['available'] < 1) {
              throw new GenerationFailure('credits', renewsAt: $snapshot['renews_at'] === null ? null : CarbonImmutable::parse($snapshot['renews_at'], 'UTC'));
            }
            $this->table('credit_balances')->where('id', $balance->id)->increment('reserved');
          }
        }
        $this->table('operations')->insert([
          'operation_id' => $generation->operationId, 'scope_key' => $context?->scopeKey(),
          'actor_key' => $context?->actor->key(), 'guard' => $context?->guard, 'panel_id' => $generation->panelId,
          'actor_type' => $generation->actorType, 'actor_id' => $generation->actorId,
          'field' => $generation->field, 'action_name' => $generation->actionName,
          'balance_id' => $balance?->id, 'deadline_at' => $deadline?->format('Y-m-d H:i:s.u'), 'status' => 'pending', 'started_at' => $context === null ? $generation->startedAt : CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
        $begin();
        return null;
      }, 3);
      if ($refusal !== null) {
        throw new GenerationFailure($refusal);
      }
    } catch (UniqueConstraintViolationException) {
      throw new GenerationFailure('duplicate');
    } catch (Throwable $error) {
      throw $error instanceof GenerationFailure ? $error : FailureCause::remember(new GenerationFailure('storage'), $error);
    }
  }

  /** Internal ledger seam: callback and terminal accounting use exactly one connection. */
  public function transition(GenerationContext $context, string $target, Closure $write): bool
  {
    if (!in_array($target, ['received', 'delivered', 'failed', 'check', 'recover'], true)) {
      throw new GenerationFailure('configuration');
    }
    $expired = false;
    try {
      $accepted = $this->connection()->transaction(function () use ($context, $target, $write, &$expired): bool {
        $operation = $this->table('operations')->where('operation_id', $context->operationId)->first();
        if ($operation === null) {
          return false;
        }
        if ($operation->scope_key !== null) {
          $policy = $this->table('policies')->where('scope_key', $operation->scope_key)->first();
          if ($policy === null) {
            throw new GenerationFailure('storage');
          }
          $this->lockScope($operation->scope_key, $policy->scope);
        }
        $balance = $operation->balance_id === null ? null : $this->table('credit_balances')->where('id', $operation->balance_id)->lockForUpdate()->first();
        $operation = $this->table('operations')->where('operation_id', $context->operationId)->lockForUpdate()->first();
        if ($operation->panel_id !== $context->panelId || $operation->actor_type !== $context->actorType || $operation->actor_id !== $context->actorId
          || $operation->field !== $context->field || $operation->action_name !== $context->actionName) {
          return false;
        }
        if ($operation->status === 'recovered') {
          $expired = $target !== 'recover' && $target !== 'failed';
          return false;
        }
        if (!in_array($operation->status, ['pending', 'received'], true)) {
          return false;
        }
        if ($operation->balance_id !== null && ($balance === null || (int) $balance->reserved < 1)) {
          throw new GenerationFailure('storage');
        }
        if ($this->isExpired($operation)) {
          $this->expire($operation, $balance);
          $expired = $target !== 'recover' && $target !== 'failed';
          return $target === 'recover' || $target === 'failed';
        }
        if ($target === 'recover') {
          return false;
        }
        if ($target === 'check') {
          return true;
        }
        $allowed = $target === 'received' ? ['pending'] : ($target === 'delivered' ? ['received'] : ['pending', 'received']);
        if (!in_array($operation->status, $allowed, true) || !$write()) {
          return false;
        }
        // A slow local delivery must not cross its frozen execution deadline.
        if ($target === 'delivered' && $this->isExpired($operation)) {
          throw new GenerationFailure('expired');
        }
        if ($target !== 'received' && $balance !== null) {
          $this->table('credit_balances')->where('id', $balance->id)->update([
            'reserved' => (int) $balance->reserved - 1,
            'consumed' => (int) $balance->consumed + ($target === 'delivered' ? 1 : 0),
          ]);
        }
        $this->table('operations')->where('operation_id', $context->operationId)->update([
          'status' => $target, 'completed_at' => $target === 'received' ? null : CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
        return true;
      }); // Never retry callbacks that mutate a field.
    } catch (GenerationFailure $error) {
      if ($error->failureCode === 'expired') {
        // The failed delivery transaction has rolled back before recovery commits.
        $this->recoverOperation($context->operationId);
      }
      throw $error;
    }
    if ($expired) {
      throw new GenerationFailure('expired'); // Throw after the release transaction commits.
    }
    return $accepted;
  }

  private function deadline(): CarbonImmutable
  {
    $timeout = config('filament-ai-writer.timeout');
    $margin = config('filament-ai-writer.recovery.margin_seconds');
    foreach ([$timeout, $margin] as $value) {
      if ((!is_int($value) && !(is_string($value) && ctype_digit($value)))
        || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => self::MAX_CREDITS]]) === false) {
        throw new GenerationFailure('configuration');
      }
    }
    if ((int) $timeout === 0) {
      throw new GenerationFailure('configuration');
    }
    return CarbonImmutable::now('UTC')->addSeconds((int) $timeout + (int) $margin);
  }

  private function isExpired(object $operation): bool
  {
    return $operation->deadline_at !== null && CarbonImmutable::now('UTC')->gte(CarbonImmutable::parse($operation->deadline_at, 'UTC'));
  }

  private function expire(object $operation, ?object $balance): void
  {
    if ($balance !== null) {
      $this->table('credit_balances')->where('id', $balance->id)->decrement('reserved');
    }
    $now = CarbonImmutable::now('UTC');
    $this->table('operations')->where('operation_id', $operation->operation_id)->update(['status' => 'recovered', 'completed_at' => $now]);
    $model = new UsageAttempt();
    $usage = $this->connection()->table($model->getTable())->where('operation_id', $operation->operation_id)->whereIn('status', ['pending', 'received']);
    $row = (clone $usage)->lockForUpdate()->first();
    if ($row !== null) {
      $usage->update([
        'status' => 'failed', 'failure_code' => 'expired',
        'outcome_unknown' => $row->received_at === null ? true : (bool) $row->outcome_unknown,
        'completed_at' => $now,
      ]);
    }
  }

  /** Maintenance only: no authorization to inspect balances or actor directories. */
  public function recoverExpiredOperations(): int
  {
    $count = 0;
    $now = CarbonImmutable::now('UTC');
    $this->table('operations')->whereIn('status', ['pending', 'received'])->where('deadline_at', '<=', $now->format('Y-m-d H:i:s.u'))
      ->orderBy('operation_id')->chunkById(500, function ($operations) use (&$count): void {
        foreach ($operations as $operation) {
          $count += $this->recoverOperation($operation->operation_id) ? 1 : 0;
        }
      }, 'operation_id');
    return $count;
  }

  /** Internal: scope lock is already held; recover each original balance and operation. */
  public function recoverExpiredActor(ControlContext $context, CarbonImmutable $decisionAt): void
  {
    $this->table('operations')->where('scope_key', $context->scopeKey())->where('actor_key', $context->actor->key())
      ->where('actor_type', $context->actor->type)->where('actor_id', $context->actor->id)
      ->whereIn('status', ['pending', 'received'])->where('deadline_at', '<=', $decisionAt->format('Y-m-d H:i:s.u'))
      ->orderBy('operation_id')->pluck('operation_id')->each(fn ($id) => $this->recoverOperation($id));
  }

  private function recoverOperation(string $operationId): bool
  {
    $operation = $this->table('operations')->where('operation_id', $operationId)->first();
    if ($operation === null) {
      return false;
    }
    $context = new GenerationContext($operation->operation_id, $operation->panel_id, $operation->actor_type, $operation->actor_id, $operation->field, $operation->action_name);
    return $this->transition($context, 'recover', fn () => false);
  }

  /** Internal indicator gate: one unlocked policy read; no balance, period or abuse table is touched. */
  public function quotaEnabled(ControlContext $context): bool
  {
    return (bool) ($this->table('policies')->where('scope_key', $context->scopeKey())->value('quota_enabled') ?? false);
  }

  public function policy(ControlContext $context): array
  {
    AiWriterPlugin::get()->controlsService()->authorize($context);
    return $this->connection()->transaction(function () use ($context): array {
      $policy = $this->lockScope($context->scopeKey(), $context->scope);
      AiWriterPlugin::get()->controlsService()->authorize($context);
      $period = $policy->default_limit === null ? null : $this->period($policy);
      return $this->policySnapshot($policy) + ['renews_at' => $period?->ends_at, 'period_started_at' => $period?->starts_at, 'period_timezone' => $period?->timezone];
    });
  }

  public function configure(ControlContext $context, bool $enabled, mixed $limit, string $renewal, ?string $reason = null): void
  {
    $this->integer($limit);
    if (!in_array($renewal, ['manual', 'daily', 'weekly', 'monthly'], true)) {
      throw ValidationException::withMessages(['renewal' => __('filament-ai-writer::controls.validation.renewal')]);
    }
    $this->admin($context, null, 'quota_policy', $reason, function ($policy) use ($enabled, $limit, $renewal): void {
      $previousRenewal = $policy->renewal;
      // Close an elapsed old period before scheduling the new calendar.
      $period = $policy->default_limit === null ? null : $this->period($policy);
      $this->table('policies')->where('scope_key', $policy->scope_key)->update([
        'quota_enabled' => $enabled, 'default_limit' => $limit, 'renewal' => $renewal,
      ]);
      $policy->default_limit = $limit;
      $policy->renewal = $renewal;
      if ($period === null) {
        $this->newPeriod($policy);
      } elseif ($renewal !== $previousRenewal) {
        // Changing the schedule keeps the balance: no new period, consumption, reservations and extras stay. Only the
        // current period's end moves, to the next cut of the new schedule from now (none for manual).
        $this->table('credit_periods')->where('id', $period->id)->update([
          'renewal' => $renewal, 'ends_at' => $this->cutoff($renewal, $period->timezone),
        ]);
      }
    });
  }

  public function setLimit(ControlContext $context, ActorIdentity $actor, mixed $limit, ?string $reason = null): void
  {
    if ($limit !== null) {
      $this->integer($limit);
    }
    $this->admin($context, $actor, 'quota_limit', $reason, function ($policy) use ($actor, $limit): void {
      $this->actor($policy, $actor);
      $this->table('credit_actors')->where('scope_key', $policy->scope_key)->where('actor_key', $actor->key())->update(['limit' => $limit]);
    });
  }

  public function grant(ControlContext $context, ActorIdentity $actor, mixed $amount, ?string $reason = null): void
  {
    $this->integer($amount, 1);
    $this->admin($context, $actor, 'quota_extra', $reason, function ($policy) use ($actor, $amount): void {
      $this->validPolicy($policy);
      $balance = $this->currentBalance($policy, $actor);
      if ((int) $balance->extras > self::MAX_CREDITS - $amount) {
        throw ValidationException::withMessages(['amount' => __('filament-ai-writer::controls.validation.credits_range')]);
      }
      $this->table('credit_balances')->where('id', $balance->id)->increment('extras', $amount);
    });
  }

  public function reset(ControlContext $context, ActorIdentity $actor, ?string $reason = null): void
  {
    $this->admin($context, $actor, 'quota_reset', $reason, function ($policy) use ($actor): void {
      $this->validPolicy($policy);
      $period = $this->period($policy);
      $this->newBalance($policy, $actor, $period->id);
    });
  }

  public function renew(ControlContext $context, ?string $reason = null): void
  {
    $this->admin($context, null, 'quota_renew', $reason, function ($policy): void {
      $this->validPolicy($policy);
      $this->newPeriod($policy);
    });
  }

  /**
   * Credit position of several directory actors with a fixed number of reads and no writes (no balance or period is
   * created, unlike balance()). An elapsed period reads as unused until the next generation or detail view rolls it.
   *
   * @param array<int, ActorIdentity> $actors
   * @return array<string, array{limit: ?int, consumed: int, reserved: int, extras: int, available: ?int}> by actor key
   */
  public function overview(ControlContext $context, array $actors): array
  {
    AiWriterPlugin::get()->controlsService()->authorize($context);
    $policy = $this->table('policies')->where('scope_key', $context->scopeKey())->first();
    $keys = array_map(fn (ActorIdentity $actor): string => $actor->key(), $actors);
    $default = $policy?->default_limit === null ? null : (int) $policy->default_limit;
    $settings = $keys === [] ? collect() : $this->table('credit_actors')->where('scope_key', $context->scopeKey())->whereIn('actor_key', $keys)->get()->keyBy('actor_key');
    $period = $policy?->active_period === null ? null : $this->table('credit_periods')->where('id', $policy->active_period)->first();
    $live = $period !== null && ($period->ends_at === null || CarbonImmutable::now('UTC')->lt(CarbonImmutable::parse($period->ends_at, 'UTC')));
    $balanceIds = $live ? $settings->pluck('active_balance')->filter()->all() : [];
    $balances = $balanceIds === [] ? collect() : $this->table('credit_balances')->whereIn('id', $balanceIds)->where('period_id', $period->id)->get()->keyBy('id');
    $overview = [];
    foreach ($keys as $key) {
      $row = $settings->get($key);
      $balance = $row?->active_balance === null ? null : $balances->get($row->active_balance);
      $limit = $row?->limit ?? $default;
      $limit = $limit === null ? null : (int) $limit;
      [$extras, $consumed, $reserved] = [(int) ($balance?->extras ?? 0), (int) ($balance?->consumed ?? 0), (int) ($balance?->reserved ?? 0)];
      $overview[$key] = ['limit' => $limit, 'consumed' => $consumed, 'reserved' => $reserved, 'extras' => $extras,
        'available' => $limit === null ? null : max(0, $limit + $extras - $consumed - $reserved)];
    }

    return $overview;
  }

  public function balance(ControlContext $context, ActorIdentity $actor): array
  {
    $this->authorizeActor($context, $actor);
    return $this->readBalance($context, $actor);
  }

  /** No actor parameter: a generator can read only its own freshly resolved scope. */
  public function ownBalance(ControlContext $context): array
  {
    $plugin = AiWriterPlugin::get();
    abort_unless($plugin->canUseAi() && $plugin->controlContext()->snapshot() === $context->snapshot(), 403);
    return $this->readBalance($context, $context->actor);
  }

  private function readBalance(ControlContext $context, ActorIdentity $actor): array
  {
    return $this->connection()->transaction(function () use ($context, $actor): array {
      $policy = $this->lockScope($context->scopeKey(), $context->scope);
      return $this->snapshot($policy, $actor, $policy->default_limit === null ? null : $this->currentBalance($policy, $actor));
    }, 3);
  }

  private function authorizeActor(ControlContext $context, ActorIdentity $actor): void
  {
    AiWriterPlugin::get()->controlsService()->authorize($context);
    if (AiWriterPlugin::get()->resolveActor($actor->token(), $context) === null) {
      throw ValidationException::withMessages(['actor' => __('filament-ai-writer::controls.validation.actor')]);
    }
  }

  private function admin(ControlContext $context, ?ActorIdentity $actor, string $action, ?string $reason, Closure $change): void
  {
    AiWriterPlugin::get()->controlsService()->authorize($context);
    if ($actor !== null) {
      $this->authorizeActor($context, $actor);
    }
    $reason = Reason::resolve($action, $reason);
    $this->connection()->transaction(function () use ($context, $actor, $action, $reason, $change): void {
      AiWriterPlugin::get()->controlsService()->authorize($context);
      if ($actor !== null) {
        $this->authorizeActor($context, $actor);
      }
      $policy = $this->lockScope($context->scopeKey(), $context->scope);
      $before = $actor === null ? $this->policySnapshot($policy) : $this->snapshot($policy, $actor, $policy->default_limit === null ? null : $this->currentBalance($policy, $actor));
      $change($policy);
      $policy = $this->table('policies')->where('scope_key', $context->scopeKey())->first();
      $after = $actor === null ? $this->policySnapshot($policy) : $this->snapshot($policy, $actor, $policy->default_limit === null ? null : $this->currentBalance($policy, $actor));
      ControlAudit::query()->create([
        'scope_key' => $context->scopeKey(), 'scope' => $context->scope, 'panel_id' => $context->panelId, 'guard' => $context->guard,
        'administrator_type' => $context->actor->type, 'administrator_id' => $context->actor->id,
        'action' => $action, 'actor_type' => $actor?->type, 'actor_id' => $actor?->id,
        'before' => $before, 'after' => $after, 'reason' => $reason, 'created_at' => CarbonImmutable::now('UTC'),
      ]);
    }, 3);
  }

  private function policySnapshot(object $policy): array
  {
    return ['enabled' => (bool) $policy->quota_enabled, 'limit' => $policy->default_limit === null ? null : (int) $policy->default_limit,
      'renewal' => $policy->renewal, 'period_id' => $policy->active_period];
  }

  private function snapshot(object $policy, ActorIdentity $actor, ?object $balance): array
  {
    $settings = $this->table('credit_actors')->where('scope_key', $policy->scope_key)->where('actor_key', $actor->key())->first();
    $limit = $settings?->limit ?? $policy->default_limit;
    $period = $balance === null ? null : $this->table('credit_periods')->where('id', $balance->period_id)->first();
    return ['enabled' => (bool) $policy->quota_enabled, 'limit' => $limit === null ? null : (int) $limit,
      'individual_limit' => $settings?->limit === null ? null : (int) $settings->limit,
      'default_limit' => $policy->default_limit === null ? null : (int) $policy->default_limit,
      'extras' => (int) ($balance?->extras ?? 0), 'consumed' => (int) ($balance?->consumed ?? 0), 'reserved' => (int) ($balance?->reserved ?? 0),
      'available' => $limit === null ? null : max(0, (int) $limit + (int) ($balance?->extras ?? 0) - (int) ($balance?->consumed ?? 0) - (int) ($balance?->reserved ?? 0)),
      'period_id' => $period?->id, 'balance_id' => $balance?->id, 'renews_at' => $period?->ends_at];
  }

  private function integer(mixed $value, int $min = 0): void
  {
    if (!is_int($value) || $value < $min || $value > self::MAX_CREDITS) {
      throw ValidationException::withMessages(['credits' => __('filament-ai-writer::controls.validation.credits_integer', ['min' => $min, 'max' => self::MAX_CREDITS])]);
    }
  }

  private function validPolicy(object $policy): void
  {
    if ($policy->default_limit === null || (int) $policy->default_limit < 0 || (int) $policy->default_limit > self::MAX_CREDITS
      || !in_array($policy->renewal, ['manual', 'daily', 'weekly', 'monthly'], true)) {
      throw new GenerationFailure('configuration');
    }
  }

  private function actor(object $policy, ActorIdentity $actor): object
  {
    $locked = fn () => $this->table('credit_actors')->where('scope_key', $policy->scope_key)->where('actor_key', $actor->key())->lockForUpdate()->first();
    // Same lock-first order as lockScope(): never take a shared lock on an existing row before the exclusive one.
    $row = $locked();
    if ($row === null) {
      $this->table('credit_actors')->insertOrIgnore(['scope_key' => $policy->scope_key, 'actor_key' => $actor->key(), 'actor_type' => $actor->type, 'actor_id' => $actor->id]);
      $row = $locked();
    }
    return $row;
  }

  private function currentBalance(object $policy, ActorIdentity $actor): object
  {
    $this->validPolicy($policy);
    $settings = $this->actor($policy, $actor);
    $period = $this->period($policy);
    $balance = $settings->active_balance === null ? null : $this->table('credit_balances')->where('id', $settings->active_balance)->lockForUpdate()->first();
    return $balance !== null && $balance->period_id === $period->id ? $balance : $this->newBalance($policy, $actor, $period->id);
  }

  private function newBalance(object $policy, ActorIdentity $actor, string $period): object
  {
    $this->actor($policy, $actor);
    $id = (string) Str::uuid();
    $this->table('credit_balances')->insert(['id' => $id, 'period_id' => $period, 'actor_key' => $actor->key()]);
    $this->table('credit_actors')->where('scope_key', $policy->scope_key)->where('actor_key', $actor->key())->update(['active_balance' => $id]);
    return $this->table('credit_balances')->where('id', $id)->first();
  }

  private function period(object $policy): object
  {
    $period = $policy->active_period === null ? null : $this->table('credit_periods')->where('id', $policy->active_period)->first();
    return $period === null || ($period->ends_at !== null && CarbonImmutable::now('UTC')->gte(CarbonImmutable::parse($period->ends_at, 'UTC')))
      ? $this->newPeriod($policy) : $period;
  }

  private function newPeriod(object $policy): object
  {
    $now = CarbonImmutable::now('UTC');
    $end = $this->cutoff($policy->renewal, config('app.timezone'));
    $id = (string) Str::uuid();
    $this->table('credit_periods')->insert(['id' => $id, 'scope_key' => $policy->scope_key, 'renewal' => $policy->renewal,
      'timezone' => config('app.timezone'), 'starts_at' => $now, 'ends_at' => $end]);
    $this->table('policies')->where('scope_key', $policy->scope_key)->update(['active_period' => $id]);
    $policy->active_period = $id;
    return $this->table('credit_periods')->where('id', $id)->first();
  }
  /** The next cut of a schedule from now in a timezone (the period's own, by default the application's); null for manual. Read-only. */
  public function nextCut(string $renewal, ?string $timezone = null): ?CarbonImmutable
  {
    return $this->cutoff($renewal, $timezone ?? config('app.timezone'));
  }

  private function cutoff(string $renewal, string $timezone): ?CarbonImmutable
  {
    $local = CarbonImmutable::now('UTC')->setTimezone($timezone);
    return match ($renewal) {
      'daily' => $local->addDay()->startOfDay()->utc(),
      'weekly' => $local->startOfWeek(CarbonImmutable::MONDAY)->addWeek()->utc(),
      'monthly' => $local->startOfMonth()->addMonth()->utc(),
      'manual' => null,
      default => throw new GenerationFailure('configuration'),
    };
  }

}
