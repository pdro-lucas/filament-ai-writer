<?php

namespace PdroLucas\FilamentAiWriter\Tests;

use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use ReflectionClass;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Pages\AiControlsPage;
use PdroLucas\FilamentAiWriter\Pages\MyAiCreditsPage;
use PdroLucas\FilamentAiWriter\Pages\UsageStatisticsPage;
use PdroLucas\FilamentAiWriter\Tests\Fixtures\AbstractStatisticsPage;
use PdroLucas\FilamentAiWriter\Tests\Fixtures\ClusteredCreditsPage;
use PdroLucas\FilamentAiWriter\Tests\Fixtures\HostStatisticsPage;
use PdroLucas\FilamentAiWriter\Tests\Fixtures\Clusters\NavigationCluster;
use PdroLucas\FilamentAiWriter\Tests\Fixtures\NavigationPanelProvider;
use PdroLucas\FilamentAiWriter\Tests\Fixtures\AnyPanelUser;
use Workbench\Database\Seeders\DatabaseSeeder;

class PluginNavigationTest extends TestCase
{
  use DatabaseMigrations;

  #[DefineEnvironment('navigationConfigured')]
  public function test_pages_are_placed_by_the_plugin_with_page_values_over_the_default_and_closures_per_request(): void
  {
    $this->signIn();
    $nav = fn (string $class): array => [
      'group' => $class::getNavigationGroup(), 'label' => $class::getNavigationLabel(), 'icon' => $class::getNavigationIcon(),
      'sort' => $class::getNavigationSort(), 'cluster' => $class::getCluster(), 'visible' => $class::shouldRegisterNavigation(),
    ];

    $this->assertSame(['group' => null, 'label' => 'Usage', 'icon' => 'heroicon-o-chart-bar', 'sort' => 7, 'cluster' => null, 'visible' => true], $nav(UsageStatisticsPage::class));
    // Controls overrides the plugin default group; statistics opts out of any group (false); credits sits in a cluster and inherits the plugin-wide group.
    $this->assertSame(['group' => 'Own group', 'label' => 'AI Writer controls', 'icon' => 'heroicon-o-cog', 'sort' => null, 'cluster' => null, 'visible' => true], $nav(AiControlsPage::class));
    $this->assertSame(['group' => 'Host tools', 'label' => 'My AI credits', 'icon' => 'heroicon-o-cog', 'sort' => null, 'cluster' => NavigationCluster::class, 'visible' => false], $nav(MyAiCreditsPage::class));
    $this->assertSame('/nav/host-cluster/credits-here', parse_url(MyAiCreditsPage::getUrl(panel: 'nav'), PHP_URL_PATH));
    $this->assertSame('/nav/stats', parse_url(UsageStatisticsPage::getUrl(panel: 'nav'), PHP_URL_PATH));

    // The plugin-wide default applies to a page with no value of its own, and Closures are read on every use.
    $plugin = AiWriterPlugin::get();
    $this->assertSame('Host tools', $plugin->getNavigationGroup());
    config(['ai-writer-test.group' => 'Changed on the next request']);
    $this->assertSame('Changed on the next request', $plugin->getNavigationGroup());
    $this->get('/nav/stats')->assertOk();
    $this->get('/nav/host-cluster/credits-here')->assertOk();
  }

  #[DefineEnvironment('navigationDefaults')]
  public function test_default_navigation_is_a_translatable_group_and_credits_page_has_no_route(): void
  {
    $this->signIn();
    $this->assertSame('AI Writer', UsageStatisticsPage::getNavigationGroup());
    // The built-in group is for loose pages: a page in a cluster (here from a subclass) gets none.
    $this->assertNull(ClusteredCreditsPage::getNavigationGroup());
    $this->assertSame('ai-writer-usage', UsageStatisticsPage::getSlug(Filament::getPanel('nav')));
    $this->assertNull(AiWriterPlugin::get()->getCreditsPage());
    $this->get('/nav/my-ai-credits')->assertNotFound();
  }

