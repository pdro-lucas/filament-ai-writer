<?php

namespace PdroLucas\FilamentAiWriter\Tests;

use Illuminate\Support\Facades\Http;
use PdroLucas\FilamentAiWriter\Providers\AnthropicProvider;
use PdroLucas\FilamentAiWriter\Providers\GeminiProvider;
use PdroLucas\FilamentAiWriter\Providers\OpenAiProvider;
use PdroLucas\FilamentAiWriter\Providers\OpenRouterProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Http\Client\ConnectionException;
use PdroLucas\FilamentAiWriter\Exceptions\GenerationFailure;

class ProviderContractTest extends TestCase
{
  protected function setUp(): void
  {
    parent::setUp();
    foreach (['openai', 'anthropic', 'gemini', 'openrouter'] as $provider) {
      config(["filament-ai-writer.$provider.model" => 'requested-model', "filament-ai-writer.$provider.api_key" => 'test-key']);
    }
  }

  #[DataProvider('usageResponses')]
  public function test_normalizes_usage_without_double_counting(string $adapter, array $body, int $input, int $output, ?int $cached, ?int $written, ?int $reasoning): void
  {
    foreach (['openai', 'anthropic', 'gemini', 'openrouter'] as $provider) {
      config(["filament-ai-writer.$provider.model" => 'requested-model', "filament-ai-writer.$provider.api_key" => 'test-key']);
    }
    config(['filament-ai-writer.connect_timeout' => 7, 'filament-ai-writer.timeout' => 23]);
    Http::fake(function ($request, $options) use ($adapter, $body) {
      $this->assertSame(7, $options['connect_timeout']);
      $this->assertSame(23, $options['timeout']);
      $this->assertFalse($options['allow_redirects']);
      if ($adapter === AnthropicProvider::class) {
        $this->assertTrue($request->hasHeader('x-api-key', 'test-key'));
        $this->assertTrue($request->hasHeader('anthropic-version', '2023-06-01'));
        $this->assertFalse($request->hasHeader('Authorization'));
      } elseif ($adapter === GeminiProvider::class) {
        $this->assertTrue($request->hasHeader('x-goog-api-key', 'test-key'));
        $this->assertStringNotContainsString('?', $request->url());
      } else {
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer test-key'));
      }
      return Http::response($body, 200, ['x-request-id' => 'openai-request', 'request-id' => 'anthropic-request']);
    });

    $result = (new $adapter())->generate('Private system prompt', 'Private input');

    $this->assertSame('Hello world', $result->text);
    $this->assertSame('text', $result->outcome);
    $this->assertSame('requested-model', $result->requestedModel);
    $this->assertSame('returned-model', $result->model);
    $this->assertSame([$input, $output, $cached, $written, $reasoning], [
      $result->inputTokens, $result->outputTokens, $result->cachedInputTokens,
      $result->cacheWriteInputTokens, $result->reasoningOutputTokens,
    ]);
    $this->assertGreaterThanOrEqual(0, $result->durationMs);
    Http::assertSentCount(1);
  }

  #[DataProvider('unusableResponses')]
  public function test_non_deliverable_responses_retain_usage_without_exposing_private_content(string $adapter, array $body, string $outcome): void
  {
    config(['filament-ai-writer.anthropic.model' => 'requested-model', 'filament-ai-writer.anthropic.api_key' => 'test-key']);
    Http::fake(['*' => Http::response($body)]);
    $result = (new $adapter())->generate('Private prompt', 'Private input');
    $this->assertSame($outcome, $result->outcome);
    $this->assertSame('', $result->text);
    $this->assertSame(40, $result->outputTokens);
  }

  #[DataProvider('failedRequests')]
  public function test_failures_are_sanitized_and_never_retried(string $kind, int $status, bool $unknown): void
  {
    config(['filament-ai-writer.openrouter.model' => 'requested-model', 'filament-ai-writer.openrouter.api_key' => 'test-key']);
    $attempts = 0;
    Http::fake(function () use ($kind, $status, &$attempts) {
      $attempts++;
      if ($kind === 'connection') {
        throw new ConnectionException('PRIVATE prompt and key');
      }
      return Http::response($kind === 'json' ? 'PRIVATE invalid JSON' : [
        'error' => ['message' => 'PRIVATE prompt and key'], 'usage' => ['completion_tokens' => 40],
      ], $status);
    });
    try {
      (new OpenRouterProvider())->generate('Private prompt', 'Private input');
      $this->fail('Expected a sanitized provider failure.');
    } catch (GenerationFailure $failure) {
      $this->assertStringNotContainsString('PRIVATE', $failure->getMessage());
      $this->assertNull($failure->getPrevious());
      $this->assertSame($unknown, $failure->outcomeUnknown);
      if ($kind === 'http' || $kind === 'body') {
        $this->assertSame(40, $failure->result->outputTokens);
        $this->assertSame('', $failure->result->text);
      }
    }
    $this->assertSame(1, $attempts);
  }

