@extends('loom::layouts.bare')

@section('title', ($unreadable ?? false) ? 'Index could not be loaded' : 'No index found')

@section('content')
    <div class="loom-state__icon">
        <svg width="20" height="20" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M2 1.75C2 .784 2.784 0 3.75 0h6.586c.464 0 .909.184 1.237.513l2.914 2.914c.329.328.513.773.513 1.237v9.586A1.75 1.75 0 0 1 13.25 16h-9.5A1.75 1.75 0 0 1 2 14.25Zm1.75-.25a.25.25 0 0 0-.25.25v12.5c0 .138.112.25.25.25h9.5a.25.25 0 0 0 .25-.25V6h-2.75A1.75 1.75 0 0 1 9 4.25V1.5Zm6.75.062V4.25c0 .138.112.25.25.25h2.688l-.011-.013-2.914-2.914-.013-.011Z"/></svg>
    </div>
    @if ($unreadable ?? false)
        <h1>Index could not be loaded</h1>
        <div class="loom-alert">{{ $message }}</div>
        <p>The snapshot exists but is not a valid Loom index. Re-run the scan to write a fresh one.</p>
    @else
        <h1>No index found</h1>
        <p>Loom reads <code>{{ $path }}</code>. Nothing is there yet, run a scan and reload this page.</p>
    @endif
    <x-loom::copy-command command="php artisan loom:scan" />
    <small>The scan takes a few seconds on a mid-sized app and writes a single deterministic JSON file.</small>
@endsection
