<?php

namespace PdroLucas\FilamentAiWriter\Providers;

use PdroLucas\FilamentAiWriter\Data\GenerateResult;

class GeminiProvider extends HttpProvider
{
  protected const PROVIDER = 'gemini';

  public function generate(string $systemPrompt, string $userInput): GenerateResult
  {
    $settings = $this->settings();
    return $this->send($settings, 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($settings['model']) . ':generateContent', [
      'x-goog-api-key' => $settings['api_key'],
    ], [
      'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
      'contents' => [['parts' => [['text' => $userInput]]]],
      'generationConfig' => ['maxOutputTokens' => $settings['max_tokens'], 'candidateCount' => 1],
    ]);
  }
}
