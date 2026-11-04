<?php

namespace PdroLucas\FilamentAiWriter\Exceptions;

use Carbon\CarbonInterface;
use PdroLucas\FilamentAiWriter\Data\GenerateResult;
use PdroLucas\FilamentAiWriter\Support\HostDates;
use RuntimeException;

final class GenerationFailure extends RuntimeException
{
  public const MESSAGES = [
    'disabled' => 'AI is disabled for this scope. Contact your administrator.',
    'expired' => 'This AI operation expired. Its result cannot be delivered; any reserved credit was released.',
    'rate' => 'Your AI request frequency limit has been reached. Try again after the request window moves.',
    'concurrency' => 'You already have the maximum number of AI generations in progress. Try again when one finishes.',
    'failure_pause' => 'AI generation is temporarily paused after repeated failures. Try again after the pause ends.',
    'credits' => 'Your AI credits are exhausted. Contact your administrator.',
    'blocked' => 'Your AI access is blocked. Contact your administrator.',
    'controls' => 'AI access controls are unavailable. Contact your administrator; publish and run the plugin migrations before generating.',
    'configuration' => 'AI Writer configuration is invalid. Check the provider, model, key and timeout settings.',
    'connection' => 'The provider response could not be confirmed. No automatic retry was made.',
    'authentication' => 'The provider rejected authentication. Contact your administrator.',
    'limited' => 'The provider is currently unavailable because of a usage or rate limit.',
    'request' => 'The provider rejected the request.',
    'provider' => 'The provider could not complete the request.',
    'invalid_response' => 'The provider returned an unsupported or invalid response.',
    'empty' => 'The provider returned no usable text. The original field was preserved.',
    'refused' => 'The provider declined the request. The original field was preserved.',
    'incomplete' => 'The generated text was incomplete. The original field was preserved.',
    'invalid_output' => 'The generated result was not valid for this field. The original field was preserved.',
    'transaction' => 'AI generation must run outside an existing database transaction so usage admission is durable.',
    'storage' => 'AI Writer storage is unavailable. Publish the filament-ai-writer-migrations and run migrations before generating. No unrecorded result was delivered.',
    'duplicate' => 'This operation was already admitted. No new provider request was made.',
    'delivery' => 'The generated result could not be applied to the form.',
  ];

  /** Denials and rejected output the person can act on. Every other code is a technical failure kept out of their sight. */
  public const SPECIFIC = ['disabled', 'expired', 'rate', 'concurrency', 'failure_pause', 'credits', 'blocked', 'duplicate', 'empty', 'refused', 'incomplete', 'invalid_output', 'delivery'];

  public readonly string $failureCode;
  public readonly ?GenerateResult $result;

  public function __construct(
    string $failureCode,
    public readonly ?int $httpStatus = null,
    public readonly ?string $requestId = null,
    public readonly ?string $responseId = null,
    public readonly int $durationMs = 0,
    public readonly bool $outcomeUnknown = false,
    ?GenerateResult $result = null,
    public readonly ?CarbonInterface $renewsAt = null,
  ) {
    $this->result = $result?->withoutText();
    $this->failureCode = array_key_exists($failureCode, self::MESSAGES) ? $failureCode : 'provider';
    parent::__construct(self::MESSAGES[$this->failureCode]);
  }

  public function isTechnical(): bool
  {
    return !in_array($this->failureCode, self::SPECIFIC, true);
  }

  /** Full, translated explanation: shown as is for actionable denials and only to managers for technical failures. */
  public function detail(): string
  {
    if ($this->failureCode === 'credits' && $this->renewsAt !== null) {
      return __('filament-ai-writer::failures.credits_renew', ['date' => HostDates::dateTime($this->renewsAt)]);
    }

    return __('filament-ai-writer::failures.messages.' . $this->failureCode);
  }
}
