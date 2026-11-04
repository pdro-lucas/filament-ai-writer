<?php

namespace PdroLucas\FilamentAiWriter;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Concerns\EvaluatesClosures;
use LogicException;
use PdroLucas\FilamentAiWriter\Generation\Generation;
use PdroLucas\FilamentAiWriter\Usage\UsageStatistics;
use PdroLucas\FilamentAiWriter\Pages\UsageStatisticsPage;

class AiWriterPlugin implements Plugin
{
  use EvaluatesClosures;

  protected ?Panel $panel = null;

  protected bool|Closure $usageTracking = false;

  protected bool|Closure $controls = false;

  protected ?Closure $useAiAuthorization = null;
  protected ?Closure $viewStatisticsAuthorization = null;
  protected ?Closure $manageAuthorization = null;

  protected ?Closure $scopeResolver = null;
  protected ?Closure $actorSearch = null;
  protected ?Closure $actorResolver = null;

  public function abuse(): \PdroLucas\FilamentAiWriter\Abuse\Abuse
  {
    return app(\PdroLucas\FilamentAiWriter\Abuse\Abuse::class);
  }

  public function credits(): \PdroLucas\FilamentAiWriter\Credits\Credits
  {
    return app(\PdroLucas\FilamentAiWriter\Credits\Credits::class);
  }

  public function controlsService(): \PdroLucas\FilamentAiWriter\Controls\Controls
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
    $this->controlsService()->authorize($context);
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
    $this->controlsService()->authorize($context);
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
    return static::current()
      ?? throw new LogicException('Register AiWriterPlugin::make() with ->plugin() in this panel\'s PanelProvider before using AiWriterAction.');
  }

  /** The plugin registered on the current panel, or null when AiWriterAction runs standalone. */
  public static function current(): ?static
  {
    $panel = Filament::getCurrentPanel();
    $plugin = $panel?->hasPlugin('ai-writer') ? $panel->getPlugin('ai-writer') : null;

    return $plugin instanceof static ? $plugin : null;
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

  protected bool $creditsInTopbar = true;

  public function creditsInTopbar(bool $enabled = true): static
  {
    $this->creditsInTopbar = $enabled;
    return $this;
  }

  public function hasCreditsInTopbar(): bool
  {
    return $this->creditsInTopbar;
  }

  /** @return class-string<\PdroLucas\FilamentAiWriter\Pages\MyAiCreditsPage> */
  public function getCreditsPage(): string
  {
    return $this->creditsPage;
  }

  protected bool $hasUsersTab = true;

  public function usersTab(bool $enabled = true): static
  {
    $this->hasUsersTab = $enabled;
    return $this;
  }

  /** The Users section needs a directory; without one it is hidden and unreachable. */
  public function hasUsersTab(): bool
  {
    return $this->hasUsersTab && $this->hasActorDirectory();
  }

  /** @var class-string<\PdroLucas\FilamentAiWriter\Pages\AiControlsPage> */
  protected string $controlsPage = \PdroLucas\FilamentAiWriter\Pages\AiControlsPage::class;

  /** @var class-string<\PdroLucas\FilamentAiWriter\Pages\MyAiCreditsPage> */
  protected string $creditsPage = \PdroLucas\FilamentAiWriter\Pages\MyAiCreditsPage::class;

  public function controlsPage(string $page): static
  {
    if (!is_a($page, \PdroLucas\FilamentAiWriter\Pages\AiControlsPage::class, true) || (new \ReflectionClass($page))->isAbstract()) {
      throw new \InvalidArgumentException('Controls page must be a concrete AiControlsPage subclass.');
    }
    $this->controlsPage = $page;
    return $this;
  }

  public function creditsPage(string $page): static
  {
    if (!is_a($page, \PdroLucas\FilamentAiWriter\Pages\MyAiCreditsPage::class, true) || (new \ReflectionClass($page))->isAbstract()) {
      throw new \InvalidArgumentException('Credits page must be a concrete MyAiCreditsPage subclass.');
    }
    $this->creditsPage = $page;
    return $this;
  }

  public function register(Panel $panel): void
  {
    if ($this->panel !== null && $this->panel !== $panel) {
      throw new LogicException('Create a separate AiWriterPlugin instance for each panel.');
    }

    $this->panel = $panel;
    $usageTracking = $this->isUsageTrackingEnabled();
    if ($this->evaluateControls() && !$usageTracking) {
      throw new LogicException('AiWriterPlugin::controls() requires ->usageTracking() on the same panel: credits and abuse protection are recorded on the usage ledger\'s operations.');
    }
    if (!$usageTracking) {
      // Core only: no pages, routes or render hooks, and no package table is read.
      return;
    }
    $panel->pages([UsageStatisticsPage::class]);
    if (!$this->isControlsEnabled()) {
      return;
    }
    $panel->pages([$this->controlsPage, $this->creditsPage]);
    $panel->renderHook(\Filament\View\PanelsRenderHook::USER_MENU_BEFORE, function () use ($panel) {
      if (Filament::getCurrentPanel() !== $panel || !$this->isControlsEnabled() || !$this->hasCreditsInTopbar()) { return ''; }
      try { if (!$this->canUseAi()) { return ''; } }
      catch (\Throwable) { return ''; }
      try { if (!$this->credits()->quotaEnabled($this->controlContext())) { return ''; } }
      catch (\Throwable) { /* Unknown quota state: the indicator reports credits as unavailable. */ }
      return view('filament-ai-writer::credits-indicator-hook', ['panelId' => $panel->getId()]);
    });
  }

  public function boot(Panel $panel): void {}

  /** Usage ledger, operation deduplication/recovery and the Statistics page. Evaluated with the panel; page routes use its value at registration. */
  public function usageTracking(bool|Closure $condition = true): static
  {
    $this->usageTracking = $condition;
    return $this;
  }

  public function isUsageTrackingEnabled(): bool
  {
    return (bool) $this->evaluate($this->usageTracking, ['panel' => $this->panel], [Panel::class => $this->panel]);
  }

  /**
   * Credits, abuse protection, the Controls and My credits pages and the credits indicator. Requires usageTracking().
   * Evaluated at registration (pages, routes, render hook) and on every request (access, admission, indicator).
   */
  public function controls(bool|Closure $condition = true): static
  {
    $this->controls = $condition;
    return $this;
  }

  public function isControlsEnabled(): bool
  {
    return $this->isUsageTrackingEnabled() && $this->evaluateControls();
  }

  private function evaluateControls(): bool
  {
    return (bool) $this->evaluate($this->controls, ['panel' => $this->panel], [Panel::class => $this->panel]);
  }

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
