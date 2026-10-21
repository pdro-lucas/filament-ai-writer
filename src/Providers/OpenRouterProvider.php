<?php

namespace PdroLucas\FilamentAiWriter\Providers;

use Illuminate\Support\Facades\Http;
use PdroLucas\FilamentAiWriter\Contracts\AiProvider;

class OpenRouterProvider implements AiProvider
{
  public function generate(string $systemPrompt, string $userInput): string
  {
    $config = config("filament-ai-writer.openrouter");
    $payload = [
      "model" => $config["model"],
      "max_tokens" => (int) config("filament-ai-writer.max_tokens"),
      "messages" => [["role" => "system", "content" => $systemPrompt], ["role" => "user", "content" => $userInput]],
    ];

    $headers = array_filter([
      "HTTP-Referer" => config("app.url"),
      "X-Title" => config("app.name"),
    ]);

    $response = Http::withToken($config["api_key"])
      ->withHeaders($headers)
      ->connectTimeout((int) config("filament-ai-writer.connect_timeout"))
      ->timeout((int) config("filament-ai-writer.timeout"))
      ->retry((int) config("filament-ai-writer.retry_times"), (int) config("filament-ai-writer.retry_sleep_ms"))
      ->post("https://openrouter.ai/api/v1/chat/completions", $payload)
      ->throw();

    return $response->json("choices.0.message.content", "");
  }
}
