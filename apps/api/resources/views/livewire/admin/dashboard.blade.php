@php use App\Support\Portal\Format; @endphp
<div>
    <h1 class="sr-only">Dashboard</h1>
    @php
        $card = 'rounded-xl border border-line bg-raised p-5';
        $link = $card.' chevron block hover:bg-hover';
        $big = 'mt-1 text-3xl font-semibold tabular-nums';
        $rows = fn (array $counts, callable $label) => collect($counts)->map(fn ($n, $k) => [$label($k), $n])->values();
        // Phase 37B (DEC-068): what needs a decision comes first, and a figure that leads somewhere
        // links there, for those who may open that section.
        $may = fn (string $section) => in_array($section, $sections, true);
    @endphp

    @if ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif ($d)
        @php
            $attention = [
                ['hr', route('admin.leave-requests'), 'Leave', $d['leave']['pending_count'], 'requests waiting for a decision', 'text-warning'],
                ['operations', route('admin.incidents'), 'Open incidents', $d['incident_reports']['open_count'], 'reported or under investigation', 'text-danger'],
                ['reports', route('admin.reports.show', ['report' => 'tasks', 'f' => ['overdue' => '1']]), 'Overdue tasks', $d['tasks']['overdue_count'], 'past their due date', 'text-danger'],
            ];
        @endphp
        <section aria-labelledby="attention" class="mb-8">
            <h2 id="attention" class="mb-3 text-lg font-semibold">Needs attention</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                @foreach ($attention as [$section, $href, $label, $count, $caption, $alert])
                    @if ($may($section))
                        <a href="{{ $href }}" class="{{ $link }}" aria-label="{{ $label }}: {{ $count }} {{ $caption }}">
                    @else
                        <div class="{{ $card }}">
                    @endif
                        <span class="block text-sm font-medium text-muted">{{ $label }}</span>
                        <span class="{{ $big }} block {{ $count > 0 ? $alert : '' }}">{{ $count }}</span>
                        <span class="block text-sm text-muted">{{ $caption }}</span>
                    @if ($may($section))
                        </a>
                    @else
                        </div>
                    @endif
                @endforeach
            </div>
        </section>
    @endif

    <section aria-labelledby="overview">
        <h2 id="overview" class="mb-3 text-lg font-semibold">Overview</h2>
        <form wire:submit="$refresh" class="mb-4 flex flex-wrap items-end gap-3">
            <div>
                <label for="from" class="block text-sm font-semibold">From</label>
                <input id="from" type="date" wire:model="from" class="field mt-1"
                       @if ($periodError) aria-invalid="true" aria-describedby="period-error" @endif>
            </div>
            <div>
                <label for="to" class="block text-sm font-semibold">To</label>
                <input id="to" type="date" wire:model="to" class="field mt-1"
                       @if ($periodError) aria-invalid="true" aria-describedby="period-error" @endif>
            </div>
            <button type="submit" class="btn btn-primary">Show</button>
            @if ($from !== '' || $to !== '')
                <button type="button" wire:click="thisMonth" class="btn btn-text">This month</button>
            @endif
            @if ($periodError)
                <p id="period-error" class="w-full text-sm font-medium text-danger">{{ $periodError }}</p>
            @elseif ($d)
                <p class="w-full text-sm text-muted">
                    {{ $d['period']['source'] === 'explicit' ? 'Period' : 'This month' }}:
                    {{ Format::reportDate($d['period']['from']) }} – {{ Format::reportDate($d['period']['to']) }}.
                    Counts without a period are as of now.
                </p>
            @endif
        </form>

        @if ($d)
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <a href="{{ route('admin.section', 'people') }}" class="{{ $link }}">
                    <h3 class="text-sm font-medium text-muted">People</h3>
                    <p class="{{ $big }}">{{ $d['people']['active_staff_count'] }}</p>
                    <p class="text-sm text-muted">active staff</p>
                </a>
                <a href="{{ route('admin.section', 'clients') }}" class="{{ $link }}">
                    <h3 class="text-sm font-medium text-muted">Clients</h3>
                    <p class="{{ $big }}">{{ $d['clients']['active_clients_count'] }}</p>
                    <p class="text-sm text-muted">active clients</p>
                </a>
                <section class="{{ $card }}">
                    <h3 class="text-sm font-medium text-muted">Projects</h3>
                    <p class="{{ $big }}">{{ $d['projects']['active_projects_count'] }}</p>
                    <p class="text-sm text-muted">active</p>
                    <dl class="mt-3 space-y-1 text-sm">
                        @foreach ($rows($d['projects']['by_status'], [Format::class, 'projectStatus']) as [$label, $n])
                            <div class="flex justify-between gap-4"><dt>{{ $label }}</dt><dd class="font-medium tabular-nums">{{ $n }}</dd></div>
                        @endforeach
                    </dl>
                </section>
                <section class="{{ $card }}">
                    <h3 class="text-sm font-medium text-muted">Tasks</h3>
                    <p class="{{ $big }} {{ $d['tasks']['overdue_count'] > 0 ? 'text-danger' : '' }}">{{ $d['tasks']['overdue_count'] }}</p>
                    <p class="text-sm text-muted">overdue</p>
                    <dl class="mt-3 space-y-1 text-sm">
                        @foreach ($rows($d['tasks']['by_status'], [Format::class, 'taskStatus']) as [$label, $n])
                            <div class="flex justify-between gap-4"><dt>{{ $label }}</dt><dd class="font-medium tabular-nums">{{ $n }}</dd></div>
                        @endforeach
                    </dl>
                </section>
                <section class="{{ $card }}">
                    <h3 class="text-sm font-medium text-muted">@if ($may('work'))<a href="{{ route('admin.work-logs') }}" class="underline-offset-4 hover:underline">Work logged</a>@else Work logged @endif</h3>
                    <p class="{{ $big }}">{{ rtrim(rtrim(number_format($d['work_logs']['total_hours'], 2), '0'), '.') }}</p>
                    <p class="text-sm text-muted">hours in the period</p>
                </section>
                <section class="{{ $card }}">
                    <h3 class="text-sm font-medium text-muted">Leave</h3>
                    <p class="{{ $big }}">{{ $d['leave']['approved_count_in_period'] }}</p>
                    <p class="text-sm text-muted">requests approved in the period</p>
                </section>
                <section class="{{ $card }}">
                    <h3 class="text-sm font-medium text-muted">@if ($may('operations'))<a href="{{ route('admin.service-reports') }}" class="underline-offset-4 hover:underline">Service reports in the period</a>@else Service reports in the period @endif</h3>
                    <dl class="mt-3 space-y-1 text-sm">
                        @foreach ($rows($d['service_reports']['by_status_in_period'], [Format::class, 'serviceStatus']) as [$label, $n])
                            <div class="flex justify-between gap-4"><dt>{{ $label }}</dt><dd class="font-medium tabular-nums">{{ $n }}</dd></div>
                        @endforeach
                    </dl>
                </section>
                <section class="{{ $card }}">
                    <h3 class="text-sm font-medium text-muted">Incidents</h3>
                    <p class="{{ $big }} {{ $d['incident_reports']['open_count'] > 0 ? 'text-danger' : '' }}">{{ $d['incident_reports']['open_count'] }}</p>
                    <p class="text-sm text-muted">open</p>
                    <p class="mt-3 text-sm font-medium">In the period, by severity</p>
                    <dl class="mt-1 space-y-1 text-sm">
                        @foreach ($rows($d['incident_reports']['by_severity_in_period'], [Format::class, 'severity']) as [$label, $n])
                            <div class="flex justify-between gap-4"><dt>{{ $label }}</dt><dd class="font-medium tabular-nums">{{ $n }}</dd></div>
                        @endforeach
                    </dl>
                </section>
                <section class="{{ $card }} sm:col-span-2 lg:col-span-1">
                    <h3 class="text-sm font-medium text-muted">@if ($may('work'))<a href="{{ route('admin.schedule') }}" class="underline-offset-4 hover:underline">Coming up</a>@else Coming up @endif</h3>
                    <p class="{{ $big }}">{{ $d['schedule']['upcoming_count'] }}</p>
                    <p class="text-sm text-muted">schedule items in the next days</p>
                    @if ($d['schedule']['items'] !== [])
                        <ul class="mt-3 space-y-2 text-sm">
                            @foreach ($d['schedule']['items'] as $item)
                                <li>
                                    <span class="block font-medium">{{ $item['title'] ?? 'Untitled' }}</span>
                                    @if (isset($item['starts_at'], $item['ends_at']))
                                        <span class="block text-muted">{{ Format::entryWhen($item, $utcOffset) }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        @endif
    </section>
</div>
