<?php

namespace PdroLucas\FilamentAiWriter\Usage;

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use PdroLucas\FilamentAiWriter\Support\HostDates;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Controls\ActorIdentity;
use PdroLucas\FilamentAiWriter\Models\UsageAttempt;
use PdroLucas\FilamentAiWriter\Support\Decimal;

class UsageStatistics
{
  public static function canAccess(): bool
  {
    $panel = Filament::getCurrentPanel();
    $plugin = $panel?->hasPlugin('ai-writer') ? $panel->getPlugin('ai-writer') : null;
    return $plugin instanceof AiWriterPlugin && $plugin->isUsageTrackingEnabled() && $plugin->canViewStatistics()
      && (!($panel->auth()->user() instanceof FilamentUser) || $panel->auth()->user()->canAccessPanel($panel));
  }

  public function query(): Builder
  {
    abort_unless(self::canAccess(), 403);
    return UsageAttempt::query()->where('panel_id', Filament::getCurrentPanel()->getId());
  }

  /** Always rescope a caller's filtered query before reading any summary rows. */
  public function summary(Builder $filtered, bool $includeCharts = false): array
  {
    $query = $this->query()->whereIn('id', (clone $filtered)->reorder()->select('id'));
    $charts = $includeCharts ? $this->chartContext($query) : null;
    $measurements = ['input_tokens', 'output_tokens', 'duration_ms', 'local_duration_ms'];
    $summary = ['attempts' => 0, 'delivered' => 0, 'failed' => 0, 'active' => 0,
      'provider_cost' => '0.000000000000', 'estimated_cost' => '0.000000000000',
      'provider_cost_count' => 0, 'estimated_cost_count' => 0, 'unknown_cost' => 0];
    foreach ($measurements as $measurement) {
      $summary[$measurement] = ['total' => '0', 'known' => 0, 'unknown' => 0];
    }
    // Chunked PHP string arithmetic avoids SQL NUMERIC/float affinity and bounded SUM overflow.
    foreach ($query->select(['id', 'status', 'cost_usd', 'cost_source', ...($includeCharts ? ['started_at', 'provider'] : []), ...$measurements])->toBase()->lazyById(500) as $row) {
      if ($includeCharts) {
        $this->countChartRow($charts, $row);
        $this->countDailyMetrics($charts, $row);
      }
      $summary['attempts']++;
      if ($row->status === 'delivered') {
        $summary['delivered']++;
      } elseif ($row->status === 'failed') {
        $summary['failed']++;
      } else {
        $summary['active']++;
      }
      foreach ($measurements as $measurement) {
        $value = $row->$measurement;
        if ($value !== null && ctype_digit((string) $value)) {
          $summary[$measurement]['total'] = Decimal::addIntegers($summary[$measurement]['total'], (string) $value);
          $summary[$measurement]['known']++;
        } else {
          $summary[$measurement]['unknown']++;
        }
      }
      if (in_array($row->cost_source, ['provider', 'estimated'], true) && is_string($row->cost_usd) && Decimal::normalize($row->cost_usd) === $row->cost_usd) {
        $key = $row->cost_source . '_cost';
        $summary[$key] = Decimal::sum($summary[$key], $row->cost_usd);
        $summary[$key . '_count']++;
      } else {
        $summary['unknown_cost']++;
      }
    }
    if ($includeCharts) {
      $summary['charts'] = $this->finishCharts($charts);
    }
    return $summary;
  }

  /** Shared filters for the page and independently authorized chart components. */
  public function filteredQuery(array $filters): Builder
  {
    $query = $this->filterPeriod($this->query(), $filters['period'] ?? []);
    foreach (['provider', 'model', 'field'] as $column) {
      if (filled($value = $filters[$column]['value'] ?? null)) {
        $query->where($column, $value);
      }
    }
    $this->filterResult($query, $filters['status']['value'] ?? null);
    if (filled($actor = $filters['actor']['value'] ?? null)) {
      $this->filterActor($query, $actor);
    }
    return $query;
  }

