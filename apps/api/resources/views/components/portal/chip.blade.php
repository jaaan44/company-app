@props(['tone' => 'neutral'])
{{-- A status chip (06 "Status presentation"): the text label always shows; colour is never the only signal. --}}
@php
    $classes = match ($tone) {
        'info' => 'bg-accent-soft text-accent-ink',
        'danger' => 'bg-danger-soft text-danger',
        'success' => 'bg-success-soft text-success',
        'muted' => 'border border-line text-muted',
        default => 'bg-hover text-ink',
    };
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-md px-2 py-0.5 text-sm font-medium $classes"]) }}>{{ $slot }}</span>
