<?php

namespace PdroLucas\FilamentAiWriter\Tests;

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Data\GenerateResult;
use PdroLucas\FilamentAiWriter\Exceptions\GenerationFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PdroLucas\FilamentAiWriter\Models\UsageAttempt;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

class UsageLedgerTest extends TestCase
{
  use DatabaseMigrations;

  public function test_records_allowlisted_metadata_and_exact_text_cost_without_private_content(): void
  {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::firstOrFail(), 'workbench');
    Filament::setCurrentPanel(Filament::getPanel('demo'));
    config(['filament-ai-writer.provider' => 'openrouter', 'filament-ai-writer.openrouter.model' => 'test-model', 'filament-ai-writer.openrouter.api_key' => 'PRIVATE-key']);
    Http::fake(['*' => Http::response('{"id":"response-id","model":"returned-model","choices":[{"message":{"content":"PRIVATE result"},"finish_reason":"stop"}],"usage":{"prompt_tokens":100,"completion_tokens":40,"cost":0.100000000001}}')]);
    $field = 'Original';
    $result = AiWriterPlugin::get()->generation()->run('PRIVATE prompt', 'PRIVATE input', function (GenerateResult $result) use (&$field): void {
      $field = $result->text;
    }, function () use (&$field): void {
      $field = 'Original';
    }, 'content');

