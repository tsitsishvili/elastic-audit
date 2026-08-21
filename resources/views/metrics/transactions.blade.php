@extends('elastic-audit::layout')

@section('dashboard', 'metrics')
@section('title', 'Performance Transactions')

@section('content')
    @php
        // Format a stored ISO timestamp in the app timezone; full ISO kept in the title.
        $fmtTs = function (?string $ts) use ($timezone): string {
            if (! is_string($ts) || $ts === '') { return '—'; }
            try { return \Illuminate\Support\Carbon::parse($ts)->timezone($timezone)->format('M j, H:i:s'); }
            catch (\Throwable) { return $ts; }
        };

        // The overview drills down with a from/to window. Render it back into the
        // filter inputs so submitting the form keeps the window instead of
        // silently widening the search to everything.
        $fmtLocal = function (?string $ts) use ($timezone): string {
            if (! is_string($ts) || $ts === '') { return ''; }
            try { return \Illuminate\Support\Carbon::parse($ts)->timezone($timezone)->format('Y-m-d\TH:i'); }
            catch (\Throwable) { return ''; }
        };

        $total = (int) ($data['total'] ?? 0);
        $base = route('elastic-audit-metrics.transactions', [], false);

        // Elasticsearch will not serve a window past index.max_result_window, so
        // do not offer pages the query would have to clamp away.
        $pages = max(1, min(
            (int) ceil($total / $perPage),
            intdiv(10000, $perPage),
        ));
        $pageUrl = fn (int $target): string => $base.'?'.http_build_query(array_merge(request()->query(), ['page' => $target]));

        $activeFilters = array_filter([
            'type' => $filters['type'] ?? null,
            'outcome' => $filters['outcome'] ?? null,
            'service' => $filters['service'] ?? null,
            'trace_id' => $filters['trace_id'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        $withoutFilter = fn (string $key): string => $base.'?'.http_build_query(
            array_diff_key(request()->query(), [$key => '', 'page' => ''])
        );

        $inputClass = 'ea-focus h-10 w-full rounded-md border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100';
        $labelClass = 'text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400';
    @endphp

    <div class="mb-5 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-normal text-slate-950 dark:text-slate-50">Transactions</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">Root application operations with trace and profile links.</p>
        </div>
        <a href="{{ route('elastic-audit-metrics.overview', [], false) }}"
           class="ea-focus inline-flex h-10 shrink-0 items-center justify-center rounded-md border border-slate-300 px-4 text-sm font-medium transition hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-900">
            ← Overview
        </a>
    </div>

    <form method="GET" action="{{ $base }}" class="ea-panel mb-4 rounded-lg border p-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="flex flex-col gap-1">
                <label class="{{ $labelClass }}" for="tx-type">Type</label>
                <input id="tx-type" name="type" value="{{ $filters['type'] ?? '' }}" list="tx-type-options" placeholder="http.server" class="{{ $inputClass }}">
                <datalist id="tx-type-options">
                    @foreach (['http.server', 'queue.job', 'console.command', 'scheduled.task'] as $option)
                        <option value="{{ $option }}"></option>
                    @endforeach
                </datalist>
            </div>
            <div class="flex flex-col gap-1">
                <label class="{{ $labelClass }}" for="tx-outcome">Outcome</label>
                <select id="tx-outcome" name="outcome" class="{{ $inputClass }}">
                    <option value="">Any outcome</option>
                    <option value="success" @selected(($filters['outcome'] ?? '') === 'success')>Success</option>
                    <option value="failure" @selected(($filters['outcome'] ?? '') === 'failure')>Failure</option>
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="{{ $labelClass }}" for="tx-service">Service</label>
                <input id="tx-service" name="service" value="{{ $filters['service'] ?? '' }}" placeholder="Any service" class="{{ $inputClass }}">
            </div>
            <div class="flex flex-col gap-1">
                <label class="{{ $labelClass }}" for="tx-per-page">Per page</label>
                <select id="tx-per-page" name="per_page" class="{{ $inputClass }}">
                    @foreach ($perPageOptions as $option)
                        <option value="{{ $option }}" @selected($perPage === $option)>{{ $option }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex flex-col gap-1">
                <label class="{{ $labelClass }}" for="tx-from">From</label>
                <input id="tx-from" type="datetime-local" name="from" value="{{ $fmtLocal($filters['from'] ?? null) }}" class="{{ $inputClass }}">
            </div>
            <div class="flex flex-col gap-1">
                <label class="{{ $labelClass }}" for="tx-to">To</label>
                <input id="tx-to" type="datetime-local" name="to" value="{{ $fmtLocal($filters['to'] ?? null) }}" class="{{ $inputClass }}">
            </div>
            @if (! empty($filters['trace_id']))
                <input type="hidden" name="trace_id" value="{{ $filters['trace_id'] }}">
            @endif
            <div class="flex items-end gap-2 sm:col-span-2">
                <button class="ea-focus h-10 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white transition hover:bg-indigo-700">Filter</button>
                <a href="{{ $base }}" class="ea-focus inline-flex h-10 items-center rounded-md border border-slate-300 px-4 text-sm transition hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-900">Reset</a>
            </div>
        </div>
    </form>

    @if ($activeFilters !== [])
        <div class="mb-4 flex flex-wrap items-center gap-2 text-xs">
            <span class="text-slate-400 dark:text-slate-500">Filtered by</span>
            @foreach ($activeFilters as $key => $value)
                <a href="{{ $withoutFilter($key) }}"
                   class="ea-focus inline-flex items-center gap-1 rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 font-medium text-indigo-700 transition hover:border-indigo-400 dark:border-indigo-900/70 dark:bg-indigo-950/40 dark:text-indigo-300">
                    {{ $key }}: <span class="font-mono">{{ \Illuminate\Support\Str::limit($value, 40) }}</span>
                    <span aria-hidden="true">×</span>
                    <span class="sr-only">Remove filter</span>
                </a>
            @endforeach
        </div>
    @endif

    <div class="ea-panel overflow-hidden rounded-lg border">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-900 dark:text-slate-400">
                    <tr>
                        <th class="px-4 py-3">Time</th>
                        <th class="px-4 py-3">Transaction</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Outcome</th>
                        <th class="px-4 py-3 text-right">Duration</th>
                        <th class="px-4 py-3 text-right">Spans</th>
                        <th class="px-4 py-3">Profile</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($data['hits'] as $item)
                        @php $type = (string) data_get($item, 'type'); @endphp
                        <tr class="hover:bg-indigo-50/40 dark:hover:bg-slate-900/60">
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-500" title="{{ data_get($item, '@timestamp') }}">{{ $fmtTs(data_get($item, '@timestamp')) }}</td>
                            <td class="max-w-sm px-4 py-3">
                                <a href="{{ route('elastic-audit-metrics.traces.show', data_get($item, 'trace.id'), false) }}" class="block truncate font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ data_get($item, 'name') }}</a>
                                <div class="font-mono text-xs text-slate-400">{{ data_get($item, 'service.name') }}</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <a href="{{ $base }}?{{ http_build_query(['type' => $type]) }}" class="font-mono text-xs text-slate-500 hover:text-indigo-600 hover:underline dark:text-slate-400 dark:hover:text-indigo-400">{{ $type }}</a>
                            </td>
                            <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ data_get($item, 'outcome') === 'failure' ? 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' }}">{{ data_get($item, 'outcome') }}</span></td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-mono">{{ number_format((float) data_get($item, 'duration_ms'), 2) }} ms</td>
                            <td class="px-4 py-3 text-right">{{ number_format((int) data_get($item, 'transaction.span_count', 0)) }}</td>
                            <td class="px-4 py-3">
                                @if (data_get($item, 'transaction.profile_id'))
                                    <a href="{{ route('elastic-audit-metrics.profiles.show', data_get($item, 'transaction.profile_id'), false) }}" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">View</a>
                                @else <span class="text-slate-400">—</span> @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-16 text-center">
                                <p class="text-sm font-medium text-slate-500 dark:text-slate-400">No transactions found.</p>
                                <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">
                                    @if ($activeFilters !== [])
                                        Try widening the filters, or <a href="{{ $base }}" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">reset them</a>.
                                    @else
                                        Metrics are recorded once <code class="font-mono">elastic_audit_metrics.enabled</code> is on and a worker drains the metrics queue.
                                    @endif
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($pages > 1)
        <div class="mt-4 flex items-center justify-between text-sm">
            <span class="text-slate-500">Page {{ number_format($page) }} of {{ number_format($pages) }} · {{ number_format($total) }} transactions</span>
            <div class="flex gap-2">
                @if ($page > 1)<a href="{{ $pageUrl($page - 1) }}" class="ea-focus rounded-md border px-3 py-2 transition hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-900">Previous</a>@endif
                @if ($page < $pages)<a href="{{ $pageUrl($page + 1) }}" class="ea-focus rounded-md border px-3 py-2 transition hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-900">Next</a>@endif
            </div>
        </div>
    @endif
@endsection
