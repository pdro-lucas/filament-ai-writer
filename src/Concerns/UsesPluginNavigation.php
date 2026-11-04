<?php

namespace PdroLucas\FilamentAiWriter\Concerns;

use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\PageConfiguration;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use UnitEnum;

/**
 * Lets a plugin page take its navigation from the AiWriterPlugin settings of the panel it runs in.
 * A value set on the plugin wins over the page's static properties; without one the page falls back to them.
 * Pages using it define `pluginPage()`: 'statistics', 'controls' or 'credits'.
 */
trait UsesPluginNavigation
{
  /** The panel whose routes are being registered; Filament does not make it current yet, but the cluster is read then. */
  private static ?Panel $registeringPanel = null;

  abstract protected static function pluginPage(): string;

  public static function registerRoutes(Panel $panel, ?PageConfiguration $configuration = null): void
  {
    $previous = self::$registeringPanel;
    self::$registeringPanel = $panel;
    try {
      parent::registerRoutes($panel, $configuration);
    } finally {
      self::$registeringPanel = $previous;
    }
  }

  protected static function pluginNavigation(string $key, ?Panel $panel = null): mixed
  {
    $panel ??= self::$registeringPanel ?? Filament::getCurrentPanel();
    $plugin = $panel?->hasPlugin('ai-writer') ? $panel->getPlugin('ai-writer') : null;
    if (!$plugin instanceof AiWriterPlugin) {
      return null;
    }
    $method = match ($key) {
      'navigation' => 'has' . ucfirst(static::pluginPage()) . 'Navigation',
      'slug', 'cluster' => 'get' . ucfirst(static::pluginPage()) . ucfirst($key),
      default => 'get' . ucfirst(static::pluginPage()) . 'Navigation' . ucfirst($key),
    };
    return $plugin->{$method}();
  }

  public static function getNavigationGroup(): string|UnitEnum|null
  {
    $group = static::pluginNavigation('group');
    if ($group === false) {
      return null;
    }

    // The built-in group is a default for loose pages only; a cluster already is their parent.
    return $group ?? parent::getNavigationGroup() ?? (filled(static::getCluster()) ? null : __('filament-ai-writer::navigation.group'));
  }

  public static function getNavigationLabel(): string|Htmlable
  {
    return static::pluginNavigation('label') ?? static::$navigationLabel ?? static::$title ?? __('filament-ai-writer::navigation.' . static::pluginPage());
  }

  public function getTitle(): string|Htmlable
  {
    return static::$title ?? __('filament-ai-writer::navigation.' . static::pluginPage());
  }

  public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
  {
    return static::pluginNavigation('icon') ?? parent::getNavigationIcon();
  }

  public static function getNavigationSort(): ?int
  {
    return static::pluginNavigation('sort') ?? parent::getNavigationSort();
  }

  public static function getCluster(): ?string
  {
    return static::pluginNavigation('cluster') ?? parent::getCluster();
  }

  public static function getSlug(?Panel $panel = null): string
  {
    return static::pluginNavigation('slug', $panel) ?? parent::getSlug($panel);
  }

  public static function shouldRegisterNavigation(): bool
  {
    return (static::pluginNavigation('navigation') ?? true) && parent::shouldRegisterNavigation();
  }
}