    $this->assertSame('PRIVATE result', $field);
    $attempt = UsageAttempt::query()->sole();
    $this->assertSame('delivered', $attempt->status);
    $this->assertSame('demo', $attempt->panel_id);
    $this->assertSame(User::class, $attempt->actor_type);
    $this->assertSame((string) User::firstOrFail()->getKey(), $attempt->actor_id);
    $this->assertSame('0.100000000001', $attempt->cost_usd);
    $this->assertSame('provider', $result->costSource);
    $this->assertNotNull($attempt->delivered_at);
    $this->assertStringNotContainsString('PRIVATE', json_encode($attempt->getAttributes()));
    $this->assertSame('text', DB::selectOne('select typeof(cost_usd) as affinity from filament_ai_writer_usage')->affinity);
    Http::assertSentCount(1);
  }

  #[DataProvider('admissionFailures')]
  public function test_admission_failure_is_safe_and_prevents_http(string $condition, string $code): void
  {
    $this->login();
    if ($condition === 'table') {
      config(['filament-ai-writer.tables.usage' => 'missing_usage']);
    } elseif ($condition === 'connection') {
      config(['database.default' => 'PRIVATE-missing-connection']);
    } else {
      DB::beginTransaction();
    }
    try {
      AiWriterPlugin::get()->generation()->run('prompt', 'input', fn () => $this->fail('Must not deliver'), fn () => null, 'content');
      $this->fail('Must reject admission');
    } catch (GenerationFailure $failure) {
      $this->assertSame($code, $failure->failureCode);
      $this->assertNull($failure->getPrevious());
      $this->assertStringNotContainsString('PRIVATE', $failure->getMessage());
      Http::assertNothingSent();
    } finally {
      config(['database.default' => 'testing']);
      if ($condition === 'transaction') {
        DB::rollBack();
      }
    }
  }

  public static function admissionFailures(): array
  {
    return ['missing table' => ['table', 'storage'], 'missing connection' => ['connection', 'storage'], 'outer transaction' => ['transaction', 'transaction']];
  }

  private function login(): void
  {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::firstOrFail(), 'workbench');
    Filament::setCurrentPanel(Filament::getPanel('demo'));
    config(['filament-ai-writer.provider' => 'openai', 'filament-ai-writer.openai.api_key' => 'PRIVATE-key', 'filament-ai-writer.openai.model' => 'gpt-4o']);
  }

  public function test_admission_is_durable_context_is_frozen_and_duplicate_operation_never_repeats_http(): void
  {
    $this->login();
    $actor = User::firstOrFail();
    $operation = (string) \Illuminate\Support\Str::uuid();
    Http::fake(function () use ($actor) {
      $this->assertSame(0, DB::transactionLevel());
      $this->assertSame('pending', UsageAttempt::query()->sole()->status);
      $this->assertSame((string) $actor->getKey(), UsageAttempt::query()->sole()->actor_id);
      Filament::setCurrentPanel(\Filament\Panel::make()->id('isolated')->authGuard('web'));
      return Http::response($this->response(), 200, ['x-request-id' => 'request-id']);
    });
    $service = AiWriterPlugin::get()->generation();
    $deliveries = 0;
    $service->run('prompt', 'input', function () use (&$deliveries) { $deliveries++; }, fn () => null, 'content', $operation);
    $attempt = UsageAttempt::query()->sole();
    $this->assertSame('demo', $attempt->panel_id);
    $this->assertSame('delivered', $attempt->status);
    Filament::setCurrentPanel(Filament::getPanel('demo'));
    try {
      $service->run('prompt', 'input', fn () => $this->fail('Duplicate delivery'), fn () => null, 'content', $operation);
      $this->fail('Duplicate admission must fail');
    } catch (GenerationFailure $failure) {
      $this->assertSame('duplicate', $failure->failureCode);
    }
    $this->assertSame(1, $deliveries);
    $this->assertSame(1, UsageAttempt::query()->count());
    Http::assertSentCount(1);
  }

  public function test_recorder_finalization_is_idempotent_and_preserves_opaque_identity(): void
  {
    $context = new \PdroLucas\FilamentAiWriter\Data\GenerationContext((string) \Illuminate\Support\Str::uuid(), 'panel-one', 'HostActor', 'opaque:actor-001', 'tags');
    $other = new \PdroLucas\FilamentAiWriter\Data\GenerationContext($context->operationId, 'panel-two', 'HostActor', 'opaque:actor-001', 'tags');
    $recorder = app(\PdroLucas\FilamentAiWriter\Usage\UsageRecorder::class);
    $result = new GenerateResult('custom', 'requested', 'returned', 'PRIVATE');
    $quote = app(\PdroLucas\FilamentAiWriter\Usage\CostCalculator::class)->quote($result);
    $recorder->begin($context);
    $this->assertFalse($recorder->received($other, $quote));
    $this->assertTrue($recorder->received($context, $quote));
    $this->assertFalse($recorder->received($context, $quote));
    $this->assertFalse($recorder->delivered($other, $result, fn () => $this->fail('Wrong context'), fn () => null));
    $this->assertTrue($recorder->delivered($context, $result, fn () => null, fn () => null));
    $this->assertFalse($recorder->delivered($context, $result, fn () => $this->fail('Duplicate delivery'), fn () => null));
    $this->assertFalse($recorder->failed($context, new GenerationFailure('provider')));
    $row = UsageAttempt::query()->sole();
    $this->assertSame('opaque:actor-001', $row->actor_id);
    $this->assertSame('delivered', $row->status);
    $this->assertNull($row->failure_code);
  }

  #[DataProvider('deliveryFailures')]
  public function test_delivery_or_final_write_failure_restores_scalar_and_array_fields(string $stage, mixed $original): void
  {
    $this->login();
    Http::fake(['*' => Http::response($this->response(), 200, ['x-request-id' => 'request-id'])]);
    if ($stage !== 'callback') {
      $status = $stage === 'received' ? 'received' : 'delivered';
      DB::unprepared("CREATE TEMP TRIGGER fail_usage_write BEFORE UPDATE ON filament_ai_writer_usage WHEN NEW.status = '$status' BEGIN SELECT RAISE(ABORT, 'PRIVATE database detail'); END");
    }
    $field = $original;
    try {
      AiWriterPlugin::get()->generation()->run('PRIVATE prompt', 'PRIVATE input', function () use (&$field, $stage) {
        $field = 'PRIVATE generated';
        if ($stage === 'callback') {
          throw new \RuntimeException('PRIVATE callback detail');
        }
      }, function () use (&$field, $original) { $field = $original; }, 'content');
      $this->fail('Must reject failed delivery');
    } catch (GenerationFailure $failure) {
      $this->assertSame($stage === 'callback' ? 'delivery' : 'storage', $failure->failureCode);
      $this->assertNull($failure->getPrevious());
      $this->assertStringNotContainsString('PRIVATE', $failure->getMessage());
    }
    $this->assertSame($original, $field);
    $row = UsageAttempt::query()->sole();
    $this->assertSame('failed', $row->status);
    $this->assertNull($row->delivered_at);
    $this->assertSame(100, $row->input_tokens);
    $this->assertSame('text', $row->outcome);
    $this->assertSame('response-id', $row->response_id);
    $this->assertSame('request-id', $row->request_id);
    $this->assertStringNotContainsString('PRIVATE', json_encode($row->getAttributes()));
    Http::assertSentCount(1);
  }

  public static function deliveryFailures(): array
  {
    return ['received write' => ['received', 'Original'], 'final write scalar' => ['final', 'Original'], 'final write array' => ['final', ['original']], 'callback' => ['callback', ['original']]];
  }

  #[DataProvider('providerFailures')]
  public function test_failure_records_preserve_available_usage_without_delivering_or_inventing_cost(string $condition, string $code, ?int $tokens, bool $unknown): void
  {
    $this->login();
    $attempts = 0;
    if ($condition === 'timeout') {
      Http::fake(function () use (&$attempts) {
        $attempts++;
        throw new \Illuminate\Http\Client\ConnectionException('PRIVATE timeout');
      });
    } else {
      $response = $this->response();
      if ($condition === 'refused') {
        $response['choices'][0]['message'] = ['refusal' => 'PRIVATE refusal'];
      } elseif ($condition === 'truncated') {
        $response['choices'][0]['finish_reason'] = 'length';
      } elseif ($condition === 'empty') {
        $response['choices'][0]['message']['content'] = '';
      }
      Http::fake(['*' => Http::response($response)]);
    }
    try {
      AiWriterPlugin::get()->generation()->run('PRIVATE prompt', 'PRIVATE input', fn () => $this->fail('Must not deliver'), fn () => null, 'content');
      $this->fail('Must fail safely');
    } catch (GenerationFailure $failure) {
      $this->assertSame($code, $failure->failureCode);
    }
    $row = UsageAttempt::query()->sole();
    $this->assertSame('failed', $row->status);
    $this->assertSame($tokens, $row->input_tokens);
    $this->assertSame($unknown, $row->outcome_unknown);
    $this->assertNull($row->cost_usd);
    $this->assertSame('unavailable', $row->cost_source);
    $this->assertSame('openai', $row->provider);
    $this->assertSame('gpt-4o', $row->requested_model);
    $this->assertNotNull($row->local_duration_ms);
    $this->assertStringNotContainsString('PRIVATE', json_encode($row->getAttributes()));
    if ($condition === 'timeout') {
      $this->assertSame(1, $attempts);
    } else {
      Http::assertSentCount(1);
    }
  }

  public static function providerFailures(): array
  {
    return ['refusal' => ['refused', 'refused', 100, false], 'truncation' => ['truncated', 'incomplete', 100, false], 'empty' => ['empty', 'empty', 100, false], 'ambiguous timeout' => ['timeout', 'connection', null, true]];
  }

  private function response(): array
  {
    return ['id' => 'response-id', 'model' => 'returned-model', 'choices' => [['message' => ['content' => 'PRIVATE result'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 40]];
  }


  public function test_estimated_cost_uses_returned_model_and_persists_exact_rates(): void
  {
    $this->login();
    config(['filament-ai-writer.pricing' => ['openai' => ['returned-model' => ['input_usd_per_million' => '2', 'output_usd_per_million' => '10']]]]);
    Http::fake(['*' => Http::response($this->response())]);
    AiWriterPlugin::get()->generation()->run('prompt', 'input', fn () => null, fn () => null, 'content');
    $row = UsageAttempt::query()->sole();
    $this->assertSame('0.000600000000', $row->cost_usd);
    $this->assertSame('estimated', $row->cost_source);
    $this->assertSame('2.000000000000', $row->input_rate_usd_per_million);
    $this->assertSame('10.000000000000', $row->output_rate_usd_per_million);
    $this->assertSame('gpt-4o', $row->requested_model);
    $this->assertSame('returned-model', $row->model);
  }

  public function test_persistent_write_outage_keeps_admission_without_delivering_or_retrying(): void
  {
    $this->login();
    Http::fake(function () {
      DB::unprepared("CREATE TEMP TRIGGER fail_all_finalization BEFORE UPDATE ON filament_ai_writer_usage BEGIN SELECT RAISE(ABORT, 'PRIVATE storage outage'); END");
      return Http::response($this->response());
    });
    try {
      AiWriterPlugin::get()->generation()->run('prompt', 'input', fn () => $this->fail('Must not deliver'), fn () => null, 'content');
      $this->fail('Must reject unavailable storage');
    } catch (GenerationFailure $failure) {
      $this->assertSame('storage', $failure->failureCode);
    }
    $row = UsageAttempt::query()->sole();
    $this->assertSame('pending', $row->status);
    $this->assertNull($row->completed_at);
    $this->assertNull($row->cost_usd);
    Http::assertSentCount(1);
  }

  public function test_utc_timestamps_round_trip_in_a_non_utc_host_timezone(): void
  {
    $this->login();
    $previousTimezone = date_default_timezone_get();
    $previousAppTimezone = config('app.timezone');
    $previousNow = CarbonImmutable::getTestNow();
    try {
      config(['app.timezone' => 'America/Sao_Paulo']);
      date_default_timezone_set('America/Sao_Paulo');
      CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21T12:00:00+00:00'));
      Http::fake(function () {
        $pending = UsageAttempt::query()->sole();
        foreach (['received_at', 'completed_at', 'delivered_at'] as $column) {
          $this->assertNull($pending->$column);
        }
        return Http::response($this->response());
      });
      AiWriterPlugin::get()->generation()->run('prompt', 'input', fn () => null, fn () => null, 'content');
      $attempt = UsageAttempt::query()->sole();
      foreach (['started_at', 'received_at', 'completed_at', 'delivered_at'] as $column) {
        $this->assertSame('2026-10-21 12:00:00', $attempt->getRawOriginal($column), $column);
        $date = $attempt->$column;
        $this->assertInstanceOf(CarbonImmutable::class, $date, $column);
        $this->assertSame('2026-10-21T12:00:00+00:00', $date->utc()->toIso8601String(), $column);
        $this->assertSame('2026-10-21T09:00:00-03:00', $date->setTimezone('America/Sao_Paulo')->toIso8601String(), $column);
      }
      $this->assertSame('America/Sao_Paulo', date_default_timezone_get());
    } finally {
      CarbonImmutable::setTestNow($previousNow);
      date_default_timezone_set($previousTimezone);
      config(['app.timezone' => $previousAppTimezone]);
    }
  }

}
