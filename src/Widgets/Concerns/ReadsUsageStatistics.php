<?php

namespace PdroLucas\FilamentAiWriter\Widgets\Concerns;

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Attributes\Locked;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Support\HostDates;
use PdroLucas\FilamentAiWriter\Usage\UsageStatistics;

/** Shared access rules for the usage widgets: Statistics permission, panel fence and default period. */
trait ReadsUsageStatistics
{
  #[Locked]
  public string $panelId = '';

  /** Filters chosen on the Statistics page. Empty means the last seven days, as on that page. */
  #[Locked]
  public array $sharedFilters = [];

  public static function canView(): bool
  {
    return UsageStatistics::canAccess();
  }

  public function hydrate(): void
  {
    $this->authorizeContext();
  }

  protected function bootUsageContext(): void
  {
    // On a host dashboard nobody passes the panel: the widget belongs to the panel that renders it.
    if ($this->panelId === '') {
      $this->panelId = Filament::getCurrentPanel()->getId();
    }
    $this->authorizeContext();
  }

  protected function authorizeContext(): void
  {
    abort_unless(UsageStatistics::canAccess() && $this->panelId === Filament::getCurrentPanel()->getId(), 403);
  }

  /** @return array<string, mixed> */
  protected function usageFilters(): array
  {
    if ($this->sharedFilters !== []) {
      return $this->sharedFilters;
    }
    $today = CarbonImmutable::now(config('app.timezone', 'UTC'));

    return ['period' => ['from' => $today->subDays(6)->toDateString(), 'until' => $today->toDateString()]];
  }

  /** Computed once per request: the chart, its description and its screen-reader table all read the same rows. */
  protected ?array $usageSummaryCache = null;

  /** @return array<string, mixed> */
  protected function usageSummary(): array
  {
    $this->authorizeContext();

    return $this->usageSummaryCache ??= AiWriterPlugin::get()->statistics()->dashboard($this->usageFilters());
  }

  /** Bucket labels come from the service as ISO days ("2026-10-01" or "2026-10-01 – 2026-10-03"); they are shown in the host's date format. */
  protected function bucketLabel(string $label): string
  {
    return implode(' – ', array_map(fn (string $day): string => HostDates::date($day), explode(' – ', $label)));
  }
}
