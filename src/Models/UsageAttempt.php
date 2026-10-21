<?php

namespace PdroLucas\FilamentAiWriter\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use PdroLucas\FilamentAiWriter\Exceptions\GenerationFailure;

class UsageAttempt extends Model
{
  public $timestamps = false;

  protected $guarded = ['*'];

  protected $casts = [
    'attempt_number' => 'integer', 'outcome_unknown' => 'boolean',
    'input_tokens' => 'integer', 'output_tokens' => 'integer',
    'cached_input_tokens' => 'integer', 'cache_write_input_tokens' => 'integer', 'reasoning_output_tokens' => 'integer',
    'duration_ms' => 'integer', 'local_duration_ms' => 'integer', 'http_status' => 'integer',
    'started_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime',
    'completed_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime',
  ];

  protected function asDateTime($value)
  {
    // Recorder timestamps are UTC database strings without timezone offsets.
    return is_string($value) && !is_numeric($value)
      ? CarbonImmutable::parse($value, 'UTC')
      : parent::asDateTime($value);
  }

  public function getTable(): string
  {
    $table = config('filament-ai-writer.tables.usage', 'filament_ai_writer_usage');
    if (!is_string($table) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $table)) {
      throw new GenerationFailure('storage');
    }
    return $table;
  }
}
