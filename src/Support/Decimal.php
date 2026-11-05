<?php

namespace PdroLucas\FilamentAiWriter\Support;

final class Decimal
{
  /** Normalize USD to twelve integer and twelve fractional digits, rounding half-up without a float conversion. */
  public static function normalize(string $value): ?string
  {
    if (strlen($value) > 128 || !preg_match('/^(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/D', $value, $parts)) {
      return null;
    }
    $exponent = $parts[3] ?? '0';
    if (strlen(ltrim($exponent, '+-0')) > 3 || abs((int) $exponent) > 200) {
      return null;
    }
    $digits = $parts[1] . ($parts[2] ?? '');
    $point = strlen($parts[1]) + (int) $exponent;
    $integer = $point > 0 ? substr(str_pad($digits, $point, '0'), 0, $point) : '0';
    $fraction = $point >= 0 ? substr($digits, $point) : str_repeat('0', -$point) . $digits;
    $integer = ltrim($integer, '0') ?: '0';
    if (strlen($integer) > 12) {
      return null;
    }
    $fraction = str_pad($fraction, 13, '0');
    $scaled = ltrim($integer . substr($fraction, 0, 12), '0') ?: '0';
    if ($fraction[12] >= '5') {
      $scaled = self::addDigits($scaled, '1');
    }
    return self::format($scaled);
  }

  public static function add(string $left, string $right): ?string
  {
    $left = self::normalize($left);
    $right = self::normalize($right);
    return $left === null || $right === null ? null : self::format(self::addDigits(str_replace('.', '', $left), str_replace('.', '', $right)));
  }

  /** Add canonical USD subtotals without imposing the per-attempt amount limit. */
  public static function sum(string $left, string $right): string
  {
    if (!preg_match('/^\d+\.\d{12}$/D', $left) || !preg_match('/^\d+\.\d{12}$/D', $right)) {
      throw new \InvalidArgumentException('Subtotals must be canonical decimal strings.');
    }
    $scaled = self::addDigits(str_replace('.', '', $left), str_replace('.', '', $right));
    $scaled = str_pad($scaled, 13, '0', STR_PAD_LEFT);
    return substr($scaled, 0, -12) . '.' . substr($scaled, -12);
  }

  public static function addIntegers(string $left, string $right): string
  {
    if (!ctype_digit($left) || !ctype_digit($right)) {
      throw new \InvalidArgumentException('Totals must be unsigned integer strings.');
    }
    return self::addDigits($left, $right);
  }

  public static function estimate(int $input, int $output, string $inputRate, string $outputRate): ?string
  {
    if ($input < 0 || $output < 0 || !preg_match('/^\d+(?:\.\d{1,12})?$/D', $inputRate) || !preg_match('/^\d+(?:\.\d{1,12})?$/D', $outputRate)) {
      return null;
    }
    $inputRate = self::normalize($inputRate);
    $outputRate = self::normalize($outputRate);
    if ($inputRate === null || $outputRate === null) {
      return null;
    }
    $numerator = self::addDigits(
      self::multiplyDigits(str_replace('.', '', $inputRate), (string) $input),
      self::multiplyDigits(str_replace('.', '', $outputRate), (string) $output),
    );
    $numerator = str_pad($numerator, 7, '0', STR_PAD_LEFT);
    $scaled = substr($numerator, 0, -6);
    if ($numerator[strlen($numerator) - 6] >= '5') {
      $scaled = self::addDigits($scaled, '1');
    }
    return self::format($scaled);
  }

  private static function format(string $scaled): ?string
  {
    $scaled = ltrim($scaled, '0') ?: '0';
    if (strlen($scaled) > 24) {
      return null;
    }
    $scaled = str_pad($scaled, 13, '0', STR_PAD_LEFT);
    return substr($scaled, 0, -12) . '.' . substr($scaled, -12);
  }

  private static function addDigits(string $left, string $right): string
  {
    $sum = '';
    $carry = 0;
    for ($i = strlen($left) - 1, $j = strlen($right) - 1; $i >= 0 || $j >= 0 || $carry; $i--, $j--) {
      $digit = ($i >= 0 ? (int) $left[$i] : 0) + ($j >= 0 ? (int) $right[$j] : 0) + $carry;
      $sum = ($digit % 10) . $sum;
      $carry = intdiv($digit, 10);
    }
    return ltrim($sum, '0') ?: '0';
  }

  private static function multiplyDigits(string $left, string $right): string
  {
    $sum = '0';
    foreach (str_split($right) as $digit) {
      $sum .= '0';
      for ($i = 0; $i < (int) $digit; $i++) {
        $sum = self::addDigits($sum, $left);
      }
    }
    return ltrim($sum, '0') ?: '0';
  }
}
