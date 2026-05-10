<?php

namespace PdroLucas\FilamentAiWriter\Providers;

use Illuminate\Support\Facades\Http;
use PdroLucas\FilamentAiWriter\Contracts\AiProvider;

class OpenAiProvider implements AiProvider
{
  public function generate(string $systemPrompt, string $userInput): string
  {
    $config = config("filament-ai-writer.openai");
    $connectTimeout = (int) config("filament-ai-writer.connect_timeout", 10);
    $timeout = (int) config("filament-ai-writer.timeout", 60);
    $retryTimes = (int) config("filament-ai-writer.retry_times", 2);
    $retrySleepMs = (int) config("filament-ai-writer.retry_sleep_ms", 500);

    $response = Http::withToken($config["api_key"])
      ->connectTimeout($connectTimeout)
      ->timeout($timeout)
      ->retry($retryTimes, $retrySleepMs)
      ->post("https://api.openai.com/v1/chat/completions", [
        "model" => $config["model"],
        "max_tokens" => config("filament-ai-writer.max_tokens"),
        "messages" => [["role" => "system", "content" => $systemPrompt], ["role" => "user", "content" => $userInput]],
      ])
      ->throw();

    return $response->json("choices.0.message.content", "");
  }
}
