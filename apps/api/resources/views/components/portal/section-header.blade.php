@props(['id', 'href' => null, 'action' => 'View all', 'actionLabel' => null])
{{-- A section's heading (37C; every screen with sections since 37D), with an optional "View all" link; screen readers hear what it views. --}}
<div {{ $attributes->merge(['class' => 'mb-3 flex items-center justify-between gap-3']) }}>
    <h2 id="{{ $id }}" class="text-lg font-bold tracking-tight">{{ $slot }}</h2>
    @if ($href)
        <a href="{{ $href }}" class="-mr-2 inline-flex min-h-11 shrink-0 items-center rounded-full px-2 text-sm font-semibold text-accent-ink hover:bg-hover">{{ $action }}@if ($actionLabel)<span class="sr-only"> {{ $actionLabel }}</span>@endif</a>
    @endif
</div>
