@php use App\Support\Portal\Format; use App\Livewire\Admin\LeaveRequests; @endphp
<div>
    <x-portal.back :href="route('admin.section', 'hr')">HR</x-portal.back>
    <h1 class="sr-only">Leave requests</h1>

    @if ($errorMessage && ! $meta)
        <x-portal.error :message="$errorMessage" />
    @else
        @php($control = 'mt-1 min-h-11 max-w-full rounded-md border border-line bg-raised px-3')
        <div class="mb-4 flex flex-wrap items-end gap-3">
            <div>
                <label for="lr-status" class="block text-sm font-medium">Status</label>
                <select id="lr-status" wire:model.live="status" class="{{ $control }}">
                    @foreach (LeaveRequests::STATUSES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                    <option value="">All</option>
                </select>
            </div>
            <div>
                <label for="lr-type" class="block text-sm font-medium">Type</label>
                <select id="lr-type" wire:model.live="type" class="{{ $control }}">
                    <option value="">All types</option>
                    @foreach ($types as $leaveType)
                        <option value="{{ $leaveType['public_id'] }}">{{ $leaveType['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="lr-from" class="block text-sm font-medium">From</label>
                <input id="lr-from" type="date" wire:model.live="from" class="{{ $control }}">
            </div>
            <div>
                <label for="lr-to" class="block text-sm font-medium">To</label>
                <input id="lr-to" type="date" wire:model.live="to" class="{{ $control }}">
            </div>
            @if ($canCreate)
                <a href="{{ route('admin.leave-requests.new') }}" class="ml-auto inline-flex min-h-11 items-center gap-2 rounded-full bg-accent px-5 font-medium text-on-accent hover:opacity-90">
                    <x-portal.icon name="add" class="size-5" /> Request leave for someone
                </a>
            @endif
        </div>

        @if ($requests === [])
            <x-portal.empty>No leave requests match.</x-portal.empty>
        @else
            <p class="mb-2 text-sm text-muted">{{ $meta['total'] ?? count($requests) }} {{ ($meta['total'] ?? count($requests)) === 1 ? 'request' : 'requests' }}</p>
            <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-raised">
                @foreach ($requests as $request)
                    <li wire:key="lr-{{ $request['public_id'] }}">
                        <a href="{{ route('admin.leave-requests.show', $request['public_id']) }}" class="flex flex-wrap items-center justify-between gap-x-6 gap-y-1 px-5 py-3 hover:bg-hover">
                            <span class="min-w-0 flex-1 basis-56">
                                <span class="block font-medium">{{ $request['staff']['display_name'] ?? '' }}</span>
                                <span class="block text-sm text-muted">{{ $request['leave_type']['name'] }} · {{ Format::leaveDates($request['start_date'], $request['end_date']) }} · {{ Format::calendarDays((int) $request['total_days']) }}</span>
                            </span>
                            <x-portal.chip :tone="Format::tone($request['status'])">{{ Format::leaveStatus($request['status']) }}</x-portal.chip>
                        </a>
                    </li>
                @endforeach
            </ul>
            <x-portal.pager :meta="$meta" />
        @endif
    @endif
</div>