  public function filterResult(Builder $query, ?string $result): Builder
  {
    return match ($result) {
      null, '' => $query,
      'active' => $query->whereIn('status', ['pending', 'received']),
      'delivered', 'failed', 'pending', 'received' => $query->where('status', $result),
      default => $query->whereRaw('1 = 0'),
    };
  }

  /** Reuse only within this HTTP request, after fresh authorization on every read. */
  public function dashboard(array $filters): array
  {
    $query = $this->filteredQuery($filters);
    $key = 'ai-writer.dashboard.' . hash('sha256', serialize([
      Filament::getCurrentPanel()->getId(), HostDates::timezone(), $query->toSql(), $query->getBindings(),
    ]));
    if (! request()->attributes->has($key)) {
      request()->attributes->set($key, $this->summary($query, includeCharts: true));
    }
    return request()->attributes->get($key);
  }

  /** Count-only charts: bounded date buckets and ten named providers plus an explicit remainder. */
  public function charts(Builder $filtered): array
  {
    return $this->summary($filtered, includeCharts: true)['charts'];
  }

  private function chartContext(Builder $query): array
  {
    $range = (clone $query)->toBase()->selectRaw('MIN(started_at) as first_at, MAX(started_at) as last_at')->first();
    $firstDay = $range->first_at === null ? null : $this->localDayNumber($range->first_at);
    $lastDay = $range->last_at === null ? null : $this->localDayNumber($range->last_at);
    $days = $firstDay === null ? 1 : max(1, (int) ceil(($lastDay - $firstDay + 1) / 59));
    $charts = ['first_day' => $firstDay, 'last_day' => $lastDay, 'days' => $days, 'time' => [], 'providers' => [], 'top' => [], 'daily' => []];
    $this->initializeTimeBuckets($charts);
    $charts['top'] = (clone $query)->whereNotNull('provider')->reorder()->select('provider')
      ->selectRaw('COUNT(*) as attempts')->groupBy('provider')->orderByDesc('attempts')->orderBy('provider')->limit(10)->pluck('provider')->all();
    return $charts;
  }

  /** Use a timezone-independent calendar-day index to keep bucket boundaries stable when a scan expands. */
  private function localDayNumber(string $timestamp): int
  {
    $local = CarbonImmutable::parse($timestamp, 'UTC')->setTimezone(HostDates::timezone());
    return (int) (CarbonImmutable::createFromFormat('!Y-m-d', $local->format('Y-m-d'), 'UTC')->getTimestamp() / 86400);
  }

  private function localDate(int $day): CarbonImmutable
  {
    return CarbonImmutable::createFromFormat(
      '!Y-m-d',
      CarbonImmutable::createFromTimestampUTC($day * 86400)->format('Y-m-d'),
      HostDates::timezone(),
    );
  }

  private function initializeTimeBuckets(array &$charts): void
  {
    if ($charts['first_day'] === null) {
      return;
    }
    $firstBucket = (int) floor($charts['first_day'] / $charts['days']) * $charts['days'];
    $lastBucket = (int) floor($charts['last_day'] / $charts['days']) * $charts['days'];
    foreach ($charts['time'] as $bucket => &$row) {
      $row['label'] = $this->timeBucketLabel($charts, (int) $bucket);
    }
    unset($row);
    for ($bucket = $firstBucket; $bucket <= $lastBucket; $bucket += $charts['days']) {
      if (isset($charts['time'][$bucket])) {
        continue;
      }
      $charts['time'][$bucket] = [
        'label' => $this->timeBucketLabel($charts, $bucket),
        'delivered' => 0, 'failed' => 0, 'active' => 0,
      ];
    }
    ksort($charts['time']);
  }

  private function timeBucketLabel(array $charts, int $bucket): string
  {
    $start = $this->localDate(max($bucket, $charts['first_day']));
    $end = $this->localDate(min($bucket + $charts['days'] - 1, $charts['last_day']));
    return $start->format('Y-m-d') . ($charts['days'] > 1 ? ' – ' . $end->format('Y-m-d') : '');
  }

