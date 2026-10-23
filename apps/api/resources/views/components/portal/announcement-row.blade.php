@props(['href', 'title', 'date' => null])
{{-- One announcement in Home's Latest announcements (mobile redesign): its icon, title and publication date, opening it. --}}
<a href="{{ $href }}" class="flex min-h-16 items-center gap-4 px-4 py-3 hover:bg-hover">
    <span class="inline-flex size-12 shrink-0 items-center justify-center rounded-full bg-tint-violet text-icon-violet"><x-portal.icon name="announcements" /></span>
    <span class="block min-w-0 flex-1">
        <span class="block font-semibold [overflow-wrap:anywhere]">{{ $title }}</span>
        @if (filled($date))
            <span class="block text-sm text-muted">{{ $date }}</span>
        @endif
    </span>
    <x-portal.icon name="chevron" class="size-6 text-muted" />
</a>
