@props(['message'])
<div role="alert" class="rounded-lg border border-line bg-raised p-6">
    <p>{{ $message }}</p>
    <button type="button" wire:click="$refresh" class="mt-4 inline-flex min-h-11 items-center rounded-md bg-accent px-4 font-medium text-on-accent hover:opacity-90">Try again</button>
</div>
