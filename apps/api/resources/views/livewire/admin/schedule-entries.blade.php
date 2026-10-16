@php use App\Support\Portal\Format; use App\Livewire\Admin\ScheduleEntryForm; @endphp
<div>
    <x-portal.back :href="route('admin.section', 'work')">Work</x-portal.back>
    <h1 class="sr-only">Schedule</h1>

    @if ($errorMessage && ! $meta)
        <x-portal.error :message="$errorMessage" />
    @else
        <div class="mb-4 flex flex-wrap items-end gap-3">
            <div>
                <label for="se-from" class="block text-sm font-medium">From</label>
                <input id="se-from" type="date" wire:model.live="from" class="mt-1 min-h-11 rounded-md border border-line bg-raised px-3">
            </div>
            <div>
                <label for="se-type" class="block text-sm font-medium">Activity</label>
                <select id="se-type" wire:model.live="type" class="mt-1 min-h-11 rounded-md border border-line bg-raised px-3">
                    <option value="">All</option>
                    @foreach (ScheduleEntryForm::TYPES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <a href="{{ route('admin.schedule.new') }}" class="ml-auto inline-flex min-h-11 items-center gap-2 rounded-full bg-accent px-5 font-medium text-on-accent hover:opacity-90">
                <x-portal.icon name="add" class="size-5" /> New schedule entry
            </a>
        </div>

        @if ($entries === [])
            <p class="rounded-xl border border-line bg-raised p-5 text-muted">No schedule entries match.</p>
        @else
            <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-raised">
                @foreach ($entries as $entry)
                    <li wire:key="se-{{ $entry['public_id'] }}">
                        <a href="{{ route('admin.schedule.edit', $entry['public_id']) }}" class="block px-5 py-3 hover:bg-hover">
                            <span class="block font-medium">{{ $entry['title'] }}</span>
                            <span class="block text-sm text-muted">
                                {{ Format::entryWhen($entry, $timezone) }} · {{ Format::activityType($entry['activity_type']) }}
                                @if ($entry['project']) · {{ $entry['project']['name'] }} @endif
                                · {{ count($entry['participants'] ?? []) }} {{ count($entry['participants'] ?? []) === 1 ? 'person' : 'people' }}
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
            <x-portal.pager :meta="$meta" />
        @endif
    @endif
</div>
