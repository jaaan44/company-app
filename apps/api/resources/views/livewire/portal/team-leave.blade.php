<div>
    <x-portal.back :href="route('portal.leave')">Leave</x-portal.back>
    <h1 class="mb-4 text-2xl font-semibold">Team requests</h1>

    <div role="group" aria-label="Which requests" class="mb-4 inline-flex flex-wrap rounded-md border border-line bg-raised p-1">
        @foreach (\App\Livewire\Portal\TeamLeave::FILTERS as $value => $label)
            <button type="button" wire:click="show('{{ $value }}')" aria-pressed="{{ $currentStatus === $value ? 'true' : 'false' }}"
                    class="inline-flex min-h-11 items-center rounded px-4 {{ $currentStatus === $value ? 'bg-accent-soft font-semibold text-accent-ink' : 'hover:bg-hover' }}">{{ $value === 'pending' && $hasTeam ? 'Pending ('.$pendingCount.')' : $label }}</button>
        @endforeach
    </div>

    @if ($noProfile)
        <p class="rounded-lg border border-line bg-raised p-4">No staff profile is linked to this account.</p>
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif (! $hasTeam)
        <p class="rounded-lg border border-line bg-raised p-4">No requests are waiting for you.</p>
    @elseif (count($requests) === 0)
        <p class="rounded-lg border border-line bg-raised p-4">{{ $currentStatus === 'pending' ? 'No requests are waiting for you.' : 'No requests here.' }}</p>
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
