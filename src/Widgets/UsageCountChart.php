<?php

namespace PdroLucas\FilamentAiWriter\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use PdroLucas\FilamentAiWriter\Support\HostDates;
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

  /** Screen-reader table with the same numbers as the bars; Filament renders it visually hidden. */
  public function getChartAssistiveContent(): string | View | Htmlable | null
  {
    $charts = $this->chartRows();

    return view('filament-ai-writer::usage-chart-data', [
      'rows' => array_map(fn (array $row): array => ['label' => $this->bucketLabel($row['label'])] + $row, $charts['time']), 'bucketDays' => $charts['bucket_days'], 'timezone' => HostDates::timezone(),
    ]);
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
      'labels' => array_map(fn (array $row): string => $this->bucketLabel($row['label']), $rows),
      'datasets' => [[
        'label' => __('filament-ai-writer::statistics.chart.series'),
        'data' => array_map(fn (array $row): int => $row['delivered'] + $row['failed'] + $row['active'], $rows),
        // Filament's outline carries the contrast: its fill alone is nearly invisible on dark panels.
        'borderRadius' => 2, 'maxBarThickness' => 48,
      ]],
    ];
  }

  public function getHeading(): string
  {
    $this->authorizeContext();
    return __('filament-ai-writer::statistics.chart.heading');
  }

  public function getDescription(): string
  {
    return __('filament-ai-writer::statistics.chart.description', ['days' => $this->chartRows()['bucket_days'], 'timezone' => HostDates::timezone()]);
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
