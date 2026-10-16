{{-- A section's landing page (Phase 35B, B-4): what it holds, live or coming. --}}
@php($section = \App\Support\Admin\Sections::ALL[$key])
<x-layouts.admin :title="$section['title']">
    <h1 class="sr-only">{{ $section['title'] }}</h1>
    <p class="mb-5 text-muted">{{ $section['summary'] }}</p>
    <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-raised">
        @foreach ($section['items'] as [$label, $description, $route, $coming])
            <li>
                @if ($route !== null)
                    <a href="{{ is_array($route) ? route($route[0], $route[1]) : route($route) }}" class="chevron block min-h-14 px-5 py-3 hover:bg-hover">
                        <span class="block font-medium">{{ $label }}</span>
                        <span class="block text-sm text-muted">{{ $description }}</span>
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
