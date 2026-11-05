<?php

namespace PdroLucas\FilamentAiWriter\Pages;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Actions\FilterAction;
use Filament\Pages\Dashboard\Concerns\HasFiltersAction;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Concerns\UsesPluginNavigation;
use PdroLucas\FilamentAiWriter\Models\UsageAttempt;
use PdroLucas\FilamentAiWriter\Schemas\UsageAttemptDetails;
use PdroLucas\FilamentAiWriter\Support\Currency;
use PdroLucas\FilamentAiWriter\Support\HostDates;
use PdroLucas\FilamentAiWriter\Usage\UsageStatistics;
use PdroLucas\FilamentAiWriter\Widgets\UsageCountChart;
use PdroLucas\FilamentAiWriter\Widgets\UsageOverviewStats;
use PdroLucas\FilamentAiWriter\Widgets\UsageTopFields;
use PdroLucas\FilamentAiWriter\Widgets\UsageTopUsers;

class UsageStatisticsPage extends Page implements HasTable
{
  use UsesPluginNavigation;

  use InteractsWithTable, HasFiltersAction {
    // The header filters and the table both carry this (deprecated) helper; the table's is the one that applies.
    InteractsWithTable::normalizeTableFilterValuesFromQueryString insteadof HasFiltersAction;
    resolveTableRecord as protected resolveTableRecordByKey;
  }

