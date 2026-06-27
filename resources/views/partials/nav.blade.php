{{-- Shared dashboard header. Expects $current: 'http' | 'activity'. --}}
@php
    $dashboards = [
        'http' => [
            'label'   => 'HTTP Logs',
            'enabled' => (bool) config('http_logs.dashboard.enabled', false),
            'route'   => 'http-logs.overview',
            'tabs'    => [
                ['label' => 'Overview', 'route' => 'http-logs.overview',   'match' => 'http-logs.overview'],
                ['label' => 'Logs',     'route' => 'http-logs.logs.index', 'match' => 'http-logs.logs.*'],
            ],
        ],
        'activity' => [
            'label'   => 'Activity Logs',
            'enabled' => (bool) config('activity_logs.dashboard.enabled', false),
            'route'   => 'activity-logs.overview',
            'tabs'    => [
                ['label' => 'Overview',      'route' => 'activity-logs.overview',   'match' => 'activity-logs.overview'],
                ['label' => 'Activity Logs', 'route' => 'activity-logs.logs.index', 'match' => 'activity-logs.logs.*'],
            ],
        ],
    ];
    $tabs = $dashboards[$current]['tabs'];
@endphp

<header class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/95 text-slate-900 backdrop-blur dark:border-slate-800 dark:bg-slate-950/95 dark:text-slate-100">
    <div class="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-3 sm:px-6 md:flex-row md:items-center md:justify-between">
        <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-center">
            <div class="flex items-center gap-2">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-indigo-600 text-sm font-bold text-white shadow-sm">EA</span>
                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold leading-5">Elastic Audit</div>
                    <div class="text-xs leading-4 text-slate-500 dark:text-slate-400">{{ $dashboards[$current]['label'] }}</div>
                </div>
            </div>

            {{-- Dashboard switcher: current is always shown; the other only when enabled. --}}
            <nav class="flex w-full items-center gap-1 rounded-lg bg-slate-100 p-1 text-sm dark:bg-slate-900 sm:w-auto">
                @foreach ($dashboards as $key => $dash)
                    @continue($key !== $current && ! $dash['enabled'])
                    @php $isCurrent = $key === $current; @endphp
                    <a href="{{ route($dash['route'], [], false) }}"
                       class="ea-focus flex-1 whitespace-nowrap rounded-md px-3 py-1.5 text-center font-semibold transition sm:flex-none {{ $isCurrent ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-white hover:text-slate-950 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white' }}">
                        {{ $dash['label'] }}
                    </a>
                @endforeach
            </nav>

            {{-- Current dashboard's page tabs. --}}
            <nav class="flex w-full items-center gap-1 text-sm sm:w-auto">
                @foreach ($tabs as $tab)
                    @php $active = request()->routeIs($tab['match']); @endphp
                    <a href="{{ route($tab['route'], [], false) }}"
                       class="ea-focus flex-1 whitespace-nowrap rounded-md px-3 py-1.5 text-center font-medium transition sm:flex-none {{ $active ? 'bg-slate-900 text-white dark:bg-slate-800' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-slate-900 dark:hover:text-white' }}">
                        {{ $tab['label'] }}
                    </a>
                @endforeach
            </nav>
        </div>

        {{-- Theme toggle (writes the shared tphl_theme key). --}}
        <button type="button"
                x-data="{ dark: document.documentElement.classList.contains('dark') }"
                @click="dark = !dark; document.documentElement.classList.toggle('dark', dark); localStorage.setItem('tphl_theme', dark ? 'dark' : 'light')"
                :title="dark ? 'Switch to light mode' : 'Switch to dark mode'"
                class="ea-focus inline-flex h-9 w-9 items-center justify-center self-end rounded-md text-slate-500 transition hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-slate-900 dark:hover:text-white md:self-auto">
            <span x-cloak x-text="dark ? '☀' : '☾'"></span>
        </button>
    </div>
</header>
