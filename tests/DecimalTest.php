<?php

namespace PdroLucas\FilamentAiWriter\Tests;

use PdroLucas\FilamentAiWriter\Support\Decimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DecimalTest extends TestCase
{
  #[DataProvider('amounts')]
  public function test_normalizes_decimal_lexemes_without_float_rounding(string $value, ?string $expected): void
  {
    $this->assertSame($expected, Decimal::normalize($value));
  }

  public function test_addition_and_estimation_use_exact_digit_arithmetic(): void
  {
    $this->assertSame('0.300000000003', Decimal::add('0.100000000001', '0.200000000002'));
    $this->assertSame('20000000.000000000002', Decimal::add('10000000.000000000001', '10000000.000000000001'));
    $this->assertSame('0.004000000000', Decimal::estimate(1000, 200, '2', '10'));
    $this->assertSame('0.000000000001', Decimal::estimate(1, 0, '0.0000005', '0'));
    $this->assertSame('0.000000000000', Decimal::estimate(1, 0, '0.000000499999', '0'));
    $this->assertNull(Decimal::estimate(1, 1, '0.0000000000001', '1'));
    $this->assertNull(Decimal::add('999999999999.999999999999', '0.000000000001'));
  }

  public static function amounts(): array
  {
    return [
      'numeric exponent' => ['1.000000000005e-1', '0.100000000001'],
      'known zero' => ['0', '0.000000000000'],
      'half-up' => ['0.0000000000005', '0.000000000001'],
      'below half' => ['0.00000000000049', '0.000000000000'],
      'carry' => ['0.9999999999995', '1.000000000000'],
      'out of range' => ['1000000000000', null],
      'negative' => ['-0.01', null],
    ];
  }
}
