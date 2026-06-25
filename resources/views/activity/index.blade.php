@extends('elastic-audit::activity.layout')

@section('title', 'Activity Logs')

@section('content')
@php
    $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
    $page       = min($page, $totalPages);
    $from       = $total > 0 ? ($page - 1) * $perPage + 1 : 0;
    $to         = min($page * $perPage, $total);

    $fmtTs = function (?string $ts) use ($timezone): string {
        if (! $ts) return '—';
        try { return \Illuminate\Support\Carbon::parse($ts)->timezone($timezone)->format('M j, H:i:s'); }
        catch (\Throwable) { return (string) $ts; }
    };

    $withParams = fn (array $overrides) => request()->fullUrlWithQuery(array_merge($filters, $overrides, ['page' => 1]));
    $pageUrl    = fn (int $p) => request()->fullUrlWithQuery(array_merge($filters, ['page' => $p]));

    $hasFilters = ! empty($filters);
@endphp

<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
    {{-- Filters form --}}
    <form method="GET" class="flex flex-wrap items-center gap-2">
        <select name="action" onchange="this.form.submit()" class="rounded border border-slate-300 bg-white px-2 py-1 text-sm dark:border-slate-600 dark:bg-slate-800">
            <option value="">All actions</option>
            @foreach($options['actions'] as $a)
                <option value="{{ $a }}" @selected(($filters['action'] ?? '') === $a)>{{ $a }}</option>
            @endforeach
        </select>

        <select name="actor_type" onchange="this.form.submit()" class="rounded border border-slate-300 bg-white px-2 py-1 text-sm dark:border-slate-600 dark:bg-slate-800">
            <option value="">All actor types</option>
            @foreach($options['actor_types'] as $t)
                <option value="{{ $t }}" @selected(($filters['actor_type'] ?? '') === $t)>{{ $t }}</option>
            @endforeach
        </select>

        <select name="success" onchange="this.form.submit()" class="rounded border border-slate-300 bg-white px-2 py-1 text-sm dark:border-slate-600 dark:bg-slate-800">
            <option value="">All results</option>
            <option value="1" @selected(($filters['success'] ?? '') === '1')>Success</option>
            <option value="0" @selected(($filters['success'] ?? '') === '0')>Failure</option>
        </select>

        <input type="text" name="entity_id" value="{{ $filters['entity_id'] ?? '' }}" placeholder="Entity ID"
               class="rounded border border-slate-300 bg-white px-2 py-1 text-sm dark:border-slate-600 dark:bg-slate-800"
               onchange="this.form.submit()">

        @if($hasFilters)
            <a href="{{ route('activity-logs.logs.index') }}" class="text-sm text-red-500 hover:underline">Clear filters</a>
        @endif
    </form>

    {{-- Live: auto-refresh the list to surface new activity as it lands. --}}
    <button type="button"
            x-data="{
                on: localStorage.getItem('tphl_live_activity') === '1',
                timer: null,
                toggle() {
                    this.on = !this.on;
                    localStorage.setItem('tphl_live_activity', this.on ? '1' : '0');
                    if (this.on) { this.timer = setInterval(() => location.reload(), 10000); }
                    else if (this.timer) { clearInterval(this.timer); this.timer = null; }
                },
            }"
            x-init="if (on) { timer = setInterval(() => location.reload(), 10000); }"
            @click="toggle()"
            :class="on
                ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'
                : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700'"
            :title="on ? 'Live: refreshing every 10s' : 'Auto-refresh the list every 10s'"
            class="inline-flex items-center gap-1.5 rounded border px-2.5 py-1 text-sm font-medium transition">
        <span class="inline-flex h-2 w-2 rounded-full" :class="on ? 'bg-emerald-500 animate-pulse' : 'bg-slate-300 dark:bg-slate-600'"></span>
        Live
        <span x-show="on" x-cloak class="text-[11px] opacity-70">10s</span>
    </button>
</div>

@if($error)
    <div class="mb-4 rounded bg-red-50 p-4 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $error }}</div>
@endif

<div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700 text-sm">
        <thead class="bg-slate-50 dark:bg-slate-900/50">
            <tr>
                <th class="px-4 py-2.5 text-left font-medium text-slate-500 dark:text-slate-400">Time</th>
                <th class="px-4 py-2.5 text-left font-medium text-slate-500 dark:text-slate-400">Actor</th>
                <th class="px-4 py-2.5 text-left font-medium text-slate-500 dark:text-slate-400">Action</th>
                <th class="px-4 py-2.5 text-left font-medium text-slate-500 dark:text-slate-400">Entity</th>
                <th class="px-4 py-2.5 text-left font-medium text-slate-500 dark:text-slate-400">Result</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-700/50">
            @forelse($logs as $log)
            @php $ts = $log['@timestamp'] ?? null; @endphp
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/30 cursor-pointer"
                onclick="window.location='{{ route('activity-logs.logs.show', $log['event_id'] ?? $log['_id']) }}'">
                <td class="whitespace-nowrap px-4 py-2.5 font-mono text-xs text-slate-500" title="{{ $ts }}">
                    {{ $fmtTs($ts) }}
                </td>
                <td class="px-4 py-2.5">
                    <span class="text-xs font-medium">{{ $log['actor']['type'] ?? '—' }}</span>
                    @if(!empty($log['actor']['id']))
                        <span class="ml-1 text-xs text-slate-400">#{{ $log['actor']['id'] }}</span>
                    @endif
                </td>
                <td class="px-4 py-2.5 font-mono text-xs">{{ $log['action'] ?? '—' }}</td>
                <td class="px-4 py-2.5 text-xs">
                    {{ $log['entity']['type'] ?? '—' }}
                    <span class="text-slate-400">#{{ $log['entity']['id'] ?? '—' }}</span>
                </td>
                <td class="px-4 py-2.5">
                    @if($log['success'] ?? true)
                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400">ok</span>
                    @else
                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-900/40 dark:text-red-400">fail</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="5" class="px-4 py-8 text-center text-sm text-slate-400">No activity logs found.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- Pagination --}}
@if($totalPages > 1)
<div class="mt-4 flex items-center justify-between text-sm text-slate-500">
    <span>Showing {{ number_format($from) }}–{{ number_format($to) }} of {{ number_format($total) }}</span>
    <div class="flex gap-1">
        @if($page > 1)
            <a href="{{ $pageUrl($page - 1) }}" class="rounded px-2.5 py-1 ring-1 ring-inset ring-slate-200 hover:bg-slate-50 dark:ring-slate-700 dark:hover:bg-slate-800">← Prev</a>
        @endif
        @if($page < $totalPages)
            <a href="{{ $pageUrl($page + 1) }}" class="rounded px-2.5 py-1 ring-1 ring-inset ring-slate-200 hover:bg-slate-50 dark:ring-slate-700 dark:hover:bg-slate-800">Next →</a>
        @endif
    </div>
</div>
@endif
@endsection
