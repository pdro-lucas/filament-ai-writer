<?php

namespace PdroLucas\FilamentAiWriter\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class UsageRetentionTest extends TestCase
{
  use DatabaseMigrations;

  public function test_cleanup_is_disabled_by_default_and_invalid_settings_never_delete(): void
  {
    $this->row();
    $this->artisan('ai-writer:prune-usage')->expectsOutputToContain('disabled')->assertSuccessful();
    foreach ([['days' => 0], ['days' => 'invalid'], ['days' => 1, 'batch_size' => 100000]] as $settings) {
      config(['filament-ai-writer.retention' => $settings]);
      $this->artisan('ai-writer:prune-usage')->expectsOutputToContain('Invalid retention configuration')->assertFailed();
      $this->assertSame(1, DB::table('filament_ai_writer_usage')->count());
    }
  }

  #[DataProvider('cutoffs')]
  public function test_pruning_preserves_unresolved_attempts_and_current_local_month_with_strict_cutoffs(int $days, string $cutoff): void
  {
    $previousNow = CarbonImmutable::getTestNow();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21T12:00:00Z'));
    config(['app.timezone' => 'America/Sao_Paulo', 'filament-ai-writer.retention' => ['days' => $days, 'batch_size' => 1]]);
    try {
      $before = CarbonImmutable::parse($cutoff, 'UTC')->subSecond()->format('Y-m-d H:i:s');
      $eligible = [$this->row(['started_at' => $before, 'completed_at' => $before]), $this->row(['panel_id' => 'another', 'status' => 'failed', 'started_at' => $before, 'completed_at' => $before])];
      $protected = [
        $this->row(['started_at' => $cutoff, 'completed_at' => $before]),
        $this->row(['started_at' => $before, 'completed_at' => $cutoff]),
        $this->row(['status' => 'pending', 'completed_at' => null]),
        $this->row(['status' => 'received', 'completed_at' => null]),
        $this->row(['completed_at' => null]),
        $this->row(['started_at' => '2026-10-01 03:00:00']),
        $this->row(['completed_at' => '2026-10-01 03:00:00']),
      ];
      \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('demo'));
      auth('workbench')->setUser(new \Illuminate\Auth\GenericUser(['id' => 'opaque-id']));
      $statistics = \PdroLucas\FilamentAiWriter\AiWriterPlugin::get()->statistics();
      $this->assertSame(8, $statistics->summary($statistics->query())['attempts']);
      $this->artisan('ai-writer:prune-usage', ['--dry-run' => true])->expectsOutputToContain('2 eligible')->assertSuccessful();
      $this->assertSame(9, DB::table('filament_ai_writer_usage')->count());
      $this->artisan('ai-writer:prune-usage')->expectsOutputToContain('Deleted 2')->assertSuccessful();
      $this->assertSame($protected, DB::table('filament_ai_writer_usage')->orderBy('id')->pluck('id')->all());
      $this->assertSame(0, DB::table('filament_ai_writer_usage')->whereIn('id', $eligible)->count());
      $this->assertSame(7, $statistics->summary($statistics->query())['attempts']);
      $this->artisan('ai-writer:prune-usage')->expectsOutputToContain('Deleted 0')->assertSuccessful();
    } finally {
      CarbonImmutable::setTestNow($previousNow);
    }
  }

  public static function cutoffs(): array
  {
    return ['active local month' => [1, '2026-10-01 03:00:00'], 'longer retention age' => [90, '2026-07-23 12:00:00']];
  }

  public function test_partial_batch_failure_reports_actual_deletions_without_raw_database_error(): void
  {
    $first = $this->row();
    $blocked = $this->row();
    DB::unprepared("CREATE TEMP TRIGGER stop_pruning BEFORE DELETE ON filament_ai_writer_usage WHEN OLD.id = $blocked BEGIN SELECT RAISE(ABORT, 'PRIVATE database detail'); END");
    config(['filament-ai-writer.retention' => ['days' => 1, 'batch_size' => 1]]);
    $this->artisan('ai-writer:prune-usage')->expectsOutputToContain('Cleanup stopped after deleting 1')->doesntExpectOutputToContain('PRIVATE')->assertFailed();
    $this->assertFalse(DB::table('filament_ai_writer_usage')->where('id', $first)->exists());
    $this->assertTrue(DB::table('filament_ai_writer_usage')->where('id', $blocked)->exists());
  }

  public function test_batch_delete_rechecks_eligibility_and_does_not_chase_new_backdated_inserts(): void
  {
    $changed = $this->row();
    $removed = $this->row();
    $late = null;
    $switched = false;
    DB::connection()->beforeExecuting(function (string $sql) use ($changed, &$switched, &$late): void {
      if (!$switched && str_starts_with($sql, 'delete from "filament_ai_writer_usage"')) {
        $switched = true;
        DB::table('filament_ai_writer_usage')->where('id', $changed)->update(['status' => 'pending', 'completed_at' => null]);
        $late = $this->row();
      }
    });
    config(['filament-ai-writer.retention' => ['days' => 1, 'batch_size' => 1]]);
    $this->artisan('ai-writer:prune-usage')->expectsOutputToContain('Deleted 1')->assertSuccessful();
    $this->assertNotNull($late);
    $this->assertSame([$changed, $late], DB::table('filament_ai_writer_usage')->orderBy('id')->pluck('id')->all());
    $this->assertFalse(DB::table('filament_ai_writer_usage')->where('id', $removed)->exists());
  }

  private function row(array $changes = []): int
  {
    return DB::table('filament_ai_writer_usage')->insertGetId(array_replace([
      'operation_id' => (string) \Illuminate\Support\Str::uuid(), 'attempt_number' => 1,
      'panel_id' => 'demo', 'actor_type' => 'HostActor', 'actor_id' => 'opaque-id', 'field' => 'content',
      'status' => 'delivered', 'started_at' => '2020-01-01 12:00:00', 'completed_at' => '2020-01-01 12:00:01',
      'cost_source' => 'unavailable',
    ], $changes));
  }
}
