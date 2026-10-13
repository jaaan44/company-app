<div>
    <h1 class="mb-4 text-2xl font-semibold">Clients</h1>
    <x-portal.search id="client-search" label="Find a client" placeholder="Search by name or code" />

    @if ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif (count($clients) === 0)
        <p class="rounded-lg border border-line bg-raised p-4">{{ $query === '' ? 'No active clients yet.' : 'No client matches "'.$query.'".' }}</p>
    @else
        <ul class="divide-y divide-line rounded-xl border border-line bg-raised">
            @foreach ($clients as $client)
                <li wire:key="client-{{ $client['public_id'] }}">
                    <a href="{{ route('portal.client', $client['public_id']) }}" class="block min-h-11 px-5 py-3 hover:bg-hover">
                        <span class="block font-medium">{{ $client['name'] }}</span>
                        @if ($client['client_code'] ?? null)
                            <span class="block text-sm text-muted">{{ $client['client_code'] }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
        <x-portal.pager :meta="$meta" />
    @endif
</div>
