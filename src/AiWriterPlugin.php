<?php

namespace PdroLucas\FilamentAiWriter;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Concerns\EvaluatesClosures;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use PdroLucas\FilamentAiWriter\Abuse\Abuse;
use PdroLucas\FilamentAiWriter\Controls\ActorIdentity;
use PdroLucas\FilamentAiWriter\Controls\ControlContext;
use PdroLucas\FilamentAiWriter\Controls\Controls;
use PdroLucas\FilamentAiWriter\Credits\Credits;
use PdroLucas\FilamentAiWriter\Generation\Generation;
use PdroLucas\FilamentAiWriter\Pages\AiControlsPage;
use PdroLucas\FilamentAiWriter\Pages\MyAiCreditsPage;
use PdroLucas\FilamentAiWriter\Pages\UsageStatisticsPage;
use PdroLucas\FilamentAiWriter\Usage\UsageStatistics;
use ReflectionClass;

class AiWriterPlugin implements Plugin
{
  use EvaluatesClosures;

  protected ?Panel $panel = null;

  protected bool|Closure $usageTracking = false;

  protected bool|Closure $controls = false;

  protected bool|Closure|null $useAiAuthorization = null;
  protected bool|Closure|null $viewStatisticsAuthorization = null;
  protected bool|Closure|null $manageAuthorization = null;

  protected string|Closure|null $scope = null;
  protected ?Closure $actorSearch = null;
  protected ?Closure $actorResolver = null;

  protected bool|Closure $usersTab = true;
  protected bool|Closure $creditsIndicator = true;

  /** @var class-string<AiControlsPage>|Closure */
  protected string|Closure $controlsPage = AiControlsPage::class;

  /** @var class-string<MyAiCreditsPage>|Closure */
  protected string|Closure $creditsPage = MyAiCreditsPage::class;

  public function abuse(): Abuse
  {
    return app(Abuse::class);
  }

  public function credits(): Credits
  {
    return app(Credits::class);
  }

  public function controlsService(): Controls
  {
    return app(Controls::class);
  }

  /**
   * Tenant/scope that credits, controls and audit are partitioned by. Defaults to the panel id.
   * Server-only; a Closure may take Authenticatable $actor and Panel $panel and must return a string.
   */
  public function scope(string|Closure|null $scope): static
  {
    $this->scope = $scope;
    return $this;
  }

  public function getScope(): string
  {
    $actor = $this->panel?->auth()->user();
    if ($this->panel === null || $actor === null) {
      throw new LogicException('An authenticated plugin panel is required to resolve the AI Writer scope.');
    }
    $scope = $this->scope === null ? 'panel:' . $this->panel->getId() : $this->evaluate($this->scope, [
      'actor' => $actor,
      'user' => $actor,
      'panel' => $this->panel,
    ], [Authenticatable::class => $actor, $actor::class => $actor, Panel::class => $this->panel]);
    if (!is_string($scope)) {
      throw new LogicException('The AI Writer scope must resolve to a string.');
    }
    return $scope;
  }

  /** Search and resolve must restrict results to actors manageable in the supplied scope. */
  public function actorDirectoryUsing(Closure $search, Closure $resolve): static
  {
    $this->actorSearch = $search;
    $this->actorResolver = $resolve;
    return $this;
  }

  public function getActorSearch(): ?Closure
  {
    return $this->actorSearch;
  }

  public function getActorResolver(): ?Closure
  {
    return $this->actorResolver;
  }

  public function hasActorDirectory(): bool
  {
    return $this->actorSearch !== null && $this->actorResolver !== null;
  }

  public function controlContext(): ControlContext
  {
    $panel = $this->panel;
    $actor = $panel?->auth()->user();
    $id = $actor?->getAuthIdentifier();
    if ($panel === null || Filament::getCurrentPanel() !== $panel || $actor === null || (!is_int($id) && !is_string($id))) {
      throw new LogicException('An authenticated current plugin panel is required.');
    }
    return new ControlContext($panel->getId(), $panel->getAuthGuard(), $this->getScope(),
      new ActorIdentity($actor::class, $id));
  }

  public function searchActors(string $search, ControlContext $context): array
  {
    $this->controlsService()->authorize($context);
    if (!$this->hasActorDirectory()) {
      return [];
    }
    if (mb_strlen($search) > 100 || !mb_check_encoding($search, 'UTF-8')) {
      throw ValidationException::withMessages(['actor' => 'Search must contain at most 100 characters.']);
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
      if (!$actor instanceof ActorIdentity) {
        throw new LogicException('Actor search must return ActorIdentity results.');
      }
      $options[$actor->token()] = $actor->label !== '' ? $actor->label : $actor->id;
    }
    return $options;
  }

