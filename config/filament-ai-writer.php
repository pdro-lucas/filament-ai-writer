<?php
return [
  "provider" => env("AI_WRITER_PROVIDER", "openai"),
  "anthropic" => [
    "api_key" => env("ANTHROPIC_API_KEY"),
    "model" => env("AI_WRITER_MODEL", "claude-sonnet-4-20250514"),
  ],

  "openai" => [
    "api_key" => env("OPENAI_API_KEY"),
    "model" => env("AI_WRITER_MODEL", "gpt-4o"),
  ],

  "gemini" => [
    "api_key" => env("GEMINI_API_KEY"),
    "model" => env("AI_WRITER_MODEL", "gemini-3.1-flash-lite"),
  ],

  "max_tokens" => env("AI_WRITER_MAX_TOKENS", 2048),
];
