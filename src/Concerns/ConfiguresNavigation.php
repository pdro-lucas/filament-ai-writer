<?php

namespace PdroLucas\FilamentAiWriter\Concerns;

use BackedEnum;
use Closure;
use Filament\Panel;
use UnitEnum;

/**
 * Navigation of the plugin pages. Each page has its own prefixed setters (`controlsNavigationGroup()`, `statisticsSlug()`,
 * `creditsCluster()` ...) and group, icon, sort, cluster and menu visibility also have a plugin-wide default
 * (`navigationGroup()`, `cluster()`, `navigation()` ...) that every page falls back to. A page value wins over the default.
 * Values are plain or Closures evaluated on use with `$panel`; null clears a value.
 */
trait ConfiguresNavigation
{
  /** @var array<string, array<string, mixed>> */
  protected array $navigationOptions = [];

  protected function setNavigationOption(string $page, string $key, mixed $value): static
  {
    $this->navigationOptions[$page][$key] = $value;
    return $this;
  }

  /** Page value, else plugin-wide default; null when neither is set. */
  protected function getNavigationOption(string $page, string $key): mixed
  {
    $value = $this->navigationOptions[$page][$key] ?? $this->navigationOptions['*'][$key] ?? null;
    return $value instanceof Closure ? $this->evaluate($value, ['panel' => $this->panel], [Panel::class => $this->panel]) : $value;
  }

  /** Plugin-wide default for every page. */
  public function navigationGroup(string|UnitEnum|Closure|null $value): static
  {
    return $this->setNavigationOption('*', 'group', $value);
  }

  public function getNavigationGroup(): mixed
  {
    return $this->getNavigationOption('*', 'group');
  }

  /** Plugin-wide default for every page. */
  public function navigationIcon(string|BackedEnum|Closure|null $value): static
  {
    return $this->setNavigationOption('*', 'icon', $value);
  }

  public function getNavigationIcon(): mixed
  {
    return $this->getNavigationOption('*', 'icon');
  }

  /** Plugin-wide default for every page. */
  public function navigationSort(int|Closure|null $value): static
  {
    return $this->setNavigationOption('*', 'sort', $value);
  }

  public function getNavigationSort(): ?int
  {
    return $this->getNavigationOption('*', 'sort');
  }

  /** Plugin-wide default for every page. */
  public function cluster(string|Closure|null $value): static
  {
    return $this->setNavigationOption('*', 'cluster', $value);
  }

  public function getCluster(): ?string
  {
    return $this->getNavigationOption('*', 'cluster');
  }

  /** Plugin-wide default for every page. */
  public function navigation(bool|Closure|null $value): static
  {
    return $this->setNavigationOption('*', 'navigation', $value);
  }

  public function hasNavigation(): ?bool
  {
    return $this->getNavigationOption('*', 'navigation');
  }

  public function statisticsNavigationGroup(string|UnitEnum|Closure|null $value): static
  {
    return $this->setNavigationOption('statistics', 'group', $value);
  }

  public function getStatisticsNavigationGroup(): mixed
  {
    return $this->getNavigationOption('statistics', 'group');
  }

  public function statisticsNavigationLabel(string|Closure|null $value): static
  {
    return $this->setNavigationOption('statistics', 'label', $value);
  }

  public function getStatisticsNavigationLabel(): ?string
  {
    return $this->getNavigationOption('statistics', 'label');
  }

  public function statisticsNavigationIcon(string|BackedEnum|Closure|null $value): static
  {
    return $this->setNavigationOption('statistics', 'icon', $value);
  }

  public function getStatisticsNavigationIcon(): mixed
  {
    return $this->getNavigationOption('statistics', 'icon');
  }

  public function statisticsNavigationSort(int|Closure|null $value): static
  {
    return $this->setNavigationOption('statistics', 'sort', $value);
  }

  public function getStatisticsNavigationSort(): ?int
  {
    return $this->getNavigationOption('statistics', 'sort');
  }

  public function statisticsSlug(string|Closure|null $value): static
  {
    return $this->setNavigationOption('statistics', 'slug', $value);
  }

  public function getStatisticsSlug(): ?string
  {
    return $this->getNavigationOption('statistics', 'slug');
  }

