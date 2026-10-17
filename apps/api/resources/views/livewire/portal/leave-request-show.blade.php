@php use App\Support\Portal\Format; @endphp
<div>
    @if ($viewer === 'team')
        <x-portal.back :href="route('portal.leave.team')">Team requests</x-portal.back>
    @else
        <x-portal.back :href="route('portal.leave')">Leave</x-portal.back>
    @endif

    @if ($noProfile)
        <x-portal.no-profile />
    @elseif ($errorMessage)
        <h1 class="sr-only">{{ $viewer === 'team' ? 'Team request' : 'Leave request' }}</h1>
        @if ($errorMessage === 'This request is no longer available.' || $errorMessage === "You don't have access to this request.")
            <x-portal.notice>{{ $errorMessage }}</x-portal.notice>
        @else
            <x-portal.error :message="$errorMessage" />
        @endif
    @elseif ($request)
        <h1 class="text-2xl font-semibold">{{ $request['leave_type']['name'] }}</h1>
        <p class="mt-2 flex flex-wrap items-center gap-3">
            <span>{{ ($request['leave_type']['is_paid'] ?? false) ? 'Paid' : 'Unpaid' }}</span>
            <x-portal.chip :tone="Format::tone($request['status'])">{{ Format::leaveStatus($request['status']) }}</x-portal.chip>
        </p>

        <div aria-live="polite">
            <x-portal.toast :message="$doneMessage" :nonce="$toastNonce ?? 0" />
            @if ($actionMessage)
                <p role="alert" class="mt-4 rounded-md bg-danger-soft p-3 font-medium text-danger">{{ $actionMessage }}</p>
            @endif
        </div>

        <section aria-labelledby="request-details" class="mt-6 rounded-xl border border-line bg-raised px-5">
            <h2 id="request-details" class="pt-4 font-semibold">Details</h2>
            <dl>
                @if ($viewer === 'team')
                    <x-portal.info-row label="Requested by" :value="$request['staff']['display_name'] ?? null" />
                @endif
                <x-portal.info-row :label="Format::calendarDays((int) $request['total_days'])" :value="Format::leaveDates($request['start_date'], $request['end_date'])" />
                <div class="border-b border-line py-3 last:border-0">
                    <dt class="text-sm text-muted">Reason</dt>
                    <dd class="mt-1 whitespace-pre-line font-medium">{{ $request['reason'] }}</dd>
                </div>
            </dl>
        </section>

        @if ($canCancel || $canDecide)
            <section aria-label="Actions" class="mt-6 rounded-xl border border-line bg-raised p-5">
                @if ($confirming === null)
                    <div class="flex flex-wrap gap-3">
                        @if ($canCancel)
                            <button type="button" wire:click="ask('cancel')" class="btn btn-danger-outline">Cancel request</button>
                        @endif
                        @if ($canDecide)
                            <button type="button" wire:click="ask('approve')" class="btn btn-primary">Approve</button>
                            <button type="button" wire:click="ask('reject')" class="btn btn-danger-outline">Reject</button>
                        @endif
                    </div>
                @else
                    @php
                        $title = match ($confirming) {
                            'cancel' => 'Cancel this request?',
                            'approve' => 'Approve this request?',
                            default => 'Reject this request?',
                        };
                        $label = match ($confirming) {
                            'cancel' => 'Reason (optional)',
                            'approve' => 'Note (optional)',
                            default => 'Reason',
                        };
                        $confirmLabel = match ($confirming) {
                            'cancel' => 'Cancel request',
                            'approve' => 'Approve',
                            default => 'Reject',
                        };
                    @endphp
                    <form wire:submit="confirm" novalidate x-data x-init="$nextTick(() => $refs.text.focus())">
                        <h2 id="confirm-title" class="font-semibold">{{ $title }}</h2>
                        @if ($confirming === 'cancel')
                            <p class="mt-1">{{ $request['status'] === 'approved' ? 'Your approved leave will be cancelled.' : 'Your manager will no longer see it as pending.' }}</p>
                        @endif
                        <label for="confirm-text" class="mb-1 mt-3 block text-sm font-semibold">{{ $label }}</label>
                        <textarea id="confirm-text" x-ref="text" wire:model="text" rows="3" maxlength="{{ \App\Livewire\Portal\LeaveRequestShow::MAX_TEXT }}"
                                  @error('text') aria-invalid="true" aria-describedby="text-error" @enderror
                                  class="field w-full"></textarea>
                        @error('text') <p id="text-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                        <div class="mt-3 flex flex-wrap gap-3">
                            <button type="submit" wire:loading.attr="disabled" wire:target="confirm"
                                    class="btn {{ $confirming === 'approve' ? 'btn-primary' : 'btn-danger' }}">{{ $confirmLabel }}</button>
                            <button type="button" wire:click="dismiss" class="btn btn-secondary">{{ $confirming === 'cancel' ? 'Keep it' : 'Back' }}</button>
                        </div>
                    </form>
                @endif
            </section>
        @endif

        <section aria-labelledby="request-history" class="mt-6 rounded-xl border border-line bg-raised p-5">
            <h2 id="request-history" class="mb-2 font-semibold">History</h2>
            @if (count($request['history'] ?? []) === 0)
                <p class="text-muted">No history yet.</p>
            @else
                <ol class="divide-y divide-line">
                    @foreach ($request['history'] as $step)
                        @php
                            $who = $step['acted_by']['display_name'] ?? null;
                            $label = Format::leaveAction($step['action']).($who ? ' by '.$who : '');
                            if (($step['created_at'] ?? null) && $companyDay) {
                                $label .= ' · '.Format::companyTime($step['created_at'], $companyDay['date'], $companyDay['utc_offset']);
                            }
                        @endphp
                        <li class="py-2">
                            <span class="block">{{ $label }}</span>
                            @if (filled($step['note'] ?? null))
                                <span class="block whitespace-pre-line text-sm text-muted">{{ $step['note'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    @endif
</div>
