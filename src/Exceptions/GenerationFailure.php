<?php

namespace PdroLucas\FilamentAiWriter\Exceptions;

use PdroLucas\FilamentAiWriter\Data\GenerateResult;
use RuntimeException;

final class GenerationFailure extends RuntimeException
{
  public const MESSAGES = [
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
    'delivery' => 'The generated result could not be applied to the form.',
  ];

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
  ) {
    $this->result = $result?->withoutText();
    $this->failureCode = array_key_exists($failureCode, self::MESSAGES) ? $failureCode : 'provider';
    parent::__construct(self::MESSAGES[$this->failureCode]);
  }
}
