<?php

namespace PdroLucas\FilamentAiWriter\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Str;
use PdroLucas\FilamentAiWriter\Models\UsageAttempt;

/** Metadata-only detail of one request, built from native schema components. */
final class UsageAttemptDetails
{
  /** @return array<int, \Filament\Schemas\Components\Component> */
  public static function components(UsageAttempt $attempt, string $timezone): array
  {
    $unavailable = __('Unavailable');
    $source = match ($attempt->cost_source) { 'provider' => __('Provider-reported'), 'estimated' => __('Estimated'), default => $unavailable };
    $result = match ($attempt->status) { 'delivered' => __('Success'), 'failed' => __('Failure'), default => __('In progress') };
    $summary = [
      __('Input → output tokens') => ($attempt->input_tokens ?? $unavailable) . ' → ' . ($attempt->output_tokens ?? $unavailable),
      __('Cost · USD') => ($attempt->cost_usd !== null ? rtrim(rtrim($attempt->cost_usd, '0'), '.') : $unavailable) . ' · ' . $source,
      __('HTTP duration') => $attempt->duration_ms !== null ? number_format($attempt->duration_ms / 1000, 2) . ' s' : $unavailable,
    ];
    $groups = [
      __('Request') => [
        'Started' => $attempt->started_at->setTimezone($timezone)->format('Y-m-d H:i:s') . ' · ' . $timezone,
        'User ID' => $attempt->actor_id, 'User type' => $attempt->actor_type,
        'Provider' => $attempt->provider, 'Actual model' => $attempt->model, 'Requested model' => $attempt->requested_model,
        'Field' => $attempt->field, 'Action' => $attempt->action_name,
      ],
      __('Consumption & cost') => [
        'Input tokens' => $attempt->input_tokens, 'Output tokens' => $attempt->output_tokens,
        'Cached input¹' => $attempt->cached_input_tokens, 'Cache write input¹' => $attempt->cache_write_input_tokens,
        'Reasoning output¹' => $attempt->reasoning_output_tokens,
        'Exact cost · USD' => $attempt->cost_usd, 'Cost source' => $source,
        'Input tariff · USD / 1M' => $attempt->input_rate_usd_per_million, 'Output tariff · USD / 1M' => $attempt->output_rate_usd_per_million,
        'HTTP duration · ms' => $attempt->duration_ms, 'Local overhead · ms' => $attempt->local_duration_ms,
      ],
      __('Identifiers & outcome') => [
        'Operation ID' => $attempt->operation_id, 'Attempt' => $attempt->attempt_number,
        'Request ID' => $attempt->request_id, 'Response ID' => $attempt->response_id,
        'Outcome' => $attempt->outcome, 'Finish reason' => $attempt->finish_reason,
        'HTTP status' => $attempt->http_status, 'Failure code' => $attempt->failure_code,
        'Remote outcome' => $attempt->outcome_unknown ? 'Unknown' : null,
      ],
    ];
    $alwaysShown = ['Actual model', 'Provider', 'Input tokens', 'Output tokens', 'Exact cost · USD', 'HTTP duration · ms'];
    $entry = static fn (string $label, mixed $value): TextEntry => TextEntry::make(Str::slug($label, '_'))->label(__($label))->state((string) ($value ?? $unavailable))->copyable(false);

    $components = [
      Section::make(($attempt->model ?? __('Unknown model')) . ' · ' . ($attempt->provider ?? __('Unknown provider')) . ' · ' . $result)
        ->schema(array_map(fn (string $label, string $value): TextEntry => $entry('summary ' . $label, $value)->label($label), array_keys($summary), $summary))
        ->columns(3),
    ];
    foreach ($groups as $title => $fields) {
      $components[] = Section::make($title)->columns(2)->schema(array_values(array_map(
        fn (string $label): TextEntry => $entry($label, $fields[$label]),
        array_keys(array_filter($fields, fn ($value, string $label): bool => $value !== null || in_array($label, $alwaysShown, true), ARRAY_FILTER_USE_BOTH)),
      )));
    }
    $components[] = Section::make()->schema([TextEntry::make('footnote')->hiddenLabel()->state(__('¹ Included in input/output totals when measured; do not add again. Metadata only.'))]);

    return $components;
  }
}
