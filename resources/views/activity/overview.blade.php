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
    $actionMax  = (int) ($topActions->max('doc_count') ?: 1);
    $actorMax   = (int) ($topActors->max('doc_count') ?: 1);

    // Time series feeding the "Activity over time" chart (success vs failed per bucket).
    $timeBuckets = $aggs['over_time']['buckets'] ?? [];
    $chartLabels = array_map(fn ($b) => $b['key_as_string'] ?? (string) ($b['key'] ?? ''), $timeBuckets);

    $bucketSuccess = static fn (array $b, string $key): int
        => (int) (collect($b['success']['buckets'] ?? [])->firstWhere('key_as_string', $key)['doc_count'] ?? 0);

    $seriesSuccess = array_map(fn ($b) => $bucketSuccess($b, 'true'), $timeBuckets);
    $seriesFailure = array_map(fn ($b) => $bucketSuccess($b, 'false'), $timeBuckets);

    $successRate = $total > 0 ? round($successCount / $total * 100, 1) : 0.0;
    $failureRate = $total > 0 ? round($failureCount / $total * 100, 1) : 0.0;

    $hasData  = $total > 0;
    $perLabel = $interval === '1d' ? 'day' : 'hour';
    $rangeLabel = $range === 'custom' ? 'Custom range' : ($ranges[$range]['label'] ?? 'Last 24 hours');
    $rangeSub   = $range === 'custom' ? 'custom range' : ($ranges[$range]['sub'] ?? 'last 24h');

    $window = array_filter([
        'from' => $filters['from'] ?? null,
        'to'   => $filters['to'] ?? null,
    ], fn ($v) => $v !== null && $v !== '');

    $logsLink = fn (array $params = []) => route('activity-logs.logs.index', $window + $params, false);

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
        ['label' => 'Total events', 'value' => number_format($total), 'sub' => $rangeSub, 'accent' => 'text-slate-900 dark:text-slate-100', 'link' => $logsLink()],
        ['label' => 'Success rate', 'value' => $successRate . '%', 'sub' => number_format($successCount) . ' ok', 'accent' => 'text-emerald-600', 'link' => $logsLink(['success' => 'true'])],
        ['label' => 'Failed', 'value' => number_format($failureCount), 'sub' => $failureRate . '% of events', 'accent' => 'text-red-600', 'link' => $logsLink(['success' => 'false'])],
        ['label' => 'Top actions', 'value' => number_format($topActions->count()), 'sub' => 'distinct actions', 'accent' => 'text-indigo-600', 'link' => $logsLink()],
    ];
@endphp

@if($error)
    <div class="mb-4 rounded bg-red-50 p-4 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $error }}</div>
@endif

<div class="mb-5 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div>
        <div class="mb-2 inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 dark:border-indigo-900/70 dark:bg-indigo-950/40 dark:text-indigo-300">
            {{ $rangeLabel }} · per {{ $perLabel }}
        </div>
        <h1 class="text-2xl font-semibold tracking-normal text-slate-950 dark:text-slate-50">Activity trail</h1>
        <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">Actor actions, model changes, failures, and entity history for the selected window.</p>
    </div>
    <a href="{{ route('activity-logs.logs.index', [], false) }}"
       class="ea-focus inline-flex h-10 shrink-0 items-center justify-center rounded-md bg-indigo-600 px-4 text-sm font-medium text-white shadow-sm transition hover:bg-indigo-700">
        Browse activity →
    </a>
</div>

<div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
    @foreach ($cards as $card)
        <a href="{{ $card['link'] }}"
           class="ea-focus ea-panel group min-h-[92px] rounded-lg border p-3.5 transition hover:-translate-y-0.5 hover:border-indigo-300 hover:shadow-md dark:hover:border-indigo-500">
            <div class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ $card['label'] }}</div>
            <div class="mt-1.5 text-2xl font-semibold {{ $card['accent'] }}">{{ $card['value'] }}</div>
            <div class="mt-1 text-xs text-slate-400 group-hover:text-indigo-500 dark:text-slate-500">{{ $card['sub'] }} →</div>
        </a>
    @endforeach
