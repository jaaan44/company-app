<div>
    <x-portal.back :href="route('admin.section', 'hr')">HR</x-portal.back>
    <h1 class="sr-only">Leave balances</h1>

    <div class="mb-4 flex flex-wrap items-end gap-4 rounded-xl border border-line bg-raised p-5">
        <div class="min-w-0 flex-1 basis-72">
            @include('livewire.admin.partials.staff-picker', ['label' => 'Person'])
        </div>
        <div>
            <label for="lb-year" class="mb-1 block text-sm font-semibold">Year</label>
            <input id="lb-year" type="number" min="2000" max="2100" wire:model.live.debounce.500ms="year" class="min-h-11 w-28 rounded-md border border-line bg-surface px-3">
        </div>
    </div>

    @if ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif ($staffId === '')
        <p class="rounded-xl border border-line bg-raised p-5 text-muted">Choose a person to see their allowances for {{ $year }}.</p>
    @elseif ($balances !== null)
        @if ($generalError)
            <p role="alert" class="mb-4 font-medium text-danger">{{ $generalError }}</p>
        @endif
        <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-raised">
            @forelse ($balances as $row)
                @php($typeId = $row['leave_type']['public_id'])
                <li wire:key="lb-{{ $typeId }}-{{ $year }}" class="px-5 py-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                        <span class="font-medium">{{ $row['leave_type']['name'] }}</span>
                        <span class="text-sm text-muted">
                            @if ($row['is_paid'])
                                {{ $row['remaining_days'] ?? 0 }} left · {{ $row['used_days'] }} used · {{ $row['pending_days'] }} pending
                            @else
                                Unpaid — no balance · {{ $row['used_days'] }} used · {{ $row['pending_days'] }} pending
                            @endif
                        </span>
                    </div>
                    @if ($row['is_paid'] && $canSet)
                        <form wire:submit="saveAllowance(@js($typeId))" class="mt-3 flex flex-wrap items-end gap-3" novalidate>
                            <div>
                                <label for="alloc-{{ $typeId }}" class="block text-sm font-medium">Allowance (days) for {{ $year }}</label>
                                <input id="alloc-{{ $typeId }}" type="number" min="0" max="365" wire:model="allocated.{{ $typeId }}"
                                       @error('allocated.'.$typeId) aria-invalid="true" aria-describedby="alloc-{{ $typeId }}-error" @enderror
                                       class="mt-1 min-h-11 w-28 rounded-md border border-line bg-surface px-3">
                            </div>
                            <div class="min-w-0 flex-1 basis-48">
                                <label for="notes-{{ $typeId }}" class="block text-sm font-medium">Notes <span class="font-normal text-muted">(optional)</span></label>
                                <input id="notes-{{ $typeId }}" type="text" maxlength="500" wire:model="notes.{{ $typeId }}" class="mt-1 min-h-11 w-full rounded-md border border-line bg-surface px-3">
                            </div>
                            <button type="submit" class="inline-flex min-h-11 items-center rounded-full border border-line px-4 font-medium text-accent-ink hover:bg-hover">Save<span class="sr-only"> {{ $row['leave_type']['name'] }} allowance</span></button>
                            @error('allocated.'.$typeId) <p id="alloc-{{ $typeId }}-error" class="w-full text-sm font-medium text-danger">{{ $message }}</p> @enderror
                        </form>
                    @endif
                </li>
            @empty
                <li class="px-5 py-4 text-muted">No active leave types.</li>
            @endforelse
        </ul>
    @endif

    <x-portal.toast :message="$savedMessage" :nonce="$toastNonce" />
</div>
