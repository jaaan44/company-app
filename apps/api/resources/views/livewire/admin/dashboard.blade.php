@php use App\Support\Portal\Format; @endphp
<div>
    <h1 class="sr-only">Dashboard</h1>

    <form wire:submit="$refresh" class="mb-6 flex flex-wrap items-end gap-3 rounded-xl border border-line bg-raised p-4">
        <div>
            <label for="from" class="block text-sm font-medium">From</label>
            <input id="from" type="date" wire:model="from" class="mt-1 min-h-11 rounded-md border border-line bg-surface px-3"
                   @if ($periodError) aria-invalid="true" aria-describedby="period-error" @endif>
        </div>
        <div>
            <label for="to" class="block text-sm font-medium">To</label>
            <input id="to" type="date" wire:model="to" class="mt-1 min-h-11 rounded-md border border-line bg-surface px-3"
                   @if ($periodError) aria-invalid="true" aria-describedby="period-error" @endif>
        </div>
        <button type="submit" class="inline-flex min-h-11 items-center rounded-full bg-accent px-5 font-medium text-on-accent hover:opacity-90">Show</button>
        @if ($from !== '' || $to !== '')
            <button type="button" wire:click="thisMonth" class="inline-flex min-h-11 items-center rounded-full px-4 font-medium text-accent-ink hover:bg-hover">This month</button>
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

    @if ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif ($d)
        @php
            $card = 'rounded-xl border border-line bg-raised p-5';
            $big = 'mt-1 text-3xl font-semibold';
            $rows = fn (array $counts, callable $label) => collect($counts)->map(fn ($n, $k) => [$label($k), $n])->values();
        @endphp
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <a href="{{ route('admin.section', 'people') }}" class="{{ $card }} hover:bg-hover">
                <h2 class="text-sm font-medium text-muted">People</h2>
                <p class="{{ $big }}">{{ $d['people']['active_staff_count'] }}</p>
                <p class="text-sm text-muted">active staff</p>
            </a>
            <a href="{{ route('admin.section', 'clients') }}" class="{{ $card }} hover:bg-hover">
                <h2 class="text-sm font-medium text-muted">Clients</h2>
                <p class="{{ $big }}">{{ $d['clients']['active_clients_count'] }}</p>
                <p class="text-sm text-muted">active clients</p>
            </a>
            <section class="{{ $card }}">
                <h2 class="text-sm font-medium text-muted">Projects</h2>
                <p class="{{ $big }}">{{ $d['projects']['active_projects_count'] }}</p>
                <p class="text-sm text-muted">active</p>
                <dl class="mt-3 space-y-1 text-sm">
                    @foreach ($rows($d['projects']['by_status'], [Format::class, 'projectStatus']) as [$label, $n])
                        <div class="flex justify-between gap-4"><dt>{{ $label }}</dt><dd class="font-medium">{{ $n }}</dd></div>
                    @endforeach
                </dl>
            </section>
            <section class="{{ $card }}">
                <h2 class="text-sm font-medium text-muted">Tasks</h2>
                <p class="{{ $big }} {{ $d['tasks']['overdue_count'] > 0 ? 'text-danger' : '' }}">{{ $d['tasks']['overdue_count'] }}</p>
                <p class="text-sm text-muted">overdue</p>
                <dl class="mt-3 space-y-1 text-sm">
                    @foreach ($rows($d['tasks']['by_status'], [Format::class, 'taskStatus']) as [$label, $n])
                        <div class="flex justify-between gap-4"><dt>{{ $label }}</dt><dd class="font-medium">{{ $n }}</dd></div>
                    @endforeach
                </dl>
            </section>
            <section class="{{ $card }}">
                <h2 class="text-sm font-medium text-muted">Work logged</h2>
                <p class="{{ $big }}">{{ rtrim(rtrim(number_format($d['work_logs']['total_hours'], 2), '0'), '.') }}</p>
                <p class="text-sm text-muted">hours in the period</p>
            </section>
            <section class="{{ $card }}">
                <h2 class="text-sm font-medium text-muted">Leave</h2>
                <p class="{{ $big }}">{{ $d['leave']['pending_count'] }}</p>
                <p class="text-sm text-muted">requests waiting for a decision</p>
                <p class="mt-3 text-sm">{{ $d['leave']['approved_count_in_period'] }} approved in the period</p>
            </section>
            <section class="{{ $card }}">
                <h2 class="text-sm font-medium text-muted">Service reports in the period</h2>
                <dl class="mt-3 space-y-1 text-sm">
                    @foreach ($rows($d['service_reports']['by_status_in_period'], [Format::class, 'serviceStatus']) as [$label, $n])
                        <div class="flex justify-between gap-4"><dt>{{ $label }}</dt><dd class="font-medium">{{ $n }}</dd></div>
                    @endforeach
                </dl>
            </section>
            <section class="{{ $card }}">
                <h2 class="text-sm font-medium text-muted">Incidents</h2>
                <p class="{{ $big }} {{ $d['incident_reports']['open_count'] > 0 ? 'text-danger' : '' }}">{{ $d['incident_reports']['open_count'] }}</p>
                <p class="text-sm text-muted">open</p>
                <p class="mt-3 text-sm font-medium">In the period, by severity</p>
                <dl class="mt-1 space-y-1 text-sm">
                    @foreach ($rows($d['incident_reports']['by_severity_in_period'], [Format::class, 'severity']) as [$label, $n])
                        <div class="flex justify-between gap-4"><dt>{{ $label }}</dt><dd class="font-medium">{{ $n }}</dd></div>
                    @endforeach
                </dl>
            </section>
            <section class="{{ $card }} sm:col-span-2 lg:col-span-1">
                <h2 class="text-sm font-medium text-muted">Coming up</h2>
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
</div>
