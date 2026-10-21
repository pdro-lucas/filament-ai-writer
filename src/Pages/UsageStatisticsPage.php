<?php

namespace PdroLucas\FilamentAiWriter\Pages;

use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Models\UsageAttempt;
use PdroLucas\FilamentAiWriter\Usage\UsageStatistics;

class UsageStatisticsPage extends Page implements HasTable
{
  use InteractsWithTable;

  protected static ?string $title = 'AI Writer usage';
  protected static ?string $slug = 'ai-writer-usage';
  protected string $view = 'filament-ai-writer::usage-statistics';

  #[Locked]
  public string $panelId;

  public static function canAccess(): bool
  {
    return UsageStatistics::canAccess();
  }

  public function mount(): void
  {
    abort_unless(static::canAccess(), 403);
    $this->panelId = Filament::getCurrentPanel()->getId();
  }

  public function hydrate(): void
  {
    $this->authorizeContext();
  }

  protected function authorizeContext(): void
  {
    abort_unless(static::canAccess() && $this->panelId === Filament::getCurrentPanel()->getId(), 403);
  }

  protected function statistics(): UsageStatistics
  {
    $this->authorizeContext();
    return AiWriterPlugin::get()->statistics();
  }

  public function table(Table $table): Table
  {
    return $table
      ->query(fn (): Builder => $this->statistics()->query())
      ->defaultSort('id', 'desc')
      ->paginated([10, 25, 50])
      ->defaultPaginationPageOption(25)
      ->deferFilters(false)
      ->columns([
        TextColumn::make('started_at')->label('Started')->dateTime('Y-m-d H:i:s', timezone: config('app.timezone'))->sortable(),
        TextColumn::make('status')->badge(),
        TextColumn::make('actor_id')->label('Actor')->formatStateUsing(fn (UsageAttempt $record): string => $record->actor_type . ' · ' . $record->actor_id)->wrap(),
        TextColumn::make('provider')->placeholder('Unknown'),
        TextColumn::make('model')->label('Returned model')->placeholder('Unknown')->wrap(),
        TextColumn::make('field'),
        TextColumn::make('input_tokens')->label('Input tokens')->placeholder('Unknown'),
        TextColumn::make('output_tokens')->label('Output tokens')->placeholder('Unknown'),
        TextColumn::make('cost_usd')->label('Cost (USD)')->placeholder('Unavailable'),
        TextColumn::make('cost_source')->label('Cost source'),
        TextColumn::make('duration_ms')->label('HTTP ms')->placeholder('Unknown'),
        TextColumn::make('local_duration_ms')->label('Local ms')->placeholder('Unknown'),
        TextColumn::make('failure_code')->label('Failure')->placeholder('—')->toggleable(),
        TextColumn::make('outcome_unknown')->label('Outcome unknown')->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')->toggleable(),
        ...array_map(fn (string $column): TextColumn => TextColumn::make($column)->placeholder('Unknown')->toggleable(isToggledHiddenByDefault: true), [
          'operation_id', 'attempt_number', 'action_name', 'requested_model', 'outcome', 'finish_reason',
          'cached_input_tokens', 'cache_write_input_tokens', 'reasoning_output_tokens', 'request_id', 'response_id', 'http_status',
          'input_rate_usd_per_million', 'output_rate_usd_per_million',
        ]),
      ])
      ->filters([
        Filter::make('period')->schema([
          DatePicker::make('from')->label('Started from'),
          DatePicker::make('until')->label('Started through'),
        ])->query(fn (Builder $query, array $data): Builder => $this->statistics()->filterPeriod($query, $data)),
        $this->metadataFilter('provider', 'Provider'),
        $this->metadataFilter('model', 'Returned model'),
        $this->metadataFilter('actor', 'Actor')
          ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $this->statistics()->filterActor($query, $data['value']) : $query),
        SelectFilter::make('status')->options(['pending' => 'Pending', 'received' => 'Received', 'delivered' => 'Delivered', 'failed' => 'Failed']),
      ])
      ->emptyStateHeading('No usage recorded for this panel and these filters');
  }

  public function getTableRecordsPerPage(): int
  {
    return in_array($this->tableRecordsPerPage, [10, 25, 50, '10', '25', '50'], true)
      ? (int) $this->tableRecordsPerPage : 25;
  }

  protected function metadataFilter(string $kind, string $label): SelectFilter
  {
    return SelectFilter::make($kind)->label($label)->searchable()
      ->options(fn (): array => $this->statistics()->options($kind))
      ->getSearchResultsUsing(fn (string $search): array => $this->statistics()->options($kind, $search))
      ->getOptionLabelUsing(fn (string $value): ?string => $this->statistics()->optionLabel($kind, $value));
  }

  protected function getViewData(): array
  {
    return [
      'summary' => $this->statistics()->summary($this->getFilteredTableQuery()),
      'panel' => $this->panelId,
      'timezone' => config('app.timezone', 'UTC'),
    ];
  }
}
