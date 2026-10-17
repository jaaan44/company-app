<div>
    <x-portal.back :href="route('portal.status')">My status</x-portal.back>
    <h1 class="sr-only">{{ $kind === 'check-ins' ? 'All check-ins' : 'All status changes' }}</h1>

    @if ($noProfile)
        <x-portal.no-profile />
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif (count($items) === 0)
        <x-portal.notice>{{ $kind === 'check-ins' ? "You haven't checked in yet." : "You haven't set a status yet." }}</x-portal.notice>
    @else
        @include($kind === 'check-ins' ? 'livewire.portal.partials.check-in-rows' : 'livewire.portal.partials.status-rows', ['items' => $items])
        <x-portal.pager :meta="$meta" />
    @endif
</div>
