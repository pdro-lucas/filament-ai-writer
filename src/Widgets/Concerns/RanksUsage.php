<?php

namespace PdroLucas\FilamentAiWriter\Widgets\Concerns;

use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;

/** A compact "top 5" list: the ranking comes from the usage service under the page filters, and its rows only narrow the page. */
trait RanksUsage
{
  use ReadsUsageStatistics;

  public function mount(): void
  {
    $this->bootUsageContext();
  }

  /** @return array<string, array{name: string, detail: ?string, requests: int, key: string}> Keyed by the value the page filter takes. */
  abstract protected function rankedRows(): array;

  abstract protected function rankingHeading(): string;

  /** The filter the explore action applies on the page: `actor` or `field`. */
  abstract protected function rankingKind(): string;

  /** The single data column's header: the card heading already names the ranking. */
  abstract protected function rankingColumnLabel(): string;

  public function table(Table $table): Table
  {
    return $table
      ->heading($this->rankingHeading())
      ->records(function (): array {
        $this->authorizeContext();

        return $this->rankedRows();
      })
      ->paginated(false)
      ->emptyStateHeading(__('filament-ai-writer::statistics.insights.empty'))
      ->columns([
        // One line per row: long names and e-mails are clamped, and the full text is in the tooltip.
        TextColumn::make('name')->label($this->rankingColumnLabel())->lineClamp(1)
          ->description(fn (array $record): ?string => $record['detail'] === null ? null : Str::limit($record['detail'], 40))
          ->tooltip(fn (array $record): string => trim($record['name'] . ' ' . $record['detail'])),
        TextColumn::make('requests')->label(__('filament-ai-writer::statistics.chart.requests'))->alignEnd()->wrap(false)->badge()->color('gray')
          ->formatStateUsing(fn (int $state): string => trans_choice('filament-ai-writer::statistics.insights.requests_count', $state, ['count' => number_format($state)])),
      ])
      ->recordActions([
        Action::make('explore')->label(__('filament-ai-writer::statistics.insights.explore'))->link()->color('gray')
          // The row the page is already narrowed to has nothing left to explore.
          ->visible(fn (array $record): bool => ($this->usageFilters()[$this->rankingKind()]['value'] ?? null) !== $record['key'])
          ->action(function (array $record): void {
            $this->authorizeContext();
            $this->dispatch('ai-writer-usage-explore', kind: $this->rankingKind(), value: $record['key']);
          }),
      ]);
  }

  protected function statistics(): \PdroLucas\FilamentAiWriter\Usage\UsageStatistics
  {
    $this->authorizeContext();

    return AiWriterPlugin::get()->statistics();
  }
}
