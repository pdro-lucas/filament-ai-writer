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

  public function test_abuse_rate_uses_admissions_with_quotas_off_and_survives_usage_pruning_and_quota_reset(): void
  {
    $this->login();
    $plugin = AiWriterPlugin::get();
    $context = $plugin->controlContext();
    $plugin->actorDirectoryUsing(fn () => [$context->actor], fn () => $context->actor);
    $clock = \Carbon\CarbonImmutable::parse('2026-01-01 12:00:00', 'UTC');
    \Carbon\CarbonImmutable::setTestNow($clock);
    try {
      $this->abuseHttp(['*' => Http::response([], 503)]);
      $this->abuseGeneration('provider'); // Off-default failures are still admissions.
      $this->abuseHttp(['*' => Http::response(['choices' => [['message' => ['content' => 'Generated'], 'finish_reason' => 'stop']]])]);
      $this->abuseGeneration();
      \Carbon\CarbonImmutable::setTestNow($clock->addDays(40));
      $this->assertSame(2, app(\PdroLucas\FilamentAiWriter\Usage\UsagePruner::class)->run(1, 100)['deleted']);
      $plugin->abuse()->configure($context, ['rate_enabled' => true, 'rate_limit' => 2, 'rate_window_seconds' => 86400 * 50], 'Activate against known history');
      $plugin->credits()->configure($context, true, 5, 'manual', 'Enable credits');
      $plugin->credits()->reset($context, $context->actor, 'Reset does not erase abuse');
      $plugin->credits()->renew($context, 'Renew does not erase abuse');
      $this->abuseHttp();
      $this->abuseGeneration('rate');
      Http::assertNothingSent();
      $this->assertSame(2, $plugin->abuse()->ownStatus($context)['rate']['count']);
      $this->assertSame(5, $plugin->credits()->ownBalance($context)['available']);
      $plugin->abuse()->configure($context, ['rate_enabled' => false], 'Pause protection');
      $plugin->abuse()->configure($context, ['rate_enabled' => true], 'Restore protection');
      $this->abuseGeneration('rate');
      $plugin->abuse()->configure($context, ['rate_enabled' => false, 'failure_enabled' => true, 'failure_limit' => 1, 'failure_window_seconds' => 86400 * 50, 'failure_pause_seconds' => 86400 * 50], 'Failure history survives pruning too');
      $this->assertSame(1, $plugin->abuse()->ownStatus($context)['failures']['count']);
      $this->abuseGeneration('failure_pause');
      Http::assertNothingSent();
      $plugin->abuse()->configure($context, ['rate_enabled' => true], 'Restore frequency');
      \Carbon\CarbonImmutable::setTestNow($clock->addDays(50));
      $this->abuseHttp(['*' => Http::response(['choices' => [['message' => ['content' => 'Generated'], 'finish_reason' => 'stop']]])]);
      $this->abuseGeneration(); // Exact rolling boundary is excluded.
      $this->assertSame(1, $plugin->abuse()->ownStatus($context)['rate']['count']);
    } finally {
      \Carbon\CarbonImmutable::setTestNow();
    }
  }

  public function test_failure_window_activation_success_and_finite_cooldown(): void
  {
    $this->login();
    $plugin = AiWriterPlugin::get();
    $context = $plugin->controlContext();
    $clock = \Carbon\CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC');
    \Carbon\CarbonImmutable::setTestNow($clock);
    try {
      $this->abuseHttp(['*' => Http::response([], 503)]);
      $this->abuseGeneration('provider');
      \Carbon\CarbonImmutable::setTestNow($clock->addSeconds(1));
      $this->abuseHttp(['*' => Http::response(['choices' => [['message' => ['content' => 'Generated'], 'finish_reason' => 'stop']]])]);
      $this->abuseGeneration();
      \Carbon\CarbonImmutable::setTestNow($clock->addSeconds(2));
      $this->abuseHttp(['*' => Http::response([], 503)]);
      $this->abuseGeneration('provider');
      $plugin->abuse()->configure($context, ['failure_enabled' => true, 'failure_limit' => 2, 'failure_window_seconds' => 100, 'failure_pause_seconds' => 10], 'Activate failure protection');
      $status = $plugin->abuse()->ownStatus($context);
      $this->assertSame(2, $status['failures']['count']);
      $this->assertSame('2026-10-21 12:00:12.000000', $status['failures']['paused_until']);
      $this->abuseHttp();
      $this->abuseGeneration('failure_pause');
      \Carbon\CarbonImmutable::setTestNow($clock->addSeconds(11));
      $this->abuseGeneration('failure_pause');
      $this->assertSame($status['failures'], $plugin->abuse()->ownStatus($context)['failures']);
      Http::assertNothingSent();
      \Carbon\CarbonImmutable::setTestNow($clock->addSeconds(12));
      $this->abuseHttp(['*' => Http::response([], 503)]);
      $this->abuseGeneration('provider'); // Cooldown ends despite threshold still met; new failure pauses again.
      $this->assertSame('2026-10-21 12:00:22.000000', $plugin->abuse()->ownStatus($context)['failures']['paused_until']);
      \Carbon\CarbonImmutable::setTestNow($clock->addSeconds(13));
      $plugin->abuse()->configure($context, ['failure_window_seconds' => 2], 'Shorten rolling window');
      $this->assertNull($plugin->abuse()->ownStatus($context)['refusal']);
      $plugin->abuse()->configure($context, ['failure_window_seconds' => 100, 'failure_pause_seconds' => 1], 'Shorten pause');
      $this->assertNull($plugin->abuse()->ownStatus($context)['refusal']);
      $plugin->abuse()->configure($context, ['failure_pause_seconds' => 100], 'Longer pause uses known recent failures');
      $this->assertSame('failure_pause', $plugin->abuse()->ownStatus($context)['refusal']);
      \Carbon\CarbonImmutable::setTestNow($clock->addSeconds(102));
      $this->assertSame(1, $plugin->abuse()->ownStatus($context)['failures']['count']);
      $this->assertNull($plugin->abuse()->ownStatus($context)['refusal']);
    } finally {
      \Carbon\CarbonImmutable::setTestNow();
    }
  }

  public function test_concurrency_recovery_releases_lease_and_uses_frozen_deadline_for_failure_pause(): void
  {
    $this->login();
    $plugin = AiWriterPlugin::get();
    $context = $plugin->controlContext();
    $clock = \Carbon\CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC');
    \Carbon\CarbonImmutable::setTestNow($clock);
    config(['filament-ai-writer.timeout' => 2, 'filament-ai-writer.recovery.margin_seconds' => 0]);
    try {
      $plugin->abuse()->configure($context, ['concurrency_enabled' => true, 'concurrency_limit' => 1,
        'failure_enabled' => true, 'failure_limit' => 1, 'failure_window_seconds' => 100, 'failure_pause_seconds' => 10], 'Protect new admissions');
      $operation = new \PdroLucas\FilamentAiWriter\Data\GenerationContext((string) \Illuminate\Support\Str::uuid(), $context->panelId, $context->actor->type, $context->actor->id, 'content');
      $recorder = app(\PdroLucas\FilamentAiWriter\Usage\UsageRecorder::class);
      $recorder->begin($operation, $context);
      $this->abuseHttp();
      $this->abuseGeneration('concurrency');
      \Carbon\CarbonImmutable::setTestNow($clock->addSeconds(5));
      $this->abuseGeneration('failure_pause'); // Inline recovery commits even on refusal.
      $this->assertSame('recovered', \Illuminate\Support\Facades\DB::table('filament_ai_writer_operations')->value('status'));
      $status = $plugin->abuse()->ownStatus($context);
      $this->assertSame(0, $status['concurrency']['count']);
      $this->assertSame('2026-10-21 12:00:12.000000', $status['failures']['paused_until']);
      \Carbon\CarbonImmutable::setTestNow($clock->addSeconds(12));
      $this->assertSame(0, $plugin->credits()->recoverExpiredOperations());
      $this->assertNull($plugin->abuse()->ownStatus($context)['refusal']);
      try {
        $recorder->assertLive($operation);
        $this->fail('Late worker must stay fenced');
      } catch (GenerationFailure $failure) {
        $this->assertSame('expired', $failure->failureCode);
      }
      Http::assertNothingSent();
      $this->abuseHttp(['*' => Http::response(['choices' => [['message' => ['content' => 'Generated'], 'finish_reason' => 'stop']]])]);
      $this->abuseGeneration();
      $this->assertSame(1, $plugin->abuse()->ownStatus($context)['failures']['count']);
    } finally {
      \Carbon\CarbonImmutable::setTestNow();
    }
  }

  public function test_abuse_limits_isolate_opaque_actor_types_and_scopes_and_preserve_admitted_delivery(): void
  {
    $this->login();
    $plugin = AiWriterPlugin::get();
    $scope = 'tenant:A';
    $plugin->resolveScopeUsing(function () use (&$scope) { return $scope; });
    $user = auth('workbench')->user();
    $context = $plugin->controlContext();
    $plugin->abuse()->configure($context, ['rate_enabled' => true, 'rate_limit' => 1, 'rate_window_seconds' => 100, 'concurrency_enabled' => true, 'concurrency_limit' => 1], 'Protect scope A');
    $this->abuseHttp(function () use ($plugin, $context) {
      $this->assertSame(0, \Illuminate\Support\Facades\DB::transactionLevel());
      $this->assertSame(1, $plugin->abuse()->ownStatus($context)['concurrency']['count']);
      $plugin->controls()->setEnabled($context, false, 'Admitted operation still delivers');
      return Http::response(['choices' => [['message' => ['content' => 'Generated'], 'finish_reason' => 'stop']]]);
    });
    $this->abuseGeneration();
    $plugin->controls()->setEnabled($context, true, 'Restore availability');
    $this->abuseHttp();
    $this->abuseGeneration('rate');
    auth('workbench')->setUser(new \Illuminate\Auth\GenericUser(['id' => (string) $user->getAuthIdentifier()]));
    $this->assertSame(0, $plugin->abuse()->ownStatus($plugin->controlContext())['rate']['count']);
    $this->abuseHttp(['*' => Http::response(['choices' => [['message' => ['content' => 'Generated'], 'finish_reason' => 'stop']]])]);
    $this->abuseGeneration();
    auth('workbench')->setUser($user);
    $scope = 'tenant:B';
    $this->assertFalse($plugin->abuse()->policy($plugin->controlContext())['rate_enabled']);
    $this->abuseGeneration();
    $scope = 'tenant:A';
    $this->assertSame(1, $plugin->abuse()->ownStatus($context)['rate']['count']);
  }

  public function test_abuse_configuration_permissions_validation_audit_rollback_and_own_status_context(): void
  {
    $this->login();
    $plugin = AiWriterPlugin::get();
    $context = $plugin->controlContext();
    $abuse = $plugin->abuse();
    foreach ([['rate_enabled' => true], ['concurrency_enabled' => true], ['failure_enabled' => true],
      ['rate_limit' => 0], ['failure_pause_seconds' => '10'], ['rate_window_seconds' => 1.5], ['rate_limit' => 2147483648], ['unknown' => 1]] as $invalid) {
      try {
        $abuse->configure($context, $invalid, 'Invalid');
        $this->fail('Must validate explicit thresholds');
      } catch (\Illuminate\Validation\ValidationException) {
        $this->assertFalse($abuse->policy($context)['rate_enabled']);
      }
    }
    $abuse->configure($context, ['rate_enabled' => true, 'rate_limit' => 2, 'rate_window_seconds' => 60], 'Activate');
    $audit = $plugin->controls()->audit($context)->sole();
    $this->assertFalse($audit->before['rate_enabled']);
    $this->assertSame(2, $audit->after['rate_limit']);
    \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\AiControlsPage::class)->assertSee('Abuse protections updated')->assertSee('Calls: 2');
    \Illuminate\Support\Facades\DB::unprepared("CREATE TRIGGER reject_audit BEFORE INSERT ON filament_ai_writer_control_audit BEGIN SELECT RAISE(ABORT, 'audit failed'); END");
    try {
      $abuse->configure($context, ['rate_limit' => 3], 'Must roll back');
      $this->fail('Audit must be atomic');
    } catch (\Illuminate\Database\QueryException) {
      $this->assertSame(2, $abuse->policy($context)['rate_limit']);
    }
    \Illuminate\Support\Facades\DB::unprepared('DROP TRIGGER reject_audit');
    try {
      $abuse->configure($context, ['rate_enabled' => false], ' ');
      $this->fail('Reason is mandatory');
    } catch (\Illuminate\Validation\ValidationException) {
      $this->assertTrue($abuse->policy($context)['rate_enabled']);
    }
    $plugin->actorDirectoryUsing(fn () => [], fn () => null);
    try {
      $abuse->status($context, $context->actor);
      $this->fail('Admin status must resolve an available target');
    } catch (\Illuminate\Validation\ValidationException) {
      $this->assertTrue(true);
    }
    $plugin->authorizeUseAiUsing(fn () => false);
    try {
      $abuse->ownStatus($context);
      $this->fail('Own status requires permission to use AI');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
      $this->assertSame(403, $error->getStatusCode());
    }
    $plugin->authorizeUseAiUsing(fn () => true);
    $plugin->authorizeManageUsing(fn () => false);
    $this->assertSame(0, $abuse->ownStatus($context)['rate']['count']);
    foreach ([fn () => $abuse->configure($context, ['rate_enabled' => false], 'Denied'), fn () => $abuse->policy($context), fn () => $abuse->status($context, $context->actor)] as $denied) {
      try {
        $denied();
        $this->fail('Management permission is required');
      } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
        $this->assertSame(403, $error->getStatusCode());
      }
    }
    $plugin->authorizeManageUsing(fn () => true);
    \Illuminate\Support\Facades\DB::table('filament_ai_writer_policies')->update(['rate_limit' => null]);
    $this->abuseHttp();
    $this->abuseGeneration('configuration');
    Http::assertNothingSent();
    $plugin->resolveScopeUsing(fn () => 'different');
    try {
      $abuse->ownStatus($context);
      $this->fail('Own status must revalidate frozen context');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
      $this->assertSame(403, $error->getStatusCode());
    }
  }

  public function test_abuse_decision_uses_one_locked_instant_when_clock_crosses_lease_deadline(): void
  {
    $this->login();
    $plugin = AiWriterPlugin::get();
    $context = $plugin->controlContext();
    $plugin->actorDirectoryUsing(fn () => [$context->actor], fn () => $context->actor);
    $clock = \Carbon\CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC');
    \Carbon\CarbonImmutable::setTestNow($clock);
    config(['filament-ai-writer.timeout' => 2, 'filament-ai-writer.recovery.margin_seconds' => 0]);
    try {
      $plugin->abuse()->configure($context, ['concurrency_enabled' => true, 'concurrency_limit' => 1,
        'failure_enabled' => true, 'failure_limit' => 1, 'failure_window_seconds' => 100, 'failure_pause_seconds' => 10], 'Protect lease boundary');
      $operation = new \PdroLucas\FilamentAiWriter\Data\GenerationContext((string) \Illuminate\Support\Str::uuid(), $context->panelId, $context->actor->type, $context->actor->id, 'content');
      app(\PdroLucas\FilamentAiWriter\Usage\UsageRecorder::class)->begin($operation, $context);
      foreach (['own', 'admin', 'admission'] as $surface) {
        $ticks = 0;
        \Carbon\CarbonImmutable::setTestNow(function () use ($clock, &$ticks, $surface) {
          return $clock->addSeconds(++$ticks <= ($surface === 'admission' ? 2 : 1) ? 1 : 3);
        });
        if ($surface === 'admission') {
          $this->abuseHttp();
          $this->abuseGeneration('concurrency');
          Http::assertNothingSent();
          $this->assertSame(1, \Illuminate\Support\Facades\DB::table('filament_ai_writer_operations')->count());
        } else {
          $status = $surface === 'own' ? $plugin->abuse()->ownStatus($context) : $plugin->abuse()->status($context, $context->actor);
          $this->assertSame('concurrency', $status['refusal']);
          $this->assertSame(1, $status['concurrency']['count']);
        }
      }
      \Carbon\CarbonImmutable::setTestNow($clock->addSeconds(3));
      $this->assertSame('failure_pause', $plugin->abuse()->ownStatus($context)['refusal']);
    } finally {
      \Carbon\CarbonImmutable::setTestNow();
    }
  }

  private function abuseHttp(mixed $callback = null): void
  {
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake($callback);
  }

  private function abuseGeneration(?string $expectedFailure = null): void
  {
    try {
      AiWriterPlugin::get()->generation()->run('prompt', 'input', fn () => null, fn () => null, 'content');
      $this->assertNull($expectedFailure, 'Expected admission/provider failure');
    } catch (GenerationFailure $failure) {
      $this->assertSame($expectedFailure, $failure->failureCode);
    }
  }

  private function login(): void
  {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::firstOrFail(), 'workbench');
    Filament::setCurrentPanel(Filament::getPanel('demo'));
    AiWriterPlugin::get()->authorizeManageUsing(fn () => true);
  }
}
