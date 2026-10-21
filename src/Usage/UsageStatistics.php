<?php

namespace PdroLucas\FilamentAiWriter\Usage;

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Models\UsageAttempt;
use PdroLucas\FilamentAiWriter\Support\Decimal;

class UsageStatistics
{
  public static function canAccess(): bool
  {
    $panel = Filament::getCurrentPanel();
    $plugin = $panel?->hasPlugin('ai-writer') ? $panel->getPlugin('ai-writer') : null;
    return $plugin instanceof AiWriterPlugin && $plugin->canViewStatistics()
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
    foreach (['provider', 'model'] as $column) {
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
      Filament::getCurrentPanel()->getId(), config('app.timezone'), $query->toSql(), $query->getBindings(),
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
    $charts = ['first_day' => $firstDay, 'last_day' => $lastDay, 'days' => $days, 'time' => [], 'providers' => [], 'top' => []];
    $this->initializeTimeBuckets($charts);
    $charts['top'] = (clone $query)->whereNotNull('provider')->reorder()->select('provider')
      ->selectRaw('COUNT(*) as attempts')->groupBy('provider')->orderByDesc('attempts')->orderBy('provider')->limit(10)->pluck('provider')->all();
    return $charts;
  }

  /** Use a timezone-independent calendar-day index to keep bucket boundaries stable when a scan expands. */
  private function localDayNumber(string $timestamp): int
  {
    $local = CarbonImmutable::parse($timestamp, 'UTC')->setTimezone(config('app.timezone', 'UTC'));
    return (int) (CarbonImmutable::createFromFormat('!Y-m-d', $local->format('Y-m-d'), 'UTC')->getTimestamp() / 86400);
  }

  private function localDate(int $day): CarbonImmutable
  {
    return CarbonImmutable::createFromFormat(
      '!Y-m-d',
      CarbonImmutable::createFromTimestampUTC($day * 86400)->format('Y-m-d'),
      config('app.timezone', 'UTC'),
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

  private function finishCharts(array $charts): array
  {
    ['providers' => $providers, 'time' => $time, 'days' => $days] = $charts;
    ksort($time);
    uasort($providers, fn (array $a, array $b): int => ($b['delivered'] + $b['failed'] + $b['active']) <=> ($a['delivered'] + $a['failed'] + $a['active']));
    return ['time' => array_values($time), 'providers' => array_values($providers), 'bucket_days' => $days];
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
        throw ValidationException::withMessages(['period' => 'Use a valid date in YYYY-MM-DD format.']);
      }
      try {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.timezone', 'UTC'));
        if ($date->format('Y-m-d') !== $value) {
          throw new \InvalidArgumentException();
        }
      } catch (\Exception) {
        throw ValidationException::withMessages(['period' => 'Use a valid calendar date.']);
      }
      $query->where('started_at', $boundary === 'from' ? '>=' : '<', ($boundary === 'from' ? $date : $date->addDay())->utc());
    }
    return $query;
  }

  private function column(string $kind): string
  {
    return match ($kind) {
      'provider' => 'provider', 'model' => 'model',
      default => throw new \InvalidArgumentException('Unsupported statistics filter.'),
    };
  }
}
