<?php

namespace PdroLucas\FilamentAiWriter\Tests\Concurrency;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Tests\TestCase;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

/**
 * Opt-in: races real PHP processes through admission on MySQL or PostgreSQL (skipped on SQLite, whose single
 * writer cannot reproduce row-lock deadlocks or double admission). See docs/contributing.md.
 */
class MultiProcessAdmissionTest extends TestCase
{
  use DatabaseMigrations;

  private const ROUNDS = 3;

  protected function setUp(): void
  {
    if (env('AI_WRITER_TEST_DB_CONNECTION', 'sqlite') === 'sqlite') {
      $this->markTestSkipped('Multi-process admission probes need AI_WRITER_TEST_DB_CONNECTION=mysql or pgsql.');
    }
    parent::setUp();
  }

  public function test_last_credit_goes_to_exactly_one_of_many_processes(): void
  {
    foreach (range(1, self::ROUNDS) as $round) {
      $result = $this->race(6, ['limit' => 1], 300, "last:$round");
      $this->assertEquals(['ok' => 1, 'credits' => 5], $result['outcomes'], 'round ' . $round . json_encode($result['messages']));
      $this->assertSame(1, $result['providerCalls']);
      $balance = $this->balance();
      $this->assertSame([1, 0, 0], [$balance['consumed'], $balance['reserved'], $balance['available']]);
    }
  }

  public function test_frequency_limit_admits_at_most_the_limit_across_processes(): void
  {
    foreach (range(1, self::ROUNDS) as $round) {
      $result = $this->race(6, ['limit' => 1000, 'abuse' => ['rate_enabled' => true, 'rate_limit' => 3, 'rate_window_seconds' => 60]], 50, "rate:$round");
      $this->assertEquals(['ok' => 3, 'rate' => 3], $result['outcomes'], 'round ' . $round . json_encode($result['messages']));
      $this->assertSame(3, $result['providerCalls']);
      $this->assertSame(3, $this->balance()['consumed']);
    }
  }

  public function test_concurrency_limit_is_respected_across_processes(): void
  {
    foreach (range(1, self::ROUNDS) as $round) {
      $result = $this->race(5, ['limit' => 1000, 'abuse' => ['concurrency_enabled' => true, 'concurrency_limit' => 2]], 1000, "concurrency:$round");
      $this->assertEquals(['ok' => 2, 'concurrency' => 3], $result['outcomes'], 'round ' . $round . json_encode($result['messages']));
      $this->assertSame(2, $result['providerCalls']);
      $this->assertLessThanOrEqual(2, $result['maxOverlap']);
    }
  }

  /** @return array{outcomes: array<string, int>, messages: list<string>, providerCalls: int, maxOverlap: int} */
  private function race(int $processes, array $policy, int $providerMs, string $scope): array
  {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::firstOrFail(), 'workbench');
    Filament::setCurrentPanel(Filament::getPanel('demo'));
    putenv('AI_WRITER_TEST_SCOPE=' . $scope);
    $plugin = AiWriterPlugin::get();
    $plugin->authorizeManage(fn () => true)->scope(fn () => getenv('AI_WRITER_TEST_SCOPE'));
    $context = $plugin->controlContext();
    $plugin->credits()->configure($context, true, $policy['limit'], 'manual', 'Start');
    if (isset($policy['abuse'])) {
      $plugin->abuse()->configure($context, $policy['abuse'], 'Start');
    }
    $dir = sys_get_temp_dir() . '/ai-writer-race-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $env = array_merge(getenv(), ['AI_WRITER_TEST_SCOPE' => $scope, 'AI_WRITER_TEST_PROVIDER_MS' => (string) $providerMs,
      'AI_WRITER_TEST_GO' => "$dir/go", 'AI_WRITER_TEST_EVENTS' => "$dir/events"]);
    $workers = [];
    for ($i = 0; $i < $processes; $i++) {
      $process = proc_open([PHP_BINARY, __DIR__ . '/worker.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env);
      $workers[] = ['process' => $process, 'out' => $pipes[1], 'err' => $pipes[2]];
    }
    foreach ($workers as $worker) {
      if (trim((string) fgets($worker['out'])) !== 'READY') {
        $this->fail('Worker failed to boot: ' . stream_get_contents($worker['err']));
      }
    }
    touch("$dir/go"); // barrier: every process starts admission at the same instant
    $outcomes = [];
    $messages = [];
    foreach ($workers as $worker) {
      $line = json_decode(trim((string) fgets($worker['out'])), true) ?? ['outcome' => 'no-output'];
      $outcomes[$line['outcome']] = ($outcomes[$line['outcome']] ?? 0) + 1;
      isset($line['message']) && $messages[] = $line['message'];
      proc_close($worker['process']);
    }
    $events = array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents("$dir/events"))));
    $calls = 0;
    $current = 0;
    $max = 0;
    foreach ($events as $event) {
      if ($event['ev'] === 'enter') {
        $calls++;
        $max = max($max, ++$current);
      } else {
        $current--;
      }
    }
    array_map('unlink', glob("$dir/*"));
    rmdir($dir);
    return ['outcomes' => $outcomes, 'messages' => $messages, 'providerCalls' => $calls, 'maxOverlap' => $max];
  }

  private function balance(): array
  {
    $plugin = AiWriterPlugin::get();
    return $plugin->credits()->ownBalance($plugin->controlContext());
  }
}
