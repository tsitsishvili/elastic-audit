@extends('elastic-audit::layout')

@section('dashboard', 'metrics')
@section('title', 'Performance Overview')

@section('content')
    @php
        $aggs = $data['aggs'] ?? [];
        $total = (int) ($data['total'] ?? 0);
        $failureCount = (int) data_get($aggs, 'failures.doc_count', 0);
        $profileCount = (int) data_get($aggs, 'profiled.doc_count', 0);

        // Elasticsearch keys percentiles as '95.0' or '95' depending on version.
        $percentile = fn (string $p): float => (float) (
            $aggs['latency']['values'][$p.'.0'] ?? $aggs['latency']['values'][$p] ?? 0
        );
        $p50 = $percentile('50');
        $p95 = $percentile('95');
        $p99 = $percentile('99');
        $average = (float) data_get($aggs, 'duration.value', 0);
        $failureRate = $total > 0 ? ($failureCount / $total) * 100 : 0;

        $timeBuckets = data_get($aggs, 'throughput.buckets', []);
        $chartLabels = array_map(fn ($bucket) => $bucket['key_as_string'] ?? null, $timeBuckets);
        $chartVolume = array_map(fn ($bucket) => (int) ($bucket['doc_count'] ?? 0), $timeBuckets);
        $chartFailed = array_map(fn ($bucket) => (int) data_get($bucket, 'failures.doc_count', 0), $timeBuckets);
        $chartP95 = array_map(fn ($bucket) => round((float) ($bucket['p95']['values']['95.0'] ?? 0), 2), $timeBuckets);

        $hasChart = $total > 0 && $timeBuckets !== [];
        $perLabel = ($interval ?? '1h') === '1d' ? 'day' : 'hour';
        $rangeLabel = $range === 'custom' ? 'Custom range' : ($ranges[$range]['label'] ?? 'Last 24 hours');
        $rangeSub = $range === 'custom' ? 'custom range' : ($ranges[$range]['sub'] ?? 'last 24h');

        // Carry the selected window into every drill-down so the transaction
        // list opens on the same slice of time the summary describes.
        $window = array_filter([
            'from' => $filters['from'] ?? null,
            'to' => $filters['to'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
        $txLink = fn (array $params = []) => route('elastic-audit-metrics.transactions', $window + $params, false);

        $ms = fn (float $value): string => number_format($value, $value >= 100 ? 0 : 1).' ms';

        $cards = [
            ['label' => 'Transactions', 'value' => number_format($total), 'sub' => $rangeSub, 'accent' => 'text-slate-900 dark:text-slate-100', 'link' => $txLink()],
            ['label' => 'Failures', 'value' => number_format($failureCount), 'sub' => number_format($failureRate, 2).'% of total', 'accent' => $failureCount > 0 ? 'text-red-600' : 'text-emerald-600', 'link' => $txLink(['outcome' => 'failure'])],
            ['label' => 'Average', 'value' => $ms($average), 'sub' => 'mean duration', 'accent' => 'text-slate-900 dark:text-slate-100', 'link' => $txLink()],
            ['label' => 'p50', 'value' => $ms($p50), 'sub' => 'median', 'accent' => 'text-slate-900 dark:text-slate-100', 'link' => $txLink()],
            ['label' => 'p95', 'value' => $ms($p95), 'sub' => 'tail latency', 'accent' => 'text-amber-600', 'link' => $txLink()],
            ['label' => 'p99', 'value' => $ms($p99), 'sub' => 'worst 1%', 'accent' => 'text-amber-600', 'link' => $txLink()],
        ];

        $typeBuckets = collect(data_get($aggs, 'by_type.buckets', []));
        $typeMax = (int) ($typeBuckets->max('doc_count') ?: 1);
        $slowBuckets = collect(data_get($aggs, 'slowest.buckets', []));
        $slowMax = (float) ($slowBuckets->map(fn ($b) => (float) data_get($b, 'avg_duration.value', 0))->max() ?: 1);
    @endphp

    <div class="mb-5 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <div class="mb-2 inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 dark:border-indigo-900/70 dark:bg-indigo-950/40 dark:text-indigo-300">
                {{ $rangeLabel }} · per {{ $perLabel }}
            </div>
            <h1 class="text-2xl font-semibold tracking-normal text-slate-950 dark:text-slate-50">Application performance</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">Transactions, spans, failures, and sampled PHP profiles for the selected window.</p>
        </div>
        <a href="{{ $txLink() }}"
           class="ea-focus inline-flex h-10 shrink-0 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700">
            Browse transactions →
        </a>
    </div>

    @include('elastic-audit::partials.stat-cards', [
        'cards' => $cards,
        'gridClass' => 'grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6',
        'cardClass' => 'min-h-[92px] p-3.5',
    ])

    @include('elastic-audit::partials.overview-controls', [
        'action' => route('elastic-audit-metrics.overview', [], false),
        'idPrefix' => 'metrics-overview',
        'range' => $range,
        'ranges' => $ranges,
        'interval' => $interval,
        'intervals' => $intervals,
        'timezone' => $timezone,
        'liveKey' => 'tphl_live_metrics_overview',
    ])

    <div class="ea-panel mt-4 rounded-lg border p-4">
        <div class="flex items-start justify-between gap-2">
            <div>
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                    Throughput and p95 latency
                    @if ($hasChart)
                        <span class="ml-1 text-xs font-normal {{ $failureCount > 0 ? 'text-red-500' : 'text-emerald-500' }}">{{ number_format($failureCount) }} failed</span>
                    @endif
                </h2>
                <p class="text-xs text-slate-400 dark:text-slate-500">Transactions by outcome · per {{ $perLabel }}</p>
            </div>
            @if ($hasChart)
                <button type="button" data-export="metricsThroughputChart" data-export-name="Throughput and p95 latency"
                        class="ea-focus shrink-0 rounded-md border border-slate-200 px-2 py-1 text-[11px] font-medium text-slate-500 transition hover:bg-slate-50 hover:text-slate-700 dark:border-slate-700 dark:text-slate-400 dark:hover:bg-slate-900 dark:hover:text-slate-200">
                    PNG
                </button>
            @endif
        </div>
        <div class="mt-3 h-72 rounded-md p-2 ring-1 ring-slate-200 sm:h-80 dark:ring-slate-700 {{ $hasChart ? 'bg-white' : '' }}">
            @if ($hasChart)
                <canvas id="metricsThroughputChart"></canvas>
            @else
                <div class="flex h-full items-center justify-center text-center text-sm text-slate-400 dark:text-slate-500">No transactions for this window.</div>
            @endif
        </div>
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="ea-panel rounded-lg border p-4">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Transaction types <span class="text-xs font-normal text-slate-400">by volume</span></h2>
            <div class="mt-4 space-y-3">
                @forelse ($typeBuckets as $bucket)
                    <div>
                        <div class="mb-1 flex items-center justify-between text-xs">
                            <a href="{{ $txLink(['type' => $bucket['key']]) }}"
                               class="font-mono font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ $bucket['key'] }}</a>
                            <span class="text-slate-400 dark:text-slate-500">{{ number_format($bucket['doc_count']) }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                            <div class="h-full bg-indigo-500" style="width: {{ round($bucket['doc_count'] / $typeMax * 100, 1) }}%"></div>
                        </div>
                    </div>
                @empty
                    <p class="text-xs text-slate-400 dark:text-slate-500">No transactions in this window.</p>
                @endforelse
            </div>
        </div>

        <div class="ea-panel rounded-lg border p-4">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Slowest transaction groups <span class="text-xs font-normal text-slate-400">avg duration</span></h2>
            <div class="mt-4 space-y-3">
                @forelse ($slowBuckets as $bucket)
                    @php $avgMs = (float) data_get($bucket, 'avg_duration.value', 0); @endphp
                    <div>
                        <div class="mb-1 flex items-center justify-between gap-4 text-xs">
                            <span class="min-w-0 truncate font-mono font-medium text-slate-600 dark:text-slate-300" title="{{ $bucket['key'] }}">{{ $bucket['key'] }}</span>
                            <span class="shrink-0 text-slate-400 dark:text-slate-500">{{ $ms($avgMs) }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                            <div class="h-full bg-amber-500" style="width: {{ round($avgMs / $slowMax * 100, 1) }}%"></div>
                        </div>
                    </div>
                @empty
                    <p class="text-xs text-slate-400 dark:text-slate-500">No latency data yet.</p>
                @endforelse
            </div>
        </div>
    </div>

    @php
        $functionRows = collect($functions ?? []);
        $functionMax = (float) ($functionRows->max('total_ms') ?: 1);
    @endphp
    @if ($functionRows->isNotEmpty())
        <div class="ea-panel mt-4 rounded-lg border p-4">
            <div class="flex items-baseline justify-between">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Measured functions <span class="text-xs font-normal text-slate-400">by total time</span></h2>
                <span class="text-xs text-slate-400 dark:text-slate-500">Performance::measure()</span>
            </div>
            <div class="mt-4 space-y-3">
                @foreach ($functionRows as $row)
                    <div>
                        <div class="mb-1 flex items-baseline justify-between gap-4 text-xs">
                            <span class="min-w-0 truncate font-mono font-medium text-slate-600 dark:text-slate-300" title="{{ $row['key'] }}">{{ $row['key'] }}</span>
                            <span class="shrink-0 text-slate-400 dark:text-slate-500">
                                <span class="font-semibold text-slate-600 dark:text-slate-300">{{ $ms($row['avg_ms']) }}</span> avg
                                · {{ $ms($row['max_ms']) }} max
                                · {{ number_format($row['count']) }} {{ \Illuminate\Support\Str::plural('call', $row['count']) }}
                            </span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                            <div class="h-full bg-sky-500" style="width: {{ round($row['total_ms'] / $functionMax * 100, 1) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="mt-3 text-[11px] text-slate-400 dark:text-slate-500">Bar length is total time spent, so a fast call made often can outrank a slow one made once.</p>
        </div>
    @endif

    <p class="mt-4 text-xs text-slate-400 dark:text-slate-500">
        {{ number_format($profileCount) }} of {{ number_format($total) }} transactions captured a PHP profile in this window.
    </p>
@endsection

@push('scripts')
    @if ($hasChart)
        <script type="module" src="{{ route('elastic-audit.assets', ['asset' => $elasticAuditAssets['resources/js/chart.js']['file']], false) }}"></script>
        <script type="module">
            (function () {
                const tz     = @json($filters['timezone'] ?? 'UTC');
                const perDay = @json(($filters['interval'] ?? '1h') === '1d');
                const labels = @json($chartLabels);

                // Buckets arrive as ISO-8601 UTC. Render them in the dashboard
                // timezone, short on the axis and fully qualified in the tooltip.
                const axisOpts = perDay
                    ? { timeZone: tz, month: 'short', day: 'numeric' }
                    : { timeZone: tz, month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false };
                const fullOpts = perDay
                    ? { timeZone: tz, weekday: 'short', month: 'short', day: 'numeric' }
                    : { timeZone: tz, month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false };
                const fmt = (iso, opts) => {
                    const date = new Date(iso);
                    return Number.isNaN(date.getTime()) ? String(iso) : date.toLocaleString([], opts);
                };

                // Vertical guide line at the hovered bucket.
                const crosshair = {
                    id: 'crosshair',
                    afterDraw(chart) {
                        const active = chart.getActiveElements();
                        if (!active.length) return;
                        const x = active[0].element.x;
                        const { top, bottom } = chart.chartArea;
                        const ctx = chart.ctx;
                        ctx.save();
                        ctx.beginPath();
                        ctx.moveTo(x, top);
                        ctx.lineTo(x, bottom);
                        ctx.lineWidth = 1;
                        ctx.strokeStyle = 'rgba(100, 116, 139, 0.35)';
                        ctx.stroke();
                        ctx.restore();
                    },
                };

                // Solid white backing so exported PNGs aren't transparent.
                const whiteBg = {
                    id: 'whiteBg',
                    beforeDraw(chart) {
                        const ctx = chart.ctx;
                        ctx.save();
                        ctx.globalCompositeOperation = 'destination-over';
                        ctx.fillStyle = '#ffffff';
                        ctx.fillRect(0, 0, chart.width, chart.height);
                        ctx.restore();
                    },
                };

                const chart = new Chart(document.getElementById('metricsThroughputChart'), {
                    type: 'bar',
                    data: {
                        labels: labels.map((iso) => fmt(iso, axisOpts)),
                        datasets: [
                            { label: 'transactions', data: @json($chartVolume), backgroundColor: '#6366f1', yAxisID: 'count' },
                            { label: 'failures', data: @json($chartFailed), backgroundColor: '#ef4444', yAxisID: 'count' },
                            { label: 'p95 ms', data: @json($chartP95), borderColor: '#f59e0b', type: 'line', tension: 0.25, pointRadius: 0, yAxisID: 'latency' },
                        ],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    title: (items) => items.length ? fmt(labels[items[0].dataIndex], fullOpts) : '',
                                    label: (item) => item.dataset.yAxisID === 'latency'
                                        ? `${item.dataset.label}: ${Number(item.parsed.y).toFixed(1)} ms`
                                        : `${item.dataset.label}: ${item.parsed.y}`,
                                },
                            },
                        },
                        scales: {
                            count: { beginAtZero: true, position: 'left', ticks: { precision: 0 } },
                            latency: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { callback: value => value + ' ms' } },
                            x: { grid: { display: false }, ticks: { maxTicksLimit: 8, maxRotation: 0, autoSkip: true } },
                        },
                    },
                    plugins: [crosshair, whiteBg],
                });

                document.querySelector('[data-export="metricsThroughputChart"]')?.addEventListener('click', (event) => {
                    const link = document.createElement('a');
                    link.href = chart.toBase64Image();
                    link.download = (event.currentTarget.dataset.exportName || 'chart') + '.png';
                    link.click();
                });
            })();
        </script>
    @endif
@endpush
