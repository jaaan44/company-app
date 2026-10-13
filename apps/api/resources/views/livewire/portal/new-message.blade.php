@php use App\Support\Portal\Format; @endphp
<div>
    <x-portal.back :href="route('portal.messages')">Messages</x-portal.back>
    <h1 class="mb-4 text-2xl font-semibold">New message</h1>

    @if ($noProfile)
        <p class="rounded-lg border border-line bg-raised p-4">No staff profile is linked to this account.</p>
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @else
        <x-portal.search id="colleague-search" label="Search colleagues" placeholder="Search colleagues" />
        @if ($startError)
            <p role="alert" class="mb-4 rounded-md bg-danger-soft p-3 font-medium text-danger">{{ $startError }}</p>
        @endif
        @if (count($people) === 0)
            <p class="rounded-lg border border-line bg-raised p-4">No colleagues found.</p>
        @else
            <ul class="divide-y divide-line rounded-xl border border-line bg-raised">
                @foreach ($people as $person)
                    <li wire:key="person-{{ $person['public_id'] }}">
                        <button type="button" wire:click="start('{{ $person['public_id'] }}')" wire:loading.attr="disabled" wire:target="start"
                                class="block min-h-11 w-full px-5 py-3 text-left hover:bg-hover disabled:opacity-60">
                            <span class="block font-medium">{{ $person['display_name'] ?? trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? '')) }}</span>
                            @if ($line = Format::roleLine($person))
                                <span class="block text-sm text-muted">{{ $line }}</span>
                            @endif
                        </button>
                    </li>
                @endforeach
            </ul>
            <x-portal.pager :meta="$meta" />
        @endif
    @endif
</div>
