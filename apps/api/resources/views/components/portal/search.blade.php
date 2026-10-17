@props(['id', 'label', 'placeholder'])
{{-- A live search box bound to the component's `search`; the component defines clearSearch(). --}}
<form role="search" wire:submit.prevent class="mb-4 flex gap-2" aria-label="{{ $label }}">
    <label for="{{ $id }}" class="sr-only">{{ $placeholder }}</label>
    <input id="{{ $id }}" type="search" wire:model.live.debounce.400ms="search" placeholder="{{ $placeholder }}" autocomplete="off"
           class="field w-full">
    @if (trim($this->search) !== '')
        <button type="button" wire:click="clearSearch" class="btn btn-plain btn-compact shrink-0">Clear search</button>
    @endif
</form>
