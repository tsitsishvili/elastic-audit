@extends('elastic-audit::layout')

@section('dashboard', 'metrics')
@section('title', 'Performance Transactions')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold text-slate-950 dark:text-white">Transactions</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Root application operations with trace and profile links.</p>
    </div>

    <form method="GET" class="ea-panel mb-6 grid gap-3 rounded-lg border p-4 md:grid-cols-4">
        <input name="type" value="{{ $filters['type'] ?? '' }}" placeholder="Type, e.g. http.server" class="ea-focus h-10 rounded-md border-slate-300 bg-white text-sm dark:border-slate-700 dark:bg-slate-900">
        <select name="outcome" class="ea-focus h-10 rounded-md border-slate-300 bg-white text-sm dark:border-slate-700 dark:bg-slate-900">
            <option value="">Any outcome</option>
            <option value="success" @selected(($filters['outcome'] ?? '') === 'success')>Success</option>
            <option value="failure" @selected(($filters['outcome'] ?? '') === 'failure')>Failure</option>
        </select>
        <input name="service" value="{{ $filters['service'] ?? '' }}" placeholder="Service" class="ea-focus h-10 rounded-md border-slate-300 bg-white text-sm dark:border-slate-700 dark:bg-slate-900">
        <div class="flex gap-2">
            <button class="ea-focus rounded-md bg-indigo-600 px-4 text-sm font-medium text-white">Filter</button>
            <a href="{{ route('elastic-audit-metrics.transactions', [], false) }}" class="ea-focus inline-flex items-center rounded-md border border-slate-300 px-4 text-sm dark:border-slate-700">Reset</a>
        </div>
    </form>

    <div class="ea-panel overflow-hidden rounded-lg border">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-900 dark:text-slate-400">
                    <tr><th class="px-4 py-3">Time</th><th class="px-4 py-3">Transaction</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Outcome</th><th class="px-4 py-3 text-right">Duration</th><th class="px-4 py-3 text-right">Spans</th><th class="px-4 py-3">Profile</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($data['hits'] as $item)
                        <tr class="hover:bg-indigo-50/40 dark:hover:bg-slate-900/60">
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-500">{{ data_get($item, '@timestamp') }}</td>
                            <td class="max-w-sm px-4 py-3">
                                <a href="{{ route('elastic-audit-metrics.traces.show', data_get($item, 'trace.id'), false) }}" class="block truncate font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ data_get($item, 'name') }}</a>
                                <div class="font-mono text-xs text-slate-400">{{ data_get($item, 'service.name') }}</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs">{{ data_get($item, 'type') }}</td>
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
                        <tr><td colspan="7" class="px-4 py-12 text-center text-slate-500">No transactions found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @php $pages = max(1, (int) ceil(($data['total'] ?? 0) / $perPage)); @endphp
    @if ($pages > 1)
        <div class="mt-4 flex items-center justify-between text-sm">
            <span class="text-slate-500">Page {{ $page }} of {{ $pages }}</span>
            <div class="flex gap-2">
                @if ($page > 1)<a href="{{ request()->fullUrlWithQuery(['page' => $page - 1]) }}" class="rounded-md border px-3 py-2 dark:border-slate-700">Previous</a>@endif
                @if ($page < $pages)<a href="{{ request()->fullUrlWithQuery(['page' => $page + 1]) }}" class="rounded-md border px-3 py-2 dark:border-slate-700">Next</a>@endif
            </div>
        </div>
    @endif
@endsection
