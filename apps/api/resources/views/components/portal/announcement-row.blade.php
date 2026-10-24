@props(['href', 'title', 'date' => null])
{{-- One announcement in a list (37C; 37D): a violet megaphone tile, the title and the publication date, opening it. --}}
<a href="{{ $href }}" class="chevron flex min-h-16 items-center gap-3 px-4 py-3 hover:bg-hover">
    <x-portal.icon-tile icon="announcements" tone="violet" />
    <span class="block min-w-0 flex-1">
        <span class="block font-semibold [overflow-wrap:anywhere]">{{ $title }}</span>
        @if (filled($date))
            <span class="block text-sm text-muted">{{ $date }}</span>
        @endif
    </span>
</a>
