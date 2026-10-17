@php use App\Support\Portal\Format; use App\Livewire\Admin\LeaveRequestRecord; @endphp
<div>
    <x-portal.back :href="route('admin.leave-requests')">Leave requests</x-portal.back>

    @if ($notFound)
        <h1 class="sr-only">Leave request</h1>
        <x-portal.notice>This request no longer exists.</x-portal.notice>
    @elseif ($errorMessage && ! $request)
        <h1 class="sr-only">Leave request</h1>
        <x-portal.error :message="$errorMessage" />
    @elseif ($request)
        <h1 class="text-xl font-semibold">{{ $request['staff']['display_name'] ?? '' }} — {{ $request['leave_type']['name'] }}</h1>
        <p class="mt-2 flex flex-wrap items-center gap-3">
            <span>{{ ($request['leave_type']['is_paid'] ?? false) ? 'Paid' : 'Unpaid' }}</span>
            <x-portal.chip :tone="Format::tone($request['status'])">{{ Format::leaveStatus($request['status']) }}</x-portal.chip>
        </p>

        @if ($generalError)
            <p role="alert" class="mt-4 rounded-md bg-danger-soft p-3 font-medium text-danger">{{ $generalError }}</p>
        @endif

        <section aria-labelledby="details" class="mt-6 rounded-xl border border-line bg-raised px-5">
            <h2 id="details" class="pt-4 font-semibold">Details</h2>
            <dl>
                <x-portal.info-row label="Employee number" :value="$request['staff']['employee_number'] ?? null" />
                <x-portal.info-row :label="Format::calendarDays((int) $request['total_days'])" :value="Format::leaveDates($request['start_date'], $request['end_date'])" />
                <div class="border-b border-line py-3 last:border-0">
                    <dt class="text-sm text-muted">Reason</dt>
                    <dd class="mt-1 whitespace-pre-line font-medium">{{ $request['reason'] }}</dd>
                </div>
            </dl>
        </section>

        @if ($canDecide || $canCancel)
            <section aria-label="Actions" class="mt-6 rounded-xl border border-line bg-raised p-5">
                @if ($confirming === null)
                    <div class="flex flex-wrap gap-3">
                        @if ($canDecide)
                            <button type="button" wire:click="ask('approve')" class="btn btn-primary">Approve</button>
                            <button type="button" wire:click="ask('reject')" class="btn btn-danger-outline">Reject</button>
                        @endif
                        @if ($canCancel)
                            <button type="button" wire:click="ask('cancel')" class="btn btn-danger-outline">Cancel request</button>
                        @endif
                    </div>
                @else
                    @php
                        [$title, $label, $button] = match ($confirming) {
                            'approve' => ['Approve this request?', 'Note (optional)', 'Approve'],
                            'reject' => ['Reject this request?', 'Reason', 'Reject'],
                            default => ['Cancel this request?', 'Reason (optional)', 'Cancel request'],
                        };
                    @endphp
                    <form wire:submit="confirm" novalidate x-data x-init="$nextTick(() => $refs.text.focus())">
                        <h2 class="font-semibold">{{ $title }}</h2>
                        <p class="mt-1 text-sm text-muted">{{ $request['staff']['display_name'] ?? '' }} sees this in the app, with your note.</p>
                        <label for="confirm-text" class="mb-1 mt-3 block text-sm font-semibold">{{ $label }}</label>
                        <textarea id="confirm-text" x-ref="text" wire:model="text" rows="3" maxlength="{{ LeaveRequestRecord::MAX_TEXT }}"
                                  @error('text') aria-invalid="true" aria-describedby="text-error" @enderror
                                  class="field w-full"></textarea>
                        @error('text') <p id="text-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                        <div class="mt-3 flex flex-wrap gap-3">
                            <button type="submit" class="btn {{ $confirming === 'approve' ? 'btn-primary' : 'btn-danger' }}">{{ $button }}</button>
                            <button type="button" wire:click="dismiss" class="btn btn-text">Back</button>
                        </div>
                    </form>
                @endif
            </section>
        @endif

        <section aria-labelledby="history" class="mt-6 rounded-xl border border-line bg-raised p-5">
            <h2 id="history" class="mb-2 font-semibold">History</h2>
            @if (count($request['history'] ?? []) === 0)
                <p class="text-muted">No history yet.</p>
            @else
                <ol class="divide-y divide-line">
                    @foreach ($request['history'] as $step)
                        <li class="py-2">
                            <span class="block">
                                {{ Format::leaveAction($step['action']) }}@if ($step['acted_by']['display_name'] ?? null) by {{ $step['acted_by']['display_name'] }}@endif
                                @if ($step['created_at'] ?? null) · {{ Format::companyTime($step['created_at'], $companyDate, $utcOffset) }} @endif
                            </span>
                            @if (filled($step['note'] ?? null))
                                <span class="block whitespace-pre-line text-sm text-muted">{{ $step['note'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        <x-portal.toast :message="$savedMessage" :nonce="$toastNonce" />
    @endif
</div>
