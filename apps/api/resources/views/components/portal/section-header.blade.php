@props(['id', 'href' => null, 'action' => 'View all', 'actionLabel' => null])
{{-- A Home section's heading (mobile redesign), with an optional "View all" link; screen readers hear what it views. --}}
<div class="mb-3 flex items-center justify-between gap-3">
    <h2 id="{{ $id }}" class="text-xl font-bold tracking-tight">{{ $slot }}</h2>
    @if ($href)
        <a href="{{ $href }}" class="-mr-2 inline-flex min-h-11 shrink-0 items-center gap-1 rounded-full px-2 font-medium text-icon-blue hover:bg-hover">{{ $action }}@if ($actionLabel)<span class="sr-only"> {{ $actionLabel }}</span>@endif <x-portal.icon name="chevron" class="size-5" /></a>
    @endif
</div>
