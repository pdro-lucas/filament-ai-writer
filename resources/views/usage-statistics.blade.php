<x-filament-panels::page>
    {{-- Native widgets, remounted whenever the shared filters change. --}}
    @livewire(\PdroLucas\FilamentAiWriter\Widgets\UsageOverviewStats::class, ['panelId' => $panel, 'sharedFilters' => $filters], key('usage-overview-' . $widgetKey))
    @livewire(\PdroLucas\FilamentAiWriter\Widgets\UsageCountChart::class, ['panelId' => $panel, 'sharedFilters' => $filters], key('usage-chart-' . $widgetKey))
    {{ $this->content }}
</x-filament-panels::page>
