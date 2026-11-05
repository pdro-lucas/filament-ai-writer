<?php

namespace PdroLucas\FilamentAiWriter\Tests;

use PdroLucas\FilamentAiWriter\Data\GenerateResult;
use PdroLucas\FilamentAiWriter\Usage\CostCalculator;
use PHPUnit\Framework\Attributes\DataProvider;

class CostCalculatorTest extends TestCase
{
  #[DataProvider('quotes')]
  public function test_quotes_only_complete_explicit_matching_tariffs(array $changes, array $rates, ?string $expected, string $source): void
  {
    config(['filament-ai-writer.pricing' => ['custom' => $rates]]);
    $result = new GenerateResult(...array_replace([
      'provider' => 'custom', 'requestedModel' => 'requested-alias', 'model' => 'vendor/model.v1', 'text' => 'text',
      'inputTokens' => 1000, 'outputTokens' => 200, 'cachedInputTokens' => 300, 'reasoningOutputTokens' => 50,
    ], $changes));
    $quote = app(CostCalculator::class)->quote($result);
    $this->assertSame($expected, $quote['result']->costUsd);
    $this->assertSame($source, $quote['result']->costSource);
    $this->assertSame($result->text, $quote['result']->text);
    $this->assertSame($source === 'estimated' ? '2.000000000000' : null, $quote['input_rate']);
  }

  public static function quotes(): array
  {
    $tariff = ['input_usd_per_million' => '2', 'output_usd_per_million' => '10'];
    $rates = ['vendor/model.v1' => $tariff];
    return [
      'inclusive totals and literal dotted model' => [[], $rates, '0.004000000000', 'estimated'],
      'known provider zero wins' => [['costUsd' => '0.000000000000', 'costSource' => 'provider'], $rates, '0.000000000000', 'provider'],
      'unknown input' => [['inputTokens' => null], $rates, null, 'unavailable'],
      'unknown output' => [['outputTokens' => null], $rates, null, 'unavailable'],
      'absent returned model' => [['model' => null], $rates, null, 'unavailable'],
      'requested alias does not substitute' => [[], ['requested-alias' => $tariff], null, 'unavailable'],
      'float tariff rejected' => [[], ['vendor/model.v1' => [...$tariff, 'input_usd_per_million' => 2.0]], null, 'unavailable'],
      'missing output tariff' => [[], ['vendor/model.v1' => ['input_usd_per_million' => '2']], null, 'unavailable'],
      'excess precision rejected' => [[], ['vendor/model.v1' => [...$tariff, 'input_usd_per_million' => '0.0000000000001']], null, 'unavailable'],
    ];
  }
}