  /** Usage ids are integers: any other key from a tampered payload is "not found" instead of a database type error. */
  protected function resolveTableRecord(?string $key): \Illuminate\Database\Eloquent\Model|array|null
  {
    return $key !== null && ctype_digit($key) ? $this->resolveTableRecordByKey($key) : null;
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

  /** Period and user live in the page header and drive the cards, rankings, chart and table alike; nothing is kept between visits. */
  public function persistsFiltersInSession(): bool
  {
    return false;
  }

  /** Which filters the embedded widgets were last mounted with, and how many times that changed (their key). */
  #[Locked]
  public string $widgetFiltersHash = '';

  #[Locked]
  public int $widgetEpoch = 0;

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

  /** @return array<int, Action|ActionGroup> OpenRouter-style header: a period menu and a filter button holding the user filter. */
  protected function getHeaderActions(): array
  {
    $t = fn (string $key, array $replace = []): string => __('filament-ai-writer::statistics.filter.' . $key, $replace);
    // Header actions are built before an action changes the filters: everything that shows state is read lazily.
    $label = function () use ($t): string {
      $state = $this->currentFilters();

      return match (true) {
        $state['preset'] === '1' => $t('today'),
        $state['preset'] === '7' => $t('days7'),
        $state['preset'] === '30' => $t('days30'),
        default => $t('range', ['from' => $this->shortDate($state['from']), 'until' => $this->shortDate($state['until'])]),
      };
    };

    return [
      Action::make('clearFilters')->label($t('clear'))->icon(Heroicon::XMark)->color('gray')
        ->visible(fn (): bool => $this->activeFilterCount() > 0)
        ->action(function (): void {
          $this->authorizeContext();
          $this->fillFilters(['actor' => null, 'field' => null]);
        }),
      ActionGroup::make([
        Action::make('period1')->label($t('today'))->action(fn () => $this->setPeriod(1)),
        Action::make('period7')->label($t('days7'))->action(fn () => $this->setPeriod(7)),
        Action::make('period30')->label($t('days30'))->action(fn () => $this->setPeriod(30)),
        Action::make('periodCustom')->label($t('custom_action'))->modalWidth('sm')->modalHeading($t('period'))
          ->fillForm(fn (): array => ['from' => $this->currentFilters()['from'], 'until' => $this->currentFilters()['until']])
          ->schema([
            DatePicker::make('from')->label($t('from'))->native(false)->format('Y-m-d')->closeOnDateSelection()->required(),
            DatePicker::make('until')->label($t('until'))->native(false)->format('Y-m-d')->closeOnDateSelection()->required()->afterOrEqual('from'),
          ])
          ->action(function (array $data): void {
            $this->authorizeContext();
            $this->fillFilters(['preset' => 'custom', 'from' => $data['from'], 'until' => $data['until']]);
          }),
      ])->label($label)->icon(Heroicon::CalendarDays)->button()->color('gray')->dropdownPlacement('bottom-end'),
      FilterAction::make()->modalWidth('sm')->badge(fn (): ?int => $this->activeFilterCount() ?: null)
        ->fillForm(fn (): array => ['actor' => $this->currentFilters()['actor'], 'field' => $this->currentFilters()['field']])
        ->schema([
          Select::make('actor')->label($t('user'))->native(false)->searchable()->placeholder($t('all_users'))
            ->options(fn (): array => $this->actorOptions())
            ->getSearchResultsUsing(fn (string $search): array => $this->actorOptions($search))
            ->getOptionLabelUsing(fn (string $value): ?string => $this->actorOptionLabel($value)),
          Select::make('field')->label($t('field'))->native(false)->searchable()->placeholder($t('all_fields'))
            ->options(fn (): array => $this->statistics()->options('field'))
            ->getSearchResultsUsing(fn (string $search): array => $this->statistics()->options('field', $search))
            ->getOptionLabelUsing(fn (string $value): ?string => $this->statistics()->optionLabel('field', $value)),
        ])
        ->action(function (array $data): void {
          $this->authorizeContext();
          $this->fillFilters([
            'actor' => filled($data['actor'] ?? null) ? (string) $data['actor'] : null,
            'field' => filled($data['field'] ?? null) ? (string) $data['field'] : null,
          ]);
        }),
    ];
  }

  /** How many of the page filters beyond the period (user, field) are active. */
  protected function activeFilterCount(): int
  {
    return count(array_filter([$this->currentFilters()['actor'], $this->currentFilters()['field']], filled(...)));
  }

  protected function shortDate(?string $value): string
  {
    return filled($value) ? HostDates::date(substr($value, 0, 10)) : '…';
  }

  /** @return array<string, string> Option labels use the same resolution as the table, falling back to the stored identity. */
  protected function actorOptions(string $search = ''): array
  {
    $options = $this->statistics()->options('actor', $search);
    foreach ($options as $key => $raw) {
      // The key already says who it is: no per-option query, just the (memoised) directory label.
      $actor = json_decode(base64_decode((string) $key, true) ?: '', true);
      $options[$key] = is_array($actor) && count($actor) === 2 && is_string($actor[0]) && is_string($actor[1])
        ? $this->statistics()->actorLabel($actor[0], $actor[1], $raw) : $raw;
    }

    return $options;
  }

  protected function actorOptionLabel(string $key): ?string
  {
    $label = $this->statistics()->optionLabel('actor', $key);
    if ($label === null) {
      return null;
    }
    $actor = json_decode(base64_decode($key, true) ?: '', true);

    return is_array($actor) && count($actor) === 2 && is_string($actor[0]) && is_string($actor[1]) ? $this->statistics()->actorLabel($actor[0], $actor[1], $label) : $label;
  }

  public function updatedFilters(): void
  {
    $this->authorizeContext();
    $this->resetPage();
  }

  /** @return array{preset: string, from: ?string, until: ?string, actor: ?string, field: ?string} The header filters with their defaults and without picker clock times. */
  protected function currentFilters(): array
  {
    $today = CarbonImmutable::now(HostDates::timezone());
    $state = array_replace(
      ['preset' => '7', 'from' => $today->subDays(6)->toDateString(), 'until' => $today->toDateString(), 'actor' => null, 'field' => null],
      array_intersect_key($this->filters ?? [], array_flip(['preset', 'from', 'until', 'actor', 'field'])),
    );
    // A tampered (non-text) user or field filter selects nothing instead of crashing.
    foreach (['actor', 'field'] as $key) {
      if ($state[$key] !== null && ! is_string($state[$key])) {
        $state[$key] = 'invalid';
      }
    }
    // These date-only pickers may carry a time (some Filament versions keep the current time on defaults).
    // Keep only the date part; strict period validation still owns parsing the date itself.
    foreach (['from', 'until'] as $boundary) {
      $value = $state[$boundary];
      if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} (?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/D', $value)) {
        $state[$boundary] = substr($value, 0, 10);
      }
    }

    return $state;
  }

