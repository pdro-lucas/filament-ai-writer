<?php

namespace PdroLucas\FilamentAiWriter\Tests;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Exceptions\GenerationFailure;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

class AiControlsTest extends TestCase
{
  use DatabaseMigrations;

  public function test_scope_disable_is_audited_and_denies_shared_generation_without_revoking_management(): void
  {
    $this->login();
    $controls = AiWriterPlugin::get()->controls();
    $context = AiWriterPlugin::get()->controlContext();
    $this->assertTrue($controls->enabled($context));
    $controls->setEnabled($context, false, 'Maintenance');
    $this->assertFalse($controls->enabled($context));
    $event = $controls->audit($context)->sole();
    $this->assertSame('availability', $event->action);
    $this->assertSame(['enabled' => true], $event->before);
    $this->assertSame(['enabled' => false], $event->after);
    $this->assertSame('Maintenance', $event->reason);
    $this->assertSame(User::class, $event->administrator_type);
    $this->assertTrue(AiWriterPlugin::get()->canManage());
    Http::fake();
    try {
      AiWriterPlugin::get()->generation()->run('prompt', 'input', fn () => $this->fail('Must not deliver'), fn () => null, 'content');
      $this->fail('Must deny disabled scope');
    } catch (GenerationFailure $failure) {
      $this->assertSame('disabled', $failure->failureCode);
    }
    Http::assertNothingSent();
    $controls->setEnabled($context, true, 'Service restored');
    $this->assertTrue($controls->enabled($context));
  }

  public function test_directory_validates_opaque_actor_identity_and_block_is_scope_and_type_specific(): void
  {
    $this->login();
    $plugin = AiWriterPlugin::get();
    $actor = new \PdroLucas\FilamentAiWriter\Controls\ActorIdentity(\Illuminate\Auth\GenericUser::class, 'id:α/007', '<script>unsafe</script>');
    $scope = 'tenant:A';
    $available = true;
    $plugin->resolveScopeUsing(function () use (&$scope) { return $scope; })
      ->actorDirectoryUsing(fn () => [$actor], function ($type, $id, $resolvedScope) use ($actor, &$available) {
        return $available && $resolvedScope === 'tenant:A' && $type === $actor->type && $id === $actor->id ? $actor : null;
      });
    $controls = $plugin->controls();
    $context = $plugin->controlContext();
    $this->assertSame([$actor->token() => '<script>unsafe</script>'], $plugin->searchActors('unsafe', $context));
    $this->assertNull($plugin->resolveActor('invalid', $context));
    $this->assertNull($plugin->resolveActor((new \PdroLucas\FilamentAiWriter\Controls\ActorIdentity('OtherActor', $actor->id))->token(), $context));
    $controls->setBlocked($context, $actor, true, 'Security hold');
    $this->assertTrue($controls->blocked($context, $actor));
    $this->assertSame($actor->id, $controls->audit($context)->sole()->actor_id);
    $this->app['auth']->guard('workbench')->setUser(new \Illuminate\Auth\GenericUser(['id' => $actor->id]));
    Http::fake();
    try {
      $plugin->generation()->run('prompt', 'input', fn () => $this->fail('Must not deliver'), fn () => null, 'content');
      $this->fail('Must block selected actor');
    } catch (GenerationFailure $failure) {
      $this->assertSame('blocked', $failure->failureCode);
    }
    Http::assertNothingSent();
    $scope = 'tenant:B';
    $this->assertSame('tenant:B', $controls->admit()->scope);
    $this->assertSame(0, $controls->audit($plugin->controlContext())->count());
    $scope = 'tenant:A';
    $this->app['auth']->guard('workbench')->setUser(new \Illuminate\Auth\GenericUser(['id' => 'another-id']));
    $context = $plugin->controlContext();
    $available = false;
    try {
      $controls->setBlocked($context, $actor, false, 'Release');
      $this->fail('Must reject removed directory identity');
    } catch (\Illuminate\Validation\ValidationException) {
      $this->assertTrue($controls->blocked($context, $actor));
    }
    $available = true;
    $controls->setBlocked($context, $actor, false, 'Release');
    $this->assertFalse($controls->blocked($context, $actor));
    $this->assertSame(2, $controls->audit($context)->count());
  }

