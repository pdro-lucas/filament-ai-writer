<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('filament_ai_writer_policies', function (Blueprint $table): void {
      $table->string('scope_key', 64)->primary();
      $table->string('scope');
      $table->boolean('enabled')->default(true);
    });
    Schema::create('filament_ai_writer_actor_blocks', function (Blueprint $table): void {
      $table->string('scope_key', 64);
      $table->string('actor_key', 64);
      $table->string('actor_type');
      $table->string('actor_id');
      $table->primary(['scope_key', 'actor_key'], 'aiw_blocks_scope_actor');
      $table->foreign('scope_key', 'aiw_blocks_scope_fk')->references('scope_key')->on('filament_ai_writer_policies');
    });
    Schema::create('filament_ai_writer_control_audit', function (Blueprint $table): void {
      $table->id();
      $table->string('scope_key', 64);
      $table->string('scope');
      $table->string('panel_id');
      $table->string('guard');
      $table->string('administrator_type');
      $table->string('administrator_id');
      $table->string('action', 32);
      $table->string('actor_type')->nullable();
      $table->string('actor_id')->nullable();
      $table->json('before');
      $table->json('after');
      $table->string('reason', 500);
      $table->timestamp('created_at', 6);
      $table->index(['scope_key', 'id'], 'aiw_audit_scope_id');
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('filament_ai_writer_control_audit');
    Schema::dropIfExists('filament_ai_writer_actor_blocks');
    Schema::dropIfExists('filament_ai_writer_policies');
  }
};
