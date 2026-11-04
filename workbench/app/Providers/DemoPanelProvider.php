<?php

namespace Workbench\App\Providers;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use Workbench\App\Pages\WriterDemo;
use Workbench\App\Models\User;
use PdroLucas\FilamentAiWriter\Controls\ActorIdentity;

class DemoPanelProvider extends PanelProvider
{
  public function panel(Panel $panel): Panel
  {
    return $panel
      ->default()
      ->id('demo')
      ->path('demo')
      ->brandName('AI Writer Workbench')
      ->when(env('WORKBENCH_THEME') === 'custom', fn (Panel $panel): Panel => $panel
        // Visual QA: different colors, radius and font prove the plugin screens follow the host theme.
        ->colors(['primary' => Color::Emerald, 'gray' => Color::Stone, 'danger' => Color::Fuchsia])
        ->font('Georgia', provider: \Filament\FontProviders\LocalFontProvider::class)
        ->renderHook(PanelsRenderHook::HEAD_END, fn (): string => '<style>:root{--radius-md:0;--radius-lg:0;--radius-xl:1.5rem;--radius-2xl:1.5rem;--font-sans:Georgia,serif;--default-font-family:Georgia,serif}body{font-family:Georgia,serif}</style>'))
      ->authGuard('workbench')
      ->login()
      ->plugin(AiWriterPlugin::make()
        // The demo shows every module; tests may switch them off before the panel registers.
        ->usageTracking(fn (): bool => (bool) config('workbench.usage_tracking', true))
        // controls() requires usageTracking(), so it follows that switch unless configured on its own.
        ->controls(fn (): bool => (bool) config('workbench.controls', config('workbench.usage_tracking', true)))
        ->creditsPage()
        ->authorizeViewStatistics(fn () => true)
        ->authorizeManage(fn ($actor): bool => $actor instanceof User && $actor->email === 'demo@example.test')
        // This directory belongs to the demo host; the plugin never queries its User model.
        ->actorDirectoryUsing(
          search: fn (string $search): array => User::query()->where(function ($query) use ($search): void {
            $query->where('name', 'like', '%' . $search . '%')->orWhere('email', 'like', '%' . $search . '%');
          })->limit(50)->get()->map(fn (User $user): ActorIdentity => new ActorIdentity(User::class, $user->getAuthIdentifier(), $user->name . ' · ' . $user->email))->all(),
          resolve: function (string $type, string $id): ?ActorIdentity {
            $user = $type === User::class ? User::query()->find($id) : null;
            return $user === null ? null : new ActorIdentity(User::class, $user->getAuthIdentifier(), $user->name . ' · ' . $user->email);
          },
        ))
      ->pages([WriterDemo::class])
      ->middleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        VerifyCsrfToken::class,
        SubstituteBindings::class,
        DisableBladeIconComponents::class,
        DispatchServingFilamentEvent::class,
      ])
      ->authMiddleware([Authenticate::class]);
  }
}