  public function test_reason_validation_and_audit_failure_rollback_first_policy_and_existing_block_mutations(): void
  {
    $this->login();
    $plugin = AiWriterPlugin::get();
    $actor = $plugin->controlContext()->actor;
    $plugin->actorDirectoryUsing(fn () => [$actor], fn () => $actor);
    $context = $plugin->controlContext();
    foreach ([' ', str_repeat('a', 501)] as $reason) {
      try {
        $plugin->controls()->setEnabled($context, false, $reason);
        $this->fail('Must validate reason');
      } catch (\Illuminate\Validation\ValidationException) {
        $this->assertTrue($plugin->controls()->enabled($context));
      }
    }
    \Illuminate\Support\Facades\DB::unprepared("CREATE TRIGGER reject_audit BEFORE INSERT ON filament_ai_writer_control_audit BEGIN SELECT RAISE(ABORT, 'audit failed'); END");
    try {
      $plugin->controls()->setEnabled($context, false, 'Maintenance');
      $this->fail('Must surface audit failure');
    } catch (\Illuminate\Database\QueryException) {
      $this->assertTrue($plugin->controls()->enabled($context));
      $this->assertDatabaseCount('filament_ai_writer_policies', 0);
      $this->assertSame(0, $plugin->controls()->audit($context)->count());
    }
    \Illuminate\Support\Facades\DB::unprepared('DROP TRIGGER reject_audit');
    $plugin->controls()->setBlocked($context, $actor, true, 'Hold');
    \Illuminate\Support\Facades\DB::unprepared("CREATE TRIGGER reject_audit BEFORE INSERT ON filament_ai_writer_control_audit BEGIN SELECT RAISE(ABORT, 'audit failed'); END");
    try {
      $plugin->controls()->setBlocked($context, $actor, false, 'Release');
      $this->fail('Must surface audit failure');
    } catch (\Illuminate\Database\QueryException) {
      $this->assertTrue($plugin->controls()->blocked($context, $actor));
      $this->assertSame(1, $plugin->controls()->audit($context)->count());
    }
  }

  public function test_missing_control_storage_or_invalid_resolver_fail_closed_before_http(): void
  {
    $this->login();
    Http::fake();
    \Illuminate\Support\Facades\Schema::drop('filament_ai_writer_actor_blocks');
    try {
      AiWriterPlugin::get()->generation()->run('prompt', 'input', fn () => $this->fail('Must not deliver'), fn () => null, 'content');
      $this->fail('Must reject inaccessible controls');
    } catch (GenerationFailure $failure) {
      $this->assertSame('storage', $failure->failureCode);
      $this->assertNull($failure->getPrevious());
    }
    AiWriterPlugin::get()->resolveScopeUsing(fn () => ['invalid']);
    try {
      AiWriterPlugin::get()->generation()->run('prompt', 'input', fn () => $this->fail('Must not deliver'), fn () => null, 'content');
      $this->fail('Must reject invalid scope');
    } catch (GenerationFailure $failure) {
      $this->assertSame('controls', $failure->failureCode);
    }
    Http::assertNothingSent();
  }

