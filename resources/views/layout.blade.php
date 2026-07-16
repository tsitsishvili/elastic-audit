@php
    $currentDashboard = trim($__env->yieldContent('dashboard', 'http'));
    $defaultTitle = $currentDashboard === 'activity' ? 'Activity Logs' : 'Third-Party HTTP Logs';
@endphp
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $defaultTitle)</title>
    <link rel="stylesheet" href="{{ asset('vendor/elastic-audit/' . $elasticAuditAssets['resources/css/elastic-audit.css']['file']) }}">
    {{-- Apply the saved/system theme before paint to avoid a flash of the wrong mode. --}}
    <script>
        (function () {
            const saved = localStorage.getItem('tphl_theme');
            const dark = saved ? saved === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>
</head>
<body class="min-h-full text-slate-800 antialiased dark:text-slate-200">
    @include('elastic-audit::partials.nav', ['current' => $currentDashboard])

    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:py-8">
        @isset($error)
            @if ($error)
                <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
                    <span class="font-semibold">Elasticsearch error:</span> {{ $error }}
                </div>
            @endif
        @endisset

        @yield('content')
    </main>

    <script type="module" src="{{ asset('vendor/elastic-audit/' . $elasticAuditAssets['resources/js/alpine.js']['file']) }}"></script>
    @stack('scripts')
</body>
</html>
