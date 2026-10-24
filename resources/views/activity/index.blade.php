@extends('elastic-audit::layout')

@section('dashboard', 'activity')

@section('title', 'Activity Logs')

@php
    $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
    $page       = min($page, $totalPages);
    $from       = $total > 0 ? ($page - 1) * $perPage + 1 : 0;
    $to         = min($page * $perPage, $total);

    $fmtTs = function (?string $ts) use ($timezone): string {
        if (! $ts) return '—';
        try { return \Illuminate\Support\Carbon::parse($ts)->timezone($timezone)->format('M j, H:i:s'); }
        catch (\Throwable) { return (string) $ts; }
    };

    // datetime-local inputs only accept `YYYY-MM-DDTHH:MM`, so normalize ISO values from overview links.
    $fmtLocal = function (?string $ts) use ($timezone): string {
        if (! $ts) return '';
        try { return \Illuminate\Support\Carbon::parse($ts)->timezone($timezone)->format('Y-m-d\TH:i'); }
        catch (\Throwable) { return ''; }
    };

    // List URL preserving filters + sort + page size; a null override drops that key, page is always reset.
    $withParams = fn (array $overrides) => route(
        'activity-logs.logs.index',
        array_filter(
            array_merge($filters, ['per_page' => $perPage, 'sort' => $sort, 'dir' => $dir], $overrides, ['page' => null]),
            fn ($v) => $v !== null && $v !== '',
        ),
        false,
    );
    $pageLink = fn (int $n) => route(
        'activity-logs.logs.index',
        array_filter(array_merge($filters, ['per_page' => $perPage, 'sort' => $sort, 'dir' => $dir, 'page' => $n]), fn ($v) => $v !== null && $v !== ''),
        false,
    );

    $sortLink = fn (string $col) => $withParams(['sort' => $col, 'dir' => ($sort === $col && $dir === 'desc') ? 'asc' : 'desc']);
    $sortIcon = fn (string $col) => $sort === $col ? ($dir === 'desc' ? '↓' : '↑') : '';

    // Table header columns, keyed by the controller's sort value.
    $columns = [
        'time'   => 'Time',
        'source' => 'Source',
        'actor'  => 'Actor',
        'action' => 'Action',
        'entity' => 'Entity',
        'result' => 'Result',
    ];

    $hasFilters = ! empty($filters);

    $filterLabels = [
        'action'         => 'Action',
        'actor_type'     => 'Actor type',
        'actor_id'       => 'Actor ID',
        'entity_type'    => 'Entity type',
        'entity_id'      => 'Entity ID',
        'request_id'     => 'Request ID',
        'trace_id'       => 'Trace ID',
        'service'        => 'Application',
        'execution_type' => 'Execution type',
        'execution_name' => 'Execution name',
        'success'        => 'Result',
        'from'           => 'From',
        'to'             => 'To',
    ];

    $field = 'ea-focus mt-1 h-10 w-full rounded-md border-slate-300 bg-white text-sm shadow-xs focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100';
    $button = 'ea-focus inline-flex h-10 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800';
@endphp

