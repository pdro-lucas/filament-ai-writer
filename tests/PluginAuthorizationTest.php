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

  public function test_custom_page_contract_rejects_unrelated_classes_and_stays_per_plugin_instance(): void
  {
    $controls = (new class extends \PdroLucas\FilamentAiWriter\Pages\AiControlsPage {})::class;
    $credits = (new class extends \PdroLucas\FilamentAiWriter\Pages\MyAiCreditsPage {})::class;
    $plugin = AiWriterPlugin::make()->controlsPage($controls)->creditsPage($credits);
    $customPanel = Panel::make()->id('custom-pages')->plugin($plugin);
    $this->assertContains($controls, $customPanel->getPages());
    $this->assertContains($credits, $customPanel->getPages());
    $this->assertSame($credits, $plugin->getCreditsPage());
    $plugin->creditsInTopbar(false);
    $this->assertFalse($plugin->hasCreditsInTopbar());
    $defaultPanel = Panel::make()->id('default-pages')->plugin(AiWriterPlugin::make());
    $this->assertContains(\PdroLucas\FilamentAiWriter\Pages\AiControlsPage::class, $defaultPanel->getPages());
    $this->assertNotContains($controls, $defaultPanel->getPages());
    $this->assertTrue($defaultPanel->getPlugin('ai-writer')->hasCreditsInTopbar());
    foreach (['controlsPage', 'creditsPage'] as $method) {
      try {
        $plugin->{$method}(\Filament\Pages\Page::class);
        $this->fail('Unrelated or abstract pages must not replace protected plugin pages.');
      } catch (\InvalidArgumentException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  protected function setUp(): void
  {
    parent::setUp();

    Filament::setCurrentPanel(Filament::getPanel('demo'));
  }
}
