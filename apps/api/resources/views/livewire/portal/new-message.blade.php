@php use App\Support\Portal\Format; @endphp
<div>
    <x-portal.back :href="route('portal.messages')">Messages</x-portal.back>
    <h1 class="sr-only">New message</h1>

    @if ($noProfile)
        <x-portal.no-profile />
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @else
        <x-portal.search id="colleague-search" label="Search colleagues" placeholder="Search colleagues" />
        @if ($startError)
            <p role="alert" class="mb-4 rounded-xl bg-danger-soft p-3 font-medium text-danger">{{ $startError }}</p>
        @endif
        @if (count($people) === 0)
            <x-portal.notice>No colleagues found.</x-portal.notice>
        @else
            <ul class="divide-y divide-line overflow-hidden card">
                @foreach ($people as $person)
                    <li wire:key="person-{{ $person['public_id'] }}">
                        <button type="button" wire:click="start('{{ $person['public_id'] }}')" wire:loading.attr="disabled" wire:target="start"
                                class="flex min-h-16 w-full items-center gap-3 px-4 py-3 text-left hover:bg-hover disabled:opacity-60">
                            @php($name = $person['display_name'] ?? trim(($person['first_name'] ?? '').' '.($person['last_name'] ?? '')))
                            <x-portal.avatar :name="$name" />
                            <span class="block min-w-0 flex-1">
                                <span class="block font-semibold [overflow-wrap:anywhere]">{{ $name }}</span>
                                @if ($line = Format::roleLine($person))
                                    <span class="block text-sm text-muted">{{ $line }}</span>
                                @endif
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
            <x-portal.pager :meta="$meta" />
        @endif
    @endif
</div>
