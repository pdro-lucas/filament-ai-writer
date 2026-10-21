<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Request;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\User;

class WorkbenchServiceProvider extends ServiceProvider
{
  public function register(): void
  {
    $this->app->useDatabasePath(dirname(__DIR__, 2) . '/database');

    $this->app['config']->set([
      'database.default' => 'workbench',
      'database.connections.workbench' => [
        'driver' => 'sqlite',
        'database' => dirname(__DIR__, 2) . '/database/database.sqlite',
        'prefix' => '',
        'foreign_key_constraints' => true,
      ],
      'auth.guards.workbench' => ['driver' => 'session', 'provider' => 'workbench'],
      'auth.providers.workbench' => ['driver' => 'eloquent', 'model' => User::class],
      'cache.default' => 'array',
      'session.driver' => 'file',
      'session.cookie' => 'ai_writer_workbench_session',
      'filament-ai-writer.provider' => 'openai',
      'filament-ai-writer.openai.api_key' => 'workbench-fake-key',
      'filament-ai-writer.openai.model' => 'workbench-demo',
      'filament-ai-writer.pricing.openai.workbench-demo-estimated' => ['input_usd_per_million' => '2', 'output_usd_per_million' => '10'],
    ]);
  }

  public function boot(): void
  {
    $this->loadViewsFrom(dirname(__DIR__, 2) . '/resources/views', 'workbench');

    Http::preventStrayRequests();

    if ($this->app->runningUnitTests()) {
      return;
    }

    Http::fake([
      'https://api.openai.com/v1/chat/completions' => function (Request $request) {
        $body = $request->body();
        if (str_contains($body, '[demo:failure]')) {
          return Http::response(['error' => ['message' => 'Local demo provider failure.']], 503);
        }
        return Http::response([
          'id' => 'workbench-response',
          'model' => str_contains($body, '[demo:estimated]') ? 'workbench-demo-estimated' : 'workbench-demo',
          'choices' => [[
            'message' => ['content' => 'This text was generated locally by the Workbench HTTP fake.'],
            'finish_reason' => str_contains($body, '[demo:truncated]') ? 'length' : 'stop',
          ]],
          'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 40],
        ]);
      },
    ]);
  }
}
