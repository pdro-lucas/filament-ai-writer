<?php

namespace PdroLucas\FilamentAiWriter\Usage;

use Carbon\CarbonImmutable;
use PdroLucas\FilamentAiWriter\Models\UsageAttempt;
use Throwable;

class UsagePruner
{
  /** CLI maintenance spans the configured table; it is not a statistics access path. */
  public function run(mixed $days, mixed $batchSize, bool $dryRun = false): array
  {
    $result = ['status' => 'disabled', 'deleted' => 0, 'batches' => 0, 'eligible' => null, 'cutoff' => null];
    if ($days === null || $days === '') {
      return $result;
    }
    if (!$this->positiveInteger($days) || !$this->positiveInteger($batchSize) || (int) $batchSize > 1000) {
      return array_replace($result, ['status' => 'invalid']);
    }
    try {
      $now = CarbonImmutable::now(config('app.timezone', 'UTC'));
      $age = $now->subDays((int) $days);
      $month = $now->startOfMonth();
      $cutoff = ($age->lessThan($month) ? $age : $month)->utc();
      $result['cutoff'] = $cutoff->toIso8601String();
      $model = new UsageAttempt();
      $query = $model->getConnection()->table($model->getTable())
        ->whereIn('status', ['delivered', 'failed'])
        ->whereNotNull('completed_at')->where('started_at', '<', $cutoff)->where('completed_at', '<', $cutoff);
      if ($dryRun) {
        return array_replace($result, ['status' => 'dry-run', 'eligible' => (clone $query)->count()]);
      }
      // Freeze the upper ID; new/backdated inserts are left for a later explicit run.
      $upperId = (clone $query)->max('id');
      $cursor = 0;
      while ($upperId !== null) {
        $ids = (clone $query)->where('id', '>', $cursor)->where('id', '<=', $upperId)->orderBy('id')->limit((int) $batchSize)->pluck('id');
        if ($ids->isEmpty()) {
          break;
        }
        // Recheck eligibility in the atomic DELETE if a row changed after selection.
        $result['deleted'] += (clone $query)->whereIn('id', $ids)->delete();
        $result['batches']++;
        $cursor = $ids->last();
      }
      return array_replace($result, ['status' => 'complete']);
    } catch (Throwable) {
      return array_replace($result, ['status' => 'failed']);
    }
  }

  private function positiveInteger(mixed $value): bool
  {
    return (is_int($value) || (is_string($value) && ctype_digit($value)))
      && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
  }
}