  #[DefineEnvironment('navigationReplaced')]
  public function test_a_subclass_replaces_the_statistics_page_without_duplicating_the_route_and_a_disabled_page_has_no_route(): void
  {
    $this->signIn();
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route): bool => $route->uri() === 'nav/ai-writer-usage');
    $this->assertCount(1, $routes);
    $this->assertSame(HostStatisticsPage::class, $routes->first()->getAction('controller'));
    $this->get('/nav/ai-writer-usage')->assertOk();
    $this->get('/nav/ai-writer-controls')->assertNotFound();

    // Disabling the interface leaves the service and its permissions untouched.
    $plugin = AiWriterPlugin::get();
    $this->assertTrue($plugin->canManage());
    $plugin->controlsService()->authorize($plugin->controlContext());
    $plugin->authorizeManage(false);
    $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $plugin->controlsService()->authorize($plugin->controlContext());
  }

  public function test_page_settings_reject_abstract_and_unrelated_classes(): void
  {
    foreach (['statisticsPage' => [AiControlsPage::class, \Filament\Pages\Page::class], 'controlsPage' => [UsageStatisticsPage::class], 'creditsPage' => [AiControlsPage::class]] as $setter => $classes) {
      foreach ([...$classes, 'Missing\\Page'] as $class) {
        try {
          AiWriterPlugin::make()->{$setter}($class);
          $this->fail($setter . ' accepted ' . $class);
        } catch (InvalidArgumentException $exception) {
          $this->assertStringContainsString('page must be a concrete', $exception->getMessage());
        }
      }
    }
    $abstract = new ReflectionClass(AbstractStatisticsPage::class);
    $this->assertTrue($abstract->isAbstract());
    $this->expectException(InvalidArgumentException::class);
    AiWriterPlugin::make()->statisticsPage($abstract->getName());
  }

  #[DefineEnvironment('indicatorOnCustomHook')]
  public function test_indicator_renders_only_in_the_configured_hook_with_its_own_icon_color_and_label(): void
  {
    $this->signIn();
    $plugin = AiWriterPlugin::get();
    $plugin->credits()->configure($plugin->controlContext(), true, 5, 'manual', 'Start');
    Filament::bootCurrentPanel();

    $hook = FilamentView::renderHook(PanelsRenderHook::SIDEBAR_NAV_START)->toHtml();
    $this->assertStringContainsString('data-ai-controls="credits-indicator"', $hook);
    $this->assertStringNotContainsString('data-ai-controls="credits-indicator"', FilamentView::renderHook(PanelsRenderHook::USER_MENU_BEFORE)->toHtml());
    $this->assertSame('heroicon-o-bolt', $plugin->getCreditsIndicatorIcon());
    $this->assertSame('warning', $plugin->getCreditsIndicatorColor());
    $this->assertSame('Balance 5', $plugin->getCreditsIndicatorLabel(['available' => 5]));
    \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Livewire\OwnCreditsIndicator::class)
      ->assertSee('Balance 5')->assertSee('Next renewal')->assertSee('Used this period');
  }

  protected function navigationConfigured($app): void
  {
    $this->panel($app, function (AiWriterPlugin $plugin): void {
      $plugin->navigationGroup(fn (): string => config('ai-writer-test.group', 'Host tools'))->navigationIcon('heroicon-o-cog')
        ->statisticsNavigationGroup(false)->controlsNavigationGroup('Own group')->statisticsNavigationLabel('Usage')->statisticsNavigationIcon('heroicon-o-chart-bar')->statisticsNavigationSort(7)->statisticsSlug('stats')
        ->creditsPage()->creditsCluster(NavigationCluster::class)->creditsSlug('credits-here')->creditsNavigation(false);
    });
  }

  protected function navigationDefaults($app): void
  {
    $this->panel($app, fn (AiWriterPlugin $plugin) => $plugin);
  }

  protected function navigationReplaced($app): void
  {
    $this->panel($app, fn (AiWriterPlugin $plugin) => $plugin->statisticsPage(HostStatisticsPage::class)->controlsPage(false));
  }

  protected function indicatorOnCustomHook($app): void
  {
    $this->panel($app, fn (AiWriterPlugin $plugin) => $plugin->creditsIndicatorRenderHook(PanelsRenderHook::SIDEBAR_NAV_START)
      ->creditsIndicatorIcon('heroicon-o-bolt')->creditsIndicatorColor('warning')
      ->creditsIndicatorLabel(fn (array $balance): string => 'Balance ' . $balance['available']));
  }

  private function panel($app, \Closure $configure): void
  {
    $app['config']->set('ai-writer-test.plugin', $configure);
    $app->register(NavigationPanelProvider::class);
  }

  private function signIn(): void
  {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(AnyPanelUser::firstOrFail(), 'workbench');
    Filament::setCurrentPanel(Filament::getPanel('nav'));
  }
}
