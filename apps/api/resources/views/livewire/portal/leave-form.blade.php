@php
    use App\Support\Portal\Format;
    use App\Livewire\Portal\LeaveForm;
@endphp
<div>
    <x-portal.back :href="route('portal.leave')">Leave</x-portal.back>
    <h1 class="mb-4 text-2xl font-semibold">Request leave</h1>

    @if ($noProfile)
        <p class="rounded-lg border border-line bg-raised p-4">No staff profile is linked to this account.</p>
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @else
        <form wire:submit="submit" class="space-y-5 rounded-xl border border-line bg-raised p-5" novalidate>
            @if ($generalError)
                <p role="alert" class="font-medium text-danger">{{ $generalError }}</p>
            @endif

            <div>
                <label for="type" class="mb-1 block text-sm font-semibold">Type of leave</label>
                <select id="type" wire:model.live="type" aria-describedby="type-hint @error('type') type-error @enderror" @error('type') aria-invalid="true" @enderror
                        class="min-h-11 w-full rounded-md border border-line bg-raised px-3">
                    <option value="">Choose a type</option>
                    @foreach ($balances as $balance)
                        <option value="{{ $balance['leave_type']['public_id'] }}">{{ $balance['leave_type']['name'] }} — {{ ($balance['is_paid'] ?? false) ? Format::days((int) ($balance['remaining_days'] ?? 0)).' left in '.$balance['year'] : 'Unpaid' }}</option>
                    @endforeach
                </select>
                <p id="type-hint" class="mt-1 text-sm text-muted">{{ $hint }}</p>
                @error('type') <p id="type-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-wrap gap-4">
                <div>
                    <label for="start-date" class="mb-1 block text-sm font-semibold">First day</label>
                    <input id="start-date" type="date" wire:model.live="startDate" min="{{ $companyDate }}"
                           @error('startDate') aria-invalid="true" aria-describedby="start-error" @enderror
                           class="min-h-11 rounded-md border border-line bg-raised px-3">
                    @error('startDate') <p id="start-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="end-date" class="mb-1 block text-sm font-semibold">Last day</label>
                    <input id="end-date" type="date" wire:model.live="endDate" min="{{ $startDate !== '' ? $startDate : $companyDate }}"
                           @error('endDate') aria-invalid="true" aria-describedby="end-error" @enderror
                           class="min-h-11 rounded-md border border-line bg-raised px-3">
                    @error('endDate') <p id="end-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                </div>
            </div>
            <p aria-live="polite" class="text-sm text-muted">{{ $days !== null ? Format::calendarDays($days) : '' }}</p>

            <div>
                <label for="reason" class="mb-1 block text-sm font-semibold">Reason</label>
                <textarea id="reason" wire:model="reason" rows="4" maxlength="{{ LeaveForm::MAX_REASON }}"
                          @error('reason') aria-invalid="true" aria-describedby="reason-error" @enderror
                          class="w-full rounded-md border border-line bg-raised px-3 py-2"></textarea>
                @error('reason') <p id="reason-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" wire:loading.attr="disabled" wire:target="submit"
                        class="inline-flex min-h-11 items-center rounded-md bg-accent px-5 font-medium text-on-accent hover:opacity-90 disabled:opacity-60">Send request</button>
                <a href="{{ route('portal.leave') }}" class="inline-flex min-h-11 items-center rounded-md px-4 hover:bg-hover">Cancel</a>
            </div>
            <p class="text-sm text-muted">Your manager approves or rejects it. You can cancel it while it's pending, or approved and not yet started.</p>
        </form>
    @endif
</div>
