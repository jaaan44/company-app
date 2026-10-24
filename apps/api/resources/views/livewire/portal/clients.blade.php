@php use App\Support\Portal\Format; @endphp
<div>
    <h1 class="sr-only">Clients</h1>
    @if ($mayAdd)
        <x-portal.fab :href="route('portal.clients.new')" label="New client" />
    @endif
    <x-portal.search id="client-search" label="Find a client" placeholder="Search by name or code" />

    @if ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif (count($clients) === 0)
        <x-portal.notice>{{ $query === '' ? 'No active clients yet.' : 'No client matches "'.$query.'".' }}</x-portal.notice>
    @else
        <ul class="divide-y divide-line overflow-hidden card">
            @foreach ($clients as $client)
                <li wire:key="client-{{ $client['public_id'] }}">
                    <a href="{{ route('portal.client', $client['public_id']) }}" class="chevron flex min-h-16 items-start gap-3 px-4 py-3 hover:bg-hover">
                        <x-portal.icon-tile icon="clients" :tone="Format::tileTone(Format::tone($client['status'] ?? null))" />
                        <span class="block min-w-0 flex-1">
                            <span class="block font-semibold [overflow-wrap:anywhere]">{{ $client['name'] }}</span>
                            @if ($client['client_code'] ?? null)
                                <span class="block text-sm text-muted">{{ $client['client_code'] }}</span>
                            @endif
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
        <x-portal.pager :meta="$meta" />
    @endif
</div>
