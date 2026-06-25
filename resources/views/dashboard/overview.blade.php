@extends('elastic-audit::dashboard.layout')

@section('title', 'Overview · Third-Party HTTP Logs')

@php
    $aggs  = $metrics['aggs'] ?? [];
    $total = $metrics['total'] ?? 0;

    $statusMap = collect($aggs['by_status_class']['buckets'] ?? [])->pluck('doc_count', 'key');
    $c2xx = (int) ($statusMap['2xx'] ?? 0);
    $c3xx = (int) ($statusMap['3xx'] ?? 0);
    $c4xx = (int) ($statusMap['4xx'] ?? 0);
    $c5xx = (int) ($statusMap['5xx'] ?? 0);

    $successCount = (int) collect($aggs['success']['buckets'] ?? [])
        ->filter(fn ($b) => ($b['key'] ?? null) === 1 || ($b['key_as_string'] ?? '') === 'true')
        ->sum('doc_count');
    $successRate = $total > 0 ? round($successCount / $total * 100, 1) : 0.0;

    $avgLatency = isset($aggs['latency']['avg']) ? (int) round($aggs['latency']['avg']) : null;
    $p95Latency = isset($aggs['latency_pct']['values']['95.0']) && $aggs['latency_pct']['values']['95.0'] !== null
        ? (int) round($aggs['latency_pct']['values']['95.0'])
        : null;

    $providerBuckets = $aggs['by_provider']['buckets'] ?? [];
    $providerMax     = collect($providerBuckets)->max('doc_count') ?: 1;

    $statusColors = ['2xx' => 'bg-emerald-500', '3xx' => 'bg-sky-500', '4xx' => 'bg-amber-500', '5xx' => 'bg-red-500'];
    $statusOrder  = ['2xx' => $c2xx, '3xx' => $c3xx, '4xx' => $c4xx, '5xx' => $c5xx];
    $statusTotal  = max(1, array_sum($statusOrder));

    $timeBuckets = $aggs['over_time']['buckets'] ?? [];
    $chartLabels = array_map(fn ($b) => $b['key_as_string'] ?? (string) ($b['key'] ?? ''), $timeBuckets);

    // Per-bucket series feeding the Volume / Latency / Errors mini-charts.
    $bucketStatus = static fn (array $b, string $class): int
        => (int) (collect($b['by_status_class']['buckets'] ?? [])->firstWhere('key', $class)['doc_count'] ?? 0);

    $series2xx       = array_map(fn ($b) => $bucketStatus($b, '2xx'), $timeBuckets);
    $series3xx       = array_map(fn ($b) => $bucketStatus($b, '3xx'), $timeBuckets);
    $series4xx       = array_map(fn ($b) => $bucketStatus($b, '4xx'), $timeBuckets);
    $series5xx       = array_map(fn ($b) => $bucketStatus($b, '5xx'), $timeBuckets);
    $seriesLatAvg    = array_map(fn ($b) => isset($b['latency_avg']['value']) ? (int) round($b['latency_avg']['value']) : null, $timeBuckets);
    $seriesLatP95    = array_map(fn ($b) => isset($b['latency_p95']['values']['95.0']) && $b['latency_p95']['values']['95.0'] !== null ? (int) round($b['latency_p95']['values']['95.0']) : null, $timeBuckets);
    $seriesErrorRate = array_map(function ($b) use ($bucketStatus) {
        $count = (int) ($b['doc_count'] ?? 0);
        return $count > 0 ? round(($bucketStatus($b, '4xx') + $bucketStatus($b, '5xx')) / $count * 100, 1) : 0;
    }, $timeBuckets);

    $hasData = $total > 0;

    $rangeLabel = $range === 'custom' ? 'Custom range' : ($ranges[$range]['label'] ?? 'Last 24 hours');
    $rangeSub   = $range === 'custom' ? 'custom range' : ($ranges[$range]['sub'] ?? 'last 24h');
    $perLabel   = $interval === '1d' ? 'day' : 'hour';

    $errorCount = $c4xx + $c5xx;

    // Direction split (incoming vs outgoing).
    $directionMap = collect($aggs['by_direction']['buckets'] ?? [])->pluck('doc_count', 'key');
    $dirOut       = (int) ($directionMap['outgoing'] ?? 0);
    $dirIn        = (int) ($directionMap['incoming'] ?? 0);
    $dirTotal     = max(1, $dirOut + $dirIn);

    // Providers ranked by average latency (reuses the by_provider buckets).
    $slowProviders = collect($providerBuckets)
        ->filter(fn ($b) => ($b['latency_avg']['value'] ?? null) !== null)
        ->sortByDesc(fn ($b) => $b['latency_avg']['value'])
        ->take(5)
        ->values();
    $slowMax = (float) ($slowProviders->max(fn ($b) => $b['latency_avg']['value']) ?: 1);

    // Deep-links from the KPI cards into the log list, scoped to the current window.
    $window   = array_filter([
        'from' => $filters['from'] ?? null,
        'to'   => $filters['to'] ?? null,
    ], fn ($v) => $v !== null && $v !== '');
    $logsLink = fn (array $params = []) => route('http-logs.logs.index', $window + $params, false);

    $timeoutCount = (int) ($aggs['timeouts']['doc_count'] ?? 0);

    // datetime-local inputs only accept `YYYY-MM-DDTHH:MM` (no seconds/zone), so normalize
    // any incoming value (e.g. an ISO `...Z` written by the chart drag-to-zoom) to the app timezone.
    $fmtLocal = function (?string $ts) use ($timezone): string {
        if (! $ts) {
            return '';
        }
        try {
            return \Illuminate\Support\Carbon::parse($ts)->timezone($timezone)->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            return '';
        }
    };

    $cards = [
        ['label' => 'Total requests', 'value' => number_format($total), 'sub' => $rangeSub, 'accent' => 'text-slate-900 dark:text-slate-100', 'link' => $logsLink()],
        ['label' => 'Success rate', 'value' => $successRate . '%', 'sub' => number_format($successCount) . ' ok', 'accent' => 'text-emerald-600', 'link' => $logsLink(['success' => 'true'])],
        ['label' => '4xx', 'value' => number_format($c4xx), 'sub' => 'client errors', 'accent' => 'text-amber-600', 'link' => $logsLink(['status_class' => '4xx'])],
        ['label' => '5xx', 'value' => number_format($c5xx), 'sub' => 'server errors', 'accent' => 'text-red-600', 'link' => $logsLink(['status_class' => '5xx'])],
        ['label' => 'Timeouts', 'value' => number_format($timeoutCount), 'sub' => 'timed out', 'accent' => 'text-amber-600', 'link' => $logsLink(['timeout' => 1])],
        ['label' => 'Avg latency', 'value' => $avgLatency !== null ? number_format($avgLatency) . ' ms' : '—', 'sub' => $p95Latency !== null ? 'p95 ' . number_format($p95Latency) . ' ms' : 'p95 —', 'accent' => 'text-indigo-600', 'link' => $logsLink(['sort' => 'latency', 'dir' => 'desc'])],
    ];
