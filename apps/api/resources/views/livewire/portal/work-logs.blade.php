@php use App\Support\Portal\Format; @endphp
<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="sr-only">My work logs</h1>
        @unless ($noProfile)
            <x-portal.fab :href="route('portal.work-logs.new')" label="Log work" />
        @endunless
    </div>

    @if ($noProfile)
        <x-portal.no-profile />
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif (count($days) === 0)
        <x-portal.notice>No work logged yet.</x-portal.notice>
    @else
        @foreach ($days as $date => $logs)
            <section wire:key="day-{{ $date }}" aria-labelledby="day-{{ $date }}" class="mb-6">
                <h2 id="day-{{ $date }}" class="mb-3 text-lg font-bold tracking-tight">{{ Format::dayLabel($date, $companyDate) }}</h2>
                <ul class="divide-y divide-line overflow-hidden card">
                    @foreach ($logs as $log)
                        <li wire:key="log-{{ $log['public_id'] }}">
                            <a href="{{ route('portal.work-logs.edit', $log['public_id']) }}" class="chevron flex min-h-16 items-start gap-3 px-4 py-3 hover:bg-hover">
                                <x-portal.icon-tile icon="work-logs" tone="blue" />
                                <span class="block min-w-0 flex-1">
                                    <span class="block font-semibold [overflow-wrap:anywhere]">{{ $log['task']['title'] ?? $log['project']['name'] ?? '' }}</span>
                                    @if (($log['task'] ?? null) && ($log['project']['name'] ?? null))
                                        <span class="block text-sm text-muted">{{ $log['project']['name'] }}</span>
                                    @endif
                                    <span class="block text-sm text-muted line-clamp-2">{{ $log['description'] }}</span>
                                </span>
                                <span class="shrink-0 font-semibold tabular-nums">{{ Format::duration($log['duration_minutes']) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
        <x-portal.pager :meta="$meta" />
    @endif
</div>
