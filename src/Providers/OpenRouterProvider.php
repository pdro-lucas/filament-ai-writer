<?php

namespace PdroLucas\FilamentAiWriter\Providers;

use PdroLucas\FilamentAiWriter\Data\GenerateResult;

class OpenRouterProvider extends HttpProvider
{
  protected const PROVIDER = 'openrouter';

  public function generate(string $systemPrompt, string $userInput): GenerateResult
  {
    $settings = $this->settings();
    $headers = ['Authorization' => 'Bearer ' . $settings['api_key']];
    $headers += array_filter(['HTTP-Referer' => config('app.url'), 'X-Title' => config('app.name')]);
    return $this->send($settings, 'https://openrouter.ai/api/v1/chat/completions', $headers, [
      'model' => $settings['model'],
      'max_tokens' => $settings['max_tokens'],
      'messages' => [['role' => 'system', 'content' => $systemPrompt], ['role' => 'user', 'content' => $userInput]],
    ]);
  }
}
