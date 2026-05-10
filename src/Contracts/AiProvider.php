<?php

namespace PdroLucas\FilamentAiWriter\Contracts;

interface AiProvider
{
  public function generate(string $systemPrompt, string $userInput): string;
}
