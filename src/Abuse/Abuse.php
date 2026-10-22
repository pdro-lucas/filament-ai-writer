<?php

namespace PdroLucas\FilamentAiWriter\Abuse;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\ValidationException;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Controls\ActorIdentity;
use PdroLucas\FilamentAiWriter\Controls\ControlContext;
use PdroLucas\FilamentAiWriter\Credits\Credits;
use PdroLucas\FilamentAiWriter\Exceptions\GenerationFailure;
use PdroLucas\FilamentAiWriter\Models\ControlAudit;

/** Admission protections share the quota scope lock and durable operation history. */
final class Abuse
{
  private const FIELDS = [
    'rate_enabled', 'rate_limit', 'rate_window_seconds', 'concurrency_enabled', 'concurrency_limit',
    'failure_enabled', 'failure_limit', 'failure_window_seconds', 'failure_pause_seconds',
  ];

  public function __construct(private Credits $credits) {}

  private function locked(ControlContext $context, Closure $read): mixed
  {
    return $this->credits->connection()->transaction(function () use ($context, $read) {
      return $read($this->credits->lockScope($context->scopeKey(), $context->scope));
    }, 3);
  }

  public function policy(ControlContext $context): array
  {
    AiWriterPlugin::get()->controls()->authorize($context);
    return $this->locked($context, function ($policy) use ($context): array {
      AiWriterPlugin::get()->controls()->authorize($context);
      return $this->settings($policy);
    });
  }

