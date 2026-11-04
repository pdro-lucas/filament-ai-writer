<?php

namespace PdroLucas\FilamentAiWriter\Support;

/**
 * Currency shown on cost labels and values. Amounts are never converted: the ledger stores what providers report and
 * what the host's `pricing` rates produce, so set a code other than USD only when your rates are in that currency.
 */
final class Currency
{
  public static function code(): string
  {
    $code = config('filament-ai-writer.currency.code');

    return is_string($code) && trim($code) !== '' ? strtoupper(trim($code)) : 'USD';
  }

  public static function symbol(): string
  {
    $symbol = config('filament-ai-writer.currency.symbol');
    if (is_string($symbol) && $symbol !== '') {
      return $symbol;
    }

    return self::code() === 'USD' ? '$' : self::code() . ' ';
  }

  /** @param string $amount A plain decimal string as stored, e.g. "0.001230000000". */
  public static function format(string $amount): string
  {
    return self::symbol() . rtrim(rtrim($amount, '0'), '.');
  }
}