  public function resolveActor(string $token, ControlContext $context): ?ActorIdentity
  {
    $this->controlsService()->authorize($context);
    if (!$this->hasActorDirectory()) {
      return null;
    }
    try {
      $identity = ActorIdentity::fromToken($token);
    } catch (InvalidArgumentException) {
      return null;
    }
    $actor = ($this->actorResolver)($identity->type, $identity->id, $context->scope, $this->panel);
    if ($actor === null) {
      return null;
    }
    if (!$actor instanceof ActorIdentity || $actor->type !== $identity->type || $actor->id !== $identity->id) {
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

  /** Whether the topbar credits indicator is shown. Still requires controls() and enabled quotas. */
  public function creditsIndicator(bool|Closure $condition = true): static
  {
    $this->creditsIndicator = $condition;
    return $this;
  }

  public function hasCreditsIndicator(): bool
  {
    return $this->evaluateCondition($this->creditsIndicator);
  }

  /** The Users section; it also needs actorDirectoryUsing() and is unreachable without it. */
  public function usersTab(bool|Closure $condition = true): static
  {
    $this->usersTab = $condition;
    return $this;
  }

  public function hasUsersTab(): bool
  {
    return $this->evaluateCondition($this->usersTab) && $this->hasActorDirectory();
  }

  /** @param class-string<AiControlsPage>|Closure(): class-string<AiControlsPage> $page */
  public function controlsPage(string|Closure $page): static
  {
    if (is_string($page)) {
      $this->assertPage($page, AiControlsPage::class, 'Controls');
    }
    $this->controlsPage = $page;
    return $this;
  }

  /** @return class-string<AiControlsPage> */
  public function getControlsPage(): string
  {
    return $this->resolvePage($this->controlsPage, AiControlsPage::class, 'Controls');
  }

  /** @param class-string<MyAiCreditsPage>|Closure(): class-string<MyAiCreditsPage> $page */
  public function creditsPage(string|Closure $page): static
  {
    if (is_string($page)) {
      $this->assertPage($page, MyAiCreditsPage::class, 'Credits');
    }
    $this->creditsPage = $page;
    return $this;
  }

  /** @return class-string<MyAiCreditsPage> */
  public function getCreditsPage(): string
  {
    return $this->resolvePage($this->creditsPage, MyAiCreditsPage::class, 'Credits');
  }

  protected function resolvePage(string|Closure $page, string $base, string $label): string
  {
    $page = $this->evaluate($page, ['panel' => $this->panel], [Panel::class => $this->panel]);
    if (!is_string($page)) {
      throw new InvalidArgumentException($label . ' page must resolve to a class name.');
    }
    $this->assertPage($page, $base, $label);
    return $page;
  }

  protected function assertPage(string $page, string $base, string $label): void
  {
    if (!is_a($page, $base, true) || (new ReflectionClass($page))->isAbstract()) {
      throw new InvalidArgumentException($label . ' page must be a concrete ' . class_basename($base) . ' subclass.');
    }
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
    $panel->pages([$this->getControlsPage(), $this->getCreditsPage()]);
    $panel->renderHook(PanelsRenderHook::USER_MENU_BEFORE, function () use ($panel) {
      if (Filament::getCurrentPanel() !== $panel || !$this->isControlsEnabled() || !$this->hasCreditsIndicator()) { return ''; }
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
    return $this->evaluateCondition($this->usageTracking);
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
    return $this->evaluateCondition($this->controls);
  }

  protected function evaluateCondition(bool|Closure $condition): bool
  {
    return (bool) $this->evaluate($condition, ['panel' => $this->panel], [Panel::class => $this->panel]);
  }

  /** Permission to use the writer. Granted to any authenticated actor by default. A Closure may take Authenticatable $actor and Panel $panel. */
  public function authorizeUseAi(bool|Closure $condition = true): static
  {
    $this->useAiAuthorization = $condition;
    return $this;
  }

  /** Permission to read statistics. Denied by default. A Closure may take Authenticatable $actor and Panel $panel. */
  public function authorizeViewStatistics(bool|Closure $condition = true): static
  {
    $this->viewStatisticsAuthorization = $condition;
    return $this;
  }

  /** Permission to manage controls and credits. Denied by default. A Closure may take Authenticatable $actor and Panel $panel. */
  public function authorizeManage(bool|Closure $condition = true): static
  {
    $this->manageAuthorization = $condition;
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

  protected function authorize(bool|Closure|null $condition, bool $default): bool
  {
    $user = $this->panel?->auth()->user();

    if ($user === null) {
      return false;
    }

    return $condition === null ? $default : (bool) $this->evaluate($condition, [
      'actor' => $user,
      'user' => $user,
      'panel' => $this->panel,
    ], [Authenticatable::class => $user, $user::class => $user, Panel::class => $this->panel]);
  }
}
