@php
    use App\Support\Portal\Format;
    use Carbon\CarbonImmutable;
@endphp
{{--
    Home (Phase 34A; mobile redesign after docs/design/mobile-home-reference.png):
    a gradient hero with the greeting, then a sheet with Today, Needs attention
    and Latest announcements, all from GET /me/home. Every card opens the screen
    it summarises, as before.
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
            $initials = collect(preg_split('/\s+/', trim($fullName)) ?: [])->filter()->take(2)
                ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
            $subtitle = $staff === null ? null : collect([Format::roleLine($staff), $staff['team']['name'] ?? null])->filter()->implode(' · ');
        @endphp

        <x-portal.home-hero :initials="$initials" :subtitle="filled($subtitle) ? $subtitle : 'Good to see you again!'">Hello, {{ $greetingName }}</x-portal.home-hero>

        <div class="relative -mx-4 -mt-8 space-y-8 rounded-t-3xl bg-surface px-4 pt-5 md:mx-0 md:mt-6 md:rounded-none md:px-0 md:pt-0">
            @if ($staff === null)
                <x-portal.notice>Your account isn't linked to an employee profile, so some sections aren't available.</x-portal.notice>
            @endif

            @if ($home['today'] !== null)
                @php($today = $home['today'])
                <section aria-labelledby="today" class="rounded-2xl border border-line/40 bg-linear-to-br from-card-blue to-tint-blue/60 p-4 shadow-card">
                    <div class="flex gap-4">
                        <span class="inline-flex size-12 shrink-0 items-center justify-center rounded-full bg-tint-blue text-icon-blue"><x-portal.icon name="today" /></span>
                        <div class="min-w-0 flex-1">
                            <h2 id="today" class="pt-1 text-sm text-muted">Today · {{ Format::dayMonth($companyDate) }}</h2>
                            @if (count($today['items']) === 0)
                                <p class="mt-1 text-lg font-semibold">Nothing scheduled for you today.</p>
                            @else
                                <ul class="mt-1 divide-y divide-line/70">
                                    @foreach ($today['items'] as $item)
                                        <li wire:key="today-{{ $item['public_id'] }}">
                                            {{-- As on mobile (Phase 29A R-6 / 30A R-10): a task opens the task, a meeting its entry. --}}
                                            <a href="{{ ($item['source_type'] ?? null) === 'task' ? route('portal.task', $item['public_id']) : route('portal.schedule-entry', $item['public_id']) }}"
                                               class="-mx-2 flex min-h-11 items-center gap-3 rounded-lg px-2 py-2 hover:bg-hover">
                                                <span class="block min-w-0 flex-1">
                                                    <span class="block text-lg leading-snug font-semibold [overflow-wrap:anywhere]">{{ $item['title'] }}</span>
                                                    <span class="mt-0.5 block text-sm text-muted">{{ Format::todayLabel($item) }} · {{ Format::todayTime($item, $day) }}</span>
                                                </span>
                                                <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-tint-blue text-icon-blue"><x-portal.icon name="chevron" class="size-5" /></span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @if (($more = $today['total_count'] - count($today['items'])) > 0)
                                <p class="mt-2 text-sm text-muted">+{{ $more }} more today</p>
                            @endif
                        </div>
                    </div>
                </section>
            @endif

            <section aria-labelledby="attention">
                <x-portal.section-header id="attention">Needs attention</x-portal.section-header>
                <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
                    @if ($home['tasks'] !== null)
                        @php($tasks = $home['tasks'])
                        <x-portal.summary-card :href="route('portal.tasks')" tone="rose" icon="flag" title="My tasks"
                                               :label="'My tasks: '.$tasks['open_count'].' open, '.$tasks['overdue_count'].' overdue, '.$tasks['due_today_count'].' due today'">
                            <span class="mt-1 block text-3xl font-bold tabular-nums {{ $tasks['overdue_count'] > 0 ? 'text-danger' : '' }}">{{ $tasks['open_count'] }} <span class="text-base font-normal text-muted">open</span></span>
                            <span class="block text-sm {{ $tasks['overdue_count'] > 0 ? 'font-semibold text-danger' : 'text-muted' }}">{{ $tasks['overdue_count'] }} overdue</span>
                            <span class="block text-sm text-muted">{{ $tasks['due_today_count'] }} due today</span>
                        </x-portal.summary-card>
                    @endif
                    @if ($home['messages'] !== null)
                        @php($unread = $home['messages']['unread_count'])
                        <x-portal.summary-card :href="route('portal.messages')" tone="blue" icon="messages" title="Messages"
                                               :label="'Messages: '.($unread === 0 ? 'no unread messages' : $unread.' unread')">
                            @if ($unread === 0)
                                <span class="mt-1 block text-sm text-muted">No unread messages</span>
                            @else
                                <span class="mt-1 block text-3xl font-bold tabular-nums">{{ $unread }} <span class="text-base font-normal text-muted">unread</span></span>
                            @endif
                        </x-portal.summary-card>
                    @endif
                    @php($unreadNotifications = $home['notifications']['unread_count'])
                    <x-portal.summary-card :href="route('portal.notifications')" tone="green" icon="notifications" title="Notifications" :wide="true" class="col-span-2 lg:col-span-1"
                                           :label="'Notifications: '.($unreadNotifications === 0 ? 'no unread notifications' : $unreadNotifications.' unread')">
                        @if ($unreadNotifications === 0)
                            <span class="mt-1 block text-sm text-muted">No unread notifications</span>
                        @else
                            <span class="mt-1 block text-3xl font-bold tabular-nums">{{ $unreadNotifications }} <span class="text-base font-normal text-muted">unread</span></span>
                        @endif
                    </x-portal.summary-card>
                </div>
            </section>

            @if ($home['announcements'] !== null)
                <section aria-labelledby="announcements">
                    <x-portal.section-header id="announcements" :href="route('portal.announcements')" action-label="announcements">Latest announcements</x-portal.section-header>
                    @if (count($home['announcements']['latest']) === 0)
                        <x-portal.empty>No announcements yet.</x-portal.empty>
                    @else
                        <ul class="divide-y divide-line overflow-hidden rounded-2xl border border-line/40 bg-raised shadow-card">
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
