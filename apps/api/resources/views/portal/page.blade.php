{{-- One wrapper for portal pages from Phase 34B on: the layout plus one Livewire component. --}}
<x-layouts.portal :title="$title">
    @livewire($livewire, $params ?? [])
</x-layouts.portal>
