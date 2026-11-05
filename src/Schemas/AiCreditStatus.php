<?php

namespace PdroLucas\FilamentAiWriter\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use PdroLucas\FilamentAiWriter\Support\HostDates;

/** Presentation only. Callers must supply snapshots from authorized public APIs. */
final class AiCreditStatus
{
  public static function components(array $balance, array $abuse, array $availability): array
  {
    $t = static fn (string $key, array $replace = []): string => __('filament-ai-writer::credits.status.' . $key, $replace);
    $date = static fn ($value): string => $value === null ? $t('manual') : HostDates::dateTime($value);
    $refusal = !$availability['enabled'] ? $t('ai_paused') : ($availability['blocked'] ? $t('blocked') : match ($abuse['refusal']) {
      'rate' => $t('rate'),
      'concurrency' => $t('concurrency'),
      'failure_pause' => $t('failure_pause'),
      default => $balance['enabled'] ? ($balance['available'] === null ? $t('credits_unavailable') : ($balance['available'] === 0 ? $t('no_credits') : null)) : null,
    });
    $credits = [];
    if ($balance['limit'] !== null) {
      foreach (['available', 'consumed', 'reserved', 'limit', 'extras'] as $key) {
        $credits[] = TextEntry::make('credits_' . $key)->label($t($key))->state($t('credits_value', ['count' => number_format($balance[$key])]));
      }
      $credits[] = TextEntry::make('limit_source')->label($t('limit_source'))->state($balance['individual_limit'] === null ? $t('workspace_default') : $t('individual_limit'));
      $credits[] = TextEntry::make('renewal')->label($t('renewal'))->state($date($balance['renews_at']));
    }
    $status = [
      TextEntry::make('access')->hiddenLabel()->state($refusal === null ? $t('allowed') : $t('paused'))
        ->badge()->color($refusal === null ? 'success' : 'warning')->extraAttributes(['class' => 'ai-controls-status', 'data-state' => $refusal === null ? 'available' : 'paused']),
      TextEntry::make('access_explanation')->hiddenLabel()->state($refusal)->visible($refusal !== null)->columnSpanFull(),
    ];
    if ($abuse['refusal'] === 'rate' && $abuse['rate']['retry_at'] !== null) {
      $status[] = TextEntry::make('frequency_retry')->label($t('frequency_retry'))->state($date($abuse['rate']['retry_at']));
    }
    if ($abuse['failures']['retry_at'] !== null) {
      $status[] = TextEntry::make('failure_retry')->label($t('failure_retry'))
        ->state($date($abuse['failures']['retry_at']));
    }
    if ($abuse['policy']['concurrency_enabled']) {
      $status[] = TextEntry::make('in_progress')->label($t('in_progress'))
        ->state($t('in_progress_value', ['count' => number_format($abuse['concurrency']['count']), 'limit' => number_format($abuse['policy']['concurrency_limit'])]));
    }
    $creditsDescription = $balance['limit'] === null
      ? (!$balance['enabled'] ? $t('quotas_off_no_limit') : $t('no_limit'))
      : (!$balance['enabled'] ? $t('quotas_off') : null);

    return [
      Section::make($t('access'))->contained(false)->schema($status)->columns(2)->extraAttributes(['class' => 'ai-controls-meta']),
      Section::make($t('credits'))->contained(false)->description($creditsDescription)->schema($credits)->columns(3)->extraAttributes(['class' => 'ai-controls-ledger']),
    ];
  }
}
