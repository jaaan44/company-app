@php use App\Support\Portal\Format; @endphp
<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="sr-only">Leave</h1>
        @unless ($noProfile || $errorMessage)
            <x-portal.fab :href="route('portal.leave.new')" label="Request leave" />
        @endunless
    </div>

    @if ($noProfile)
        <x-portal.no-profile />
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @else
        @if ($hasTeam)
            <a href="{{ route('portal.leave.team') }}" class="chevron mb-6 flex min-h-11 items-center justify-between gap-4 rounded-xl border border-line bg-raised px-5 py-3 hover:bg-hover">
                <span>
                    <span class="block font-semibold">Team requests</span>
                    <span class="block text-sm text-muted">{{ $pendingCount === 0 ? 'Nothing waiting for you' : $pendingCount.' pending' }}</span>
                </span>
            </a>
        @endif

        <section aria-labelledby="balances" class="mb-6">
            <h2 id="balances" class="mb-2 font-semibold">{{ count($balances) > 0 ? 'Balances '.$balances[0]['year'] : 'Balances' }}</h2>
            @if (count($balances) === 0)
                <x-portal.notice>No leave types are available.</x-portal.notice>
            @else
                <ul class="grid gap-3 sm:grid-cols-2">
                    @foreach ($balances as $balance)
                        <li wire:key="balance-{{ $balance['leave_type']['public_id'] }}" class="rounded-xl border border-line bg-raised p-4">
                            <span class="block font-medium">{{ $balance['leave_type']['name'] }}</span>
                            <span class="block">{{ Format::balanceHeadline($balance) }}</span>
                            <span class="block text-sm text-muted">{{ $balance['used_days'] }} used · {{ $balance['pending_days'] }} pending</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section aria-labelledby="my-requests">
            <h2 id="my-requests" class="mb-2 font-semibold">My requests</h2>
            @if (count($requests) === 0)
                <x-portal.notice>You haven't requested leave yet.</x-portal.notice>
            @else
                <ul class="divide-y divide-line rounded-xl border border-line bg-raised">
                    @foreach ($requests as $request)
                        <li wire:key="request-{{ $request['public_id'] }}">
                            <x-portal.leave-row :request="$request" :href="route('portal.leave.request', $request['public_id'])" />
                        </li>
                    @endforeach
                </ul>
                <x-portal.pager :meta="$meta" />
            @endif
        </section>
    @endif
</div>
