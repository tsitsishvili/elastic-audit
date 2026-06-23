<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Activity Logs')</title>
    <script>
        (function () {
            const saved = localStorage.getItem('tphl_theme');
            const dark = saved ? saved === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: { fontFamily: { mono: ['ui-monospace', 'SFMono-Regular', 'Menlo', 'monospace'] } } },
        };
    </script>
    <style>[x-cloak]{display:none!important;}</style>
</head>
<body class="h-full bg-slate-100 text-slate-800 antialiased dark:bg-slate-900 dark:text-slate-200">
    @include('elastic-audit::partials.nav', ['current' => 'activity'])

    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
        @yield('content')
    </main>
</body>
</html>
