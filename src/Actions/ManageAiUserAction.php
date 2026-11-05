<?php

namespace PdroLucas\FilamentAiWriter\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Controls\ActorIdentity;
use PdroLucas\FilamentAiWriter\Schemas\AiCreditStatus;
use PdroLucas\FilamentAiWriter\Support\ActorConfirmation;
use PdroLucas\FilamentAiWriter\Usage\UsageStatistics;
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
    $this->label(__('filament-ai-writer::controls.user.manage'))->modalWidth('3xl')
      ->authorize(fn (): bool => AiWriterPlugin::get()->canManage())
      ->slideOver()
      ->modalHeading(fn (): string => UsageStatistics::splitActorLabel($this->actorLabel())[0])
      ->modalDescription(fn (): ?string => UsageStatistics::splitActorLabel($this->actorLabel())[1])
      ->modalSubmitActionLabel(__('filament-ai-writer::controls.user.save'))->modalCancelActionLabel(__('filament-ai-writer::controls.user.close'))
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
        $schema?->components($this->statusComponents())->fill($this->formState());
      })
      ->action(fn (Action $action, array $data) => $this->save($action, $data))
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
      return $status === null ? [Section::make(__('filament-ai-writer::credits.status.access'))] : $this->panelSections($status);
    } catch (ValidationException) {
      return [Section::make(__('filament-ai-writer::controls.user.unavailable'))->description(__('filament-ai-writer::controls.user.unavailable_section'))];
    }
  }

  /**
   * The read-only picture on top, then one form with an Access section (the block switch) and a Credits section
   * (limit, extras, reason), saved together by the slide-over's own Save.
   */
  protected function panelSections(array $status): array
  {
    $t = fn (string $key, array $replace = []): string => __('filament-ai-writer::controls.user.' . $key, $replace);
    $balance = $status['balance'];
    $planned = fn (Get $get): array => $this->plan($status, [
      'blocked' => $get('blocked'), 'limit_mode' => $get('limit_mode'), 'limit' => $get('limit'), 'amount' => $get('amount'),
    ]);

    return [
      // Read-only status and numbers on top, exactly as everywhere else...
      ...AiCreditStatus::components($balance, $status['abuse'], $status['availability']),
      // ...then the editable form in two separate sections, saved by the slide-over's own Save.
      Section::make(__('filament-ai-writer::credits.status.access'))->schema([
        Toggle::make('blocked')->label($t('block_toggle'))->live(),
      ]),
      Section::make(__('filament-ai-writer::credits.status.credits'))->schema([
        ToggleButtons::make('limit_mode')->label($t('limit_mode'))->inline()->live()->grouped()->options([
          'default' => $balance['default_limit'] === null ? $t('limit_default') : $t('limit_default_value', ['count' => number_format($balance['default_limit'])]),
          'individual' => $t('limit_individual'),
        ]),
        ControlFields::integerField('limit', $t('limit_field'), 0, __('filament-ai-writer::controls.field.unit.credits'))->live()
          ->visible(fn (Get $get): bool => $get('limit_mode') === 'individual')->required(fn (Get $get): bool => $get('limit_mode') === 'individual'),
        ControlFields::integerField('amount', $t('extra_add'), 1, __('filament-ai-writer::controls.field.unit.credits'))->live()
          ->visible($balance['limit'] !== null),
      ]),
      // Outside both sections: it applies to the whole save. Shown (and required) only when a planned change needs a reason.
      ControlFields::reasonField('block')->visible(fn (Get $get): bool => $this->reasonRequired($planned($get)))
        ->required(fn (Get $get): bool => $this->reasonRequired($planned($get))),
    ];
  }

  /** The values the form opens with: what is stored now. */
  protected function formState(): array
  {
    $status = $this->snapshot();
    if ($status === null) { return []; }
    $balance = $status['balance'];

    return ['blocked' => $status['availability']['blocked'], 'limit_mode' => $balance['individual_limit'] === null ? 'default' : 'individual',
      'limit' => $balance['individual_limit'] ?? $balance['limit'], 'amount' => null, 'reason' => null];
  }

  /**
   * The operations the submitted form asks for, compared with the stored state: only what changed, in the order they apply.
   * Raw values are parsed strictly; anything unparsable is a validation error, never a silent no-op.
   *
   * @return array<int, array{0: string, 1?: ?int}> e.g. [['limit', 20], ['extra', 5], ['block']]
   */
  protected function plan(array $status, array $state): array
  {
    $operations = [];
    $balance = $status['balance'];
    $filled = fn (string $key): bool => ($state[$key] ?? null) !== null && $state[$key] !== '';
    if (($state['limit_mode'] ?? 'default') === 'individual') {
      if ($filled('limit')) {
        try { $limit = ControlFields::parseInteger($state['limit'], 'limit'); } catch (ValidationException) { $limit = null; }
        if ($limit === null || $limit !== $balance['individual_limit']) { $operations[] = ['limit', $limit]; }
      }
    } elseif ($balance['individual_limit'] !== null) {
      $operations[] = ['restoreLimit'];
    }
    if ($filled('amount')) {
      try { $operations[] = ['extra', ControlFields::parseInteger($state['amount'], 'amount', 1)]; } catch (ValidationException) { $operations[] = ['extra', null]; }
    }
    $blocked = (bool) ($state['blocked'] ?? false);
    if ($blocked !== (bool) $status['availability']['blocked']) { $operations[] = [$blocked ? 'block' : 'unblock']; }

    return $operations;
  }

  protected function reasonRequired(array $operations): bool
  {
    foreach ($operations as [$operation]) {
      if (Reason::isRequired(self::AUDITED[$operation])) { return true; }
    }
    return false;
  }

  /** Save from the panel's own submit button: only changes, one audit entry per operation; blocking asks to confirm first. */
  protected function save(Action $action, array $data): void
  {
    try { [$context, $actor] = $this->target(); }
    catch (ValidationException $exception) { $this->unavailable($exception); }
    $operations = $this->validatedPlan($data);
    if ($operations === []) {
      Notification::make()->info()->title(__('filament-ai-writer::controls.user.unchanged'))->send();
      return;
    }
    if (in_array(['block'], $operations, true)) {
      // Blocking is the one destructive edit: it asks again. Only the sealed target travels; the plan is re-derived from
      // the submitted form on the server when the confirmation is accepted.
      $this->getLivewire()->mountAction('block', [
        'aiConfirmation' => ActorConfirmation::seal($this->purpose('block'), $this->componentId(), $context, $actor),
        'confirmBlock' => true,
      ]);
      $action->halt();
    }
    $this->apply($context, $actor, $operations, $data['reason'] ?? null);
  }

  /**
   * What the submitted form changes against the stored state, parsed strictly and with its reason requirement checked.
   * One rule for the save and for the block confirmation, so a confirmation can never apply more than the form says.
   *
   * @return array<int, array{0: string, 1?: ?int}>
   */
  protected function validatedPlan(array $data): array
  {
    $this->statusSnapshot = null;
    $operations = $this->plan($this->snapshot(), $data);
    foreach ($operations as $operation) {
      if (($operation[1] ?? 0) === null) {
        throw ValidationException::withMessages(['data.' . ($operation[0] === 'extra' ? 'amount' : 'limit') => __('filament-ai-writer::controls.field.error', ['min' => $operation[0] === 'extra' ? 1 : 0, 'max' => number_format(\PdroLucas\FilamentAiWriter\Credits\Credits::MAX_CREDITS)])]);
      }
    }
    if ($this->reasonRequired($operations) && blank($data['reason'] ?? null)) {
      throw ValidationException::withMessages(['data.reason' => __('filament-ai-writer::controls.reason.required')]);
    }

    return $operations;
  }

  /** @param array<int, array{0: string, 1?: ?int}> $operations */
  protected function apply(\PdroLucas\FilamentAiWriter\Controls\ControlContext $context, ActorIdentity $actor, array $operations, ?string $reason): void
  {
    $plugin = AiWriterPlugin::get();
    try {
      $plugin->credits()->connection()->transaction(function () use ($plugin, $context, $actor, $operations, $reason): void {
        foreach ($operations as $operation) {
          match ($operation[0]) {
            'block' => $plugin->controlsService()->setBlocked($context, $actor, true, $reason),
            'unblock' => $plugin->controlsService()->setBlocked($context, $actor, false, $reason),
            'limit' => $plugin->credits()->setLimit($context, $actor, ControlFields::parseInteger($operation[1], 'limit'), $reason),
            'restoreLimit' => $plugin->credits()->setLimit($context, $actor, null, $reason),
            'extra' => $plugin->credits()->grant($context, $actor, ControlFields::parseInteger($operation[1], 'amount', 1), $reason),
          };
        }
      });
      $this->refreshPanel();
      $this->getLivewire()->dispatch('ai-writer-credits-changed');
      Notification::make()->success()->title(__('filament-ai-writer::controls.updated'))->send();
    } catch (ValidationException|AuthorizationException|HttpException $exception) { throw $exception; }
    catch (Throwable) {
      Notification::make()->danger()->title(__('filament-ai-writer::controls.update_failed.title'))->body(__('filament-ai-writer::controls.update_failed.body'))->send();
    }
  }

  protected function userActions(): array
  {
    return [
      // Resetting the period is not an edit: it stays an explicit, confirmed, destructive action.
      $this->userAction('resetPeriod', __('filament-ai-writer::controls.user.reset'), __('filament-ai-writer::controls.user.reset_description'))
        ->color('danger')->outlined()->visible(fn (): bool => $this->hasStatus(fn (array $status): bool => $status['balance']['limit'] !== null)),
      $this->blockConfirmation(),
    ];
  }

  /** Not shown as a button: the save flow mounts it to confirm a block; it carries only the sealed target. */
  protected function blockConfirmation(): Action
  {
    return Action::make('block')->label(__('filament-ai-writer::controls.user.block'))->color('danger')->requiresConfirmation()
      ->modalHeading(function (Action $action): string {
        try {
          [, $actor] = ActorConfirmation::open($action->getArguments()['aiConfirmation'] ?? null, $this->purpose('block'), $this->componentId());
          return __('filament-ai-writer::controls.user.block') . ': ' . ($actor->label !== '' ? $actor->label : $actor->id);
        } catch (\Throwable) { return __('filament-ai-writer::controls.user.block'); }
      })
      ->modalDescription(__('filament-ai-writer::controls.user.block_description'))->modalSubmitActionLabel(__('filament-ai-writer::controls.user.block'))
      // Only present once the save flow has mounted it; never a button in the footer.
      ->visible(fn (Action $action): bool => ($action->getArguments()['confirmBlock'] ?? false) === true)
      ->cancelParentActions()
      ->action(function (Action $action): void {
        try { [$context, $actor] = ActorConfirmation::open($action->getArguments()['aiConfirmation'] ?? null, $this->purpose('block'), $this->componentId()); }
        catch (ValidationException $exception) { $this->unavailable($exception); }
        // The confirmation only proves consent: it must be for the very user the open form was sealed for.
        [, $parentActor] = $this->target();
        abort_unless($parentActor->key() === $actor->key(), 403);
        // Nothing but the sealed target comes from this action's arguments: the plan is the open form's, re-derived here.
        $form = collect($this->getLivewire()->mountedActions)->first(fn (array $mounted): bool => ($mounted['name'] ?? null) === $this->getName());
        abort_unless(is_array($form), 403);
        $data = is_array($form['data'] ?? null) ? $form['data'] : [];
        $operations = $this->validatedPlan($data);
        abort_unless(in_array(['block'], $operations, true), 403);
        $this->apply($context, $actor, $operations, is_string($data['reason'] ?? null) ? $data['reason'] : null);
      });
  }

  /**
   * The panel's schema is cached when it opens, so after a write that keeps it open (the reset, or a failed block
   * confirmation) it is rebuilt from fresh data: the badge, tiles and form never show what was just replaced.
   */
  protected function refreshPanel(): void
  {
    $this->statusSnapshot = null;
    try {
      $livewire = $this->getLivewire();
      $index = array_key_first(array_filter($livewire->mountedActions, fn (array $mounted): bool => ($mounted['name'] ?? null) === $this->getName()));
      $schema = $index === null ? null : $livewire->getSchema('mountedActionSchema' . $index);
      $schema?->components($this->statusComponents())->fill($this->formState());
    } catch (Throwable) {
      // The panel is closing or its target is gone: nothing to refresh.
    }
  }

  protected function hasStatus(Closure $test): bool
  {
    try { $status = $this->snapshot(); return $status !== null && $test($status); }
    catch (ValidationException) { return false; }
  }

  protected function userAction(string $operation, string $label, string $description): Action
  {
    return Action::make($operation)->label($label)->modalHeading(function (Action $action) use ($operation, $label): string {
      if (!isset($action->getArguments()['aiConfirmation'])) { return $label; }
      try {
        [, $actor] = ActorConfirmation::open($action->getArguments()['aiConfirmation'], $this->purpose($operation), $this->componentId());
        return $label . ': ' . ($actor->label !== '' ? $actor->label : $actor->id);
      } catch (ValidationException) { return $label . ': ' . __('filament-ai-writer::controls.user.unavailable'); }
    })->modalDescription($description)->modalSubmitActionLabel($label)
      // Always confirms (it is destructive); the reason is asked for only when the rules require one.
      ->modal()->modalSubmitAction(fn (Action $action) => $action->color('danger'))
      ->schema(fn (): array => Reason::isRequired(self::AUDITED[$operation]) ? [ControlFields::reasonField(self::AUDITED[$operation])] : [])
      ->mountUsing(function (Action $action, ?Schema $schema) use ($operation): void {
        try { [$context, $actor] = $this->target(); }
        catch (ValidationException $exception) { $this->unavailable($exception); }
        $this->getLivewire()->mergeMountedActionArguments([
          'aiConfirmation' => ActorConfirmation::seal($this->purpose($operation), $this->componentId(), $context, $actor),
        ]);
        $schema?->fill([]);
      })
      ->action(function (Action $action, array $data) use ($operation): void {
        try { [$context, $actor] = ActorConfirmation::open($action->getArguments()['aiConfirmation'] ?? null, $this->purpose($operation), $this->componentId()); }
        catch (ValidationException $exception) { $this->unavailable($exception); }
        // This action's own seal must be for the very user the open panel was sealed for.
        [, $panelActor] = $this->target();
        abort_unless($panelActor->key() === $actor->key(), 403);
        $plugin = AiWriterPlugin::get();
        try {
          match ($operation) {
            'resetPeriod' => $plugin->credits()->reset($context, $actor, $data['reason'] ?? null),
          };
          $this->refreshPanel();
          $this->getLivewire()->dispatch('ai-writer-credits-changed');
          Notification::make()->success()->title(__('filament-ai-writer::controls.updated'))->send();
        } catch (ValidationException|AuthorizationException|HttpException $exception) { throw $exception; }
        catch (Throwable) {
          Notification::make()->danger()->title(__('filament-ai-writer::controls.update_failed.title'))->body(__('filament-ai-writer::controls.update_failed.body'))->send();
        }
      });
  }
}
