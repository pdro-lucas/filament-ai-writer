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
use PdroLucas\FilamentAiWriter\Controls\ActorIdentity;
use PdroLucas\FilamentAiWriter\Models\UsageAttempt;
use PdroLucas\FilamentAiWriter\Usage\UsageStatistics;

class UsageStatisticsPage extends Page implements HasTable
{
  use InteractsWithTable {
    getTableFilterState as protected getRawTableFilterState;
  }

  protected static ?string $title = 'AI Writer usage';
  protected static ?string $slug = 'ai-writer-usage';
  protected string $view = 'filament-ai-writer::usage-statistics';

  #[Locked]
  public string $panelId;

  // Server-derived menu display state; actual dates remain authoritative.
  public string $periodPreset = '7';

  protected array $actorLabels = [];

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
        TextColumn::make('actor_id')->label('User')
          ->formatStateUsing(fn (UsageAttempt $record): string => $this->actorLabel($record))->wrap(),
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
          DatePicker::make('from')->label('From')->native(false)->format('Y-m-d')->closeOnDateSelection()->default(fn (): string => CarbonImmutable::now(config('app.timezone'))->subDays(6)->toDateString()),
          DatePicker::make('until')->label('Through')->native(false)->format('Y-m-d')->closeOnDateSelection()->default(fn (): string => CarbonImmutable::now(config('app.timezone'))->toDateString()),
        ])->query(fn (Builder $query, array $data): Builder => $this->statistics()->filterPeriod($query, $data)),
        $this->metadataFilter('actor', 'User')
          ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $this->statistics()->filterActor($query, $data['value']) : $query),
        SelectFilter::make('status')->label('Result')->options(['delivered' => 'Success', 'failed' => 'Failure', 'active' => 'In progress'])
          ->query(fn (Builder $query, array $data): Builder => $this->statistics()->filterResult($query, $data['value'] ?? null)),
      ])
      ->emptyStateHeading('No usage recorded for this panel and these filters');
  }

  public function getTableFilterState(string $name): ?array
  {
    $state = $this->getRawTableFilterState($name);
    if ($name === 'period' && $state !== null) {
      // Adapt only the picker's exact midnight representation; strict period validation owns parsing.
      foreach (['from', 'until'] as $boundary) {
        $value = $state[$boundary] ?? null;
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} 00:00:00$/D', $value)) {
          $state[$boundary] = substr($value, 0, 10);
        }
      }
    }
    return $state;
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

  protected function actorLabel(UsageAttempt $record): string
  {
    $this->authorizeContext();
    $key = UsageStatistics::actorKey($record->actor_type, $record->actor_id);
    if (!array_key_exists($key, $this->actorLabels)) {
      $this->actorLabels[$key] = 'User unavailable';
      $plugin = AiWriterPlugin::get();
      // Reuse the existing authorized directory without granting statistics viewers management access.
      try {
        if ($plugin->canManage() && $plugin->hasActorDirectory()) {
          $actor = new ActorIdentity($record->actor_type, $record->actor_id);
          $this->actorLabels[$key] = $plugin->resolveActor($actor->token(), $plugin->controlContext())?->label ?: 'User unavailable';
        }
      } catch (\Throwable) {
        // Removed users and unavailable host directories must not hide usage history.
      }
    }
    return $this->actorLabels[$key];
  }

  protected function getViewData(): array
  {
    // Only visible table filters may affect cards or the independently mounted chart.
    $filters = array_intersect_key($this->tableFilters ?? [], array_flip(['period', 'actor', 'status']));
    $filters['period'] = $this->getTableFilterState('period') ?? [];
    $today = CarbonImmutable::now(config('app.timezone', 'UTC'));
    $periodPreset = 'custom';
    foreach ([1, 7, 30] as $days) {
      if (($filters['period']['from'] ?? null) === $today->subDays($days - 1)->toDateString()
        && ($filters['period']['until'] ?? null) === $today->toDateString()) {
        $periodPreset = (string) $days;
        break;
      }
    }
    $this->periodPreset = $periodPreset;
    return [
      'summary' => $this->statistics()->dashboard($filters),
      'filters' => $filters,
      'panel' => $this->panelId,
      'timezone' => config('app.timezone', 'UTC'),
      'periodPreset' => $periodPreset,
    ];
  }
}
