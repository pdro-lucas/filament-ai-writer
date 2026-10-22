<?php

namespace PdroLucas\FilamentAiWriter\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Controls\ActorIdentity;
use PdroLucas\FilamentAiWriter\Schemas\AiCreditStatus;
use PdroLucas\FilamentAiWriter\Support\ActorConfirmation;
use PdroLucas\FilamentAiWriter\Support\ControlFields;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ManageAiUserAction extends Action
{
  protected ?Closure $actorResolver = null;
  protected ?array $statusSnapshot = null;

  public static function getDefaultName(): ?string
  {
    return 'manageAiUser';
  }

  /** The host owns identity mapping; the scoped directory still authorizes every target. */
  public function actorUsing(Closure $actor): static
  {
    $this->actorResolver = $actor;
    return $this;
  }

  protected function setUp(): void
  {
    parent::setUp();
    $this->label('Manage AI')->modalWidth('2xl')
      ->authorize(fn (): bool => AiWriterPlugin::get()->canManage())
      ->modalHeading(fn (): string => $this->actorLabel())
      ->modalSubmitAction(false)->modalCancelActionLabel('Close')
      ->schema(fn (): array => $this->statusComponents())
      ->mountUsing(function (?Schema $schema): void {
        $plugin = AiWriterPlugin::get();
        $context = $plugin->controlContext();
        $plugin->controls()->authorize($context);
        try { $actor = ActorConfirmation::resolve($context, fn () => $this->actorResolver === null ? null : $this->evaluate($this->actorResolver)); }
        catch (ValidationException $exception) { $this->unavailable($exception); }
        $this->statusSnapshot = null;
        $this->getLivewire()->mergeMountedActionArguments([
          'aiConfirmation' => ActorConfirmation::seal($this->purpose(), $this->componentId(), $context, $actor),
        ]);
        $schema?->components($this->statusComponents())->fill();
      })
      ->extraModalFooterActions(fn (): array => $this->userActions());
  }

  protected function unavailable(ValidationException $exception): never
  {
    Notification::make()->danger()->title('User unavailable')->body('Try again or close this dialog.')->send();
    throw $exception;
  }

  protected function purpose(?string $operation = null): string
  {
    return 'ai-user:' . $this->getName() . ($operation === null ? '' : ':' . $operation);
  }

  protected function componentId(): string
  {
    return $this->getLivewire()->getId();
  }

  protected function target(): array
  {
    return ActorConfirmation::open($this->getArguments()['aiConfirmation'] ?? null, $this->purpose(), $this->componentId());
  }

  protected function actorLabel(): string
  {
    // Native schema/modal discovery can happen before mount. No target data may be read then.
    if (!isset($this->getArguments()['aiConfirmation'])) { return 'User'; }
    try {
      [, $actor] = $this->target();
      return $actor->label !== '' ? $actor->label : $actor->id;
    } catch (ValidationException) {
      return 'User unavailable';
    }
  }

  protected function snapshot(): ?array
  {
    if (!isset($this->getArguments()['aiConfirmation'])) { return null; }
    [$context, $actor] = $this->target();
    if ($this->statusSnapshot === null) {
      $plugin = AiWriterPlugin::get();
      $abuse = $plugin->abuse()->status($context, $actor);
      $this->statusSnapshot = ['actor' => $actor, 'abuse' => $abuse,
        'balance' => $plugin->credits()->balance($context, $actor),
        'availability' => ['enabled' => $plugin->controls()->enabled($context), 'blocked' => $plugin->controls()->blocked($context, $actor)]];
    }
    return $this->statusSnapshot;
  }

  protected function statusComponents(): array
  {
    try {
      $status = $this->snapshot();
      return $status === null ? [Section::make('Access')->contained(false)] : AiCreditStatus::components($status['balance'], $status['abuse'], $status['availability']);
    } catch (ValidationException) {
      return [Section::make('User unavailable')->contained(false)->description('Close and search again.')];
    }
  }

  protected function userActions(): array
  {
    return [
      $this->userAction('block', 'Block user', 'Prevent new AI generations. Already admitted generations can finish.')->color('danger')
        ->visible(fn (): bool => $this->hasStatus(fn (array $status): bool => !$status['availability']['blocked'])),
      $this->userAction('unblock', 'Unblock user', 'Restore access to new generations, subject to credits and protections.')
        ->visible(fn (): bool => $this->hasStatus(fn (array $status): bool => $status['availability']['blocked'])),
      $this->userAction('limit', 'Edit individual limit', 'Set an individual limit. Consumption and reservations are preserved.'),
      $this->userAction('restoreLimit', 'Restore default limit', 'Use the current workspace limit. Consumption and reservations are preserved.')
        ->visible(fn (): bool => $this->hasStatus(fn (array $status): bool => $status['balance']['individual_limit'] !== null)),
      $this->userAction('extra', 'Give extra credits', 'Add credits to this period only. Unused extras do not carry over.')
        ->visible(fn (): bool => $this->hasStatus(fn (array $status): bool => $status['balance']['limit'] !== null)),
      $this->userAction('resetPeriod', 'Reset user period', 'Clear this user’s consumed and extra credits for the current period. In-flight generations settle against their original balance.')
        ->color('danger')->visible(fn (): bool => $this->hasStatus(fn (array $status): bool => $status['balance']['limit'] !== null)),
    ];
  }

  protected function hasStatus(Closure $test): bool
  {
    try { $status = $this->snapshot(); return $status !== null && $test($status); }
    catch (ValidationException) { return false; }
  }

  protected function userAction(string $operation, string $label, string $description): Action
  {
    $fields = match ($operation) {
      'limit' => [ControlFields::integerField('limit', 'Individual limit (credits)', 0)->required()],
      'extra' => [ControlFields::integerField('amount', 'Extra credits', 1)->required()],
      default => [],
    };
    return Action::make($operation)->label($label)->modalHeading(function (Action $action) use ($operation, $label): string {
      if (!isset($action->getArguments()['aiConfirmation'])) { return $label; }
      try {
        [, $actor] = ActorConfirmation::open($action->getArguments()['aiConfirmation'], $this->purpose($operation), $this->componentId());
        return $label . ': ' . ($actor->label !== '' ? $actor->label : $actor->id);
      } catch (ValidationException) { return $label . ': User unavailable'; }
    })->modalDescription($description)->modalSubmitActionLabel($label)
      ->schema([...$fields, ControlFields::reasonField()])
      ->mountUsing(function (Action $action, ?Schema $schema) use ($operation): void {
        try { [$context, $actor] = $this->target(); }
        catch (ValidationException $exception) { $this->unavailable($exception); }
        $this->getLivewire()->mergeMountedActionArguments([
          'aiConfirmation' => ActorConfirmation::seal($this->purpose($operation), $this->componentId(), $context, $actor),
        ]);
        $data = $operation === 'limit' ? ['limit' => AiWriterPlugin::get()->credits()->balance($context, $actor)['limit']] : [];
        $schema?->fill($data);
      })
      ->action(function (Action $action, array $data) use ($operation): void {
        try { [$context, $actor] = ActorConfirmation::open($action->getArguments()['aiConfirmation'] ?? null, $this->purpose($operation), $this->componentId()); }
        catch (ValidationException $exception) { $this->unavailable($exception); }
        $plugin = AiWriterPlugin::get();
        try {
          match ($operation) {
            'block' => $plugin->controls()->setBlocked($context, $actor, true, $data['reason']),
            'unblock' => $plugin->controls()->setBlocked($context, $actor, false, $data['reason']),
            'limit' => $plugin->credits()->setLimit($context, $actor, ControlFields::parseInteger($data['limit'], 'limit'), $data['reason']),
            'restoreLimit' => $plugin->credits()->setLimit($context, $actor, null, $data['reason']),
            'extra' => $plugin->credits()->grant($context, $actor, ControlFields::parseInteger($data['amount'], 'amount', 1), $data['reason']),
            'resetPeriod' => $plugin->credits()->reset($context, $actor, $data['reason']),
          };
          $this->statusSnapshot = null;
          $this->getLivewire()->dispatch('ai-writer-credits-changed');
          Notification::make()->success()->title('AI controls updated')->send();
        } catch (ValidationException|AuthorizationException|HttpException $exception) { throw $exception; }
        catch (Throwable) {
          Notification::make()->danger()->title('AI controls could not be updated')->body('No change was saved. Please try again or contact support.')->send();
        }
      });
  }
}