</div>

<div class="ea-panel mt-4 flex flex-wrap items-end justify-between gap-4 rounded-lg border p-4">
    <form method="GET" action="{{ route('activity-logs.overview', [], false) }}"
          class="flex flex-wrap items-end gap-4" x-data="{ range: @js($range) }">
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400" for="activity-range-select">Range</label>
            <select id="activity-range-select" name="range" x-model="range"
                    @change="$el.value !== 'custom' && $el.form.submit()"
                    class="ea-focus h-10 rounded-md border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                @foreach ($ranges as $key => $meta)
                    <option value="{{ $key }}" @selected($range === $key)>{{ $meta['label'] }}</option>
                @endforeach
                <option value="custom" @selected($range === 'custom')>Custom range</option>
            </select>
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400" for="activity-interval-select">Interval</label>
            <select id="activity-interval-select" name="interval" onchange="this.form.submit()"
                    class="ea-focus h-10 rounded-md border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                @foreach ($intervals as $key => $label)
                    <option value="{{ $key }}" @selected($interval === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <template x-if="range === 'custom'">
            <div class="flex flex-wrap items-end gap-4">
                <div class="flex flex-col gap-1">
                    <label class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400" for="activity-from-input">From</label>
                    <input id="activity-from-input" type="datetime-local" name="from" value="{{ $fmtLocal(request('from')) }}"
                           class="ea-focus h-10 rounded-md border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400" for="activity-to-input">To</label>
                    <input id="activity-to-input" type="datetime-local" name="to" value="{{ $fmtLocal(request('to')) }}"
                           class="ea-focus h-10 rounded-md border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                </div>
                <button type="submit" class="ea-focus h-10 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white transition hover:bg-indigo-700">Apply</button>
            </div>
        </template>

        <noscript>
            <button type="submit" class="ea-focus h-10 rounded-md border border-slate-300 bg-white px-4 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200">Apply</button>
        </noscript>
    </form>

    <div class="flex items-center gap-2"
         x-data="{
            on: localStorage.getItem('tphl_live_activity_overview') === '1',
            timer: null,
            toggle() {
                localStorage.setItem('tphl_live_activity_overview', this.on ? '1' : '0');
                if (this.on) { this.timer = setInterval(() => location.reload(), 30000); }
                else if (this.timer) { clearInterval(this.timer); this.timer = null; }
            },
         }"
         x-init="if (on) { timer = setInterval(() => location.reload(), 30000); }">
        <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-600 dark:text-slate-300">
            <input type="checkbox" x-model="on" @change="toggle()"
                   class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900">
            <span class="flex items-center gap-1">
                <span class="inline-flex h-2 w-2 rounded-full" :class="on ? 'bg-emerald-500 animate-pulse' : 'bg-slate-300'"></span>
                Live
            </span>
        </label>
        <span x-show="on" x-cloak class="text-[11px] text-slate-400 dark:text-slate-500">every 30s</span>
    </div>
</div>

{{-- Activity over time --}}
<div class="ea-panel mt-4 rounded-lg border p-4">
    <div class="flex items-start justify-between gap-2">
        <div>
            <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                Activity over time
                @if($hasData)
                    <span class="ml-1 text-xs font-normal {{ $failureCount > 0 ? 'text-red-500' : 'text-emerald-500' }}">{{ number_format($failureCount) }} failed</span>
                @endif
            </h2>
            <p class="text-xs text-slate-400 dark:text-slate-500">Events by outcome · per {{ $perLabel }}</p>
        </div>
        @if($hasData)
            <button type="button" data-export="activityChart" data-export-name="Activity over time"
                    class="shrink-0 rounded-md border border-slate-200 px-2 py-1 text-[11px] font-medium text-slate-500 transition hover:bg-slate-50 hover:text-slate-700 dark:border-slate-600 dark:text-slate-400 dark:hover:bg-slate-700 dark:hover:text-slate-200">
                PNG
            </button>
        @endif
    </div>
    <div class="mt-3 h-72 rounded-md bg-white p-2 ring-1 ring-slate-200 sm:h-80 dark:ring-slate-700">
        @if($hasData)
            <canvas id="activityChart"></canvas>
        @else
            <div class="flex h-full items-center justify-center text-center text-sm text-slate-400 dark:text-slate-500">No data for this window.</div>
        @endif
    </div>
    @if($hasData)
        <p class="mt-2 text-center text-[11px] text-slate-400 dark:text-slate-500">Click a bar to open matching logs · drag across to zoom into that range</p>
    @endif
