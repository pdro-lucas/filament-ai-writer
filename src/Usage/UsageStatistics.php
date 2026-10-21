<?php

namespace PdroLucas\FilamentAiWriter\Usage;

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
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
    return $plugin instanceof AiWriterPlugin && $plugin->canViewStatistics();
  }

  public function query(): Builder
  {
    abort_unless(self::canAccess(), 403);
    return UsageAttempt::query()->where('panel_id', Filament::getCurrentPanel()->getId());
  }

  /** Always rescope a caller's filtered query before reading any summary rows. */
  public function summary(Builder $filtered): array
  {
    $query = $this->query()->whereIn('id', (clone $filtered)->reorder()->select('id'));
    $measurements = ['input_tokens', 'output_tokens', 'duration_ms', 'local_duration_ms'];
    $summary = ['attempts' => 0, 'delivered' => 0, 'failed' => 0, 'active' => 0,
      'provider_cost' => '0.000000000000', 'estimated_cost' => '0.000000000000',
      'provider_cost_count' => 0, 'estimated_cost_count' => 0, 'unknown_cost' => 0];
    foreach ($measurements as $measurement) {
      $summary[$measurement] = ['total' => '0', 'known' => 0, 'unknown' => 0];
    }
    // Chunked PHP string arithmetic avoids SQL NUMERIC/float affinity and bounded SUM overflow.
    foreach ($query->select(['id', 'status', 'cost_usd', 'cost_source', ...$measurements])->toBase()->lazyById(500) as $row) {
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
    return $summary;
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
