<?php

namespace PdroLucas\FilamentAiWriter\Pages;

use Filament\Actions\Action;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use PdroLucas\FilamentAiWriter\Actions\ManageAiUserAction;
use PdroLucas\FilamentAiWriter\Support\ControlFields;
use PdroLucas\FilamentAiWriter\Support\HostDates;
use PdroLucas\FilamentAiWriter\Support\Reason;
use PdroLucas\FilamentAiWriter\Controls\ActorIdentity;
use PdroLucas\FilamentAiWriter\Credits\Credits;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Concerns\UsesPluginNavigation;
use PdroLucas\FilamentAiWriter\Controls\ControlContext;
use PdroLucas\FilamentAiWriter\Models\ControlAudit;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class AiControlsPage extends Page implements HasTable
{
  use UsesPluginNavigation;

  use InteractsWithTable {
    updatedTableFilters as protected updatedTableFiltersByFilament;
  }

  protected static ?string $title = null;
  protected static ?string $slug = 'ai-writer-controls';

  protected static function pluginPage(): string
  {
    return 'controls';
  }

  protected const DIRECTORY_DOCS_URL = 'https://github.com/pdro-lucas/filament-ai-writer/blob/main/docs/integration.md#panel-setup-and-a-scoped-actor-directory';
  protected string $view = 'filament-ai-writer::ai-controls';

  #[Locked]
  public array $controlSnapshot;

  /** Bound to the URL (?section=) so a section opens from a link, survives a reload and follows the browser's back button. */
  #[Url(as: 'section', history: true)]
  public string $section = 'settings';

  #[Locked]
  public array $taskTableState = [];

  protected bool $directoryError = false;

  public array $creditData = [];
  public array $rateData = [];
  public array $concurrencyData = [];
  public array $failureData = [];

  protected array $auditIdentities = [];

  public static function canAccess(): bool
  {
    $plugin = AiWriterPlugin::get();
    return $plugin->isControlsEnabled() && $plugin->canManage();
  }

  /**
   * The sections of this page, in display order, as key => label. Override it in a subclass to remove or reorder sections;
   * a removed section cannot be opened from the URL. Known keys: settings, users, activity.
   *
   * @return array<string, string>
   */
  protected function sections(): array
  {
    return ['settings' => __('filament-ai-writer::controls.section.settings'), 'users' => __('filament-ai-writer::controls.section.users'), 'activity' => __('filament-ai-writer::controls.section.activity')];
  }

  /** Sections this administrator can open now: Users also needs a configured actor directory (and usersTab()). */
  protected function availableSections(): array
  {
    $sections = $this->sections();
    if (!AiWriterPlugin::get()->hasUsersTab()) {
      unset($sections['users']);
    }
    return $sections;
  }

  protected function defaultSection(): string
  {
    return (string) (array_key_first($this->availableSections()) ?? 'settings');
  }

  public function mount(): void
  {
    abort_unless(static::canAccess(), 403);
    // No section in the URL opens the first one; a value that is unknown, removed or not allowed falls back to it too.
    $requested = request()->has('section');
    $corrected = $requested && !array_key_exists($this->section, $this->availableSections());
    if (!$requested || $corrected) {
      $this->section = $this->defaultSection();
    }
    if ($corrected) {
      // Replace the misleading link with the section actually shown.
      throw new HttpResponseException(new RedirectResponse(static::getUrl(['section' => $this->section])));
    }
    $this->controlSnapshot = AiWriterPlugin::get()->controlContext()->snapshot();
    $this->fillSettings();
  }

  public function hydrate(): void
  {
    $this->context();
  }

  protected function context(): ControlContext
  {
    abort_unless(static::canAccess(), 403);
    abort_unless(array_key_exists($this->section, $this->availableSections()), 403);
    $context = AiWriterPlugin::get()->controlContext();
    abort_unless($context->snapshot() === $this->controlSnapshot, 403);
    return $context;
  }

  public function enableAction(): Action
  {
    return $this->availabilityAction('enable', true);
  }

  public function disableAction(): Action
  {
    return $this->availabilityAction('disable', false);
  }

  /** Availability lives in the page header (danger only for pausing); what it shows is read lazily, as actions mutate it. */
  protected function getHeaderActions(): array
  {
    return [$this->disableAction(), $this->enableAction()];
  }

  public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
  {
    if ($this->section !== 'settings') {
      return null;
    }

    return AiWriterPlugin::get()->controlsService()->enabled($this->context()) ? __('filament-ai-writer::controls.availability.enabled') : __('filament-ai-writer::controls.availability.disabled');
  }

  protected function availabilityAction(string $name, bool $enabled): Action
  {
    return Action::make($name)->label($enabled ? __('filament-ai-writer::controls.availability.enable') : __('filament-ai-writer::controls.availability.pause'))->color($enabled ? 'gray' : 'danger')
      ->visible(fn (): bool => $this->section === 'settings' && AiWriterPlugin::get()->controlsService()->enabled($this->context()) !== $enabled)
      // Pausing always confirms (it stops everyone); enabling is one click unless requireReason() asks for a reason here.
      ->modal(fn (): bool => !$enabled || Reason::isRequired('availability'))
      ->modalHeading($enabled ? __('filament-ai-writer::controls.availability.enable') : __('filament-ai-writer::controls.availability.pause'))
      ->modalDescription($enabled ? __('filament-ai-writer::controls.availability.modal') : __('filament-ai-writer::controls.availability.pause_modal'))
      ->modalSubmitActionLabel($enabled ? __('filament-ai-writer::controls.availability.enable') : __('filament-ai-writer::controls.availability.pause'))
      ->modalSubmitAction(fn (Action $action) => $enabled ? $action : $action->color('danger'))
      ->schema(fn (): array => Reason::isRequired('availability') ? [$this->reasonField('availability')] : [])
      ->action(function (array $data) use ($enabled): void {
        $context = $this->context();
        $this->saveChange(fn () => AiWriterPlugin::get()->controlsService()->setEnabled($context, $enabled, $data['reason'] ?? null));
      });
  }

  public function showSection(string $section): void
  {
    $this->context();
    abort_unless(array_key_exists($section, $this->sections()), 404);
    abort_unless(array_key_exists($section, $this->availableSections()), 403);
    $this->leaveSection();
    $this->section = $section;
    $this->enterSection();
  }

  /** Runs when the client sets the URL-bound property (browser back/forward): same checks as showSection(). */
  public function updatingSection(mixed $section): void
  {
    $this->context();
    abort_unless(is_string($section) && array_key_exists($section, $this->availableSections()), 403);
    $this->leaveSection();
  }

  public function updatedSection(): void
  {
    $this->enterSection();
  }

  protected function leaveSection(): void
  {
    $this->taskTableState[$this->section] = ['search' => $this->tableSearch, 'sort' => $this->tableSort,
      'perPage' => $this->getTableRecordsPerPage(), 'page' => $this->getTablePage()];
    if ($this->mountedActions !== []) { $this->unmountAction(cancelParentActions: true); }
  }

  protected function enterSection(): void
  {
    $state = $this->taskTableState[$this->section] ?? [];
    $this->tableSearch = $state['search'] ?? '';
    $this->tableSort = $state['sort'] ?? null;
    $this->tableRecordsPerPage = $state['perPage'] ?? 10;
    $this->tableFilters = null;
    $this->tableDeferredFilters = null;
    $this->tableColumnSearches = [];
    $this->tableGrouping = null;
    $this->resetTable();
    $this->setPage($state['page'] ?? 1, $this->getTablePaginationPageName());
  }

  /** A secondary action beside the current-period line: starting a new period for everyone is destructive, so it confirms. */
  public function renewAction(): Action
  {
    return Action::make('renew')->label(__('filament-ai-writer::controls.renew.label'))->color('gray')->outlined()->size('sm')
      ->visible(fn (): bool => $this->quotaLimitConfigured())
      ->modalHeading(__('filament-ai-writer::controls.renew.heading'))
      ->modalDescription(__('filament-ai-writer::controls.renew.description'))
      ->modalSubmitActionLabel(__('filament-ai-writer::controls.renew.confirm'))
      ->modalSubmitAction(fn (Action $action) => $action->color('danger'))
      ->modal()->schema([$this->reasonField('quota_renew')])->action(function (array $data): void {
        $this->saveChange(fn () => AiWriterPlugin::get()->credits()->renew($this->context(), $data['reason'] ?? null));
      });
  }

  protected function quotaLimitConfigured(): bool
  {
    $policy = AiWriterPlugin::get()->credits()->policy($this->context());

    return $policy['enabled'] && $policy['limit'] !== null;
  }

  /** The current period in one line, read when rendered so it follows a save or a renewal in the same response. */
  protected function currentPeriodText(): string
  {
    $policy = AiWriterPlugin::get()->credits()->policy($this->context());
    // A schedule picked but not saved yet: say when the period would end, from the same cut function the save uses.
    $selected = $this->creditData['renewal'] ?? null;
    if (is_string($selected) && in_array($selected, ['manual', 'daily', 'weekly', 'monthly'], true) && $selected !== $policy['renewal']) {
      $cut = AiWriterPlugin::get()->credits()->nextCut($selected, $policy['period_timezone']);

      return $cut === null ? __('filament-ai-writer::controls.credits.preview_manual') : __('filament-ai-writer::controls.credits.preview', ['until' => HostDates::dateTimeToMinutes($cut)]);
    }
    $since = $policy['period_started_at'] === null ? null : HostDates::dateOf($policy['period_started_at']);
    if ($policy['renews_at'] === null) {
      return __('filament-ai-writer::controls.credits.period_manual', ['since' => $since ?? '—']);
    }

    return __('filament-ai-writer::controls.credits.period', ['since' => $since ?? '—', 'until' => HostDates::dateTimeToMinutes($policy['renews_at'])]);
  }

  protected function fillSettings(): void
  {
    $plugin = AiWriterPlugin::get();
    $context = $this->context();
    $this->creditForm->fill($plugin->credits()->policy($context));
    $abuse = $plugin->abuse()->policy($context);
    foreach (['rate', 'concurrency', 'failure'] as $group) {
      $this->{$group . 'Form'}->fill($abuse);
    }
  }

  protected function integerField(string $name, string $label, int $min, ?string $unit = null): TextInput
  {
    return ControlFields::integerField($name, $label, $min, $unit);
  }

  protected function parseInteger(mixed $value, string $field, int $min = 0): int
  {
    return ControlFields::parseInteger($value, $field, $min);
  }

  public function creditForm(Schema $schema): Schema
  {
    return $schema->statePath('creditData')->columns(2)->components([
      Toggle::make('enabled')->label(__('filament-ai-writer::controls.form.credits.label'))->columnSpanFull(),
      $this->integerField('limit', __('filament-ai-writer::controls.form.limit'), 0, __('filament-ai-writer::controls.field.unit.credits'))->required(),
      Select::make('renewal')->label(__('filament-ai-writer::controls.form.renewal.label'))->options($this->renewalOptions())->required()->native()->live()->placeholder(__('filament-ai-writer::controls.form.renewal.placeholder')),
    ]);
  }

  public function rateForm(Schema $schema): Schema
  {
    return $schema->statePath('rateData')->columns(2)->components([
      Toggle::make('rate_enabled')->label(__('filament-ai-writer::controls.form.rate.label'))->columnSpanFull(),
      $this->integerField('rate_limit', __('filament-ai-writer::controls.form.rate.limit'), 1),
      $this->integerField('rate_window_seconds', __('filament-ai-writer::controls.form.rate.window'), 1, __('filament-ai-writer::controls.field.unit.seconds')),
    ]);
  }

  public function concurrencyForm(Schema $schema): Schema
  {
    return $schema->statePath('concurrencyData')->columns(2)->components([
      Toggle::make('concurrency_enabled')->label(__('filament-ai-writer::controls.form.concurrency.label'))->columnSpanFull(),
      $this->integerField('concurrency_limit', __('filament-ai-writer::controls.form.concurrency.limit'), 1),
    ]);
  }

  public function failureForm(Schema $schema): Schema
  {
    return $schema->statePath('failureData')->columns(3)->components([
      Toggle::make('failure_enabled')->label(__('filament-ai-writer::controls.form.failure.label'))->columnSpanFull(),
      $this->integerField('failure_limit', __('filament-ai-writer::controls.form.failure.limit'), 1),
      $this->integerField('failure_window_seconds', __('filament-ai-writer::controls.form.failure.window'), 1, __('filament-ai-writer::controls.field.unit.seconds')),
      $this->integerField('failure_pause_seconds', __('filament-ai-writer::controls.form.failure.pause'), 1, __('filament-ai-writer::controls.field.unit.seconds')),
    ]);
  }

  protected function settingValues(string $group, array $data): array
  {
    $fields = match ($group) {
      'credit' => ['enabled', 'limit', 'renewal'],
      'rate' => ['rate_enabled', 'rate_limit', 'rate_window_seconds'],
      'concurrency' => ['concurrency_enabled', 'concurrency_limit'],
      'failure' => ['failure_enabled', 'failure_limit', 'failure_window_seconds', 'failure_pause_seconds'],
      default => throw ValidationException::withMessages(['settings' => __('filament-ai-writer::controls.validation.group')]),
    };
    $values = [];
    foreach ($fields as $field) {
      $value = $data[$field] ?? null;
      if ($field === 'renewal') {
        if (!in_array($value, ['manual', 'daily', 'weekly', 'monthly'], true)) {
          throw ValidationException::withMessages(['creditData.renewal' => __('filament-ai-writer::controls.validation.renewal_short')]);
        }
      } elseif ($field === 'enabled' || str_ends_with($field, '_enabled')) {
        if (!is_bool($value)) {
          throw ValidationException::withMessages([$group . 'Data.' . $field => __('filament-ai-writer::controls.validation.on_off')]);
        }
      } else {
        $value = ($value === null || $value === '') && $group !== 'credit' ? null : $this->parseInteger($value, $group . 'Data.' . $field, $group === 'credit' ? 0 : 1);
      }
      $values[$field] = $value;
    }
    if ($group !== 'credit' && $values[$group . '_enabled'] && in_array(null, $values, true)) {
      throw ValidationException::withMessages([$group . 'Data.' . $fields[1] => __('filament-ai-writer::controls.validation.threshold_all')]);
    }
    return $values;
  }

  /** @var array<string, string> group => audited action */
  protected const GROUP_ACTIONS = ['credit' => 'quota_policy', 'rate' => 'abuse_policy', 'concurrency' => 'abuse_policy', 'failure' => 'abuse_policy'];

  /**
   * The groups whose validated form values differ from what is stored now, group => values. Throws the strict
   * validation of any group, changed or not, so a bad field is reported where it is.
   *
   * @param array<string, array<string, mixed>>|null $submitted group => raw values; null reads the open forms
   * @return array<string, array<string, mixed>>
   */
  protected function changedSettings(?array $submitted = null): array
  {
    $context = $this->context();
    $plugin = AiWriterPlugin::get();
    $stored = ['credit' => $plugin->credits()->policy($context)] + array_fill_keys(['rate', 'concurrency', 'failure'], $plugin->abuse()->policy($context));
    $changed = [];
    foreach (array_keys(self::GROUP_ACTIONS) as $group) {
      if ($submitted !== null && !array_key_exists($group, $submitted)) {
        continue;
      }
      // Compare what is typed with what is stored first: an untouched group is never validated (a workspace that never
      // configured credits has no limit yet, and must still be able to save a frequency change) nor written.
      $raw = $submitted === null ? $this->{$group . 'Data'} : (is_array($submitted[$group]) ? $submitted[$group] : []);
      if (!$this->differsFromStored($group, $raw, $stored[$group])) {
        continue;
      }
      $changed[$group] = $this->settingValues($group, $submitted === null ? $this->{$group . 'Form'}->getState() : $raw);
    }
    return $changed;
  }

  /** @param array<string, mixed> $raw */
  protected function differsFromStored(string $group, array $raw, array $stored): bool
  {
    $fields = match ($group) {
      'credit' => ['enabled', 'limit', 'renewal'],
      'rate' => ['rate_enabled', 'rate_limit', 'rate_window_seconds'],
      'concurrency' => ['concurrency_enabled', 'concurrency_limit'],
      'failure' => ['failure_enabled', 'failure_limit', 'failure_window_seconds', 'failure_pause_seconds'],
    };
    foreach ($fields as $field) {
      if ($this->normalizeSetting($field, $raw[$field] ?? null) !== $this->normalizeSetting($field, $stored[$field] ?? null)) {
        return true;
      }
    }
    return false;
  }

  protected function normalizeSetting(string $field, mixed $value): mixed
  {
    return match (true) {
      $field === 'enabled' || str_ends_with($field, '_enabled') => (bool) $value,
      $field === 'renewal' => (string) $value,
      // Digits compare as numbers; anything else ("1.5", "1e3") stays itself so it counts as a change and fails validation.
      default => $value === null || $value === '' ? null : (is_string($value) && ctype_digit($value) ? (int) $value : $value),
    };
  }

  /** The one save button: validates every group, then asks (one reason when required) only for the groups that changed. */
  public function confirmSettings(): void
  {
    $this->context();
    $changes = $this->changedSettings();
    if ($changes === []) {
      Notification::make()->info()->title(__('filament-ai-writer::controls.save.unchanged'))->send();
      return;
    }
    // From the save button the button's own action is open: replace it rather than nest the dialog inside it.
    $this->replaceMountedAction('saveSettings', ['changes' => $changes]);
  }

  /** The audited action of the first changed group that needs a reason; null when none does. */
  protected function reasonAction(array $arguments): ?string
  {
    foreach (array_keys(is_array($arguments['changes'] ?? null) ? $arguments['changes'] : []) as $group) {
      if (isset(self::GROUP_ACTIONS[$group]) && Reason::isRequired(self::GROUP_ACTIONS[$group])) {
        return self::GROUP_ACTIONS[$group];
      }
    }
    return null;
  }

  public function saveSettingsAction(): Action
  {
    return Action::make('saveSettings')->label(__('filament-ai-writer::controls.save.label'))->modalHeading(__('filament-ai-writer::controls.save.heading'))
      ->modalDescription(fn (array $arguments): string => __('filament-ai-writer::controls.save.apply', ['selected' => $this->settingsDescription($arguments)]))
      // Saving is one click unless requireReason() asks for a reason for one of the changed groups.
      ->modal(fn (array $arguments): bool => $this->reasonAction($arguments) !== null)
      ->schema(fn (array $arguments): array => ($action = $this->reasonAction($arguments)) !== null ? [$this->reasonField($action)] : [])
      ->action(function (array $data, array $arguments): void {
        $context = $this->context();
        // Re-validate and re-diff on the server: the dialog's arguments are only a hint of what the administrator saw.
        $changes = $this->changedSettings(is_array($arguments['changes'] ?? null) ? $arguments['changes'] : []);
        if ($changes === []) {
          Notification::make()->info()->title(__('filament-ai-writer::controls.save.unchanged'))->send();
          return;
        }
        $plugin = AiWriterPlugin::get();
        $this->saveChange(function () use ($context, $changes, $data, $plugin): void {
          // Every changed group writes its own audit entry; the groups commit together or not at all.
          $plugin->credits()->connection()->transaction(function () use ($context, $changes, $data, $plugin): void {
            foreach ($changes as $group => $values) {
              if ($group === 'credit') {
                $plugin->credits()->configure($context, $values['enabled'], $values['limit'], $values['renewal'], $data['reason'] ?? null);
              } else {
                $plugin->abuse()->configure($context, $values, $data['reason'] ?? null);
              }
            }
          });
          $this->fillSettings();
        });
      });
  }

  protected function settingsDescription(array $arguments): string
  {
    try {
      $lines = [];
      foreach (is_array($arguments['changes'] ?? null) ? $arguments['changes'] : [] as $group => $values) {
        $values = $this->settingValues($group, is_array($values) ? $values : []);
        $on = ($values[$group === 'credit' ? 'enabled' : $group . '_enabled'] ?? false) ? __('filament-ai-writer::controls.selected.on') : __('filament-ai-writer::controls.selected.off');
        $number = fn (string $key): string => $values[$key] === null ? __('filament-ai-writer::controls.selected.not_configured') : number_format($values[$key]);
        $lines[] = __('filament-ai-writer::controls.save.name.' . $group) . ': ' . match ($group) {
          'credit' => __('filament-ai-writer::controls.selected.credit', ['on' => $on, 'limit' => $number('limit'), 'renewal' => $this->renewalOptions()[$values['renewal']] ?? $values['renewal']]),
          'rate' => __('filament-ai-writer::controls.selected.rate', ['on' => $on, 'limit' => $number('rate_limit'), 'window' => $number('rate_window_seconds')]),
          'concurrency' => __('filament-ai-writer::controls.selected.concurrency', ['on' => $on, 'limit' => $number('concurrency_limit')]),
          'failure' => __('filament-ai-writer::controls.selected.failure', ['on' => $on, 'limit' => $number('failure_limit'), 'window' => $number('failure_window_seconds'), 'pause' => $number('failure_pause_seconds')]),
        };
      }
      // The consequences of each changed group, as before.
      $notes = array_map(fn (string $group): string => __('filament-ai-writer::controls.save.note.' . $group), array_keys(array_filter(is_array($arguments['changes'] ?? null) ? $arguments['changes'] : [], fn ($group) => isset(self::GROUP_ACTIONS[$group]), ARRAY_FILTER_USE_KEY)));

      return implode(' ', [...$lines, ...$notes]);
    } catch (ValidationException) {
      return __('filament-ai-writer::controls.save.invalid');
    }
  }

  /** @return array<string, string> */
  protected function renewalOptions(): array
  {
    return array_map(fn (string $key): string => __('filament-ai-writer::controls.renewal.' . $key), array_combine(['manual', 'daily', 'weekly', 'monthly'], ['manual', 'daily', 'weekly', 'monthly']));
  }

  protected function reasonField(string $action): Textarea
  {
    return ControlFields::reasonField($action);
  }

  protected function saveChange(\Closure $change): void
  {
    try {
      $change();
      $this->cacheSchema('settings', null);
      $this->dispatch('ai-writer-credits-changed');
      Notification::make()->success()->title(__('filament-ai-writer::controls.updated'))->send();
      $this->resetTable();
    } catch (ValidationException|AuthorizationException|HttpException $exception) {
      throw $exception;
    } catch (Throwable) {
      Notification::make()->danger()->title(__('filament-ai-writer::controls.update_failed.title'))->body(__('filament-ai-writer::controls.update_failed.body'))->send();
    }
  }

  public function table(Table $table): Table
  {
    $this->context();
    if ($this->section === 'users') {
      return $table->records(fn (?string $search): array => $this->directoryRecords($search ?? ''))->columns([
        TextColumn::make('name')->label(__('filament-ai-writer::controls.directory.user'))->description(fn (array $record): ?string => $record['email'])->wrap(),
        TextColumn::make('status')->label(__('filament-ai-writer::controls.directory.status'))->badge()
          ->formatStateUsing(fn (string $state): string => __('filament-ai-writer::credits.status.' . ($state === 'allowed' ? 'allowed' : ($state === 'blocked' ? 'blocked' : 'paused'))))
          ->color(fn (string $state): string => match ($state) { 'allowed' => 'success', 'blocked' => 'danger', default => 'warning' }),
        TextColumn::make('credits')->label(__('filament-ai-writer::controls.directory.credits'))->placeholder('—')->alignEnd(),
        TextColumn::make('requests')->label(__('filament-ai-writer::controls.directory.requests'))->numeric()->placeholder('—')->alignEnd()
          ->visible(fn (): bool => $this->showsUsage()),
        TextColumn::make('last_used')->label(__('filament-ai-writer::controls.directory.last_used'))->dateTime()->placeholder('—')
          ->visible(fn (): bool => $this->showsUsage()),
      ])->searchable(AiWriterPlugin::get()->hasActorDirectory())->searchPlaceholder(__('filament-ai-writer::controls.directory.search'))
        ->paginated(false)->description(__('filament-ai-writer::controls.directory.limit'))
        ->recordAction('manageAiUser')
        ->recordActions([ManageAiUserAction::make()->actorUsing(fn (array $record): ActorIdentity => ActorIdentity::fromToken($record['token']))])
        ->emptyStateHeading(fn (): string => !AiWriterPlugin::get()->hasActorDirectory() ? __('filament-ai-writer::controls.directory.not_configured') : ($this->directoryError ? __('filament-ai-writer::controls.directory.unavailable') : __('filament-ai-writer::controls.directory.none')))
        ->emptyStateDescription(fn (): string => !AiWriterPlugin::get()->hasActorDirectory() ? __('filament-ai-writer::controls.directory.not_configured_description') : ($this->directoryError ? __('filament-ai-writer::controls.directory.unavailable_description') : __('filament-ai-writer::controls.directory.none_description')));
    }
    return $table
      ->query(fn (): Builder => AiWriterPlugin::get()->controlsService()->audit($this->context()))
      ->heading(__('filament-ai-writer::controls.activity.heading'))->defaultSort('id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(10)
      ->deferFilters(false)->filtersLayout(FiltersLayout::Dropdown)->filtersFormColumns(1)
      ->filters($this->activityFilters())
      ->columns([
        // No format or timezone here: the host's Filament defaults (also per user) decide how dates look.
        TextColumn::make('created_at')->label(__('filament-ai-writer::controls.activity.date'))->tooltip(__('filament-ai-writer::statistics.timezone', ['timezone' => HostDates::timezone()]))->dateTime(),
        TextColumn::make('administrator_id')->label(__('filament-ai-writer::controls.activity.administrator'))->wrap()
          ->formatStateUsing(fn (ControlAudit $record): string => \PdroLucas\FilamentAiWriter\Usage\UsageStatistics::splitActorLabel($this->auditIdentity($record->administrator_type, $record->administrator_id))[0])
          ->description(fn (ControlAudit $record): ?string => \PdroLucas\FilamentAiWriter\Usage\UsageStatistics::splitActorLabel($this->auditIdentity($record->administrator_type, $record->administrator_id))[1]),
        TextColumn::make('action')->label(__('filament-ai-writer::controls.activity.change'))->formatStateUsing(fn (ControlAudit $record): string => __('filament-ai-writer::controls.activity.' . match ($record->action) {
          'availability' => $record->after['enabled'] ? 'availability_on' : 'availability_off',
          'block' => $record->after['blocked'] ? 'block_on' : 'block_off',
          'abuse_policy', 'quota_policy', 'quota_limit', 'quota_extra', 'quota_reset', 'quota_renew' => $record->action,
          default => 'default',
        })),
        TextColumn::make('actor_id')->label(__('filament-ai-writer::controls.activity.user'))->placeholder(__('filament-ai-writer::controls.activity.all_users'))->wrap()
          ->formatStateUsing(fn (ControlAudit $record): string => \PdroLucas\FilamentAiWriter\Usage\UsageStatistics::splitActorLabel($this->auditIdentity($record->actor_type, $record->actor_id))[0])
          ->description(fn (ControlAudit $record): ?string => $record->actor_type === null ? null : \PdroLucas\FilamentAiWriter\Usage\UsageStatistics::splitActorLabel($this->auditIdentity($record->actor_type, $record->actor_id))[1]),
        // Long reasons stay at two lines; the whole text is in the tooltip.
        TextColumn::make('reason')->label(__('filament-ai-writer::controls.activity.reason'))->placeholder(__('filament-ai-writer::controls.reason.none'))->wrap()->lineClamp(2)
          ->tooltip(fn (ControlAudit $record): ?string => $record->reason),
      ])->emptyStateHeading(__('filament-ai-writer::controls.activity.empty'));
  }

  /** Request counts and last use come from the usage ledger, so they follow the Statistics permission, not just this page's. */
  protected function showsUsage(): bool
  {
    $plugin = AiWriterPlugin::get();

    return $plugin->isUsageTrackingEnabled() && $plugin->canViewStatistics();
  }

  /**
   * The directory's matches (at most 50, from the host's scoped directory) with their status, credits and usage.
   * Everything beyond the directory is read for all rows together, in a fixed number of queries, and only for the
   * actors the directory returned.
   *
   * @return array<string, array<string, mixed>>
   */
  protected function directoryRecords(string $search): array
  {
    $context = $this->context();
    $plugin = AiWriterPlugin::get();
    if (!$plugin->hasActorDirectory()) { return []; }
    try {
      $actors = [];
      $labels = [];
      foreach ($plugin->searchActors($search, $context) as $token => $label) {
        $actors[$token] = ActorIdentity::fromToken($token);
        $labels[$token] = $label;
      }
      $list = array_values($actors);
      $enabled = $plugin->controlsService()->enabled($context);
      $blocked = $plugin->controlsService()->blockedAmong($context, $list);
      $credits = $plugin->credits()->overview($context, $list);
      $failurePaused = $plugin->abuse()->pausedByFailures($context, $list);
      $quotas = $plugin->credits()->quotaEnabled($context);
      $usage = $this->showsUsage() ? $this->usageByActor($context, $list) : [];
      $records = [];
      foreach ($actors as $token => $actor) {
        $key = $actor->key();
        $balance = ['enabled' => $quotas] + $credits[$key];
        $refusal = \PdroLucas\FilamentAiWriter\Schemas\AiCreditStatus::refusal($balance, ['refusal' => isset($failurePaused[$key]) ? 'failure_pause' : null], ['enabled' => $enabled, 'blocked' => isset($blocked[$key])]);
        [$name, $email] = \PdroLucas\FilamentAiWriter\Usage\UsageStatistics::splitActorLabel($labels[$token]);
        $records[$token] = ['token' => $token, 'name' => $name, 'email' => $email, 'id' => $actor->id,
          'status' => $refusal === null ? 'allowed' : ($refusal === 'blocked' ? 'blocked' : 'paused'),
          'credits' => !$quotas ? null : ($balance['limit'] === null ? __('filament-ai-writer::controls.directory.no_limit') : number_format($balance['consumed']) . ' / ' . number_format($balance['limit'])),
          'requests' => $usage[$key]['requests'] ?? ($this->showsUsage() ? 0 : null), 'last_used' => $usage[$key]['last_used'] ?? null];
      }
      return $records;
    } catch (AuthorizationException|HttpException $exception) { throw $exception; }
    catch (Throwable) { $this->directoryError = true; return []; }
  }

  /**
   * Requests in the last 30 days and the last use per listed actor: one grouped query over this panel's ledger.
   *
   * @param array<int, ActorIdentity> $actors
   * @return array<string, array{requests: int, last_used: ?\Carbon\CarbonImmutable}> by actor key
   */
  protected function usageByActor(ControlContext $context, array $actors): array
  {
    if ($actors === []) { return []; }
    $since = \Carbon\CarbonImmutable::now('UTC')->subDays(30)->toDateTimeString();
    $wanted = [];
    foreach ($actors as $actor) { $wanted[$actor->key()] = true; }
    $rows = \PdroLucas\FilamentAiWriter\Models\UsageAttempt::query()->where('panel_id', $context->panelId)
      ->whereIn('actor_id', array_map(fn (ActorIdentity $actor): string => $actor->id, $actors))
      ->selectRaw('actor_type, actor_id, SUM(CASE WHEN started_at >= ? THEN 1 ELSE 0 END) as requests, MAX(started_at) as last_used', [$since])
      ->groupBy('actor_type', 'actor_id')->toBase()->get();
    $usage = [];
    foreach ($rows as $row) {
      $key = (new ActorIdentity($row->actor_type, $row->actor_id))->key();
      if (isset($wanted[$key])) {
        $usage[$key] = ['requests' => (int) $row->requests, 'last_used' => $row->last_used === null ? null : \Carbon\CarbonImmutable::parse($row->last_used, 'UTC')];
      }
    }
    return $usage;
  }

  /** A filter value the browser sends as anything but text (an array, say) would fail inside Filament's own rendering: it becomes an unknown choice, which selects nothing. */
  public function updatedTableFilters(): void
  {
    foreach (['kind', 'administrator'] as $name) {
      $value = $this->tableFilters[$name]['value'] ?? null;
      if ($value !== null && !is_string($value)) {
        $this->tableFilters[$name]['value'] = 'invalid';
      }
    }
    $this->updatedTableFiltersByFilament();
  }

  /**
   * Period, author and kind. Each one narrows the audit query the page already scopes to this context, so none can widen
   * it; a malformed or foreign value selects nothing instead of failing.
   *
   * @return array<int, \Filament\Tables\Filters\BaseFilter>
   */
  protected function activityFilters(): array
  {
    $kinds = $this->activityKinds();

    return [
      Filter::make('period')->schema([
        DatePicker::make('from')->label(__('filament-ai-writer::statistics.filter.from'))->native(false)->format('Y-m-d')->closeOnDateSelection(),
        DatePicker::make('until')->label(__('filament-ai-writer::statistics.filter.until'))->native(false)->format('Y-m-d')->closeOnDateSelection(),
      ])->query(function (Builder $query, array $data): Builder {
        foreach (['from', 'until'] as $boundary) {
          $value = $data[$boundary] ?? null;
          if ($value === null || $value === '') { continue; }
          $day = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? CarbonImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10), HostDates::timezone()) : false;
          if ($day === false || $day->format('Y-m-d') !== substr((string) $value, 0, 10)) { return $query->whereRaw('1 = 0'); }
          $query->where('created_at', $boundary === 'from' ? '>=' : '<', ($boundary === 'from' ? $day : $day->addDay())->utc());
        }
        return $query;
      }),
      // The 50 most recent authors as options, and a name, e-mail or id search over the 200 most recent.
      SelectFilter::make('administrator')->label(__('filament-ai-writer::controls.activity.administrator'))->searchable()
        ->options(fn (): array => $this->recentAuditActors())
        ->getSearchResultsUsing(fn (string $search): array => $this->auditActors('administrator', $search))
        ->getOptionLabelUsing(fn (mixed $value): ?string => is_string($value) ? $this->auditActorLabel($value) : null)
        ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $this->whereAuditActor($query, $data['value']) : $query),
      SelectFilter::make('kind')->label(__('filament-ai-writer::controls.activity.kind'))->options($kinds)
        ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
          ? (is_string($data['value']) && array_key_exists($data['value'], $kinds) ? $query->where('action', $data['value']) : $query->whereRaw('1 = 0')) : $query),
    ];
  }

  /** @return array<string, string> Audit action => the translated change label (the two states of a switch share one entry). */
  protected function activityKinds(): array
  {
    $t = fn (string $key): string => __('filament-ai-writer::controls.activity.' . $key);

    return ['availability' => $t('availability_on') . ' / ' . $t('availability_off'), 'block' => $t('block_on') . ' / ' . $t('block_off'),
      'abuse_policy' => $t('abuse_policy'), 'quota_policy' => $t('quota_policy'), 'quota_limit' => $t('quota_limit'),
      'quota_extra' => $t('quota_extra'), 'quota_reset' => $t('quota_reset'), 'quota_renew' => $t('quota_renew')];
  }

  /** @var array<string, string>|null */
  protected ?array $recentAuditActorsCache = null;

  /** The select's options: the 50 most recent authors, read once per request however often Filament asks for them. */
  protected function recentAuditActors(): array
  {
    return $this->recentAuditActorsCache ??= $this->auditActors('administrator');
  }

  /**
   * Authors in this scope's audit rows, most recent first, labelled as the column is. Without a search: the 50 most recent.
   * With one: the 200 most recent are resolved and kept when their name, e-mail, type or id contains the text.
   *
   * @return array<string, string>
   */
  protected function auditActors(string $role, string $search = ''): array
  {
    $search = trim(mb_substr($search, 0, 100));
    $rows = AiWriterPlugin::get()->controlsService()->audit($this->context())->reorder()->whereNotNull($role . '_id')
      ->select([$role . '_type', $role . '_id'])->selectRaw('MAX(id) AS last_id')->groupBy($role . '_type', $role . '_id')
      ->orderByDesc('last_id')->limit($search === '' ? 50 : 200)->get();
    $options = [];
    foreach ($rows as $row) {
      [$type, $id] = [$row->{$role . '_type'}, $row->{$role . '_id'}];
      $label = $this->auditIdentity($type, $id);
      if ($search === '' || mb_stripos($label, $search) !== false || mb_stripos($id, $search) !== false || mb_stripos($type, $search) !== false) {
        $options[\PdroLucas\FilamentAiWriter\Usage\UsageStatistics::actorKey($type, $id)] = $label;
      }
      if (count($options) === 50) { break; }
    }
    return $options;
  }

  protected function auditActorLabel(string $key): ?string
  {
    $actor = json_decode(base64_decode($key, true) ?: '', true);

    return is_array($actor) && array_is_list($actor) && count($actor) === 2 && is_string($actor[0]) && is_string($actor[1]) ? $this->auditIdentity($actor[0], $actor[1]) : null;
  }

  protected function whereAuditActor(Builder $query, mixed $key): Builder
  {
    $actor = is_string($key) ? json_decode(base64_decode($key, true) ?: '', true) : null;

    return is_array($actor) && array_is_list($actor) && count($actor) === 2 && is_string($actor[0]) && is_string($actor[1])
      ? $query->where('administrator_type', $actor[0])->where('administrator_id', $actor[1]) : $query->whereRaw('1 = 0');
  }

  public function getTableRecordsPerPage(): int
  {
    return in_array($this->tableRecordsPerPage, [10, 25, 50, '10', '25', '50'], true) ? (int) $this->tableRecordsPerPage : 10;
  }

  protected function auditIdentity(?string $type, ?string $id): string
  {
    $context = $this->context();
    if ($type === null || $id === null) { return __('filament-ai-writer::controls.activity.all_users'); }
    $token = (new ActorIdentity($type, $id))->token();
    if (!array_key_exists($token, $this->auditIdentities)) {
      try {
        $actor = AiWriterPlugin::get()->resolveActor($token, $context);
        $this->auditIdentities[$token] = $actor === null || $actor->label === '' ? $id : $actor->label;
      }
      catch (Throwable) { $this->auditIdentities[$token] = $id; }
    }
    return $this->auditIdentities[$token];
  }

  public function settings(Schema $schema): Schema
  {
    $context = $this->context();
    $plugin = AiWriterPlugin::get();
    $policy = $plugin->credits()->policy($context);
    return $schema->components([
      Section::make(__('filament-ai-writer::controls.directory.heading'))->aside()->compact()
        ->description(__('filament-ai-writer::controls.directory.description'))
        ->schema([Actions::make([Action::make('directoryDocs')->label(__('filament-ai-writer::controls.directory.setup'))->link()
          ->url(self::DIRECTORY_DOCS_URL, shouldOpenInNewTab: true)])->key('directoryActions')])
        ->visible(!$plugin->hasActorDirectory() && $plugin->canManage()),
      Section::make(__('filament-ai-writer::controls.credits.heading'))->aside()->compact()->description(__('filament-ai-writer::controls.credits.description', ['state' => $policy['enabled'] ? __('filament-ai-writer::controls.credits.quotas_on') : __('filament-ai-writer::controls.credits.quotas_off')]))
        ->schema([
          EmbeddedSchema::make('creditForm'),
          // Hidden when quotas are off or no limit is set; both parts read the current state when rendered.
          \Filament\Schemas\Components\Flex::make([
            \Filament\Infolists\Components\TextEntry::make('current_period')->label(__('filament-ai-writer::controls.credits.heading'))->hiddenLabel()->state(fn (): string => $this->currentPeriodText()),
            Actions::make([$this->renewAction()])->key('creditActions')->grow(false),
          ])->verticalAlignment(\Filament\Support\Enums\VerticalAlignment::Center)->visible(fn (): bool => $this->quotaLimitConfigured()),
        ]),
      ...array_map(fn (string $group, string $heading, string $description): Section => Section::make($heading)->aside()->compact()->description($description)->schema([
        EmbeddedSchema::make($group . 'Form'),
      ]), ['rate', 'concurrency', 'failure'], [__('filament-ai-writer::controls.form.rate.heading'), __('filament-ai-writer::controls.form.concurrency.heading'), __('filament-ai-writer::controls.form.failure.heading')], [
        __('filament-ai-writer::controls.form.rate.description'), __('filament-ai-writer::controls.form.concurrency.description'), __('filament-ai-writer::controls.form.failure.description'),
      ]),
      // One button for the whole page: it saves only the groups that changed.
      Actions::make([Action::make('saveAll')->label(__('filament-ai-writer::controls.save.label'))->action(fn () => $this->confirmSettings())])->key('saveActions')->alignEnd(),
    ]);
  }

  protected function getViewData(): array
  {
    $this->context();
    return ['sections' => $this->availableSections()];
  }
}
