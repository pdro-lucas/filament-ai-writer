<?php

namespace PdroLucas\FilamentAiWriter\Providers;

use Illuminate\Support\Facades\Http;
use PdroLucas\FilamentAiWriter\Contracts\AiProvider;

class GeminiProvider implements AiProvider
{
  public function generate(string $systemPrompt, string $userInput): string
  {
    $config = config("filament-ai-writer.gemini");

    $response = Http::post(
      "https://generativelanguage.googleapis.com/v1beta/models/{$config["model"]}:generateContent?key={$config["api_key"]}",
      [
        "system_instruction" => ["parts" => [["text" => $systemPrompt]]],
        "contents" => [["parts" => [["text" => $userInput]]]],
        "generationConfig" => [
          "maxOutputTokens" => config("filament-ai-writer.max_tokens"),
        ],
      ],
    );

    return $response->json("candidates.0.content.parts.0.text", "");
  }
}
