<?php

namespace PdroLucas\FilamentAiWriter\Pages;

use Filament\Actions\Action;
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

  use InteractsWithTable;

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

  protected function availabilityAction(string $name, bool $enabled): Action
  {
    return Action::make($name)->label($enabled ? __('filament-ai-writer::controls.availability.enable') : __('filament-ai-writer::controls.availability.pause'))->color($enabled ? 'primary' : 'danger')
      ->visible(fn (): bool => AiWriterPlugin::get()->controlsService()->enabled($this->context()) !== $enabled)
      // One click by default; a dialog only appears when requireReason() asks for a reason here.
      ->modal(fn (): bool => Reason::isRequired('availability'))
      ->modalHeading($enabled ? __('filament-ai-writer::controls.availability.enable') : __('filament-ai-writer::controls.availability.pause'))->modalDescription(__('filament-ai-writer::controls.availability.modal'))
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

  public function renewAction(): Action
  {
    return Action::make('renew')->label(__('filament-ai-writer::controls.renew.label'))->color('danger')
      ->modalHeading(__('filament-ai-writer::controls.renew.heading'))
      ->modalDescription(__('filament-ai-writer::controls.renew.description'))
      ->modal()->schema([$this->reasonField('quota_renew')])->action(function (array $data): void {
        $this->saveChange(fn () => AiWriterPlugin::get()->credits()->renew($this->context(), $data['reason'] ?? null));
      });
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

  protected function integerField(string $name, string $label, int $min): TextInput
  {
    return ControlFields::integerField($name, $label, $min);
  }

  protected function parseInteger(mixed $value, string $field, int $min = 0): int
  {
    return ControlFields::parseInteger($value, $field, $min);
  }

  public function creditForm(Schema $schema): Schema
  {
    return $schema->statePath('creditData')->columns(2)->components([
      Toggle::make('enabled')->label(__('filament-ai-writer::controls.form.credits.label'))->columnSpanFull()
        ->helperText(__('filament-ai-writer::controls.form.credits.help'))
        ->hintAction($this->informationAction('credits', __('filament-ai-writer::controls.form.credits.label'), __('filament-ai-writer::controls.form.credits.info'))),
      $this->integerField('limit', __('filament-ai-writer::controls.form.limit'), 0)->required(),
      Select::make('renewal')->label(__('filament-ai-writer::controls.form.renewal.label'))->options($this->renewalOptions())->required()->native()->placeholder(__('filament-ai-writer::controls.form.renewal.placeholder'))
        ->helperText(__('filament-ai-writer::controls.form.renewal.help'))
        ->hintAction($this->informationAction('renewal', __('filament-ai-writer::controls.form.renewal.info_label'), __('filament-ai-writer::controls.form.renewal.info'))),
    ]);
  }

  public function rateForm(Schema $schema): Schema
  {
    return $schema->statePath('rateData')->columns(2)->components([
      Toggle::make('rate_enabled')->label(__('filament-ai-writer::controls.form.rate.label'))->columnSpanFull()
        ->helperText(__('filament-ai-writer::controls.form.rate.help'))
        ->hintAction($this->informationAction('frequency', __('filament-ai-writer::controls.form.rate.info_label'), __('filament-ai-writer::controls.form.rate.info'))),
      $this->integerField('rate_limit', __('filament-ai-writer::controls.form.rate.limit'), 1),
      $this->integerField('rate_window_seconds', __('filament-ai-writer::controls.form.rate.window'), 1),
    ]);
  }

  public function concurrencyForm(Schema $schema): Schema
  {
    return $schema->statePath('concurrencyData')->columns(2)->components([
      Toggle::make('concurrency_enabled')->label(__('filament-ai-writer::controls.form.concurrency.label'))->columnSpanFull()
        ->helperText(__('filament-ai-writer::controls.form.concurrency.help'))
        ->hintAction($this->informationAction('simultaneous', __('filament-ai-writer::controls.form.concurrency.heading'), __('filament-ai-writer::controls.form.concurrency.info'))),
      $this->integerField('concurrency_limit', __('filament-ai-writer::controls.form.concurrency.limit'), 1),
    ]);
  }

  public function failureForm(Schema $schema): Schema
  {
    return $schema->statePath('failureData')->columns(3)->components([
      Toggle::make('failure_enabled')->label(__('filament-ai-writer::controls.form.failure.label'))->columnSpanFull()
        ->helperText(__('filament-ai-writer::controls.form.failure.help'))
        ->hintAction($this->informationAction('failures', __('filament-ai-writer::controls.form.failure.info_label'), __('filament-ai-writer::controls.form.failure.info'))),
      $this->integerField('failure_limit', __('filament-ai-writer::controls.form.failure.limit'), 1),
      $this->integerField('failure_window_seconds', __('filament-ai-writer::controls.form.failure.window'), 1),
      $this->integerField('failure_pause_seconds', __('filament-ai-writer::controls.form.failure.pause'), 1),
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

  public function confirmSettings(string $group): void
  {
    $this->context();
    abort_unless(in_array($group, ['credit', 'rate', 'concurrency', 'failure'], true), 404);
    $values = $this->settingValues($group, $this->{$group . 'Form'}->getState());
    $this->mountAction('saveSettings', ['group' => $group, 'settings' => $values]);
  }

  public function saveSettingsAction(): Action
  {
    return Action::make('saveSettings')->label(__('filament-ai-writer::controls.save.label'))->modalHeading(__('filament-ai-writer::controls.save.heading'))
      ->modalDescription(fn (array $arguments): string => __('filament-ai-writer::controls.save.apply', ['what' => __('filament-ai-writer::controls.save.' . (in_array($arguments['group'] ?? '', ['credit', 'rate', 'concurrency', 'failure'], true) ? $arguments['group'] : 'default')), 'selected' => $this->settingsDescription($arguments)]))
      // Saving settings is one click unless requireReason() asks for a reason here.
      ->modal(fn (array $arguments): bool => Reason::isRequired($this->settingsAction($arguments)))
      ->schema(fn (array $arguments): array => Reason::isRequired($this->settingsAction($arguments)) ? [$this->reasonField($this->settingsAction($arguments))] : [])
      ->action(function (array $data, array $arguments): void {
        $context = $this->context();
        $group = $arguments['group'] ?? '';
        $values = $this->settingValues($group, is_array($arguments['settings'] ?? null) ? $arguments['settings'] : []);
        $this->saveChange(function () use ($context, $group, $values, $data): void {
          if ($group === 'credit') {
            AiWriterPlugin::get()->credits()->configure($context, $values['enabled'], $values['limit'], $values['renewal'], $data['reason'] ?? null);
          } else {
            AiWriterPlugin::get()->abuse()->configure($context, $values, $data['reason'] ?? null);
          }
          $this->fillSettings();
        });
      });
  }

  protected function settingsDescription(array $arguments): string
  {
    try {
      $group = $arguments['group'] ?? '';
      $values = $this->settingValues($group, is_array($arguments['settings'] ?? null) ? $arguments['settings'] : []);
      $on = ($values[$group === 'credit' ? 'enabled' : $group . '_enabled'] ?? false) ? __('filament-ai-writer::controls.selected.on') : __('filament-ai-writer::controls.selected.off');
      $number = fn (string $key): string => $values[$key] === null ? __('filament-ai-writer::controls.selected.not_configured') : number_format($values[$key]);

      return __('filament-ai-writer::controls.selected.prefix') . match ($group) {
        'credit' => __('filament-ai-writer::controls.selected.credit', ['on' => $on, 'limit' => $number('limit'), 'renewal' => $this->renewalOptions()[$values['renewal']] ?? $values['renewal']]),
        'rate' => __('filament-ai-writer::controls.selected.rate', ['on' => $on, 'limit' => $number('rate_limit'), 'window' => $number('rate_window_seconds')]),
        'concurrency' => __('filament-ai-writer::controls.selected.concurrency', ['on' => $on, 'limit' => $number('concurrency_limit')]),
        'failure' => __('filament-ai-writer::controls.selected.failure', ['on' => $on, 'limit' => $number('failure_limit'), 'window' => $number('failure_window_seconds'), 'pause' => $number('failure_pause_seconds')]),
      };
    } catch (ValidationException) {
      return __('filament-ai-writer::controls.save.invalid');
    }
  }

  /** @return array<string, string> */
  protected function renewalOptions(): array
  {
    return array_map(fn (string $key): string => __('filament-ai-writer::controls.renewal.' . $key), array_combine(['manual', 'daily', 'weekly', 'monthly'], ['manual', 'daily', 'weekly', 'monthly']));
  }

  protected function informationAction(string $name, string $label, string $details): Action
  {
    return ControlFields::informationAction($name, $label, $details);
  }

  protected function reasonField(string $action): Textarea
  {
    return ControlFields::reasonField($action);
  }

  /** The audited action behind a settings save: credit settings are the quota policy, the rest abuse protection. */
  protected function settingsAction(array $arguments): string
  {
    return ($arguments['group'] ?? '') === 'credit' ? 'quota_policy' : 'abuse_policy';
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
      return $table->records(function (?string $search): array {
        $context = $this->context();
        $plugin = AiWriterPlugin::get();
        if (!$plugin->hasActorDirectory()) { return []; }
        try {
          $records = [];
          foreach ($plugin->searchActors($search ?? '', $context) as $token => $label) {
            $actor = ActorIdentity::fromToken($token);
            $records[$token] = ['token' => $token, 'label' => $label, 'id' => $actor->id];
          }
          return $records;
        } catch (AuthorizationException|HttpException $exception) { throw $exception; }
        catch (Throwable) { $this->directoryError = true; return []; }
      })->columns([
        TextColumn::make('label')->label(__('filament-ai-writer::controls.directory.user'))->wrap(),
      ])->searchable(AiWriterPlugin::get()->hasActorDirectory())->searchPlaceholder(__('filament-ai-writer::controls.directory.search'))
        ->paginated(false)->description(__('filament-ai-writer::controls.directory.limit'))
        ->extraAttributes(['class' => 'ai-controls-directory ai-controls-list'])
        ->recordActions([ManageAiUserAction::make()->actorUsing(fn (array $record): ActorIdentity => ActorIdentity::fromToken($record['token']))])
        ->emptyStateHeading(fn (): string => !AiWriterPlugin::get()->hasActorDirectory() ? __('filament-ai-writer::controls.directory.not_configured') : ($this->directoryError ? __('filament-ai-writer::controls.directory.unavailable') : __('filament-ai-writer::controls.directory.none')))
        ->emptyStateDescription(fn (): string => !AiWriterPlugin::get()->hasActorDirectory() ? __('filament-ai-writer::controls.directory.not_configured_description') : ($this->directoryError ? __('filament-ai-writer::controls.directory.unavailable_description') : __('filament-ai-writer::controls.directory.none_description')));
    }
    return $table
      ->query(fn (): Builder => AiWriterPlugin::get()->controlsService()->audit($this->context()))
      ->heading(__('filament-ai-writer::controls.activity.heading'))->defaultSort('id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(10)
      ->columns([
        // No format or timezone here: the host's Filament defaults (also per user) decide how dates look.
        TextColumn::make('created_at')->label(__('filament-ai-writer::controls.activity.date'))->tooltip(__('filament-ai-writer::statistics.timezone', ['timezone' => HostDates::timezone()]))->dateTime(),
        TextColumn::make('administrator_id')->label(__('filament-ai-writer::controls.activity.administrator'))->wrap()
          ->formatStateUsing(fn (ControlAudit $record): string => $this->auditIdentity($record->administrator_type, $record->administrator_id)),
        TextColumn::make('action')->label(__('filament-ai-writer::controls.activity.change'))->formatStateUsing(fn (ControlAudit $record): string => __('filament-ai-writer::controls.activity.' . match ($record->action) {
          'availability' => $record->after['enabled'] ? 'availability_on' : 'availability_off',
          'block' => $record->after['blocked'] ? 'block_on' : 'block_off',
          'abuse_policy', 'quota_policy', 'quota_limit', 'quota_extra', 'quota_reset', 'quota_renew' => $record->action,
          default => 'default',
        })),
        TextColumn::make('actor_id')->label(__('filament-ai-writer::controls.activity.user'))->placeholder(__('filament-ai-writer::controls.activity.all_users'))->wrap()
          ->formatStateUsing(fn (ControlAudit $record): string => $this->auditIdentity($record->actor_type, $record->actor_id)),
        TextColumn::make('reason')->label(__('filament-ai-writer::controls.activity.reason'))->placeholder(__('filament-ai-writer::controls.reason.none'))->wrap(),
      ])->emptyStateHeading(__('filament-ai-writer::controls.activity.empty'));
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

  protected function settingsSaveAction(string $group, string $label): Action
  {
    return $this->saveSettingsAction()->name('save' . ucfirst($group))->label($label)
      ->mountUsing(function (?Schema $schema) use ($group): void {
        $this->context();
        $values = $this->settingValues($group, $this->getSchema($group . 'Form')->getState());
        $this->mergeMountedActionArguments(['group' => $group, 'settings' => $values]);
        $schema?->fill();
      });
  }

  public function settings(Schema $schema): Schema
  {
    $context = $this->context();
    $plugin = AiWriterPlugin::get();
    $enabled = $plugin->controlsService()->enabled($context);
    $policy = $plugin->credits()->policy($context);
    $renewal = $policy['renews_at'] === null ? __('filament-ai-writer::controls.credits.manual_renewal') : HostDates::dateTime($policy['renews_at']);
    return $schema->components([
      Section::make(__('filament-ai-writer::controls.directory.heading'))->aside()->compact()
        ->description(__('filament-ai-writer::controls.directory.description'))
        ->schema([Actions::make([Action::make('directoryDocs')->label(__('filament-ai-writer::controls.directory.setup'))->link()
          ->url(self::DIRECTORY_DOCS_URL, shouldOpenInNewTab: true)])->key('directoryActions')])
        ->visible(!$plugin->hasActorDirectory() && $plugin->canManage()),
      Section::make(__('filament-ai-writer::controls.availability.heading'))->aside()->compact()->description($enabled ? __('filament-ai-writer::controls.availability.enabled') : __('filament-ai-writer::controls.availability.disabled'))
        ->schema([Actions::make([$enabled ? $this->disableAction() : $this->enableAction()])->key('availabilityActions')]),
      Section::make(__('filament-ai-writer::controls.credits.heading'))->aside()->compact()->description(__('filament-ai-writer::controls.credits.description', ['state' => $policy['enabled'] ? __('filament-ai-writer::controls.credits.quotas_on') : __('filament-ai-writer::controls.credits.quotas_off')]))
        ->schema([
          EmbeddedSchema::make('creditForm'),
          \Filament\Infolists\Components\TextEntry::make('current_period')->label(__('filament-ai-writer::controls.credits.current_period'))->state($policy['limit'] === null ? __('filament-ai-writer::controls.credits.no_limit') : $renewal),
          Actions::make([$this->settingsSaveAction('credit', __('filament-ai-writer::controls.credits.save')), $this->renewAction()->visible($policy['limit'] !== null)])->key('creditActions'),
        ]),
      ...array_map(fn (string $group, string $heading, string $description): Section => Section::make($heading)->aside()->compact()->description($description)->schema([
        EmbeddedSchema::make($group . 'Form'),
        Actions::make([$this->settingsSaveAction($group, __('filament-ai-writer::controls.form.save_group', ['group' => mb_strtolower($heading)]))])->key($group . 'Actions'),
      ]), ['rate', 'concurrency', 'failure'], [__('filament-ai-writer::controls.form.rate.heading'), __('filament-ai-writer::controls.form.concurrency.heading'), __('filament-ai-writer::controls.form.failure.heading')], [
        __('filament-ai-writer::controls.form.rate.description'), __('filament-ai-writer::controls.form.concurrency.description'), __('filament-ai-writer::controls.form.failure.description'),
      ]),
    ]);
  }

  protected function getViewData(): array
  {
    $this->context();
    return ['sections' => $this->availableSections()];
  }
}
