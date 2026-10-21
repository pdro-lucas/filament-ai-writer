<?php

namespace PdroLucas\FilamentAiWriter\Support;

use Illuminate\Http\Client\Response;
use JsonException;
use PdroLucas\FilamentAiWriter\Data\GenerateResult;
use PdroLucas\FilamentAiWriter\Exceptions\GenerationFailure;

final class ProviderResponse
{
  public static function normalize(string $provider, string $requestedModel, Response $response, int $durationMs): GenerateResult
  {
    $requestId = self::label(match ($provider) {
      'openai' => $response->header('x-request-id'),
      'anthropic' => $response->header('request-id'),
      default => null,
    });
    try {
      $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
      throw new GenerationFailure($response->successful() ? 'invalid_response' : self::failureCode($response->status()), $response->status(), $requestId, durationMs: $durationMs, outcomeUnknown: $response->serverError());
    }
    if (!is_array($body)) {
      throw new GenerationFailure('invalid_response', $response->status(), $requestId, durationMs: $durationMs);
    }
    $usage = $provider === 'gemini' ? data_get($body, 'usageMetadata') : data_get($body, 'usage');
    $usage = is_array($usage) ? $usage : [];
    $count = fn (string $path): ?int => self::count(data_get($usage, $path));
    $cached = $count($provider === 'gemini' ? 'cachedContentTokenCount' : ($provider === 'anthropic' ? 'cache_read_input_tokens' : 'prompt_tokens_details.cached_tokens'));
    $written = $provider === 'anthropic' ? $count('cache_creation_input_tokens') : ($provider === 'openrouter' ? $count('prompt_tokens_details.cache_write_tokens') : null);
    $reasoning = $count(match ($provider) {
      'anthropic' => 'output_tokens_details.thinking_tokens', 'gemini' => 'thoughtsTokenCount', default => 'completion_tokens_details.reasoning_tokens',
    });
    if ($provider === 'anthropic') {
      $input = self::sum($count('input_tokens'), $cached, $written);
      $output = $count('output_tokens');
    } elseif ($provider === 'gemini') {
      // No tools are requested by this adapter; absent tool-use prompt usage is zero.
      $input = self::sum($count('promptTokenCount'), array_key_exists('toolUsePromptTokenCount', $usage) ? $count('toolUsePromptTokenCount') : 0);
      $total = $count('totalTokenCount');
      $candidate = $count('candidatesTokenCount');
      $parts = self::sum($candidate, $reasoning);
      $output = $total !== null && $input !== null ? ($total >= $input ? $total - $input : null) : $parts;
      if (($total !== null && $input !== null && $total < $input) || ($output !== null && $parts !== null && $output !== $parts)
        || ($output !== null && $candidate !== null && $candidate > $output)) {
        $output = null;
      }
    } else {
      $input = $count('prompt_tokens');
      $output = $count('completion_tokens');
    }
    if ($input !== null && $cached !== null && $cached > $input) {
      $cached = null;
    }
    if ($input !== null && $written !== null && ($written > $input || ($provider === 'anthropic' && $written > $input - ($cached ?? 0)))) {
      $written = null;
    }
    if ($output !== null && $reasoning !== null && $reasoning > $output) {
      $reasoning = null;
    }
    $cost = $provider === 'openrouter' ? self::costLexeme($response->body()) : null;
    $result = new GenerateResult(
      provider: $provider, requestedModel: $requestedModel,
      model: self::label(data_get($body, $provider === 'gemini' ? 'modelVersion' : 'model')),
      text: '', outcome: GenerateResult::EMPTY,
      inputTokens: $input, outputTokens: $output, cachedInputTokens: $cached, cacheWriteInputTokens: $written, reasoningOutputTokens: $reasoning,
      costUsd: $cost, costSource: $cost === null ? 'unavailable' : 'provider',
      responseId: self::label(data_get($body, $provider === 'gemini' ? 'responseId' : 'id')),
      requestId: $requestId, durationMs: $durationMs,
      finishReason: self::reason(data_get($body, match ($provider) {
        'anthropic' => 'stop_reason', 'gemini' => 'candidates.0.finishReason', default => 'choices.0.finish_reason',
      })),
    );
    if (!$response->successful() || isset($body['error']) || data_get($body, 'choices.0.error') !== null || data_get($body, 'choices.0.finish_reason') === 'error') {
      throw new GenerationFailure(self::failureCode($response->status()), $response->status(), $requestId, $result->responseId, $durationMs, $response->serverError(), $result);
    }
    try {
      [$text, $outcome, $reason] = self::text($provider, $body, $result);
    } catch (GenerationFailure $failure) {
      throw new GenerationFailure($failure->failureCode, $response->status(), $requestId, $result->responseId, $durationMs, result: $failure->result);
    }
    return new GenerateResult(...array_replace(get_object_vars($result), ['text' => $text, 'outcome' => $outcome, 'finishReason' => $reason]));
  }

