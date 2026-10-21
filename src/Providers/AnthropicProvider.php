<?php

namespace PdroLucas\FilamentAiWriter\Providers;

use PdroLucas\FilamentAiWriter\Data\GenerateResult;

class AnthropicProvider extends HttpProvider
{
  protected const PROVIDER = 'anthropic';

  public function generate(string $systemPrompt, string $userInput): GenerateResult
  {
    $settings = $this->settings();
    return $this->send($settings, 'https://api.anthropic.com/v1/messages', [
      'x-api-key' => $settings['api_key'], 'anthropic-version' => '2023-06-01',
    ], [
      'model' => $settings['model'], 'max_tokens' => $settings['max_tokens'], 'system' => $systemPrompt,
      'messages' => [['role' => 'user', 'content' => $userInput]],
    ]);
  }
}
