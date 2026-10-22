<?php

namespace PdroLucas\FilamentAiWriter\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
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

  public static function canAccess(): bool
  {
    return AiWriterPlugin::get()->canManage();
  }

  public function mount(): void
  {
    abort_unless(static::canAccess(), 403);
    $this->controlSnapshot = AiWriterPlugin::get()->controlContext()->snapshot();
  }

  public function hydrate(): void
  {
    $this->context();
  }

  protected function context(): ControlContext
  {
    abort_unless(static::canAccess(), 403);
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
    return Action::make($name)->label($enabled ? 'Enable AI' : 'Disable AI')->color($enabled ? 'primary' : 'danger')
      ->visible(fn (): bool => AiWriterPlugin::get()->controls()->enabled($this->context()) !== $enabled)
      ->modalHeading($enabled ? 'Enable AI' : 'Disable AI')
      ->schema([$this->reasonField()])
      ->action(function (array $data) use ($enabled): void {
        $context = $this->context();
        $this->saveChange(fn () => AiWriterPlugin::get()->controls()->setEnabled($context, $enabled, $data['reason']));
      });
  }

  public function blockActorAction(): Action
  {
    return $this->actorAction('blockActor', true);
  }

  public function unblockActorAction(): Action
  {
    return $this->actorAction('unblockActor', false);
  }

  protected function actorAction(string $name, bool $blocked): Action
  {
    return Action::make($name)->label($blocked ? 'Block user' : 'Unblock user')->color($blocked ? 'danger' : 'primary')
      ->visible(function (): bool {
        $this->context();
        return AiWriterPlugin::get()->hasActorDirectory();
      })
      ->schema([
        Select::make('actor')->label('User')->required()->searchable()->live()->optionsLimit(50)
          ->getSearchResultsUsing(fn (string $search): array => AiWriterPlugin::get()->searchActors($search, $this->context()))
          ->getOptionLabelUsing(function (string $value): ?string {
            $actor = AiWriterPlugin::get()->resolveActor($value, $this->context());
            return $actor === null ? null : ($actor->label !== '' ? $actor->label : $actor->id);
          })
          ->helperText(function (Get $get): string {
            $value = $get('actor');
            $context = $this->context();
            $actor = is_string($value) ? AiWriterPlugin::get()->resolveActor($value, $context) : null;
            return $actor === null ? 'Search for a user.'
              : 'Current AI access: ' . (AiWriterPlugin::get()->controls()->blocked($context, $actor) ? 'Blocked' : 'Allowed');
          }),
        $this->reasonField(),
      ])
      ->action(function (array $data) use ($blocked): void {
        $context = $this->context();
        $actor = AiWriterPlugin::get()->resolveActor($data['actor'], $context);
        if ($actor === null) {
          throw ValidationException::withMessages(['actor' => 'Select an available user.']);
        }
        $this->saveChange(fn () => AiWriterPlugin::get()->controls()->setBlocked($context, $actor, $blocked, $data['reason']));
      });
  }

  protected function reasonField(): Textarea
  {
    return Textarea::make('reason')->label('Reason')->required()->maxLength(500)->rows(3)->autofocus();
  }

  protected function saveChange(\Closure $change): void
  {
    try {
      $change();
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
    return $table
      ->query(fn (): Builder => AiWriterPlugin::get()->controls()->audit($this->context()))
      ->defaultSort('id', 'desc')->paginated([10, 25, 50])->defaultPaginationPageOption(10)
      ->columns([
        TextColumn::make('created_at')->label('Date / time')->dateTime('M j, Y H:i:s', timezone: config('app.timezone')),
        TextColumn::make('administrator_id')->label('Administrator')->wrap()
          ->tooltip(fn (ControlAudit $record): string => $record->administrator_type),
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
          ->tooltip(fn (ControlAudit $record): ?string => $record->actor_type),
        TextColumn::make('before')->label('Before → after')->formatStateUsing(fn (ControlAudit $record): string => $this->auditSummary($record->action, $record->before) . ' → ' . $this->auditSummary($record->action, $record->after)),
        TextColumn::make('reason')->label('Reason')->wrap(),
      ])->emptyStateHeading('No changes recorded');
  }

  protected function auditSummary(string $action, array $values): string
  {
    if ($action === 'availability') {
      return $values['enabled'] ? 'Enabled' : 'Disabled';
    }
    if ($action === 'block') {
      return $values['blocked'] ? 'Blocked' : 'Allowed';
    }
    $labels = $action === 'abuse_policy'
      ? ['rate_enabled' => 'Frequency', 'rate_limit' => 'Calls', 'rate_window_seconds' => 'Interval seconds',
        'concurrency_enabled' => 'Concurrency', 'concurrency_limit' => 'In progress',
        'failure_enabled' => 'Failure pause', 'failure_limit' => 'Failures', 'failure_window_seconds' => 'Failure window seconds', 'failure_pause_seconds' => 'Pause seconds']
      : (in_array($action, ['quota_policy', 'quota_renew'], true)
      ? ['enabled' => 'Credits', 'limit' => 'Limit', 'renewal' => 'Renewal']
      : ['limit' => 'Limit', 'extras' => 'Extras', 'consumed' => 'Used', 'reserved' => 'Reserved', 'available' => 'Available']);
    $parts = [];
    foreach ($labels as $key => $label) {
      if (array_key_exists($key, $values)) {
        $value = $values[$key];
        $parts[] = $label . ': ' . (is_bool($value) ? ($value ? 'On' : 'Off') : ($value ?? 'Not configured'));
      }
    }
    return implode(' · ', $parts) ?: '—';
  }

  public function getTableRecordsPerPage(): int
  {
    return in_array($this->tableRecordsPerPage, [10, 25, 50, '10', '25', '50'], true) ? (int) $this->tableRecordsPerPage : 10;
  }

  protected function getViewData(): array
  {
    $context = $this->context();
    return [
      'enabled' => AiWriterPlugin::get()->controls()->enabled($context),
      'hasDirectory' => AiWriterPlugin::get()->hasActorDirectory(),
    ];
  }
}
