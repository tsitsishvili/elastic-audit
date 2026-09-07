@extends('elastic-audit::layout')

@section('dashboard', 'metrics')
@section('title', 'Performance Functions')

@section('content')
    @php
        $all = collect($rows);
        $ms = fn (?float $value): string => $value === null ? '—' : number_format($value, $value >= 100 ? 0 : 1).' ms';

        $active = $all->where('count', '>', 0);

        // Worst first for regressions, best first for improvements.
        $regressed = $all->where('status', 'regressed')->sortByDesc('delta_pct')->values();
        $improved = $all->where('status', 'improved')->sortBy('delta_pct')->values();
        $appeared = $all->where('status', 'new')->sortByDesc('total_ms')->values();
        $vanished = $all->where('status', 'gone')->sortByDesc('baseline_avg_ms')->values();

        // Already ordered by the chosen sort; just cap what gets a chart.
        $ranked = $active->take(12)->values();

        $rangeLabel = $range === 'custom' ? 'Custom range' : ($ranges[$range]['label'] ?? 'Last 24 hours');
        $worst = $regressed->first();
        $best = $improved->first();

        $pct = fn (?float $value): string => $value === null
            ? '—'
            : ($value > 0 ? '+' : '').number_format($value, 1).'%';

        $cards = [
            ['label' => 'Functions', 'value' => number_format($active->count()), 'sub' => 'seen in window', 'accent' => 'text-slate-900 dark:text-slate-100', 'link' => '#table'],
            ['label' => 'Self time', 'value' => $ms((float) $active->sum('self_ms')), 'sub' => 'excluding callees', 'accent' => 'text-slate-900 dark:text-slate-100', 'link' => '#heaviest'],
            ['label' => 'Regressed', 'value' => number_format($regressed->count()), 'sub' => $worst ? $pct($worst['delta_pct']).' worst' : 'none', 'accent' => $regressed->isNotEmpty() ? 'text-red-600' : 'text-emerald-600', 'link' => '#regressions'],
            ['label' => 'Improved', 'value' => number_format($improved->count()), 'sub' => $best ? $pct($best['delta_pct']).' best' : 'none', 'accent' => 'text-emerald-600', 'link' => '#improvements'],
        ];
    @endphp

    <div class="mb-5 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <div class="mb-2 inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 dark:border-indigo-900/70 dark:bg-indigo-950/40 dark:text-indigo-300">
                {{ $rangeLabel }} · vs preceding window
            </div>
            <h1 class="text-2xl font-semibold tracking-normal text-slate-950 dark:text-slate-50">Application functions</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
                Where time goes inside your own code, and how it moved against the window of the same length immediately before.
            </p>
        </div>
    </div>

    @include('elastic-audit::partials.stat-cards', [
        'cards' => $cards,
        'gridClass' => 'grid grid-cols-2 gap-3 lg:grid-cols-4',
        'cardClass' => 'min-h-[92px] p-3.5',
    ])

    @include('elastic-audit::partials.overview-controls', [
        'action' => route('elastic-audit-metrics.functions', [], false),
        'idPrefix' => 'metrics-functions',
        'range' => $range,
        'ranges' => $ranges,
        'interval' => $interval,
        'intervals' => $intervals,
        'timezone' => $timezone,
        'liveKey' => 'tphl_live_metrics_functions',
    ])

    @if ($all->isEmpty())
        <div class="ea-panel mt-4 rounded-lg border p-10 text-center">
            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">No function timings in this window.</p>
            <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">
                Turn on <code class="font-mono">capture.functions.automatic</code> to read them from captured profiles,
                or wrap a block with <code class="font-mono">Performance::measure()</code>.
            </p>
        </div>
    @else
        {{-- Movement first: a list of slow functions is only actionable once you know which ones changed. --}}
        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div id="regressions" class="ea-panel rounded-lg border p-4">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                    Regressions <span class="text-xs font-normal text-slate-400">slower than before</span>
                </h2>
                <div class="mt-4 space-y-3">
                    @forelse ($regressed as $row)
                        <div>
                            <div class="mb-1 flex items-baseline justify-between gap-3 text-xs">
                                <span class="min-w-0 truncate font-mono text-slate-600 dark:text-slate-300" title="{{ $row['key'] }}">{{ $row['key'] }}</span>
                                <span class="shrink-0 font-semibold text-red-600 dark:text-red-400">{{ $pct($row['delta_pct']) }}</span>
                            </div>
                            <div class="flex items-center gap-2 text-[11px] text-slate-400 dark:text-slate-500">
                                <span class="font-mono">{{ $ms($row['baseline_avg_ms']) }}</span>
                                <span aria-hidden="true">→</span>
                                <span class="font-mono font-semibold text-slate-600 dark:text-slate-300">{{ $ms($row['avg_ms']) }}</span>
                                <span>avg · {{ number_format($row['count']) }} {{ \Illuminate\Support\Str::plural('call', $row['count']) }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-slate-400 dark:text-slate-500">Nothing got measurably slower.</p>
                    @endforelse
                </div>
            </div>

            <div id="improvements" class="ea-panel rounded-lg border p-4">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                    Improvements <span class="text-xs font-normal text-slate-400">faster than before</span>
                </h2>
                <div class="mt-4 space-y-3">
                    @forelse ($improved as $row)
                        <div>
                            <div class="mb-1 flex items-baseline justify-between gap-3 text-xs">
                                <span class="min-w-0 truncate font-mono text-slate-600 dark:text-slate-300" title="{{ $row['key'] }}">{{ $row['key'] }}</span>
                                <span class="shrink-0 font-semibold text-emerald-600 dark:text-emerald-400">{{ $pct($row['delta_pct']) }}</span>
                            </div>
                            <div class="flex items-center gap-2 text-[11px] text-slate-400 dark:text-slate-500">
                                <span class="font-mono">{{ $ms($row['baseline_avg_ms']) }}</span>
                                <span aria-hidden="true">→</span>
                                <span class="font-mono font-semibold text-slate-600 dark:text-slate-300">{{ $ms($row['avg_ms']) }}</span>
                                <span>avg · {{ number_format($row['count']) }} {{ \Illuminate\Support\Str::plural('call', $row['count']) }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-slate-400 dark:text-slate-500">Nothing got measurably faster.</p>
                    @endforelse
                </div>
            </div>
        </div>

        @if ($appeared->isNotEmpty() || $vanished->isNotEmpty())
            <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div class="ea-panel rounded-lg border p-4">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">New <span class="text-xs font-normal text-slate-400">not seen before</span></h2>
                    <div class="mt-3 space-y-2">
                        @forelse ($appeared->take(8) as $row)
                            <div class="flex items-baseline justify-between gap-3 text-xs">
                                <span class="min-w-0 truncate font-mono text-slate-600 dark:text-slate-300" title="{{ $row['key'] }}">{{ $row['key'] }}</span>
                                <span class="shrink-0 text-slate-400 dark:text-slate-500">{{ $ms($row['avg_ms']) }} avg</span>
                            </div>
                        @empty
                            <p class="text-xs text-slate-400 dark:text-slate-500">Nothing new.</p>
                        @endforelse
                    </div>
                </div>
                <div class="ea-panel rounded-lg border p-4">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Gone <span class="text-xs font-normal text-slate-400">ran before, not now</span></h2>
                    <div class="mt-3 space-y-2">
                        @forelse ($vanished->take(8) as $row)
                            <div class="flex items-baseline justify-between gap-3 text-xs">
                                <span class="min-w-0 truncate font-mono text-slate-600 dark:text-slate-300" title="{{ $row['key'] }}">{{ $row['key'] }}</span>
                                <span class="shrink-0 text-slate-400 dark:text-slate-500">was {{ $ms($row['baseline_avg_ms']) }}</span>
                            </div>
                        @empty
                            <p class="text-xs text-slate-400 dark:text-slate-500">Nothing disappeared.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        <div id="heaviest" class="ea-panel mt-4 overflow-hidden rounded-lg border">
            <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-slate-200 px-4 py-3 dark:border-slate-700">
                <div>
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Slowest functions <span class="text-xs font-normal text-slate-400">breakdown by p75</span></h2>
                    <p class="mt-0.5 text-xs text-slate-400 dark:text-slate-500">
                        Sorted by <span class="font-medium text-slate-500 dark:text-slate-400">{{ $sorts[$sort] ?? 'total time' }}</span>. Expand a row for its trend across the window.
                    </p>
                </div>
                <form method="GET" class="flex items-center gap-2">
                    @foreach (['range' => $range, 'interval' => $interval, 'from' => request('from'), 'to' => request('to')] as $name => $value)
                        @if ($value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
                    @endforeach
                    <label class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400" for="fn-sort">Sort</label>
                    <select id="fn-sort" name="sort" onchange="this.form.submit()"
                            class="ea-focus h-9 rounded-md border-slate-300 bg-white text-sm shadow-sm dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                        @foreach ($sorts as $key => $label)
                            <option value="{{ $key }}" @selected($sort === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </form>
            </div>

            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($ranked as $index => $row)
                    @php
                        $trend = collect($row['trend'] ?? []);
                        $peak = (float) ($trend->max('p75_ms') ?: 1);
                        $chartId = 'fn-chart-'.$index;
                    @endphp
                    <details class="group" @if ($index === 0) open @endif>
                        <summary class="ea-focus flex cursor-pointer list-none items-center gap-3 px-4 py-3 transition hover:bg-slate-50 dark:hover:bg-slate-900/60">
                            <span class="shrink-0 text-slate-400 transition group-open:rotate-90" aria-hidden="true">&rsaquo;</span>
                            <span class="min-w-0 flex-1 truncate font-mono text-xs text-slate-700 dark:text-slate-200" title="{{ $row['key'] }}">{{ $row['key'] }}</span>

                            {{-- Sparkline: the shape of the window at a glance, without expanding. --}}
                            <span class="hidden shrink-0 items-end gap-px sm:flex" aria-hidden="true">
                                @foreach ($trend->take(-14) as $point)
                                    <span class="w-1 rounded-sm bg-indigo-500/70" style="height: {{ max(2, round(($point['p75_ms'] / $peak) * 22)) }}px"></span>
                                @endforeach
                            </span>

                            <span class="shrink-0 text-right font-mono text-xs">
                                <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $ms($row['self_ms']) }}</span>
                                <span class="text-slate-400 dark:text-slate-500"> self</span>
                            </span>
                            @if ($row['delta_pct'] !== null)
                                <span class="w-16 shrink-0 text-right font-mono text-xs {{ $row['delta_pct'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">{{ $pct($row['delta_pct']) }}</span>
                            @else
                                <span class="w-16 shrink-0 text-right font-mono text-xs text-slate-300 dark:text-slate-600">—</span>
                            @endif
                        </summary>

                        <div class="px-4 pb-4">
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                                @foreach ([
                                    ['Calls', number_format($row['count'])],
                                    ['Avg', $ms($row['avg_ms'])],
                                    ['p75', $ms($row['p75_ms'])],
                                    ['p95', $ms($row['p95_ms'])],
                                    ['Total', $ms($row['total_ms'])],
                                ] as [$label, $value])
                                    <div class="rounded-md bg-slate-50 px-3 py-2 dark:bg-slate-900/60">
                                        <div class="text-[10px] font-medium uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ $label }}</div>
                                        <div class="mt-0.5 font-mono text-xs text-slate-700 dark:text-slate-200">{{ $value }}</div>
                                    </div>
                                @endforeach
                            </div>
                            @if ($trend->isNotEmpty())
                                <div class="mt-3 h-48 rounded-md bg-white p-2 ring-1 ring-slate-200 dark:ring-slate-700">
                                    <canvas id="{{ $chartId }}" data-trend="{{ json_encode($trend->values()) }}"></canvas>
                                </div>
                            @endif
                        </div>
                    </details>
                @endforeach
            </div>
        </div>

        <div id="table" class="ea-panel mt-4 overflow-hidden rounded-lg border">
            <div class="border-b border-slate-200 px-4 py-3 dark:border-slate-700">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">All functions</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-900 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-3">Function</th>
                            <th class="px-4 py-3 text-right">Calls</th>
                            <th class="px-4 py-3 text-right">Avg</th>
                            <th class="px-4 py-3 text-right">p75</th>
                            <th class="px-4 py-3 text-right">p95</th>
                            <th class="px-4 py-3 text-right">Self</th>
                            <th class="px-4 py-3 text-right">Max</th>
                            <th class="px-4 py-3 text-right">Total</th>
                            <th class="px-4 py-3 text-right">Δ avg</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($all as $row)
                            @php
                                $tone = match ($row['status']) {
                                    'regressed' => 'text-red-600 dark:text-red-400',
                                    'improved' => 'text-emerald-600 dark:text-emerald-400',
                                    default => 'text-slate-400 dark:text-slate-500',
                                };
                            @endphp
                            <tr class="hover:bg-indigo-50/40 dark:hover:bg-slate-900/60">
                                <td class="max-w-md px-4 py-2.5">
                                    <div class="flex items-baseline gap-1.5">
                                        <span class="min-w-0 truncate font-mono text-xs text-slate-700 dark:text-slate-200" title="{{ $row['key'] }}">{{ $row['key'] }}</span>
                                        @unless ($row['exact'])
                                            <span class="shrink-0 rounded bg-fuchsia-100 px-1 py-0.5 text-[10px] font-semibold uppercase text-fuchsia-700 dark:bg-fuchsia-950 dark:text-fuchsia-300" title="Sampled from the profiler; durations are estimates">est</span>
                                        @endunless
                                        @if ($row['status'] === 'new')
                                            <span class="shrink-0 rounded bg-sky-100 px-1 py-0.5 text-[10px] font-semibold uppercase text-sky-700 dark:bg-sky-950 dark:text-sky-300">new</span>
                                        @elseif ($row['status'] === 'gone')
                                            <span class="shrink-0 rounded bg-slate-200 px-1 py-0.5 text-[10px] font-semibold uppercase text-slate-600 dark:bg-slate-700 dark:text-slate-300">gone</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-xs">{{ number_format($row['count']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-xs">{{ $ms($row['avg_ms']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-xs">{{ $ms($row['p75_ms']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-xs">{{ $ms($row['p95_ms']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-xs">{{ $ms($row['self_ms']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-xs">{{ $ms($row['max_ms']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-xs">{{ $ms($row['total_ms']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-xs {{ $tone }}" title="{{ $row['baseline_avg_ms'] !== null ? 'was '.$ms($row['baseline_avg_ms']).' over '.number_format($row['baseline_count']).' calls' : 'no comparable baseline' }}">
                                    {{ $pct($row['delta_pct']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <p class="mt-3 text-[11px] text-slate-400 dark:text-slate-500">
            A function is called regressed or improved only when its average moved more than 10% and it ran at least
            three times in both windows, so a couple of unlucky calls do not read as a trend. Rows marked
            <span class="font-semibold">est</span> come from the profiler and are sampled estimates.
        </p>
    @endif
@endsection

@push('scripts')
    @if (! $all->isEmpty() && $ranked->isNotEmpty())
        <script type="module" src="{{ route('elastic-audit.assets', ['asset' => $elasticAuditAssets['resources/js/chart.js']['file']], false) }}"></script>
        <script type="module">
            (function () {
                const tz     = @json($filters['timezone'] ?? 'UTC');
                const perDay = @json(($filters['interval'] ?? '1h') === '1d');
                const opts   = perDay
                    ? { timeZone: tz, month: 'short', day: 'numeric' }
                    : { timeZone: tz, month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false };
                const fmt = (iso) => {
                    const date = new Date(iso);
                    return Number.isNaN(date.getTime()) ? String(iso) : date.toLocaleString([], opts);
                };

                // Charts are built the first time a row opens, so a page of
                // functions does not pay for canvases nobody looked at.
                const build = (canvas) => {
                    if (canvas.dataset.rendered) return;
                    canvas.dataset.rendered = '1';

                    const points = JSON.parse(canvas.dataset.trend || '[]');

                    new Chart(canvas, {
                        type: 'line',
                        data: {
                            labels: points.map((p) => fmt(p.at)),
                            datasets: [{
                                label: 'p75 ms',
                                data: points.map((p) => p.p75_ms),
                                borderColor: '#6366f1',
                                backgroundColor: 'rgba(99, 102, 241, 0.12)',
                                fill: true,
                                tension: 0.25,
                                pointRadius: 0,
                                spanGaps: true,
                            }],
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: { mode: 'index', intersect: false },
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    callbacks: {
                                        label: (item) => {
                                            const point = points[item.dataIndex] || {};
                                            return `p75 ${Number(item.parsed.y).toFixed(1)} ms · ${point.count ?? 0} calls`;
                                        },
                                    },
                                },
                            },
                            scales: {
                                y: { beginAtZero: true, ticks: { callback: (value) => value + ' ms' } },
                                x: { grid: { display: false }, ticks: { maxTicksLimit: 6, maxRotation: 0 } },
                            },
                        },
                    });
                };

                document.querySelectorAll('details').forEach((row) => {
                    const canvas = row.querySelector('canvas[data-trend]');
                    if (!canvas) return;
                    if (row.open) build(canvas);
                    row.addEventListener('toggle', () => row.open && build(canvas));
                });
            })();
        </script>
    @endif
@endpush
