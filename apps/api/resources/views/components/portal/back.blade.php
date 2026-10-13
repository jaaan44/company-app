@props(['href'])
{{-- The app bar's back arrow (Phase 34F, F-2): pushed into the layout's app bar, as on mobile. --}}
@push('appbar-back')
    <a href="{{ $href }}" class="-ml-2 inline-flex size-11 shrink-0 items-center justify-center rounded-full hover:bg-hover" aria-label="Back to {{ $slot }}">
        <x-portal.icon name="back" />
    </a>
@endpush