  public function statisticsCluster(string|Closure|null $value): static
  {
    return $this->setNavigationOption('statistics', 'cluster', $value);
  }

  public function getStatisticsCluster(): ?string
  {
    return $this->getNavigationOption('statistics', 'cluster');
  }

  public function statisticsNavigation(bool|Closure|null $value): static
  {
    return $this->setNavigationOption('statistics', 'navigation', $value);
  }

  public function hasStatisticsNavigation(): ?bool
  {
    return $this->getNavigationOption('statistics', 'navigation');
  }

  public function controlsNavigationGroup(string|UnitEnum|Closure|null $value): static
  {
    return $this->setNavigationOption('controls', 'group', $value);
  }

  public function getControlsNavigationGroup(): mixed
  {
    return $this->getNavigationOption('controls', 'group');
  }

  public function controlsNavigationLabel(string|Closure|null $value): static
  {
    return $this->setNavigationOption('controls', 'label', $value);
  }

  public function getControlsNavigationLabel(): ?string
  {
    return $this->getNavigationOption('controls', 'label');
  }

  public function controlsNavigationIcon(string|BackedEnum|Closure|null $value): static
  {
    return $this->setNavigationOption('controls', 'icon', $value);
  }

  public function getControlsNavigationIcon(): mixed
  {
    return $this->getNavigationOption('controls', 'icon');
  }

  public function controlsNavigationSort(int|Closure|null $value): static
  {
    return $this->setNavigationOption('controls', 'sort', $value);
  }

  public function getControlsNavigationSort(): ?int
  {
    return $this->getNavigationOption('controls', 'sort');
  }

  public function controlsSlug(string|Closure|null $value): static
  {
    return $this->setNavigationOption('controls', 'slug', $value);
  }

  public function getControlsSlug(): ?string
  {
    return $this->getNavigationOption('controls', 'slug');
  }

  public function controlsCluster(string|Closure|null $value): static
  {
    return $this->setNavigationOption('controls', 'cluster', $value);
  }

  public function getControlsCluster(): ?string
  {
    return $this->getNavigationOption('controls', 'cluster');
  }

  public function controlsNavigation(bool|Closure|null $value): static
  {
    return $this->setNavigationOption('controls', 'navigation', $value);
  }

  public function hasControlsNavigation(): ?bool
  {
    return $this->getNavigationOption('controls', 'navigation');
  }

  public function creditsNavigationGroup(string|UnitEnum|Closure|null $value): static
  {
    return $this->setNavigationOption('credits', 'group', $value);
  }

  public function getCreditsNavigationGroup(): mixed
  {
    return $this->getNavigationOption('credits', 'group');
  }

  public function creditsNavigationLabel(string|Closure|null $value): static
  {
    return $this->setNavigationOption('credits', 'label', $value);
  }

  public function getCreditsNavigationLabel(): ?string
  {
    return $this->getNavigationOption('credits', 'label');
  }

  public function creditsNavigationIcon(string|BackedEnum|Closure|null $value): static
  {
    return $this->setNavigationOption('credits', 'icon', $value);
  }

  public function getCreditsNavigationIcon(): mixed
  {
    return $this->getNavigationOption('credits', 'icon');
  }

  public function creditsNavigationSort(int|Closure|null $value): static
  {
    return $this->setNavigationOption('credits', 'sort', $value);
  }

  public function getCreditsNavigationSort(): ?int
  {
    return $this->getNavigationOption('credits', 'sort');
  }

  public function creditsSlug(string|Closure|null $value): static
  {
    return $this->setNavigationOption('credits', 'slug', $value);
  }

  public function getCreditsSlug(): ?string
  {
    return $this->getNavigationOption('credits', 'slug');
  }

  public function creditsCluster(string|Closure|null $value): static
  {
    return $this->setNavigationOption('credits', 'cluster', $value);
  }

  public function getCreditsCluster(): ?string
  {
    return $this->getNavigationOption('credits', 'cluster');
  }

  public function creditsNavigation(bool|Closure|null $value): static
  {
    return $this->setNavigationOption('credits', 'navigation', $value);
  }

  public function hasCreditsNavigation(): ?bool
  {
    return $this->getNavigationOption('credits', 'navigation');
  }
}
