<?php

namespace PdroLucas\FilamentAiWriter\Tests;

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Notifications\Notification;
use PdroLucas\FilamentAiWriter\Actions\AiWriterAction;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Tests\Fixtures\ArrayWriterPage;
use PHPUnit\Framework\Attributes\DataProvider;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use PdroLucas\FilamentAiWriter\Events\AiTextGenerated;
use PdroLucas\FilamentAiWriter\Contracts\AiProvider;
use PdroLucas\FilamentAiWriter\Data\GenerateResult;
use Livewire\Livewire;
use Workbench\App\Models\User;
use Workbench\App\Pages\WriterDemo;
use Workbench\Database\Seeders\DatabaseSeeder;

class WriterDemoTest extends TestCase
{
  use DatabaseMigrations;

  public function test_generation_uses_the_tests_own_http_fake_and_an_isolated_database(): void
  {
    $this->assertSame('testing', DB::getDefaultConnection());
    $this->assertSame('sqlite', DB::connection()->getDriverName());
    $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    $this->assertSame('', DB::select('PRAGMA database_list')[0]->file);

    $this->get('/demo/writer-demo')->assertRedirect('/demo/login');

    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::firstOrFail(), 'workbench');
    Filament::setCurrentPanel(Filament::getPanel('demo'));
    Filament::bootCurrentPanel();

    $this->get('/demo/writer-demo')->assertOk()->assertSee('No request is sent to an AI provider.');

