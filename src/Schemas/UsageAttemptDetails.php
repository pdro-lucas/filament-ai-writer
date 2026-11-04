<?php

namespace PdroLucas\FilamentAiWriter\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use PdroLucas\FilamentAiWriter\Models\UsageAttempt;
use PdroLucas\FilamentAiWriter\Support\Currency;
use PdroLucas\FilamentAiWriter\Support\HostDates;

/** Metadata-only detail of one request, built from native schema components. */
final class UsageAttemptDetails
{
  /** @return array<int, \Filament\Schemas\Components\Component> */
  public static function components(UsageAttempt $attempt): array
  {
    $t = fn (string $key, array $replace = []): string => __('filament-ai-writer::statistics.' . $key, $replace);
    $currency = ['currency' => Currency::code()];
    $unavailable = $t('table.unavailable');
    $source = match ($attempt->cost_source) { 'provider' => $t('source.provider'), 'estimated' => $t('source.estimated'), default => $t('source.none') };
    $result = match ($attempt->status) { 'delivered' => $t('status.delivered'), 'failed' => $t('status.failed'), default => $t('status.active') };
    $summary = [
      'tokens' => [$t('details.tokens'), ($attempt->input_tokens ?? $unavailable) . ' → ' . ($attempt->output_tokens ?? $unavailable)],
      'cost' => [$t('details.cost', $currency), ($attempt->cost_usd !== null ? Currency::format($attempt->cost_usd) : $unavailable) . ' · ' . $source],
      'http_duration' => [$t('details.http_duration'), $attempt->duration_ms !== null ? number_format($attempt->duration_ms / 1000, 2) . ' s' : $unavailable],
    ];
    // key => [value, always shown]. A missing value is hidden unless the field is one of the essential ones.
    $groups = [
      'request' => [
        'started' => [HostDates::dateTime($attempt->started_at) . ' · ' . HostDates::timezone(), false],
        'user_id' => [$attempt->actor_id, false], 'user_type' => [$attempt->actor_type, false],
        'provider' => [$attempt->provider, true], 'actual_model' => [$attempt->model, true], 'requested_model' => [$attempt->requested_model, false],
        'field' => [$attempt->field, false], 'action' => [$attempt->action_name, false],
      ],
      'consumption' => [
        'input_tokens' => [$attempt->input_tokens, true], 'output_tokens' => [$attempt->output_tokens, true],
        'cached_input' => [$attempt->cached_input_tokens, false], 'cache_write' => [$attempt->cache_write_input_tokens, false],
        'reasoning' => [$attempt->reasoning_output_tokens, false],
        'exact_cost' => [$attempt->cost_usd, true], 'cost_source' => [$source, false],
        'input_rate' => [$attempt->input_rate_usd_per_million, false], 'output_rate' => [$attempt->output_rate_usd_per_million, false],
        'http_ms' => [$attempt->duration_ms, true], 'local_ms' => [$attempt->local_duration_ms, false],
      ],
      'identifiers' => [
        'operation_id' => [$attempt->operation_id, false], 'attempt' => [$attempt->attempt_number, false],
        'request_id' => [$attempt->request_id, false], 'response_id' => [$attempt->response_id, false],
        'outcome' => [$attempt->outcome, false], 'finish_reason' => [$attempt->finish_reason, false],
        'http_status' => [$attempt->http_status, false], 'failure_code' => [$attempt->failure_code, false],
        'remote_outcome' => [$attempt->outcome_unknown ? $t('details.unknown') : null, false],
      ],
    ];
    $entry = static fn (string $key, string $label, mixed $value): TextEntry => TextEntry::make($key)->label($label)->state((string) ($value ?? $unavailable));

    $components = [
      Section::make(($attempt->model ?? $t('details.unknown_model')) . ' · ' . ($attempt->provider ?? $t('details.unknown_provider')) . ' · ' . $result)
        ->schema(array_values(array_map(fn (string $key, array $row): TextEntry => $entry('summary_' . $key, $row[0], $row[1]), array_keys($summary), $summary)))
        ->columns(3),
    ];
    foreach ($groups as $group => $fields) {
      $entries = [];
      foreach ($fields as $key => [$value, $always]) {
        if ($value !== null || $always) {
          $entries[] = $entry($key, $t('details.field.' . $key, $currency), $value);
        }
      }
      $components[] = Section::make($t('details.group.' . $group))->columns(2)->schema($entries);
    }
    $components[] = Section::make()->schema([TextEntry::make('footnote')->hiddenLabel()->state($t('details.footnote'))]);

    return $components;
  }
}
