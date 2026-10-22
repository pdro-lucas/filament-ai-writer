<?php

namespace Workbench\App\Providers;

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
      ->authGuard('workbench')
      ->login()
      ->plugin(AiWriterPlugin::make()
        ->authorizeViewStatisticsUsing(fn () => true)
        ->authorizeManageUsing(fn ($actor): bool => $actor instanceof User && $actor->email === 'demo@example.test')
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
