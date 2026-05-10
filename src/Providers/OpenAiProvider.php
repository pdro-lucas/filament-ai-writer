<?php

namespace PdroLucas\FilamentAiWriter\Providers;

use Illuminate\Support\Facades\Http;
use PdroLucas\FilamentAiWriter\Contracts\AiProvider;

class OpenAiProvider implements AiProvider
{
  public function generate(string $systemPrompt, string $userInput): string
  {
    $config = config("filament-ai-writer.openai");
    $payload = [
      "model" => $config["model"],
      "max_completion_tokens" => (int) config("filament-ai-writer.max_tokens"),
      "messages" => [["role" => "system", "content" => $systemPrompt], ["role" => "user", "content" => $userInput]],
    ];

    $response = Http::withToken($config["api_key"])
      ->connectTimeout(5)
      ->timeout(30)
      ->post("https://api.openai.com/v1/chat/completions", $payload)
      ->throw();

    return $response->json("choices.0.message.content", "");
  }
}
