@php
    $gridClass = $gridClass ?? 'grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-6';
    $cardClass = $cardClass ?? 'min-h-[116px] p-4';
@endphp

<div class="{{ $gridClass }}">
    @foreach ($cards as $card)
        <a href="{{ $card['link'] }}"
           class="ea-focus ea-panel group rounded-lg border transition hover:-translate-y-0.5 hover:border-indigo-300 hover:shadow-md dark:hover:border-indigo-500 {{ $cardClass }}">
            <div class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ $card['label'] }}</div>
            <div class="mt-2 text-2xl font-semibold {{ $card['accent'] }}">{{ $card['value'] }}</div>
            <div class="mt-1 text-xs text-slate-400 group-hover:text-indigo-500 dark:text-slate-500">{{ $card['sub'] }} &rarr;</div>
        </a>
    @endforeach
</div>
