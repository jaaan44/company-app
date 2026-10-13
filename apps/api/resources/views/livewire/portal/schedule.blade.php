@php use App\Support\Portal\Format; @endphp
<div>
    <h1 class="sr-only">Schedule</h1>

    @if ($noProfile)
        <p class="rounded-lg border border-line bg-raised p-4">No staff profile is linked to this account.</p>
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif ($companyDay)
        @php
            $dates = array_keys($days);
        @endphp
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <button type="button" wire:click="move(-7)" class="inline-flex min-h-11 items-center rounded-md border border-line px-3 hover:bg-hover">Previous week</button>
            <p class="px-2 font-semibold" aria-live="polite">{{ Format::weekRange($dates[0], $dates[count($dates) - 1]) }}</p>
            <button type="button" wire:click="move(7)" class="inline-flex min-h-11 items-center rounded-md border border-line px-3 hover:bg-hover">Next week</button>
            @if ($dates[0] !== $companyDay['date'])
                <button type="button" wire:click="today" class="inline-flex min-h-11 items-center rounded-md px-3 text-accent-ink hover:bg-hover">Today</button>
            @endif
        </div>

        <div class="space-y-4">
            @foreach ($days as $date => $items)
                <section wire:key="day-{{ $date }}" aria-labelledby="day-{{ $date }}" class="rounded-xl border border-line bg-raised p-5 {{ $date === $companyDay['date'] ? 'ring-2 ring-accent' : '' }}">
                    <h2 id="day-{{ $date }}" class="mb-2 font-semibold">{{ Format::dayLabel($date, $companyDay['date'], withTomorrow: true) }}</h2>
                    @if (count($items) === 0)
                        <p class="text-muted">Nothing scheduled.</p>
                    @else
                        <ul class="divide-y divide-line">
                            @foreach ($items as $item)
                                @php
                                    $href = match ($item['source_type']) {
                                        'task' => route('portal.task', $item['public_id']),
                                        'schedule_entry' => route('portal.schedule-entry', $item['public_id']),
                                        default => null,
                                    };
                                @endphp
                                <li wire:key="item-{{ $date }}-{{ $item['source_type'] }}-{{ $item['public_id'] }}">
                                    @if ($href)
                                        <a href="{{ $href }}" class="block min-h-11 py-2 hover:bg-hover">
                                    @else
                                        <div class="py-2">
                                    @endif
                                        <span class="block font-medium">{{ $item['title'] }}</span>
                                        <span class="block text-sm text-muted">{{ Format::scheduleLabel($item) }} · {{ Format::itemTimeOn($item, $date, $companyDay['utc_offset']) }}@if ($item['project']['name'] ?? null) · {{ $item['project']['name'] }}@endif</span>
                                    @if ($href)
                                        </a>
                                    @else
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endforeach
        </div>
    @endif
</div>
