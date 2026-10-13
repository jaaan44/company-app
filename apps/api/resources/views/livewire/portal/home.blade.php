@php
    use App\Support\Portal\Format;
    use Carbon\CarbonImmutable;
@endphp
<div>
    @if ($noProfile)
        <h1 class="mb-4 text-2xl font-semibold">Home</h1>
        <p class="rounded-lg border border-line bg-raised p-4">No staff profile is linked to this account.</p>
    @elseif ($errorMessage)
        <h1 class="mb-4 text-2xl font-semibold">Home</h1>
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
                <p class="mt-3 rounded-lg border border-line bg-raised p-4">Your account isn't linked to an employee profile, so some sections aren't available.</p>
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
                            <li wire:key="today-{{ $item['public_id'] }}" class="py-3">
                                <p class="font-medium">{{ $item['title'] }}</p>
                                <p class="text-sm text-muted">{{ Format::todayLabel($item) }} · {{ Format::todayTime($item, $day) }}</p>
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
                    <div class="rounded-xl border border-line bg-raised p-5" aria-label="My tasks: {{ $tasks['open_count'] }} open, {{ $tasks['overdue_count'] }} overdue, {{ $tasks['due_today_count'] }} due today">
                        <p class="text-sm text-muted">My tasks</p>
                        <p class="text-3xl font-semibold">{{ $tasks['open_count'] }} <span class="text-base font-normal text-muted">open</span></p>
                        <p class="mt-1 text-sm {{ $tasks['overdue_count'] > 0 ? 'font-semibold text-danger' : 'text-muted' }}">{{ $tasks['overdue_count'] }} overdue</p>
                        <p class="text-sm text-muted">{{ $tasks['due_today_count'] }} due today</p>
                    </div>
                @endif
                @if ($home['messages'] !== null)
                    @php($unread = $home['messages']['unread_count'])
                    <div class="rounded-xl border border-line bg-raised p-5">
                        <p class="text-sm text-muted">Messages</p>
                        @if ($unread === 0)
                            <p class="mt-2">No unread messages</p>
                        @else
                            <p class="text-3xl font-semibold">{{ $unread }} <span class="text-base font-normal text-muted">unread</span></p>
                        @endif
                    </div>
                @endif
                @php($unreadNotifications = $home['notifications']['unread_count'])
                <div class="rounded-xl border border-line bg-raised p-5">
                    <p class="text-sm text-muted">Notifications</p>
                    @if ($unreadNotifications === 0)
                        <p class="mt-2">No unread notifications</p>
                    @else
                        <p class="text-3xl font-semibold">{{ $unreadNotifications }} <span class="text-base font-normal text-muted">unread</span></p>
                    @endif
                </div>
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
                            <li wire:key="announcement-{{ $announcement['public_id'] }}" class="py-3">
                                <p class="font-medium">{{ $announcement['title'] }}</p>
                                @if ($announcement['published_at'] ?? null)
                                    <p class="text-sm text-muted">{{ Format::dayMonth(CarbonImmutable::parse($announcement['published_at'])->setTimezone($day['utc_offset'])) }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif
    @endif
</div>
