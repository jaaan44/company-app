@php
    use App\Support\Portal\Format;
    use Carbon\CarbonImmutable;
@endphp
<div>
    @if ($noProfile)
        <h1 class="sr-only">Home</h1>
        <x-portal.no-profile />
    @elseif ($errorMessage)
        <h1 class="sr-only">Home</h1>
        <x-portal.error :message="$errorMessage" />
    @elseif ($home)
        @php
            $staff = $home['staff'];
            $day = $home['company_day'];
            $companyDate = CarbonImmutable::parse($day['date']);
            $greetingName = $staff === null
                ? $home['user']['name']
                : (filled($staff['preferred_name']) ? $staff['preferred_name'] : $staff['first_name']);
        @endphp

        <section aria-labelledby="greeting" class="mb-8">
            <h1 id="greeting" class="text-2xl font-semibold">Hello, {{ $greetingName }}</h1>
            @if ($staff)
                @if ($line = Format::roleLine($staff))
                    <p class="mt-1 text-muted">{{ $line }}</p>
                @endif
                @if ($staff['team']['name'] ?? null)
                    <p class="text-muted">{{ $staff['team']['name'] }}</p>
                @endif
            @else
                <x-portal.notice class="mt-3">Your account isn't linked to an employee profile, so some sections aren't available.</x-portal.notice>
            @endif
        </section>

        @if ($home['today'] !== null)
            @php($today = $home['today'])
            <section aria-labelledby="today" class="mb-8 rounded-xl border border-line bg-raised p-5">
                <h2 id="today" class="mb-3 text-lg font-semibold">Today · {{ Format::dayMonth($companyDate) }}</h2>
                @if (count($today['items']) === 0)
                    <p>Nothing scheduled for you today.</p>
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($today['items'] as $item)
                            <li wire:key="today-{{ $item['public_id'] }}">
                                {{-- As on mobile (Phase 29A R-6 / 30A R-10): a task opens the task, a meeting its entry. --}}
                                <a href="{{ ($item['source_type'] ?? null) === 'task' ? route('portal.task', $item['public_id']) : route('portal.schedule-entry', $item['public_id']) }}"
                                   class="chevron -mx-3 block min-h-11 rounded-md px-3 py-3 hover:bg-hover">
                                    <span class="block font-medium">{{ $item['title'] }}</span>
                                    <span class="block text-sm text-muted">{{ Format::todayLabel($item) }} · {{ Format::todayTime($item, $day) }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if (($more = $today['total_count'] - count($today['items'])) > 0)
                    <p class="mt-2 text-sm">+{{ $more }} more today</p>
                @endif
            </section>
        @endif

        <section aria-labelledby="attention" class="mb-8">
            <h2 id="attention" class="mb-3 text-lg font-semibold">Needs attention</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @if ($home['tasks'] !== null)
                    @php($tasks = $home['tasks'])
                    <a href="{{ route('portal.tasks') }}" class="block rounded-xl border border-line bg-raised p-5 hover:bg-hover" aria-label="My tasks: {{ $tasks['open_count'] }} open, {{ $tasks['overdue_count'] }} overdue, {{ $tasks['due_today_count'] }} due today">
                        <span class="block text-sm text-muted">My tasks</span>
                        <span class="block text-3xl font-semibold">{{ $tasks['open_count'] }} <span class="text-base font-normal text-muted">open</span></span>
                        <span class="mt-1 block text-sm {{ $tasks['overdue_count'] > 0 ? 'font-semibold text-danger' : 'text-muted' }}">{{ $tasks['overdue_count'] }} overdue</span>
                        <span class="block text-sm text-muted">{{ $tasks['due_today_count'] }} due today</span>
                    </a>
                @endif
                @if ($home['messages'] !== null)
                    @php($unread = $home['messages']['unread_count'])
                    <a href="{{ route('portal.messages') }}" class="block rounded-xl border border-line bg-raised p-5 hover:bg-hover" aria-label="Messages: {{ $unread === 0 ? 'no unread messages' : $unread.' unread' }}">
                        <span class="block text-sm text-muted">Messages</span>
                        @if ($unread === 0)
                            <span class="mt-2 block">No unread messages</span>
                        @else
                            <span class="block text-3xl font-semibold">{{ $unread }} <span class="text-base font-normal text-muted">unread</span></span>
                        @endif
                    </a>
                @endif
                @php($unreadNotifications = $home['notifications']['unread_count'])
                <a href="{{ route('portal.notifications') }}" class="block rounded-xl border border-line bg-raised p-5 hover:bg-hover" aria-label="Notifications: {{ $unreadNotifications === 0 ? 'no unread notifications' : $unreadNotifications.' unread' }}">
                    <span class="block text-sm text-muted">Notifications</span>
                    @if ($unreadNotifications === 0)
                        <span class="mt-2 block">No unread notifications</span>
                    @else
                        <span class="block text-3xl font-semibold">{{ $unreadNotifications }} <span class="text-base font-normal text-muted">unread</span></span>
                    @endif
                </a>
            </div>
        </section>

        @if ($home['announcements'] !== null)
            <section aria-labelledby="announcements" class="rounded-xl border border-line bg-raised p-5">
                <h2 id="announcements" class="mb-3 text-lg font-semibold">Latest announcements</h2>
                @if (count($home['announcements']['latest']) === 0)
                    <p>No announcements yet.</p>
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($home['announcements']['latest'] as $announcement)
                            <li wire:key="announcement-{{ $announcement['public_id'] }}">
                                <a href="{{ route('portal.announcement', $announcement['public_id']) }}" class="chevron -mx-3 block min-h-11 rounded-md px-3 py-3 hover:bg-hover">
                                    <span class="block font-medium">{{ $announcement['title'] }}</span>
                                    @if ($announcement['published_at'] ?? null)
                                        <span class="block text-sm text-muted">{{ Format::dayMonth(CarbonImmutable::parse($announcement['published_at'])->setTimezone($day['utc_offset'])) }}</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <a href="{{ route('portal.announcements') }}" class="mt-2 inline-flex min-h-11 items-center text-accent-ink underline-offset-4 hover:underline">All announcements</a>
            </section>
        @endif
    @endif
</div>
