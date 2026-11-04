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
use PdroLucas\FilamentAiWriter\Support\Reason;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ManageAiUserAction extends Action
{
  protected ?Closure $actorResolver = null;
  protected ?array $statusSnapshot = null;

  /** The audited action each operation is recorded as; it decides whether a reason is required. */
  private const AUDITED = ['block' => 'block', 'unblock' => 'block', 'limit' => 'quota_limit', 'restoreLimit' => 'quota_limit', 'extra' => 'quota_extra', 'resetPeriod' => 'quota_reset'];

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
    $this->label(__('filament-ai-writer::controls.user.manage'))->modalWidth('2xl')
      ->authorize(fn (): bool => AiWriterPlugin::get()->canManage())
      ->modalHeading(fn (): string => $this->actorLabel())
      ->modalSubmitAction(false)->modalCancelActionLabel(__('filament-ai-writer::controls.user.close'))
      ->schema(fn (): array => $this->statusComponents())
      ->mountUsing(function (?Schema $schema): void {
        $plugin = AiWriterPlugin::get();
        $context = $plugin->controlContext();
        $plugin->controlsService()->authorize($context);
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
    Notification::make()->danger()->title(__('filament-ai-writer::controls.user.unavailable'))->body(__('filament-ai-writer::controls.user.unavailable_body'))->send();
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
    if (!isset($this->getArguments()['aiConfirmation'])) { return __('filament-ai-writer::controls.user.user'); }
    try {
      [, $actor] = $this->target();
      return $actor->label !== '' ? $actor->label : $actor->id;
    } catch (ValidationException) {
      return __('filament-ai-writer::controls.user.unavailable');
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
        'availability' => ['enabled' => $plugin->controlsService()->enabled($context), 'blocked' => $plugin->controlsService()->blocked($context, $actor)]];
    }
    return $this->statusSnapshot;
  }

  protected function statusComponents(): array
  {
    try {
      $status = $this->snapshot();
      return $status === null ? [Section::make(__('filament-ai-writer::credits.status.access'))->contained(false)] : AiCreditStatus::components($status['balance'], $status['abuse'], $status['availability']);
    } catch (ValidationException) {
      return [Section::make(__('filament-ai-writer::controls.user.unavailable'))->contained(false)->description(__('filament-ai-writer::controls.user.unavailable_section'))];
    }
  }

  protected function userActions(): array
  {
    return [
      $this->userAction('block', __('filament-ai-writer::controls.user.block'), __('filament-ai-writer::controls.user.block_description'))->color('danger')
        ->visible(fn (): bool => $this->hasStatus(fn (array $status): bool => !$status['availability']['blocked'])),
      $this->userAction('unblock', __('filament-ai-writer::controls.user.unblock'), __('filament-ai-writer::controls.user.unblock_description'))
        ->visible(fn (): bool => $this->hasStatus(fn (array $status): bool => $status['availability']['blocked'])),
      $this->userAction('limit', __('filament-ai-writer::controls.user.limit'), __('filament-ai-writer::controls.user.limit_description')),
      $this->userAction('restoreLimit', __('filament-ai-writer::controls.user.restore_limit'), __('filament-ai-writer::controls.user.restore_limit_description'))
        ->visible(fn (): bool => $this->hasStatus(fn (array $status): bool => $status['balance']['individual_limit'] !== null)),
      $this->userAction('extra', __('filament-ai-writer::controls.user.extra'), __('filament-ai-writer::controls.user.extra_description'))
        ->visible(fn (): bool => $this->hasStatus(fn (array $status): bool => $status['balance']['limit'] !== null)),
      $this->userAction('resetPeriod', __('filament-ai-writer::controls.user.reset'), __('filament-ai-writer::controls.user.reset_description'))
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
      'limit' => [ControlFields::integerField('limit', __('filament-ai-writer::controls.user.limit_field'), 0)->required()],
      'extra' => [ControlFields::integerField('amount', __('filament-ai-writer::controls.user.extra_field'), 1)->required()],
      default => [],
    };
    return Action::make($operation)->label($label)->modalHeading(function (Action $action) use ($operation, $label): string {
      if (!isset($action->getArguments()['aiConfirmation'])) { return $label; }
      try {
        [, $actor] = ActorConfirmation::open($action->getArguments()['aiConfirmation'], $this->purpose($operation), $this->componentId());
        return $label . ': ' . ($actor->label !== '' ? $actor->label : $actor->id);
      } catch (ValidationException) { return $label . ': ' . __('filament-ai-writer::controls.user.unavailable'); }
    })->modalDescription($description)->modalSubmitActionLabel($label)
      ->modal(fn (): bool => in_array($operation, ['block', 'limit', 'extra'], true) || Reason::isRequired(self::AUDITED[$operation]))
      ->schema([...$fields, ControlFields::reasonField(self::AUDITED[$operation])])
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
            'block' => $plugin->controlsService()->setBlocked($context, $actor, true, $data['reason'] ?? null),
            'unblock' => $plugin->controlsService()->setBlocked($context, $actor, false, $data['reason'] ?? null),
            'limit' => $plugin->credits()->setLimit($context, $actor, ControlFields::parseInteger($data['limit'], 'limit'), $data['reason'] ?? null),
            'restoreLimit' => $plugin->credits()->setLimit($context, $actor, null, $data['reason'] ?? null),
            'extra' => $plugin->credits()->grant($context, $actor, ControlFields::parseInteger($data['amount'], 'amount', 1), $data['reason'] ?? null),
            'resetPeriod' => $plugin->credits()->reset($context, $actor, $data['reason'] ?? null),
          };
          $this->statusSnapshot = null;
          $this->getLivewire()->dispatch('ai-writer-credits-changed');
          Notification::make()->success()->title(__('filament-ai-writer::controls.updated'))->send();
        } catch (ValidationException|AuthorizationException|HttpException $exception) { throw $exception; }
        catch (Throwable) {
          Notification::make()->danger()->title(__('filament-ai-writer::controls.update_failed.title'))->body(__('filament-ai-writer::controls.update_failed.body'))->send();
        }
      });
  }
}
