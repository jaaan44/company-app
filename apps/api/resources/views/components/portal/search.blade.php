@props(['id', 'label', 'placeholder'])
{{-- A live search box bound to the component's `search`; the component defines clearSearch(). --}}
<form role="search" wire:submit.prevent class="mb-4 flex gap-2" aria-label="{{ $label }}">
    <label for="{{ $id }}" class="sr-only">{{ $placeholder }}</label>
    <input id="{{ $id }}" type="search" wire:model.live.debounce.400ms="search" placeholder="{{ $placeholder }}" autocomplete="off"
           class="min-h-11 w-full rounded-md border border-line bg-raised px-3">
    @if (trim($this->search) !== '')
        <button type="button" wire:click="clearSearch" class="inline-flex min-h-11 shrink-0 items-center rounded-md px-3 hover:bg-hover">Clear search</button>
    @endif
</form>
