<div>
    <x-portal.back :href="route('portal.leave')">Leave</x-portal.back>
    <h1 class="sr-only">Team requests</h1>

    <div role="group" aria-label="Which requests" class="mb-4 inline-flex flex-wrap rounded-full border border-line bg-raised p-1">
        @foreach (\App\Livewire\Portal\TeamLeave::FILTERS as $value => $label)
            <button type="button" wire:click="show('{{ $value }}')" aria-pressed="{{ $currentStatus === $value ? 'true' : 'false' }}"
                    class="inline-flex min-h-11 items-center rounded-full px-4 {{ $currentStatus === $value ? 'bg-accent-soft font-semibold text-accent-ink' : 'hover:bg-hover' }}">{{ $value === 'pending' && $hasTeam ? 'Pending ('.$pendingCount.')' : $label }}</button>
        @endforeach
    </div>

    @if ($noProfile)
        <x-portal.no-profile />
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif (! $hasTeam)
        <x-portal.notice>No requests are waiting for you.</x-portal.notice>
    @elseif (count($requests) === 0)
        <x-portal.notice>{{ $currentStatus === 'pending' ? 'No requests are waiting for you.' : 'No requests here.' }}</x-portal.notice>
    @else
        <ul class="divide-y divide-line rounded-xl border border-line bg-raised">
            @foreach ($requests as $request)
                <li wire:key="team-{{ $request['public_id'] }}">
                    <x-portal.leave-row :request="$request" :href="route('portal.leave.team-request', $request['public_id'])" :show-requester="true" />
                </li>
            @endforeach
        </ul>
        <x-portal.pager :meta="$meta" />
    @endif
</div>
