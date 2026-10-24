@php
    use App\Support\Portal\Format;
    use App\Livewire\Portal\MyStatus;
@endphp
<div>
    <h1 class="sr-only">My status</h1>

    @if ($noProfile)
        <x-portal.no-profile />
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @else
        @php($current = $statuses[0] ?? null)
        <section aria-labelledby="current-status" class="card p-5">
            <h2 id="current-status" class="text-sm text-muted">Current status</h2>
            @if ($current === null)
                <p class="mt-1 text-xl font-bold">No status set yet</p>
            @else
                {{-- 37D: the status's colour beside its word, as on the directory. --}}
                <p class="mt-1 flex items-center gap-2 text-xl font-bold"><span aria-hidden="true" class="size-3 rounded-full {{ ['green' => 'bg-icon-green', 'amber' => 'bg-icon-amber', 'rose' => 'bg-icon-rose', 'violet' => 'bg-icon-violet'][Format::operationalTone($current['status'])] ?? 'bg-icon-slate' }}"></span>{{ Format::operationalStatus($current['status']) }}</p>
            @endif
            @if ($current && $companyDay)
                <p class="text-sm text-muted">Set {{ Format::companyTime($current['recorded_at'], $companyDay['date'], $companyDay['utc_offset']) }}</p>
            @endif

            <h3 id="change-status" class="mt-5 mb-2 text-lg font-bold tracking-tight">Change status</h3>
            <div role="group" aria-labelledby="change-status" class="flex flex-wrap gap-2">
                @foreach (MyStatus::STATUSES as $status)
                    @php($isCurrent = ($current['status'] ?? null) === $status)
                    <button type="button" wire:click="setStatus('{{ $status }}')" aria-pressed="{{ $isCurrent ? 'true' : 'false' }}"
                            @disabled($isCurrent) wire:loading.attr="disabled" wire:target="setStatus"
                            class="inline-flex min-h-11 items-center rounded-full border px-4 {{ $isCurrent ? 'border-accent bg-accent-soft font-semibold text-accent-ink' : 'border-field hover:bg-hover disabled:opacity-50' }}">
                        {{ Format::operationalStatus($status) }}
                    </button>
                @endforeach
            </div>
            <p class="mt-2 text-sm text-muted">Off duty: not working right now. This isn't leave.</p>
            <p wire:loading wire:target="setStatus" class="mt-2 text-sm text-muted">Saving…</p>
            <div aria-live="polite">
                <x-portal.toast :message="$savedMessage" :nonce="$toastNonce ?? 0" />
                @if ($saveMessage)
                    <p role="alert" class="mt-2 text-sm font-medium text-danger">{{ $saveMessage }}</p>
                @endif
            </div>
            <p class="mt-4 text-sm text-muted">Check in from the Company App on your phone.</p>
        </section>

        <section aria-labelledby="recent-changes" class="mt-6">
            <h2 id="recent-changes" class="mb-3 text-lg font-bold tracking-tight">Recent status changes</h2>
            @if (count($statuses) === 0)
                <x-portal.notice>You haven't set a status yet.</x-portal.notice>
            @else
                @include('livewire.portal.partials.status-rows', ['items' => $statuses])
                <a href="{{ route('portal.status.changes') }}" class="mt-2 inline-flex min-h-11 items-center text-accent-ink underline-offset-4 hover:underline">All status changes</a>
            @endif
        </section>

        <section aria-labelledby="recent-check-ins" class="mt-6">
            <h2 id="recent-check-ins" class="mb-3 text-lg font-bold tracking-tight">Recent check-ins</h2>
            @if (count($checkIns) === 0)
                <x-portal.notice>You haven't checked in yet.</x-portal.notice>
            @else
                @include('livewire.portal.partials.check-in-rows', ['items' => $checkIns])
                <a href="{{ route('portal.status.check-ins') }}" class="mt-2 inline-flex min-h-11 items-center text-accent-ink underline-offset-4 hover:underline">All check-ins</a>
            @endif
        </section>
    @endif
</div>
