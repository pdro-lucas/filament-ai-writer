<?php

namespace PdroLucas\FilamentAiWriter\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use Illuminate\Support\Facades\Http;
use PdroLucas\FilamentAiWriter\Contracts\AiProvider;
use PdroLucas\FilamentAiWriter\Data\GenerateResult;
use PdroLucas\FilamentAiWriter\Exceptions\GenerationFailure;
use PdroLucas\FilamentAiWriter\Support\ProviderResponse;

abstract class HttpProvider implements AiProvider
{
  protected const PROVIDER = '';

  protected function settings(): array
  {
    $settings = config('filament-ai-writer.' . static::PROVIDER, []);
    $settings = is_array($settings) ? $settings : [];
    $model = $settings['model'] ?? null;
    if (is_string($model) && trim($model) === '') {
      $model = null;
    }
    $model ??= match (static::PROVIDER) {
      'openai' => 'gpt-4o', 'gemini' => 'gemini-3.1-flash-lite', default => null,
    };
    $key = $settings['api_key'] ?? null;
    $connect = filter_var(config('filament-ai-writer.connect_timeout'), FILTER_VALIDATE_INT);
    $timeout = filter_var(config('filament-ai-writer.timeout'), FILTER_VALIDATE_INT);
    $maxTokens = filter_var(config('filament-ai-writer.max_tokens'), FILTER_VALIDATE_INT);
    if (!is_string($model) || ProviderResponse::label($model) === null || !is_string($key) || trim($key) === ''
      || preg_match('/[\x00-\x20\x7F]/', $key) || $connect === false || $connect <= 0 || $timeout === false || $timeout <= 0 || $maxTokens === false || $maxTokens <= 0) {
      throw new GenerationFailure('configuration');
    }
    return ['model' => $model, 'api_key' => $key, 'connect_timeout' => $connect, 'timeout' => $timeout, 'max_tokens' => $maxTokens];
  }

  protected function send(array $settings, string $url, array $headers, array $payload): GenerateResult
  {
    foreach ($headers as $value) {
      if (!is_string($value) || preg_match('/[\x00-\x1F\x7F]/', $value)) {
        throw new GenerationFailure('configuration');
      }
    }
    $started = hrtime(true);
    try {
      $response = Http::withHeaders($headers)->acceptJson()
        ->connectTimeout($settings['connect_timeout'])->timeout($settings['timeout'])
        ->withOptions(['allow_redirects' => false])->post($url, $payload);
    } catch (InvalidArgumentException) {
      throw new GenerationFailure('configuration');
    } catch (RequestException $exception) {
      $response = $exception->response;
    } catch (ConnectionException) {
      $duration = self::elapsed($started);
      throw new GenerationFailure('connection', durationMs: $duration, outcomeUnknown: true, result: new GenerateResult(
        provider: static::PROVIDER, requestedModel: $settings['model'], model: null, text: '', outcome: GenerateResult::EMPTY, durationMs: $duration,
      ));
    }
    return ProviderResponse::normalize(static::PROVIDER, $settings['model'], $response, self::elapsed($started));
  }

  private static function elapsed(int $started): int
  {
    return (int) round((hrtime(true) - $started) / 1_000_000);
  }
}
