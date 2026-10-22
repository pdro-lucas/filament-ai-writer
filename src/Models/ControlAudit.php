<?php

namespace PdroLucas\FilamentAiWriter\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class ControlAudit extends Model
{
  protected $table = 'filament_ai_writer_control_audit';
  public $timestamps = false;
  protected $guarded = [];

  protected function asDateTime($value)
  {
    // Controls timestamps are UTC database strings without timezone offsets.
    return is_string($value) && !is_numeric($value)
      ? CarbonImmutable::parse($value, 'UTC')
      : parent::asDateTime($value);
  }

  protected function casts(): array
  {
    return ['before' => 'array', 'after' => 'array', 'created_at' => 'immutable_datetime'];
  }
}
