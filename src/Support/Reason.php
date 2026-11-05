<?php

namespace PdroLucas\FilamentAiWriter\Support;

use Illuminate\Validation\ValidationException;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;

/** Server-side rule for the reason attached to administrative changes; the UI mirrors it but never replaces it. */
final class Reason
{
  /** Actions that target one person, plus renewing everyone: the reason is required unless requireReason() says otherwise. */
  public const REQUIRED_BY_DEFAULT = ['block', 'quota_limit', 'quota_extra', 'quota_reset', 'quota_renew'];

  public static function isRequired(string $action): bool
  {
    return AiWriterPlugin::get()->isReasonRequired($action);
  }

  /** The trimmed reason, or null when none was given and the action does not require one. */
  public static function resolve(string $action, ?string $reason): ?string
  {
    $reason = trim((string) $reason);
    if ($reason === '') {
      if (self::isRequired($action)) {
        throw ValidationException::withMessages(['reason' => __('filament-ai-writer::controls.reason.invalid')]);
      }
      return null;
    }
    if (mb_strlen($reason) > 500 || !mb_check_encoding($reason, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)) {
      throw ValidationException::withMessages(['reason' => __('filament-ai-writer::controls.reason.invalid')]);
    }
    return $reason;
  }
}
