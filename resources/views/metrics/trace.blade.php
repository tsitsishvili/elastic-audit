@extends('elastic-audit::layout')

@section('dashboard', 'metrics')
@section('title', 'Performance Trace')

@section('content')
    @php
        $rows     = $timeline['rows'];
        $counts   = $timeline['counts'];
        $profiled = collect($timeline['profiled']);
        $root     = collect($rows)->first(fn ($row) => $row['kind'] === 'transaction')['doc'] ?? null;

        // Jobs fold their spans away until opened, so flatten the tree once and
        // let Alpine toggle visibility by row key.
        $flat = [];
        foreach ($rows as $i => $row) {
            $flat[] = ['row' => $row, 'key' => "r{$i}", 'parent' => null];
            foreach ($row['children'] as $j => $child) {
                $flat[] = ['row' => $child, 'key' => "r{$i}c{$j}", 'parent' => "r{$i}"];
            }
        }

        // Bars sit on the shared timeline; rows without an offset (audit-only
        // traces) have no position, so they get no bar.
        $span = max(0.001, (float) collect($flat)
            ->filter(fn ($f) => $f['row']['offset_ms'] !== null)
            ->map(fn ($f) => $f['row']['offset_ms'] + (float) $f['row']['duration_ms'])
            ->max());

        $rootDuration = (float) data_get($root, 'duration_ms', 0);
        $fmtTs = function (?string $ts) use ($timezone): string {
            if (! is_string($ts) || $ts === '') { return '—'; }
            try { return \Illuminate\Support\Carbon::parse($ts)->timezone($timezone)->format('M j, H:i:s.v'); }
            catch (\Throwable) { return $ts; }
        };
        $fmtAt = fn (?float $at): string => $at === null ? '—' : \Illuminate\Support\Carbon::createFromTimestampMs($at, $timezone)->format('H:i:s.v');

        // Stored previews are often JSON strings; pretty-print those, show anything else as is.
        $pretty = function ($value): string {
            if ($value === null || $value === [] || $value === '') { return ''; }
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $value   = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : $value;
            }

            return is_string($value) ? $value : (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        };
        $inline = fn ($value): string => is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $sourceLabels = ['metrics' => 'Performance data', 'http' => 'HTTP logs', 'activity' => 'Activity events'];
        $capLabels    = ['http' => 'HTTP logs', 'activity' => 'activity events'];

        /*
         * Each kind of work reads differently, so each gets its own colour and
         * its own row shape. Class strings are written out in full because
         * Tailwind scans this file for literals.
         */
        $styles = [
            'http.server' => ['label' => 'request', 'chip' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300', 'bar' => 'bg-indigo-600'],
            'queue.job' => ['label' => 'job', 'chip' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300', 'bar' => 'bg-indigo-600'],
            'console.command' => ['label' => 'command', 'chip' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300', 'bar' => 'bg-indigo-600'],
            'scheduled.task' => ['label' => 'task', 'chip' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300', 'bar' => 'bg-indigo-600'],
            'app.function' => ['label' => 'function', 'chip' => 'bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300', 'bar' => 'bg-violet-500'],
            'db.query' => ['label' => 'query', 'chip' => 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300', 'bar' => 'bg-sky-500'],
            'http.client' => ['label' => 'outbound', 'chip' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300', 'bar' => 'bg-emerald-500'],
            'cache.operation' => ['label' => 'cache', 'chip' => 'bg-teal-100 text-teal-700 dark:bg-teal-950 dark:text-teal-300', 'bar' => 'bg-teal-500'],
            'redis.command' => ['label' => 'redis', 'chip' => 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300', 'bar' => 'bg-rose-500'],
            'queue.publish' => ['label' => 'dispatch', 'chip' => 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300', 'bar' => 'bg-amber-500'],
            'mail.send' => ['label' => 'mail', 'chip' => 'bg-pink-100 text-pink-700 dark:bg-pink-950 dark:text-pink-300', 'bar' => 'bg-pink-500'],
            'notification.send' => ['label' => 'notify', 'chip' => 'bg-pink-100 text-pink-700 dark:bg-pink-950 dark:text-pink-300', 'bar' => 'bg-pink-500'],
        ];
        $styles['http'] = ['label' => 'http', 'chip' => 'bg-cyan-100 text-cyan-700 dark:bg-cyan-950 dark:text-cyan-300', 'bar' => 'bg-cyan-500'];
        $styles['activity'] = ['label' => 'activity', 'chip' => 'bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-300', 'bar' => 'bg-orange-500'];
        $fallback = ['label' => 'span', 'chip' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300', 'bar' => 'bg-slate-500'];
        $styleFor = fn (?string $type): array => $styles[$type] ?? $fallback;
        $profiledMax = (float) ($profiled->map(fn ($i) => (float) data_get($i, 'duration_ms', 0))->max() ?: 1);
    @endphp

    <a href="{{ route('elastic-audit-metrics.transactions', [], false) }}" class="ea-focus text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">← Transactions</a>

    <div class="my-5 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <h1 class="truncate text-2xl font-semibold tracking-normal text-slate-950 dark:text-slate-50">{{ data_get($root, 'name', 'Trace') }}</h1>
            <div class="mt-1 font-mono text-xs text-slate-500">{{ $traceId }}</div>
        </div>
        @if ($root)
            <div class="shrink-0 text-sm text-slate-500 dark:text-slate-400">
                {{ $fmtTs(data_get($root, '@timestamp')) }} ·
                <span class="font-mono">{{ number_format($rootDuration, 2) }} ms</span> ·
                {{ number_format($counts['span']) }} {{ \Illuminate\Support\Str::plural('span', $counts['span']) }}
            </div>
        @endif
    </div>

    @if ($auditHidden)
        <div class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-200">Some audit data in this trace is hidden because you don't have access to its dashboard.</div>
    @endif
    @foreach ($sourceErrors as $source => $message)
        <div class="mb-3 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/50 dark:text-red-200"><span class="font-semibold">{{ $sourceLabels[$source] }}:</span> {{ $message }}</div>
    @endforeach
    @foreach ($truncated as $source => $total)
        <div class="mb-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-200">Showing the first {{ $auditCap }} {{ $capLabels[$source] }} of {{ number_format($total) }}.</div>
    @endforeach

    <div x-data="{ show: { span: true, http: true, activity: true, job: true }, open: {} }">
        <div class="mb-3 flex flex-wrap items-center gap-2 text-xs">
            @foreach (['span' => 'span', 'http' => 'http', 'activity' => 'activity', 'job' => 'job'] as $kind => $chipLabel)
                @if ($counts[$kind] > 0)
                    @php $style = $styleFor($kind === 'span' ? null : ($kind === 'job' ? 'queue.job' : $kind)); @endphp
                    <button type="button" @click="show.{{ $kind }} = !show.{{ $kind }}" :aria-pressed="show.{{ $kind }}"
                            :class="show.{{ $kind }} ? '{{ $style['chip'] }}' : 'bg-transparent text-slate-400 line-through dark:text-slate-500'"
                            class="ea-focus rounded-full border border-slate-200 px-2.5 py-1 font-semibold dark:border-slate-700">
                        {{ $chipLabel }} <span class="font-mono">{{ number_format($counts[$kind]) }}</span>
                    </button>
                @endif
            @endforeach
        </div>

        <div class="ea-panel overflow-hidden rounded-lg border">
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($flat as $entry)
                    @php
                        $row      = $entry['row'];
                        $doc      = $row['doc'];
                        $key      = $entry['key'];
                        $kind     = $row['kind'];
                        $type     = $row['type'];
                        $style    = $styleFor($type);
                        $duration = $row['duration_ms'];
                        $offsetMs = $row['offset_ms'];
                        $isRoot   = $kind === 'transaction';
                        $failed   = data_get($doc, 'outcome') === 'failure';
                        $expand   = in_array($kind, ['http', 'activity', 'job'], true);
                        $eventId  = data_get($doc, 'event_id');
                        $offset   = $offsetMs === null ? null : max(0, min(100, ($offsetMs / $span) * 100));
                        $width    = $offset === null ? null : max(0.5, min(100 - $offset, ((float) $duration / $span) * 100));
                        $share    = $rootDuration > 0 && $duration !== null ? ($duration / $rootDuration) * 100 : null;
                        $visible  = $isRoot ? null : ($entry['parent'] === null ? "show.{$kind}" : "show.job && show.{$kind} && open.{$entry['parent']}");

                        // A query's content is its SQL; a function's content is its
                        // name. Everything else gets a compact metadata line.
                        $detail = match ($type) {
                            'http.client' => trim(data_get($doc, 'http.method', '').' '.data_get($doc, 'http.host', '').data_get($doc, 'http.path', '')),
                            'cache.operation' => implode(' · ', array_filter([data_get($doc, 'cache.operation'), data_get($doc, 'cache.store'), data_get($doc, 'cache.result')])),
                            'redis.command' => implode(' · ', array_filter([data_get($doc, 'redis.command'), data_get($doc, 'redis.connection')])),
                            'queue.publish', 'queue.job' => implode(' · ', array_filter([data_get($doc, 'queue.connection'), data_get($doc, 'queue.name')])),
                            'mail.send' => data_get($doc, 'mail.transport'),
                            'notification.send' => data_get($doc, 'notification.channel'),
                            default => null,
                        };

                        $label = match ($kind) {
                            'http' => implode(' · ', array_filter([
                                data_get($doc, 'provider'),
                                data_get($doc, 'event_type'),
                                trim(strtoupper((string) data_get($doc, 'http.method')).' '.data_get($doc, 'http.url')),
                                data_get($doc, 'http.status_code'),
                            ])),
                            'activity' => implode(' · ', array_filter([
                                data_get($doc, 'action'),
                                trim(data_get($doc, 'entity.type', '').(data_get($doc, 'entity.id') !== null ? ' #'.data_get($doc, 'entity.id') : '')),
                            ])),
                            default => $type === 'db.query' ? (data_get($doc, 'db.statement') ?: data_get($doc, 'name')) : data_get($doc, 'name'),
                        };
                    @endphp
                    <div @if ($visible) x-show="{{ $visible }}" x-cloak @endif>
                        <div class="px-4 py-3 {{ $entry['parent'] ? 'pl-9' : '' }} {{ $expand ? 'cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/40' : '' }}"
                             @if ($expand) role="button" tabindex="0" :aria-expanded="!!open.{{ $key }}" @click="open.{{ $key }} = !open.{{ $key }}" @keydown.enter.prevent="open.{{ $key }} = !open.{{ $key }}" @keydown.space.prevent="open.{{ $key }} = !open.{{ $key }}" @endif>
                            <div class="mb-2 flex items-baseline justify-between gap-4 text-sm">
                                <div class="flex min-w-0 items-baseline gap-2">
                                    @if ($expand)
                                        <span class="shrink-0 text-[10px] text-slate-400 transition" :class="open.{{ $key }} && 'rotate-90'" aria-hidden="true">▶</span>
                                    @endif
                                    <span class="shrink-0 rounded-sm px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $style['chip'] }}">{{ $style['label'] }}</span>

                                    @if ($type === 'db.query')
                                        {{-- The statement is the content, so it leads. --}}
                                        <span class="min-w-0 truncate font-mono text-xs text-slate-700 dark:text-slate-200" title="{{ $label }}">{{ $label }}</span>
                                    @elseif ($type === 'app.function')
                                        {{-- A measured label, not code: read it as prose. --}}
                                        <span class="min-w-0 truncate font-medium text-violet-700 dark:text-violet-300" title="{{ $label }}">{{ $label }}</span>
                                    @else
                                        <span class="min-w-0 truncate font-medium text-slate-700 dark:text-slate-200" title="{{ $label }}">{{ $label }}</span>
                                    @endif

                                    @if ($failed)
                                        <span class="shrink-0 rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-semibold text-red-700 dark:bg-red-950 dark:text-red-300">failed</span>
                                    @endif
                                </div>
                                <span class="shrink-0 font-mono text-xs" title="{{ $fmtAt($row['at']) }}">
                                    @if ($offsetMs !== null)
                                        {{ $offsetMs < 0 ? '−' : '+' }}{{ number_format(abs($offsetMs), 1) }} ms
                                    @else
                                        {{ $fmtAt($row['at']) }}
                                    @endif
                                    @if ($duration !== null)
                                        · {{ number_format($duration, 2) }} ms
                                    @endif
                                    @if ($share !== null && ! $isRoot && $kind === 'span')
                                        <span class="ml-1 text-slate-400">{{ number_format($share, 1) }}%</span>
                                    @endif
                                </span>
                            </div>

                            @if ($offset !== null)
                                <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                    <div class="h-full rounded-full {{ $failed ? 'bg-red-500' : $style['bar'] }}"
                                         style="margin-left: {{ round($offset, 3) }}%; width: {{ round($width, 3) }}%"></div>
                                </div>
                            @endif

                            @if ($type === 'db.query')
                                @php
                                    // The connection is usually named after its driver;
                                    // only say it twice when they actually differ.
                                    $dbMeta = array_values(array_unique(array_filter([
                                        data_get($doc, 'db.operation'),
                                        data_get($doc, 'db.connection'),
                                        data_get($doc, 'db.driver'),
                                    ])));
                                @endphp
                                <p class="mt-1.5 text-[11px] text-slate-400 dark:text-slate-500">{{ implode(' · ', $dbMeta) }}</p>
                            @elseif ($detail)
                                <p class="mt-1.5 truncate font-mono text-[11px] text-slate-400 dark:text-slate-500" title="{{ $detail }}">{{ $detail }}@if ($type === 'http.client' && data_get($doc, 'http.status_code')) <span class="font-semibold">{{ data_get($doc, 'http.status_code') }}</span>@endif</p>
                            @endif
                        </div>

                        @if ($kind === 'http' || $kind === 'activity')
                            <div x-show="open.{{ $key }}" x-cloak class="border-t border-slate-100 bg-slate-50/60 px-4 py-3 text-xs dark:border-slate-800 dark:bg-slate-900/40">
                                @if ($kind === 'http')
                                    <dl class="grid grid-cols-[6rem_1fr] gap-x-3 gap-y-1">
                                        @foreach (['Direction' => data_get($doc, 'direction'), 'Method' => data_get($doc, 'http.method'), 'URL' => data_get($doc, 'http.url'), 'Status' => data_get($doc, 'http.status_code'), 'Latency' => data_get($doc, 'http.latency_ms') !== null ? number_format((float) data_get($doc, 'http.latency_ms')).' ms' : null] as $term => $value)
                                            @if ($value !== null && $value !== '')
                                                <dt class="text-slate-400">{{ $term }}</dt>
                                                <dd class="break-all font-mono text-slate-700 dark:text-slate-200">{{ $value }}</dd>
                                            @endif
                                        @endforeach
                                    </dl>
                                    @foreach (['request' => 'Request body', 'response' => 'Response body'] as $part => $title)
                                        @if ($pretty(data_get($doc, "{$part}.body_preview")) !== '')
                                            <h3 class="mb-1 mt-3 font-medium uppercase tracking-wide text-slate-400">{{ $title }}</h3>
                                            <pre class="max-h-64 overflow-auto rounded-md bg-slate-900 p-3 font-mono leading-relaxed text-slate-100">{{ $pretty(data_get($doc, "{$part}.body_preview")) }}</pre>
                                        @endif
                                    @endforeach
                                @else
                                    <dl class="grid grid-cols-[6rem_1fr] gap-x-3 gap-y-1">
                                        <dt class="text-slate-400">Actor</dt>
                                        <dd class="font-mono text-slate-700 dark:text-slate-200">{{ trim(data_get($doc, 'actor.type', '').(data_get($doc, 'actor.id') !== null ? ' #'.data_get($doc, 'actor.id') : '')) ?: '—' }}</dd>
                                        <dt class="text-slate-400">Entity</dt>
                                        <dd class="font-mono text-slate-700 dark:text-slate-200">{{ trim(data_get($doc, 'entity.type', '').(data_get($doc, 'entity.id') !== null ? ' #'.data_get($doc, 'entity.id') : '')) ?: '—' }}</dd>
                                    </dl>
                                    @if (is_array(data_get($doc, 'changes')) && data_get($doc, 'changes') !== [])
                                        <h3 class="mb-1 mt-3 font-medium uppercase tracking-wide text-slate-400">Changes</h3>
                                        <div class="space-y-1 font-mono">
                                            @foreach (data_get($doc, 'changes') as $field => $diff)
                                                @php $isDiff = is_array($diff) && array_key_exists('old', $diff) && array_key_exists('new', $diff); @endphp
                                                <div class="break-all">
                                                    <span class="font-medium text-slate-700 dark:text-slate-200">{{ $field }}</span>
                                                    <span class="text-slate-500 dark:text-slate-400">{{ $isDiff ? $inline($diff['old']) : '' }}</span>
                                                    <span class="text-slate-400">→</span>
                                                    <span class="text-emerald-700 dark:text-emerald-400">{{ $inline($isDiff ? $diff['new'] : $diff) }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                @endif
                                @if ($eventId)
                                    <a href="{{ route($kind === 'http' ? 'http-logs.logs.show' : 'activity-logs.logs.show', $eventId, false) }}" class="ea-focus mt-3 inline-flex font-medium text-indigo-600 hover:underline dark:text-indigo-400">Open full log →</a>
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="px-5 py-12 text-center text-sm text-slate-500 dark:text-slate-400">No timeline events recorded for this trace.</p>
                @endforelse
            </div>
        </div>
    </div>

    @if ($profiled->isNotEmpty())
        <div class="ea-panel mt-4 rounded-lg border p-4">
            <div class="flex items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Application functions <span class="text-xs font-normal text-slate-400">by time accounted for</span></h2>
                <span class="text-xs text-slate-400 dark:text-slate-500">from the profiler</span>
            </div>
            <div class="mt-4 space-y-3">
                @foreach ($profiled as $item)
                    @php $ms = (float) data_get($item, 'duration_ms', 0); @endphp
                    <div>
                        <div class="mb-1 flex items-baseline justify-between gap-4 text-xs">
                            <span class="min-w-0 truncate font-mono text-slate-600 dark:text-slate-300" title="{{ data_get($item, 'name') }}">{{ data_get($item, 'name') }}</span>
                            <span class="shrink-0 text-slate-400 dark:text-slate-500">
                                <span class="font-semibold text-slate-600 dark:text-slate-300">{{ number_format($ms, 1) }} ms</span>
                                @if ($rootDuration > 0) · {{ number_format(($ms / $rootDuration) * 100, 1) }}% @endif
                            </span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700">
                            <div class="h-full bg-fuchsia-500" style="width: {{ round($ms / $profiledMax * 100, 1) }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="mt-3 text-[11px] text-slate-400 dark:text-slate-500">Inclusive time, so a caller's total contains its callees. Sampled durations are estimates, which is why these are ranked rather than placed on the timeline above.</p>
        </div>
    @endif

    @if (data_get($root, 'transaction.profile_id'))
        <a href="{{ route('elastic-audit-metrics.profiles.show', data_get($root, 'transaction.profile_id'), false) }}" class="ea-focus mt-5 inline-flex rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700">Open sampled profile</a>
    @endif
@endsection
