<?php

namespace PdroLucas\FilamentAiWriter\Widgets;

use Carbon\CarbonImmutable;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Js;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Support\Currency;
use PdroLucas\FilamentAiWriter\Support\Decimal;
use PdroLucas\FilamentAiWriter\Support\HostDates;
use PdroLucas\FilamentAiWriter\Usage\UsageStatistics;
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
      . $day($period['until'] ?? null, 'filament-ai-writer::statistics.overview.today') . ' · ' . HostDates::timezone() . $this->activeFiltersText();
  }

  /** The user and field the page is narrowed to, so a filtered view is never mistaken for the general one. */
  protected function activeFiltersText(): string
  {
    $filters = $this->usageFilters();
    $text = '';
    if (filled($key = $filters['actor']['value'] ?? null)) {
      $actor = json_decode(base64_decode((string) $key, true) ?: '', true);
      $label = is_array($actor) && count($actor) === 2 && is_string($actor[0]) && is_string($actor[1])
        ? UsageStatistics::splitActorLabel(AiWriterPlugin::get()->statistics()->actorLabel($actor[0], $actor[1]))[0]
        : __('filament-ai-writer::statistics.table.user_unavailable');
      $text .= ' · ' . __('filament-ai-writer::statistics.overview.filter_user', ['name' => $label]);
    }
    if (filled($field = $filters['field']['value'] ?? null)) {
      $text .= ' · ' . __('filament-ai-writer::statistics.overview.filter_field', ['name' => $field]);
    }

    return $text;
  }

  protected function getStats(): array
  {
    $summary = $this->usageSummary();
    $previous = $this->previousSummary();
    $t = fn (string $key, array $replace = []): string => __('filament-ai-writer::statistics.overview.' . $key, $replace);
    $unavailable = $t('unavailable');
    $attempts = $summary['attempts'];
    $knownCost = $summary['provider_cost_count'] + $summary['estimated_cost_count'];
    $cost = Decimal::sum($summary['provider_cost'], $summary['estimated_cost']);
    $tokens = Decimal::addIntegers($summary['input_tokens']['total'], $summary['output_tokens']['total']);
    $measuredTokens = $summary['input_tokens']['known'] + $summary['output_tokens']['known'];
    $duration = $summary['duration_ms'];
    $currency = Currency::code();
    $series = $this->sparklines();
    // A metric nobody measured is null, not zero: there is nothing to compare.
    $metrics = fn (array $s): array => [
      'requests' => $s['attempts'],
      'tokens' => $s['input_tokens']['known'] + $s['output_tokens']['known'] ? (float) Decimal::addIntegers($s['input_tokens']['total'], $s['output_tokens']['total']) : null,
      'cost' => $s['provider_cost_count'] + $s['estimated_cost_count'] ? (float) Decimal::sum($s['provider_cost'], $s['estimated_cost']) : null,
      'duration' => $s['duration_ms']['known'] ? (float) $s['duration_ms']['total'] / $s['duration_ms']['known'] / 1000 : null,
    ];
    $now = $metrics($summary);
    $before = $previous === null ? null : $metrics($previous);

    return [
      $this->stat(Stat::make($t('requests'), Number::format($attempts)), 'requests', $now, $before, $series,
        $t('requests_detail', ['delivered' => number_format($summary['delivered']), 'failed' => number_format($summary['failed']), 'active' => number_format($summary['active'])])),
      $this->stat(Stat::make($t('tokens'), $measuredTokens ? (strlen($tokens) <= 15 ? Number::format((int) $tokens) : $tokens) : $unavailable), 'tokens', $now, $before, $series,
        $t('tokens_detail', ['partial' => $measuredTokens < $attempts * 2 ? ' · ' . $t('partial') : '', 'measured' => $measuredTokens, 'total' => $attempts * 2])),
      $this->stat(Stat::make($t('cost', ['currency' => $currency]), $knownCost ? Currency::format($cost) : $unavailable), 'cost', $now, $before, $series,
        $t('cost_detail', [
          'partial' => $summary['unknown_cost'] && $knownCost ? $t('partial') . ' · ' : '', 'known' => $knownCost, 'total' => $attempts,
          'estimates' => $summary['estimated_cost_count'] ? ' · ' . $t('includes_estimates') : '',
          'reported' => $summary['provider_cost_count'] ? Currency::format($summary['provider_cost']) : $unavailable,
          'estimated' => $summary['estimated_cost_count'] ? Currency::format($summary['estimated_cost']) : $unavailable,
        ])),
      $this->stat(Stat::make($t('duration'), $duration['known'] ? number_format((float) $duration['total'] / $duration['known'] / 1000, 2) . ' s' : $unavailable), 'duration', $now, $before, $series,
        $t('duration_detail', ['known' => $duration['known'], 'total' => $attempts, 'partial' => $duration['unknown'] && $duration['known'] ? ' · ' . $t('partial') : ''])),
    ];
  }

  /** One short comparison line, the sparkline over the period and the technical coverage notes as a tooltip. */
  protected function stat(Stat $stat, string $metric, array $now, ?array $before, array $series, string $detail): Stat
  {
    $change = $before === null ? null : UsageStatistics::change($now[$metric], $before[$metric]);
    $stat->descriptionColor('gray')->chartColor('gray')->chart($series[$metric] ?: null)
      ->extraAttributes(['x-tooltip' => '{ content: ' . Js::from($detail) . ', theme: $store.theme }']);
    if ($change === null) {
      return $stat->description(__('filament-ai-writer::statistics.overview.no_previous'));
    }
    $icon = abs($change) < 0.05 ? Heroicon::Minus : ($change > 0 ? Heroicon::ArrowTrendingUp : Heroicon::ArrowTrendingDown);

    return $stat->descriptionIcon($icon, IconPosition::Before)
      ->description(__('filament-ai-writer::statistics.overview.vs_previous', ['percent' => Number::format(abs($change), 1) . '%']));
  }

  /** @return array<string, mixed>|null The same filters over the window right before the period; null when the period is open. */
  protected function previousSummary(): ?array
  {
    $filters = $this->usageFilters();
    $period = AiWriterPlugin::get()->statistics()->previousPeriod($filters['period'] ?? []);

    return $period === null ? null : AiWriterPlugin::get()->statistics()->dashboard(['period' => $period] + $filters);
  }

  /**
   * Sparkline points per metric, one per chart bucket; single-day buckets are padded with zeros across the whole period.
   *
   * @return array<string, array<string, float>>
   */
  protected function sparklines(): array
  {
    $charts = $this->usageSummary()['charts'];
    $points = ['requests' => [], 'tokens' => [], 'cost' => [], 'duration' => []];
    foreach ($charts['series'] as $index => $row) {
      $requests = $charts['time'][$index];
      $points['requests'][$row['label']] = (float) ($requests['delivered'] + $requests['failed'] + $requests['active']);
      foreach (['tokens', 'cost', 'duration'] as $metric) {
        $points[$metric][$row['label']] = $row[$metric];
      }
    }
    $period = $this->usageFilters()['period'] ?? [];
    $from = is_string($period['from'] ?? null) ? CarbonImmutable::createFromFormat('!Y-m-d', $period['from'], 'UTC') : false;
    $until = is_string($period['until'] ?? null) ? CarbonImmutable::createFromFormat('!Y-m-d', $period['until'], 'UTC') : false;
    if ($charts['bucket_days'] === 1 && $from && $until && $from <= $until && $from->diffInDays($until) < 60) {
      foreach ($points as $metric => $byDay) {
        $padded = [];
        for ($day = $from; $day <= $until; $day = $day->addDay()) {
          $padded[$day->format('Y-m-d')] = $byDay[$day->format('Y-m-d')] ?? 0.0;
        }
        $points[$metric] = $padded;
      }
    }
    // Labels are shown in the host's date format.
    foreach ($points as $metric => $byLabel) {
      $points[$metric] = array_combine(array_map(fn (string $label): string => $this->bucketLabel($label), array_keys($byLabel)), $byLabel);
    }

    return $points;
  }
}
