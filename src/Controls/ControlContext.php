<?php

namespace PdroLucas\FilamentAiWriter\Controls;

use InvalidArgumentException;

final class ControlContext
{
  public function __construct(
    public readonly string $panelId,
    public readonly string $guard,
    public readonly string $scope,
    public readonly ActorIdentity $actor,
  ) {
    foreach ([$panelId, $guard, $scope] as $value) {
      if ($value === '' || strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/', $value) || !mb_check_encoding($value, 'UTF-8')) {
        throw new InvalidArgumentException('Control context must contain valid nonempty strings of at most 255 bytes.');
      }
    }
  }

  public function scopeKey(): string
  {
    return hash('sha256', $this->scope);
  }

  public function snapshot(): array
  {
    return [$this->panelId, $this->guard, $this->scope, $this->actor->type, $this->actor->id];
  }
}
