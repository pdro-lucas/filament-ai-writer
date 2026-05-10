<?php

namespace PdroLucas\FilamentAiWriter;

use Illuminate\Support\ServiceProvider;
use PdroLucas\FilamentAiWriter\Contracts\AiProvider;
use PdroLucas\FilamentAiWriter\Providers\AnthropicProvider;
use PdroLucas\FilamentAiWriter\Providers\GeminiProvider;
use PdroLucas\FilamentAiWriter\Providers\OpenAiProvider;

class FilamentAiWriterServiceProvider extends ServiceProvider
{
  public function register(): void
  {
    $this->mergeConfigFrom(__DIR__ . "/../config/filament-ai-writer.php", "filament-ai-writer");

    $this->app->bind(AiProvider::class, function () {
      return match (config("filament-ai-writer.provider")) {
        "openai" => new OpenAiProvider(),
        "gemini" => new GeminiProvider(),
        default => new AnthropicProvider(),
      };
    });
  }

  public function boot(): void
  {
    $this->publishes(
      [
        __DIR__ . "/../config/filament-ai-writer.php" => config_path("filament-ai-writer.php"),
      ],
      "filament-ai-writer",
    );
  }
}
