<?php

namespace PdroLucas\FilamentAiWriter\Providers;

use Illuminate\Support\Facades\Http;
use PdroLucas\FilamentAiWriter\Contracts\AiProvider;

class AnthropicProvider implements AiProvider
{
  public function generate(string $systemPrompt, string $userInput): string
  {
    $config = config("filament-ai-writer.anthropic");

    $response = Http::withToken($config["api_key"])
      ->withHeaders(["anthropic-version" => "2023-06-01"])
      ->post("https://api.anthropic.com/v1/messages", [
        "model" => $config["model"],
        "max_tokens" => config("filament-ai-writer.max_tokens"),
        "system" => $systemPrompt,
        "messages" => [["role" => "user", "content" => $userInput]],
      ]);

    return $response->json("context.0.text", "");
  }
}