  public function test_management_page_and_every_later_read_and_mutation_require_management_and_frozen_context(): void
  {
    $this->login();
    $plugin = AiWriterPlugin::get();
    $plugin->authorizeManageUsing(fn () => false)->authorizeViewStatisticsUsing(fn () => true);
    \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\AiControlsPage::class)->assertForbidden();
    $plugin->authorizeManageUsing(fn () => true);
    $scope = 'tenant:A';
    $plugin->resolveScopeUsing(function () use (&$scope) { return $scope; });
    $page = \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\AiControlsPage::class)
      ->assertSee('AI is enabled')
      ->callAction('disable', data: ['reason' => 'Maintenance'])
      ->assertSee('AI is disabled');
    $scope = 'tenant:B';
    $page->call('$refresh')->assertForbidden();
    $this->assertTrue($plugin->controls()->enabled($plugin->controlContext()));
    $scope = 'tenant:A';
    $page = \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\AiControlsPage::class)->mountAction('enable')->setActionData(['reason' => 'Attempted bypass']);
    $plugin->authorizeManageUsing(fn () => false);
    $page->callMountedAction()->assertForbidden();
    $plugin->authorizeManageUsing(fn () => true);
    $this->assertFalse($plugin->controls()->enabled($plugin->controlContext()));
    $this->assertSame(1, $plugin->controls()->audit($plugin->controlContext())->count());
  }

  public function test_missing_directory_keeps_availability_and_audit_usable_but_cannot_accept_arbitrary_actors(): void
  {
    $this->login();
    $panel = \Filament\Panel::make()->id('no-directory')->authGuard('workbench')->plugin(
      AiWriterPlugin::make()->authorizeManageUsing(fn () => true));
    Filament::setCurrentPanel($panel);
    $plugin = AiWriterPlugin::get();
    $context = $plugin->controlContext();
    $this->assertFalse($plugin->hasActorDirectory());
    \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\AiControlsPage::class)
      ->assertSee('User search is not configured.')
      ->callAction('disable', data: ['reason' => 'Maintenance'])
      ->assertSee('AI is disabled')->assertCountTableRecords(1);
    try {
      $plugin->controls()->setBlocked($context, $context->actor, true, 'Hold');
      $this->fail('Unvalidated identity must be rejected');
    } catch (\Illuminate\Validation\ValidationException) {
      $this->assertFalse($plugin->controls()->blocked($context, $context->actor));
    }
  }

  public function test_page_block_action_revalidates_directory_selection_and_limits_escaped_search_results(): void
  {
    $this->login();
    $plugin = AiWriterPlugin::get();
    $actor = new \PdroLucas\FilamentAiWriter\Controls\ActorIdentity('HostPerson', 'opaque:009', '<img src=x onerror=alert(1)>');
    $available = true;
    $plugin->actorDirectoryUsing(fn () => array_map(fn ($id) => new \PdroLucas\FilamentAiWriter\Controls\ActorIdentity('HostPerson', $id, '<b>User</b>'), range(1, 100)),
      function ($type, $id) use ($actor, &$available) { return $available && $type === $actor->type && $id === $actor->id ? $actor : null; });
    $this->assertCount(50, $plugin->searchActors('user', $plugin->controlContext()));
    $page = \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\AiControlsPage::class)
      ->mountAction('blockActor')->setActionData(['actor' => $actor->token(), 'reason' => '<script>Hold</script>']);
    $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $page->html());
    $available = false;
    $page->callMountedAction()->assertHasActionErrors(['actor']);
    $this->assertFalse($plugin->controls()->blocked($plugin->controlContext(), $actor));
    $available = true;
    $page->callMountedAction()->assertHasNoActionErrors()->assertNotified('AI controls updated')
      ->assertDontSeeHtml('<script>Hold</script>');
    $this->assertTrue($plugin->controls()->blocked($plugin->controlContext(), $actor));
    $page->callAction('unblockActor', data: ['actor' => $actor->token(), 'reason' => 'Release'])
      ->assertHasNoActionErrors();
    $this->assertFalse($plugin->controls()->blocked($plugin->controlContext(), $actor));
  }

  public function test_context_rejects_panel_guard_and_administrator_changes_and_forged_snapshot(): void
  {
    $this->login();
    $plugin = AiWriterPlugin::get();
    $panel = Filament::getCurrentPanel();
    $actor = $panel->auth()->user();
    $page = \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\AiControlsPage::class);
    $panel->authGuard('web');
    $this->app['auth']->guard('web')->setUser($actor);
    $page->call('$refresh')->assertForbidden();
    $panel->authGuard('workbench');
    $page = \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\AiControlsPage::class);
    $this->app['auth']->guard('workbench')->setUser(new \Illuminate\Auth\GenericUser(['id' => 'another-admin']));
    $page->call('$refresh')->assertForbidden();
    $this->app['auth']->guard('workbench')->setUser($actor);
    $page = \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\AiControlsPage::class);
    $other = \Filament\Panel::make()->id('other')->authGuard('workbench')->plugin(AiWriterPlugin::make()->authorizeManageUsing(fn () => true));
    Filament::setCurrentPanel($other);
    $page->call('$refresh')->assertForbidden();
    Filament::setCurrentPanel($panel);
    $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
    \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\AiControlsPage::class)->set('controlSnapshot.2', 'forged-scope');
  }

  public function test_audit_utc_timestamp_round_trips_in_a_non_utc_host_timezone(): void
  {
    $this->login();
    $previousTimezone = date_default_timezone_get();
    $previousAppTimezone = config('app.timezone');
    $previousNow = \Carbon\CarbonImmutable::getTestNow();
    try {
      config(['app.timezone' => 'America/Sao_Paulo']);
      date_default_timezone_set('America/Sao_Paulo');
      \Carbon\CarbonImmutable::setTestNow('2026-10-21 12:00:00 UTC');
      $controls = AiWriterPlugin::get()->controls();
      $context = AiWriterPlugin::get()->controlContext();
      $controls->setEnabled($context, false, 'Maintenance');

      $date = $controls->audit($context)->sole()->created_at;
      $this->assertSame('2026-10-21T12:00:00+00:00', $date->toIso8601String());
      $this->assertSame('2026-10-21T09:00:00-03:00', $date->setTimezone('America/Sao_Paulo')->toIso8601String());
    } finally {
      \Carbon\CarbonImmutable::setTestNow($previousNow);
      date_default_timezone_set($previousTimezone);
      config(['app.timezone' => $previousAppTimezone]);
    }
  }

  public function test_credit_policy_preserves_consumption_and_enforces_last_credit(): void
  {
    $this->login();
    $plugin = AiWriterPlugin::get();
    $context = $plugin->controlContext();
    $credits = $plugin->credits();
    $credits->configure($context, true, 1, 'manual', 'Start quota');
    Http::fake(['*' => Http::response(['model' => 'demo', 'choices' => [['message' => ['content' => 'Generated'], 'finish_reason' => 'stop']]])]);
    $plugin->generation()->run('prompt', 'input', fn () => null, fn () => null, 'content');
    $this->assertSame(0, $credits->ownBalance($context)['available']);
    try {
      $plugin->generation()->run('prompt', 'input', fn () => $this->fail('Exhausted'), fn () => null, 'content');
      $this->fail('Must deny exhausted quota');
    } catch (GenerationFailure $failure) {
      $this->assertSame('credits', $failure->failureCode);
    }
    Http::assertSentCount(1);
    $credits->configure($context, false, 3, 'manual', 'Pause quota');
    $plugin->generation()->run('prompt', 'input', fn () => null, fn () => null, 'content');
    $credits->configure($context, true, 3, 'manual', 'Resume quota');
    $this->assertSame(1, $credits->ownBalance($context)['consumed']);
    $this->assertSame(2, $credits->ownBalance($context)['available']);
    $credits->setLimit($context, $context->actor, 0, 'Lower limit');
    $this->assertSame(1, $credits->ownBalance($context)['consumed']);
    $this->assertSame(0, $credits->ownBalance($context)['available']);
  }

  public function test_quota_off_admission_stays_free_when_enabled_during_http_and_reset_preserves_inflight_origin(): void
  {
    $this->login();
    $plugin = AiWriterPlugin::get();
    $context = $plugin->controlContext();
    $credits = $plugin->credits();
    $credits->configure($context, false, 1, 'manual', 'Configure');
    Http::fake(function () use ($credits, $context) {
      $this->assertSame(0, \Illuminate\Support\Facades\DB::transactionLevel());
      $credits->configure($context, true, 1, 'manual', 'Activate while running');
      return Http::response(['choices' => [['message' => ['content' => 'Text'], 'finish_reason' => 'stop']]]);
    });
    $plugin->generation()->run('prompt', 'input', fn () => null, fn () => null, 'content');
    $this->assertSame(1, $credits->ownBalance($context)['available']);
    Http::fake(function () use ($credits, $context) {
      $this->assertSame(1, $credits->ownBalance($context)['reserved']);
      $credits->reset($context, $context->actor, 'Reset during flight');
      $this->assertSame(1, $credits->ownBalance($context)['available']);
      return Http::response(['choices' => [['message' => ['content' => 'Text'], 'finish_reason' => 'stop']]]);
    });
    $plugin->generation()->run('prompt', 'input', fn () => null, fn () => null, 'content');
    $this->assertSame(0, $credits->ownBalance($context)['consumed']);
    $this->assertSame(0, $credits->ownBalance($context)['reserved']);
    $this->assertSame(1, $credits->ownBalance($context)['available']);
  }

  public function test_credit_mutations_rollback_with_audit_and_own_balance_does_not_require_admin(): void
  {
    $this->login();
    $plugin = AiWriterPlugin::get();
    $context = $plugin->controlContext();
    $credits = $plugin->credits();
    $credits->configure($context, true, 2, 'manual', 'Start');
    $credits->grant($context, $context->actor, 3, 'Temporary extra');
    $this->assertSame(5, $credits->ownBalance($context)['available']);
    \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\AiControlsPage::class)
      ->assertSee('Credit policy updated')->assertSee('Extra credits granted')->assertSee('Available: 5');
    \Illuminate\Support\Facades\DB::unprepared("CREATE TEMP TRIGGER fail_credit_audit BEFORE INSERT ON filament_ai_writer_control_audit BEGIN SELECT RAISE(ABORT, 'audit failure'); END");
    try {
      $credits->reset($context, $context->actor, 'Reset');
      $this->fail('Audit must be atomic');
    } catch (\Illuminate\Database\QueryException) {
      $this->assertSame(5, $credits->ownBalance($context)['available']);
    }
    $plugin->authorizeManageUsing(fn () => false)->authorizeViewStatisticsUsing(fn () => false);
    $this->assertSame(5, $credits->ownBalance($context)['available']);
    try {
      $credits->balance($context, $context->actor);
      $this->fail('Must require admin for target balance');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
      $this->assertSame(403, $error->getStatusCode());
    }
    $plugin->resolveScopeUsing(fn () => 'changed-tenant');
    $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $credits->ownBalance($context);
  }

  public function test_credit_calendar_changes_preserve_current_period_and_manual_switch_uses_next_cut(): void
  {
    $this->login();
    $credits = AiWriterPlugin::get()->credits();
    $context = AiWriterPlugin::get()->controlContext();
    $previous = \Carbon\CarbonImmutable::getTestNow();
    config(['app.timezone' => 'America/New_York']);
    try {
      \Carbon\CarbonImmutable::setTestNow('2026-03-07 17:00:00 UTC');
      $credits->configure($context, true, 2, 'manual', 'Start');
      $credits->grant($context, $context->actor, 1, 'Extra');
      $initial = $credits->ownBalance($context);
      $credits->configure($context, true, 2, 'daily', 'Next day');
      $scheduled = $credits->ownBalance($context);
      $this->assertSame($initial['period_id'], $scheduled['period_id']);
      $this->assertSame(3, $scheduled['available']);
      $this->assertSame('2026-03-08 05:00:00', $scheduled['renews_at']);
      \Carbon\CarbonImmutable::setTestNow('2026-03-08 05:00:00 UTC');
      $next = $credits->ownBalance($context);
      $this->assertNotSame($initial['period_id'], $next['period_id']);
      $this->assertSame(2, $next['available']);
      $this->assertSame('2026-03-09 04:00:00', $next['renews_at']); // 23-hour DST day
      $credits->configure($context, true, 2, 'weekly', 'Next calendar');
      $this->assertSame($next['period_id'], $credits->ownBalance($context)['period_id']);
      $this->assertSame('2026-03-09 04:00:00', $credits->ownBalance($context)['renews_at']);
      \Carbon\CarbonImmutable::setTestNow('2026-03-09 04:00:00 UTC');
      $weekly = $credits->ownBalance($context);
      $this->assertSame('2026-03-16 04:00:00', $weekly['renews_at']);
      $credits->configure($context, true, 2, 'monthly', 'Next month');
      \Carbon\CarbonImmutable::setTestNow('2026-03-16 04:00:00 UTC');
      $this->assertSame('2026-04-01 04:00:00', $credits->ownBalance($context)['renews_at']);
    } finally {
      \Carbon\CarbonImmutable::setTestNow($previous);
    }
  }

  public function test_credit_input_contract_rejects_lossy_values_and_preserves_large_balance(): void
  {
    $this->login();
    $credits = AiWriterPlugin::get()->credits();
    $context = AiWriterPlugin::get()->controlContext();
    foreach ([-1, 1.5, '2', true, PHP_INT_MAX] as $invalid) {
      try {
        $credits->configure($context, true, $invalid, 'manual', 'Invalid');
        $this->fail('Must reject invalid integer');
      } catch (\Illuminate\Validation\ValidationException) {
        $this->assertFalse($credits->policy($context)['enabled']);
      }
    }
    $credits->configure($context, true, 2147483647, 'manual', 'Large quota');
    $credits->grant($context, $context->actor, 2147483647, 'Large extra');
    $this->assertSame(4294967294, $credits->ownBalance($context)['available']);
    try {
      $credits->grant($context, $context->actor, 1, 'Overflow extra');
      $this->fail('Must reject overflow');
    } catch (\Illuminate\Validation\ValidationException) {
      $this->assertSame(4294967294, $credits->ownBalance($context)['available']);
    }
    $credits->setLimit($context, $context->actor, 0, 'Zero override');
    $this->assertSame(2147483647, $credits->ownBalance($context)['available']);
  }

  public function test_renewal_during_http_keeps_new_period_unconsumed_and_old_admission_valid(): void
  {
    $this->login();
    $plugin = AiWriterPlugin::get();
    $credits = $plugin->credits();
    $context = $plugin->controlContext();
    $credits->configure($context, true, 1, 'manual', 'Start');
    $origin = $credits->ownBalance($context)['period_id'];
    Http::fake(function () use ($credits, $context) {
      $this->assertSame(1, $credits->ownBalance($context)['reserved']);
      $credits->renew($context, 'Renew while running');
      $this->assertSame(1, $credits->ownBalance($context)['available']);
      return Http::response(['choices' => [['message' => ['content' => 'Text'], 'finish_reason' => 'stop']]]);
    });
    $delivered = false;
    $plugin->generation()->run('prompt', 'input', function () use (&$delivered) { $delivered = true; }, fn () => null, 'content');
    $this->assertTrue($delivered);
    $balance = $credits->ownBalance($context);
    $this->assertNotSame($origin, $balance['period_id']);
    $this->assertSame(0, $balance['consumed']);
    $this->assertSame(0, $balance['reserved']);
    $this->assertSame(1, $balance['available']);
  }

  private function login(): void
  {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::firstOrFail(), 'workbench');
    Filament::setCurrentPanel(Filament::getPanel('demo'));
    AiWriterPlugin::get()->authorizeManageUsing(fn () => true);
  }
}
