@php use App\Support\Portal\Format; @endphp
<div>
    <x-portal.back :href="route('admin.section', 'people')">People</x-portal.back>
    <h1 class="sr-only">Staff records</h1>

    @if ($errorMessage && ! $meta)
        <x-portal.error :message="$errorMessage" />
    @else
        <div class="mb-4 flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-1 basis-56">
                <label for="staff-search" class="block text-sm font-semibold">Search by name or employee number</label>
                <input id="staff-search" type="search" wire:model.live.debounce.400ms="search" autocomplete="off"
                       class="field mt-1 w-full">
            </div>
            <div>
                <label for="staff-status" class="block text-sm font-semibold">Status</label>
                <select id="staff-status" wire:model.live="status" class="field mt-1">
                    <option value="">All</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="separated">Separated</option>
                </select>
            </div>
            @if ($search !== '' || $status !== '')
                <button type="button" wire:click="clearFilters" class="btn btn-text btn-compact">Clear filters</button>
            @endif
            @if ($canCreate)
                <a href="{{ route('admin.staff.new') }}"
                   class="btn btn-primary">
                    <x-portal.icon name="add" class="size-5" /> New staff record
                </a>
            @endif
        </div>

        @if ($staff === [])
            <x-portal.empty>No staff records match.</x-portal.empty>
        @else
            <p class="mb-2 text-sm text-muted">{{ $meta['total'] ?? count($staff) }} {{ ($meta['total'] ?? count($staff)) === 1 ? 'record' : 'records' }}</p>
            <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-raised">
                @foreach ($staff as $person)
                    <li wire:key="staff-{{ $person['public_id'] }}">
                        <a href="{{ route('admin.staff.show', $person['public_id']) }}" class="chevron flex flex-wrap items-center justify-between gap-x-6 gap-y-1 px-5 py-3 hover:bg-hover">
                            <span class="min-w-0 flex-1 basis-56">
                                <span class="block font-medium">{{ $person['display_name'] }}</span>
                                <span class="block text-sm text-muted">
                                    {{ $person['employee_number'] }}
                                    @if ($person['position']) · {{ $person['position']['title'] }} @endif
                                    @if ($person['department']) · {{ $person['department']['name'] }} @endif
                                </span>
                            </span>
                            <span class="flex flex-wrap gap-2">
                                @if ($person['status'] !== 'active')
                                    <x-portal.chip :tone="Format::tone($person['status'])">{{ Format::staffStatus($person['status']) }}</x-portal.chip>
                                @endif
                                @unless ($person['has_user_account'])
                                    <x-portal.chip tone="muted">No sign-in</x-portal.chip>
                                @endunless
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
            <x-portal.pager :meta="$meta" />
        @endif
    @endif
</div>
