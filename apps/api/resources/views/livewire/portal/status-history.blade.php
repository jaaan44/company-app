<div>
    <x-portal.back :href="route('portal.status')">My status</x-portal.back>
    <h1 class="sr-only">{{ $kind === 'check-ins' ? 'All check-ins' : 'All status changes' }}</h1>

    @if ($noProfile)
        <p class="rounded-lg border border-line bg-raised p-4">No staff profile is linked to this account.</p>
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif (count($items) === 0)
        <p class="rounded-lg border border-line bg-raised p-4">{{ $kind === 'check-ins' ? "You haven't checked in yet." : "You haven't set a status yet." }}</p>
    @else
        @include($kind === 'check-ins' ? 'livewire.portal.partials.check-in-rows' : 'livewire.portal.partials.status-rows', ['items' => $items])
        <x-portal.pager :meta="$meta" />
    @endif
</div>