  /** @return array{from: mixed, until: mixed} */
  public function periodFilter(): array
  {
    $state = $this->currentFilters();

    return ['from' => $state['from'], 'until' => $state['until']];
  }

  public function setPeriod(int $days): void
  {
    $this->authorizeContext();
    abort_unless(in_array($days, [1, 7, 30], true), 422);
    $today = CarbonImmutable::now(HostDates::timezone());
    $this->fillFilters(['preset' => (string) $days, 'from' => $today->subDays($days - 1)->toDateString(), 'until' => $today->toDateString()]);
  }

  public function resetUsageFilters(): void
  {
    $this->authorizeContext();
    $this->removeTableFilters();
    $this->fillFilters(['actor' => null, 'field' => null]);
    $this->setPeriod(7);
  }

  /** @param array<string, mixed> $changes */
  protected function fillFilters(array $changes): void
  {
    $this->filters = array_replace($this->currentFilters(), $changes);
    $this->resetPage();
  }

  /** Rankings ask to narrow the whole screen to one user or field. Unknown values only ever narrow results to nothing. */
  #[On('ai-writer-usage-explore')]
  public function explore(string $kind, string $value): void
  {
    $this->authorizeContext();
    abort_unless(in_array($kind, ['actor', 'field'], true) && strlen($value) <= 255, 422);
    $this->fillFilters([$kind => $value]);
  }

  public function table(Table $table): Table
  {
    return $table
      ->query(fn (): Builder => $this->filteredStatistics())
      ->defaultSort('id', 'desc')
      ->paginated([10, 25, 50])
      ->defaultPaginationPageOption(25)
      ->deferFilters(false)
      ->filtersLayout(FiltersLayout::Dropdown)
      ->filtersFormColumns(1)
      ->recordAction('details')
      ->recordActions([
        Action::make('details')->label(__('filament-ai-writer::statistics.table.details'))->link()->slideOver()->modalWidth('4xl')
          ->modalHeading(__('filament-ai-writer::statistics.details.heading'))->modalSubmitAction(false)->modalCancelActionLabel(__('filament-ai-writer::statistics.details.close'))
          ->extraModalFooterActions([
            $this->neighbourAction('previous', Heroicon::ChevronLeft, '>'),
            $this->neighbourAction('next', Heroicon::ChevronRight, '<'),
          ])
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
          ->formatStateUsing(fn (UsageAttempt $record): string => UsageStatistics::splitActorLabel($this->actorLabel($record))[0])
          ->description(fn (UsageAttempt $record): ?string => UsageStatistics::splitActorLabel($this->actorLabel($record))[1])->wrap(),
        TextColumn::make('status')->label(__('filament-ai-writer::statistics.table.result'))->badge()
          ->formatStateUsing(fn (string $state): string => __('filament-ai-writer::statistics.status.' . (in_array($state, ['delivered', 'failed'], true) ? $state : 'active')))
          ->color(fn (string $state): string => match ($state) { 'delivered' => 'success', 'failed' => 'danger', default => 'warning' }),
        TextColumn::make('input_tokens')->label(__('filament-ai-writer::statistics.table.input_tokens'))->numeric()->suffix(' tok')->placeholder('—')->alignEnd(),
        TextColumn::make('output_tokens')->label(__('filament-ai-writer::statistics.table.output_tokens'))->numeric()->suffix(' tok')->placeholder('—')->alignEnd(),
        TextColumn::make('cost_usd')->label(__('filament-ai-writer::statistics.table.cost', ['currency' => Currency::code()]))->placeholder('—')->alignEnd()
          ->formatStateUsing(fn (string $state): string => Currency::format($state))
          ->tooltip(fn (UsageAttempt $record): string => __('filament-ai-writer::statistics.source.' . match ($record->cost_source) { 'provider' => 'provider', 'estimated' => 'estimated', default => 'none' })),
        TextColumn::make('duration_ms')->label(__('filament-ai-writer::statistics.table.duration'))->placeholder('—')->alignEnd()
          ->formatStateUsing(fn (int $state): string => number_format($state / 1000, 2) . ' s'),
      ])
      ->filters([
        SelectFilter::make('status')->label(__('filament-ai-writer::statistics.filter.result'))->options(['delivered' => __('filament-ai-writer::statistics.status.delivered'), 'failed' => __('filament-ai-writer::statistics.status.failed'), 'active' => __('filament-ai-writer::statistics.status.active')])
          ->query(fn (Builder $query, array $data): Builder => $this->statistics()->filterResult($query, $data['value'] ?? null)),
      ])
      ->emptyStateHeading(__('filament-ai-writer::statistics.table.empty'));
  }