  private function countChartRow(array &$charts, object $row): void
  {
    $day = $this->localDayNumber($row->started_at);
    if ($charts['first_day'] === null || $day < $charts['first_day'] || $day > $charts['last_day']) {
      $charts['first_day'] = $charts['first_day'] === null ? $day : min($charts['first_day'], $day);
      $charts['last_day'] = $charts['last_day'] === null ? $day : max($charts['last_day'], $day);
      $requiredDays = max(1, (int) ceil(($charts['last_day'] - $charts['first_day'] + 1) / 59));
      $newDays = max($charts['days'], (int) ceil($requiredDays / $charts['days']) * $charts['days']);
      if ($newDays !== $charts['days']) {
        $rebinned = [];
        foreach ($charts['time'] as $oldBucket => $counts) {
          $newBucket = (int) floor($oldBucket / $newDays) * $newDays;
          $rebinned[$newBucket] ??= ['label' => '', 'delivered' => 0, 'failed' => 0, 'active' => 0];
          foreach (['delivered', 'failed', 'active'] as $outcome) {
            $rebinned[$newBucket][$outcome] += $counts[$outcome];
          }
        }
        $charts['time'] = $rebinned;
        $charts['days'] = $newDays;
      }
      $this->initializeTimeBuckets($charts);
    }
    $bucket = (int) floor($day / $charts['days']) * $charts['days'];
    $outcome = match ($row->status) { 'delivered' => 'delivered', 'failed' => 'failed', default => 'active' };
    $charts['time'][$bucket][$outcome]++;
    $key = $row->provider === null ? 'unknown' : (in_array($row->provider, $charts['top'], true) ? 'provider:' . $row->provider : 'other');
    $charts['providers'][$key] ??= ['label' => match ($key) { 'unknown' => 'Unknown provider', 'other' => 'Other providers (outside top 10)', default => $row->provider }, 'delivered' => 0, 'failed' => 0, 'active' => 0];
    $charts['providers'][$key][$outcome]++;
  }

  /** Per-day sums behind the overview sparklines; days are grouped into the chart's buckets only when the charts finish. */
  private function countDailyMetrics(array &$charts, object $row): void
  {
    $day = $this->localDayNumber($row->started_at);
    $daily = $charts['daily'][$day] ?? ['tokens' => '0', 'cost' => '0.000000000000', 'duration_total' => '0', 'duration_known' => 0];
    foreach (['input_tokens', 'output_tokens'] as $column) {
      if ($row->$column !== null && ctype_digit((string) $row->$column)) {
        $daily['tokens'] = Decimal::addIntegers($daily['tokens'], (string) $row->$column);
      }
    }
    if (in_array($row->cost_source, ['provider', 'estimated'], true) && is_string($row->cost_usd) && Decimal::normalize($row->cost_usd) === $row->cost_usd) {
      $daily['cost'] = Decimal::sum($daily['cost'], $row->cost_usd);
    }
    if ($row->duration_ms !== null && ctype_digit((string) $row->duration_ms)) {
      $daily['duration_total'] = Decimal::addIntegers($daily['duration_total'], (string) $row->duration_ms);
      $daily['duration_known']++;
    }
    $charts['daily'][$day] = $daily;
  }

  /** @return array<int, array{label: string, tokens: float, cost: float, duration: float}> One entry per chart bucket, in the same order as `time`. */
  private function bucketSeries(array $charts): array
  {
    $buckets = [];
    foreach ($charts['daily'] as $day => $daily) {
      $key = (int) floor($day / $charts['days']) * $charts['days'];
      $bucket = $buckets[$key] ?? ['tokens' => '0', 'cost' => '0.000000000000', 'duration_total' => '0', 'duration_known' => 0];
      $bucket['tokens'] = Decimal::addIntegers($bucket['tokens'], $daily['tokens']);
      $bucket['cost'] = Decimal::sum($bucket['cost'], $daily['cost']);
      $bucket['duration_total'] = Decimal::addIntegers($bucket['duration_total'], $daily['duration_total']);
      $bucket['duration_known'] += $daily['duration_known'];
      $buckets[$key] = $bucket;
    }
    $series = [];
    ksort($charts['time']);
    foreach ($charts['time'] as $key => $row) {
      $bucket = $buckets[$key] ?? ['tokens' => '0', 'cost' => '0.000000000000', 'duration_total' => '0', 'duration_known' => 0];
      $series[] = [
        'label' => $row['label'], 'tokens' => (float) $bucket['tokens'], 'cost' => (float) $bucket['cost'],
        'duration' => $bucket['duration_known'] ? (float) $bucket['duration_total'] / $bucket['duration_known'] / 1000 : 0.0,
      ];
    }

    return $series;
  }

