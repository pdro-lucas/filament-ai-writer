<?php

namespace PdroLucas\FilamentAiWriter\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use PdroLucas\FilamentAiWriter\Support\HostDates;

/** Presentation only. Callers must supply snapshots from authorized public APIs. */
final class AiCreditStatus
{
  /**
   * Why this actor cannot generate right now, or null when allowed: the one rule behind the status badge here and in the
   * Users list (which passes an abuse snapshot without a refusal, as protections are only evaluated per actor).
   *
   * @return ?string a `credits.status.*` key
   */
  public static function refusal(array $balance, array $abuse, array $availability): ?string
  {
    return match (true) {
      ! $availability['enabled'] => 'ai_paused',
      $availability['blocked'] => 'blocked',
      in_array($abuse['refusal'] ?? null, ['rate', 'concurrency', 'failure_pause'], true) => $abuse['refusal'],
      $balance['enabled'] && $balance['available'] === null => 'credits_unavailable',
      $balance['enabled'] && $balance['available'] === 0 => 'no_credits',
      default => null,
    };
  }

  /** The whole read-only picture (used by the own-credits page): status first, then the credit numbers. */
  public static function components(array $balance, array $abuse, array $availability): array
  {
    return [...self::statusEntries($balance, $abuse, $availability), ...self::creditsComponents($balance, $abuse)];
  }

  /** The status badge and, when access is refused, why. */
  public static function statusEntries(array $balance, array $abuse, array $availability): array
  {
    $t = static fn (string $key, array $replace = []): string => __('filament-ai-writer::credits.status.' . $key, $replace);
    $refusalKey = self::refusal($balance, $abuse, $availability);

    return array_values(array_filter([
      TextEntry::make('access')->label($t('access'))->hiddenLabel()->state($refusalKey === null ? $t('allowed') : $t('paused'))
        ->badge()->color($refusalKey === null ? 'success' : 'warning'),
      $refusalKey === null ? null : TextEntry::make('access_explanation')->hiddenLabel()->state($t($refusalKey)),
    ]));
  }

  /** Tiles (available, used, reserved, limit) and the details list. */
  public static function creditsComponents(array $balance, array $abuse): array
  {
    $t = static fn (string $key, array $replace = []): string => __('filament-ai-writer::credits.status.' . $key, $replace);
    $date = static fn ($value): string => $value === null ? $t('manual') : HostDates::dateTime($value);
    $number = static fn (int $value): string => number_format($value);
    $tile = static fn (string $key, string $label, Heroicon $icon, string $value): Section => Section::make()->compact()->schema([
      TextEntry::make($key)->label($label)->icon($icon)->iconColor('gray')->state($value),
    ]);
    $row = static fn (string $key, string $label, string $value): TextEntry => TextEntry::make($key)->label($label)->inlineLabel()->alignEnd()->state($value);

    $details = [];
    if ($balance['limit'] !== null) {
      $details[] = $row('limit_source', $t('limit_source'), $balance['individual_limit'] === null ? $t('workspace_default') : $t('individual_limit'));
      $details[] = $row('credits_extras', $t('extras'), $number($balance['extras']));
      $details[] = $row('renewal', $t('renewal'), $date($balance['renews_at']));
    }
    if (($abuse['refusal'] ?? null) === 'rate' && $abuse['rate']['retry_at'] !== null) {
      $details[] = $row('frequency_retry', $t('frequency_retry'), $date($abuse['rate']['retry_at']));
    }
    if ($abuse['failures']['retry_at'] !== null) {
      $details[] = $row('failure_retry', $t('failure_retry'), $date($abuse['failures']['retry_at']));
    }
    if ($abuse['policy']['concurrency_enabled']) {
      $details[] = $row('in_progress', $t('in_progress'), $t('in_progress_value', ['count' => $number($abuse['concurrency']['count']), 'limit' => $number($abuse['policy']['concurrency_limit'])]));
    }
    $creditsDescription = $balance['limit'] === null
      ? (! $balance['enabled'] ? $t('quotas_off_no_limit') : $t('no_limit'))
      : (! $balance['enabled'] ? $t('quotas_off') : null);

    return array_values(array_filter([
      $balance['limit'] === null ? null : Grid::make(['default' => 2, 'lg' => 4])->schema([
        $tile('credits_available', $t('available'), Heroicon::CheckCircle, $balance['available'] === null ? '—' : $number($balance['available'])),
        $tile('credits_consumed', $t('consumed'), Heroicon::ArrowTrendingDown, $number($balance['consumed'])),
        $tile('credits_reserved', $t('reserved'), Heroicon::LockClosed, $number($balance['reserved'])),
        $tile('credits_limit', $t('limit'), Heroicon::Flag, $number($balance['limit'])),
      ]),
      $creditsDescription === null ? null : TextEntry::make('credits_note')->hiddenLabel()->state($creditsDescription),
      $details === [] ? null : Section::make()->compact()->schema($details),
    ]));
  }
}
