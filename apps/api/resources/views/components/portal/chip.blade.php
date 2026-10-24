@props(['tone' => 'neutral'])
{{-- A status chip (06 "Status presentation"): the text label always shows; colour is never the only signal. --}}
@php
    $classes = match ($tone) {
        'info' => 'bg-accent-soft text-accent-ink',
        'warning' => 'bg-warning-soft text-warning',
        'danger' => 'bg-danger-soft text-danger',
        'success' => 'bg-success-soft text-success',
        'muted' => 'border border-field/40 text-muted',
        default => 'bg-hover text-ink',
    };
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2.5 py-0.5 text-sm font-medium $classes"]) }}>{{ $slot }}</span>
