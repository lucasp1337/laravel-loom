<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Loom' }} · Loom</title>
    <link rel="stylesheet" href="{{ \Lucasp\Loom\Ui\Asset::CSS->url() }}">
    @livewireStyles
</head>
<body>
<div class="loom-app" x-data="loomShell" data-go='@json($go, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)' x-on:keydown.window="onKey($event)">
    @include('loom::partials.sidebar')

    <div class="loom-body">
        @include('loom::partials.topbar')

        @if ($staleBy !== null)
            <div class="loom-stale" role="status" x-data="loomCopy('php artisan loom:scan')">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8.22 1.754a.25.25 0 0 0-.44 0L1.698 13.132a.25.25 0 0 0 .22.368h12.164a.25.25 0 0 0 .22-.368ZM6.457 1.047c.659-1.234 2.427-1.234 3.086 0l6.082 11.378A1.75 1.75 0 0 1 14.082 15H1.918a1.75 1.75 0 0 1-1.543-2.575ZM8 5a.75.75 0 0 1 .75.75v2.5a.75.75 0 0 1-1.5 0v-2.5A.75.75 0 0 1 8 5Zm1 6a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/></svg>
                <span>Index is {{ $staleBy }} older than your last commit to app/. Re-run <code>php artisan loom:scan</code> to refresh.</span>
                <button type="button" class="loom-btn" x-on:click="copy()" x-text="label">Copy</button>
            </div>
        @endif

        <main class="loom-main">{{ $slot }}</main>
    </div>

    <div class="loom-scrim" x-show="palette" x-cloak x-ref="palette" role="dialog" aria-modal="true" aria-label="Search"
         x-on:click.self="closePalette()"
         x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)" x-on:keydown.enter.prevent="choose()">
        <div class="loom-palette">
            @livewire('loom.palette')
        </div>
    </div>
</div>

@stack('scripts')
<script src="{{ \Lucasp\Loom\Ui\Asset::JS->url() }}"></script>
@livewireScripts
</body>
</html>
