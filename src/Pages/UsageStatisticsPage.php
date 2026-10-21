<?php

namespace PdroLucas\FilamentAiWriter\Pages;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Enums\FiltersLayout;
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
      ->heading('Requests')
      ->filtersLayout(FiltersLayout::Hidden)
      ->filtersFormColumns(1)
      ->recordAction('details')
      ->recordActions([
        Action::make('details')->label('Details')->link()->slideOver()->modalWidth('2xl')
          ->modalHeading('Request details')->modalSubmitAction(false)->modalCancelActionLabel('Close')
          ->modalContent(fn (UsageAttempt $record) => view('filament-ai-writer::usage-attempt-details', [
            // A record supplied by the action is never an authorization boundary.
            'attempt' => $this->statistics()->query()->findOrFail($record->getKey()),
            'timezone' => config('app.timezone', 'UTC'),
          ])),
      ])
      ->columns([
        TextColumn::make('started_at')->label('Date / time')->dateTime('M j, H:i:s', timezone: config('app.timezone'))->sortable(),
        TextColumn::make('actor_id')->label('User')->formatStateUsing(fn (UsageAttempt $record): string => $record->actor_id)
          ->description(fn (UsageAttempt $record): string => class_basename($record->actor_type))->wrap(),
        TextColumn::make('status')->label('Result')->badge()
          ->formatStateUsing(fn (string $state): string => match ($state) { 'delivered' => 'Success', 'failed' => 'Failure', default => 'In progress' })
          ->color(fn (string $state): string => match ($state) { 'delivered' => 'success', 'failed' => 'danger', default => 'warning' }),
        TextColumn::make('input_tokens')->label('Input tokens')->placeholder('Unavailable')->alignEnd(),
        TextColumn::make('output_tokens')->label('Output tokens')->placeholder('Unavailable')->alignEnd(),
        TextColumn::make('cost_usd')->label('Cost · USD')->placeholder('Unavailable')->alignEnd()
          ->formatStateUsing(fn (string $state): string => rtrim(rtrim($state, '0'), '.'))
          ->description(fn (UsageAttempt $record): string => match ($record->cost_source) { 'provider' => 'Reported', 'estimated' => 'Estimated', default => 'Unavailable' }),
        TextColumn::make('duration_ms')->label('Duration')->placeholder('Unavailable')->alignEnd()
          ->formatStateUsing(fn (int $state): string => number_format($state / 1000, 2) . ' s'),
      ])
      ->filters([
        Filter::make('period')->schema([
          DatePicker::make('from')->label('From')->default(fn (): string => CarbonImmutable::now(config('app.timezone'))->subDays(6)->toDateString()),
          DatePicker::make('until')->label('Through')->default(fn (): string => CarbonImmutable::now(config('app.timezone'))->toDateString()),
        ])->query(fn (Builder $query, array $data): Builder => $this->statistics()->filterPeriod($query, $data)),
        $this->metadataFilter('actor', 'User')
          ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $this->statistics()->filterActor($query, $data['value']) : $query),
        SelectFilter::make('status')->label('Result')->options(['delivered' => 'Success', 'failed' => 'Failure', 'active' => 'In progress'])
          ->query(fn (Builder $query, array $data): Builder => $this->statistics()->filterResult($query, $data['value'] ?? null)),
      ])
      ->emptyStateHeading('No usage recorded for this panel and these filters');
  }

  public function setPeriod(int $days): void
  {
    $this->authorizeContext();
    abort_unless(in_array($days, [1, 7, 30], true), 422);
    $today = CarbonImmutable::now(config('app.timezone', 'UTC'));
    $this->tableFilters['period'] = ['from' => $today->subDays($days - 1)->toDateString(), 'until' => $today->toDateString()];
    $this->getTableFiltersForm()->fill($this->tableFilters);
    $this->updatedTableFilters();
  }

  public function resetUsageFilters(): void
  {
    $this->authorizeContext();
    $this->removeTableFilters();
    $this->setPeriod(7);
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
    // Only visible table filters may affect cards or the independently mounted chart.
    $filters = array_intersect_key($this->tableFilters ?? [], array_flip(['period', 'actor', 'status']));
    return [
      'summary' => $this->statistics()->dashboard($filters),
      'filters' => $filters,
      'panel' => $this->panelId,
      'timezone' => config('app.timezone', 'UTC'),
    ];
  }
}