@section('content')
    <div x-data="{ open: {{ $hasFilters ? 'true' : 'false' }} }">
        <div class="mb-5 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-normal text-slate-950 dark:text-slate-50">Activity log stream</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ number_format($total) }} {{ \Illuminate\Support\Str::plural('result', $total) }}
                    @if ($total > 0) · showing {{ $from }}–{{ $to }} @endif
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                {{-- Live: auto-refresh the list to surface new activity as it lands. --}}
                <button type="button"
                        x-data="{
                            on: localStorage.getItem('tphl_live_activity') === '1',
                            timer: null,
                            toggle() {
                                this.on = !this.on;
                                localStorage.setItem('tphl_live_activity', this.on ? '1' : '0');
                                if (this.on) { this.timer = setInterval(() => location.reload(), 10000); }
                                else if (this.timer) { clearInterval(this.timer); this.timer = null; }
                            },
                        }"
                        x-init="if (on) { timer = setInterval(() => location.reload(), 10000); }"
                        @click="toggle()"
                        :class="on
                            ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'
                            : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800'"
                        :title="on ? 'Live: refreshing every 10s' : 'Auto-refresh the list every 10s'"
                        class="ea-focus inline-flex h-10 items-center gap-1.5 rounded-md border px-3 text-sm font-medium transition">
                    <span class="inline-flex h-2 w-2 rounded-full" :class="on ? 'bg-emerald-500 animate-pulse' : 'bg-slate-300 dark:bg-slate-600'"></span>
                    Live
                    <span x-show="on" x-cloak class="text-[11px] opacity-70">10s</span>
                </button>
                <button type="button"
                        x-data="{ copied: false }"
                        @click="navigator.clipboard.writeText(location.href).then(() => { copied = true; setTimeout(() => copied = false, 1500); })"
                        class="{{ $button }}">
                    <span x-show="!copied">Copy link</span>
                    <span x-show="copied" x-cloak class="text-emerald-600 dark:text-emerald-400">Copied ✓</span>
                </button>
                <button type="button" @click="open = !open" class="{{ $button }}">
                    <span x-show="!open">Show filters</span>
                    <span x-show="open" x-cloak>Hide filters</span>
                    @if ($hasFilters)<span class="ml-1 rounded-full bg-indigo-100 px-1.5 text-xs text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300">{{ count($filters) }}</span>@endif
                </button>
            </div>
        </div>

        <form method="GET" action="{{ route('activity-logs.logs.index', [], false) }}" x-show="open" x-cloak
              class="ea-panel mb-6 rounded-lg border p-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    'action'         => ['Action', $options['actions']],
                    'actor_type'     => ['Actor type', $options['actor_types']],
                    'service'        => ['Application', $options['services']],
                    'execution_type' => ['Execution type', $options['executions']],
                ] as $name => [$label, $values])
                    <label class="block">
                        <span class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ $label }}</span>
                        <select name="{{ $name }}" class="{{ $field }}">
                            <option value="">All</option>
                            @foreach ($values as $opt)
                                <option value="{{ $opt }}" @selected(($filters[$name] ?? '') === $opt)>{{ $opt }}</option>
                            @endforeach
                        </select>
                    </label>
                @endforeach

                <label class="block">
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400">Execution name</span>
                    <input type="text" name="execution_name" value="{{ $filters['execution_name'] ?? '' }}"
                           placeholder="Route, job, or command" class="{{ $field }} font-mono">
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400">Result</span>
                    <select name="success" class="{{ $field }}">
                        <option value="">All</option>
                        <option value="1" @selected(($filters['success'] ?? '') === '1')>Success</option>
                        <option value="0" @selected(($filters['success'] ?? '') === '0')>Failure</option>
                    </select>
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400">Entity ID</span>
                    <input type="text" name="entity_id" value="{{ $filters['entity_id'] ?? '' }}" class="{{ $field }}">
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400">Trace ID</span>
                    <input type="text" name="trace_id" value="{{ $filters['trace_id'] ?? '' }}" class="{{ $field }} font-mono">
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400">From</span>
                    <input type="datetime-local" name="from" value="{{ $fmtLocal($filters['from'] ?? null) }}" class="{{ $field }}">
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400">To</span>
                    <input type="datetime-local" name="to" value="{{ $fmtLocal($filters['to'] ?? null) }}" class="{{ $field }}">
                </label>
            </div>

            {{-- Keep filters without a field (set from links) plus sort and page size when applying. --}}
            @foreach (['actor_id', 'entity_type', 'request_id'] as $key)
                @isset($filters[$key])<input type="hidden" name="{{ $key }}" value="{{ $filters[$key] }}">@endisset
            @endforeach
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="dir" value="{{ $dir }}">
            <input type="hidden" name="per_page" value="{{ $perPage }}">

            <div class="mt-4 flex items-center gap-2">
                <button type="submit" class="ea-focus h-10 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white transition hover:bg-indigo-700">
                    Apply filters
                </button>
                <a href="{{ route('activity-logs.logs.index', [], false) }}" class="{{ $button }}">Reset</a>
            </div>
        </form>
    </div>

    @if ($hasFilters)
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <span class="text-xs font-medium uppercase tracking-wide text-slate-400 dark:text-slate-500">Filters</span>
            @foreach ($filters as $key => $value)
                <a href="{{ $withParams([$key => null]) }}"
                   class="ea-focus group inline-flex items-center gap-1 rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs text-slate-600 transition hover:border-red-300 hover:text-red-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:border-red-500 dark:hover:text-red-400">
                    <span class="font-medium">{{ $filterLabels[$key] ?? $key }}:</span>
                    <span class="font-mono">{{ \Illuminate\Support\Str::limit((string) $value, 24) }}</span>
                    <span class="text-slate-400 group-hover:text-red-500">✕</span>
                </a>
            @endforeach
            <a href="{{ route('activity-logs.logs.index', [], false) }}"
               class="ea-focus rounded-xs text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400">Clear all</a>
        </div>
    @endif

    <div class="ea-panel overflow-hidden rounded-lg border">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700">
                <thead class="bg-slate-50/90 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-900/70 dark:text-slate-400">
                    <tr>
                        @foreach ($columns as $col => $label)
                            <th class="px-4 py-3 font-medium"
                                @if ($sort === $col) aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif>
                                <a href="{{ $sortLink($col) }}" class="inline-flex items-center gap-1 hover:text-slate-700 dark:hover:text-slate-200 {{ $sort === $col ? 'text-indigo-600 dark:text-indigo-400' : '' }}">{{ $label }} <span>{{ $sortIcon($col) }}</span></a>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                    @forelse ($logs as $log)
                        @php
                            $ts            = $log['@timestamp'] ?? null;
                            $service       = data_get($log, 'service.name');
                            $executionName = data_get($log, 'execution.name');
                        @endphp
                        <tr class="cursor-pointer align-top transition hover:bg-indigo-50/50 dark:hover:bg-slate-900/60"
                            onclick="window.location='{{ route('activity-logs.logs.show', $log['event_id'] ?? $log['_id'], false) }}'">
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-500 dark:text-slate-400" title="{{ $ts }}">
                                {{ $fmtTs($ts) }}
                            </td>
                            <td class="px-4 py-3 text-xs">
                                @if ($service)
                                    <a href="{{ $withParams(['service' => $service]) }}" onclick="event.stopPropagation()" class="font-medium hover:text-indigo-600 hover:underline dark:hover:text-indigo-400">{{ $service }}</a>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                                <div class="font-mono text-slate-400">
                                    {{ data_get($log, 'execution.type') ?: '—' }}
                                    @if ($executionName)
                                        · <a href="{{ $withParams(['execution_name' => $executionName]) }}" onclick="event.stopPropagation()" class="hover:text-indigo-600 hover:underline dark:hover:text-indigo-400">{{ $executionName }}</a>
                                    @endif
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-slate-700 dark:text-slate-200">
                                {{ $log['actor']['type'] ?? '—' }}
                                @if (! empty($log['actor']['id']))
                                    <span class="ml-1 text-xs font-normal text-slate-400 dark:text-slate-500">#{{ $log['actor']['id'] }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300">{{ $log['action'] ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-xs text-slate-500 dark:text-slate-400">
                                {{ $log['entity']['type'] ?? '—' }}
                                <span class="text-slate-400 dark:text-slate-500">#{{ $log['entity']['id'] ?? '—' }}</span>
                            </td>
                            <td class="px-4 py-3">
                                @if ($log['success'] ?? true)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300" title="success">
                                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>ok
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950/40 dark:text-red-300" title="failure">
                                        <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>fail
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-12 text-center text-sm text-slate-400 dark:text-slate-500">No activity logs match the current filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex flex-col gap-3 border-t border-slate-200 px-4 py-3 text-sm dark:border-slate-700 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-2 text-slate-500 dark:text-slate-400">
                <label for="per-page" class="sr-only">Rows per page</label>
                <select id="per-page" onchange="location.href=this.value"
                        class="ea-focus rounded-md border-slate-300 bg-white py-1 text-xs shadow-xs focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                    @foreach ($perPageOptions as $opt)
                        <option value="{{ $withParams(['per_page' => $opt]) }}" @selected($opt === $perPage)>{{ $opt }} / page</option>
                    @endforeach
                </select>
                <span>Page {{ $page }} of {{ $totalPages }}</span>
            </div>
            <div class="flex gap-2">
                @if ($page > 1)
                    <a href="{{ $pageLink($page - 1) }}"
                       class="ea-focus rounded-md border border-slate-300 bg-white px-3 py-1.5 font-medium text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800">← Prev</a>
                @endif
                @if ($page < $totalPages)
                    <a href="{{ $pageLink($page + 1) }}"
                       class="ea-focus rounded-md border border-slate-300 bg-white px-3 py-1.5 font-medium text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800">Next →</a>
                @endif
            </div>
        </div>
    </div>
@endsection