  private function finishCharts(array $charts): array
  {
    ['providers' => $providers, 'time' => $time, 'days' => $days] = $charts;
    $series = $this->bucketSeries($charts);
    ksort($time);
    uasort($providers, fn (array $a, array $b): int => ($b['delivered'] + $b['failed'] + $b['active']) <=> ($a['delivered'] + $a['failed'] + $a['active']));
    return ['time' => array_values($time), 'providers' => array_values($providers), 'bucket_days' => $days, 'series' => $series];
  }

  /**
   * The window of the same length that ends the day before `from`, or null for an open or invalid period.
   *
   * @return array{from: string, until: string}|null
   */
  public function previousPeriod(array $period): ?array
  {
    $from = $period['from'] ?? null;
    $until = $period['until'] ?? null;
    if (! is_string($from) || ! is_string($until)) {
      return null;
    }
    try {
      $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC');
      $end = CarbonImmutable::createFromFormat('!Y-m-d', $until, 'UTC');
    } catch (\Exception) {
      return null;
    }
    if ($start === false || $end === false || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $until || $end < $start) {
      return null;
    }
    $length = (int) $start->diffInDays($end) + 1;

    return ['from' => $start->subDays($length)->format('Y-m-d'), 'until' => $start->subDay()->format('Y-m-d')];
  }

  /** Percent change against the previous period; null when there is no base to compare with. */
  public static function change(float|int|null $current, float|int|null $previous): ?float
  {
    if ($current === null || $previous === null) {
      return null;
    }
    if ((float) $previous === 0.0) {
      return (float) $current === 0.0 ? 0.0 : null;
    }

    return ((float) $current - (float) $previous) / (float) $previous * 100;
  }

  /**
   * The most used actors or fields under the shared filters, by request count.
   *
   * @return array<int, array{key: string, actor_type: ?string, actor_id: ?string, field: ?string, requests: int}>
   */
  public function top(array $filters, string $kind, int $limit = 5): array
  {
    $columns = match ($kind) {
      'actor' => ['actor_type', 'actor_id'],
      'field' => ['field'],
      default => throw new \InvalidArgumentException('Unsupported statistics ranking.'),
    };
    $query = $this->filteredQuery($filters)->reorder()->whereNotNull($columns[0]);
    $rows = $query->select($columns)->selectRaw('COUNT(*) as requests')->groupBy($columns)
      ->orderByDesc('requests')->orderBy($columns[0])->when($kind === 'actor', fn (Builder $query) => $query->orderBy('actor_id'))
      ->limit(max(1, min($limit, 10)))->get();

    return $rows->map(fn ($row): array => [
      'key' => $kind === 'actor' ? self::actorKey($row->actor_type, $row->actor_id) : (string) $row->field,
      'actor_type' => $row->actor_type ?? null, 'actor_id' => $row->actor_id ?? null, 'field' => $row->field ?? null,
      'requests' => (int) $row->requests,
    ])->all();
  }

