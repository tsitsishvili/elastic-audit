@extends('elastic-audit::activity.layout')

@section('title', 'Event Detail · Activity Logs')

@section('content')
@php
    $fmtTs = function (?string $ts) use ($timezone): string {
        if (! $ts) return '—';
        try { return \Illuminate\Support\Carbon::parse($ts)->timezone($timezone)->format('Y-m-d H:i:s T'); }
        catch (\Throwable) { return (string) $ts; }
    };
@endphp

<div class="mb-4">
    <a href="{{ route('activity-logs.logs.index') }}" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← Back to list</a>
</div>

@if($error)
    <div class="rounded bg-red-50 p-4 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $error }}</div>
@elseif($log)
@php $changes = $log['changes'] ?? []; @endphp

<div class="space-y-6">
    {{-- Summary card --}}
    <div class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
            <div>
                <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Action</div>
                <div class="mt-1 font-mono text-sm font-semibold">{{ $log['action'] ?? '—' }}</div>
            </div>
            <div>
                <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Time</div>
                <div class="mt-1 text-sm">{{ $fmtTs($log['@timestamp'] ?? null) }}</div>
            </div>
            <div>
                <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Result</div>
                <div class="mt-1">
                    @if($log['success'] ?? true)
                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400">success</span>
                    @else
                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/40 dark:text-red-400">failure</span>
                    @endif
                </div>
            </div>
            <div>
                <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Actor</div>
                <div class="mt-1 text-sm">
                    {{ $log['actor']['type'] ?? '—' }}
                    @if(!empty($log['actor']['id'])) <span class="text-slate-400">#{{ $log['actor']['id'] }}</span> @endif
                </div>
            </div>
            <div>
                <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Entity</div>
                <div class="mt-1 text-sm">
                    {{ $log['entity']['type'] ?? '—' }} <span class="text-slate-400">#{{ $log['entity']['id'] ?? '—' }}</span>
                </div>
            </div>
            <div>
                <div class="text-xs font-medium uppercase tracking-wide text-slate-500">Request ID</div>
                <div class="mt-1 font-mono text-xs text-slate-500 break-all">{{ $log['request_id'] ?? '—' }}</div>
            </div>
        </div>
    </div>

    {{-- Changes --}}
    @if(! empty($changes))
    <div class="rounded-lg bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-700">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300">Changes</h3>
        </div>
        <table class="min-w-full divide-y divide-slate-100 dark:divide-slate-700 text-sm">
            <thead class="bg-slate-50 dark:bg-slate-900/50">
                <tr>
                    <th class="px-4 py-2.5 text-left font-medium text-slate-500 w-1/4">Field</th>
                    <th class="px-4 py-2.5 text-left font-medium text-slate-500 w-5/12">Old value</th>
                    <th class="px-4 py-2.5 text-left font-medium text-slate-500 w-5/12">New value</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700/50">
                @foreach($changes as $field => $diff)
                <tr>
                    <td class="px-4 py-2.5 font-mono text-xs font-medium">{{ $field }}</td>
                    <td class="px-4 py-2.5 font-mono text-xs text-slate-500">
                        @if($diff['old'] === null)
                            <span class="italic text-slate-400">null</span>
                        @else
                            {{ is_array($diff['old']) ? json_encode($diff['old']) : $diff['old'] }}
                        @endif
                    </td>
                    <td class="px-4 py-2.5 font-mono text-xs text-emerald-700 dark:text-emerald-400">
                        {{ is_array($diff['new']) ? json_encode($diff['new']) : $diff['new'] }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- Metadata --}}
    @if(! empty($log['metadata']))
    <div class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
        <h3 class="mb-3 text-sm font-semibold text-slate-700 dark:text-slate-300">Metadata</h3>
        <pre class="overflow-auto rounded bg-slate-50 p-3 font-mono text-xs dark:bg-slate-900">{{ json_encode($log['metadata'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
    @endif

    {{-- Error --}}
    @if(! empty($log['error']['class']))
    <div class="rounded-lg bg-red-50 p-6 shadow-sm ring-1 ring-red-100 dark:bg-red-900/20 dark:ring-red-900/50">
        <h3 class="mb-2 text-sm font-semibold text-red-700 dark:text-red-400">Error</h3>
        <p class="font-mono text-xs text-red-600 dark:text-red-400">{{ $log['error']['class'] }}</p>
        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $log['error']['message'] }}</p>
    </div>
    @endif
</div>
@endif
@endsection
