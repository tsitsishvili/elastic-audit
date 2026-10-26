@extends('elastic-audit::layout')

@section('dashboard', 'metrics')
@section('title', 'Not found')

@section('content')
    <div class="ea-panel rounded-lg border p-6">
        <h1 class="text-base font-semibold text-slate-900 dark:text-slate-100">Nothing found</h1>
        <p class="mt-2 break-all text-sm text-slate-600 dark:text-slate-300">No trace or request found for "{{ $id }}".</p>
        <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">
            Try a 32-character trace ID, a <span class="font-mono">traceparent</span> header, a request ID or an event ID.
        </p>
    </div>
@endsection
