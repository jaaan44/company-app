@php
    use App\Support\Portal\Format;
    use Carbon\CarbonImmutable;
@endphp
{{--
    Home (Phase 34A; mobile redesign 37C; 37D after docs/design/portal-style-reference.webp):
    a gradient hero with the greeting, then a sheet with quick actions,
    Employees (37E), Today, My work, Needs attention and Latest announcements,
    from GET /me/home (and GET /staff for Employees). Every card opens the screen it
    summarises, as before.
--}}
<div>
    @if ($noProfile || $errorMessage || ! $home)
        <x-portal.home-hero>Home</x-portal.home-hero>
        <div class="relative -mx-4 -mt-8 rounded-t-3xl bg-surface px-4 pt-5 md:mx-0 md:mt-6 md:rounded-none md:px-0 md:pt-0">
            @if ($noProfile)
                <x-portal.no-profile />
            @elseif ($errorMessage)
                <x-portal.error :message="$errorMessage" />
            @endif
        </div>
    @else
        @php
            $staff = $home['staff'];
            $day = $home['company_day'];
            $companyDate = CarbonImmutable::parse($day['date']);
            $greetingName = $staff === null
                ? $home['user']['name']
                : (filled($staff['preferred_name']) ? $staff['preferred_name'] : $staff['first_name']);
            $fullName = $staff === null ? $home['user']['name'] : ($staff['display_name'] ?? $greetingName);
            $subtitle = $staff === null ? null : collect([Format::roleLine($staff), $staff['team']['name'] ?? null])->filter()->implode(' · ');
        @endphp

        <x-portal.home-hero :initials="Format::initials($fullName)" :subtitle="filled($subtitle) ? $subtitle : 'Good to see you again!'">Hello, {{ $greetingName }}</x-portal.home-hero>

        <div class="relative -mx-4 -mt-8 space-y-8 rounded-t-3xl bg-surface px-4 pt-5 md:mx-0 md:mt-6 md:rounded-none md:px-0 md:pt-0">
            @if ($staff === null)
                <x-portal.notice>Your account isn't linked to an employee profile, so some sections aren't available.</x-portal.notice>
            @else
                {{-- 37D: the reference's quick actions, each a link to a page that already has the action; the first is the main one. --}}
                @php
                    $actions = array_values(array_filter([
                        $mayAddTasks ? ['portal.tasks.new', 'New task', 'add'] : null,
                        ['portal.messages.new', 'Message', 'messages'],
                        ['portal.leave.new', 'Request leave', 'leave'],
                        ['portal.status', 'My status', 'status'],
                        ['portal.more', 'More', 'more'],
                    ]));
                @endphp
                <nav aria-label="Quick actions">
                    <ul class="grid {{ count($actions) === 5 ? 'grid-cols-5' : 'grid-cols-4' }} gap-2">
                        @foreach ($actions as $i => [$route, $label, $icon])
                            <li>
                                <a href="{{ route($route) }}" class="group flex min-h-11 flex-col items-center gap-1.5 rounded-2xl py-1 text-center text-sm leading-tight font-medium">
                                    <span class="inline-flex size-14 items-center justify-center rounded-2xl shadow-card {{ $i === 0 && $mayAddTasks ? 'bg-accent text-on-accent group-hover:opacity-90' : 'border border-edge bg-raised text-ink group-hover:bg-hover' }}">
                                        <x-portal.icon :name="$icon" class="size-7" />
                                    </span>
                                    {{ $label }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
            @endif

            {{-- 37E (DEC-072): the first four employees, A–Z, as compact cards; View all opens the whole directory. --}}
            @if ($employees !== null && count($employees['people']) > 0)
                <section aria-labelledby="employees">
                    <x-portal.section-header id="employees" :href="route('portal.directory')" :action="'View all ('.$employees['total'].')'" action-label="employees">Employees</x-portal.section-header>
                    {{-- As in the reference's dashboard: narrow cards side by side, a row that scrolls sideways inside itself on phones (the next card peeks in; the page never scrolls sideways), four columns from md. --}}
                    <ul class="-mx-4 flex snap-x snap-mandatory scroll-px-4 gap-3 overflow-x-auto px-4 pb-2 md:mx-0 md:grid md:grid-cols-4 md:overflow-visible md:px-0 md:pb-0">
                        @foreach ($employees['people'] as $person)
                            <li wire:key="employee-{{ $person['public_id'] }}" class="w-38 shrink-0 snap-start md:w-auto">
                                <x-portal.staff-card :person="$person" :may-view-status="$mayViewStatus" :compact="true" />
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($home['today'] !== null)
                @php($today = $home['today'])
                <section aria-labelledby="today">
                    <x-portal.section-header id="today" :href="route('portal.schedule')" action-label="schedule">Today · {{ Format::dayMonth($companyDate) }}</x-portal.section-header>
                    @if (count($today['items']) === 0)
                        <x-portal.empty>Nothing scheduled for you today.</x-portal.empty>
                    @else
                        <ul class="divide-y divide-line overflow-hidden card">
                            @foreach ($today['items'] as $item)
                                @php($isTask = ($item['source_type'] ?? null) === 'task')
                                <li wire:key="today-{{ $item['public_id'] }}">
                                    {{-- As on mobile (Phase 29A R-6 / 30A R-10): a task opens the task, a meeting its entry. --}}
                                    <a href="{{ $isTask ? route('portal.task', $item['public_id']) : route('portal.schedule-entry', $item['public_id']) }}"
                                       class="chevron flex min-h-16 items-center gap-3 px-4 py-3 hover:bg-hover">
                                        <x-portal.icon-tile :icon="$isTask ? 'tasks' : 'schedule'" :tone="$isTask ? 'amber' : 'blue'" />
                                        <span class="block min-w-0 flex-1">
                                            <span class="block font-semibold [overflow-wrap:anywhere]">{{ $item['title'] }}</span>
                                            <span class="block text-sm text-muted">{{ Format::todayLabel($item) }} · {{ Format::todayTime($item, $day) }}</span>
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if (($more = $today['total_count'] - count($today['items'])) > 0)
                        <p class="mt-2 text-sm text-muted">+{{ $more }} more today</p>
                    @endif
                </section>
            @endif

            @if ($home['tasks'] !== null)
                @php($tasks = $home['tasks'])
                <section aria-labelledby="my-work">
                    <x-portal.section-header id="my-work">My work</x-portal.section-header>
                    <a href="{{ route('portal.tasks') }}" class="chevron card flex items-center p-3 hover:bg-hover"
                       aria-label="{{ 'My tasks: '.$tasks['open_count'].' open, '.$tasks['overdue_count'].' overdue, '.$tasks['due_today_count'].' due today' }}">
                        <span class="flex flex-1 gap-2">
                            <x-portal.stat-chip :count="$tasks['due_today_count']" label="due today" tone="amber" />
                            <x-portal.stat-chip :count="$tasks['open_count']" label="open" tone="blue" />
                            <x-portal.stat-chip :count="$tasks['overdue_count']" label="overdue" :tone="$tasks['overdue_count'] > 0 ? 'rose' : 'green'" />
                        </span>
                    </a>
                </section>
            @endif

            <section aria-labelledby="attention">
                <x-portal.section-header id="attention">Needs attention</x-portal.section-header>
                <ul class="divide-y divide-line overflow-hidden card">
                    @if ($home['messages'] !== null)
                        @php($unread = $home['messages']['unread_count'])
                        <li>
                            <a href="{{ route('portal.messages') }}" class="chevron flex min-h-16 items-center gap-3 px-4 py-3 hover:bg-hover">
                                <x-portal.icon-tile icon="messages" tone="blue" />
                                <span class="block min-w-0 flex-1">
                                    <span class="block font-semibold">Messages</span>
                                    <span class="block text-sm text-muted">{{ $unread === 0 ? 'No unread messages' : $unread.' unread' }}</span>
                                </span>
                                @if ($unread > 0)
                                    <span aria-hidden="true" class="min-w-6 rounded-full bg-danger px-1.5 text-center text-sm leading-6 font-semibold text-raised tabular-nums">{{ $unread > 99 ? '99+' : $unread }}</span>
                                @endif
                            </a>
                        </li>
                    @endif
                    @php($unreadNotifications = $home['notifications']['unread_count'])
                    <li>
                        <a href="{{ route('portal.notifications') }}" class="chevron flex min-h-16 items-center gap-3 px-4 py-3 hover:bg-hover">
                            <x-portal.icon-tile icon="notifications" tone="green" />
                            <span class="block min-w-0 flex-1">
                                <span class="block font-semibold">Notifications</span>
                                <span class="block text-sm text-muted">{{ $unreadNotifications === 0 ? 'No unread notifications' : $unreadNotifications.' unread' }}</span>
                            </span>
                            @if ($unreadNotifications > 0)
                                <span aria-hidden="true" class="min-w-6 rounded-full bg-danger px-1.5 text-center text-sm leading-6 font-semibold text-raised tabular-nums">{{ $unreadNotifications > 99 ? '99+' : $unreadNotifications }}</span>
                            @endif
                        </a>
                    </li>
                </ul>
            </section>


            @if ($home['announcements'] !== null)
                <section aria-labelledby="announcements">
                    <x-portal.section-header id="announcements" :href="route('portal.announcements')" action-label="announcements">Latest announcements</x-portal.section-header>
                    @if (count($home['announcements']['latest']) === 0)
                        <x-portal.empty>No announcements yet.</x-portal.empty>
                    @else
                        <ul class="divide-y divide-line overflow-hidden card">
                            @foreach ($home['announcements']['latest'] as $announcement)
                                <li wire:key="announcement-{{ $announcement['public_id'] }}">
                                    <x-portal.announcement-row :href="route('portal.announcement', $announcement['public_id'])" :title="$announcement['title']"
                                                               :date="($announcement['published_at'] ?? null) ? Format::dayMonth(CarbonImmutable::parse($announcement['published_at'])->setTimezone($day['utc_offset'])) : null" />
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif
        </div>
    @endif
</div>
