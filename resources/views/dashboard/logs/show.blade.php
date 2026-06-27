@extends('elastic-audit::dashboard.layout')

@section('title', 'Log detail · Third-Party HTTP Logs')

@php
    $pretty = function ($value): string {
        if ($value === null || $value === [] || $value === '') {
            return '';
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }
        if (is_string($value)) {
            return $value;
        }
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    };

    $statusBadge = function (?string $class): string {
        return match ($class) {
            '2xx'   => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
            '3xx'   => 'bg-sky-100 text-sky-700 dark:bg-sky-950/50 dark:text-sky-300',
            '4xx'   => 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
            '5xx'   => 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300',
            default => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
        };
    };

    $fmtTs = function (?string $ts) use ($timezone): string {
        if (! $ts) {
            return '—';
        }
        try {
            return \Illuminate\Support\Carbon::parse($ts)->timezone($timezone)->format('M j, Y H:i:s');
        } catch (\Throwable) {
            return $ts;
        }
    };
@endphp

@section('content')
    <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('http-logs.logs.index', [], false) }}"
       class="ea-focus mb-4 inline-flex h-9 items-center gap-1 rounded-md text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">← Back to logs</a>

    @if (! $log)
        <div class="ea-panel rounded-lg border p-12 text-center text-sm text-slate-400 dark:text-slate-500">
            Log not found.
        </div>
    @else
        @php
            $meta = [
                'Provider'       => data_get($log, 'provider'),
                'Event type'     => data_get($log, 'event_type'),
                'Direction'      => data_get($log, 'direction'),
                'Method'         => data_get($log, 'http.method'),
                'Host'           => data_get($log, 'http.host'),
                'Path'           => data_get($log, 'http.path'),
                'Entity'         => trim((string) data_get($log, 'entity.type') . ' ' . (data_get($log, 'entity.id') ? '#' . data_get($log, 'entity.id') : '')),
                'External ID'    => data_get($log, 'external.id'),
                'User ID'        => data_get($log, 'user_id'),
                'Attempt'        => data_get($log, 'attempt'),
                'Retention days' => data_get($log, 'retention_days'),
                'Request ID'     => data_get($log, 'request_id'),
                'Event ID'       => data_get($log, 'event_id'),
            ];
            $success = (bool) data_get($log, 'success');
        @endphp

        <div class="ea-panel mb-6 rounded-lg border p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ data_get($log, 'provider') ?: 'Unknown provider' }}</p>
                    <h1 class="mt-1 break-words text-2xl font-semibold tracking-normal text-slate-950 dark:text-slate-50">{{ data_get($log, 'event_type') ?: 'HTTP log detail' }}</h1>
                    <div class="mt-3 flex min-w-0 flex-wrap items-center gap-2 text-sm">
                        @if (data_get($log, 'http.method'))
                            <span class="rounded bg-slate-100 px-2 py-1 font-mono text-xs font-semibold text-slate-700 dark:bg-slate-900 dark:text-slate-300">{{ data_get($log, 'http.method') }}</span>
                        @endif
                        <span class="min-w-0 break-all font-mono text-xs text-slate-500 dark:text-slate-400">{{ data_get($log, 'http.path') ?: data_get($log, 'http.host') ?: '—' }}</span>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusBadge(data_get($log, 'http.status_class')) }}">
                        {{ data_get($log, 'http.status_code') ?? data_get($log, 'http.status_class') ?? '—' }}
                    </span>
                    @if ($success)
                        <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">success</span>
                    @else
                        <span class="rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700 dark:bg-red-950/50 dark:text-red-300">failure</span>
                    @endif
                    @if (data_get($log, 'http.latency_ms') !== null)
                        <span class="rounded-full bg-indigo-100 px-2.5 py-1 text-xs font-semibold text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300">{{ number_format((int) data_get($log, 'http.latency_ms')) }} ms</span>
                    @endif
                    <span class="font-mono text-xs text-slate-400" title="{{ data_get($log, '@timestamp') }}">{{ $fmtTs(data_get($log, '@timestamp')) }}</span>
                </div>
            </div>
        </div>

        <div class="ea-panel mb-6 rounded-lg border p-5">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($meta as $label => $value)
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ $label }}</dt>
                        <dd class="mt-0.5 break-all font-mono text-sm text-slate-700 dark:text-slate-300">{{ ($value === null || $value === '') ? '—' : $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        @if (data_get($log, 'error.class') || data_get($log, 'error.message'))
            <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-5 shadow-sm dark:border-red-900 dark:bg-red-950">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-red-800 dark:text-red-300">
                    Error
                    @if (data_get($log, 'http.timed_out'))
                        <span class="rounded bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/50 dark:text-amber-300">Timeout</span>
                    @endif
                </h2>
                <p class="mt-1 font-mono text-xs text-red-700 dark:text-red-400">{{ data_get($log, 'error.class') }}</p>
                <pre class="mt-2 overflow-x-auto whitespace-pre-wrap text-xs text-red-700 dark:text-red-400">{{ data_get($log, 'error.message') }}</pre>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            @foreach (['request' => 'Request', 'response' => 'Response'] as $key => $title)
                @php
                    $headers   = data_get($log, "$key.headers");
                    $preview   = data_get($log, "$key.body_preview");
                    $hash      = data_get($log, "$key.body_hash");
                    $truncated = (bool) data_get($log, "$key.body_truncated");
                @endphp
                <div class="ea-panel rounded-lg border">
                    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3 dark:border-slate-700">
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ $title }}</h2>
                        @if ($truncated)
                            <span class="rounded bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">truncated</span>
                        @endif
                    </div>
                    <div class="space-y-4 p-5">
                        <div>
                            <h3 class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-400">Headers</h3>
                            @if ($pretty($headers) !== '')
                                <div x-data="{ copied: false }" class="overflow-hidden rounded-md bg-slate-950 ring-1 ring-slate-800">
                                    <div class="flex items-center justify-end border-b border-slate-800 px-2 py-1">
                                        <button type="button" @click="navigator.clipboard.writeText($refs.headers.textContent).then(() => { copied = true; setTimeout(() => copied = false, 1200); })" class="ea-focus rounded px-2 py-1 text-[11px] font-medium text-slate-300 hover:bg-slate-800">
                                            <span x-show="!copied">Copy</span><span x-show="copied" x-cloak>Copied</span>
                                        </button>
                                    </div>
                                    <pre x-ref="headers" class="max-h-96 overflow-auto p-3 font-mono text-xs leading-relaxed text-slate-100">{{ $pretty($headers) }}</pre>
                                </div>
                            @else
                                <p class="text-xs text-slate-400">—</p>
                            @endif
                        </div>
                        <div>
                            <h3 class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-400">Body preview</h3>
                            @if ($pretty($preview) !== '')
                                <div x-data="{ copied: false }" class="overflow-hidden rounded-md bg-slate-950 ring-1 ring-slate-800">
                                    <div class="flex items-center justify-end border-b border-slate-800 px-2 py-1">
                                        <button type="button" @click="navigator.clipboard.writeText($refs.body.textContent).then(() => { copied = true; setTimeout(() => copied = false, 1200); })" class="ea-focus rounded px-2 py-1 text-[11px] font-medium text-slate-300 hover:bg-slate-800">
                                            <span x-show="!copied">Copy</span><span x-show="copied" x-cloak>Copied</span>
                                        </button>
                                    </div>
                                    <pre x-ref="body" class="max-h-96 overflow-auto p-3 font-mono text-xs leading-relaxed text-slate-100">{{ $pretty($preview) }}</pre>
                                </div>
                            @else
                                <p class="text-xs text-slate-400">—</p>
                            @endif
                        </div>
                        @if ($hash)
                            <div>
                                <h3 class="mb-1 text-xs font-medium uppercase tracking-wide text-slate-400">Body hash</h3>
                                <p class="break-all font-mono text-xs text-slate-500">{{ $hash }}</p>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
