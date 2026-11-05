<?php

namespace PdroLucas\FilamentAiWriter\Pages;

use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Concerns\UsesPluginNavigation;
use PdroLucas\FilamentAiWriter\Controls\ControlContext;

class MyAiCreditsPage extends Page
{
  use UsesPluginNavigation;

  protected static ?string $title = null;
  protected static ?string $slug = 'my-ai-credits';

  protected static function pluginPage(): string
  {
    return 'credits';
  }
  protected string $view = 'filament-ai-writer::my-ai-credits';

  #[Locked]
  public array $controlSnapshot;

  public static function canAccess(): bool
  {
    $plugin = AiWriterPlugin::get();
    return $plugin->isControlsEnabled() && $plugin->canUseAi();
  }

  public function mount(): void
  {
    abort_unless(static::canAccess(), 403);
    $this->controlSnapshot = AiWriterPlugin::get()->controlContext()->snapshot();
  }

  public function hydrate(): void
  {
    $this->context();
  }

  protected function context(): ControlContext
  {
    abort_unless(static::canAccess(), 403);
    $context = AiWriterPlugin::get()->controlContext();
    abort_unless($context->snapshot() === $this->controlSnapshot, 403);
    return $context;
  }

  protected function getViewData(): array
  {
    $plugin = AiWriterPlugin::get();
    $context = $this->context();
    // Recovery precedes balance so expired reservations are reflected here.
    $abuse = $plugin->abuse()->ownStatus($context);
    $balance = $plugin->credits()->ownBalance($context);
    $status = $plugin->controlsService()->ownStatus($context);
    return ['balance' => $balance, 'abuse' => $abuse, 'enabled' => $status['enabled'], 'blocked' => $status['blocked']];
  }
}
