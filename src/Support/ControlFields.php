<?php

namespace PdroLucas\FilamentAiWriter\Support;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Illuminate\Validation\ValidationException;
use PdroLucas\FilamentAiWriter\Credits\Credits;

/** Shared native fields and strict boundary parsing for administrative actions. */
final class ControlFields
{
  public static function integerField(string $name, string $label, int $min): TextInput
  {
    [$example, $help, $details] = match ($name) {
      'limit' => ['e.g. 100', 'Whole credits. A limit change preserves consumption and reservations.', 'Each delivered generation uses one credit. Zero allows no new quota-backed generations. An individual limit overrides the workspace default; restoring the default is a separate action.'],
      'amount' => ['e.g. 10', 'Extra credits apply only to the current period.', 'Grant additional credits without changing the user’s limit or clearing consumption. Unused extras expire at renewal.'],
      'rate_limit' => ['e.g. 5', 'Maximum admissions per user in the interval.', 'Count admitted generations, including failures, in a rolling interval. Refused attempts are not new admissions.'],
      'rate_window_seconds' => ['e.g. 60', 'Rolling interval in whole seconds. Required when enabled.', 'For example, a 60-second interval counts admissions during the preceding 60 seconds. Saving does not clear recent admission history.'],
      'concurrency_limit' => ['e.g. 2', 'Maximum generations in progress per user.', 'Limit new admissions while this many generations remain in progress. Already admitted generations can finish normally.'],
      'failure_limit' => ['e.g. 3', 'Failures needed to pause new generations.', 'Count failed deliveries and expired operations in the configured window. Successful generations do not erase other recent failures.'],
      'failure_window_seconds' => ['e.g. 300', 'Rolling failure window in whole seconds.', 'Only recent failures in this window count toward the threshold. A shrinking count can release the protection before the pause duration ends.'],
      'failure_pause_seconds' => ['e.g. 60', 'Pause duration after the latest qualifying failure.', 'The pause lasts at most this long after the latest failure, while the failure threshold is still met. Refused attempts do not extend the pause.'],
    };
    return TextInput::make($name)->label($label)->inputMode('numeric')
      ->placeholder($example)->helperText($help)
      ->hintAction(self::informationAction($name, $label, $details))
      ->rules([fn () => function (string $attribute, mixed $value, \Closure $fail) use ($min): void {
        try { self::parseInteger($value, $attribute, $min); }
        catch (ValidationException) { $fail('Enter a whole number between ' . $min . ' and ' . number_format(Credits::MAX_CREDITS) . '.'); }
      }]);
  }

  public static function parseInteger(mixed $value, string $field, int $min = 0): int
  {
    if ((!is_int($value) && (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)$/D', $value)))
      || strlen((string) $value) > 10 || (int) $value < $min || (int) $value > Credits::MAX_CREDITS) {
      throw ValidationException::withMessages([$field => 'Enter a whole number between ' . $min . ' and ' . number_format(Credits::MAX_CREDITS) . '.']);
    }
    return (int) $value;
  }

  public static function informationAction(string $name, string $label, string $details): Action
  {
    return Action::make('info' . ucfirst($name))->label('About ' . $label)
      ->icon('heroicon-m-information-circle')->iconButton()->color('gray')->tooltip($details)
      ->modalHeading($label)->modalDescription($details)
      ->modalSubmitAction(false)->modalCancelActionLabel('Close');
  }

  public static function reasonField(): Textarea
  {
    return Textarea::make('reason')->label('Reason')->required()->maxLength(500)->rows(3)->autofocus()
      ->placeholder('e.g. Temporary access change requested by the team.')
      ->helperText('Required. Saved with the administrator, user and date in Activity.')
      ->hintAction(self::informationAction('reason', 'Reason for this change', 'Explain why this administrative change is needed. The reason is visible to administrators in Activity. Your explanation is included in the audit record after confirmation.'));
  }

}