  /**
   * Moves the open details to the row above (newer) or below (older) in the table's current filters.
   * Neighbours come from the same scoped, filtered query as the table, never from the client.
   */
  protected function neighbourAction(string $name, Heroicon $icon, string $operator): Action
  {
    $neighbour = fn (UsageAttempt $record): ?int => $this->getFilteredTableQuery()->reorder()
      ->where('id', $operator, $record->getKey())->orderBy('id', $operator === '>' ? 'asc' : 'desc')->value('id');

    return Action::make($name)->label(__('filament-ai-writer::statistics.details.' . $name))->icon($icon)->color('gray')
      ->disabled(fn (UsageAttempt $record): bool => $neighbour($record) === null)
      ->action(function (UsageAttempt $record) use ($neighbour): void {
        $id = $neighbour($record);
        if ($id !== null) {
          $this->replaceMountedAction('details', context: ['table' => true, 'recordKey' => (string) $id]);
        }
      });
  }

  /** The panel's attempts narrowed by the header period, user and field; the table adds its own result filter. */
  protected function filteredStatistics(): Builder
  {
    $query = $this->statistics()->filterPeriod($this->statistics()->query(), $this->periodFilter());
    ['actor' => $actor, 'field' => $field] = $this->currentFilters();
    if (filled($field)) {
      $query->where('field', $field);
    }

    return filled($actor) ? $this->statistics()->filterActor($query, (string) $actor) : $query;
  }

  public function getTableRecordsPerPage(): int
  {
    return in_array($this->tableRecordsPerPage, [10, 25, 50, '10', '25', '50'], true)
      ? (int) $this->tableRecordsPerPage : 25;
  }

  protected function actorLabel(UsageAttempt $record): string
  {
    return $this->statistics()->actorLabel($record->actor_type, $record->actor_id);
  }

  public function content(Schema $schema): Schema
  {
    return $schema->components([EmbeddedTable::make()]);
  }

  /**
   * The widgets above the table. Built on every render (the content schema is cached per request, before an action can
   * change the filters) and keyed so that a key never repeats: Livewire keeps every child key it has already seen and
   * would reuse the stale, still-filtered child when a cleared filter brings an old key back.
   */
  protected function widgetsSchema(array $filters): Schema
  {
    $hash = hash('sha256', json_encode($filters));
    if ($hash !== $this->widgetFiltersHash) {
      $this->widgetFiltersHash = $hash;
      $this->widgetEpoch++;
    }
    $key = $this->widgetEpoch . $hash;
    $widget = fn (string $class): Livewire => Livewire::make($class, ['panelId' => $this->panelId, 'sharedFilters' => $filters])->key($class . $key);

    return Schema::make($this)->components([
      $widget(UsageOverviewStats::class),
      Grid::make(['default' => 1, 'lg' => 2])->schema([$widget(UsageTopUsers::class), $widget(UsageTopFields::class)]),
      $widget(UsageCountChart::class),
    ]);
  }

  /** @return array<string, mixed> Only visible filters may affect the overview, the rankings or the independently mounted chart. */
  protected function widgetFilters(): array
  {
    $filters = array_intersect_key($this->tableFilters ?? [], array_flip(['status']));
    $filters['period'] = $this->periodFilter();
    foreach (['actor', 'field'] as $key) {
      if (filled($value = $this->currentFilters()[$key])) {
        $filters[$key] = ['value' => $value];
      }
    }

    return $filters;
  }

  protected function getViewData(): array
  {
    // Not `filters`: that name is the public header-filter property.
    $filters = $this->widgetFilters();

    return ['usageFilters' => $filters, 'widgets' => $this->widgetsSchema($filters)];
  }
}
