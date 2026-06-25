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

    $withParams = fn (array $overrides) => request()->fullUrlWithQuery($overrides);

    // Time series feeding the "Activity over time" chart (success vs failed per bucket).
    $timeBuckets = $aggs['over_time']['buckets'] ?? [];
    $chartLabels = array_map(fn ($b) => $b['key_as_string'] ?? (string) ($b['key'] ?? ''), $timeBuckets);

    $bucketSuccess = static fn (array $b, string $key): int
        => (int) (collect($b['success']['buckets'] ?? [])->firstWhere('key_as_string', $key)['doc_count'] ?? 0);

    $seriesSuccess = array_map(fn ($b) => $bucketSuccess($b, 'true'), $timeBuckets);
    $seriesFailure = array_map(fn ($b) => $bucketSuccess($b, 'false'), $timeBuckets);

    $successRate = $total > 0 ? round($successCount / $total * 100, 1) : 0.0;

    $hasData  = $total > 0;
    $perLabel = $interval === '1d' ? 'day' : 'hour';
@endphp

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div class="flex flex-wrap items-center gap-3">
        <span class="text-sm text-slate-500 dark:text-slate-400">Range:</span>
        @foreach($ranges as $key => $r)
            <a href="{{ $withParams(['range' => $key]) }}"
               class="rounded px-2.5 py-1 text-sm {{ $range === $key ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700' }} ring-1 ring-inset ring-slate-200 dark:ring-slate-700">
                {{ $r['label'] }}
            </a>
        @endforeach
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <span class="text-sm text-slate-500 dark:text-slate-400">Interval:</span>
        @foreach($intervals as $key => $label)
            <a href="{{ $withParams(['interval' => $key]) }}"
               class="rounded px-2.5 py-1 text-sm {{ $interval === $key ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 hover:bg-slate-50 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700' }} ring-1 ring-inset ring-slate-200 dark:ring-slate-700">
                {{ $label }}
            </a>
        @endforeach
    </div>
</div>

@if($error)
    <div class="mb-4 rounded bg-red-50 p-4 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">{{ $error }}</div>
@endif

<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
        <div class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Total Events</div>
        <div class="mt-1 text-3xl font-bold">{{ number_format($total) }}</div>
    </div>
    <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
        <div class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Success Rate</div>
        <div class="mt-1 text-3xl font-bold text-indigo-600 dark:text-indigo-400">{{ $successRate }}%</div>
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

{{-- Activity over time --}}
<div class="mb-6 rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
    <div class="flex items-start justify-between gap-2">
        <div>
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-300">Activity over time</h3>
            <p class="text-xs text-slate-400 dark:text-slate-500">Events by outcome · per {{ $perLabel }}</p>
        </div>
        @if($hasData)
            <button type="button" data-export="activityChart" data-export-name="Activity over time"
                    class="shrink-0 rounded-md border border-slate-200 px-2 py-1 text-[11px] font-medium text-slate-500 transition hover:bg-slate-50 hover:text-slate-700 dark:border-slate-600 dark:text-slate-400 dark:hover:bg-slate-700 dark:hover:text-slate-200">
                PNG
            </button>
        @endif
    </div>
    <div class="mt-3 h-72 sm:h-80">
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

<div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
    {{-- Top Actions --}}
    <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
        <h3 class="mb-4 text-sm font-semibold text-slate-700 dark:text-slate-300">Top Actions</h3>
        @forelse($topActions as $bucket)
            <div class="py-1.5">
                <div class="mb-1 flex items-center justify-between text-xs">
                    <a href="{{ route('activity-logs.logs.index', ['action' => $bucket['key']]) }}"
                       class="font-mono text-indigo-600 hover:underline dark:text-indigo-400">
                        {{ $bucket['key'] }}
                    </a>
                    <span class="font-medium text-slate-500 dark:text-slate-400">{{ number_format($bucket['doc_count']) }}</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                    <div class="h-full bg-indigo-500" style="width: {{ round($bucket['doc_count'] / $actionMax * 100, 1) }}%"></div>
                </div>
            </div>
        @empty
            <p class="text-sm text-slate-400">No data yet.</p>
        @endforelse
    </div>

    {{-- Actor Types --}}
    <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
        <h3 class="mb-4 text-sm font-semibold text-slate-700 dark:text-slate-300">By Actor Type</h3>
        @forelse($topActors as $bucket)
            <div class="py-1.5">
                <div class="mb-1 flex items-center justify-between text-xs">
                    <a href="{{ route('activity-logs.logs.index', ['actor_type' => $bucket['key']]) }}"
                       class="text-indigo-600 hover:underline dark:text-indigo-400">
                        {{ $bucket['key'] }}
                    </a>
                    <span class="font-medium text-slate-500 dark:text-slate-400">{{ number_format($bucket['doc_count']) }}</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                    <div class="h-full bg-purple-500" style="width: {{ round($bucket['doc_count'] / $actorMax * 100, 1) }}%"></div>
                </div>
            </div>
        @empty
            <p class="text-sm text-slate-400">No data yet.</p>
        @endforelse
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
