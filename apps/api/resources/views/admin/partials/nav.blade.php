{{-- The Admin Backoffice's section list (Phase 35B), shared by the sidebar and the phone drawer. --}}
<nav aria-label="Admin" class="{{ $class }}">
    <ul class="space-y-1">
        @foreach ($links as [$key, $href, $label, $icon])
            <li>
                <a href="{{ $href }}" @if ($key === $currentKey) aria-current="page" @endif
                   class="flex min-h-11 items-center gap-4 rounded-full px-4 {{ $key === $currentKey ? 'bg-accent-soft font-semibold text-accent-ink' : 'hover:bg-hover' }}">
                    <x-portal.icon :name="$icon" /> {{ $label }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
