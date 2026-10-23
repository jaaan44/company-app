@props(['href', 'tone', 'icon', 'title', 'label', 'wide' => false])
{{--
    A Home "Needs attention" card (mobile redesign): a pastel card per kind
    of thing (tasks rose, messages blue, notifications green) with its icon,
    title and figures, opening its screen. `wide` lays the icon beside the
    text while the card spans the row (below lg); in the three-column row it
    stacks like the others.
--}}
@php
    [$card, $tint, $ink] = match ($tone) {
        'rose' => ['bg-card-rose', 'bg-tint-rose', 'text-icon-rose'],
        'green' => ['bg-card-green', 'bg-tint-green', 'text-icon-green'],
        default => ['bg-card-blue', 'bg-tint-blue', 'text-icon-blue'],
    };
@endphp
<a href="{{ $href }}" class="block rounded-xl relative h-full border border-line/40 {{ $card }} p-4 pr-10 shadow-card hover:border-field {{ $attributes->get('class') }}" aria-label="{{ $label }}">
    <span class="flex {{ $wide ? 'items-center gap-4 lg:flex-col lg:items-start lg:gap-3' : 'flex-col gap-3' }}">
        <span class="inline-flex size-12 shrink-0 items-center justify-center rounded-full {{ $tint }} {{ $ink }}"><x-portal.icon :name="$icon" /></span>
        <span class="block min-w-0">
            <span class="block font-semibold">{{ $title }}</span>
            {{ $slot }}
        </span>
    </span>
    <x-portal.icon name="chevron" class="absolute top-1/2 right-3 size-6 -translate-y-1/2 text-muted" />
</a>
