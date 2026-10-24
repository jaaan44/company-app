{{-- More (Phase 34F, F-1): the mobile app's More screen — the same items, in the same order — plus Admin and Log out. --}}
<x-layouts.portal title="More">
    <h1 class="sr-only">More</h1>
    <ul class="divide-y divide-line overflow-hidden card">
        @foreach ([
            ['portal.profile', 'My profile', null, 'person', 'blue'],
            ['portal.status', 'My status', null, 'status', 'green'],
            ['portal.announcements', 'Announcements', 'Company news', 'announcements', 'violet'],
            ['portal.notifications', 'Notifications', null, 'notifications', 'green'],
            ['portal.reports', 'Reports', 'Service and incident reports', 'reports', 'rose'],
            ['portal.leave', 'Leave', 'Balances and requests', 'leave', 'amber'],
            ['portal.directory', 'Staff directory', 'Find a colleague', 'directory', 'violet'],
            ['portal.work-logs', 'My work logs', 'Time you have logged', 'work-logs', 'blue'],
            ['portal.projects', 'Projects', null, 'projects', 'blue'],
            ['portal.clients', 'Clients', 'Company clients and contacts', 'clients', 'slate'],
        ] as [$name, $label, $subtitle, $icon, $tone])
            <li>
                <a href="{{ route($name) }}" class="chevron flex min-h-16 items-center gap-3 px-4 py-3 hover:bg-hover">
                    <x-portal.icon-tile :icon="$icon" :tone="$tone" />
                    <span>
                        <span class="block font-semibold">{{ $label }}</span>
                        @if ($subtitle)
                            <span class="block text-sm text-muted">{{ $subtitle }}</span>
                        @endif
                    </span>
                </a>
            </li>
        @endforeach
    </ul>

    <div class="mt-6 overflow-hidden card">
        @can('admin.access')
            <a href="{{ route('admin.dashboard') }}" data-no-navigate class="chevron flex min-h-16 items-center gap-3 border-b border-line px-4 py-3 hover:bg-hover">
                <x-portal.icon-tile icon="admin" tone="slate" /> <span class="font-semibold">Admin</span>
            </a>
        @endcan
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="flex min-h-16 w-full items-center gap-3 px-4 py-3 text-left font-semibold text-danger hover:bg-hover">
                <x-portal.icon-tile icon="logout" tone="rose" /> Log out
            </button>
        </form>
    </div>
</x-layouts.portal>
