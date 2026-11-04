<?php

namespace PdroLucas\FilamentAiWriter\Tests\Fixtures;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;

/** A second panel whose plugin is configured by the test through config('ai-writer-test.plugin'). */
class NavigationPanelProvider extends PanelProvider
{
  public function panel(Panel $panel): Panel
  {
    $plugin = AiWriterPlugin::make()->usageTracking()->controls()
      ->authorizeViewStatistics(true)->authorizeManage(true);
    config('ai-writer-test.plugin')($plugin);

    return $panel->id('nav')->path('nav')->authGuard('workbench')->login()
      ->plugin($plugin)
      ->discoverClusters(in: __DIR__, for: __NAMESPACE__)
      ->middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, ShareErrorsFromSession::class,
        VerifyCsrfToken::class, SubstituteBindings::class, DisableBladeIconComponents::class, DispatchServingFilamentEvent::class])
      ->authMiddleware([Authenticate::class]);
  }
}
