<?php

namespace PdroLucas\FilamentAiWriter\Providers;

use Illuminate\Support\Facades\Http;
use PdroLucas\FilamentAiWriter\Contracts\AiProvider;

class GeminiProvider implements AiProvider
{
  public function generate(string $systemPrompt, string $userInput): string
  {
    $config = config("filament-ai-writer.gemini");
    $connectTimeout = (int) config("filament-ai-writer.connect_timeout", 10);
    $timeout = (int) config("filament-ai-writer.timeout", 60);
    $retryTimes = (int) config("filament-ai-writer.retry_times", 2);
    $retrySleepMs = (int) config("filament-ai-writer.retry_sleep_ms", 500);

    $response = Http::connectTimeout($connectTimeout)
      ->timeout($timeout)
      ->retry($retryTimes, $retrySleepMs)
      ->post(
        "https://generativelanguage.googleapis.com/v1beta/models/{$config["model"]}:generateContent?key={$config["api_key"]}",
        [
          "system_instruction" => ["parts" => [["text" => $systemPrompt]]],
          "contents" => [["parts" => [["text" => $userInput]]]],
          "generationConfig" => [
            "maxOutputTokens" => config("filament-ai-writer.max_tokens"),
          ],
        ],
      )
      ->throw();

    return $response->json("candidates.0.content.parts.0.text", "");
  }
}
