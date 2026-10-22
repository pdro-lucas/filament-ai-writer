<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PdroLucas\FilamentAiWriter\Models\UsageAttempt;

return new class extends Migration
{
  public function up(): void
  {
    $table = (new UsageAttempt())->getTable();
    $index = 'aiw_' . substr(hash('sha256', $table), 0, 12);
    Schema::create($table, function (Blueprint $blueprint) use ($index): void {
      $blueprint->id();
      $blueprint->uuid('operation_id');
      $blueprint->unsignedSmallInteger('attempt_number');
      $blueprint->string('panel_id');
      $blueprint->string('actor_type');
      $blueprint->string('actor_id');
      $blueprint->string('field');
      $blueprint->string('action_name')->nullable();
      $blueprint->string('status', 16);
      $blueprint->string('outcome', 16)->nullable();
      $blueprint->string('failure_code', 32)->nullable();
      $blueprint->boolean('outcome_unknown')->default(false);
      $blueprint->unsignedSmallInteger('http_status')->nullable();
      $blueprint->string('provider', 64)->nullable();
      $blueprint->string('requested_model')->nullable();
      $blueprint->string('model')->nullable();
      $blueprint->string('finish_reason', 64)->nullable();
      $blueprint->string('response_id')->nullable();
      $blueprint->string('request_id')->nullable();
      foreach (['input_tokens', 'output_tokens', 'cached_input_tokens', 'cache_write_input_tokens', 'reasoning_output_tokens', 'duration_ms', 'local_duration_ms'] as $column) {
        $blueprint->unsignedBigInteger($column)->nullable();
      }
      // TEXT preserves decimal strings in SQLite; NUMERIC affinity cannot preserve them.
      $blueprint->text('cost_usd')->nullable();
      $blueprint->string('cost_source', 16)->default('unavailable');
      $blueprint->text('input_rate_usd_per_million')->nullable();
      $blueprint->text('output_rate_usd_per_million')->nullable();
      $blueprint->timestamp('started_at', 6);
      $blueprint->timestamp('received_at', 6)->nullable();
      $blueprint->timestamp('completed_at', 6)->nullable();
      $blueprint->timestamp('delivered_at', 6)->nullable();
      $blueprint->unique(['operation_id', 'attempt_number'], $index . '_operation');
      $blueprint->index(['panel_id', 'started_at'], $index . '_panel_date');
      $blueprint->index(['panel_id', 'actor_type', 'actor_id'], $index . '_panel_actor');
      $blueprint->index(['panel_id', 'provider', 'model'], $index . '_panel_model');
      $blueprint->index(['status', 'completed_at'], $index . '_retention');
    });
  }

  public function down(): void
  {
    Schema::dropIfExists((new UsageAttempt())->getTable());
  }
};
