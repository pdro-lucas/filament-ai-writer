<?php

namespace PdroLucas\FilamentAiWriter;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use LogicException;
use PdroLucas\FilamentAiWriter\Generation\Generation;
use PdroLucas\FilamentAiWriter\Usage\UsageStatistics;
use PdroLucas\FilamentAiWriter\Pages\UsageStatisticsPage;

class AiWriterPlugin implements Plugin
{
  protected ?Panel $panel = null;

  protected ?Closure $useAiAuthorization = null;
  protected ?Closure $viewStatisticsAuthorization = null;
  protected ?Closure $manageAuthorization = null;

  protected ?Closure $scopeResolver = null;
  protected ?Closure $actorSearch = null;
  protected ?Closure $actorResolver = null;

  public function controls(): \PdroLucas\FilamentAiWriter\Controls\Controls
  {
    return app(\PdroLucas\FilamentAiWriter\Controls\Controls::class);
  }

  /** Server-only resolver: fn (Authenticatable $actor, Panel $panel): string. */
  public function resolveScopeUsing(Closure $callback): static
  {
    $this->scopeResolver = $callback;
    return $this;
  }

  /** Search and resolve must restrict results to actors manageable in the supplied scope. */
  public function actorDirectoryUsing(Closure $search, Closure $resolve): static
  {
    $this->actorSearch = $search;
    $this->actorResolver = $resolve;
    return $this;
  }

  public function hasActorDirectory(): bool
  {
    return $this->actorSearch !== null && $this->actorResolver !== null;
  }

  public function controlContext(): \PdroLucas\FilamentAiWriter\Controls\ControlContext
  {
    $panel = $this->panel;
    $actor = $panel?->auth()->user();
    $id = $actor?->getAuthIdentifier();
    if ($panel === null || Filament::getCurrentPanel() !== $panel || $actor === null || (!is_int($id) && !is_string($id))) {
      throw new LogicException('An authenticated current plugin panel is required.');
    }
    $scope = $this->scopeResolver === null ? 'panel:' . $panel->getId() : ($this->scopeResolver)($actor, $panel);
    if (!is_string($scope)) {
      throw new LogicException('The AI Writer scope resolver must return a string.');
    }
    return new \PdroLucas\FilamentAiWriter\Controls\ControlContext($panel->getId(), $panel->getAuthGuard(), $scope,
      new \PdroLucas\FilamentAiWriter\Controls\ActorIdentity($actor::class, $id));
  }

  public function searchActors(string $search, \PdroLucas\FilamentAiWriter\Controls\ControlContext $context): array
  {
    $this->controls()->authorize($context);
    if (!$this->hasActorDirectory()) {
      return [];
    }
    if (mb_strlen($search) > 100 || !mb_check_encoding($search, 'UTF-8')) {
      throw \Illuminate\Validation\ValidationException::withMessages(['actor' => 'Search must contain at most 100 characters.']);
    }
    $results = ($this->actorSearch)($search, $context->scope, $this->panel);
    if (!is_iterable($results)) {
      throw new LogicException('Actor search must return iterable ActorIdentity results.');
    }
    $options = [];
    $count = 0;
    foreach ($results as $actor) {
      if (++$count > 50) {
        break;
      }
      if (!$actor instanceof \PdroLucas\FilamentAiWriter\Controls\ActorIdentity) {
        throw new LogicException('Actor search must return ActorIdentity results.');
      }
      $options[$actor->token()] = $actor->label !== '' ? $actor->label : $actor->id;
    }
    return $options;
  }

  public function resolveActor(string $token, \PdroLucas\FilamentAiWriter\Controls\ControlContext $context): ?\PdroLucas\FilamentAiWriter\Controls\ActorIdentity
  {
    $this->controls()->authorize($context);
    if (!$this->hasActorDirectory()) {
      return null;
    }
    try {
      $identity = \PdroLucas\FilamentAiWriter\Controls\ActorIdentity::fromToken($token);
    } catch (\InvalidArgumentException) {
      return null;
    }
    $actor = ($this->actorResolver)($identity->type, $identity->id, $context->scope, $this->panel);
    if ($actor === null) {
      return null;
    }
    if (!$actor instanceof \PdroLucas\FilamentAiWriter\Controls\ActorIdentity || $actor->type !== $identity->type || $actor->id !== $identity->id) {
      throw new LogicException('Actor resolver must return the selected identity or null.');
    }
    return $actor;
  }

  public static function make(): static
  {
    return app(static::class);
  }

  public static function get(): static
  {
    $panel = Filament::getCurrentPanel();
    $plugin = $panel?->hasPlugin('ai-writer') ? $panel->getPlugin('ai-writer') : null;

    if (!$plugin instanceof static) {
      throw new LogicException('Register AiWriterPlugin::make() with ->plugin() in this panel\'s PanelProvider before using AiWriterAction.');
    }

    return $plugin;
  }

  public function generation(): Generation
  {
    return app(Generation::class);
  }

  public function statistics(): UsageStatistics
  {
    return app(UsageStatistics::class);
  }

  public function getId(): string
  {
    return 'ai-writer';
  }

  public function register(Panel $panel): void
  {
    if ($this->panel !== null && $this->panel !== $panel) {
      throw new LogicException('Create a separate AiWriterPlugin instance for each panel.');
    }

    $this->panel = $panel;
    $panel->pages([UsageStatisticsPage::class, \PdroLucas\FilamentAiWriter\Pages\AiControlsPage::class]);
  }

  public function boot(Panel $panel): void {}

  /** @param Closure(\Illuminate\Contracts\Auth\Authenticatable, Panel): bool $callback */
  public function authorizeUseAiUsing(Closure $callback): static
  {
    $this->useAiAuthorization = $callback;

    return $this;
  }

  /** @param Closure(\Illuminate\Contracts\Auth\Authenticatable, Panel): bool $callback */
  public function authorizeViewStatisticsUsing(Closure $callback): static
  {
    $this->viewStatisticsAuthorization = $callback;

    return $this;
  }

  /** @param Closure(\Illuminate\Contracts\Auth\Authenticatable, Panel): bool $callback */
  public function authorizeManageUsing(Closure $callback): static
  {
    $this->manageAuthorization = $callback;

    return $this;
  }

  public function canUseAi(): bool
  {
    return $this->authorize($this->useAiAuthorization, true);
  }

  public function canViewStatistics(): bool
  {
    return $this->authorize($this->viewStatisticsAuthorization, false);
  }

  public function canManage(): bool
  {
    return $this->authorize($this->manageAuthorization, false);
  }

  protected function authorize(?Closure $callback, bool $default): bool
  {
    $user = $this->panel?->auth()->user();

    if ($user === null) {
      return false;
    }

    return $callback === null ? $default : (bool) $callback($user, $this->panel);
  }
}