  private static function text(string $provider, array $body, GenerateResult $result): array
  {
    $reason = data_get($body, match ($provider) {
      'anthropic' => 'stop_reason', 'gemini' => 'candidates.0.finishReason', default => 'choices.0.finish_reason',
    });
    $refused = match ($provider) {
      'anthropic' => $reason === 'refusal' || data_get($body, 'stop_details.type') === 'refusal',
      'gemini' => !in_array(data_get($body, 'promptFeedback.blockReason'), [null, 'BLOCK_REASON_UNSPECIFIED'], true) || in_array($reason, ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY', 'MODEL_ARMOR', 'IMAGE_PROHIBITED_CONTENT', 'ESCALATION', 'PUP_LIMITED_DISABLED'], true),
      default => $reason === 'content_filter' || data_get($body, 'choices.0.message.refusal') !== null,
    };
    if ($refused) {
      return ['', GenerateResult::REFUSED, self::reason($reason)];
    }
    $incomplete = in_array($reason, ['length', 'max_tokens', 'MAX_TOKENS', 'CONTINUATION'], true);
    $complete = in_array($reason, ['stop', 'end_turn', 'stop_sequence', 'STOP'], true);
    if (!$incomplete && !$complete) {
      self::invalid($result);
    }
    if (data_get($body, 'choices.0.message.tool_calls') || data_get($body, 'choices.0.message.function_call')) {
      self::invalid($result);
    }
    $content = data_get($body, match ($provider) {
      'anthropic' => 'content', 'gemini' => 'candidates.0.content.parts', default => 'choices.0.message.content',
    });
    if ($content === null && ($incomplete || in_array($provider, ['openai', 'openrouter'], true))) {
      $content = '';
    }
    if (is_string($content) && in_array($provider, ['openai', 'openrouter'], true)) {
      $text = $content;
    } elseif (is_array($content) && array_is_list($content) && $provider !== 'openrouter') {
      $segments = [];
      foreach ($content as $part) {
        if (!is_array($part)) {
          self::invalid($result);
        }
        if ($provider === 'gemini') {
          if (array_key_exists('thought', $part) && !is_bool($part['thought'])) {
            self::invalid($result);
          }
          if (($part['thought'] ?? false) === true) {
            continue;
          }
          if (isset($part['functionCall']) || isset($part['functionResponse'])) {
            self::invalid($result);
          }
          $isText = array_key_exists('text', $part);
        } else {
          if (($part['type'] ?? null) === 'refusal') {
            return ['', GenerateResult::REFUSED, self::reason($reason)];
          }
          if (($part['type'] ?? null) === 'tool_use') {
            self::invalid($result);
          }
          $isText = ($part['type'] ?? null) === 'text';
        }
        if ($isText) {
          if (!is_string($part['text'] ?? null)) {
            self::invalid($result);
          }
          $segments[] = $part['text'];
        }
      }
      $text = implode('', $segments);
    } else {
      self::invalid($result);
    }
    return [$incomplete || trim($text) === '' ? '' : $text, $incomplete ? GenerateResult::INCOMPLETE : (trim($text) === '' ? GenerateResult::EMPTY : GenerateResult::TEXT), self::reason($reason)];
  }

  private static function invalid(GenerateResult $result): never
  {
    throw new GenerationFailure('invalid_response', requestId: $result->requestId, responseId: $result->responseId, durationMs: $result->durationMs, result: $result);
  }

  private static function reason(mixed $value): ?string
  {
    return in_array($value, [
      'stop', 'length', 'content_filter', 'tool_calls', 'function_call', 'error',
      'end_turn', 'stop_sequence', 'refusal', 'max_tokens', 'tool_use', 'pause_turn',
      'STOP', 'MAX_TOKENS', 'CONTINUATION', 'SAFETY', 'RECITATION', 'BLOCKLIST',
      'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY', 'MODEL_ARMOR', 'IMAGE_PROHIBITED_CONTENT',
      'ESCALATION', 'PUP_LIMITED_DISABLED', 'OTHER', 'MALFORMED_FUNCTION_CALL', 'UNEXPECTED_TOOL_CALL',
    ], true) ? $value : null;
  }

  public static function label(mixed $value): ?string
  {
    return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:\/@+\-]{0,199}$/D', $value) ? $value : null;
  }

  private static function count(mixed $value): ?int
  {
    return is_int($value) && $value >= 0 ? $value : null;
  }

  private static function sum(?int ...$values): ?int
  {
    $sum = 0;
    foreach ($values as $value) {
      if ($value === null || $value > PHP_INT_MAX - $sum) {
        return null;
      }
      $sum += $value;
    }
    return $sum;
  }

  private static function failureCode(int $status): string
  {
    return match (true) {
      in_array($status, [401, 403], true) => 'authentication',
      in_array($status, [402, 429], true) => 'limited',
      $status >= 400 && $status < 500 => 'request',
      default => 'provider',
    };
  }

  /** Decode a second, lossless numeric view, then select exactly usage.cost. Strings are never scanned for keys. */
  private static function costLexeme(string $json): ?string
  {
    $lossless = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/s',
      fn (array $token): string => $token[0][0] === '"' ? $token[0] : json_encode($token[0], JSON_THROW_ON_ERROR), $json);
    $value = data_get(json_decode($lossless, true, 512, JSON_THROW_ON_ERROR), 'usage.cost');
    return is_string($value) ? Decimal::normalize($value) : null;
  }
}
