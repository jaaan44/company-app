@php use App\Support\Portal\Format; use App\Livewire\Portal\ProjectForm; @endphp
<div>
    @if ($publicId === null)
        <x-portal.back :href="route('portal.projects')">Projects</x-portal.back>
    @else
        <x-portal.back :href="route('portal.project', $publicId)">Project</x-portal.back>
    @endif
    <h1 class="sr-only">{{ $publicId === null ? 'New project' : 'Edit project' }}</h1>

    @if ($notFound)
        <x-portal.notice>This project no longer exists.</x-portal.notice>
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @else
        <form wire:submit="save" class="space-y-5 rounded-xl border border-line bg-raised p-5" novalidate>
            @if ($generalError)
                <p role="alert" class="font-medium text-danger">{{ $generalError }}</p>
            @endif

            @include('livewire.portal.partials.field', ['id' => 'name', 'label' => 'Name', 'model' => 'name', 'error' => 'name', 'max' => 255])

            <div class="grid gap-5 sm:grid-cols-2">
                @include('livewire.portal.partials.field', ['id' => 'code', 'label' => 'Project code', 'model' => 'code', 'error' => 'code', 'max' => 50, 'optional' => true])
                <div>
                    <label for="client" class="mb-1 block text-sm font-semibold">Client <span class="font-normal text-muted">(optional)</span></label>
                    <select id="client" wire:model="client" @error('client') aria-invalid="true" aria-describedby="client-error" @enderror
                            class="field w-full">
                        <option value="">No client</option>
                        @foreach ($clients as $c)
                            <option value="{{ $c['public_id'] }}">{{ $c['name'] }}</option>
                        @endforeach
                    </select>
                    @error('client') <p id="client-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="status" class="mb-1 block text-sm font-semibold">Status</label>
                <select id="status" wire:model.live="status" class="field w-full sm:max-w-xs">
                    @foreach (ProjectForm::STATUSES as $value)
                        <option value="{{ $value }}">{{ Format::projectStatus($value) }}</option>
                    @endforeach
                </select>
                @error('status') <p class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-5 sm:grid-cols-3">
                @include('livewire.portal.partials.field', ['id' => 'start-date', 'label' => 'Start date', 'model' => 'startDate', 'error' => 'startDate', 'type' => 'date', 'optional' => true])
                @include('livewire.portal.partials.field', ['id' => 'target-end-date', 'label' => 'Target end date', 'model' => 'targetEndDate', 'error' => 'targetEndDate', 'type' => 'date', 'optional' => true])
                @if ($status === 'completed' || $completedDate !== '')
                    @include('livewire.portal.partials.field', ['id' => 'completed-date', 'label' => 'Completed on', 'model' => 'completedDate', 'error' => 'completedDate', 'type' => 'date'])
                @endif
            </div>

            @include('livewire.portal.partials.field', ['id' => 'description', 'label' => 'Description', 'model' => 'description', 'error' => 'description', 'max' => 5000, 'textarea' => true, 'optional' => true])
            @include('livewire.portal.partials.field', ['id' => 'notes', 'label' => 'Notes', 'model' => 'notes', 'error' => 'notes', 'max' => 2000, 'textarea' => true, 'rows' => 3, 'optional' => true])

            <div class="flex flex-wrap gap-2">
                <button type="submit" wire:loading.attr="disabled" wire:target="save"
                        class="btn btn-primary">
                    {{ $publicId === null ? 'Add project' : 'Save changes' }}
                </button>
                <a href="{{ $publicId === null ? route('portal.projects') : route('portal.project', $publicId) }}" class="btn btn-text">Cancel</a>
            </div>
        </form>
    @endif
</div>
