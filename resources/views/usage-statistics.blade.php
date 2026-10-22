<x-filament-panels::page>
    @php
        $knownCost = $summary['provider_cost_count'] + $summary['estimated_cost_count'];
        $cost = \PdroLucas\FilamentAiWriter\Support\Decimal::sum($summary['provider_cost'], $summary['estimated_cost']);
        $tokens = \PdroLucas\FilamentAiWriter\Support\Decimal::addIntegers($summary['input_tokens']['total'], $summary['output_tokens']['total']);
        $measuredTokens = $summary['input_tokens']['known'] + $summary['output_tokens']['known'];
        $duration = $summary['duration_ms'];
        $period = $filters['period'] ?? [];
        $filterForm = $this->getTableFiltersForm();
    @endphp
    @include('filament-ai-writer::usage-styles')
    <div class="ai-usage">
        <div class="ai-usage-toolbar">
            <span class="ai-usage-muted">{{ $period['from'] ?? 'All history' }} — {{ $period['until'] ?? 'Today' }} · {{ $timezone }}</span>
            <div class="ai-usage-controls" aria-label="Usage filters">
                <x-filament::dropdown :menu="false" placement="bottom-end" shift width="xs"
                    :wire:key="$this->getId() . '.usage.filters'">
                    <x-slot name="trigger">
                        <x-filament::button size="sm" color="gray" icon="heroicon-m-adjustments-horizontal">Filters</x-filament::button>
                    </x-slot>
                    <div class="ai-usage-popover" role="group" aria-label="Usage filters" x-data="{ showCustom: false }">
                        <div class="ai-usage-presets" role="group" aria-label="Period">
                            <span class="ai-usage-filter-label">Period</span>
                            @foreach (['1' => 'Today', '7' => '7 days', '30' => '30 days'] as $value => $label)
                                <button type="button" class="ai-usage-preset" wire:click="setPeriod({{ $value }})"
                                    x-on:click="showCustom = false"
                                    x-bind:aria-pressed="!showCustom && $wire.periodPreset === '{{ $value }}'">
                                    <span>{{ $label }}</span>
                                    <x-filament::icon icon="heroicon-m-check" class="ai-usage-preset-check"
                                        x-show="!showCustom && $wire.periodPreset === '{{ $value }}'" x-cloak />
                                </button>
                            @endforeach
                            <button type="button" class="ai-usage-preset" x-on:click="showCustom = true"
                                x-bind:aria-pressed="showCustom || $wire.periodPreset === 'custom'">
                                <span>Custom range</span>
                                <x-filament::icon icon="heroicon-m-check" class="ai-usage-preset-check"
                                    x-show="showCustom || $wire.periodPreset === 'custom'" x-cloak />
                            </button>
                        </div>
                        <div x-show="showCustom || $wire.periodPreset === 'custom'" x-cloak role="group" aria-label="Custom date range">
                            {{-- Render each group from the cached table schema once, keeping its original state path. --}}
                            {{ $filterForm->getComponentByStatePath('period') }}
                        </div>
                        {{ $filterForm->getComponentByStatePath('actor') }}
                        {{ $filterForm->getComponentByStatePath('status') }}
                        <div><x-filament::button size="sm" color="gray" wire:click="resetUsageFilters" x-on:click="showCustom = false; close()">Reset filters</x-filament::button></div>
                    </div>
                </x-filament::dropdown>
            </div>
        </div>

        <dl class="ai-usage-metrics" aria-label="Usage overview">
            <div>
                <dt>Requests</dt><dd class="ai-usage-value">{{ number_format($summary['attempts']) }}</dd>
                <dd class="ai-usage-muted">{{ number_format($summary['delivered']) }} success · {{ number_format($summary['failed']) }} failure · {{ number_format($summary['active']) }} in progress</dd>
            </div>
            <div>
                <dt>Tokens consumed</dt><dd class="ai-usage-value">{{ $measuredTokens ? $tokens : 'Unavailable' }}</dd>
                <dd class="ai-usage-muted">Input + output{{ $measuredTokens < $summary['attempts'] * 2 ? ' · Partial' : '' }} · {{ $measuredTokens }}/{{ $summary['attempts'] * 2 }} counters measured</dd>
            </div>
            <div>
                <dt>Costs <span class="ai-usage-muted">USD</span></dt><dd class="ai-usage-value">{{ $knownCost ? '$' . rtrim(rtrim($cost, '0'), '.') : 'Unavailable' }}</dd>
                <dd class="ai-usage-muted">{{ $summary['unknown_cost'] && $knownCost ? 'Partial · ' : '' }}{{ $knownCost }}/{{ $summary['attempts'] }} known{{ $summary['estimated_cost_count'] ? ' · Includes estimates' : '' }}</dd>
                <dd>
                    <details class="ai-usage-coverage">
                        <summary>Cost coverage</summary>
                        <p class="ai-usage-muted">Reported: {{ $summary['provider_cost_count'] ? $summary['provider_cost'] . ' USD' : 'Unavailable' }} · Estimated: {{ $summary['estimated_cost_count'] ? $summary['estimated_cost'] . ' USD' : 'Unavailable' }}. {{ $summary['unknown_cost'] }} unavailable. Known costs only; retained plugin requests, not a complete bill.</p>
                    </details>
                </dd>
            </div>
            <div>
                <dt>Average duration</dt><dd class="ai-usage-value">{{ $duration['known'] ? number_format((float) $duration['total'] / $duration['known'] / 1000, 2) . ' s' : 'Unavailable' }}</dd>
                <dd class="ai-usage-muted">HTTP · {{ $duration['known'] }}/{{ $summary['attempts'] }} measured{{ $duration['unknown'] && $duration['known'] ? ' · Partial' : '' }}</dd>
            </div>
        </dl>

        <div class="ai-usage-requests">
            <div class="ai-usage-chart">
                @livewire(\PdroLucas\FilamentAiWriter\Widgets\UsageCountChart::class, ['panelId' => $panel, 'sharedFilters' => $filters], key('usage-' . $panel . '-' . hash('sha256', json_encode($filters))))
                <div class="ai-usage-chart-data">
                    <table class="ai-usage-data">
                        <caption>Request counts by date · {{ $summary['charts']['bucket_days'] }}-day buckets · {{ $timezone }}</caption>
                        <thead><tr><th scope="col">Date</th><th scope="col">Requests</th></tr></thead>
                        <tbody>
                            @forelse ($summary['charts']['time'] as $row)
                                <tr><th scope="row">{{ $row['label'] }}</th><td>{{ $row['delivered'] + $row['failed'] + $row['active'] }}</td></tr>
                            @empty
                                <tr><td colspan="2">No requests match these filters.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="ai-usage-history" role="region" aria-label="Request history">
                {{ $this->table }}
            </div>
        </div>
    </div>
</x-filament-panels::page>
