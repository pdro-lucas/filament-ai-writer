<?php

namespace PdroLucas\FilamentAiWriter\Controls;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Exceptions\GenerationFailure;
use PdroLucas\FilamentAiWriter\Models\ControlAudit;
use Throwable;

final class Controls
{
  public function authorize(ControlContext $context): void
  {
    $plugin = AiWriterPlugin::get();
    abort_unless($plugin->canManage() && $plugin->controlContext()->snapshot() === $context->snapshot(), 403);
  }

  public function enabled(ControlContext $context): bool
  {
    $this->authorize($context);
    return $this->readEnabled($context);
  }

  private function readEnabled(ControlContext $context): bool
  {
    return (bool) (DB::table('filament_ai_writer_policies')->where('scope_key', $context->scopeKey())->value('enabled') ?? true);
  }

  public function blocked(ControlContext $context, ActorIdentity $actor): bool
  {
    $this->authorize($context);
    return $this->readBlocked($context, $actor);
  }

  private function readBlocked(ControlContext $context, ActorIdentity $actor): bool
  {
    return DB::table('filament_ai_writer_actor_blocks')->where('scope_key', $context->scopeKey())->where('actor_key', $actor->key())->exists();
  }

  /** Admission is resolved once on the server, outside the provider's HTTP call. */
  public function admit(): ControlContext
  {
    try {
      $context = AiWriterPlugin::get()->controlContext();
    } catch (Throwable) {
      throw new GenerationFailure('controls');
    }
    try {
      $this->assertAdmission($context);
      return $context;
    } catch (GenerationFailure $failure) {
      throw $failure;
    } catch (Throwable) {
      throw new GenerationFailure('storage');
    }
  }

  /** Internal admission seam; caller owns the stable scope lock and frozen server context. */
  public function assertAdmission(ControlContext $context): void
  {
    if (!$this->readEnabled($context)) {
      throw new GenerationFailure('disabled');
    }
    if ($this->readBlocked($context, $context->actor)) {
      throw new GenerationFailure('blocked');
    }
  }

  public function setEnabled(ControlContext $context, bool $enabled, string $reason): void
  {
    $this->change($context, 'availability', null, $enabled, $reason);
  }

  public function setBlocked(ControlContext $context, ActorIdentity $actor, bool $blocked, string $reason): void
  {
    $this->authorize($context);
    // Even a previously selected actor must still be manageable in this context.
    $resolved = AiWriterPlugin::get()->resolveActor($actor->token(), $context);
    if ($resolved === null) {
      throw ValidationException::withMessages(['actor' => 'Select an available user.']);
    }
    $this->change($context, 'block', $resolved, $blocked, $reason);
  }

  private function change(ControlContext $context, string $action, ?ActorIdentity $actor, bool $value, string $reason): void
  {
    $this->authorize($context);
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 500 || !mb_check_encoding($reason, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)) {
      throw ValidationException::withMessages(['reason' => 'Enter a reason of at most 500 characters.']);
    }
    app(\PdroLucas\FilamentAiWriter\Credits\Credits::class)->connection()->transaction(function () use ($context, $action, $actor, $value, $reason): void {
      // Unique insert arbitrates first writers; every change then locks the same scope row.
      DB::table('filament_ai_writer_policies')->insertOrIgnore(['scope_key' => $context->scopeKey(), 'scope' => $context->scope, 'enabled' => true]);
      $policy = DB::table('filament_ai_writer_policies')->where('scope_key', $context->scopeKey())->lockForUpdate()->first();
      if ($policy === null || $policy->scope !== $context->scope) {
        throw new \RuntimeException('Control scope could not be locked.');
      }
      $before = $actor === null ? (bool) $policy->enabled : $this->readBlocked($context, $actor);
      if ($before === $value) {
        return;
      }
      if ($actor === null) {
        DB::table('filament_ai_writer_policies')->where('scope_key', $context->scopeKey())->update(['enabled' => $value]);
      } elseif ($value) {
        DB::table('filament_ai_writer_actor_blocks')->insert(['scope_key' => $context->scopeKey(), 'actor_key' => $actor->key(), 'actor_type' => $actor->type, 'actor_id' => $actor->id]);
      } else {
        DB::table('filament_ai_writer_actor_blocks')->where('scope_key', $context->scopeKey())->where('actor_key', $actor->key())->delete();
      }
      $key = $actor === null ? 'enabled' : 'blocked';
      ControlAudit::query()->create([
        'scope_key' => $context->scopeKey(), 'scope' => $context->scope, 'panel_id' => $context->panelId, 'guard' => $context->guard,
        'administrator_type' => $context->actor->type, 'administrator_id' => $context->actor->id,
        'action' => $action, 'actor_type' => $actor?->type, 'actor_id' => $actor?->id,
        'before' => [$key => $before], 'after' => [$key => $value], 'reason' => $reason, 'created_at' => now('UTC'),
      ]);
    }, 3);
  }

  public function audit(ControlContext $context): Builder
  {
    $this->authorize($context);
    return ControlAudit::query()->where('scope_key', $context->scopeKey());
  }
}
