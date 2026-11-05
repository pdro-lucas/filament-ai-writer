<?php

namespace PdroLucas\FilamentAiWriter\Support;

use Filament\Support\Facades\FilamentIcon;

/** Icon aliases the host can replace with FilamentIcon::register(['filament-ai-writer::action' => '...']). */
final class Icons
{
  public const ACTION = 'filament-ai-writer::action';
  public const INDICATOR = 'filament-ai-writer::indicator';
  public const INDICATOR_LINK = 'filament-ai-writer::indicator.link';

  private const DEFAULTS = [
    self::ACTION => 'heroicon-o-sparkles',
    self::INDICATOR => 'heroicon-m-sparkles',
    self::INDICATOR_LINK => 'heroicon-m-arrow-right',
  ];

  public static function resolve(string $alias): mixed
  {
    return FilamentIcon::resolve($alias) ?? self::DEFAULTS[$alias];
  }
}
