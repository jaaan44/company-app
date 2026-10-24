@props(['count', 'label', 'tone' => 'blue'])
{{-- A count in a tinted chip (Phase 37D, the reference's "3 due today"): the number above its words, read as one phrase. --}}
@php
    [$bg, $figure] = match ($tone) {
        'amber' => ['bg-card-amber', 'text-icon-amber'],
        'rose' => ['bg-card-rose', 'text-icon-rose'],
        'green' => ['bg-card-green', 'text-icon-green'],
        default => ['bg-card-blue', 'text-icon-blue'],
    };
@endphp
<span {{ $attributes->merge(['class' => "flex min-w-0 flex-1 flex-col rounded-xl px-3 py-2 $bg"]) }}>
    <span class="text-2xl leading-tight font-bold tabular-nums {{ $figure }}">{{ $count }}</span>
    <span class="text-sm {{ $figure }}">{{ $label }}</span>
</span>
