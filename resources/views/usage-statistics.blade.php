<x-filament-panels::page>
    @php
        $knownCost = $summary['provider_cost_count'] + $summary['estimated_cost_count'];
        $cost = \PdroLucas\FilamentAiWriter\Support\Decimal::sum($summary['provider_cost'], $summary['estimated_cost']);
        $tokens = \PdroLucas\FilamentAiWriter\Support\Decimal::addIntegers($summary['input_tokens']['total'], $summary['output_tokens']['total']);
        $measuredTokens = $summary['input_tokens']['known'] + $summary['output_tokens']['known'];
        $duration = $summary['duration_ms'];
        $period = $filters['period'] ?? [];
    @endphp
    @include('filament-ai-writer::usage-styles')
    <div class="ai-usage" x-data="{ filtersOpen: @js($periodPreset === 'custom') }">
        <div class="ai-usage-toolbar">
            <span class="ai-usage-muted">{{ $period['from'] ?? 'All history' }} — {{ $period['until'] ?? 'Today' }} · {{ $timezone }}</span>
            <div class="ai-usage-controls" aria-label="Usage filters">
                <label for="ai-usage-period" class="ai-usage-muted">Period</label>
                <select id="ai-usage-period" x-model="$wire.periodPreset" class="ai-usage-period" aria-controls="ai-usage-filters"
                    x-on:change="if ($event.target.value === 'custom') { filtersOpen = true } else { $wire.setPeriod(Number($event.target.value)) }">
                    @foreach (['1' => 'Today', '7' => '7 days', '30' => '30 days', 'custom' => 'Custom range'] as $value => $label)
                        <option value="{{ $value }}" @selected((string) $value === $periodPreset)>{{ $label }}</option>
                    @endforeach
                </select>
                <x-filament::button size="sm" color="gray" icon="heroicon-m-adjustments-horizontal"
                    x-on:click="filtersOpen = ! filtersOpen" x-bind:aria-expanded="filtersOpen" aria-controls="ai-usage-filters">Filters</x-filament::button>
            </div>
        </div>
        <div id="ai-usage-filters" class="ai-usage-filter-form" x-show="filtersOpen" x-cloak role="region" aria-label="Usage filters">
            {{ $this->getTableFiltersForm() }}
            <div><x-filament::button size="sm" color="gray" wire:click="resetUsageFilters">Reset filters</x-filament::button></div>
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
