{{-- A section's landing page (Phase 35B, B-4): what it holds, live or coming. --}}
@php($section = \App\Support\Admin\Sections::ALL[$key])
<x-layouts.admin :title="$section['title']">
    <h1 class="sr-only">{{ $section['title'] }}</h1>
    <p class="mb-5 text-muted">{{ $section['summary'] }}</p>
    <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-raised">
        @foreach ($section['items'] as [$label, $description, $route, $coming])
            <li>
                @if ($route !== null)
                    @php($inPortal = str_starts_with(is_array($route) ? $route[0] : $route, 'portal.'))
                    {{-- Phase 37B (DEC-068): a screen of the staff portal says so, and opens with a full page load (a different layout, like "Staff portal"). --}}
                    <a href="{{ is_array($route) ? route($route[0], $route[1]) : route($route) }}" @if ($inPortal) data-no-navigate @endif class="chevron block min-h-14 px-5 py-3 hover:bg-hover">
                        <span class="block font-medium">{{ $label }}</span>
                        <span class="block text-sm text-muted">{{ $description }}</span>
                        @if ($inPortal)
                            <span class="mt-1 flex items-center gap-1 text-sm text-accent-ink"><x-portal.icon name="portal" class="size-4" /> Opens in the staff portal</span>
                        @endif
                    </a>
                @else
                    <div class="flex min-h-14 flex-wrap items-center justify-between gap-x-4 gap-y-1 px-5 py-3">
                        <span>
                            <span class="block font-medium">{{ $label }}</span>
                            <span class="block text-sm text-muted">{{ $description }}</span>
                        </span>
                        <span class="rounded-full bg-hover px-3 py-1 text-sm text-muted">Coming in {{ $coming }}</span>
                    </div>
                @endif
            </li>
        @endforeach
    </ul>
</x-layouts.admin>
