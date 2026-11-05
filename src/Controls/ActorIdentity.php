<?php

namespace PdroLucas\FilamentAiWriter\Controls;

use InvalidArgumentException;

final class ActorIdentity
{
  public readonly string $id;

  public function __construct(public readonly string $type, string|int $id, public readonly string $label = '')
  {
    $this->id = (string) $id;
    foreach ([$type, $this->id] as $value) {
      if ($value === '' || strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/', $value) || !mb_check_encoding($value, 'UTF-8')) {
        throw new InvalidArgumentException('Actor identity must contain valid nonempty strings of at most 255 bytes.');
      }
    }
    if (strlen($label) > 255 || !mb_check_encoding($label, 'UTF-8')) {
      throw new InvalidArgumentException('Actor label must be valid UTF-8 of at most 255 bytes.');
    }
  }

  public function key(): string
  {
    return hash('sha256', json_encode([$this->type, $this->id], JSON_THROW_ON_ERROR));
  }

  public function token(): string
  {
    return base64_encode(json_encode([$this->type, $this->id], JSON_THROW_ON_ERROR));
  }

  public static function fromToken(string $token): self
  {
    if (strlen($token) > 3000) {
      throw new InvalidArgumentException('Invalid actor selection.');
    }
    $tuple = json_decode(base64_decode($token, true) ?: '', true);
    if (!is_array($tuple) || !array_is_list($tuple) || count($tuple) !== 2 || !is_string($tuple[0]) || !is_string($tuple[1])) {
      throw new InvalidArgumentException('Invalid actor selection.');
    }
    return new self($tuple[0], $tuple[1]);
  }
}
