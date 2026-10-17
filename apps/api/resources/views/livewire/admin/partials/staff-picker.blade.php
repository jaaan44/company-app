{{-- The PicksStaff search (Phase 36B–36D): $label, $people (null when the search failed), optional $hint. --}}
<div>
    <label for="staff-search" class="mb-1 block text-sm font-semibold">{{ $label }}</label>
    @if ($hint ?? null)
        <p class="mb-1 text-sm text-muted">{{ $hint }}</p>
    @endif
    @if ($staffId !== '')
        <p class="flex flex-wrap items-center gap-3">
            <span class="font-medium">{{ $staffName }}</span>
            <button type="button" wire:click="clearStaff" class="btn btn-text btn-compact">Remove<span class="sr-only"> {{ $staffName }}</span></button>
        </p>
    @endif
    <input id="staff-search" type="search" wire:model.live.debounce.400ms="staffSearch" autocomplete="off"
           placeholder="{{ $staffId === '' ? 'Search by name or employee number' : 'Search to change' }}"
           @error('staffSearch') aria-invalid="true" aria-describedby="staff-search-error" @enderror
           class="field w-full sm:max-w-md">
    @error('staffSearch') <p id="staff-search-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
    @if ($people === null)
        <p class="mt-1 text-sm text-muted">The staff directory couldn't be searched.</p>
    @elseif (mb_strlen(trim($staffSearch)) >= 2)
        @if (count($people) === 0)
            <p class="mt-1 text-sm text-muted">No one matches "{{ trim($staffSearch) }}".</p>
        @else
            <ul class="mt-2 divide-y divide-line rounded-md border border-line sm:max-w-md">
                @foreach ($people as $person)
                    <li wire:key="pick-{{ $person['public_id'] }}">
                        <button type="button" wire:click="chooseStaff(@js($person['public_id']), @js($person['display_name']))"
                                class="block min-h-11 w-full px-3 py-2 text-left hover:bg-hover">
                            {{ $person['display_name'] }} <span class="text-sm text-muted">{{ $person['employee_number'] }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
