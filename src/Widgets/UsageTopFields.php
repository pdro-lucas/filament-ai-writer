<?php

namespace PdroLucas\FilamentAiWriter\Widgets;

use Filament\Widgets\TableWidget;
use PdroLucas\FilamentAiWriter\Widgets\Concerns\RanksUsage;

/** The five fields with the most requests under the Statistics page filters. */
class UsageTopFields extends TableWidget
{
  use RanksUsage;

  protected static bool $isLazy = false;

  protected function rankingHeading(): string
  {
    return __('filament-ai-writer::statistics.insights.top_fields');
  }

  protected function rankingColumnLabel(): string
  {
    return __('filament-ai-writer::statistics.filter.field');
  }

  protected function rankingKind(): string
  {
    return 'field';
  }

  protected function rankedRows(): array
  {
    $rows = [];
    foreach ($this->statistics()->top($this->usageFilters(), 'field') as $row) {
      $rows[$row['key']] = ['key' => $row['key'], 'name' => $row['field'], 'detail' => null, 'requests' => $row['requests']];
    }

    return $rows;
  }
}
