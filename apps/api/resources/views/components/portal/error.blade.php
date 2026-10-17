@props(['message'])
<div role="alert" class="rounded-xl border border-line bg-raised p-5">
    <p>{{ $message }}</p>
    <button type="button" wire:click="$refresh" class="btn btn-primary mt-4">Try again</button>
</div>
