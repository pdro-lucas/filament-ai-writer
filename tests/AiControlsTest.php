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

  private function login(): void
  {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::firstOrFail(), 'workbench');
    Filament::setCurrentPanel(Filament::getPanel('demo'));
    AiWriterPlugin::get()->authorizeManageUsing(fn () => true);
  }
}
