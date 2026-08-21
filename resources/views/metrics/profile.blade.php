@extends('elastic-audit::layout')

@section('dashboard', 'metrics')
@section('title', 'PHP Profile')

@section('content')
    <a href="{{ $profile ? route('elastic-audit-metrics.traces.show', data_get($profile, 'trace.id'), false) : route('elastic-audit-metrics.transactions', [], false) }}"
       class="ea-focus text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">← Trace</a>

    @if ($profile)
        @php
            $frames = collect(data_get($profile, 'hot_frames', []));
            $maximum = max(1, (int) $frames->max('self_samples'));
            $sampleCount = (int) data_get($profile, 'sample_count');
            // Excimer samples on a wall-clock period, so a frame's share of the
            // samples approximates its share of wall time.
            $totalSelf = max(1, (int) $frames->sum('self_samples'));
            $sampleRate = data_get($profile, 'sample_rate_hz');

            $meta = array_filter([
                'Driver' => data_get($profile, 'driver'),
                'Mode' => data_get($profile, 'mode'),
                'Format' => data_get($profile, 'format'),
                'Samples' => number_format($sampleCount),
                'Sample rate' => $sampleRate ? number_format((float) $sampleRate, 1).' Hz' : null,
            ], fn ($value) => $value !== null && $value !== '');
        @endphp

        <div class="my-5 flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <h1 class="truncate text-2xl font-semibold tracking-normal text-slate-950 dark:text-slate-50">{{ data_get($profile, 'transaction.name') }}</h1>
                <p class="mt-1 font-mono text-xs text-slate-500 dark:text-slate-400">{{ data_get($profile, 'profile_id') }}</p>
            </div>
            @if (data_get($profile, 'truncated'))
                <span class="shrink-0 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-950 dark:text-amber-300">Payload truncated</span>
            @endif
        </div>

        <div class="ea-panel mb-4 flex flex-wrap gap-x-8 gap-y-3 rounded-lg border p-4">
            @foreach ($meta as $label => $value)
                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ $label }}</div>
                    <div class="mt-1 font-mono text-sm text-slate-900 dark:text-slate-100">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        <section class="ea-panel overflow-hidden rounded-lg border">
            <div class="flex items-baseline justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Hottest frames <span class="text-xs font-normal text-slate-400">by self samples</span></h2>
                <span class="text-xs text-slate-400 dark:text-slate-500">{{ number_format($frames->count()) }} shown</span>
            </div>
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($frames as $frame)
                    @php
                        $self = (int) data_get($frame, 'self_samples');
                        $width = max(1, ($self / $maximum) * 100);
                        $share = ($self / $totalSelf) * 100;
                    @endphp
                    <div class="px-5 py-3">
                        <div class="mb-1.5 flex items-baseline justify-between gap-4 text-xs">
                            <span class="min-w-0 truncate font-mono text-slate-700 dark:text-slate-200" title="{{ data_get($frame, 'function') }}">{{ data_get($frame, 'function') }}</span>
                            <span class="shrink-0 text-slate-400 dark:text-slate-500">
                                <span class="font-semibold text-slate-600 dark:text-slate-300">{{ number_format($share, 1) }}%</span>
                                · {{ number_format($self) }} self / {{ number_format((int) data_get($frame, 'total_samples')) }} total
                            </span>
                        </div>
                        <div class="h-2.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <div class="h-full rounded-full bg-gradient-to-r from-amber-400 to-red-500" style="width: {{ round($width, 2) }}%"></div>
                        </div>
                        @if (data_get($frame, 'file'))
                            <p class="mt-1.5 truncate font-mono text-[11px] text-slate-400 dark:text-slate-500">{{ data_get($frame, 'file') }}@if (data_get($frame, 'line')):{{ data_get($frame, 'line') }}@endif</p>
                        @endif
                    </div>
                @empty
                    <p class="px-5 py-12 text-center text-sm text-slate-500 dark:text-slate-400">The profiler produced no stack samples.</p>
                @endforelse
            </div>
        </section>

        <details class="ea-panel mt-4 rounded-lg border p-4">
            <summary class="ea-focus cursor-pointer text-sm font-semibold text-slate-900 dark:text-slate-100">Raw {{ data_get($profile, 'format') }} payload</summary>
            <p class="mt-2 text-xs text-slate-400 dark:text-slate-500">Not indexed by Elasticsearch. Source paths follow the <code class="font-mono">profiles.include_paths</code> setting.</p>
            <pre class="mt-3 max-h-[32rem] overflow-auto rounded bg-slate-950 p-4 text-xs text-slate-100">{{ json_encode(data_get($profile, 'payload'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </details>
    @else
        <p class="ea-panel mt-6 rounded-lg border p-8 text-center text-sm text-slate-500 dark:text-slate-400">
            This profile could not be loaded. It may have been pruned, or the query above failed.
        </p>
    @endif
@endsection
