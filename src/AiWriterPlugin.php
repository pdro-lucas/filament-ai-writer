<?php

namespace PdroLucas\FilamentAiWriter;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use LogicException;
use PdroLucas\FilamentAiWriter\Generation\Generation;
use PdroLucas\FilamentAiWriter\Usage\UsageStatistics;
use PdroLucas\FilamentAiWriter\Pages\UsageStatisticsPage;

class AiWriterPlugin implements Plugin
{
  protected ?Panel $panel = null;

  protected ?Closure $useAiAuthorization = null;
  protected ?Closure $viewStatisticsAuthorization = null;
  protected ?Closure $manageAuthorization = null;

  public static function make(): static
  {
    return app(static::class);
  }

  public static function get(): static
  {
    $panel = Filament::getCurrentPanel();
    $plugin = $panel?->hasPlugin('ai-writer') ? $panel->getPlugin('ai-writer') : null;

    if (!$plugin instanceof static) {
      throw new LogicException('Register AiWriterPlugin::make() with ->plugin() in this panel\'s PanelProvider before using AiWriterAction.');
    }

    return $plugin;
  }

  public function generation(): Generation
  {
    return app(Generation::class);
  }

  public function statistics(): UsageStatistics
  {
    return app(UsageStatistics::class);
  }

  public function getId(): string
  {
    return 'ai-writer';
  }

  public function register(Panel $panel): void
  {
    if ($this->panel !== null && $this->panel !== $panel) {
      throw new LogicException('Create a separate AiWriterPlugin instance for each panel.');
    }

    $this->panel = $panel;
    $panel->pages([UsageStatisticsPage::class]);
  }

  public function boot(Panel $panel): void {}

  /** @param Closure(\Illuminate\Contracts\Auth\Authenticatable, Panel): bool $callback */
  public function authorizeUseAiUsing(Closure $callback): static
  {
    $this->useAiAuthorization = $callback;

    return $this;
  }

  /** @param Closure(\Illuminate\Contracts\Auth\Authenticatable, Panel): bool $callback */
  public function authorizeViewStatisticsUsing(Closure $callback): static
  {
    $this->viewStatisticsAuthorization = $callback;

    return $this;
  }

  /** @param Closure(\Illuminate\Contracts\Auth\Authenticatable, Panel): bool $callback */
  public function authorizeManageUsing(Closure $callback): static
  {
    $this->manageAuthorization = $callback;

    return $this;
  }

  public function canUseAi(): bool
  {
    return $this->authorize($this->useAiAuthorization, true);
  }

  public function canViewStatistics(): bool
  {
    return $this->authorize($this->viewStatisticsAuthorization, false);
  }

  public function canManage(): bool
  {
    return $this->authorize($this->manageAuthorization, false);
  }

  protected function authorize(?Closure $callback, bool $default): bool
  {
    $user = $this->panel?->auth()->user();

    if ($user === null) {
      return false;
    }

    return $callback === null ? $default : (bool) $callback($user, $this->panel);
  }
}
