@extends('elastic-audit::layout')

@section('dashboard', 'metrics')
@section('title', 'Performance Trace')

@section('content')
    @php
        $root = collect($items)->first(fn ($item) => data_get($item, 'kind') === 'transaction');
        $maxDuration = max(0.001, (float) data_get($root, 'duration_ms', collect($items)->max('duration_ms') ?: 1));
    @endphp
    <a href="{{ route('elastic-audit-metrics.transactions', [], false) }}" class="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">← Transactions</a>
    <div class="my-5">
        <h1 class="text-2xl font-semibold text-slate-950 dark:text-white">{{ data_get($root, 'name', 'Trace') }}</h1>
        <div class="mt-1 font-mono text-xs text-slate-500">{{ $traceId }}</div>
    </div>

    <div class="ea-panel overflow-hidden rounded-lg border">
        <div class="divide-y divide-slate-100 dark:divide-slate-800">
            @foreach ($items as $item)
                @php
                    $width = max(1, min(100, ((float) data_get($item, 'duration_ms', 0) / $maxDuration) * 100));
                    $isRoot = data_get($item, 'kind') === 'transaction';
                @endphp
                <div class="p-4">
                    <div class="mb-2 flex items-center justify-between gap-4 text-sm">
                        <div class="min-w-0"><span class="mr-2 rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs dark:bg-slate-800">{{ $isRoot ? 'transaction' : data_get($item, 'type') }}</span><span class="font-medium">{{ data_get($item, 'name') }}</span></div>
                        <span class="shrink-0 font-mono">{{ number_format((float) data_get($item, 'duration_ms'), 2) }} ms</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-full {{ data_get($item, 'outcome') === 'failure' ? 'bg-red-500' : ($isRoot ? 'bg-indigo-600' : 'bg-sky-500') }}" style="width: {{ $width }}%"></div></div>
                </div>
            @endforeach
        </div>
    </div>

    @if (data_get($root, 'transaction.profile_id'))
        <a href="{{ route('elastic-audit-metrics.profiles.show', data_get($root, 'transaction.profile_id'), false) }}" class="mt-5 inline-flex rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white">Open sampled profile</a>
    @endif
@endsection
