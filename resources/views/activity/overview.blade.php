@extends('elastic-audit::activity.layout')

@section('title', 'Overview · Activity Logs')

@section('content')
@php
    $aggs    = $metrics['aggs'] ?? [];
    $total   = $metrics['total'] ?? 0;

    $successBuckets = collect($aggs['success']['buckets'] ?? []);
    $successCount   = $successBuckets->firstWhere('key_as_string', 'true')['doc_count'] ?? 0;
    $failureCount   = $successBuckets->firstWhere('key_as_string', 'false')['doc_count'] ?? 0;

    $topActions = collect($aggs['by_action']['buckets'] ?? [])->take(10);
    $topActors  = collect($aggs['by_actor']['buckets'] ?? []);

    $withParams = fn (array $overrides) => request()->fullUrlWithQuery($overrides);
@endphp

<div class="mb-6 flex flex-wrap items-center gap-3">
    <span class="text-sm text-slate-500 dark:text-slate-400">Range:</span>
    @foreach($ranges as $key => $r)
        <a href="{{ $withParams(['range' => $key]) }}"
           class="rounded px-2.5 py-1 text-sm {{ $range === $key ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700' }} ring-1 ring-inset ring-slate-200 dark:ring-slate-700">
            {{ $r['label'] }}
        </a>
    @endforeach
</div>

@if($error)
    <div class="mb-4 rounded bg-red-50 p-4 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $error }}</div>
@endif

<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
    <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
        <div class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Total Events</div>
        <div class="mt-1 text-3xl font-bold">{{ number_format($total) }}</div>
    </div>
    <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
        <div class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Successful</div>
        <div class="mt-1 text-3xl font-bold text-emerald-600 dark:text-emerald-400">{{ number_format($successCount) }}</div>
    </div>
    <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
        <div class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Failed</div>
        <div class="mt-1 text-3xl font-bold text-red-600 dark:text-red-400">{{ number_format($failureCount) }}</div>
    </div>
</div>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
    {{-- Top Actions --}}
    <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
        <h3 class="mb-4 text-sm font-semibold text-slate-700 dark:text-slate-300">Top Actions</h3>
        @forelse($topActions as $bucket)
            <div class="flex items-center justify-between py-1.5 border-b border-slate-100 dark:border-slate-700 last:border-0">
                <a href="{{ route('activity-logs.logs.index', ['action' => $bucket['key']]) }}"
                   class="font-mono text-xs text-indigo-600 hover:underline dark:text-indigo-400">
                    {{ $bucket['key'] }}
                </a>
                <span class="text-sm font-medium">{{ number_format($bucket['doc_count']) }}</span>
            </div>
        @empty
            <p class="text-sm text-slate-400">No data yet.</p>
        @endforelse
    </div>

    {{-- Actor Types --}}
    <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
        <h3 class="mb-4 text-sm font-semibold text-slate-700 dark:text-slate-300">By Actor Type</h3>
        @forelse($topActors as $bucket)
            <div class="flex items-center justify-between py-1.5 border-b border-slate-100 dark:border-slate-700 last:border-0">
                <a href="{{ route('activity-logs.logs.index', ['actor_type' => $bucket['key']]) }}"
                   class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">
                    {{ $bucket['key'] }}
                </a>
                <span class="text-sm font-medium">{{ number_format($bucket['doc_count']) }}</span>
            </div>
        @empty
            <p class="text-sm text-slate-400">No data yet.</p>
        @endforelse
    </div>
</div>
@endsection
