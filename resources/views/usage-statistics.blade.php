<x-filament-panels::page>
    <x-filament::section heading="Current panel usage">
        <p>Panel: {{ $panel }}. Dates use {{ $timezone }}. All actor metadata in this panel is included.</p>
        <p>Totals follow the history filters and include only retained records. Costs are known subtotals, not a complete bill.</p>
        <dl>
            <dt>Attempts / delivered / failed / unresolved</dt>
            <dd>{{ $summary['attempts'] }} / {{ $summary['delivered'] }} / {{ $summary['failed'] }} / {{ $summary['active'] }}</dd>
            <dt>Provider-reported USD subtotal</dt>
            <dd>{{ $summary['provider_cost'] }} ({{ $summary['provider_cost_count'] }} measured attempts)</dd>
            <dt>Estimated USD subtotal</dt>
            <dd>{{ $summary['estimated_cost'] }} ({{ $summary['estimated_cost_count'] }} estimated attempts)</dd>
            <dt>Attempts with unavailable cost</dt>
            <dd>{{ $summary['unknown_cost'] }}</dd>
            @foreach (['input_tokens' => 'Input tokens', 'output_tokens' => 'Output tokens', 'duration_ms' => 'HTTP duration (ms)', 'local_duration_ms' => 'Local overhead (ms)'] as $key => $label)
                <dt>{{ $label }} — known subtotal</dt>
                <dd>{{ $summary[$key]['total'] }} ({{ $summary[$key]['known'] }} measured, {{ $summary[$key]['unknown'] }} unknown)</dd>
            @endforeach
        </dl>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
