@extends('elastic-audit::dashboard.layout')

@section('title', 'Logs · Third-Party HTTP Logs')

@php
    $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
    $page       = min($page, $totalPages);
    $from       = $total > 0 ? ($page - 1) * $perPage + 1 : 0;
    $to         = min($page * $perPage, $total);

    $statusBadge = function (?string $class): string {
        return match ($class) {
            '2xx'   => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
            '3xx'   => 'bg-sky-100 text-sky-700 dark:bg-sky-950/50 dark:text-sky-300',
            '4xx'   => 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
            '5xx'   => 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300',
            default => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
        };
    };

    $hasFilters = collect($filters)->except([])->isNotEmpty();

    // Format a stored ISO timestamp in the app timezone; full ISO kept in the title.
    $fmtTs = function (?string $ts) use ($timezone): string {
        if (! $ts) {
            return '—';
        }
        try {
            return \Illuminate\Support\Carbon::parse($ts)->timezone($timezone)->format('M j, H:i:s');
        } catch (\Throwable) {
            return (string) $ts;
        }
    };

    // datetime-local inputs only accept `YYYY-MM-DDTHH:MM` (no seconds/zone), so normalize
    // any incoming value (e.g. an ISO `...Z` from a card/chart link) to the app timezone.
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

    // Build a list URL preserving filters + sort + page size, applying overrides
    // (a null override drops that key; page is always reset).
    $withParams = fn (array $overrides) => route(
        'http-logs.logs.index',
        array_filter(
            array_merge($filters, ['per_page' => $perPage, 'sort' => $sort, 'dir' => $dir], $overrides, ['page' => null]),
            fn ($v) => $v !== null && $v !== '',
        ),
        false,
    );

    $sortLink = fn (string $col) => $withParams(['sort' => $col, 'dir' => ($sort === $col && $dir === 'desc') ? 'asc' : 'desc']);
    $sortIcon = fn (string $col) => $sort === $col ? ($dir === 'desc' ? '↓' : '↑') : '';

    $filterLabels = [
        'provider'     => 'Provider',
        'event_type'   => 'Event type',
        'direction'    => 'Direction',
        'status_class' => 'Status',
        'success'      => 'Success',
        'timeout'      => 'Timeout',
        'entity_id'    => 'Entity ID',
        'request_id'   => 'Request ID',
        'external_id'  => 'External ID',
        'from'         => 'From',
        'to'           => 'To',
    ];
@endphp

