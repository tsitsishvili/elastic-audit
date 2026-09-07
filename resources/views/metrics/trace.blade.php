@extends('elastic-audit::layout')

@section('dashboard', 'metrics')
@section('title', 'Performance Trace')

@section('content')
    @php
        $root = collect($items)->first(fn ($item) => data_get($item, 'kind') === 'transaction');

        $startedAt = function ($item): ?float {
            $ts = data_get($item, '@timestamp');

            if (! is_string($ts) || $ts === '') {
                return null;
            }

            try { return (float) \Illuminate\Support\Carbon::parse($ts)->getPreciseTimestamp(3); }
            catch (\Throwable) { return null; }
        };

        // Lay the spans out on a shared timeline: everything is positioned
        // against the earliest start in the trace, so a span's bar shows both
        // when it ran and how long it took rather than duration alone.
        $timelineItems = collect($items)->reject(fn ($item) => data_get($item, 'type') === 'app.function.profiled');
        $starts   = $timelineItems->map($startedAt)->filter(fn ($v) => $v !== null);
        $traceMin = $starts->min();
        $traceMax = $timelineItems
            ->map(fn ($item) => ($startedAt($item) ?? $traceMin) + (float) data_get($item, 'duration_ms', 0))
            ->max();
        $span = max(0.001, (float) $traceMax - (float) $traceMin);

        // Elasticsearch sorts purely by start time, so a span that begins in the
        // same millisecond as its root can sort ahead of it. Pin the root first
        // and order the remaining spans by where they sit on the timeline.
        // Profiled frames are an attribution breakdown, not timeline events: a
        // sample says how much time a function accounted for, never when it
        // started. Keep them out of the waterfall and rank them separately.
        [$profiled, $timeline] = collect($items)->partition(
            fn ($item) => data_get($item, 'type') === 'app.function.profiled'
        );
        $profiled = $profiled->sortByDesc(fn ($item) => (float) data_get($item, 'duration_ms', 0))->values();

        [$roots, $spans] = $timeline->partition(fn ($item) => data_get($item, 'kind') === 'transaction');
        $ordered = $roots->sortBy(fn ($item) => $startedAt($item) ?? $traceMin)
            ->concat($spans->sortBy(fn ($item) => $startedAt($item) ?? $traceMin))
            ->values();

        $rootDuration = (float) data_get($root, 'duration_ms', 0);
        $fmtTs = function (?string $ts) use ($timezone): string {
            if (! is_string($ts) || $ts === '') { return '—'; }
            try { return \Illuminate\Support\Carbon::parse($ts)->timezone($timezone)->format('M j, H:i:s.v'); }
            catch (\Throwable) { return $ts; }
        };

        /*
         * Each kind of work reads differently, so each gets its own colour and
         * its own row shape. Class strings are written out in full because
         * Tailwind scans this file for literals.
         */
        $styles = [
            'http.server' => ['label' => 'request', 'chip' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300', 'bar' => 'bg-indigo-600'],
            'queue.job' => ['label' => 'job', 'chip' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300', 'bar' => 'bg-indigo-600'],
            'console.command' => ['label' => 'command', 'chip' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300', 'bar' => 'bg-indigo-600'],
            'scheduled.task' => ['label' => 'task', 'chip' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300', 'bar' => 'bg-indigo-600'],
            'app.function' => ['label' => 'function', 'chip' => 'bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300', 'bar' => 'bg-violet-500'],
            'db.query' => ['label' => 'query', 'chip' => 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300', 'bar' => 'bg-sky-500'],
            'http.client' => ['label' => 'outbound', 'chip' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300', 'bar' => 'bg-emerald-500'],
            'cache.operation' => ['label' => 'cache', 'chip' => 'bg-teal-100 text-teal-700 dark:bg-teal-950 dark:text-teal-300', 'bar' => 'bg-teal-500'],
            'redis.command' => ['label' => 'redis', 'chip' => 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300', 'bar' => 'bg-rose-500'],
            'queue.publish' => ['label' => 'dispatch', 'chip' => 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300', 'bar' => 'bg-amber-500'],
            'mail.send' => ['label' => 'mail', 'chip' => 'bg-pink-100 text-pink-700 dark:bg-pink-950 dark:text-pink-300', 'bar' => 'bg-pink-500'],
            'notification.send' => ['label' => 'notify', 'chip' => 'bg-pink-100 text-pink-700 dark:bg-pink-950 dark:text-pink-300', 'bar' => 'bg-pink-500'],
        ];
        $fallback = ['label' => 'span', 'chip' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300', 'bar' => 'bg-slate-500'];
        $styleFor = fn (?string $type): array => $styles[$type] ?? $fallback;

        // Only legend the types this trace actually contains.
        $presentTypes = $ordered->pluck('type')->filter()->unique()->values();
        $profiledMax = (float) ($profiled->map(fn ($i) => (float) data_get($i, 'duration_ms', 0))->max() ?: 1);
    @endphp

    <a href="{{ route('elastic-audit-metrics.transactions', [], false) }}" class="ea-focus text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">← Transactions</a>

    <div class="my-5 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <h1 class="truncate text-2xl font-semibold tracking-normal text-slate-950 dark:text-slate-50">{{ data_get($root, 'name', 'Trace') }}</h1>
            <div class="mt-1 font-mono text-xs text-slate-500">{{ $traceId }}</div>
        </div>
        @if ($root)
            <div class="shrink-0 text-sm text-slate-500 dark:text-slate-400">
                {{ $fmtTs(data_get($root, '@timestamp')) }} ·
                <span class="font-mono">{{ number_format($rootDuration, 2) }} ms</span> ·
                {{ number_format($ordered->count() - 1) }} {{ \Illuminate\Support\Str::plural('span', $ordered->count() - 1) }}
            </div>
        @endif
    </div>

    @if ($presentTypes->isNotEmpty())
        <div class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-slate-400 dark:text-slate-500">
            @foreach ($presentTypes as $type)
                @php $style = $styleFor($type); @endphp
                <span class="inline-flex items-center gap-1.5">
                    <span class="inline-block h-2 w-2 rounded-full {{ $style['bar'] }}"></span>
                    {{ $style['label'] }}
                </span>
            @endforeach
        </div>
    @endif

    <div class="ea-panel overflow-hidden rounded-lg border">
        <div class="divide-y divide-slate-100 dark:divide-slate-800">
            @forelse ($ordered as $item)
                @php
                    $type     = (string) data_get($item, 'type');
                    $style    = $styleFor($type);
                    $duration = (float) data_get($item, 'duration_ms', 0);
                    $start    = $startedAt($item) ?? $traceMin;
                    $offset   = max(0, min(100, (($start - $traceMin) / $span) * 100));
                    $width    = max(0.5, min(100 - $offset, ($duration / $span) * 100));
                    $isRoot   = data_get($item, 'kind') === 'transaction';
                    $failed   = data_get($item, 'outcome') === 'failure';
                    $share    = $rootDuration > 0 ? ($duration / $rootDuration) * 100 : null;

                    // A query's content is its SQL; a function's content is its
                    // name. Everything else gets a compact metadata line.
                    $detail = match ($type) {
                        'http.client' => trim(data_get($item, 'http.method', '').' '.data_get($item, 'http.host', '').data_get($item, 'http.path', '')),
                        'cache.operation' => implode(' · ', array_filter([data_get($item, 'cache.operation'), data_get($item, 'cache.store'), data_get($item, 'cache.result')])),
                        'redis.command' => implode(' · ', array_filter([data_get($item, 'redis.command'), data_get($item, 'redis.connection')])),
                        'queue.publish', 'queue.job' => implode(' · ', array_filter([data_get($item, 'queue.connection'), data_get($item, 'queue.name')])),
                        'mail.send' => data_get($item, 'mail.transport'),
                        'notification.send' => data_get($item, 'notification.channel'),
                        default => null,
                    };
                @endphp
                <div class="px-4 py-3">
                    <div class="mb-2 flex items-baseline justify-between gap-4 text-sm">
                        <div class="flex min-w-0 items-baseline gap-2">
                            <span class="shrink-0 rounded px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $style['chip'] }}">{{ $style['label'] }}</span>

                            @if ($type === 'db.query')
                                {{-- The statement is the content, so it leads. --}}
                                <span class="min-w-0 truncate font-mono text-xs text-slate-700 dark:text-slate-200" title="{{ data_get($item, 'db.statement') ?: data_get($item, 'name') }}">{{ data_get($item, 'db.statement') ?: data_get($item, 'name') }}</span>
                            @elseif ($type === 'app.function')
                                {{-- A measured label, not code: read it as prose. --}}
                                <span class="min-w-0 truncate font-medium text-violet-700 dark:text-violet-300" title="{{ data_get($item, 'name') }}">{{ data_get($item, 'name') }}</span>
                            @else
                                <span class="min-w-0 truncate font-medium text-slate-700 dark:text-slate-200" title="{{ data_get($item, 'name') }}">{{ data_get($item, 'name') }}</span>
                            @endif

                            @if ($failed)
                                <span class="shrink-0 rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-semibold text-red-700 dark:bg-red-950 dark:text-red-300">failed</span>
                            @endif
                        </div>
                        <span class="shrink-0 font-mono text-xs" title="{{ $fmtTs(data_get($item, '@timestamp')) }}">
                            {{ number_format($duration, 2) }} ms
                            @if ($share !== null && ! $isRoot)
                                <span class="ml-1 text-slate-400">{{ number_format($share, 1) }}%</span>
                            @endif
                        </span>
                    </div>

                    <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                        <div class="h-full rounded-full {{ $failed ? 'bg-red-500' : $style['bar'] }}"
                             style="margin-left: {{ round($offset, 3) }}%; width: {{ round($width, 3) }}%"></div>
                    </div>

                    @if ($type === 'db.query')
                        @php
                            // The connection is usually named after its driver;
                            // only say it twice when they actually differ.
                            $dbMeta = array_values(array_unique(array_filter([
                                data_get($item, 'db.operation'),
                                data_get($item, 'db.connection'),
                                data_get($item, 'db.driver'),
                            ])));
                        @endphp
                        <p class="mt-1.5 text-[11px] text-slate-400 dark:text-slate-500">{{ implode(' · ', $dbMeta) }}</p>
                    @elseif ($detail)
                        <p class="mt-1.5 truncate font-mono text-[11px] text-slate-400 dark:text-slate-500" title="{{ $detail }}">{{ $detail }}@if ($type === 'http.client' && data_get($item, 'http.status_code')) <span class="font-semibold">{{ data_get($item, 'http.status_code') }}</span>@endif</p>
                    @endif
                </div>
            @empty
                <p class="px-5 py-12 text-center text-sm text-slate-500 dark:text-slate-400">No spans recorded for this trace.</p>
            @endforelse
        </div>
    </div>

    @if ($profiled->isNotEmpty())
        <div class="ea-panel mt-4 rounded-lg border p-4">
            <div class="flex items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Application functions <span class="text-xs font-normal text-slate-400">by time accounted for</span></h2>
                <span class="text-xs text-slate-400 dark:text-slate-500">from the profiler</span>
            </div>
            <div class="mt-4 space-y-3">
                @foreach ($profiled as $item)
                    @php $ms = (float) data_get($item, 'duration_ms', 0); @endphp
                    <div>
                        <div class="mb-1 flex items-baseline justify-between gap-4 text-xs">
                            <span class="min-w-0 truncate font-mono text-slate-600 dark:text-slate-300" title="{{ data_get($item, 'name') }}">{{ data_get($item, 'name') }}</span>
                            <span class="shrink-0 text-slate-400 dark:text-slate-500">
                                <span class="font-semibold text-slate-600 dark:text-slate-300">{{ number_format($ms, 1) }} ms</span>
                                @if ($rootDuration > 0) · {{ number_format(($ms / $rootDuration) * 100, 1) }}% @endif
                            </span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                            <div class="h-full bg-fuchsia-500" style="width: {{ round($ms / $profiledMax * 100, 1) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="mt-3 text-[11px] text-slate-400 dark:text-slate-500">Inclusive time, so a caller's total contains its callees. Sampled durations are estimates, which is why these are ranked rather than placed on the timeline above.</p>
        </div>
    @endif

    @if (data_get($root, 'transaction.profile_id'))
        <a href="{{ route('elastic-audit-metrics.profiles.show', data_get($root, 'transaction.profile_id'), false) }}" class="ea-focus mt-5 inline-flex rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700">Open sampled profile</a>
    @endif
@endsection
