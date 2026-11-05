<?php

namespace PdroLucas\FilamentAiWriter\Widgets;

use Filament\Widgets\TableWidget;
use PdroLucas\FilamentAiWriter\Usage\UsageStatistics;
use PdroLucas\FilamentAiWriter\Widgets\Concerns\RanksUsage;

/** The five people with the most requests under the Statistics page filters. */
class UsageTopUsers extends TableWidget
{
  use RanksUsage;

  protected function rankingHeading(): string
  {
    return __('filament-ai-writer::statistics.insights.top_users');
  }

  protected function rankingColumnLabel(): string
  {
    return __('filament-ai-writer::statistics.filter.user');
  }

  protected function rankingKind(): string
  {
    return 'actor';
  }

  protected function rankedRows(): array
  {
    $rows = [];
    foreach ($this->statistics()->top($this->usageFilters(), 'actor') as $row) {
      [$name, $email] = UsageStatistics::splitActorLabel($this->statistics()->actorLabel($row['actor_type'], $row['actor_id']));
      $rows[$row['key']] = ['key' => $row['key'], 'name' => $name, 'detail' => $email, 'requests' => $row['requests']];
    }

    return $rows;
  }
}
