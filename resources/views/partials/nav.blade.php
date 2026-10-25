{{-- Shared dashboard sidebar. Expects $current: 'http' | 'activity' | 'metrics'. --}}
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
        'metrics' => [
            'label'   => 'Performance',
            'enabled' => (bool) config('elastic_audit_metrics.dashboard.enabled', false),
            'route'   => 'elastic-audit-metrics.overview',
            'tabs'    => [
                ['label' => 'Overview',     'route' => 'elastic-audit-metrics.overview',     'match' => 'elastic-audit-metrics.overview'],
                ['label' => 'Transactions', 'route' => 'elastic-audit-metrics.transactions', 'match' => 'elastic-audit-metrics.transactions'],
                ['label' => 'Functions',    'route' => 'elastic-audit-metrics.functions',    'match' => 'elastic-audit-metrics.functions'],
            ],
        ],
    ];
@endphp

<aside x-data="{ open: false }"
       class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/95 text-slate-900 backdrop-blur-sm dark:border-slate-800 dark:bg-slate-950/95 dark:text-slate-100 lg:fixed lg:inset-y-0 lg:left-0 lg:flex lg:w-60 lg:flex-col lg:border-b-0 lg:border-r">
    <div class="flex items-center justify-between gap-2 px-4 py-3 lg:py-5">
        <div class="flex min-w-0 items-center gap-2">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-indigo-600 text-sm font-bold text-white shadow-xs">EA</span>
            <div class="min-w-0">
                <div class="truncate text-sm font-semibold leading-5">Elastic Audit</div>
                <div class="text-xs leading-4 text-slate-500 dark:text-slate-400">{{ $dashboards[$current]['label'] }}</div>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-1">
            {{-- Theme toggle (writes the shared tphl_theme key). --}}
            <button type="button"
                    x-data="{ dark: document.documentElement.classList.contains('dark') }"
                    @click="dark = !dark; document.documentElement.classList.toggle('dark', dark); localStorage.setItem('tphl_theme', dark ? 'dark' : 'light')"
                    :title="dark ? 'Switch to light mode' : 'Switch to dark mode'"
                    :aria-label="dark ? 'Switch to light mode' : 'Switch to dark mode'"
                    class="ea-focus inline-flex h-9 w-9 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-slate-900 dark:hover:text-white">
                <span x-cloak x-text="dark ? '☀' : '☾'"></span>
            </button>

            {{-- Menu toggle: the sidebar collapses into this top bar below lg. --}}
            <button type="button"
                    @click="open = !open"
                    :aria-expanded="open.toString()"
                    aria-controls="ea-sidebar-nav"
                    aria-label="Toggle navigation"
                    class="ea-focus inline-flex h-9 w-9 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-slate-900 dark:hover:text-white lg:hidden">
                ☰
            </button>
        </div>
    </div>

    {{-- Every enabled dashboard with its pages; the current one is always shown. --}}
    <nav id="ea-sidebar-nav" aria-label="Dashboards"
         :class="{ 'max-lg:flex': open }"
         class="hidden flex-col gap-5 px-3 pb-4 text-sm lg:flex lg:flex-1 lg:overflow-y-auto">
        @foreach ($dashboards as $key => $dash)
            @continue($key !== $current && ! $dash['enabled'])
            <div>
                <div class="px-3 pb-1 text-xs font-semibold uppercase tracking-wide {{ $key === $current ? 'text-indigo-600 dark:text-indigo-400' : 'text-slate-400 dark:text-slate-500' }}">
                    {{ $dash['label'] }}
                </div>
                @foreach ($dash['tabs'] as $tab)
                    @php $active = request()->routeIs($tab['match']); @endphp
                    <a href="{{ route($tab['route'], [], false) }}"
                       @if ($active) aria-current="page" @endif
                       class="ea-focus block rounded-md px-3 py-1.5 font-medium transition {{ $active ? 'bg-slate-900 text-white dark:bg-slate-800' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-slate-900 dark:hover:text-white' }}">
                        {{ $tab['label'] }}
                    </a>
                @endforeach
            </div>
        @endforeach
    </nav>
</aside>
