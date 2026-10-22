<?php

namespace PdroLucas\FilamentAiWriter\Usage;

use PdroLucas\FilamentAiWriter\Data\GenerateResult;
use PdroLucas\FilamentAiWriter\Support\Decimal;

class CostCalculator
{
  /** @return array{result: GenerateResult, input_rate: ?string, output_rate: ?string} */
  public function quote(GenerateResult $result): array
  {
    $quote = ['result' => $result, 'input_rate' => null, 'output_rate' => null];
    if ($result->costUsd !== null || $result->model === null || $result->inputTokens === null || $result->outputTokens === null) {
      return $quote;
    }
    $pricing = config('filament-ai-writer.pricing', []);
    $rates = is_array($pricing) && is_array($pricing[$result->provider] ?? null) ? ($pricing[$result->provider][$result->model] ?? null) : null;
    if (!is_array($rates) || !is_string($rates['input_usd_per_million'] ?? null) || !is_string($rates['output_usd_per_million'] ?? null)) {
      return $quote;
    }
    $cost = Decimal::estimate($result->inputTokens, $result->outputTokens, $rates['input_usd_per_million'], $rates['output_usd_per_million']);
    if ($cost === null) {
      return $quote;
    }
    return [
      'result' => new GenerateResult(...array_replace(get_object_vars($result), ['costUsd' => $cost, 'costSource' => 'estimated'])),
      'input_rate' => Decimal::normalize($rates['input_usd_per_million']),
      'output_rate' => Decimal::normalize($rates['output_usd_per_million']),
    ];
  }
}
