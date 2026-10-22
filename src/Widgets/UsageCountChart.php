<?php

namespace PdroLucas\FilamentAiWriter\Widgets;

use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Livewire\Attributes\Locked;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Usage\UsageStatistics;

class UsageCountChart extends ChartWidget
{
  protected static bool $isLazy = false;
  protected ?string $pollingInterval = null;
  protected ?string $maxHeight = '120px';

  #[Locked]
  public string $panelId = '';

  #[Locked]
  public array $sharedFilters = [];

  public static function canView(): bool
  {
    return UsageStatistics::canAccess();
  }

  public function mount(): void
  {
    $this->authorizeContext();
    parent::mount();
  }

  public function hydrate(): void
  {
    $this->authorizeContext();
  }

  protected function authorizeContext(): void
  {
    abort_unless(UsageStatistics::canAccess() && $this->panelId === Filament::getCurrentPanel()->getId(), 403);
  }

  protected function chartRows(): array
  {
    $this->authorizeContext();
    return AiWriterPlugin::get()->statistics()->dashboard($this->sharedFilters)['charts'];
  }

  protected function getCachedData(): array
  {
    $this->authorizeContext();
    return parent::getCachedData();
  }

  protected function getData(): array
  {
    $rows = $this->chartRows()['time'];
    return [
      'labels' => array_column($rows, 'label'),
      'datasets' => [[
        'label' => 'Requests',
        'data' => array_map(fn (array $row): int => $row['delivered'] + $row['failed'] + $row['active'], $rows),
        'borderWidth' => 0, 'borderRadius' => 2, 'maxBarThickness' => 48,
      ]],
    ];
  }

  public function getHeading(): string
  {
    $this->authorizeContext();
    return 'Requests over time';
  }

  public function getDescription(): string
  {
    return $this->chartRows()['bucket_days'] . '-day buckets · ' . config('app.timezone', 'UTC');
  }

  protected function getType(): string
  {
    return 'bar';
  }

  protected function getOptions(): array
  {
    return [
      'plugins' => ['legend' => ['display' => false]],
      // Filament initializes animation.duration before handing options to Chart.js.
      'animation' => ['duration' => 0],
      'maintainAspectRatio' => false,
      'scales' => [
        'x' => ['grid' => ['display' => false], 'ticks' => ['maxTicksLimit' => 8, 'maxRotation' => 0]],
        'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0, 'maxTicksLimit' => 3]],
      ],
    ];
  }
}
