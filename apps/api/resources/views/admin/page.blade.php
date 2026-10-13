{{-- One wrapper for Admin Backoffice pages (Phase 35B): the layout plus one Livewire component. --}}
<x-layouts.admin :title="$title">
    @livewire($livewire, $params ?? [])
</x-layouts.admin>
