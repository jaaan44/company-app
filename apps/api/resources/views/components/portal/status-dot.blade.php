@props(['status'])
{{-- A person's operational status (Phase 37D): a coloured dot and its word, so colour is never the only signal. --}}
@php
    use App\Support\Portal\Format;

    [$dot, $text] = match (Format::operationalTone($status)) {
        'green' => ['bg-icon-green', 'text-icon-green'],
        'amber' => ['bg-icon-amber', 'text-icon-amber'],
        'rose' => ['bg-icon-rose', 'text-icon-rose'],
        'violet' => ['bg-icon-violet', 'text-icon-violet'],
        default => ['bg-icon-slate', 'text-muted'],
    };
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 text-sm font-medium $text"]) }}><span class="size-2 shrink-0 rounded-full {{ $dot }}" aria-hidden="true"></span>{{ Format::operationalStatus($status) }}</span>
