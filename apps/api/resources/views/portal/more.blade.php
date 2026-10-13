{{-- More (Phase 34F, F-1): the mobile app's More screen — the same items, in the same order — plus Admin and Log out. --}}
<x-layouts.portal title="More">
    <h1 class="sr-only">More</h1>
    <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-raised">
        @foreach ([
            ['portal.profile', 'My profile', null, 'person'],
            ['portal.status', 'My status', null, 'status'],
            ['portal.announcements', 'Announcements', 'Company news', 'announcements'],
            ['portal.notifications', 'Notifications', null, 'notifications'],
            ['portal.reports', 'Reports', 'Service and incident reports', 'reports'],
            ['portal.leave', 'Leave', 'Balances and requests', 'leave'],
            ['portal.directory', 'Staff directory', 'Find a colleague', 'directory'],
            ['portal.work-logs', 'My work logs', 'Time you have logged', 'work-logs'],
            ['portal.projects', 'Projects', null, 'projects'],
            ['portal.clients', 'Clients', 'Company clients and contacts', 'clients'],
        ] as [$name, $label, $subtitle, $icon])
            <li>
                <a href="{{ route($name) }}" class="chevron flex min-h-14 items-center gap-4 px-5 py-3 hover:bg-hover">
                    <x-portal.icon :name="$icon" class="size-6 text-muted" />
                    <span>
                        <span class="block font-medium">{{ $label }}</span>
                        @if ($subtitle)
                            <span class="block text-sm text-muted">{{ $subtitle }}</span>
                        @endif
                    </span>
                </a>
            </li>
        @endforeach
    </ul>

    <div class="mt-6 overflow-hidden rounded-xl border border-line bg-raised">
        @can('admin.access')
            <a href="{{ route('admin.dashboard') }}" data-no-navigate class="chevron flex min-h-14 items-center gap-4 border-b border-line px-5 py-3 hover:bg-hover">
                <x-portal.icon name="admin" class="size-6 text-muted" /> <span class="font-medium">Admin</span>
            </a>
        @endcan
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="flex min-h-14 w-full items-center gap-4 px-5 py-3 text-left font-medium text-danger hover:bg-hover">
                <x-portal.icon name="logout" class="size-6" /> Log out
            </button>
        </form>
    </div>
</x-layouts.portal>
