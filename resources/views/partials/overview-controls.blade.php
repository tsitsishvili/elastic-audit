@php
    $idPrefix = $idPrefix ?? 'overview';
    $liveSeconds = $liveSeconds ?? 30;
    $liveIntervalMs = $liveSeconds * 1000;

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
@endphp

<div class="ea-panel mt-4 flex flex-wrap items-end justify-between gap-4 rounded-lg border p-4">
    <form method="GET" action="{{ $action }}"
          class="flex flex-wrap items-end gap-4" x-data="{ range: @js($range) }">
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400" for="{{ $idPrefix }}-range-select">Range</label>
            <select id="{{ $idPrefix }}-range-select" name="range" x-model="range"
                    @change="$el.value !== 'custom' && $el.form.submit()"
                    class="ea-focus h-10 rounded-md border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                @foreach ($ranges as $key => $meta)
                    <option value="{{ $key }}" @selected($range === $key)>{{ $meta['label'] }}</option>
                @endforeach
                <option value="custom" @selected($range === 'custom')>Custom range</option>
            </select>
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400" for="{{ $idPrefix }}-interval-select">Interval</label>
            <select id="{{ $idPrefix }}-interval-select" name="interval" onchange="this.form.submit()"
                    class="ea-focus h-10 rounded-md border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                @foreach ($intervals as $key => $label)
                    <option value="{{ $key }}" @selected($interval === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <template x-if="range === 'custom'">
            <div class="flex flex-wrap items-end gap-4">
                <div class="flex flex-col gap-1">
                    <label class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400" for="{{ $idPrefix }}-from-input">From</label>
                    <input id="{{ $idPrefix }}-from-input" type="datetime-local" name="from" value="{{ $fmtLocal(request('from')) }}"
                           class="ea-focus h-10 rounded-md border-slate-300 bg-white text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400" for="{{ $idPrefix }}-to-input">To</label>
                    <input id="{{ $idPrefix }}-to-input" type="datetime-local" name="to" value="{{ $fmtLocal(request('to')) }}"
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
            on: localStorage.getItem(@js($liveKey)) === '1',
            timer: null,
            toggle() {
                localStorage.setItem(@js($liveKey), this.on ? '1' : '0');
                if (this.on) { this.timer = setInterval(() => location.reload(), {{ $liveIntervalMs }}); }
                else if (this.timer) { clearInterval(this.timer); this.timer = null; }
            },
         }"
         x-init="if (on) { timer = setInterval(() => location.reload(), {{ $liveIntervalMs }}); }">
        <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-600 dark:text-slate-300">
            <input type="checkbox" x-model="on" @change="toggle()"
                   class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 dark:border-slate-600 dark:bg-slate-900">
            <span class="flex items-center gap-1">
                <span class="inline-flex h-2 w-2 rounded-full" :class="on ? 'bg-emerald-500 animate-pulse' : 'bg-slate-300'"></span>
                Live
            </span>
        </label>
        <span x-show="on" x-cloak class="text-[11px] text-slate-400 dark:text-slate-500">every {{ $liveSeconds }}s</span>
    </div>
</div>
