@props(['meta'])
{{-- Previous / Next for a paginated API list; the component defines goToPage(int). --}}
@if ($meta && ($meta['last_page'] ?? 1) > 1)
    <nav aria-label="Pages" class="mt-4 flex items-center justify-between gap-2">
        <button type="button" wire:click="goToPage({{ $meta['current_page'] - 1 }})" @disabled($meta['current_page'] <= 1)
                class="btn btn-plain btn-compact">Previous</button>
        <span class="text-sm text-muted">Page {{ $meta['current_page'] }} of {{ $meta['last_page'] }}</span>
        <button type="button" wire:click="goToPage({{ $meta['current_page'] + 1 }})" @disabled($meta['current_page'] >= $meta['last_page'])
                class="btn btn-plain btn-compact">Next</button>
    </nav>
@endif