  public function test_exact_router_cost_uses_only_the_usage_path_and_unknown_tokens_stay_null(): void
  {
    config(['filament-ai-writer.openrouter.model' => 'requested-model', 'filament-ai-writer.openrouter.api_key' => 'test-key']);
    Http::fake(['*' => Http::response('{"cost":9,"choices":[{"message":{"content":"misleading \"cost\": 7"},"finish_reason":"stop"}],"usage":{"cost_details":{"cost":5},"cost":1.000000000005e-1,"prompt_tokens":-1,"completion_tokens":40,"completion_tokens_details":{"reasoning_tokens":41}}}')]);
    $result = (new OpenRouterProvider())->generate('Private prompt', 'Private input');
    $this->assertSame('0.100000000001', $result->costUsd);
    $this->assertSame('provider', $result->costSource);
    $this->assertNull($result->inputTokens);
    $this->assertNull($result->reasoningOutputTokens);
    $this->assertSame(40, $result->outputTokens);
  }

  public function test_inconsistent_gemini_totals_and_missing_anthropic_components_remain_unknown(): void
  {
    Http::fakeSequence()->push([
      'candidates' => [['content' => ['parts' => [['text' => 'Hello']]], 'finishReason' => 'STOP']],
      'usageMetadata' => ['promptTokenCount' => 100, 'totalTokenCount' => 90, 'candidatesTokenCount' => 10, 'thoughtsTokenCount' => 5],
    ])->push(['content' => [['type' => 'text', 'text' => 'Hello']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]);
    $result = (new GeminiProvider())->generate('Private prompt', 'Private input');
    $this->assertSame(100, $result->inputTokens);
    $this->assertNull($result->outputTokens);
    $this->assertNull($result->costUsd);
    config(['filament-ai-writer.anthropic.model' => 'requested-model', 'filament-ai-writer.anthropic.api_key' => 'test-key']);
    $this->assertNull((new AnthropicProvider())->generate('Private prompt', 'Private input')->inputTokens);
  }

  #[DataProvider('invalidSettings')]
  public function test_invalid_configuration_is_rejected_without_a_remote_attempt(array $settings): void
  {
    config($settings);
    Http::fake();
    try {
      app(\PdroLucas\FilamentAiWriter\Contracts\AiProvider::class)->generate('Private prompt', 'Private input');
      $this->fail('Expected a configuration failure.');
    } catch (GenerationFailure $failure) {
      $this->assertSame('configuration', $failure->failureCode);
      $this->assertNull($failure->getPrevious());
      $this->assertStringNotContainsString('PRIVATE', $failure->getMessage());
    }
    Http::assertNothingSent();
  }

  #[DataProvider('blankModels')]
  public function test_blank_defaultable_models_use_the_documented_package_default(string $adapter, string $provider, string $default): void
  {
    config(["filament-ai-writer.$provider.model" => '   ']);
    $body = self::usageResponses()[$provider][1];
    Http::fake(['*' => Http::response($body)]);
    $this->assertSame($default, (new $adapter())->generate('Private prompt', 'Private input')->requestedModel);
  }

  public static function invalidSettings(): array
  {
    return [
      'unknown provider' => [['filament-ai-writer.provider' => 'not-a-provider']],
      'blank anthropic model' => [['filament-ai-writer.provider' => 'anthropic', 'filament-ai-writer.anthropic.model' => ' ']],
      'blank router model' => [['filament-ai-writer.provider' => 'openrouter', 'filament-ai-writer.openrouter.model' => null]],
      'forbidden API key byte' => [['filament-ai-writer.openai.api_key' => "PRIVATE\0key"]],
      'forbidden attribution byte' => [['filament-ai-writer.provider' => 'openrouter', 'app.name' => "PRIVATE\1name"]],
      'invalid timeout' => [['filament-ai-writer.timeout' => 0]],
    ];
  }

  public static function blankModels(): array
  {
    return ['openai' => [OpenAiProvider::class, 'openai', 'gpt-4o'], 'gemini' => [GeminiProvider::class, 'gemini', 'gemini-3.1-flash-lite']];
  }

  #[DataProvider('invalidResponses')]
  public function test_invalid_shapes_preserve_metadata_in_a_sanitized_failure(string $adapter, array $body): void
  {
    Http::fake(['*' => Http::response($body)]);
    try {
      (new $adapter())->generate('Private prompt', 'Private input');
      $this->fail('Expected an invalid response failure.');
    } catch (GenerationFailure $failure) {
      $this->assertSame('invalid_response', $failure->failureCode);
      $this->assertSame(40, $failure->result->outputTokens);
      $this->assertSame('', $failure->result->text);
    }
  }

  public static function invalidResponses(): array
  {
    return [
      'router array content' => [OpenRouterProvider::class, ['choices' => [['message' => ['content' => [['type' => 'text', 'text' => 'PRIVATE']]], 'finish_reason' => 'stop']], 'usage' => ['completion_tokens' => 40]]],
      'unexpected tool' => [AnthropicProvider::class, ['content' => [['type' => 'tool_use']], 'stop_reason' => 'tool_use', 'usage' => ['output_tokens' => 40]]],
      'malformed thought marker' => [GeminiProvider::class, ['candidates' => [['content' => ['parts' => [['text' => 'PRIVATE', 'thought' => 'true']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 100, 'totalTokenCount' => 140]]],
    ];
  }

  public static function unusableResponses(): array
  {
    return [
      'empty' => [OpenAiProvider::class, ['choices' => [['message' => ['content' => '  '], 'finish_reason' => 'stop']], 'usage' => ['completion_tokens' => 40]], 'empty'],
      'truncated' => [OpenAiProvider::class, ['choices' => [['message' => ['content' => 'PRIVATE partial'], 'finish_reason' => 'length']], 'usage' => ['completion_tokens' => 40]], 'incomplete'],
      'refused' => [AnthropicProvider::class, ['content' => [['type' => 'text', 'text' => 'PRIVATE refusal']], 'stop_reason' => 'end_turn', 'stop_details' => ['type' => 'refusal'], 'usage' => ['output_tokens' => 40]], 'refused'],
      'gemini continuation' => [GeminiProvider::class, ['candidates' => [['content' => ['parts' => [['text' => 'PRIVATE partial']]], 'finishReason' => 'CONTINUATION']], 'usageMetadata' => ['promptTokenCount' => 100, 'totalTokenCount' => 140]], 'incomplete'],
    ];
  }

  public static function failedRequests(): array
  {
    return [
      'connection' => ['connection', 0, true], 'quota' => ['http', 429, false],
      'authentication' => ['http', 401, false], 'validation' => ['http', 400, false],
      'server' => ['http', 503, true], 'error in HTTP 200' => ['body', 200, false], 'malformed JSON' => ['json', 200, false],
    ];
  }

  public static function usageResponses(): array
  {
    $chat = ['id' => 'response-id', 'model' => 'returned-model', 'choices' => [[
      'message' => ['content' => 'Hello world'], 'finish_reason' => 'stop',
    ]], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 40,
      'prompt_tokens_details' => ['cached_tokens' => 20], 'completion_tokens_details' => ['reasoning_tokens' => 15]]];
    return [
      'openai' => [OpenAiProvider::class, $chat, 100, 40, 20, null, 15],
      'openrouter' => [OpenRouterProvider::class, $chat, 100, 40, 20, null, 15],
      'anthropic' => [AnthropicProvider::class, ['id' => 'response-id', 'model' => 'returned-model',
        'content' => [['type' => 'text', 'text' => 'Hello '], ['type' => 'thinking', 'thinking' => 'PRIVATE'], ['type' => 'text', 'text' => 'world']],
        'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'cache_read_input_tokens' => 80, 'cache_creation_input_tokens' => 20, 'output_tokens' => 30,
          'output_tokens_details' => ['thinking_tokens' => 12]]], 110, 30, 80, 20, 12],
      'gemini' => [GeminiProvider::class, ['responseId' => 'response-id', 'modelVersion' => 'returned-model',
        'candidates' => [['content' => ['parts' => [['text' => 'Hello '], ['text' => 'PRIVATE', 'thought' => true], ['text' => 'world']]], 'finishReason' => 'STOP']],
        'usageMetadata' => ['promptTokenCount' => 100, 'cachedContentTokenCount' => 20, 'candidatesTokenCount' => 15, 'thoughtsTokenCount' => 25, 'totalTokenCount' => 140]], 100, 40, 20, null, 25],
    ];
  }
}
