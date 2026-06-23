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

<header class="bg-slate-900 text-slate-100 dark:border-b dark:border-slate-700">
    <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6">
        <div class="flex items-center gap-4">
            {{-- Dashboard switcher: current is always shown; the other only when enabled. --}}
            <nav class="flex items-center gap-1 rounded-lg bg-slate-800/60 p-1 text-sm">
                @foreach ($dashboards as $key => $dash)
                    @continue($key !== $current && ! $dash['enabled'])
                    @php $isCurrent = $key === $current; @endphp
                    <a href="{{ route($dash['route'], [], false) }}"
                       class="rounded-md px-3 py-1.5 font-semibold transition {{ $isCurrent ? 'bg-indigo-500 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' }}">
                        {{ $dash['label'] }}
                    </a>
                @endforeach
            </nav>

            {{-- Current dashboard's page tabs. --}}
            <nav class="flex items-center gap-1 text-sm">
                @foreach ($tabs as $tab)
                    @php $active = request()->routeIs($tab['match']); @endphp
                    <a href="{{ route($tab['route'], [], false) }}"
                       class="rounded-md px-3 py-1.5 font-medium transition {{ $active ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white' }}">
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
                class="ml-1 rounded-md px-2 py-1.5 text-slate-300 transition hover:bg-slate-800 hover:text-white">
            <span x-cloak x-text="dark ? '☀' : '☾'"></span>
        </button>
    </div>
</header>
