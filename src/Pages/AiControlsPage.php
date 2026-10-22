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
use PdroLucas\FilamentAiWriter\Controls\ActorIdentity;
use PdroLucas\FilamentAiWriter\Credits\Credits;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Controls\ControlContext;
use PdroLucas\FilamentAiWriter\Models\ControlAudit;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class AiControlsPage extends Page implements HasTable
{
  use InteractsWithTable;

  protected static ?string $title = 'AI Writer controls';
  protected static ?string $slug = 'ai-writer-controls';
  protected string $view = 'filament-ai-writer::ai-controls';

  #[Locked]
  public array $controlSnapshot;

  #[Locked]
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
    return AiWriterPlugin::get()->canManage();
  }

  public function mount(): void
  {
    abort_unless(static::canAccess(), 403);
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
    abort_unless($this->section !== 'users' || AiWriterPlugin::get()->hasUsersTab(), 403);
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
    return Action::make($name)->label($enabled ? 'Enable AI' : 'Pause AI')->color($enabled ? 'primary' : 'danger')
      ->visible(fn (): bool => AiWriterPlugin::get()->controls()->enabled($this->context()) !== $enabled)
      ->modalHeading($enabled ? 'Enable AI' : 'Pause AI')->modalDescription('Applies to new generations. Already admitted generations can finish.')
      ->schema([$this->reasonField()])
      ->action(function (array $data) use ($enabled): void {
        $context = $this->context();
        $this->saveChange(fn () => AiWriterPlugin::get()->controls()->setEnabled($context, $enabled, $data['reason']));
      });
  }

  public function showSection(string $section): void
  {
    $this->context();
    abort_unless(in_array($section, ['settings', 'users', 'activity'], true), 404);
    abort_unless($section !== 'users' || AiWriterPlugin::get()->hasUsersTab(), 403);
    $this->taskTableState[$this->section] = ['search' => $this->tableSearch, 'sort' => $this->tableSort,
      'perPage' => $this->getTableRecordsPerPage(), 'page' => $this->getTablePage()];
    if ($this->mountedActions !== []) { $this->unmountAction(cancelParentActions: true); }
    $this->section = $section;
    $state = $this->taskTableState[$section] ?? [];
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
    return Action::make('renew')->label('Renew all credits')->color('danger')
      ->modalHeading('Renew credits for all users')
      ->modalDescription('Start a new period for everyone in this scope. Unused credits do not carry over. In-flight generations settle against their original period.')
      ->schema([$this->reasonField()])->action(function (array $data): void {
        $this->saveChange(fn () => AiWriterPlugin::get()->credits()->renew($this->context(), $data['reason']));
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
      Toggle::make('enabled')->label('Credit quotas')->columnSpanFull()
        ->helperText('Off preserves the saved balance.')
        ->hintAction($this->informationAction('credits', 'Credit quotas', 'Turning quotas off stops charging new generations. Previously consumed credits and reservations are preserved for reactivation; AI availability is a separate control.')),
      $this->integerField('limit', 'Default limit (credits)', 0)->required(),
      Select::make('renewal')->label('Renewal')->options(['manual' => 'Manual', 'daily' => 'Daily · midnight', 'weekly' => 'Weekly · Monday', 'monthly' => 'Monthly · first day'])->required()->native()->placeholder('Choose a renewal schedule')
        ->helperText('Calendar boundaries use the application timezone.')
        ->hintAction($this->informationAction('renewal', 'Renewal schedule', 'Daily renews at midnight; weekly on Monday; monthly on the first day. Calendar changes preserve the current finite cutoff. From manual, renewal begins at the next cut. Unused credits do not carry over.')),
    ]);
  }

  public function rateForm(Schema $schema): Schema
  {
    return $schema->statePath('rateData')->columns(2)->components([
      Toggle::make('rate_enabled')->label('Limit generation frequency')->columnSpanFull()
        ->helperText('Requires a limit and interval.')
        ->hintAction($this->informationAction('frequency', 'Frequency protection', 'When on, new admissions are refused at the configured rolling frequency limit. Recent admissions, including those made while this protection was off, count immediately.')),
      $this->integerField('rate_limit', 'Generations per interval', 1),
      $this->integerField('rate_window_seconds', 'Interval (seconds)', 1),
    ]);
  }

  public function concurrencyForm(Schema $schema): Schema
  {
    return $schema->statePath('concurrencyData')->columns(2)->components([
      Toggle::make('concurrency_enabled')->label('Limit simultaneous generations')->columnSpanFull()
        ->helperText('Requires an in-progress limit.')
        ->hintAction($this->informationAction('simultaneous', 'Simultaneous generations', 'When on, each user must wait when their in-progress generations reach the limit. Changing this setting does not cancel admitted work.')),
      $this->integerField('concurrency_limit', 'Generations in progress per user', 1),
    ]);
  }

  public function failureForm(Schema $schema): Schema
  {
    return $schema->statePath('failureData')->columns(3)->components([
      Toggle::make('failure_enabled')->label('Pause after repeated failures')->columnSpanFull()
        ->helperText('Requires threshold, window and pause.')
        ->hintAction($this->informationAction('failures', 'Repeated failure protection', 'When on, recent failures can immediately pause new generations. A success does not clear other failures. The pause ends when its duration expires or the rolling count falls below the threshold.')),
      $this->integerField('failure_limit', 'Failures in window', 1),
      $this->integerField('failure_window_seconds', 'Window (seconds)', 1),
      $this->integerField('failure_pause_seconds', 'Pause (seconds)', 1),
    ]);
  }

  protected function settingValues(string $group, array $data): array
  {
    $fields = match ($group) {
      'credit' => ['enabled', 'limit', 'renewal'],
      'rate' => ['rate_enabled', 'rate_limit', 'rate_window_seconds'],
      'concurrency' => ['concurrency_enabled', 'concurrency_limit'],
      'failure' => ['failure_enabled', 'failure_limit', 'failure_window_seconds', 'failure_pause_seconds'],
      default => throw ValidationException::withMessages(['settings' => 'Select an available setting group.']),
    };
    $values = [];
    foreach ($fields as $field) {
      $value = $data[$field] ?? null;
      if ($field === 'renewal') {
        if (!in_array($value, ['manual', 'daily', 'weekly', 'monthly'], true)) {
          throw ValidationException::withMessages(['creditData.renewal' => 'Select a renewal calendar.']);
        }
      } elseif ($field === 'enabled' || str_ends_with($field, '_enabled')) {
        if (!is_bool($value)) {
          throw ValidationException::withMessages([$group . 'Data.' . $field => 'Choose on or off.']);
        }
      } else {
        $value = ($value === null || $value === '') && $group !== 'credit' ? null : $this->parseInteger($value, $group . 'Data.' . $field, $group === 'credit' ? 0 : 1);
      }
      $values[$field] = $value;
    }
    if ($group !== 'credit' && $values[$group . '_enabled'] && in_array(null, $values, true)) {
      throw ValidationException::withMessages([$group . 'Data.' . $fields[1] => 'Configure every threshold before enabling this protection.']);
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
    return Action::make('saveSettings')->label('Save settings')->modalHeading('Confirm settings change')
      ->modalDescription(fn (array $arguments): string => 'Apply ' . match ($arguments['group'] ?? '') {
        'credit' => 'credit settings. Consumption is preserved. Calendar changes preserve the current finite cutoff; a manual period ends at the next cut of the chosen calendar.',
        'rate' => 'frequency settings. Recent admissions, including failures, count within the configured interval.',
        'concurrency' => 'simultaneous generation settings. Already admitted generations can finish.',
        'failure' => 'failure protection settings. Recent failures can immediately pause new generations. Successful generations do not clear recent failures.',
        default => 'settings.',
      } . $this->settingsDescription($arguments))
      ->schema([$this->reasonField()])->action(function (array $data, array $arguments): void {
        $context = $this->context();
        $group = $arguments['group'] ?? '';
        $values = $this->settingValues($group, is_array($arguments['settings'] ?? null) ? $arguments['settings'] : []);
        $this->saveChange(function () use ($context, $group, $values, $data): void {
          if ($group === 'credit') {
            AiWriterPlugin::get()->credits()->configure($context, $values['enabled'], $values['limit'], $values['renewal'], $data['reason']);
          } else {
            AiWriterPlugin::get()->abuse()->configure($context, $values, $data['reason']);
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
      $on = ($values[$group === 'credit' ? 'enabled' : $group . '_enabled'] ?? false) ? 'On' : 'Off';
      $number = fn (string $key): string => $values[$key] === null ? 'Not configured' : number_format($values[$key]);
      return ' Selected settings: ' . match ($group) {
        'credit' => $on . '; default limit ' . $number('limit') . ' credits; ' . $values['renewal'] . ' renewal.',
        'rate' => $on . '; ' . $number('rate_limit') . ' generations per ' . $number('rate_window_seconds') . ' seconds.',
        'concurrency' => $on . '; ' . $number('concurrency_limit') . ' generations in progress per user.',
        'failure' => $on . '; ' . $number('failure_limit') . ' failures within ' . $number('failure_window_seconds') . ' seconds; pause ' . $number('failure_pause_seconds') . ' seconds.',
      };
    } catch (ValidationException) {
      return ' Settings are invalid. Close this dialog and check the fields.';
    }
  }

  protected function informationAction(string $name, string $label, string $details): Action
  {
    return ControlFields::informationAction($name, $label, $details);
  }

  protected function reasonField(): Textarea
  {
    return ControlFields::reasonField();
  }

  protected function saveChange(\Closure $change): void
  {
    try {
      $change();
      $this->cacheSchema('settings', null);
      $this->dispatch('ai-writer-credits-changed');
      Notification::make()->success()->title('AI controls updated')->send();
      $this->resetTable();
    } catch (ValidationException|AuthorizationException|HttpException $exception) {
      throw $exception;
    } catch (Throwable) {
      Notification::make()->danger()->title('AI controls could not be updated')->body('No change was saved. Please try again or contact support.')->send();
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
        TextColumn::make('label')->label('User')->wrap(),
      ])->searchable(AiWriterPlugin::get()->hasActorDirectory())->searchPlaceholder('Search users')
        ->paginated(false)->description('Up to 50 matches. Refine search for more.')
        ->extraAttributes(['class' => 'ai-controls-directory ai-controls-list'])
        ->recordActions([ManageAiUserAction::make()->actorUsing(fn (array $record): ActorIdentity => ActorIdentity::fromToken($record['token']))])
        ->emptyStateHeading(fn (): string => !AiWriterPlugin::get()->hasActorDirectory() ? 'User search is not configured.' : ($this->directoryError ? 'Directory unavailable' : 'No users found'))
        ->emptyStateDescription(fn (): string => !AiWriterPlugin::get()->hasActorDirectory() ? 'Contact your application administrator to connect the directory. Settings and Activity remain available.' : ($this->directoryError ? 'Try searching again or contact your application administrator.' : 'Try another name or identifier recognized by your application.'));
    }
    return $table
      ->query(fn (): Builder => AiWriterPlugin::get()->controls()->audit($this->context()))
      ->heading('Activity')->defaultSort('id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(10)
      ->columns([
        TextColumn::make('created_at')->label('Date / time')->tooltip('Times shown in ' . config('app.timezone'))->dateTime('M j, Y H:i:s', timezone: config('app.timezone')),
        TextColumn::make('administrator_id')->label('Administrator')->wrap()
          ->formatStateUsing(fn (ControlAudit $record): string => $this->auditIdentity($record->administrator_type, $record->administrator_id))
,
        TextColumn::make('action')->label('Change')->formatStateUsing(fn (ControlAudit $record): string => match ($record->action) {
          'availability' => $record->after['enabled'] ? 'AI enabled' : 'AI disabled',
          'block' => $record->after['blocked'] ? 'User blocked' : 'User unblocked',
          'abuse_policy' => 'Abuse protections updated',
          'quota_policy' => 'Credit policy updated',
          'quota_limit' => 'User limit updated',
          'quota_extra' => 'Extra credits granted',
          'quota_reset' => 'User quota reset',
          'quota_renew' => 'Credits renewed',
          default => 'Controls updated',
        }),
        TextColumn::make('actor_id')->label('User')->placeholder('All users')->wrap()
          ->formatStateUsing(fn (ControlAudit $record): string => $this->auditIdentity($record->actor_type, $record->actor_id))
,
        TextColumn::make('reason')->label('Reason')->wrap(),
      ])->emptyStateHeading('No changes recorded');
  }

  public function getTableRecordsPerPage(): int
  {
    return in_array($this->tableRecordsPerPage, [10, 25, 50, '10', '25', '50'], true) ? (int) $this->tableRecordsPerPage : 10;
  }

  protected function auditIdentity(?string $type, ?string $id): string
  {
    $context = $this->context();
    if ($type === null || $id === null) { return 'All users'; }
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
    $enabled = $plugin->controls()->enabled($context);
    $policy = $plugin->credits()->policy($context);
    $renewal = $policy['renews_at'] === null ? 'Manual renewal' : \Carbon\CarbonImmutable::parse($policy['renews_at'], 'UTC')->setTimezone(config('app.timezone'))->format('M j, Y · H:i T');
    return $schema->components([
      Section::make('Availability')->aside()->compact()->description($enabled ? 'AI is enabled' : 'AI is disabled')
        ->schema([Actions::make([$enabled ? $this->disableAction() : $this->enableAction()])->key('availabilityActions')]),
      Section::make('Credits')->aside()->compact()->description('1 delivered generation = 1 credit. ' . ($policy['enabled'] ? 'Quotas on.' : 'Quotas off.'))
        ->schema([
          EmbeddedSchema::make('creditForm'),
          \Filament\Infolists\Components\TextEntry::make('current_period')->label('Current period')->state($policy['limit'] === null ? 'No credit limit configured' : $renewal),
          Actions::make([$this->settingsSaveAction('credit', 'Save credit settings'), $this->renewAction()->visible($policy['limit'] !== null)])->key('creditActions'),
        ]),
      ...array_map(fn (string $group, string $heading, string $description): Section => Section::make($heading)->aside()->compact()->description($description)->schema([
        EmbeddedSchema::make($group . 'Form'),
        Actions::make([$this->settingsSaveAction($group, 'Save ' . strtolower($heading))])->key($group . 'Actions'),
      ]), ['rate', 'concurrency', 'failure'], ['Frequency', 'Simultaneous generations', 'Repeated failures'], [
        'Limit generations in a rolling interval.', 'Limit generations in progress per user.', 'Pause new generations after repeated failures.',
      ]),
    ]);
  }

  protected function getViewData(): array
  {
    $this->context();
    return ['hasUsersTab' => AiWriterPlugin::get()->hasUsersTab()];
  }
}