  /** Bounded searchable options never inspect another panel or load a host User relationship. */
  public function options(string $kind, string $search = ''): array
  {
    $query = $this->query();
    $columns = $kind === 'actor' ? ['actor_type', 'actor_id'] : [$this->column($kind)];
    $query->where(function (Builder $query) use ($columns, $search): void {
      foreach ($columns as $column) {
        $query->orWhere($column, 'like', '%' . substr($search, 0, 100) . '%');
      }
    });
    $options = [];
    foreach ($query->select($columns)->distinct()->orderBy($columns[0])->when($kind === 'actor', fn (Builder $query) => $query->orderBy('actor_id'))->limit(50)->get() as $row) {
      $key = $kind === 'actor' ? self::actorKey($row->actor_type, $row->actor_id) : $row->{$columns[0]};
      if ($key !== null) {
        $options[$key] = $kind === 'actor' ? $row->actor_type . ' · ' . $row->actor_id : $key;
      }
    }
    return $options;
  }

  public function optionLabel(string $kind, string $value): ?string
  {
    $query = $this->query();
    if ($kind === 'actor') {
      $this->filterActor($query, $value);
    } else {
      $query->where($this->column($kind), $value);
    }
    $row = $query->first();
    return $row === null ? null : ($kind === 'actor' ? $row->actor_type . ' · ' . $row->actor_id : $row->{$this->column($kind)});
  }

  /** @var array<string, ?string> */
  private array $actorLabels = [];

  /** The label the host directory gives an actor, shared by the table, the rankings and the details. Never grants management access. */
  public function actorLabel(string $type, string $id, ?string $fallback = null): string
  {
    abort_unless(self::canAccess(), 403);
    $key = self::actorKey($type, $id);
    if (! array_key_exists($key, $this->actorLabels)) {
      $this->actorLabels[$key] = null;
      $plugin = AiWriterPlugin::get();
      // Reuse the existing authorized directory without granting statistics viewers management access.
      try {
        if ($plugin->canManage() && $plugin->hasActorDirectory()) {
          $actor = new ActorIdentity($type, $id);
          $this->actorLabels[$key] = $plugin->resolveActor($actor->token(), $plugin->controlContext())?->label ?: null;
        }
      } catch (\Throwable) {
        // Removed users and unavailable host directories must not hide usage history.
      }
    }

    return $this->actorLabels[$key] ?? $fallback ?? __('filament-ai-writer::statistics.table.user_unavailable');
  }

  /**
   * Host labels are usually "Name · email"; a label without an e-mail part stays whole.
   *
   * @return array{0: string, 1: ?string}
   */
  public static function splitActorLabel(string $label): array
  {
    $parts = explode(' · ', $label, 2);

    return count($parts) === 2 && str_contains($parts[1], '@') ? [$parts[0], $parts[1]] : [$label, null];
  }

  public static function actorKey(string $type, string $id): string
  {
    return base64_encode(json_encode([$type, $id], JSON_THROW_ON_ERROR));
  }

  public function filterActor(Builder $query, string $key): Builder
  {
    $actor = json_decode(base64_decode($key, true) ?: '', true);
    return is_array($actor) && array_is_list($actor) && count($actor) === 2 && is_string($actor[0]) && is_string($actor[1])
      ? $query->where('actor_type', $actor[0])->where('actor_id', $actor[1])
      : $query->whereRaw('1 = 0');
  }

  public function filterPeriod(Builder $query, array $data): Builder
  {
    foreach (['from', 'until'] as $boundary) {
      $value = $data[$boundary] ?? null;
      if ($value === null || $value === '') {
        continue;
      }
      if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        throw ValidationException::withMessages(['period' => __('filament-ai-writer::statistics.validation.date_format')]);
      }
      try {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, HostDates::timezone());
        if ($date->format('Y-m-d') !== $value) {
          throw new \InvalidArgumentException();
        }
      } catch (\Exception) {
        throw ValidationException::withMessages(['period' => __('filament-ai-writer::statistics.validation.date')]);
      }
      $query->where('started_at', $boundary === 'from' ? '>=' : '<', ($boundary === 'from' ? $date : $date->addDay())->utc());
    }
    return $query;
  }

  private function column(string $kind): string
  {
    return match ($kind) {
      'provider' => 'provider', 'model' => 'model', 'field' => 'field',
      default => throw new \InvalidArgumentException('Unsupported statistics filter.'),
    };
  }
}
