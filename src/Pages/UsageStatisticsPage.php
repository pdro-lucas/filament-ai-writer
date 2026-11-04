<?php

namespace PdroLucas\FilamentAiWriter\Pages;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
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
use PdroLucas\FilamentAiWriter\Concerns\UsesPluginNavigation;
use PdroLucas\FilamentAiWriter\Controls\ActorIdentity;
use PdroLucas\FilamentAiWriter\Models\UsageAttempt;
use PdroLucas\FilamentAiWriter\Schemas\UsageAttemptDetails;
use PdroLucas\FilamentAiWriter\Support\Currency;
use PdroLucas\FilamentAiWriter\Support\HostDates;
use PdroLucas\FilamentAiWriter\Usage\UsageStatistics;

class UsageStatisticsPage extends Page implements HasTable
{
  use UsesPluginNavigation;

  use InteractsWithTable {
    getTableFilterState as protected getRawTableFilterState;
  }

  protected static ?string $title = null;
  protected static ?string $slug = 'ai-writer-usage';

  protected static function pluginPage(): string
  {
    return 'statistics';
  }
  protected string $view = 'filament-ai-writer::usage-statistics';

  #[Locked]
  public string $panelId;

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
      ->filtersLayout(FiltersLayout::Dropdown)
      ->filtersFormColumns(1)
      ->recordAction('details')
      ->recordActions([
        Action::make('details')->label(__('filament-ai-writer::statistics.table.details'))->link()->slideOver()->modalWidth('2xl')
          ->modalHeading(__('filament-ai-writer::statistics.details.heading'))->modalSubmitAction(false)->modalCancelActionLabel(__('filament-ai-writer::statistics.details.close'))
          ->modalContent(fn (UsageAttempt $record) => view('filament-ai-writer::usage-attempt-details', [
            // A record supplied by the action is never an authorization boundary.
            'schema' => Schema::make($this)->components(UsageAttemptDetails::components(
              $this->statistics()->query()->findOrFail($record->getKey()),
            )),
          ])),
      ])
      ->columns([
        // No format or timezone here: the host's Filament defaults (also per user) decide how dates look.
        TextColumn::make('started_at')->label(__('filament-ai-writer::statistics.table.date'))->dateTime()->sortable(),
        TextColumn::make('actor_id')->label(__('filament-ai-writer::statistics.table.user'))
          ->formatStateUsing(fn (UsageAttempt $record): string => $this->actorLabel($record))->wrap(),
        TextColumn::make('status')->label(__('filament-ai-writer::statistics.table.result'))->badge()
          ->formatStateUsing(fn (string $state): string => __('filament-ai-writer::statistics.status.' . (in_array($state, ['delivered', 'failed'], true) ? $state : 'active')))
          ->color(fn (string $state): string => match ($state) { 'delivered' => 'success', 'failed' => 'danger', default => 'warning' }),
        TextColumn::make('input_tokens')->label(__('filament-ai-writer::statistics.table.input_tokens'))->placeholder(__('filament-ai-writer::statistics.table.unavailable'))->alignEnd(),
        TextColumn::make('output_tokens')->label(__('filament-ai-writer::statistics.table.output_tokens'))->placeholder(__('filament-ai-writer::statistics.table.unavailable'))->alignEnd(),
        TextColumn::make('cost_usd')->label(__('filament-ai-writer::statistics.table.cost', ['currency' => Currency::code()]))->placeholder(__('filament-ai-writer::statistics.table.unavailable'))->alignEnd()
          ->formatStateUsing(fn (string $state): string => rtrim(rtrim($state, '0'), '.'))
          ->description(fn (UsageAttempt $record): string => __('filament-ai-writer::statistics.source.' . match ($record->cost_source) { 'provider' => 'provider', 'estimated' => 'estimated', default => 'none' })),
        TextColumn::make('duration_ms')->label(__('filament-ai-writer::statistics.table.duration'))->placeholder(__('filament-ai-writer::statistics.table.unavailable'))->alignEnd()
          ->formatStateUsing(fn (int $state): string => number_format($state / 1000, 2) . ' s'),
      ])
      ->filters([
        Filter::make('period')->schema([
          // A shortcut over the two dates: editing a date returns it to "Custom range", and only the dates filter.
          Select::make('preset')->label(__('filament-ai-writer::statistics.filter.period'))->native(false)->live()->selectablePlaceholder(false)->default('7')
            ->options(['1' => __('filament-ai-writer::statistics.filter.today'), '7' => __('filament-ai-writer::statistics.filter.days7'), '30' => __('filament-ai-writer::statistics.filter.days30'), 'custom' => __('filament-ai-writer::statistics.filter.custom')])
            ->afterStateUpdated(function (?string $state, Set $set): void {
              if (in_array($state, ['1', '7', '30'], true)) {
                $today = CarbonImmutable::now(HostDates::timezone());
                $set('from', $today->subDays((int) $state - 1)->toDateString());
                $set('until', $today->toDateString());
              }
            }),
          DatePicker::make('from')->label(__('filament-ai-writer::statistics.filter.from'))->native(false)->format('Y-m-d')->closeOnDateSelection()->live()
            ->afterStateUpdated(fn (Set $set) => $set('preset', 'custom'))
            ->default(fn (): string => CarbonImmutable::now(HostDates::timezone())->subDays(6)->toDateString()),
          DatePicker::make('until')->label(__('filament-ai-writer::statistics.filter.until'))->native(false)->format('Y-m-d')->closeOnDateSelection()->live()
            ->afterStateUpdated(fn (Set $set) => $set('preset', 'custom'))
            ->default(fn (): string => CarbonImmutable::now(HostDates::timezone())->toDateString()),
        ])->query(fn (Builder $query, array $data): Builder => $this->statistics()->filterPeriod($query, $data))
          ->indicateUsing(function (array $data): ?string {
            $from = $data['from'] ?? null;
            $until = $data['until'] ?? null;
            return filled($from) || filled($until)
              ? __('filament-ai-writer::statistics.filter.indicator', ['from' => filled($from) ? HostDates::date(substr((string) $from, 0, 10)) : '…', 'until' => filled($until) ? HostDates::date(substr((string) $until, 0, 10)) : '…'])
              : null;
          }),
        $this->metadataFilter('actor', __('filament-ai-writer::statistics.filter.user'))
          ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $this->statistics()->filterActor($query, $data['value']) : $query),
        SelectFilter::make('status')->label(__('filament-ai-writer::statistics.filter.result'))->options(['delivered' => __('filament-ai-writer::statistics.status.delivered'), 'failed' => __('filament-ai-writer::statistics.status.failed'), 'active' => __('filament-ai-writer::statistics.status.active')])
          ->query(fn (Builder $query, array $data): Builder => $this->statistics()->filterResult($query, $data['value'] ?? null)),
      ])
      ->emptyStateHeading(__('filament-ai-writer::statistics.table.empty'));
  }

  public function getTableFilterState(string $name): ?array
  {
    $state = $this->getRawTableFilterState($name);
    if ($name === 'period' && $state !== null) {
      // The quick-range selector only drives the dates; it is never part of the filter itself.
      unset($state['preset']);
      // These date-only pickers may carry a time (some Filament versions keep the current time on defaults).
      // Keep only the date part; strict period validation still owns parsing the date itself.
      foreach (['from', 'until'] as $boundary) {
        $value = $state[$boundary] ?? null;
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} (?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D', $value)) {
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
    $today = CarbonImmutable::now(HostDates::timezone());
    $this->tableFilters['period'] = ['preset' => (string) $days, 'from' => $today->subDays($days - 1)->toDateString(), 'until' => $today->toDateString()];
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
      $this->actorLabels[$key] = __('filament-ai-writer::statistics.table.user_unavailable');
      $plugin = AiWriterPlugin::get();
      // Reuse the existing authorized directory without granting statistics viewers management access.
      try {
        if ($plugin->canManage() && $plugin->hasActorDirectory()) {
          $actor = new ActorIdentity($record->actor_type, $record->actor_id);
          $this->actorLabels[$key] = $plugin->resolveActor($actor->token(), $plugin->controlContext())?->label ?: __('filament-ai-writer::statistics.table.user_unavailable');
        }
      } catch (\Throwable) {
        // Removed users and unavailable host directories must not hide usage history.
      }
    }
    return $this->actorLabels[$key];
  }

  public function content(Schema $schema): Schema
  {
    return $schema->components([EmbeddedTable::make()]);
  }

  /** @return array<string, mixed> Only visible table filters may affect the overview or the independently mounted chart. */
  protected function widgetFilters(): array
  {
    $filters = array_intersect_key($this->tableFilters ?? [], array_flip(['period', 'actor', 'status']));
    $filters['period'] = $this->getTableFilterState('period') ?? [];

    return $filters;
  }

  protected function getViewData(): array
  {
    $filters = $this->widgetFilters();

    return [
      'widgetKey' => hash('sha256', json_encode($filters)),
      'filters' => $filters,
      'panel' => $this->panelId,
    ];
  }
}
