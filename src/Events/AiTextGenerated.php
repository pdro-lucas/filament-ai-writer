<?php

namespace PdroLucas\FilamentAiWriter\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AiTextGenerated
{
  use Dispatchable, SerializesModels;

  /**
   * @param array<string, mixed> $payload
   */
  public function __construct(public readonly array $payload) {}
}
