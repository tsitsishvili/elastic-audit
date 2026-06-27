<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Third-Party HTTP Logs')</title>
    {{-- Apply the saved/system theme before paint to avoid a flash of the wrong mode. --}}
    <script>
        (function () {
            const saved = localStorage.getItem('tphl_theme');
            const dark = saved ? saved === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>
    {{-- Tailwind Play CDN: version-pinned for reproducibility. No SRI: the Play CDN sends no CORS header, so integrity+crossorigin would block it. --}}
    <script src="https://cdn.tailwindcss.com/3.4.16"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"
            integrity="sha384-X9kJyAubVxnP0hcA+AMMs21U445qsnqhnUF8EBlEpP3a42Kh/JwWjlv2ZcvGfphb"
            crossorigin="anonymous"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        mono: ['ui-monospace', 'SFMono-Regular', 'Menlo', 'monospace'],
                    },
                },
            },
        };
    </script>
    <style>
        [x-cloak]{display:none!important;}
        :root {
            color-scheme: light;
            --ea-bg: #f6f7fb;
            --ea-panel: #ffffff;
            --ea-panel-soft: #f8fafc;
            --ea-border: #d9e1ec;
            --ea-text: #0f172a;
            --ea-muted: #64748b;
            --ea-muted-soft: #94a3b8;
            --ea-accent: #4f46e5;
        }
        .dark {
            color-scheme: dark;
            --ea-bg: #101318;
            --ea-panel: #171b24;
            --ea-panel-soft: #202636;
            --ea-border: #303849;
            --ea-text: #f8fafc;
            --ea-muted: #a5b4c7;
            --ea-muted-soft: #768297;
            --ea-accent: #818cf8;
        }
        body {
            background:
                linear-gradient(180deg, rgba(79, 70, 229, 0.08), transparent 280px),
                var(--ea-bg);
        }
        .ea-panel {
            background: var(--ea-panel);
            border-color: var(--ea-border);
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        }
        .ea-focus:focus-visible {
            outline: 2px solid var(--ea-accent);
            outline-offset: 2px;
        }
    </style>
</head>
<body class="min-h-full text-slate-800 antialiased dark:text-slate-200">
    @include('elastic-audit::partials.nav', ['current' => 'http'])

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

    @stack('scripts')
</body>
</html>
