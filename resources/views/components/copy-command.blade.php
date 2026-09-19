@props(['command'])
<div class="loom-code" x-data="loomCopy(@js($command))">
    <code>{{ $command }}</code>
    <button type="button" class="loom-btn" x-on:click="copy()" x-text="label">Copy</button>
</div>
