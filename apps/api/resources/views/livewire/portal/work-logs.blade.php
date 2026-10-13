@php use App\Support\Portal\Format; @endphp
<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold">My work logs</h1>
        @unless ($noProfile)
            <a href="{{ route('portal.work-logs.new') }}" class="inline-flex min-h-11 items-center rounded-md bg-accent px-4 font-medium text-on-accent hover:opacity-90">Log work</a>
        @endunless
    </div>

    @if (session('status'))
        <p role="status" class="mb-4 rounded-md border border-line bg-raised p-3">{{ session('status') }}</p>
    @endif

    @if ($noProfile)
        <p class="rounded-lg border border-line bg-raised p-4">No staff profile is linked to this account.</p>
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif (count($days) === 0)
        <p class="rounded-lg border border-line bg-raised p-4">No work logged yet.</p>
    @else
        @foreach ($days as $date => $logs)
            <section wire:key="day-{{ $date }}" aria-labelledby="day-{{ $date }}" class="mb-6">
                <h2 id="day-{{ $date }}" class="mb-2 font-semibold">{{ Format::dayLabel($date, $companyDate) }}</h2>
                <ul class="divide-y divide-line rounded-xl border border-line bg-raised">
                    @foreach ($logs as $log)
                        <li wire:key="log-{{ $log['public_id'] }}">
                            <a href="{{ route('portal.work-logs.edit', $log['public_id']) }}" class="flex min-h-11 items-start justify-between gap-4 px-5 py-3 hover:bg-hover">
                                <span class="min-w-0">
                                    <span class="block font-medium">{{ $log['task']['title'] ?? $log['project']['name'] ?? '' }}</span>
                                    @if (($log['task'] ?? null) && ($log['project']['name'] ?? null))
                                        <span class="block text-sm text-muted">{{ $log['project']['name'] }}</span>
                                    @endif
                                    <span class="block text-sm text-muted line-clamp-2">{{ $log['description'] }}</span>
                                </span>
                                <span class="shrink-0 font-semibold">{{ Format::duration($log['duration_minutes']) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
        <x-portal.pager :meta="$meta" />
    @endif
</div>
