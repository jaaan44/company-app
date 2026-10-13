@php use App\Support\Portal\Format; @endphp
<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold">Leave</h1>
        @unless ($noProfile || $errorMessage)
            <a href="{{ route('portal.leave.new') }}" class="inline-flex min-h-11 items-center rounded-md bg-accent px-4 font-medium text-on-accent hover:opacity-90">Request leave</a>
        @endunless
    </div>

    @if (session('status'))
        <p role="status" class="mb-4 rounded-md border border-line bg-raised p-3">{{ session('status') }}</p>
    @endif

    @if ($noProfile)
        <p class="rounded-lg border border-line bg-raised p-4">No staff profile is linked to this account.</p>
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @else
        @if ($hasTeam)
            <a href="{{ route('portal.leave.team') }}" class="mb-6 flex min-h-11 items-center justify-between gap-4 rounded-xl border border-line bg-raised px-5 py-3 hover:bg-hover">
                <span>
                    <span class="block font-semibold">Team requests</span>
                    <span class="block text-sm text-muted">{{ $pendingCount === 0 ? 'Nothing waiting for you' : $pendingCount.' pending' }}</span>
                </span>
                <span aria-hidden="true">›</span>
            </a>
        @endif

        <section aria-labelledby="balances" class="mb-6">
            <h2 id="balances" class="mb-2 font-semibold">{{ count($balances) > 0 ? 'Balances '.$balances[0]['year'] : 'Balances' }}</h2>
            @if (count($balances) === 0)
                <p class="rounded-lg border border-line bg-raised p-4">No leave types are available.</p>
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
                <p class="rounded-lg border border-line bg-raised p-4">You haven't requested leave yet.</p>
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
