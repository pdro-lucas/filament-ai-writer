<?php

namespace PdroLucas\FilamentAiWriter\Schemas;

use Carbon\CarbonImmutable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

/** Presentation only. Callers must supply snapshots from authorized public APIs. */
final class AiCreditStatus
{
  public static function components(array $balance, array $abuse, array $availability): array
  {
    $date = static fn ($value): string => $value === null ? 'Manual renewal'
      : CarbonImmutable::parse($value, 'UTC')->setTimezone(config('app.timezone'))->format('M j, Y · H:i T');
    $refusal = !$availability['enabled'] ? 'AI paused' : ($availability['blocked'] ? 'User blocked' : match ($abuse['refusal']) {
      'rate' => 'Frequency limit reached',
      'concurrency' => 'Wait for a generation to finish',
      'failure_pause' => 'Paused after repeated failures',
      default => $balance['enabled'] ? ($balance['available'] === null ? 'Credits unavailable' : ($balance['available'] === 0 ? 'No credits available' : null)) : null,
    });
    $credits = [];
    if ($balance['limit'] !== null) {
      foreach (['available' => 'Available credits', 'consumed' => 'Consumed credits', 'reserved' => 'Reserved credits', 'limit' => 'Effective limit', 'extras' => 'Extra credits this period'] as $key => $label) {
        $credits[] = TextEntry::make('credits_' . $key)->label($label)->state(number_format($balance[$key]) . ' credits');
      }
      $credits[] = TextEntry::make('limit_source')->label('Limit source')->state($balance['individual_limit'] === null ? 'Workspace default' : 'Individual limit');
      $credits[] = TextEntry::make('renewal')->label('Next renewal')->state($date($balance['renews_at']));
    }
    $status = [
      TextEntry::make('access')->hiddenLabel()->state($refusal === null ? 'Allowed' : 'Paused')
        ->badge()->color($refusal === null ? 'success' : 'warning')->extraAttributes(['class' => 'ai-controls-status', 'data-state' => $refusal === null ? 'available' : 'paused']),
      TextEntry::make('access_explanation')->hiddenLabel()->state($refusal)->visible($refusal !== null)->columnSpanFull(),
    ];
    if ($abuse['refusal'] === 'rate' && $abuse['rate']['retry_at'] !== null) {
      $status[] = TextEntry::make('frequency_retry')->label('Frequency retry')->state($date($abuse['rate']['retry_at']));
    }
    if ($abuse['failures']['retry_at'] !== null) {
      $status[] = TextEntry::make('failure_retry')->label('Failure protection retry')
        ->state($date($abuse['failures']['retry_at']));
    }
    if ($abuse['policy']['concurrency_enabled']) {
      $status[] = TextEntry::make('in_progress')->label('Generations in progress')
        ->state(number_format($abuse['concurrency']['count']) . ' of ' . number_format($abuse['policy']['concurrency_limit']) . ' allowed');
    }
    return [
      Section::make('Access')->contained(false)->schema($status)->columns(2)->extraAttributes(['class' => 'ai-controls-meta']),
      Section::make('Credits')->contained(false)->description($balance['limit'] === null ? (!$balance['enabled'] ? 'Quotas off · No credit limit configured' : 'No credit limit configured') : (!$balance['enabled'] ? 'Quotas off' : null))->schema($credits)->columns(3)->extraAttributes(['class' => 'ai-controls-ledger']),
    ];
  }
}
