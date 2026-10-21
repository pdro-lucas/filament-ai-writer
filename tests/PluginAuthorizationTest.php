<?php

namespace PdroLucas\FilamentAiWriter\Tests;

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Auth\GenericUser;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;

class PluginAuthorizationTest extends TestCase
{
  public function test_guest_cannot_use_ai_or_access_management_even_with_allowing_callbacks(): void
  {
    $plugin = AiWriterPlugin::get()
      ->authorizeUseAiUsing(fn () => true)
      ->authorizeViewStatisticsUsing(fn () => true)
      ->authorizeManageUsing(fn () => true);

    $this->assertFalse($plugin->canUseAi());
    $this->assertFalse($plugin->canViewStatistics());
    $this->assertFalse($plugin->canManage());
  }

  public function test_authenticated_actor_can_use_ai_but_management_is_denied_by_default(): void
  {
    $this->app['auth']->guard('workbench')->setUser(new GenericUser(['id' => 'writer:opaque-id']));
    $plugin = AiWriterPlugin::get();

    $this->assertTrue($plugin->canUseAi());
    $this->assertFalse($plugin->canViewStatistics());
    $this->assertFalse($plugin->canManage());
  }

  public function test_permissions_are_independent_rechecked_and_use_the_registered_panels_guard(): void
  {
    $actor = new GenericUser(['id' => 'writer:opaque-id']);
    $this->app['auth']->guard('workbench')->setUser($actor);
    $plugin = AiWriterPlugin::get()
      ->authorizeUseAiUsing(fn ($user, $panel) => false)
      ->authorizeViewStatisticsUsing(fn ($user, $panel) => $user === $actor && $panel->getId() === 'demo');

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

    $plugin->authorizeManageUsing(fn ($user, $panel) => $user === $actor && $panel->getId() === 'demo');
    $this->assertTrue($plugin->canManage());
    $this->app['auth']->guard('workbench')->forgetUser();
    $this->assertFalse($plugin->canManage());
    $this->assertFalse($plugin->canViewStatistics());
  }

  protected function setUp(): void
  {
    parent::setUp();

    Filament::setCurrentPanel(Filament::getPanel('demo'));
  }
}
