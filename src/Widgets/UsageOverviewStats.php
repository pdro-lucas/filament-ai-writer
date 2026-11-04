<?php

namespace PdroLucas\FilamentAiWriter\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use PdroLucas\FilamentAiWriter\Support\Currency;
use PdroLucas\FilamentAiWriter\Support\Decimal;
use PdroLucas\FilamentAiWriter\Support\HostDates;
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
    return __('filament-ai-writer::statistics.overview.heading');
  }

  protected function getDescription(): ?string
  {
    $period = $this->usageFilters()['period'] ?? [];
    $day = fn (?string $value, string $fallback): string => filled($value) ? HostDates::date($value) : __($fallback);

    return $day($period['from'] ?? null, 'filament-ai-writer::statistics.overview.all_history') . ' — '
      . $day($period['until'] ?? null, 'filament-ai-writer::statistics.overview.today') . ' · ' . HostDates::timezone();
  }

  protected function getStats(): array
  {
    $summary = $this->usageSummary();
    $t = fn (string $key, array $replace = []): string => __('filament-ai-writer::statistics.overview.' . $key, $replace);
    $unavailable = $t('unavailable');
    $attempts = $summary['attempts'];
    $knownCost = $summary['provider_cost_count'] + $summary['estimated_cost_count'];
    $cost = Decimal::sum($summary['provider_cost'], $summary['estimated_cost']);
    $tokens = Decimal::addIntegers($summary['input_tokens']['total'], $summary['output_tokens']['total']);
    $measuredTokens = $summary['input_tokens']['known'] + $summary['output_tokens']['known'];
    $duration = $summary['duration_ms'];
    $currency = Currency::code();

    return [
      Stat::make($t('requests'), number_format($attempts))
        ->description($t('requests_detail', ['delivered' => number_format($summary['delivered']), 'failed' => number_format($summary['failed']), 'active' => number_format($summary['active'])])),
      Stat::make($t('tokens'), $measuredTokens ? $tokens : $unavailable)
        ->description($t('tokens_detail', ['partial' => $measuredTokens < $attempts * 2 ? ' · ' . $t('partial') : '', 'measured' => $measuredTokens, 'total' => $attempts * 2])),
      Stat::make($t('cost', ['currency' => $currency]), $knownCost ? Currency::format($cost) : $unavailable)
        ->description($t('cost_detail', [
          'partial' => $summary['unknown_cost'] && $knownCost ? $t('partial') . ' · ' : '', 'known' => $knownCost, 'total' => $attempts,
          'estimates' => $summary['estimated_cost_count'] ? ' · ' . $t('includes_estimates') : '',
          'reported' => $summary['provider_cost_count'] ? $summary['provider_cost'] : $unavailable,
          'estimated' => $summary['estimated_cost_count'] ? $summary['estimated_cost'] : $unavailable,
        ])),
      Stat::make($t('duration'), $duration['known'] ? number_format((float) $duration['total'] / $duration['known'] / 1000, 2) . ' s' : $unavailable)
        ->description($t('duration_detail', ['known' => $duration['known'], 'total' => $attempts, 'partial' => $duration['unknown'] && $duration['known'] ? ' · ' . $t('partial') : ''])),
    ];
  }
}
