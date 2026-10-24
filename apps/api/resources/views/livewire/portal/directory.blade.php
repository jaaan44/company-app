@php use App\Livewire\Portal\Directory; @endphp
<div>
    <h1 class="sr-only">Staff directory</h1>

    <form role="search" wire:submit.prevent class="mb-4 flex gap-2" aria-label="Find a colleague">
        <label for="directory-search" class="sr-only">Search by name</label>
        <input id="directory-search" type="search" wire:model.live.debounce.400ms="search"
               placeholder="Search by name" autocomplete="off"
               class="field w-full">
        @if ($search !== '')
            <button type="button" wire:click="clearSearch" class="btn btn-plain btn-compact">Clear search</button>
        @endif
    </form>

    {{-- 37E (DEC-072): status chips (with staff-status.view) and a department filter, as in the reference's All Employees. --}}
    @if ($mayViewStatus || count($departments) > 0)
        <div class="mb-4 flex flex-wrap items-center gap-2">
            @if ($mayViewStatus)
                <div role="group" aria-label="Show" class="flex flex-wrap gap-2">
                    @foreach (Directory::FILTERS as $value => $label)
                        <button type="button" wire:click="showOnly('{{ $value }}')" aria-pressed="{{ $currentShow === $value ? 'true' : 'false' }}"
                                class="filter-chip">{{ $label }}</button>
                    @endforeach
                </div>
            @endif
            @if (count($departments) > 0)
                <label for="directory-department" class="sr-only">Department</label>
                <select id="directory-department" wire:model.live="department" class="field">
                    <option value="">All departments</option>
                    @foreach ($departments as $option)
                        <option value="{{ $option['public_id'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </select>
            @endif
        </div>
    @endif

    @if ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @else
        <div aria-live="polite" class="sr-only">
            @if ($meta) {{ $meta['total'] }} {{ $meta['total'] === 1 ? 'person' : 'people' }} @endif
        </div>

        @if (count($staff) === 0)
            <x-portal.notice>{{ $query !== '' ? 'No one matches "'.$query.'".' : ($currentShow !== '' || $department !== '' ? 'No one here right now.' : 'No active staff yet.') }}</x-portal.notice>
        @else
            <ul class="space-y-3">
                @foreach ($staff as $person)
                    <li wire:key="staff-{{ $person['public_id'] }}">
                        <x-portal.staff-card :person="$person" :may-view-status="$mayViewStatus" />
                    </li>
                @endforeach
            </ul>

            @if ($meta && $meta['last_page'] > 1)
                <nav aria-label="Pages" class="mt-4 flex items-center justify-between gap-2">
                    <button type="button" wire:click="goToPage({{ $meta['current_page'] - 1 }})" @disabled($meta['current_page'] <= 1)
                            class="btn btn-plain btn-compact">Previous</button>
                    <span class="text-sm text-muted">Page {{ $meta['current_page'] }} of {{ $meta['last_page'] }}</span>
                    <button type="button" wire:click="goToPage({{ $meta['current_page'] + 1 }})" @disabled($meta['current_page'] >= $meta['last_page'])
                            class="btn btn-plain btn-compact">Next</button>
                </nav>
            @endif
        @endif
    @endif
</div>
