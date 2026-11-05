<table>
    <caption>{{ __('filament-ai-writer::statistics.chart.caption', ['days' => $bucketDays, 'timezone' => $timezone]) }}</caption>
    <thead><tr><th scope="col">{{ __('filament-ai-writer::statistics.chart.date') }}</th><th scope="col">{{ __('filament-ai-writer::statistics.chart.requests') }}</th></tr></thead>
    <tbody>
        @forelse ($rows as $row)
            <tr><th scope="row">{{ $row['label'] }}</th><td>{{ $row['delivered'] + $row['failed'] + $row['active'] }}</td></tr>
        @empty
            <tr><td colspan="2">{{ __('filament-ai-writer::statistics.chart.empty') }}</td></tr>
        @endforelse
    </tbody>
</table>
