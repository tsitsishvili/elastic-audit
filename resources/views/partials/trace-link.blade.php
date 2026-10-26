{{-- Trace ID as a link to the trace page. `compact` (list rows) renders a short "trace ↗" link, or nothing when it can't link. --}}
@php
    $traceId  = $traceId ?? null;
    $linkable = is_string($traceId)
        && preg_match('/^[a-f0-9]{32}$/', $traceId) === 1
        && app(\Tsitsishvili\ElasticAudit\Dashboard\DashboardAccess::class)->enabled(\Tsitsishvili\ElasticAudit\Dashboard\DashboardAccess::METRICS);
@endphp
@if ($linkable)
    <a href="{{ route('elastic-audit-metrics.traces.show', $traceId, false) }}" onclick="event.stopPropagation()"
       class="ea-focus rounded-xs font-mono text-indigo-600 hover:underline dark:text-indigo-400" @if (! empty($compact)) title="{{ $traceId }}" @endif>{{ ! empty($compact) ? 'trace ↗' : $traceId }}</a>
@elseif (! empty($compact))
@elseif ($traceId === null || $traceId === '')
    —
@else
    {{ $traceId }}
@endif