</div>

<div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
    {{-- Top Actions --}}
    <div class="ea-panel rounded-lg border p-4">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Top actions</h2>
        <div class="mt-4 space-y-3">
        @forelse($topActions as $bucket)
            <div>
                <div class="mb-1 flex items-center justify-between gap-3 text-xs">
                    <a href="{{ route('activity-logs.logs.index', ['action' => $bucket['key']]) }}"
                       class="min-w-0 truncate font-mono text-indigo-600 hover:underline dark:text-indigo-400">
                        {{ $bucket['key'] }}
                    </a>
                    <span class="font-medium text-slate-500 dark:text-slate-400">{{ number_format($bucket['doc_count']) }}</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                    <div class="h-full rounded-full bg-indigo-500" style="width: {{ round($bucket['doc_count'] / $actionMax * 100, 1) }}%"></div>
                </div>
            </div>
        @empty
            <p class="text-sm text-slate-400">No data yet.</p>
        @endforelse
        </div>
    </div>

    {{-- Actor Types --}}
    <div class="ea-panel rounded-lg border p-4">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">By actor type</h2>
        <div class="mt-4 space-y-3">
        @forelse($topActors as $bucket)
            <div>
                <div class="mb-1 flex items-center justify-between text-xs">
                    <a href="{{ route('activity-logs.logs.index', ['actor_type' => $bucket['key']]) }}"
                       class="text-indigo-600 hover:underline dark:text-indigo-400">
                        {{ $bucket['key'] }}
                    </a>
                    <span class="font-medium text-slate-500 dark:text-slate-400">{{ number_format($bucket['doc_count']) }}</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                    <div class="h-full rounded-full bg-violet-500" style="width: {{ round($bucket['doc_count'] / $actorMax * 100, 1) }}%"></div>
                </div>
            </div>
        @empty
            <p class="text-sm text-slate-400">No data yet.</p>
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
                const logsBase = @json(route('activity-logs.logs.index', [], false));
                const overviewBase = @json(route('activity-logs.overview', [], false));
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

                // Drag horizontally across the chart to zoom the overview into the
                // selected sub-range (re-renders with a custom from/to).
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
                    const end = new Date(start.getTime() + bucketMs);
                    const qs = new URLSearchParams({ from: start.toISOString(), to: end.toISOString() });
                    window.location = logsBase + '?' + qs.toString();
                };

                const baseOptions = {
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
                        legend: { display: true, position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, font: { size: 10 }, color: '#64748b' } },
                        tooltip: { callbacks: { title: (items) => fmtFull(labels[items[0].dataIndex]) } },
                    },
                    scales: {
                        x: {
                            stacked: true,
                            ticks: { maxTicksLimit: 6, maxRotation: 0, autoSkip: true, color: '#94a3b8', callback: function (v) { return fmtAxis(this.getLabelForValue(v)); } },
                            grid: { display: false },
                        },
                        y: { beginAtZero: true, stacked: true, grid: { color: '#f1f5f9' }, ticks: { precision: 0, color: '#94a3b8' } },
                    },
                };

                const charts = {};

                const statusDs = (label, color, data) => ({ label, data, backgroundColor: color, stack: 's', borderWidth: 0, categoryPercentage: 0.92, barPercentage: 0.98 });
                charts.activityChart = new Chart(document.getElementById('activityChart'), {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [
                            statusDs('successful', '#10b981', @json($seriesSuccess)),
                            statusDs('failed', '#ef4444', @json($seriesFailure)),
                        ],
                    },
                    options: baseOptions,
                    plugins: [crosshair, dragSelect, whiteBg],
                });

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
