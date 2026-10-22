<?php

namespace PdroLucas\FilamentAiWriter;

use Illuminate\Support\ServiceProvider;
use PdroLucas\FilamentAiWriter\Exceptions\GenerationFailure;
use PdroLucas\FilamentAiWriter\Contracts\AiProvider;
use PdroLucas\FilamentAiWriter\Providers\AnthropicProvider;
use PdroLucas\FilamentAiWriter\Providers\GeminiProvider;
use PdroLucas\FilamentAiWriter\Providers\OpenAiProvider;
use PdroLucas\FilamentAiWriter\Providers\OpenRouterProvider;

class FilamentAiWriterServiceProvider extends ServiceProvider
{
  public function register(): void
  {
    $this->mergeConfigFrom(__DIR__ . "/../config/filament-ai-writer.php", "filament-ai-writer");

    $this->app->bind(AiProvider::class, function () {
      return match (config("filament-ai-writer.provider")) {
        "openai" => new OpenAiProvider(),
        "gemini" => new GeminiProvider(),
        "openrouter" => new OpenRouterProvider(),
        "anthropic" => new AnthropicProvider(),
        default => throw new GenerationFailure("configuration"),
      };
    });
  }

  public function boot(): void
  {
    if ($this->app->runningInConsole()) {
      $this->commands([\PdroLucas\FilamentAiWriter\Console\PruneUsageCommand::class]);
    }

    $this->loadViewsFrom(__DIR__ . "/../resources/views", "filament-ai-writer");

    $this->publishesMigrations([
      __DIR__ . "/../database/migrations/2026_10_21_000001_create_ai_writer_usage_table.php" => database_path("migrations/2026_10_21_000001_create_ai_writer_usage_table.php"),
      __DIR__ . "/../database/migrations/2026_10_21_000002_create_ai_writer_controls_tables.php" => database_path("migrations/2026_10_21_000002_create_ai_writer_controls_tables.php"),
    ], "filament-ai-writer-migrations");

    $this->publishes(
      [
        __DIR__ . "/../config/filament-ai-writer.php" => config_path("filament-ai-writer.php"),
      ],
      "filament-ai-writer",
    );
  }
}