@section('content')
    <div x-data="{ open: {{ $hasFilters ? 'true' : 'false' }} }">
        <div class="mb-5 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <h1 class="text-2xl font-semibold tracking-normal text-slate-950 dark:text-slate-50">HTTP log stream</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ number_format($total) }} {{ \Illuminate\Support\Str::plural('result', $total) }}
                    @if ($total > 0) · showing {{ $from }}–{{ $to }} @endif
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button"
                        x-data="{
                            on: localStorage.getItem('tphl_live_logs') === '1',
                            timer: null,
                            toggle() {
                                this.on = !this.on;
                                localStorage.setItem('tphl_live_logs', this.on ? '1' : '0');
                                if (this.on) { this.timer = setInterval(() => location.reload(), 10000); }
                                else if (this.timer) { clearInterval(this.timer); this.timer = null; }
                            },
                        }"
                        x-init="if (on) { timer = setInterval(() => location.reload(), 10000); }"
                        @click="toggle()"
                        :class="on
                            ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300'
                            : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700'"
                        :title="on ? 'Live: refreshing every 10s' : 'Auto-refresh the list every 10s'"
                        class="ea-focus inline-flex h-10 items-center gap-1.5 rounded-md border px-3 text-sm font-medium transition">
                    <span class="inline-flex h-2 w-2 rounded-full" :class="on ? 'bg-emerald-500 animate-pulse' : 'bg-slate-300 dark:bg-slate-600'"></span>
                    Live
                    <span x-show="on" x-cloak class="text-[11px] opacity-70">10s</span>
                </button>
                <button type="button"
                        x-data="{ copied: false }"
                        @click="navigator.clipboard.writeText(location.href).then(() => { copied = true; setTimeout(() => copied = false, 1500); })"
                        class="ea-focus inline-flex h-10 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                    <span x-show="!copied">Copy link</span>
                    <span x-show="copied" x-cloak class="text-emerald-600 dark:text-emerald-400">Copied ✓</span>
                </button>
                <button type="button" @click="open = !open"
                        class="ea-focus inline-flex h-10 items-center rounded-md border border-slate-300 bg-white px-3 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                    <span x-show="!open">Show filters</span>
                    <span x-show="open" x-cloak>Hide filters</span>
                    @if ($hasFilters)<span class="ml-1 rounded-full bg-indigo-100 px-1.5 text-xs text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300">{{ count($filters) }}</span>@endif
                </button>
            </div>
        </div>

        <form method="GET" action="{{ route('http-logs.logs.index', [], false) }}" x-show="open" x-cloak
              class="ea-panel mb-6 rounded-lg border p-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <label class="block">
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400">Provider</span>
                    <select name="provider" class="ea-focus mt-1 h-10 w-full rounded-md border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                        <option value="">All</option>
                        @foreach ($options['providers'] as $opt)
                            <option value="{{ $opt }}" @selected(($filters['provider'] ?? '') === $opt)>{{ $opt }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400">Event type</span>
                    <select name="event_type" class="ea-focus mt-1 h-10 w-full rounded-md border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                        <option value="">All</option>
                        @foreach ($options['event_types'] as $opt)
                            <option value="{{ $opt }}" @selected(($filters['event_type'] ?? '') === $opt)>{{ $opt }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400">Direction</span>
                    <select name="direction" class="ea-focus mt-1 h-10 w-full rounded-md border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                        <option value="">All</option>
                        @foreach (['outgoing', 'incoming'] as $opt)
                            <option value="{{ $opt }}" @selected(($filters['direction'] ?? '') === $opt)>{{ $opt }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400">Status class</span>
                    <select name="status_class" class="ea-focus mt-1 h-10 w-full rounded-md border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                        <option value="">All</option>
                        @foreach (['2xx', '3xx', '4xx', '5xx'] as $opt)
                            <option value="{{ $opt }}" @selected(($filters['status_class'] ?? '') === $opt)>{{ $opt }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400">Success</span>
                    <select name="success" class="ea-focus mt-1 h-10 w-full rounded-md border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                        <option value="">All</option>
                        <option value="true" @selected(($filters['success'] ?? '') === 'true')>Success</option>
                        <option value="false" @selected(($filters['success'] ?? '') === 'false')>Failure</option>
                    </select>
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400">Timeout</span>
                    <select name="timeout" class="ea-focus mt-1 h-10 w-full rounded-md border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                        <option value="">All</option>
                        <option value="1" @selected(filter_var($filters['timeout'] ?? '', FILTER_VALIDATE_BOOL))>Timeouts only</option>
                    </select>
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400">Entity ID</span>
                    <input type="text" name="entity_id" value="{{ $filters['entity_id'] ?? '' }}"
                           class="ea-focus mt-1 h-10 w-full rounded-md border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400">From</span>
                    <input type="datetime-local" name="from" value="{{ $fmtLocal($filters['from'] ?? null) }}"
                           class="ea-focus mt-1 h-10 w-full rounded-md border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400">To</span>
                    <input type="datetime-local" name="to" value="{{ $fmtLocal($filters['to'] ?? null) }}"
                           class="ea-focus mt-1 h-10 w-full rounded-md border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                </label>
            </div>

            {{-- Preserve sort + page size when filters are applied. --}}
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="dir" value="{{ $dir }}">
            <input type="hidden" name="per_page" value="{{ $perPage }}">

            <div class="mt-4 flex items-center gap-2">
                <button type="submit" class="ea-focus h-10 rounded-md bg-indigo-600 px-4 text-sm font-medium text-white transition hover:bg-indigo-700">
                    Apply filters
                </button>
                <a href="{{ route('http-logs.logs.index', [], false) }}"
                   class="ea-focus inline-flex h-10 items-center rounded-md border border-slate-300 bg-white px-4 text-sm font-medium text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800">
                    Reset
                </a>
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
            <a href="{{ route('http-logs.logs.index', [], false) }}"
               class="ea-focus rounded-sm text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400">Clear all</a>
        </div>
    @endif

    <div class="ea-panel overflow-hidden rounded-lg border">
        <div class="overflow-x-auto">
            <table class="min-w-[1120px] divide-y divide-slate-200 text-sm dark:divide-slate-700">
                <thead class="bg-slate-50/90 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-900/70 dark:text-slate-400">
                    <tr>
                        <th class="px-4 py-3 font-medium">
                            <a href="{{ $sortLink('time') }}" class="inline-flex items-center gap-1 hover:text-slate-700 dark:hover:text-slate-200 {{ $sort === 'time' ? 'text-indigo-600 dark:text-indigo-400' : '' }}">Time <span>{{ $sortIcon('time') }}</span></a>
                        </th>
                        <th class="px-4 py-3 font-medium">Provider</th>
                        <th class="px-4 py-3 font-medium">Event</th>
                        <th class="px-4 py-3 font-medium">Dir</th>
                        <th class="px-4 py-3 font-medium">Method</th>
                        <th class="px-4 py-3 font-medium">Path</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 text-right font-medium">
                            <a href="{{ $sortLink('latency') }}" class="inline-flex items-center gap-1 hover:text-slate-700 dark:hover:text-slate-200 {{ $sort === 'latency' ? 'text-indigo-600 dark:text-indigo-400' : '' }}">Latency <span>{{ $sortIcon('latency') }}</span></a>
                        </th>
                        <th class="px-4 py-3 font-medium">Entity</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                    @forelse ($logs as $log)
                        @php
                            $eventId   = data_get($log, 'event_id');
                            $statusCls = data_get($log, 'http.status_class');
                            $direction = data_get($log, 'direction');
                            $provider  = data_get($log, 'provider');
                            $success   = (bool) data_get($log, 'success');
                            $timedOut  = (bool) data_get($log, 'http.timed_out');
                            $rowUrl    = $eventId ? route('http-logs.logs.show', $eventId, false) : null;
                        @endphp
                        <tr class="cursor-pointer align-top transition hover:bg-indigo-50/50 dark:hover:bg-slate-900/60" @if ($rowUrl) onclick="window.location='{{ $rowUrl }}'" @endif>
                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-500 dark:text-slate-400" title="{{ data_get($log, '@timestamp') }}" data-ts="{{ data_get($log, '@timestamp') }}">
                                {{ $fmtTs(data_get($log, '@timestamp')) }}<span class="ml-1 text-slate-400 dark:text-slate-500" data-rel></span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium text-slate-700 dark:text-slate-200">
                                @if ($provider)
                                    <a href="{{ $withParams(['provider' => $provider]) }}" onclick="event.stopPropagation()" class="hover:text-indigo-600 hover:underline dark:hover:text-indigo-400">{{ $provider }}</a>
                                @else — @endif
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">
                                <span class="line-clamp-2">{{ data_get($log, 'event_type', '—') }}</span>
                            </td>
                            <td class="px-4 py-3">
                                @if ($direction)
                                    <a href="{{ $withParams(['direction' => $direction]) }}" onclick="event.stopPropagation()"
                                       class="rounded-full px-2 py-0.5 text-xs font-medium {{ $direction === 'incoming' ? 'bg-violet-100 text-violet-700 dark:bg-violet-900/50 dark:text-violet-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' }}">{{ $direction }}</a>
                                @else <span class="text-slate-400">—</span> @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <span class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-300">{{ data_get($log, 'http.method', '—') }}</span>
                            </td>
                            <td class="max-w-sm px-4 py-3 font-mono text-xs text-slate-500 dark:text-slate-400" title="{{ data_get($log, 'http.path') }}">
                                <span class="block truncate">{{ data_get($log, 'http.path', '—') }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                @if ($statusCls)
                                    <a href="{{ $withParams(['status_class' => $statusCls]) }}" onclick="event.stopPropagation()" class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $statusBadge($statusCls) }}">
                                        {{ data_get($log, 'http.status_code') ?? $statusCls }}
                                    </a>
                                @elseif ($timedOut)
                                    <a href="{{ $withParams(['timeout' => 1]) }}" onclick="event.stopPropagation()" class="rounded-full px-2 py-0.5 text-xs font-semibold bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-300">timeout</a>
                                @else <span class="text-slate-400">—</span> @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-mono text-xs text-slate-600 dark:text-slate-400">
                                {{ data_get($log, 'http.latency_ms') !== null ? number_format((int) data_get($log, 'http.latency_ms')) . ' ms' : '—' }}
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">
                                {{ data_get($log, 'entity.type', '—') }}@if (data_get($log, 'entity.id')) <span class="text-slate-400 dark:text-slate-500">#{{ data_get($log, 'entity.id') }}</span>@endif
                            </td>
                            <td class="px-4 py-3">
                                @if ($success)
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
                            <td colspan="10" class="px-4 py-12 text-center text-sm text-slate-400 dark:text-slate-500">No logs match the current filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @php
            $pageLink = fn (int $n) => route(
                'http-logs.logs.index',
                array_filter(array_merge($filters, ['per_page' => $perPage, 'sort' => $sort, 'dir' => $dir, 'page' => $n]), fn ($v) => $v !== null && $v !== ''),
                false,
            );
        @endphp
        <div class="flex flex-col gap-3 border-t border-slate-200 px-4 py-3 text-sm dark:border-slate-700 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-2 text-slate-500 dark:text-slate-400">
                <label for="per-page" class="sr-only">Rows per page</label>
                <select id="per-page" onchange="location.href=this.value"
                        class="ea-focus rounded-md border-slate-300 bg-white py-1 text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
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

@push('scripts')
    <style>[x-cloak]{display:none!important;}</style>
    <script>
        // Append a relative "Xm ago" hint to each timestamp cell.
        (function () {
            const rel = (iso) => {
                const diff = Date.now() - new Date(iso).getTime();
                if (isNaN(diff)) return '';
                const s = Math.round(diff / 1000);
                if (s < 60) return s + 's ago';
                const m = Math.round(s / 60);
                if (m < 60) return m + 'm ago';
                const h = Math.round(m / 60);
                if (h < 24) return h + 'h ago';
                return Math.round(h / 24) + 'd ago';
            };
            document.querySelectorAll('td[data-ts]').forEach((td) => {
                const span = td.querySelector('[data-rel]');
                if (span) span.textContent = '· ' + rel(td.dataset.ts);
            });
        })();
    </script>
@endpush