  /** Partial settings update; enabling requires explicit valid thresholds, never defaults. */
  public function configure(ControlContext $context, array $settings, string $reason): void
  {
    AiWriterPlugin::get()->controls()->authorize($context);
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 500 || !mb_check_encoding($reason, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)) {
      throw ValidationException::withMessages(['reason' => 'Enter a reason of at most 500 characters.']);
    }
    if (array_diff(array_keys($settings), self::FIELDS)) {
      throw ValidationException::withMessages(['abuse' => 'Unknown protection setting.']);
    }
    $this->locked($context, function ($policy) use ($context, $settings, $reason): void {
      AiWriterPlugin::get()->controls()->authorize($context);
      $before = $this->settings($policy);
      $after = array_replace($before, $settings);
      $this->validate($after);
      $this->credits->connection()->table('filament_ai_writer_policies')->where('scope_key', $context->scopeKey())->update($after);
      $after = $this->settings($this->credits->connection()->table('filament_ai_writer_policies')->where('scope_key', $context->scopeKey())->first());
      ControlAudit::query()->create([
        'scope_key' => $context->scopeKey(), 'scope' => $context->scope, 'panel_id' => $context->panelId, 'guard' => $context->guard,
        'administrator_type' => $context->actor->type, 'administrator_id' => $context->actor->id,
        'action' => 'abuse_policy', 'actor_type' => null, 'actor_id' => null,
        'before' => $before, 'after' => $after, 'reason' => $reason, 'created_at' => CarbonImmutable::now('UTC'),
      ]);
    });
  }

  public function ownStatus(ControlContext $context): array
  {
    $authorize = function () use ($context): void {
      $plugin = AiWriterPlugin::get();
      abort_unless($plugin->canUseAi() && $plugin->controlContext()->snapshot() === $context->snapshot(), 403);
    };
    $authorize();
    return $this->locked($context, function ($policy) use ($context, $authorize): array {
      $authorize();
      $decisionAt = CarbonImmutable::now('UTC');
      $this->credits->recoverExpiredActor($context, $decisionAt);
      return $this->snapshot($policy, $context, $decisionAt);
    });
  }

  public function status(ControlContext $context, ActorIdentity $actor): array
  {
    $authorize = function () use ($context, $actor): void {
      $plugin = AiWriterPlugin::get();
      $plugin->controls()->authorize($context);
      if ($plugin->resolveActor($actor->token(), $context) === null) {
        throw ValidationException::withMessages(['actor' => 'Select an available user.']);
      }
    };
    $authorize();
    return $this->locked($context, function ($policy) use ($context, $actor, $authorize): array {
      $authorize();
      $target = new ControlContext($context->panelId, $context->guard, $context->scope, $actor);
      $decisionAt = CarbonImmutable::now('UTC');
      $this->credits->recoverExpiredActor($target, $decisionAt);
      return $this->snapshot($policy, $target, $decisionAt);
    });
  }

  /** Internal seam: caller holds scope lock and has reconciled expired operations. */
  public function admissionFailure(object $policy, ControlContext $context, CarbonImmutable $decisionAt): ?string
  {
    return $this->snapshot($policy, $context, $decisionAt)['refusal'];
  }

  private function settings(object $policy): array
  {
    $settings = [];
    foreach (self::FIELDS as $field) {
      $value = $policy->$field;
      if (str_ends_with($field, '_enabled')) {
        if (!in_array($value, [false, true, 0, 1, '0', '1'], true)) {
          throw new GenerationFailure('configuration');
        }
        $settings[$field] = (bool) $value;
      } else {
        if ($value !== null && ((!is_int($value) && !(is_string($value) && ctype_digit($value))) || (int) $value < 1 || (int) $value > Credits::MAX_CREDITS)) {
          throw new GenerationFailure('configuration');
        }
        $settings[$field] = $value === null ? null : (int) $value;
      }
    }
    try {
      $this->validate($settings);
    } catch (ValidationException) {
      throw new GenerationFailure('configuration');
    }
    return $settings;
  }

  private function validate(array $settings): void
  {
    foreach ($settings as $field => $value) {
      if (str_ends_with($field, '_enabled') ? !is_bool($value) : ($value !== null && (!is_int($value) || $value < 1 || $value > Credits::MAX_CREDITS))) {
        throw ValidationException::withMessages([$field => 'Use a boolean flag or a positive integer threshold within the supported range.']);
      }
    }
    foreach (['rate' => ['rate_limit', 'rate_window_seconds'], 'concurrency' => ['concurrency_limit'],
      'failure' => ['failure_limit', 'failure_window_seconds', 'failure_pause_seconds']] as $kind => $required) {
      foreach ($required as $field) {
        if ($settings[$kind . '_enabled'] && $settings[$field] === null) {
          throw ValidationException::withMessages([$field => 'Configure this threshold before enabling protection.']);
        }
      }
    }
  }

  private function operations(ControlContext $context): Builder
  {
    return $this->credits->connection()->table('filament_ai_writer_operations')
      ->where('scope_key', $context->scopeKey())->where('actor_key', $context->actor->key())
      ->where('actor_type', $context->actor->type)->where('actor_id', $context->actor->id);
  }

  private function snapshot(object $policy, ControlContext $context, CarbonImmutable $now): array
  {
    $settings = $this->settings($policy);
    $format = fn (CarbonImmutable $date): string => $date->format('Y-m-d H:i:s.u');
    $rate = ['count' => 0, 'retry_at' => null];
    if ($settings['rate_window_seconds'] !== null) {
      $events = $this->operations($context)->where('started_at', '>', $format($now->subSeconds($settings['rate_window_seconds'])));
      $rate['count'] = (clone $events)->count();
      if ($settings['rate_enabled'] && $rate['count'] >= $settings['rate_limit']) {
        $exit = $events->orderBy('started_at')->offset($rate['count'] - $settings['rate_limit'])->value('started_at');
        $rate['retry_at'] = $format(CarbonImmutable::parse($exit, 'UTC')->addSeconds($settings['rate_window_seconds']));
      }
    }
    $live = $this->operations($context)->whereIn('status', ['pending', 'received'])->where('deadline_at', '>', $format($now));
    $concurrency = ['count' => (clone $live)->count(), 'lease_ends_at' => $live->min('deadline_at')];
    $failures = ['count' => 0, 'paused_until' => null, 'retry_at' => null];
    if ($settings['failure_window_seconds'] !== null) {
      // Recovery's effective failure time is its frozen deadline, never maintenance time.
      $time = "CASE WHEN status = 'recovered' THEN deadline_at ELSE completed_at END";
      $events = $this->operations($context)->whereIn('status', ['failed', 'recovered'])
        ->whereRaw($time . ' > ?', [$format($now->subSeconds($settings['failure_window_seconds']))]);
      $failures['count'] = (clone $events)->count();
      if ($settings['failure_enabled'] && $failures['count'] >= $settings['failure_limit']) {
        $latest = (clone $events)->selectRaw('MAX(' . $time . ') AS event_time')->first()->event_time;
        $until = CarbonImmutable::parse($latest, 'UTC')->addSeconds($settings['failure_pause_seconds']);
        if ($until->gt($now)) {
          $exit = $events->selectRaw($time . ' AS event_time')->orderBy('event_time')
            ->offset($failures['count'] - $settings['failure_limit'])->first()->event_time;
          $windowExit = CarbonImmutable::parse($exit, 'UTC')->addSeconds($settings['failure_window_seconds']);
          $failures['paused_until'] = $format($until);
          $failures['retry_at'] = $format($until->min($windowExit));
        }
      }
    }
    $refusal = $rate['retry_at'] !== null ? 'rate'
      : ($settings['concurrency_enabled'] && $concurrency['count'] >= $settings['concurrency_limit'] ? 'concurrency'
        : ($failures['retry_at'] !== null ? 'failure_pause' : null));
    return ['policy' => $settings, 'rate' => $rate, 'concurrency' => $concurrency, 'failures' => $failures, 'refusal' => $refusal];
  }
}
