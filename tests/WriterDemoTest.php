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
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Event;
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
  public function test_unregistered_panel_reports_configuration_guidance_without_calling_provider(string $action, string $field, array $data): void
  {
    $this->signIn();
    Http::fake();
    $page = Livewire::test(WriterDemo::class);
    Filament::setCurrentPanel(Panel::make()->id('unregistered')->path('unregistered')->authGuard('web'));

    $page->callAction(TestAction::make($action)->schemaComponent($field, 'form'), data: $data)
      ->assertSet('data.' . $field, null)
      ->assertNotified(Notification::make()
        ->danger()
        ->title('AI Writer is not configured')
        ->body("Register AiWriterPlugin::make() with ->plugin() in this panel's PanelProvider before using AiWriterAction."));

    Http::assertNothingSent();
  }

  #[DataProvider('revocationSources')]
  public function test_revoked_permission_blocks_mounted_modal_and_subsequent_silent_request(string $source): void
  {
    $this->signIn();
    Http::fake();
    $allowPlugin = true;
    $allowAction = true;
    AiWriterPlugin::get()->authorizeUseAiUsing(function () use (&$allowPlugin): bool {
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
          AiWriterPlugin::get()->controls()->setEnabled(AiWriterPlugin::get()->controlContext(), false, 'Maintenance');
        } else {
          $context = AiWriterPlugin::get()->controlContext();
          AiWriterPlugin::get()->controls()->setBlocked($context, $context->actor, true, 'Hold');
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
