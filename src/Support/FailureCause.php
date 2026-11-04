<?php

namespace PdroLucas\FilamentAiWriter\Support;

use PdroLucas\FilamentAiWriter\Exceptions\GenerationFailure;
use Throwable;
use WeakMap;

/** Keeps the original cause next to a GenerationFailure without chaining it: getPrevious() stays null by contract. */
final class FailureCause
{
  private static ?WeakMap $causes = null;

  public static function remember(GenerationFailure $failure, Throwable $cause): GenerationFailure
  {
    self::$causes ??= new WeakMap();
    self::$causes[$failure] = $cause;

    return $failure;
  }

  public static function transfer(GenerationFailure $from, GenerationFailure $to): GenerationFailure
  {
    $cause = self::of($from);

    return $cause === null ? $to : self::remember($to, $cause);
  }

  public static function of(GenerationFailure $failure): ?Throwable
  {
    return self::$causes === null ? null : (self::$causes[$failure] ?? null);
  }
}
