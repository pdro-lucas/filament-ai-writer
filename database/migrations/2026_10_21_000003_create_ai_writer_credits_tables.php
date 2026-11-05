<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PdroLucas\FilamentAiWriter\Models\UsageAttempt;

return new class extends Migration
{
  public function up(): void
  {
    if (DB::table((new UsageAttempt())->getTable())->whereIn('status', ['pending', 'received'])->exists()) {
      throw new RuntimeException('Drain pending AI generations before the credits migration. Stop old writers and the usage pruner during cutover.');
    }
    Schema::table('filament_ai_writer_policies', function (Blueprint $table): void {
      $table->boolean('quota_enabled')->default(false);
      $table->unsignedInteger('default_limit')->nullable();
      $table->string('renewal', 16)->nullable();
      $table->uuid('active_period')->nullable();
    });
    Schema::create('filament_ai_writer_credit_periods', function (Blueprint $table): void {
      $table->uuid('id')->primary();
      $table->string('scope_key', 64)->index();
      $table->string('renewal', 16);
      $table->string('timezone');
      $table->timestamp('starts_at', 6);
      $table->timestamp('ends_at', 6)->nullable();
    });
    Schema::create('filament_ai_writer_credit_actors', function (Blueprint $table): void {
      $table->string('scope_key', 64);
      $table->string('actor_key', 64);
      $table->string('actor_type');
      $table->string('actor_id');
      $table->unsignedInteger('limit')->nullable();
      $table->uuid('active_balance')->nullable();
      $table->primary(['scope_key', 'actor_key'], 'aiw_credit_actor');
    });
    Schema::create('filament_ai_writer_credit_balances', function (Blueprint $table): void {
      $table->uuid('id')->primary();
      $table->uuid('period_id')->index();
      $table->string('actor_key', 64);
      $table->unsignedBigInteger('extras')->default(0);
      $table->unsignedBigInteger('consumed')->default(0);
      $table->unsignedBigInteger('reserved')->default(0);
    });
    Schema::create('filament_ai_writer_operations', function (Blueprint $table): void {
      $table->uuid('operation_id')->primary();
      $table->string('scope_key', 64)->nullable()->index();
      $table->string('panel_id');
      $table->string('guard')->nullable();
      $table->string('actor_type');
      $table->string('actor_id');
      $table->string('field');
      $table->string('action_name')->nullable();
      $table->uuid('balance_id')->nullable();
      $table->dateTime('deadline_at', 6)->nullable()->index();
      $table->string('status', 16);
      $table->timestamp('started_at', 6);
      $table->timestamp('completed_at', 6)->nullable();
    });
    // Retain every still-known identity, including legacy unresolved attempts, without
    // retroactively reserving credits or inventing a historical server scope.
    DB::table((new UsageAttempt())->getTable())->orderBy('id')->chunkById(500, function ($rows): void {
      foreach ($rows as $row) {
        DB::table('filament_ai_writer_operations')->insertOrIgnore([
          'operation_id' => $row->operation_id, 'panel_id' => $row->panel_id,
          'actor_type' => $row->actor_type, 'actor_id' => $row->actor_id,
          'field' => $row->field, 'action_name' => $row->action_name,
          'status' => $row->status, 'started_at' => $row->started_at, 'completed_at' => $row->completed_at,
        ]);
      }
    });
  }

  public function down(): void
  {
    foreach (['operations', 'credit_balances', 'credit_actors', 'credit_periods'] as $table) {
      Schema::dropIfExists('filament_ai_writer_' . $table);
    }
    Schema::table('filament_ai_writer_policies', fn (Blueprint $table) => $table->dropColumn(['quota_enabled', 'default_limit', 'renewal', 'active_period']));
  }
};
