<?php

namespace PdroLucas\FilamentAiWriter\Tests;

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use PdroLucas\FilamentAiWriter\Pages\AiControlsPage;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;

class PluginAuthorizationTest extends TestCase
{
  public function test_guest_cannot_use_ai_or_access_management_even_with_allowing_callbacks(): void
  {
    $plugin = AiWriterPlugin::get()
      ->authorizeUseAi(fn () => true)
      ->authorizeViewStatistics(fn () => true)
      ->authorizeManage(fn () => true);

    $this->assertFalse($plugin->canUseAi());
    $this->assertFalse($plugin->canViewStatistics());
    $this->assertFalse($plugin->canManage());
  }

  public function test_authenticated_actor_can_use_ai_but_management_is_denied_by_default(): void
  {
    $this->app['auth']->guard('workbench')->setUser(new GenericUser(['id' => 'writer:opaque-id']));
    $panel = Panel::make()->id('default-permissions')->authGuard('workbench')->plugin(AiWriterPlugin::make());
    $plugin = $panel->getPlugin('ai-writer');

    $this->assertTrue($plugin->canUseAi());
    $this->assertFalse($plugin->canViewStatistics());
    $this->assertFalse($plugin->canManage());
  }

  public function test_permissions_are_independent_rechecked_and_use_the_registered_panels_guard(): void
  {
    $actor = new GenericUser(['id' => 'writer:opaque-id']);
    $this->app['auth']->guard('workbench')->setUser($actor);
    $plugin = AiWriterPlugin::get()
      ->authorizeUseAi(fn ($user, $panel) => false)
      ->authorizeViewStatistics(fn ($user, $panel) => $user === $actor && $panel->getId() === 'demo');

    $otherPanel = Panel::make()->id('other')->authGuard('web');
    $otherPanel->plugin(AiWriterPlugin::make());
    $otherPlugin = $otherPanel->getPlugin('ai-writer');

    $this->assertFalse($plugin->canUseAi());
    $this->assertTrue($plugin->canViewStatistics());
    $this->assertFalse($plugin->canManage());
    $this->assertFalse($otherPlugin->canUseAi());

    $this->app['auth']->guard('web')->setUser(new GenericUser(['id' => 'other-user']));
    $this->assertTrue($otherPlugin->canUseAi());
    $this->assertFalse($otherPlugin->canViewStatistics());

    $plugin->authorizeManage(fn ($user, $panel) => $user === $actor && $panel->getId() === 'demo');
    $this->assertTrue($plugin->canManage());
    $this->app['auth']->guard('workbench')->forgetUser();
    $this->assertFalse($plugin->canManage());
    $this->assertFalse($plugin->canViewStatistics());
  }

  public function test_custom_page_contract_rejects_unrelated_classes_and_stays_per_plugin_instance(): void
  {
    $controls = (new class extends \PdroLucas\FilamentAiWriter\Pages\AiControlsPage {})::class;
    $credits = (new class extends \PdroLucas\FilamentAiWriter\Pages\MyAiCreditsPage {})::class;
    $plugin = AiWriterPlugin::make()->usageTracking()->controls()->controlsPage($controls)->creditsPage($credits);
    $customPanel = Panel::make()->id('custom-pages')->plugin($plugin);
    $this->assertContains($controls, $customPanel->getPages());
    $this->assertContains($credits, $customPanel->getPages());
    $this->assertSame($credits, $plugin->getCreditsPage());
    $plugin->creditsIndicator(false);
    $this->assertFalse($plugin->hasCreditsIndicator());
    $defaultPanel = Panel::make()->id('default-pages')->plugin(AiWriterPlugin::make()->usageTracking(fn (Panel $panel): bool => $panel->getId() === 'default-pages')->controls(fn (Panel $panel): bool => $panel->getId() === 'default-pages'));
    $this->assertContains(\PdroLucas\FilamentAiWriter\Pages\AiControlsPage::class, $defaultPanel->getPages());
    $this->assertContains(\PdroLucas\FilamentAiWriter\Pages\UsageStatisticsPage::class, $defaultPanel->getPages());
    $this->assertSame([], Panel::make()->id('core-only')->plugin(AiWriterPlugin::make())->getPages());
    $this->assertNotContains($controls, $defaultPanel->getPages());
    $this->assertTrue($defaultPanel->getPlugin('ai-writer')->hasCreditsIndicator());
    foreach (['controlsPage', 'creditsPage'] as $method) {
      try {
        $plugin->{$method}(\Filament\Pages\Page::class);
        $this->fail('Unrelated or abstract pages must not replace protected plugin pages.');
      } catch (\InvalidArgumentException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  public function test_setters_accept_values_or_closures_evaluated_when_used_not_when_set(): void
  {
    $actor = new GenericUser(['id' => 'writer:opaque-id']);
    $this->app['auth']->guard('workbench')->setUser($actor);
    $calls = 0;
    $state = ['users' => false, 'indicator' => false, 'scope' => 'tenant-a', 'use' => false];
    $plugin = AiWriterPlugin::make()
      ->usersTab(function () use (&$calls, &$state): bool { $calls++; return $state['users']; })
      ->creditsIndicator(function () use (&$state): bool { return $state['indicator']; })
      ->scope(function (Authenticatable $actor, Panel $panel) use (&$state): string { return $state['scope'] . ':' . $panel->getId(); })
      ->authorizeUseAi(function ($actor) use (&$state): bool { return $state['use']; })
      ->authorizeManage(true)
      ->actorDirectoryUsing(fn () => [], fn () => null);
    Panel::make()->id('lazy')->authGuard('workbench')->plugin($plugin);

    $this->assertSame(0, $calls, 'Closures must not run when the plugin is configured or registered.');
    $this->assertFalse($plugin->hasUsersTab());
    $this->assertFalse($plugin->hasCreditsIndicator());
    $this->assertFalse($plugin->canUseAi());
    $this->assertTrue($plugin->canManage());
    $this->assertSame('tenant-a:lazy', $plugin->getScope());

    $state = ['users' => true, 'indicator' => true, 'scope' => 'tenant-b', 'use' => true];
    $this->assertTrue($plugin->hasUsersTab());
    $this->assertTrue($plugin->hasCreditsIndicator());
    $this->assertTrue($plugin->canUseAi());
    $this->assertSame('tenant-b:lazy', $plugin->getScope());
    $this->assertSame(AiControlsPage::class, $plugin->getControlsPage());
  }

  public function test_controls_without_usage_tracking_is_a_registration_error(): void
  {
    try {
      Panel::make()->id('controls-only')->plugin(AiWriterPlugin::make()->controls());
      $this->fail('Controls must require usage tracking.');
    } catch (\LogicException $exception) {
      $this->assertStringContainsString('controls() requires ->usageTracking()', $exception->getMessage());
    }
    $plugin = AiWriterPlugin::make()->usageTracking()->controls();
    Panel::make()->id('both-modules')->plugin($plugin);
    // A later per-request change cannot leave controls running without the ledger.
    $plugin->usageTracking(false);
    $this->assertFalse($plugin->isControlsEnabled());
  }

  protected function setUp(): void
  {
    parent::setUp();

    Filament::setCurrentPanel(Filament::getPanel('demo'));
  }
}
