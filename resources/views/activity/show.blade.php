@extends('elastic-audit::layout')

@section('dashboard', 'activity')

@section('title', 'Event Detail · Activity Logs')

@section('content')
@php
    $fmtTs = function (?string $ts) use ($timezone): string {
        if (! $ts) return '—';
        try { return \Illuminate\Support\Carbon::parse($ts)->timezone($timezone)->format('Y-m-d H:i:s T'); }
        catch (\Throwable) { return (string) $ts; }
    };
    $fmtValue = function (mixed $value): string {
        if (is_string($value)) return $value;
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_int($value) || is_float($value)) return (string) $value;
        if ($value === null) return 'null';

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '[unrenderable value]' : $encoded;
    };
@endphp

<a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('activity-logs.logs.index', [], false) }}"
   class="ea-focus mb-4 inline-flex h-9 items-center gap-1 rounded-md text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">← Back to activity</a>

{{-- Query errors are shown by the layout banner. --}}
@if($log)
@php
    $changes = is_array($log['changes'] ?? null) ? $log['changes'] : [];
    $actor   = trim(($log['actor']['type'] ?? '') . (! empty($log['actor']['id']) ? ' #' . $log['actor']['id'] : ''));
    $meta    = [
        'Actor'            => $actor,
        'Entity'           => trim(($log['entity']['type'] ?? '') . (! empty($log['entity']['id']) ? ' #' . $log['entity']['id'] : '')),
        'Application'      => data_get($log, 'service.name'),
        'Environment'      => data_get($log, 'service.environment'),
        'Execution type'   => data_get($log, 'execution.type'),
        'Execution name'   => data_get($log, 'execution.name'),
        'Execution action' => data_get($log, 'execution.action'),
        'Request ID'       => $log['request_id'] ?? null,
        'Trace ID'         => data_get($log, 'trace.id'),
        'Span ID'          => data_get($log, 'trace.span_id'),
    ];
@endphp

<div class="space-y-6">
    <div class="ea-panel rounded-lg border p-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $actor ?: 'Unknown actor' }}</p>
                <h1 class="mt-1 break-words font-mono text-2xl font-semibold tracking-normal text-slate-950 dark:text-slate-50">{{ $log['action'] ?? 'Activity detail' }}</h1>
            </div>
            <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                @if($log['success'] ?? true)
                    <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">success</span>
                @else
                    <span class="rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700 dark:bg-red-950/50 dark:text-red-300">failure</span>
                @endif
                <span class="font-mono text-xs text-slate-400" title="{{ $log['@timestamp'] ?? '' }}">{{ $fmtTs($log['@timestamp'] ?? null) }}</span>
            </div>
        </div>
    </div>

    <div class="ea-panel rounded-lg border p-5">
        <dl class="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($meta as $label => $value)
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ $label }}</dt>
                    <dd class="mt-0.5 break-all font-mono text-sm text-slate-700 dark:text-slate-300">{{ ($value === null || $value === '') ? '—' : $value }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

    {{-- Changes --}}
    @if(! empty($changes))
    <div class="ea-panel overflow-hidden rounded-lg border">
        <div class="border-b border-slate-200 px-5 py-3 dark:border-slate-700">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Changes</h2>
        </div>
        <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
            <thead class="bg-slate-50/90 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-900/70 dark:text-slate-400">
                <tr>
                    <th class="w-1/4 px-4 py-3 font-medium">Field</th>
                    <th class="w-5/12 px-4 py-3 font-medium">Old value</th>
                    <th class="w-5/12 px-4 py-3 font-medium">New value</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                @foreach($changes as $field => $diff)
                @php
                    $isDiff = is_array($diff)
                        && array_key_exists('old', $diff)
                        && array_key_exists('new', $diff);
                    $oldValue = $isDiff ? $diff['old'] : null;
                    $newValue = $isDiff ? $diff['new'] : $diff;
                @endphp
                <tr>
                    <td class="px-4 py-3 font-mono text-xs font-medium text-slate-700 dark:text-slate-200">{{ $field }}</td>
                    <td class="break-all px-4 py-3 font-mono text-xs text-slate-500 dark:text-slate-400">
                        @if($oldValue === null)
                            <span class="italic text-slate-400">null</span>
                        @else
                            {{ $fmtValue($oldValue) }}
                        @endif
                    </td>
                    <td class="break-all px-4 py-3 font-mono text-xs text-emerald-700 dark:text-emerald-400">
                        @if($newValue === null)
                            <span class="italic text-slate-400">null</span>
                        @else
                            {{ $fmtValue($newValue) }}
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>
    @endif

    {{-- Metadata --}}
    @if(! empty($log['metadata']))
    <div class="ea-panel rounded-lg border p-5">
        <h2 class="mb-3 text-sm font-semibold text-slate-900 dark:text-slate-100">Metadata</h2>
        <pre class="max-h-96 overflow-auto rounded-md bg-slate-950 p-3 font-mono text-xs leading-relaxed text-slate-100 ring-1 ring-slate-800">{{ json_encode($log['metadata'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
    @endif

    {{-- Error --}}
    @if(! empty($log['error']['class']))
    <div class="rounded-lg border border-red-200 bg-red-50 p-5 shadow-sm dark:border-red-900 dark:bg-red-950">
        <h2 class="text-sm font-semibold text-red-800 dark:text-red-300">Error</h2>
        <p class="mt-1 font-mono text-xs text-red-700 dark:text-red-400">{{ $log['error']['class'] }}</p>
        <pre class="mt-2 overflow-x-auto whitespace-pre-wrap text-xs text-red-700 dark:text-red-400">{{ $log['error']['message'] ?? '' }}</pre>
    </div>
    @endif
</div>
@endif
@endsection
