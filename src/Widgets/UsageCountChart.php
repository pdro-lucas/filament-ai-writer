<?php

namespace PdroLucas\FilamentAiWriter\Widgets;

use Filament\Widgets\ChartWidget;
use PdroLucas\FilamentAiWriter\Widgets\Concerns\ReadsUsageStatistics;

class UsageCountChart extends ChartWidget
{
  use ReadsUsageStatistics;

  protected static bool $isLazy = false;
  protected ?string $pollingInterval = null;
  protected ?string $maxHeight = '120px';

  public function mount(): void
  {
    $this->bootUsageContext();
    parent::mount();
  }

  protected function chartRows(): array
  {
    $this->authorizeContext();
    return $this->usageSummary()['charts'];
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
        'label' => __('Requests'),
        'data' => array_map(fn (array $row): int => $row['delivered'] + $row['failed'] + $row['active'], $rows),
        'borderWidth' => 0, 'borderRadius' => 2, 'maxBarThickness' => 48,
      ]],
    ];
  }

  public function getHeading(): string
  {
    $this->authorizeContext();
    return __('Requests over time');
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