    Http::fake([
      'https://api.openai.com/v1/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => 'The test supplied this response.'], 'finish_reason' => 'stop']],
      ]),
    ]);

    Livewire::test(WriterDemo::class)
      ->callAction(TestAction::make('ai_writer')->schemaComponent('content', 'form'))
      ->assertSet('data.content', 'The test supplied this response.')->assertDispatched('ai-writer-credits-changed');

    Livewire::test(WriterDemo::class)
      ->callAction(TestAction::make('ai_interactive')->schemaComponent('interactive_content', 'form'), data: [
        'ai_input' => 'Write an introduction to Filament plugins.',
      ])
      ->assertSet('data.interactive_content', 'The test supplied this response.')->assertDispatched('ai-writer-credits-changed');

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/chat/completions'
      && $request->hasHeader('Authorization', 'Bearer workbench-fake-key'));
  }

  #[DataProvider('generationModes')]
  public function test_standalone_action_generates_without_plugin_or_package_tables(string $action, string $field, array $data): void
  {
    $this->signIn();
    // A 0.7.1 host: no plugin on the panel and no package migrations.
    $this->artisan('migrate:reset', ['--path' => realpath(__DIR__ . '/../database/migrations'), '--realpath' => true])->assertSuccessful();
    $this->assertSame([], array_filter(\Illuminate\Support\Facades\Schema::getTableListing(), fn (string $table): bool => str_contains($table, 'filament_ai_writer')));
    Filament::setCurrentPanel(Panel::make()->id('unregistered')->path('unregistered')->authGuard('workbench'));
    Event::fake([AiTextGenerated::class]);
    Http::fake(['https://api.openai.com/v1/chat/completions' => Http::sequence()
      ->push(['error' => ['message' => 'Unavailable']], 500)
      ->push(['choices' => [['message' => ['content' => 'Standalone result.'], 'finish_reason' => 'stop']]])]);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
      $queries[] = $query->sql;
    });

    $page = Livewire::test(WriterDemo::class)
      ->callAction(TestAction::make($action)->schemaComponent($field, 'form'), data: $data)
      ->assertSet('data.' . $field, null)
      ->assertNotified('AI generation was not delivered');
    Event::assertNotDispatched(AiTextGenerated::class);

    $page->callAction(TestAction::make($action)->schemaComponent($field, 'form'), data: $data)
      ->assertSet('data.' . $field, 'Standalone result.')
      ->assertNotified('Generated successfully');

    Event::assertDispatched(AiTextGenerated::class, fn ($event) => $event->payload['field'] === $field && $event->payload['result'] === 'Standalone result.');
    $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'filament_ai_writer'))));
    Http::assertSentCount(2);
    // Maintenance commands stay safe to schedule before the optional storage exists.
    $this->artisan('ai-writer:prune-usage', ['--days' => 90])->expectsOutputToContain('not installed')->assertSuccessful();
    $this->artisan('ai-writer:recover-operations')->expectsOutputToContain('not installed')->assertSuccessful();
  }

  #[DefineEnvironment('withoutUsageTracking')]
  public function test_registered_plugin_without_modules_has_no_statistics_route_and_skips_plugin_storage(): void
  {
    $this->signIn();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
      $queries[] = $query->sql;
    });
    Http::fake(['https://api.openai.com/v1/chat/completions' => Http::response([
      'choices' => [['message' => ['content' => 'Core result.'], 'finish_reason' => 'stop']],
    ])]);

    $this->get('/demo/ai-writer-usage')->assertNotFound();
    $this->get('/demo/ai-writer-controls')->assertNotFound();
    $this->get('/demo/writer-demo')->assertOk();
    Livewire::test(WriterDemo::class)
      ->callAction(TestAction::make('ai_writer')->schemaComponent('content', 'form'))
      ->assertSet('data.content', 'Core result.');

    $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'filament_ai_writer'))));
    AiWriterPlugin::get()->authorizeUseAi(fn () => false);
    $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
    AiWriterPlugin::get()->generation()->run('prompt', 'input', fn () => $this->fail('Denied use must not deliver'), fn () => null, 'content');
  }

  #[DefineEnvironment('withoutControls')]
  public function test_usage_tracking_without_controls_records_the_ledger_without_admission_or_debit(): void
  {
    $this->signIn();
    $plugin = AiWriterPlugin::get();
    $plugin->authorizeManage(fn () => true);
    $context = $plugin->controlContext();
    // Stored controls that would refuse this generation if admission ran.
    $plugin->credits()->configure($context, true, 0, 'manual', 'No credits');
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
      $queries[] = $query->sql;
    });
    Http::fake(['https://api.openai.com/v1/chat/completions' => Http::response([
      'choices' => [['message' => ['content' => 'Tracked result.'], 'finish_reason' => 'stop']],
    ])]);

    $this->get('/demo/ai-writer-controls')->assertNotFound();
    $this->get('/demo/my-ai-credits')->assertNotFound();
    $this->get('/demo/ai-writer-usage')->assertOk();
    Livewire::test(WriterDemo::class)
      ->callAction(TestAction::make('ai_writer')->schemaComponent('content', 'form'))
      ->assertSet('data.content', 'Tracked result.');

    $controlsTables = '/filament_ai_writer_(policies|actor_blocks|control_audit|credit_)/';
    $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => preg_match($controlsTables, $sql) === 1)));
    $this->assertSame('delivered', \PdroLucas\FilamentAiWriter\Models\UsageAttempt::query()->sole()->status);
    $operation = DB::table('filament_ai_writer_operations')->sole();
    $this->assertSame(['delivered', null, null], [$operation->status, $operation->scope_key, $operation->balance_id]);
    $this->assertNotNull($operation->deadline_at, 'Ledger-only operations stay recoverable.');

    // Turning controls back on resumes the stored state; nothing was charged or deleted.
    $plugin->controls();
    $balance = $plugin->credits()->ownBalance($context);
    $this->assertSame([true, 0, 0, 0], [$balance['enabled'], $balance['limit'], $balance['consumed'], $balance['reserved']]);
    Livewire::test(WriterDemo::class)
      ->callAction(TestAction::make('ai_writer')->schemaComponent('content', 'form'))
      ->assertNotified('AI generation was not delivered');
    Http::assertSentCount(1);
  }

  protected function withoutControls($app): void
  {
    $app['config']->set('workbench.controls', false);
  }

  protected function withoutUsageTracking($app): void
  {
    $app['config']->set('workbench.usage_tracking', false);
  }

  #[DataProvider('revocationSources')]
  public function test_revoked_permission_blocks_mounted_modal_and_subsequent_silent_request(string $source): void
  {
    $this->signIn();
    Http::fake();
    $allowPlugin = true;
    $allowAction = true;
    AiWriterPlugin::get()->authorizeUseAi(function () use (&$allowPlugin): bool {
      return $allowPlugin;
    });

    AiWriterAction::configureUsing(
      function (AiWriterAction $action) use (&$allowAction): void {
        $action->authorize(function () use (&$allowAction): bool {
          return $allowAction;
        });
      },
      function () use ($source, &$allowPlugin, &$allowAction): void {
        $page = Livewire::test(WriterDemo::class)
          ->mountAction(TestAction::make('ai_interactive')->schemaComponent('interactive_content', 'form'))
          ->assertActionMounted(TestAction::make('ai_interactive')->schemaComponent('interactive_content', 'form'));

        if ($source === 'plugin') {
          $allowPlugin = false;
        } elseif ($source === 'action') {
          $allowAction = false;
        } elseif ($source === 'disabled') {
          AiWriterPlugin::get()->controlsService()->setEnabled(AiWriterPlugin::get()->controlContext(), false, 'Maintenance');
        } else {
          $context = AiWriterPlugin::get()->controlContext();
          AiWriterPlugin::get()->controlsService()->setBlocked($context, $context->actor, true, 'Hold');
        }

        $page->setActionData(['ai_input' => 'Write an introduction to Filament plugins.'])
          ->callMountedAction()
          ->assertSet('data.interactive_content', null);

        // Use the raw Livewire entry point because a denied action may be hidden.
        $page->call('mountAction', 'ai_writer', [], ['schemaComponent' => 'form.content'])
          ->assertSet('data.content', null);
      },
    );

    Http::assertNothingSent();
  }

  #[DataProvider('arrayModes')]
  public function test_generation_preserves_array_results(bool $silent): void
  {
    $this->signIn();
    config(['testing.silent' => $silent]);
    Http::fake([
      'https://api.openai.com/v1/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => '["Laravel", "FILAMENT", "Laravel"]'], 'finish_reason' => 'stop']],
      ]),
    ]);

    Livewire::test(ArrayWriterPage::class)
      ->callAction(TestAction::make('ai_writer')->schemaComponent('tags', 'form'), data: $silent ? [] : [
        'ai_input' => 'Suggest tags for a Laravel and Filament article.',
      ])
      ->assertSet('data.tags', ['laravel', 'filament']);

    Http::assertSentCount(1);
  }

  #[DataProvider('undeliverableResponses')]
  public function test_non_deliverable_provider_output_preserves_the_field_and_emits_no_success(bool $silent, array $body): void
  {
    $this->signIn();
    Event::fake([AiTextGenerated::class]);
    Http::fake(['https://api.openai.com/v1/chat/completions' => Http::response($body)]);
    $field = $silent ? 'content' : 'interactive_content';
    $action = $silent ? 'ai_writer' : 'ai_interactive';

    Livewire::test(WriterDemo::class)
      ->set('data.' . $field, 'Original field value')
      ->callAction(TestAction::make($action)->schemaComponent($field, 'form'), data: $silent ? [] : [
        'ai_input' => 'Write a short introduction to Filament plugins.',
      ])
      ->assertSet('data.' . $field, 'Original field value')
      ->assertNotified('AI generation was not delivered');

    Event::assertNotDispatched(AiTextGenerated::class);
    Http::assertSentCount(1);
  }

  #[DataProvider('arrayModes')]
  public function test_empty_array_output_preserves_the_array_field(bool $silent): void
  {
    $this->signIn();
    config(['testing.silent' => $silent]);
    Http::fake(['https://api.openai.com/v1/chat/completions' => Http::response([
      'choices' => [['message' => ['content' => '[]'], 'finish_reason' => 'stop']],
    ])]);
    Livewire::test(ArrayWriterPage::class)
      ->set('data.tags', ['original'])
      ->callAction(TestAction::make('ai_writer')->schemaComponent('tags', 'form'), data: $silent ? [] : [
        'ai_input' => 'Suggest tags for a Laravel and Filament article.',
      ])
      ->assertSet('data.tags', ['original'])
      ->assertNotified('AI generation was not delivered');
  }

  #[DataProvider('restrictedOutputs')]
  public function test_silent_selection_and_allowed_values_remain_validated(string $kind, string $text, mixed $expected): void
  {
    $this->signIn();
    config(['testing.silent' => true]);
    Http::fake(['https://api.openai.com/v1/chat/completions' => Http::response([
      'choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']],
    ])]);
    AiWriterAction::configureUsing(
      fn (AiWriterAction $action) => $kind === 'map' ? $action->valueMap([1 => 'First', 2 => 'Second']) : $action->allowedValues(['Laravel', 'Filament']),
      function () use ($kind, $expected): void {
        $field = $kind === 'map' ? 'content' : 'tags';
        Livewire::test($kind === 'map' ? WriterDemo::class : ArrayWriterPage::class)
          ->set('data.' . $field, $kind === 'map' ? 'original' : ['original'])
          ->callAction(TestAction::make('ai_writer')->schemaComponent($field, 'form'))
          ->assertSet('data.' . $field, $expected);
      },
    );
  }

  public function test_host_custom_provider_is_used_by_the_plugins_shared_generation_service(): void
  {
    $this->signIn();
    Event::fake([AiTextGenerated::class]);
    $this->app->bind(AiProvider::class, fn () => new class implements AiProvider {
      public function generate(string $systemPrompt, string $userInput): GenerateResult
      {
        return new GenerateResult(provider: 'host-provider', requestedModel: 'requested', model: 'returned', text: 'Custom provider result');
      }
    });
    Livewire::test(WriterDemo::class)
      ->callAction(TestAction::make('ai_writer')->schemaComponent('content', 'form'))
      ->assertSet('data.content', 'Custom provider result');
    Event::assertDispatched(AiTextGenerated::class, fn ($event) => $event->payload['provider'] === 'host-provider' && $event->payload['model'] === 'returned');
    Http::assertNothingSent();
  }

  public function test_failing_generated_text_listener_keeps_the_single_charged_delivery(): void
  {
    $this->signIn();
    $plugin = AiWriterPlugin::get();
    $plugin->authorizeManage(fn () => true);
    $context = $plugin->controlContext();
    $plugin->credits()->configure($context, true, 3, 'manual', 'Start quota');
    Exceptions::fake();
    $failure = new \RuntimeException('Host listener failed');
    Event::listen(AiTextGenerated::class, fn () => throw $failure);
    Http::fake(['https://api.openai.com/v1/chat/completions' => Http::response([
      'choices' => [['message' => ['content' => 'Charged and delivered.'], 'finish_reason' => 'stop']],
    ])]);

    Livewire::test(WriterDemo::class)
      ->callAction(TestAction::make('ai_writer')->schemaComponent('content', 'form'))
      ->assertOk()
      ->assertSet('data.content', 'Charged and delivered.');

    Exceptions::assertReported(fn (\RuntimeException $reported): bool => $reported === $failure);
    $this->assertSame(1, $plugin->credits()->ownBalance($context)['consumed']);
    $this->assertSame(['delivered'], DB::table('filament_ai_writer_operations')->pluck('status')->all());
    Http::assertSentCount(1);
  }

  public function test_notification_mailbox_survives_independent_workbench_requests(): void
  {
    $this->signIn();
    $cachePath = sys_get_temp_dir() . '/ai-writer-notifications-' . bin2hex(random_bytes(8));
    $previousPath = config('cache.stores.file.path');
    $previousLockPath = config('cache.stores.file.lock_path');
    try {
      config(['cache.stores.file.path' => $cachePath, 'cache.stores.file.lock_path' => $cachePath]);
      $this->app['cache']->forgetDriver('file');
      // Minimal production transport: publish, then a fresh request reads the same session's mailbox.
      Notification::make()->warning()->title('AI generation was not delivered')->body('Your AI access is blocked.')->send();
      app(\Filament\Notifications\NotificationMailbox::class)->flush();
      $this->app->forgetScopedInstances();
      $this->app['cache']->forgetDriver(config('filament.cache_store') ?? config('cache.default'));
      Livewire::test(\Filament\Notifications\Livewire\Notifications::class)
        ->call('pullNotificationsFromSession')
        ->assertSee('AI generation was not delivered')
        ->assertSee('Your AI access is blocked.');
    } finally {
      $this->app['cache']->forgetDriver('file');
      config(['cache.stores.file.path' => $previousPath, 'cache.stores.file.lock_path' => $previousLockPath]);
      \Illuminate\Support\Facades\File::deleteDirectory($cachePath);
    }
  }

  public static function restrictedOutputs(): array
  {
    return [
      'valid selection' => ['map', ' 2 ', '2'],
      'invalid selection preserves original' => ['map', '99', 'original'],
      'allowed array' => ['values', '["laravel", "unknown"]', ['Laravel']],
      'empty filtered array preserves original' => ['values', '["unknown"]', ['original']],
    ];
  }

  public static function undeliverableResponses(): array
  {
    return [
      'silent empty' => [true, ['choices' => [['message' => ['content' => ' '], 'finish_reason' => 'stop']]]],
      'interactive truncated' => [false, ['choices' => [['message' => ['content' => 'PRIVATE partial'], 'finish_reason' => 'length']]]],
      'interactive refusal' => [false, ['choices' => [['message' => ['content' => 'PRIVATE refusal', 'refusal' => 'PRIVATE'], 'finish_reason' => 'stop']]]],
      'silent invalid content' => [true, ['choices' => [['message' => ['content' => 123], 'finish_reason' => 'stop']]]],
      'silent body error' => [true, ['error' => ['message' => 'PRIVATE upstream error']]],
    ];
  }

  public static function arrayModes(): array
  {
    return ['silent array' => [true], 'interactive array' => [false]];
  }

  public static function generationModes(): array
  {
    return [
      'silent' => ['ai_writer', 'content', []],
      'interactive' => ['ai_interactive', 'interactive_content', ['ai_input' => 'Write a short introduction to Filament.']],
    ];
  }

  public static function revocationSources(): array
  {
    return [...self::permissionSources(), 'scope disabled' => ['disabled'], 'actor blocked' => ['blocked']];
  }

  public static function permissionSources(): array
  {
    return ['plugin denial despite action allowing' => ['plugin'], 'host action denial' => ['action']];
  }

  protected function signIn(): void
  {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::firstOrFail(), 'workbench');
    Filament::setCurrentPanel(Filament::getPanel('demo'));
    Filament::bootCurrentPanel();
  }

}