@endphp

@section('content')
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Overview</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ $rangeLabel }} of third-party HTTP traffic, per {{ $perLabel }}.</p>
        </div>
        <a href="{{ route('http-logs.logs.index', [], false) }}"
           class="inline-flex shrink-0 items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-indigo-700">
            Browse logs →
        </a>
    </div>

    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ($cards as $card)
            <a href="{{ $card['link'] }}"
               class="group rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-indigo-300 hover:shadow dark:border-slate-700 dark:bg-slate-800 dark:hover:border-indigo-500">
                <div class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ $card['label'] }}</div>
                <div class="mt-2 text-2xl font-semibold {{ $card['accent'] }}">{{ $card['value'] }}</div>
                <div class="mt-1 text-xs text-slate-400 group-hover:text-indigo-500 dark:text-slate-500">{{ $card['sub'] }} →</div>
            </a>
        @endforeach
    </div>

    <div class="mt-6 flex flex-wrap items-end justify-between gap-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-800">
        <form method="GET" action="{{ route('http-logs.overview', [], false) }}"
              class="flex flex-wrap items-end gap-4" x-data="{ range: @js($range) }">
            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400" for="range-select">Range</label>
                <select id="range-select" name="range" x-model="range"
                        @change="$el.value !== 'custom' && $el.form.submit()"
                        class="rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100">
                    @foreach ($ranges as $key => $meta)
                        <option value="{{ $key }}" @selected($range === $key)>{{ $meta['label'] }}</option>
                    @endforeach
                    <option value="custom" @selected($range === 'custom')>Custom range</option>
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400" for="interval-select">Interval</label>
                <select id="interval-select" name="interval" onchange="this.form.submit()"
                        class="rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100">
                    @foreach ($intervals as $key => $label)
                        <option value="{{ $key }}" @selected($interval === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <template x-if="range === 'custom'">
                <div class="flex flex-wrap items-end gap-4">
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400" for="from-input">From</label>
                        <input id="from-input" type="datetime-local" name="from" value="{{ $fmtLocal(request('from')) }}"
                               class="rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100">
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400" for="to-input">To</label>
                        <input id="to-input" type="datetime-local" name="to" value="{{ $fmtLocal(request('to')) }}"
                               class="rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-100">
                    </div>
                    <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-indigo-700">Apply</button>
                </div>
            </template>

            <noscript>
                <button type="submit" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-700 dark:text-slate-200">Apply</button>
            </noscript>
        </form>

        <div class="flex items-center gap-2"
             x-data="{
                on: localStorage.getItem('tphl_live') === '1',
                timer: null,
                toggle() {
                    localStorage.setItem('tphl_live', this.on ? '1' : '0');
                    if (this.on) { this.timer = setInterval(() => location.reload(), 30000); }
                    else if (this.timer) { clearInterval(this.timer); this.timer = null; }
                },
             }"
             x-init="if (on) { timer = setInterval(() => location.reload(), 30000); }">
            <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-600 dark:text-slate-300">
                <input type="checkbox" x-model="on" @change="toggle()"
                       class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-700">
                <span class="flex items-center gap-1">
                    <span class="inline-flex h-2 w-2 rounded-full" :class="on ? 'bg-emerald-500 animate-pulse' : 'bg-slate-300'"></span>
                    Live
                </span>
            </label>
            <span x-show="on" x-cloak class="text-[11px] text-slate-400 dark:text-slate-500">every 30s</span>
        </div>
    </div>

    @php
        $miniCharts = [
            ['id' => 'volumeChart',  'title' => 'Volume',     'sub' => 'Requests by status · per ' . $perLabel],
            ['id' => 'latencyChart', 'title' => 'Latency',    'sub' => 'Avg & p95 · per ' . $perLabel],
            ['id' => 'errorsChart',  'title' => 'Error rate', 'sub' => '4xx + 5xx share · per ' . $perLabel],
        ];
    @endphp

    <div class="mt-4 grid grid-cols-1 gap-4">
        @foreach ($miniCharts as $chart)
            @php
                // The Errors card shows a clean "all good" state when there are no errors at all.
                $isErrors    = $chart['id'] === 'errorsChart';
                $noErrors    = $isErrors && $hasData && $errorCount === 0;
                $showCanvas  = $hasData && ! $noErrors;
            @endphp
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-800">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                            {{ $chart['title'] }}
                            @if ($isErrors && $hasData)
                                <span class="ml-1 text-xs font-normal {{ $errorCount > 0 ? 'text-red-500' : 'text-emerald-500' }}">{{ number_format($errorCount) }} failed</span>
                            @endif
                        </h2>
                        <p class="text-xs text-slate-400 dark:text-slate-500">{{ $chart['sub'] }}</p>
                    </div>
                    @if ($showCanvas)
                        <button type="button" data-export="{{ $chart['id'] }}" data-export-name="{{ $chart['title'] }}"
                                class="shrink-0 rounded-md border border-slate-200 px-2 py-1 text-[11px] font-medium text-slate-500 transition hover:bg-slate-50 hover:text-slate-700 dark:border-slate-600 dark:text-slate-400 dark:hover:bg-slate-700 dark:hover:text-slate-200">
                            PNG
                        </button>
                    @endif
                </div>
                <div class="mt-3 h-72 sm:h-80">
                    @if ($noErrors)
                        <div class="flex h-full flex-col items-center justify-center text-center">
                            <span class="text-3xl">✓</span>
                            <p class="mt-2 text-sm font-medium text-emerald-600 dark:text-emerald-400">No errors</p>
                            <p class="text-xs text-slate-400 dark:text-slate-500">0 of {{ number_format($total) }} requests failed</p>
                        </div>
                    @elseif ($showCanvas)
                        <canvas id="{{ $chart['id'] }}"></canvas>
                    @else
                        <div class="flex h-full items-center justify-center text-center text-sm text-slate-400 dark:text-slate-500">No data for this window.</div>
                    @endif
                </div>
                @if ($showCanvas)
                    <p class="mt-2 text-center text-[11px] text-slate-400 dark:text-slate-500">Click a point to open matching logs · drag across to zoom into that range</p>
                @endif
            </div>
        @endforeach
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-800">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">By status class</h2>
            <div class="mt-4 space-y-3">
                @foreach ($statusOrder as $class => $count)
                    <div>
                        <div class="mb-1 flex items-center justify-between text-xs">
                            <span class="font-medium text-slate-600 dark:text-slate-300">{{ $class }}</span>
                            <span class="text-slate-400 dark:text-slate-500">{{ number_format($count) }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                            <div class="h-full {{ $statusColors[$class] }}" style="width: {{ round($count / $statusTotal * 100, 1) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-800">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Direction</h2>
            <div class="mt-4 space-y-3">
                @php $dirRows = [['outgoing', $dirOut, 'bg-slate-500'], ['incoming', $dirIn, 'bg-purple-500']]; @endphp
                @foreach ($dirRows as [$label, $count, $color])
                    <div>
                        <div class="mb-1 flex items-center justify-between text-xs">
                            <a href="{{ route('http-logs.logs.index', ['direction' => $label], false) }}"
                               class="font-medium capitalize text-indigo-600 hover:underline dark:text-indigo-400">{{ $label }}</a>
                            <span class="text-slate-400 dark:text-slate-500">{{ number_format($count) }} · {{ round($count / $dirTotal * 100) }}%</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                            <div class="h-full {{ $color }}" style="width: {{ round($count / $dirTotal * 100, 1) }}%"></div>
                        </div>
                    </div>
                @endforeach
                @if ($dirOut + $dirIn === 0)
                    <p class="text-xs text-slate-400 dark:text-slate-500">No traffic in this window.</p>
                @endif
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-800">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Top providers <span class="text-xs font-normal text-slate-400">by volume</span></h2>
            <div class="mt-4 space-y-3">
                @forelse ($providerBuckets as $bucket)
                    <div>
                        <div class="mb-1 flex items-center justify-between text-xs">
                            <a href="{{ route('http-logs.logs.index', ['provider' => $bucket['key']], false) }}"
                               class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ $bucket['key'] }}</a>
                            <span class="text-slate-400 dark:text-slate-500">{{ number_format($bucket['doc_count']) }}</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                            <div class="h-full bg-indigo-500" style="width: {{ round($bucket['doc_count'] / $providerMax * 100, 1) }}%"></div>
                        </div>
                    </div>
                @empty
                    <p class="text-xs text-slate-400 dark:text-slate-500">No providers yet.</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-800">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Slowest providers <span class="text-xs font-normal text-slate-400">avg latency</span></h2>
            <div class="mt-4 space-y-3">
                @forelse ($slowProviders as $bucket)
                    @php $avgMs = (int) round($bucket['latency_avg']['value']); @endphp
                    <div>
                        <div class="mb-1 flex items-center justify-between text-xs">
                            <a href="{{ route('http-logs.logs.index', ['provider' => $bucket['key'], 'sort' => 'latency', 'dir' => 'desc'], false) }}"
                               class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ $bucket['key'] }}</a>
                            <span class="text-slate-400 dark:text-slate-500">{{ number_format($avgMs) }} ms</span>
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
@endsection

