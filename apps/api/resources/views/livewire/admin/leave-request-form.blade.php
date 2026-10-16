<div>
    <x-portal.back :href="route('admin.leave-requests')">Leave requests</x-portal.back>
    <h1 class="sr-only">Request leave for someone</h1>

    @if ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @else
        <form wire:submit="save" class="space-y-5 rounded-xl border border-line bg-raised p-5" novalidate>
            @if ($generalError)
                <p role="alert" class="font-medium text-danger">{{ $generalError }}</p>
            @endif
            @include('livewire.admin.partials.staff-picker', ['label' => 'Person'])
            <div class="grid gap-5 sm:grid-cols-3">
                <div>
                    <label for="lr-type" class="mb-1 block text-sm font-semibold">Type of leave</label>
                    <select id="lr-type" wire:model="typeId" class="min-h-11 w-full rounded-md border border-line bg-raised px-3"
                            @error('typeId') aria-invalid="true" aria-describedby="lr-type-error" @enderror>
                        <option value="">Choose…</option>
                        @foreach ($types as $type)
                            <option value="{{ $type['public_id'] }}">{{ $type['name'] }}{{ $type['is_paid'] ? '' : ' (unpaid)' }}</option>
                        @endforeach
                    </select>
                    @error('typeId') <p id="lr-type-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                </div>
                @include('livewire.portal.partials.field', ['id' => 'lr-start', 'label' => 'First day', 'model' => 'startDate', 'error' => 'startDate', 'type' => 'date'])
                @include('livewire.portal.partials.field', ['id' => 'lr-end', 'label' => 'Last day', 'model' => 'endDate', 'error' => 'endDate', 'type' => 'date'])
            </div>
            @include('livewire.portal.partials.field', ['id' => 'lr-reason', 'label' => 'Reason', 'model' => 'reason', 'error' => 'reason', 'textarea' => true, 'rows' => 3, 'max' => 1000])
            <p class="text-sm text-muted">The request starts as Pending, and their manager or an Administrator decides it.</p>
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="inline-flex min-h-11 items-center rounded-full bg-accent px-5 font-medium text-on-accent hover:opacity-90">Request leave</button>
                <a href="{{ route('admin.leave-requests') }}" class="inline-flex min-h-11 items-center rounded-full px-5 font-medium text-accent-ink hover:bg-hover">Cancel</a>
            </div>
        </form>
    @endif
</div>
