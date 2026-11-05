<?php

namespace PdroLucas\FilamentAiWriter\Providers;

use PdroLucas\FilamentAiWriter\Data\GenerateResult;

class OpenAiProvider extends HttpProvider
{
  protected const PROVIDER = 'openai';

  public function generate(string $systemPrompt, string $userInput): GenerateResult
  {
    $settings = $this->settings();
    $headers = ['Authorization' => 'Bearer ' . $settings['api_key']];
    return $this->send($settings, 'https://api.openai.com/v1/chat/completions', $headers, [
      'model' => $settings['model'],
      'max_completion_tokens' => $settings['max_tokens'],
      'messages' => [['role' => 'system', 'content' => $systemPrompt], ['role' => 'user', 'content' => $userInput]],
    ]);
  }
}
