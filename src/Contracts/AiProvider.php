<?php

namespace PdroLucas\FilamentAiWriter\Contracts;

use PdroLucas\FilamentAiWriter\Data\GenerateResult;

interface AiProvider
{
  public function generate(string $systemPrompt, string $userInput): GenerateResult;
}
