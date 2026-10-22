@include('filament-ai-writer::usage-styles')
<div class="ai-usage-detail">
    <p>{{ $attempt->model ?? 'Unknown model' }} <span class="ai-usage-muted">· {{ $attempt->provider ?? 'Unknown provider' }} · {{ match ($attempt->status) { 'delivered' => 'Success', 'failed' => 'Failure', default => 'In progress' } }}</span></p>
    <dl class="ai-usage-detail-summary">
        <div><dt class="ai-usage-muted">Input → output tokens</dt><dd>{{ $attempt->input_tokens ?? 'Unavailable' }} → {{ $attempt->output_tokens ?? 'Unavailable' }}</dd></div>
        <div><dt class="ai-usage-muted">Cost · USD</dt><dd>{{ $attempt->cost_usd !== null ? rtrim(rtrim($attempt->cost_usd, '0'), '.') : 'Unavailable' }}</dd><dd class="ai-usage-muted">{{ match ($attempt->cost_source) { 'provider' => 'Provider-reported', 'estimated' => 'Estimated', default => 'Unavailable' } }}</dd></div>
        <div><dt class="ai-usage-muted">HTTP duration</dt><dd>{{ $attempt->duration_ms !== null ? number_format($attempt->duration_ms / 1000, 2) . ' s' : 'Unavailable' }}</dd></div>
    </dl>
    @php
        $groups = [
            'Request' => [
                'Started' => $attempt->started_at->setTimezone($timezone)->format('Y-m-d H:i:s') . ' · ' . $timezone,
                'User ID' => $attempt->actor_id, 'User type' => $attempt->actor_type,
                'Provider' => $attempt->provider, 'Actual model' => $attempt->model, 'Requested model' => $attempt->requested_model,
                'Field' => $attempt->field, 'Action' => $attempt->action_name,
            ],
            'Consumption & cost' => [
                'Input tokens' => $attempt->input_tokens, 'Output tokens' => $attempt->output_tokens,
                'Cached input¹' => $attempt->cached_input_tokens, 'Cache write input¹' => $attempt->cache_write_input_tokens,
                'Reasoning output¹' => $attempt->reasoning_output_tokens,
                'Exact cost · USD' => $attempt->cost_usd,
                'Cost source' => match ($attempt->cost_source) { 'provider' => 'Provider-reported', 'estimated' => 'Estimated', default => 'Unavailable' },
                'Input tariff · USD / 1M' => $attempt->input_rate_usd_per_million, 'Output tariff · USD / 1M' => $attempt->output_rate_usd_per_million,
                'HTTP duration · ms' => $attempt->duration_ms, 'Local overhead · ms' => $attempt->local_duration_ms,
            ],
            'Identifiers & outcome' => [
                'Operation ID' => $attempt->operation_id, 'Attempt' => $attempt->attempt_number,
                'Request ID' => $attempt->request_id, 'Response ID' => $attempt->response_id,
                'Outcome' => $attempt->outcome, 'Finish reason' => $attempt->finish_reason,
                'HTTP status' => $attempt->http_status, 'Failure code' => $attempt->failure_code,
                'Remote outcome' => $attempt->outcome_unknown ? 'Unknown' : null,
            ],
        ];
    @endphp
    @foreach ($groups as $title => $fields)
        <section>
            <h3>{{ $title }}</h3>
            <dl class="ai-usage-detail-meta">
                @foreach ($fields as $label => $value)
                    @if ($value !== null || in_array($label, ['Actual model', 'Provider', 'Input tokens', 'Output tokens', 'Exact cost · USD', 'HTTP duration · ms'], true))
                        <dt>{{ $label }}</dt><dd>{{ $value ?? 'Unavailable' }}</dd>
                    @endif
                @endforeach
            </dl>
        </section>
    @endforeach
    <p class="ai-usage-muted">¹ Included in input/output totals when measured; do not add again. Metadata only.</p>
</div>
