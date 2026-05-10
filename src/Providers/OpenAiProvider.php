<?php

namespace PdroLucas\FilamentAiWriter\Providers;

use Illuminate\Support\Facades\Http;
use PdroLucas\FilamentAiWriter\Contracts\AiProvider;

class OpenAiProvider implements AiProvider
{
  public function generate(string $systemPrompt, string $userInput): string
  {
    $config = config("filament-ai-writer.openai");

    $response = Http::withToken($config["api_key"])->post("https://api.openai.com/v1/chat/completions", [
      "model" => $config["model"],
      "max_tokens" => config("filament-ai-writer.max_tokens"),
      "messages" => [["role" => "system", "content" => $systemPrompt], ["role" => "user", "content" => $userInput]],
    ]);

    return $response->json("choices.0.message.content", "");
  }
}
