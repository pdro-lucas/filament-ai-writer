<?php

namespace PdroLucas\FilamentAiWriter\Support;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Illuminate\Validation\ValidationException;
use PdroLucas\FilamentAiWriter\Credits\Credits;

/** Shared native fields and strict boundary parsing for administrative actions. */
final class ControlFields
{
  /** @param ?string $unit Shown as a suffix ("seconds", "credits"), so the label and the hint stay free of it. */
  public static function integerField(string $name, string $label, int $min, ?string $unit = null): TextInput
  {
    abort_unless(in_array($name, ['limit', 'amount', 'rate_limit', 'rate_window_seconds', 'concurrency_limit', 'failure_limit', 'failure_window_seconds', 'failure_pause_seconds'], true), 500);
    return TextInput::make($name)->label($label)->inputMode('numeric')->suffix($unit)
      ->placeholder(__('filament-ai-writer::controls.field.' . $name . '.example'))
      ->rules([fn () => function (string $attribute, mixed $value, \Closure $fail) use ($min): void {
        try { self::parseInteger($value, $attribute, $min); }
        catch (ValidationException) { $fail(__('filament-ai-writer::controls.field.error', ['min' => $min, 'max' => number_format(Credits::MAX_CREDITS)])); }
      }]);
  }

  public static function parseInteger(mixed $value, string $field, int $min = 0): int
  {
    if ((!is_int($value) && (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D', $value)))
      || strlen((string) $value) > 10 || (int) $value < $min || (int) $value > Credits::MAX_CREDITS) {
      throw ValidationException::withMessages([$field => __('filament-ai-writer::controls.field.error', ['min' => $min, 'max' => number_format(Credits::MAX_CREDITS)])]);
    }
    return (int) $value;
  }

  /** @param string $action The audited action ('block', 'availability', 'quota_renew' ...): the plugin decides if it needs a reason. */
  /** @param string $action The audited action ('block', 'availability', 'quota_renew' ...): the plugin decides if it needs a reason. */
  public static function reasonField(string $action): Textarea
  {
    return Textarea::make('reason')->label(__('filament-ai-writer::controls.reason.label'))->required(fn (): bool => Reason::isRequired($action))
      ->maxLength(500)->rows(3)->autofocus()->placeholder(__('filament-ai-writer::controls.reason.placeholder'));
  }
}
