<?php

namespace PdroLucas\FilamentAiWriter\Data;

use InvalidArgumentException;
use PdroLucas\FilamentAiWriter\Support\Decimal;

final class GenerateResult
{
  public const TEXT = 'text';
  public const EMPTY = 'empty';
  public const REFUSED = 'refused';
  public const INCOMPLETE = 'incomplete';

  public function __construct(
    public readonly string $provider,
    public readonly ?string $requestedModel,
    public readonly ?string $model,
    public readonly string $text,
    public readonly string $outcome = self::TEXT,
    public readonly ?string $finishReason = null,
    public readonly ?int $inputTokens = null,
    public readonly ?int $outputTokens = null,
    public readonly ?int $cachedInputTokens = null,
    public readonly ?int $cacheWriteInputTokens = null,
    public readonly ?int $reasoningOutputTokens = null,
    public readonly ?string $costUsd = null,
    public readonly string $costSource = 'unavailable',
    public readonly ?string $responseId = null,
    public readonly ?string $requestId = null,
    public readonly int $durationMs = 0,
  ) {
    if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $provider)
      || !in_array($outcome, [self::TEXT, self::EMPTY, self::REFUSED, self::INCOMPLETE], true)
      || $durationMs < 0 || ($outcome === self::TEXT && trim($text) === '')
      || (in_array($outcome, [self::EMPTY, self::REFUSED], true) && $text !== '')
      || !in_array($costSource, ['provider', 'estimated', 'unavailable'], true)
      || (($costUsd === null) !== ($costSource === 'unavailable'))
      || ($costUsd !== null && Decimal::normalize($costUsd) !== $costUsd)) {
      throw new InvalidArgumentException('Invalid normalized generation result.');
    }
    foreach ([$inputTokens, $outputTokens, $cachedInputTokens, $cacheWriteInputTokens, $reasoningOutputTokens] as $tokens) {
      if ($tokens !== null && $tokens < 0) {
        throw new InvalidArgumentException('Token counts must be nonnegative or unknown.');
      }
    }
    if (($inputTokens !== null && (($cachedInputTokens ?? 0) > $inputTokens || ($cacheWriteInputTokens ?? 0) > $inputTokens || ($provider === 'anthropic' && ($cacheWriteInputTokens ?? 0) > $inputTokens - ($cachedInputTokens ?? 0))))
      || ($outputTokens !== null && ($reasoningOutputTokens ?? 0) > $outputTokens)) {
      throw new InvalidArgumentException('Token details cannot exceed their inclusive totals.');
    }
  }

  public function withoutText(): self
  {
    return new self(...array_replace(get_object_vars($this), ['text' => '', 'outcome' => self::EMPTY]));
  }
}
