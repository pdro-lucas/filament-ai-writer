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
    } elseif ($condition === 'ledger_connection') {
      config(['database.connections.separate' => config('database.connections.testing'), 'filament-ai-writer.connection' => 'separate']);
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
      config(['database.default' => 'testing', 'filament-ai-writer.connection' => null]);
      if ($condition === 'transaction') {
        DB::rollBack();
      }
    }
  }

  public static function admissionFailures(): array
  {
    return ['separate ledger connection' => ['ledger_connection', 'storage'], 'missing table' => ['table', 'storage'], 'missing connection' => ['connection', 'storage'], 'outer transaction' => ['transaction', 'transaction']];
  }

  private function login(): void
  {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::firstOrFail(), 'workbench');
    Filament::setCurrentPanel(Filament::getPanel('demo'));
    config(['filament-ai-writer.provider' => 'openai', 'filament-ai-writer.openai.api_key' => 'PRIVATE-key', 'filament-ai-writer.openai.model' => 'gpt-4o']);
  }

  #[DataProvider('admissionChanges')]
  public function test_admitted_generation_finishes_with_frozen_context_and_later_requests_recheck_admission(?string $change, string $failureCode): void
  {
    $this->login();
    $actor = User::firstOrFail();
    $operation = (string) \Illuminate\Support\Str::uuid();
    $plugin = AiWriterPlugin::get();
    $context = $plugin->controlContext();
    Http::fake(function () use ($actor, $plugin, $context, $change) {
      $this->assertSame(0, DB::transactionLevel());
      $this->assertSame('pending', UsageAttempt::query()->sole()->status);
      $this->assertSame((string) $actor->getKey(), UsageAttempt::query()->sole()->actor_id);
      if ($change === 'disable') {
        $plugin->controls()->setEnabled($context, false, 'Maintenance during generation');
      } elseif ($change === 'block') {
        $plugin->controls()->setBlocked($context, $context->actor, true, 'Hold during generation');
      }
      $this->assertSame(0, DB::transactionLevel());
      Filament::setCurrentPanel(\Filament\Panel::make()->id('isolated')->authGuard('web'));
      return Http::response($this->response(), 200, ['x-request-id' => 'request-id']);
    });
    $service = AiWriterPlugin::get()->generation();
    $deliveries = 0;
    $deliveredText = null;
    $service->run('prompt', 'input', function (GenerateResult $result) use (&$deliveries, &$deliveredText) {
      $deliveries++;
      $deliveredText = $result->text;
    }, fn () => null, 'content', $operation);
    $this->assertSame('PRIVATE result', $deliveredText);
    $attempt = UsageAttempt::query()->sole();
    $this->assertSame('demo', $attempt->panel_id);
    $this->assertSame('delivered', $attempt->status);
    Filament::setCurrentPanel(Filament::getPanel('demo'));
    try {
      $service->run('prompt', 'input', fn () => $this->fail('Unexpected delivery'), fn () => null, 'content', $change === null ? $operation : null);
      $this->fail('Later admission must fail');
    } catch (GenerationFailure $failure) {
      $this->assertSame($failureCode, $failure->failureCode);
    }
    $this->assertSame(1, $deliveries);
    $this->assertSame(1, UsageAttempt::query()->count());
    Http::assertSentCount(1);
  }

  public static function admissionChanges(): array
  {
    return [
      'duplicate operation' => [null, 'duplicate'],
      'disabled during HTTP' => ['disable', 'disabled'],
      'blocked during HTTP' => ['block', 'blocked'],
    ];
  }

  public function test_pruned_operation_cannot_call_provider_or_deliver_again(): void
  {
    $this->login();
    $operation = (string) \Illuminate\Support\Str::uuid();
    Http::fake(['*' => Http::response($this->response())]);
    $generation = AiWriterPlugin::get()->generation();
    $generation->run('prompt', 'input', fn () => null, fn () => null, 'content', $operation);
    DB::table('filament_ai_writer_usage')->update(['started_at' => '2020-01-01', 'completed_at' => '2020-01-02']);
    config(['filament-ai-writer.retention' => ['days' => 1, 'batch_size' => 500]]);
    $this->artisan('ai-writer:prune-usage')->expectsOutputToContain('Deleted 1')->assertSuccessful();
    try {
      $generation->run('prompt', 'input', fn () => $this->fail('Duplicate delivery'), fn () => null, 'content', $operation);
      $this->fail('Must preserve durable duplicate guard');
    } catch (GenerationFailure $failure) {
      $this->assertSame('duplicate', $failure->failureCode);
    }
    Http::assertSentCount(1);
  }

  public function test_public_delivery_refuses_outer_transaction_before_mutating_field(): void
  {
    $context = new \PdroLucas\FilamentAiWriter\Data\GenerationContext((string) \Illuminate\Support\Str::uuid(), 'panel', 'Actor', 'opaque', 'content');
    $recorder = app(\PdroLucas\FilamentAiWriter\Usage\UsageRecorder::class);
    $result = new GenerateResult('custom', 'requested', 'returned', 'Text');
    $recorder->begin($context);
    $recorder->received($context, app(\PdroLucas\FilamentAiWriter\Usage\CostCalculator::class)->quote($result));
    DB::beginTransaction();
    try {
      $recorder->delivered($context, $result, fn () => $this->fail('Must not mutate'), fn () => $this->fail('No restore needed'));
      $this->fail('Must reject outer transaction');
    } catch (GenerationFailure $failure) {
      $this->assertSame('transaction', $failure->failureCode);
    } finally {
      DB::rollBack();
    }
    $this->assertTrue($recorder->delivered($context, $result, fn () => null, fn () => null));
  }

  #[DataProvider('expiryCases')]
  public function test_deadline_fences_late_http_and_releases_once_with_or_without_maintenance(bool $quota, bool $maintenance, bool $prune = false, int $httpStatus = 200, bool $invalidBody = false): void
  {
    $this->login();
    $plugin = AiWriterPlugin::get();
    $plugin->authorizeManageUsing(fn () => true);
    $context = $plugin->controlContext();
    $plugin->credits()->configure($context, $quota, 1, 'manual', 'Start');
    config(['filament-ai-writer.timeout' => 60, 'filament-ai-writer.recovery.margin_seconds' => 10]);
    $operation = (string) \Illuminate\Support\Str::uuid();
    $previous = CarbonImmutable::getTestNow();
    CarbonImmutable::setTestNow('2026-10-21 12:00:00 UTC');
    Http::fake(function () use ($plugin, $context, $quota, $maintenance, $prune, $httpStatus, $invalidBody) {
      CarbonImmutable::setTestNow('2026-10-21 12:01:10 UTC');
      // Editing configuration does not extend the already frozen deadline.
      config(['filament-ai-writer.timeout' => 1000, 'filament-ai-writer.recovery.margin_seconds' => 1000]);
      if ($maintenance) {
        $this->artisan('ai-writer:recover-operations')->expectsOutputToContain('Recovered 1')->assertSuccessful();
        $this->artisan('ai-writer:recover-operations')->expectsOutputToContain('Recovered 0')->assertSuccessful();
        $balance = $plugin->credits()->ownBalance($context);
        $this->assertSame(1, $balance['available']);
        $this->assertSame(0, $balance['reserved']);
        if ($prune) {
          DB::table('filament_ai_writer_usage')->update(['started_at' => '2020-01-01', 'completed_at' => '2020-01-02']);
          $this->artisan('ai-writer:prune-usage', ['--days' => 1])->expectsOutputToContain('Deleted 1')->assertSuccessful();
        }
      }
      return Http::response($invalidBody ? 'invalid json' : $this->response(), $httpStatus, ['x-request-id' => 'late-request']);
    });
    try {
      $plugin->generation()->run('prompt', 'input', fn () => $this->fail('Late worker must not deliver'), fn () => null, 'content', $operation);
      $this->fail('Deadline must refuse delivery');
    } catch (GenerationFailure $failure) {
      $this->assertSame($httpStatus === 200 ? 'expired' : ($httpStatus === 400 ? 'request' : 'provider'), $failure->failureCode);
      $balance = $plugin->credits()->ownBalance($context);
      $this->assertSame(1, $balance['available']);
      $this->assertSame(0, $balance['reserved']);
      $this->assertSame(0, $balance['consumed']);
      $this->artisan('ai-writer:recover-operations')->expectsOutputToContain('Recovered 0')->assertSuccessful();
    } finally {
      CarbonImmutable::setTestNow($previous);
    }
    Http::assertSentCount(1);
    if (!$prune) {
      $row = UsageAttempt::query()->sole();
      $this->assertSame('failed', $row->status);
      $this->assertSame('expired', $row->failure_code);
      $this->assertSame($invalidBody ? null : 100, $row->input_tokens);
      $this->assertSame($httpStatus >= 500, $row->outcome_unknown);
      $this->assertSame('late-request', $row->request_id);
      $this->assertIsInt($row->duration_ms);
      if ($httpStatus !== 200) {
        $this->assertSame($httpStatus, $row->http_status);
      }
    }
    // A different late quote cannot replace the first known response or recreate pruning.
    $late = new \PdroLucas\FilamentAiWriter\Data\GenerationContext($operation, $context->panelId, $context->actor->type, $context->actor->id, 'content');
    $changed = new GenerateResult('custom', 'model', 'model', 'Text', inputTokens: 999);
    try {
      app(\PdroLucas\FilamentAiWriter\Usage\UsageRecorder::class)->received($late, app(\PdroLucas\FilamentAiWriter\Usage\CostCalculator::class)->quote($changed));
      $this->fail('Must remain expired');
    } catch (GenerationFailure $failure) {
      $this->assertSame('expired', $failure->failureCode);
    }
    if ($prune) {
      $this->assertSame(0, UsageAttempt::query()->count());
    } else {
      $this->assertSame($invalidBody ? null : 100, UsageAttempt::query()->sole()->input_tokens);
    }
  }

  public static function expiryCases(): array
  {
    return ['quota maintenance' => [true, true], 'quota inline' => [true, false], 'free maintenance' => [false, true], 'free inline' => [false, false], 'pruned late response' => [true, true, true], 'late 400 no result' => [true, false, false, 400, true], 'late 503 no result' => [true, true, false, 503, true], 'late 503 measured unknown result' => [true, true, false, 503, false]];
  }

  public function test_deadline_crossed_by_local_delivery_restores_field_and_refunds(): void
  {
    $this->login();
    $plugin = AiWriterPlugin::get();
    $plugin->authorizeManageUsing(fn () => true);
    $context = $plugin->controlContext();
    $plugin->credits()->configure($context, true, 1, 'manual', 'Start');
    config(['filament-ai-writer.timeout' => 1, 'filament-ai-writer.recovery.margin_seconds' => 0, 'filament-ai-writer.pricing.openai.returned-model' => ['input_usd_per_million' => '2', 'output_usd_per_million' => '10']]);
    $previous = CarbonImmutable::getTestNow();
    CarbonImmutable::setTestNow('2026-10-21 12:00:00 UTC');
    $field = 'Original';
    Http::fake(['*' => Http::response($this->response())]);
    try {
      $plugin->generation()->run('prompt', 'input', function () use (&$field) {
        $field = 'Generated';
        CarbonImmutable::setTestNow('2026-10-21 12:00:01 UTC');
      }, function () use (&$field) { $field = 'Original'; }, 'content');
      $this->fail('Must expire slow delivery');
    } catch (GenerationFailure $failure) {
      $this->assertSame('expired', $failure->failureCode);
      $this->assertSame('Original', $field);
      $this->assertSame(1, $plugin->credits()->ownBalance($context)['available']);
      $this->assertSame(0, $plugin->credits()->ownBalance($context)['reserved']);
      $row = UsageAttempt::query()->sole();
      $this->assertSame('failed', $row->status);
      $this->assertFalse($row->outcome_unknown);
      $this->assertSame(100, $row->input_tokens);
      $this->assertSame(40, $row->output_tokens);
      $this->assertSame('0.000600000000', $row->cost_usd);
    } finally {
      CarbonImmutable::setTestNow($previous);
    }
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
    $plugin = AiWriterPlugin::get();
    $control = $plugin->controlContext();
    $plugin->authorizeManageUsing(fn () => true);
    $plugin->credits()->configure($control, true, 1, 'manual', 'Quota for failure');
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
    $balance = $plugin->credits()->ownBalance($control);
    $this->assertSame(1, $balance['available']);
    $this->assertSame(0, $balance['reserved']);
    $this->assertSame(0, $balance['consumed']);
    $row = UsageAttempt::query()->sole();
    $retryContext = new \PdroLucas\FilamentAiWriter\Data\GenerationContext($row->operation_id, $row->panel_id, $row->actor_type, $row->actor_id, $row->field);
    $this->assertFalse(app(\PdroLucas\FilamentAiWriter\Usage\UsageRecorder::class)->failed($retryContext, new GenerationFailure('provider')));
    $this->assertSame(1, $plugin->credits()->ownBalance($control)['available']);
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
