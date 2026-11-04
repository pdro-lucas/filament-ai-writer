<table>
    <caption>{{ __('Request counts by date') }} · {{ __(':days-day buckets', ['days' => $bucketDays]) }} · {{ $timezone }}</caption>
    <thead><tr><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Requests') }}</th></tr></thead>
    <tbody>
        @forelse ($rows as $row)
            <tr><th scope="row">{{ $row['label'] }}</th><td>{{ $row['delivered'] + $row['failed'] + $row['active'] }}</td></tr>
        @empty
            <tr><td colspan="2">{{ __('No requests match these filters.') }}</td></tr>
        @endforelse
    </tbody>
</table>
