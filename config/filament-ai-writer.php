<?php
return [
  "tables" => ["usage" => "filament_ai_writer_usage"],
  "pricing" => [],
  "provider" => env("AI_WRITER_PROVIDER", "openai"),
  "anthropic" => [
    "api_key" => env("ANTHROPIC_API_KEY"),
    "model" => env("AI_WRITER_MODEL"),
  ],

  "openai" => [
    "api_key" => env("OPENAI_API_KEY"),
    "model" => env("AI_WRITER_MODEL", "gpt-4o"),
  ],

  "gemini" => [
    "api_key" => env("GEMINI_API_KEY"),
    "model" => env("AI_WRITER_MODEL", "gemini-3.1-flash-lite"),
  ],

  "openrouter" => [
    "api_key" => env("OPENROUTER_API_KEY"),
    "model" => env("AI_WRITER_MODEL"),
  ],

  "max_tokens" => env("AI_WRITER_MAX_TOKENS", 2048),
  "connect_timeout" => env("AI_WRITER_CONNECT_TIMEOUT", 10),
  "timeout" => env("AI_WRITER_TIMEOUT", 60),
];
