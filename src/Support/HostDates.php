<?php

namespace PdroLucas\FilamentAiWriter\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;

/** Dates written as text follow the host's Filament display format and timezone (also per user); nothing is fixed here. */
final class HostDates
{
  /** @param CarbonInterface|string $value A UTC instant, as stored. */
  public static function dateTime(CarbonInterface|string $value): string
  {
    $date = $value instanceof CarbonInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value, 'UTC');

    return $date->setTimezone(FilamentTimezone::get())->translatedFormat(Schema::make()->getDefaultDateTimeDisplayFormat());
  }

  /** @param string $value A calendar day (Y-m-d) chosen in the host's timezone. */
  public static function date(string $value): string
  {
    $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, FilamentTimezone::get());

    return $date === false ? $value : $date->translatedFormat(Schema::make()->getDefaultDateDisplayFormat());
  }

  public static function timezone(): string
  {
    return FilamentTimezone::get();
  }
}