@push('scripts')
    @if ($hasData)
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"
                integrity="sha384-vsrfeLOOY6KuIYKDlmVH5UiBmgIdB1oEf7p01YgWHuqmOHfZr374+odEv96n9tNC"
                crossorigin="anonymous"></script>
        <script>
            (function () {
                const tz       = @json($timezone);
                const interval = @json($interval);
                const perDay   = interval === '1d';
                const labels   = @json($chartLabels);
                const logsBase = @json(route('http-logs.logs.index', [], false));
                const overviewBase = @json(route('http-logs.overview', [], false));
                const bucketMs = perDay ? 86400000 : 3600000;

                const axisOpts = perDay
                    ? { timeZone: tz, month: 'short', day: 'numeric' }
                    : { timeZone: tz, month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false };
                const fullOpts = perDay
                    ? { timeZone: tz, weekday: 'short', month: 'short', day: 'numeric' }
                    : { timeZone: tz, month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false };
                const fmtAxis = (iso) => new Date(iso).toLocaleString([], axisOpts);
                const fmtFull = (iso) => new Date(iso).toLocaleString([], fullOpts);

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

                // Mark + label the peak of any dataset flagged `peak: true`.
                const peakLabel = {
                    id: 'peakLabel',
                    afterDatasetsDraw(chart) {
                        chart.data.datasets.forEach((ds, di) => {
                            if (!ds.peak || chart.getDatasetMeta(di).hidden) return;
                            let maxV = -Infinity, maxI = -1;
                            ds.data.forEach((v, i) => { if (v != null && v > maxV) { maxV = v; maxI = i; } });
                            if (maxI < 0 || maxV <= 0) return;
                            const pt = chart.getDatasetMeta(di).data[maxI];
                            if (!pt) return;
                            const ctx = chart.ctx;
                            ctx.save();
                            ctx.fillStyle = ds.borderColor || '#475569';
                            ctx.beginPath();
                            ctx.arc(pt.x, pt.y, 3, 0, Math.PI * 2);
                            ctx.fill();
                            ctx.font = '600 10px ui-sans-serif, system-ui, sans-serif';
                            ctx.textAlign = 'center';
                            ctx.fillText('▲ ' + (ds.peakUnit ? maxV + ds.peakUnit : maxV), pt.x, Math.max(pt.y - 8, 9));
                            ctx.restore();
                        });
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

                // Set by a drag so the trailing click doesn't also trigger onClick navigation.
                let suppressClick = false;

                // Drag horizontally across a chart to zoom the whole dashboard into the
                // selected sub-range (re-renders the overview with a custom from/to).
                const dragSelect = {
                    id: 'dragSelect',
                    _isDown: (t) => t === 'mousedown' || t === 'touchstart',
                    _isMove: (t) => t === 'mousemove' || t === 'touchmove',
                    _isUp:   (t) => t === 'mouseup' || t === 'touchend',
                    afterEvent(chart, args) {
                        const e = args.event;
                        const area = chart.chartArea;
                        const st = chart.$drag || (chart.$drag = { active: false, startX: null, curX: null });
                        const clamp = (x) => Math.min(Math.max(x, area.left), area.right);

                        if (dragSelect._isDown(e.type)) {
                            st.active = true;
                            st.startX = st.curX = clamp(e.x);
                        } else if (dragSelect._isMove(e.type) && st.active) {
                            st.curX = clamp(e.x);
                            if (e.native) e.native.target.style.cursor = 'col-resize';
                            args.changed = true;
                        } else if (st.active && (dragSelect._isUp(e.type) || e.type === 'mouseout')) {
                            const { startX, curX } = st;
                            st.active = false;
                            st.startX = st.curX = null;
                            args.changed = true;

                            // A short drag (or leaving the chart) is treated as a click, not a zoom.
                            if (e.type === 'mouseout' || startX === null || Math.abs(curX - startX) < 8) return;

                            const xScale = chart.scales.x;
                            const last = labels.length - 1;
                            const idx = (px) => Math.max(0, Math.min(last, Math.round(xScale.getValueForPixel(px))));
                            const i1 = idx(Math.min(startX, curX));
                            const i2 = idx(Math.max(startX, curX));

                            suppressClick = true; // the trailing click after a drag must not also open logs

                            const start = new Date(labels[i1]);
                            const end   = new Date(new Date(labels[i2]).getTime() + bucketMs);
                            const qs = new URLSearchParams(window.location.search);
                            qs.set('range', 'custom');
                            qs.set('interval', interval);
                            qs.set('from', start.toISOString());
                            qs.set('to', end.toISOString());
                            window.location = overviewBase + '?' + qs.toString();
                        }
                    },
                    afterDraw(chart) {
                        const st = chart.$drag;
                        if (!st || !st.active || st.startX === null) return;
                        const area = chart.chartArea;
                        const ctx = chart.ctx;
                        const x = Math.min(st.startX, st.curX);
                        const w = Math.abs(st.curX - st.startX);
                        ctx.save();
                        ctx.fillStyle = 'rgba(99, 102, 241, 0.12)';
                        ctx.strokeStyle = 'rgba(99, 102, 241, 0.5)';
                        ctx.lineWidth = 1;
                        ctx.fillRect(x, area.top, w, area.bottom - area.top);
                        ctx.strokeRect(x, area.top, w, area.bottom - area.top);
                        ctx.restore();
                    },
                };

                // Click a bucket → open the log list filtered to that time window.
                const onClick = (evt, els) => {
                    if (suppressClick) { suppressClick = false; return; }
                    if (!els.length) return;
                    const i = els[0].index;
                    const start = new Date(labels[i]);
                    const end = new Date(start.getTime() + (perDay ? 86400000 : 3600000));
                    const qs = new URLSearchParams({ from: start.toISOString(), to: end.toISOString() });
                    window.location = logsBase + '?' + qs.toString();
                };

                const gradient = (color) => (ctx) => {
                    const area = ctx.chart.chartArea;
                    if (!area) return color + '20';
                    const g = ctx.chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
                    g.addColorStop(0, color + '40');
                    g.addColorStop(1, color + '00');
                    return g;
                };

                const baseOptions = (opts = {}) => ({
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    // mousedown/mouseup/touch* are needed so the dragSelect plugin sees the full gesture.
                    events: ['mousemove', 'mouseout', 'click', 'mousedown', 'mouseup', 'touchstart', 'touchmove', 'touchend'],
                    onClick,
                    onHover: (e, els, chart) => {
                        if (!e.native) return;
                        e.native.target.style.cursor = (chart.$drag && chart.$drag.active)
                            ? 'col-resize'
                            : (els.length ? 'pointer' : 'default');
                    },
                    plugins: {
                        legend: opts.legend
                            ? { display: true, position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, font: { size: 10 }, color: '#64748b' } }
                            : { display: false },
                        tooltip: {
                            callbacks: Object.assign(
                                { title: (items) => fmtFull(labels[items[0].dataIndex]) },
                                opts.tooltipLabel ? { label: opts.tooltipLabel } : {},
                            ),
                        },
                    },
                    scales: {
                        x: {
                            stacked: !!opts.stacked,
                            ticks: { maxTicksLimit: 6, maxRotation: 0, autoSkip: true, color: '#94a3b8', callback: function (v) { return fmtAxis(this.getLabelForValue(v)); } },
                            grid: { display: false },
                        },
                        y: Object.assign({ beginAtZero: true, stacked: !!opts.stacked, grid: { color: '#f1f5f9' }, ticks: { precision: 0, color: '#94a3b8' } }, opts.y || {}),
                    },
                });

                const charts = {};

                // Volume — stacked status bars by class.
                const statusDs = (label, color, data) => ({ label, data, backgroundColor: color, stack: 's', borderWidth: 0, categoryPercentage: 0.92, barPercentage: 0.98 });
                charts.volumeChart = new Chart(document.getElementById('volumeChart'), {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [
                            statusDs('2xx', '#10b981', @json($series2xx)),
                            statusDs('3xx', '#0ea5e9', @json($series3xx)),
                            statusDs('4xx', '#f59e0b', @json($series4xx)),
                            statusDs('5xx', '#ef4444', @json($series5xx)),
                        ],
                    },
                    options: baseOptions({ stacked: true, legend: true }),
                    plugins: [crosshair, dragSelect, whiteBg],
                });

                // Latency — avg (filled) + p95 (dashed), in ms.
                charts.latencyChart = new Chart(document.getElementById('latencyChart'), {
                    type: 'line',
                    data: {
                        labels,
                        datasets: [
                            { label: 'avg', data: @json($seriesLatAvg), borderColor: '#6366f1', backgroundColor: gradient('#6366f1'), borderWidth: 2, tension: 0.3, pointRadius: 0, pointHoverRadius: 4, fill: true, spanGaps: true, peak: true, peakUnit: ' ms' },
                            { label: 'p95', data: @json($seriesLatP95), borderColor: '#a855f7', borderDash: [4, 4], borderWidth: 1.5, tension: 0.3, pointRadius: 0, pointHoverRadius: 4, fill: false, spanGaps: true },
                        ],
                    },
                    options: baseOptions({
                        legend: true,
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { color: '#94a3b8', precision: 0, callback: (v) => v + ' ms' } },
                        tooltipLabel: (ctx) => ctx.dataset.label + ': ' + (ctx.parsed.y == null ? '—' : ctx.parsed.y + ' ms'),
                    }),
                    plugins: [crosshair, peakLabel, dragSelect, whiteBg],
                });

                // Error rate — % of 4xx + 5xx per bucket. (Absent when the window has no errors.)
                const errorsEl = document.getElementById('errorsChart');
                if (errorsEl) {
                    charts.errorsChart = new Chart(errorsEl, {
                        type: 'line',
                        data: {
                            labels,
                            datasets: [
                                { label: 'error rate', data: @json($seriesErrorRate), borderColor: '#ef4444', backgroundColor: gradient('#ef4444'), borderWidth: 2, tension: 0.3, pointRadius: 0, pointHoverRadius: 4, fill: true, peak: true, peakUnit: '%' },
                            ],
                        },
                        options: baseOptions({
                            y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { color: '#94a3b8', callback: (v) => v + '%' } },
                            tooltipLabel: (ctx) => 'Error rate: ' + ctx.parsed.y + '%',
                        }),
                        plugins: [crosshair, peakLabel, dragSelect, whiteBg],
                    });
                }

                // PNG export per chart.
                document.querySelectorAll('[data-export]').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        const chart = charts[btn.dataset.export];
                        if (!chart) return;
                        const a = document.createElement('a');
                        a.href = chart.toBase64Image('image/png', 1);
                        a.download = (btn.dataset.exportName || 'chart').toLowerCase().replace(/\s+/g, '-') + '.png';
                        a.click();
                    });
                });
            })();
        </script>
    @endif
@endpush
