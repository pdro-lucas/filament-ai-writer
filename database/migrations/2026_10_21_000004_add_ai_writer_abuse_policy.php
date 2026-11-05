<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PdroLucas\FilamentAiWriter\Controls\ActorIdentity;

return new class extends Migration
{
  public function up(): void
  {
    Schema::table('filament_ai_writer_policies', function (Blueprint $table): void {
      foreach (['rate', 'concurrency', 'failure'] as $kind) {
        $table->boolean($kind . '_enabled')->default(false);
        $table->unsignedInteger($kind . '_limit')->nullable();
      }
      $table->unsignedInteger('rate_window_seconds')->nullable();
      $table->unsignedInteger('failure_window_seconds')->nullable();
      $table->unsignedInteger('failure_pause_seconds')->nullable();
    });
    Schema::table('filament_ai_writer_operations', function (Blueprint $table): void {
      $table->string('actor_key', 64)->nullable();
      $table->index(['scope_key', 'actor_key', 'started_at'], 'aiw_abuse_started');
      $table->index(['scope_key', 'actor_key', 'status', 'completed_at'], 'aiw_abuse_completed');
    });
    DB::table('filament_ai_writer_operations')->orderBy('operation_id')->chunkById(500, function ($rows): void {
      foreach ($rows as $row) {
        DB::table('filament_ai_writer_operations')->where('operation_id', $row->operation_id)
          ->update(['actor_key' => (new ActorIdentity($row->actor_type, $row->actor_id))->key()]);
      }
    }, 'operation_id');
  }

  public function down(): void
  {
    Schema::table('filament_ai_writer_operations', function (Blueprint $table): void {
      $table->dropIndex('aiw_abuse_started');
      $table->dropIndex('aiw_abuse_completed');
      $table->dropColumn('actor_key');
    });
    Schema::table('filament_ai_writer_policies', fn (Blueprint $table) => $table->dropColumn([
      'rate_enabled', 'rate_limit', 'rate_window_seconds', 'concurrency_enabled', 'concurrency_limit',
      'failure_enabled', 'failure_limit', 'failure_window_seconds', 'failure_pause_seconds',
    ]));
  }
};
