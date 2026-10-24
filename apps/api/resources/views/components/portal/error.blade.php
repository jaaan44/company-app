@props(['message'])
<div role="alert" class="card p-5">
    <p>{{ $message }}</p>
    <button type="button" wire:click="$refresh" class="btn btn-primary mt-4">Try again</button>
</div>
