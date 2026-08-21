@extends('elastic-audit::layout')

@section('dashboard', 'metrics')
@section('title', 'Performance Overview')

@section('content')
    @php
        $aggs = $data['aggs'] ?? [];
        $total = (int) ($data['total'] ?? 0);
        $failureCount = (int) data_get($aggs, 'failures.doc_count', 0);
        $profileCount = (int) data_get($aggs, 'profiled.doc_count', 0);
        $p95 = (float) ($aggs['latency']['values']['95.0'] ?? $aggs['latency']['values']['95'] ?? 0);
        $failureRate = $total > 0 ? ($failureCount / $total) * 100 : 0;
        $timeBuckets = data_get($aggs, 'throughput.buckets', []);
        $chartLabels = array_map(fn ($bucket) => $bucket['key_as_string'] ?? null, $timeBuckets);
        $chartVolume = array_map(fn ($bucket) => (int) ($bucket['doc_count'] ?? 0), $timeBuckets);
        $chartFailed = array_map(fn ($bucket) => (int) data_get($bucket, 'failures.doc_count', 0), $timeBuckets);
        $chartP95 = array_map(fn ($bucket) => (float) ($bucket['p95']['values']['95.0'] ?? 0), $timeBuckets);
    @endphp

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-slate-950 dark:text-white">Application performance</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Transactions, spans, failures, and sampled PHP profiles.</p>
        </div>
        <form method="GET" class="flex gap-2">
            <select name="range" class="ea-focus h-10 rounded-md border-slate-300 bg-white text-sm dark:border-slate-700 dark:bg-slate-900">
                @foreach ($ranges as $key => $option)
                    <option value="{{ $key }}" @selected($range === $key)>{{ $option['label'] }}</option>
                @endforeach
            </select>
            <button class="ea-focus rounded-md bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-700">Apply</button>
        </form>
    </div>

    @if ($timeBuckets !== [])
        <section class="ea-panel mb-6 rounded-lg border p-5">
            <div class="mb-4"><h2 class="font-semibold text-slate-950 dark:text-white">Throughput and p95 latency</h2><p class="text-xs text-slate-500">Transactions per bucket; failures are highlighted.</p></div>
            <div class="h-72"><canvas id="metricsThroughputChart"></canvas></div>
        </section>
    @endif

    <div class="mb-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Transactions', number_format($total)],
            ['Average', number_format((float) data_get($aggs, 'duration.value', 0), 1).' ms'],
            ['p95 latency', number_format($p95, 1).' ms'],
            ['Failure rate', number_format($failureRate, 2).'%'],
        ] as [$label, $value])
            <div class="ea-panel rounded-lg border p-5">
                <div class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ $label }}</div>
                <div class="mt-2 text-2xl font-semibold text-slate-950 dark:text-white">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="ea-panel overflow-hidden rounded-lg border">
            <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                <h2 class="font-semibold text-slate-950 dark:text-white">Transaction types</h2>
            </div>
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse (data_get($aggs, 'by_type.buckets', []) as $bucket)
                    <div class="flex items-center justify-between px-5 py-3 text-sm">
                        <span class="font-mono">{{ $bucket['key'] }}</span>
                        <span class="font-semibold">{{ number_format($bucket['doc_count']) }}</span>
                    </div>
                @empty
                    <p class="px-5 py-8 text-center text-sm text-slate-500">No transactions in this window.</p>
                @endforelse
            </div>
        </section>

        <section class="ea-panel overflow-hidden rounded-lg border">
            <div class="border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                <h2 class="font-semibold text-slate-950 dark:text-white">Slowest transaction groups</h2>
            </div>
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse (data_get($aggs, 'slowest.buckets', []) as $bucket)
                    <div class="flex items-center justify-between gap-4 px-5 py-3 text-sm">
                        <span class="min-w-0 truncate font-mono" title="{{ $bucket['key'] }}">{{ $bucket['key'] }}</span>
                        <span class="shrink-0 font-semibold">{{ number_format((float) data_get($bucket, 'avg_duration.value', 0), 1) }} ms</span>
                    </div>
                @empty
                    <p class="px-5 py-8 text-center text-sm text-slate-500">No latency groups available.</p>
                @endforelse
            </div>
        </section>
    </div>

    <div class="mt-6 text-sm text-slate-500 dark:text-slate-400">
        {{ number_format($profileCount) }} transaction profiles captured in this window.
        <a href="{{ route('elastic-audit-metrics.transactions', [], false) }}" class="ml-2 font-medium text-indigo-600 hover:underline dark:text-indigo-400">Browse transactions →</a>
    </div>
@endsection

@push('scripts')
    @if ($timeBuckets !== [])
        <script type="module" src="{{ route('elastic-audit.assets', ['asset' => $elasticAuditAssets['resources/js/chart.js']['file']], false) }}"></script>
        <script type="module">
            new Chart(document.getElementById('metricsThroughputChart'), {
                type: 'bar',
                data: {
                    labels: @json($chartLabels),
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
                    scales: {
                        count: { beginAtZero: true, position: 'left', ticks: { precision: 0 } },
                        latency: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { callback: value => value + ' ms' } },
                        x: { grid: { display: false }, ticks: { maxTicksLimit: 8, maxRotation: 0 } },
                    },
                },
            });
        </script>
    @endif
@endpush
