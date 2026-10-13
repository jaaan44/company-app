@props(['meta'])
{{-- Previous / Next for a paginated API list; the component defines goToPage(int). --}}
@if ($meta && ($meta['last_page'] ?? 1) > 1)
    <nav aria-label="Pages" class="mt-4 flex items-center justify-between gap-2">
        <button type="button" wire:click="goToPage({{ $meta['current_page'] - 1 }})" @disabled($meta['current_page'] <= 1)
                class="inline-flex min-h-11 items-center rounded-md px-3 hover:bg-hover disabled:opacity-40">Previous</button>
        <span class="text-sm text-muted">Page {{ $meta['current_page'] }} of {{ $meta['last_page'] }}</span>
        <button type="button" wire:click="goToPage({{ $meta['current_page'] + 1 }})" @disabled($meta['current_page'] >= $meta['last_page'])
                class="inline-flex min-h-11 items-center rounded-md px-3 hover:bg-hover disabled:opacity-40">Next</button>
    </nav>
@endif
