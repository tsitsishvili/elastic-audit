@extends('elastic-audit::layout')

@section('dashboard', 'metrics')
@section('title', 'PHP Profile')

@section('content')
    <a href="{{ $profile ? route('elastic-audit-metrics.traces.show', data_get($profile, 'trace.id'), false) : route('elastic-audit-metrics.transactions', [], false) }}" class="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">← Trace</a>

    @if ($profile)
        @php
            $frames = data_get($profile, 'hot_frames', []);
            $maximum = max(1, (int) collect($frames)->max('self_samples'));
        @endphp
        <div class="my-5 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div><h1 class="text-2xl font-semibold text-slate-950 dark:text-white">{{ data_get($profile, 'transaction.name') }}</h1><p class="mt-1 text-sm text-slate-500">{{ data_get($profile, 'driver') }} · {{ data_get($profile, 'mode') }} · {{ number_format((int) data_get($profile, 'sample_count')) }} samples</p></div>
            @if (data_get($profile, 'truncated'))<span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-700">Payload truncated</span>@endif
        </div>

        <section class="ea-panel overflow-hidden rounded-lg border">
            <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-700"><h2 class="font-semibold">Hottest frames</h2></div>
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($frames as $frame)
                    @php $width = max(1, ((int) data_get($frame, 'self_samples') / $maximum) * 100); @endphp
                    <div class="p-4">
                        <div class="mb-2 flex justify-between gap-4 text-sm"><span class="min-w-0 truncate font-mono" title="{{ data_get($frame, 'function') }}">{{ data_get($frame, 'function') }}</span><span class="shrink-0">{{ data_get($frame, 'self_samples') }} self / {{ data_get($frame, 'total_samples') }} total</span></div>
                        <div class="h-3 rounded-sm bg-slate-100 dark:bg-slate-800"><div class="h-full rounded-sm bg-gradient-to-r from-amber-400 to-red-500" style="width: {{ $width }}%"></div></div>
                    </div>
                @empty
                    <p class="px-5 py-10 text-center text-sm text-slate-500">The profiler produced no stack samples.</p>
                @endforelse
            </div>
        </section>

        <details class="ea-panel mt-6 rounded-lg border p-5">
            <summary class="cursor-pointer font-semibold">Raw {{ data_get($profile, 'format') }} payload</summary>
            <pre class="mt-4 max-h-[32rem] overflow-auto rounded bg-slate-950 p-4 text-xs text-slate-100">{{ json_encode(data_get($profile, 'payload'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </details>
    @endif
@endsection
