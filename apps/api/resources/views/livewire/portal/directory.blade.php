@php use App\Support\Portal\Format; @endphp
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

    @if ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @else
        <div aria-live="polite" class="sr-only">
            @if ($meta) {{ $meta['total'] }} {{ $meta['total'] === 1 ? 'person' : 'people' }} @endif
        </div>

        @if (count($staff) === 0)
            <x-portal.notice>{{ $query === '' ? 'No active staff yet.' : 'No one matches "'.$query.'".' }}</x-portal.notice>
        @else
            <ul class="divide-y divide-line rounded-xl border border-line bg-raised">
                @foreach ($staff as $person)
                    <li wire:key="staff-{{ $person['public_id'] }}">
                        <a href="{{ route('portal.staff', $person['public_id']) }}" class="chevron block min-h-11 px-5 py-3 hover:bg-hover">
                            <span class="block font-medium">{{ $person['display_name'] }}</span>
                            @if ($line = Format::roleLine($person))
                                <span class="block text-sm text-muted">{{ $line }}</span>
                            @endif
                        </a>
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
