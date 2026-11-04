<?php

namespace PdroLucas\FilamentAiWriter\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use PdroLucas\FilamentAiWriter\Support\Decimal;
use PdroLucas\FilamentAiWriter\Widgets\Concerns\ReadsUsageStatistics;

/** Requests, tokens, costs and duration. Register it on any dashboard of a panel with the plugin; it follows the Statistics permission. */
class UsageOverviewStats extends StatsOverviewWidget
{
  use ReadsUsageStatistics;

  protected static bool $isLazy = false;
  protected ?string $pollingInterval = null;

  public function mount(): void
  {
    $this->bootUsageContext();
  }

  protected function getHeading(): ?string
  {
    return __('Usage overview');
  }

  protected function getDescription(): ?string
  {
    $period = $this->usageFilters()['period'] ?? [];

    return ($period['from'] ?? __('All history')) . ' — ' . ($period['until'] ?? __('Today')) . ' · ' . config('app.timezone', 'UTC');
  }

  protected function getStats(): array
  {
    $summary = $this->usageSummary();
    $unavailable = __('Unavailable');
    $attempts = $summary['attempts'];
    $knownCost = $summary['provider_cost_count'] + $summary['estimated_cost_count'];
    $cost = Decimal::sum($summary['provider_cost'], $summary['estimated_cost']);
    $tokens = Decimal::addIntegers($summary['input_tokens']['total'], $summary['output_tokens']['total']);
    $measuredTokens = $summary['input_tokens']['known'] + $summary['output_tokens']['known'];
    $duration = $summary['duration_ms'];

    return [
      Stat::make(__('Requests'), number_format($attempts))
        ->description(number_format($summary['delivered']) . ' ' . __('success') . ' · ' . number_format($summary['failed']) . ' ' . __('failure') . ' · ' . number_format($summary['active']) . ' ' . __('in progress')),
      Stat::make(__('Tokens consumed'), $measuredTokens ? $tokens : $unavailable)
        ->description(__('Input + output') . ($measuredTokens < $attempts * 2 ? ' · ' . __('Partial') : '') . ' · ' . $measuredTokens . '/' . ($attempts * 2) . ' ' . __('counters measured')),
      Stat::make(__('Costs · USD'), $knownCost ? '$' . rtrim(rtrim($cost, '0'), '.') : $unavailable)
        ->description(($summary['unknown_cost'] && $knownCost ? __('Partial') . ' · ' : '') . $knownCost . '/' . $attempts . ' ' . __('known') . ($summary['estimated_cost_count'] ? ' · ' . __('Includes estimates') : '')
          . ' · ' . __('Reported') . ': ' . ($summary['provider_cost_count'] ? $summary['provider_cost'] : $unavailable)
          . ' · ' . __('Estimated') . ': ' . ($summary['estimated_cost_count'] ? $summary['estimated_cost'] : $unavailable)),
      Stat::make(__('Average duration'), $duration['known'] ? number_format((float) $duration['total'] / $duration['known'] / 1000, 2) . ' s' : $unavailable)
        ->description('HTTP · ' . $duration['known'] . '/' . $attempts . ' ' . __('measured') . ($duration['unknown'] && $duration['known'] ? ' · ' . __('Partial') : '')),
    ];
  }
}
