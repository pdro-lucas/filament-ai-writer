<?php

namespace PdroLucas\FilamentAiWriter\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Number;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
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
    $unavailable = '—';
    $source = match ($attempt->cost_source) { 'provider' => $t('source.provider'), 'estimated' => $t('source.estimated'), default => $t('source.none') };
    $status = $attempt->status;
    $resultLabel = $t('status.' . (in_array($status, ['delivered', 'failed'], true) ? $status : 'active'));
    $speed = $attempt->output_tokens !== null && $attempt->duration_ms ? Number::format($attempt->output_tokens / ($attempt->duration_ms / 1000), 1) : null;
    $actor = AiWriterPlugin::get()->statistics()->actorLabel($attempt->actor_type, $attempt->actor_id);

    $tile = static fn (string $key, string $label, Heroicon $icon, ?string $value, ?string $tooltip = null): Section => Section::make()->compact()->schema([
      TextEntry::make($key)->label($label)->icon($icon)->iconColor('gray')->state($value ?? $unavailable)->tooltip($tooltip),
    ]);
    // Rows with no value are left out, so the lists only carry what the provider and the host reported.
    $rows = static function (array $rows): array {
      $entries = [];
      foreach ($rows as $key => [$label, $value]) {
        if ($value !== null && $value !== '') {
          $entries[] = TextEntry::make($key)->label($label)->inlineLabel()->alignEnd()->state((string) $value);
        }
      }

      return $entries;
    };
    $technical = array_map(
      fn (TextEntry $entry): TextEntry => $entry->fontFamily(FontFamily::Mono)->copyable()->limit(28),
      $rows([
        'http_ms' => [$t('details.field.http_ms'), $attempt->duration_ms], 'local_ms' => [$t('details.field.local_ms'), $attempt->local_duration_ms],
        'operation_id' => [$t('details.field.operation_id'), $attempt->operation_id], 'request_id' => [$t('details.field.request_id'), $attempt->request_id],
        'response_id' => [$t('details.field.response_id'), $attempt->response_id], 'attempt' => [$t('details.field.attempt'), $attempt->attempt_number],
        'outcome' => [$t('details.field.outcome'), $attempt->outcome], 'finish_reason' => [$t('details.field.finish_reason'), $attempt->finish_reason],
        'http_status' => [$t('details.field.http_status'), $attempt->http_status], 'failure_code' => [$t('details.field.failure_code'), $attempt->failure_code],
        'remote_outcome' => [$t('details.field.remote_outcome'), $attempt->outcome_unknown ? $t('details.unknown') : null],
        'user_type' => [$t('details.field.user_type'), $attempt->actor_type], 'user_id' => [$t('details.field.user_id'), $attempt->actor_id],
      ]),
    );
    $usage = $rows([
      'cached_input' => [$t('details.field.cached_input'), $attempt->cached_input_tokens], 'cache_write' => [$t('details.field.cache_write'), $attempt->cache_write_input_tokens],
      'reasoning' => [$t('details.field.reasoning'), $attempt->reasoning_output_tokens],
      'input_rate' => [$t('details.field.input_rate', $currency), $attempt->input_rate_usd_per_million], 'output_rate' => [$t('details.field.output_rate', $currency), $attempt->output_rate_usd_per_million],
    ]);

    return array_values(array_filter([
      // One badge entry keeps the model, provider and result evenly spaced; only the result is coloured.
      TextEntry::make('badges')->hiddenLabel()->badge()
        ->state([$attempt->model ?? $t('details.unknown_model'), $attempt->provider ?? $t('details.unknown_provider'), $resultLabel])
        ->color(fn (string $state): string => $state === $resultLabel ? match ($status) { 'delivered' => 'success', 'failed' => 'danger', default => 'warning' } : 'gray'),
      Grid::make(['default' => 2, 'lg' => 4])->schema([
        $tile('tile_duration', $t('details.http_duration'), Heroicon::Clock, $attempt->duration_ms !== null ? number_format($attempt->duration_ms / 1000, 2) . ' s' : null),
        $tile('tile_speed', $t('details.speed'), Heroicon::Bolt, $speed !== null ? $t('details.speed_value', ['rate' => $speed]) : null),
        $tile('tile_cost', $t('details.cost', $currency), Heroicon::Banknotes, $attempt->cost_usd !== null ? Currency::format($attempt->cost_usd) : null, $source),
        $tile('tile_tokens', $t('details.tokens'), Heroicon::CircleStack, ($attempt->input_tokens !== null ? Number::format($attempt->input_tokens) : $unavailable) . ' → ' . ($attempt->output_tokens !== null ? Number::format($attempt->output_tokens) : $unavailable)),
      ]),
      Section::make($t('details.group.overview'))->schema($rows([
        'when' => [$t('details.field.when'), HostDates::dateTime($attempt->started_at) . ' · ' . HostDates::timezone()],
        'user' => [$t('details.field.user'), $actor],
        'field' => [$t('details.field.field'), $attempt->field], 'action' => [$t('details.field.action'), $attempt->action_name],
        'requested_model' => [$t('details.field.requested_model'), $attempt->requested_model !== $attempt->model ? $attempt->requested_model : null],
      ])),
      $usage === [] ? null : Section::make($t('details.group.usage'))->description($t('details.usage_note'))->schema($usage),
      Section::make($t('details.group.technical'))->collapsed()->schema($technical),
    ]));
  }
}
