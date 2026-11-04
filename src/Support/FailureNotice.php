<?php

namespace PdroLucas\FilamentAiWriter\Support;

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Exceptions\GenerationFailure;
use Throwable;

/** Two-layer failure message: actionable text for everyone, technical detail in the log and for managers only. */
final class FailureNotice
{
  public static function send(GenerationFailure $failure): void
  {
    $body = $failure->detail();

    if ($failure->isTechnical()) {
      $reference = 'AIW-' . Str::upper(Str::random(8));
      Log::error('AI Writer generation failed.', [
        'reference' => $reference,
        'failure_code' => $failure->failureCode,
        'detail' => $failure->getMessage(),
        'http_status' => $failure->httpStatus,
        'request_id' => $failure->requestId,
        'response_id' => $failure->responseId,
        'outcome_unknown' => $failure->outcomeUnknown,
      ]);
      $body = self::canSeeDetail()
        ? __(':detail (Reference: :reference)', ['detail' => $body, 'reference' => $reference])
        : __('AI is unavailable right now. Try again later or contact your administrator and mention reference :reference.', ['reference' => $reference]);
    }

    Notification::make()->warning()->title(__('AI generation was not delivered'))->body($body)->send();
  }

  /** Without a registered plugin there is no manage permission to consult, so the detail stays hidden. */
  private static function canSeeDetail(): bool
  {
    try {
      return AiWriterPlugin::current()?->canManage() ?? false;
    } catch (Throwable) {
      return false;
    }
  }
}
