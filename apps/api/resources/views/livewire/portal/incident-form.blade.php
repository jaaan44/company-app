@php
    use App\Livewire\Portal\IncidentForm as F;
    use App\Support\Portal\Format;
@endphp
<div>
    <x-portal.back :href="$publicId ? route('portal.incident', $publicId) : route('portal.reports', ['type' => 'incidents'])">{{ $publicId ? 'Incident' : 'Reports' }}</x-portal.back>
    <h1 class="sr-only">{{ $publicId === null ? 'Report an incident' : 'Edit incident' }}</h1>

    @if ($noProfile)
        <p class="rounded-lg border border-line bg-raised p-4">No staff profile is linked to this account.</p>
    @elseif ($notFound)
        <p class="rounded-lg border border-line bg-raised p-4">This incident can no longer be edited here.</p>
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @else
        <form wire:submit="save" class="space-y-5 rounded-xl border border-line bg-raised p-5" novalidate>
            @if ($generalError)
                <p role="alert" class="font-medium text-danger">{{ $generalError }}</p>
            @endif

            <div>
                <label for="incident-type" class="mb-1 block text-sm font-semibold">Type of incident</label>
                <select id="incident-type" wire:model="type" @error('type') aria-invalid="true" aria-describedby="incident-type-error" @enderror
                        class="min-h-11 w-full rounded-md border border-line bg-raised px-3">
                    <option value="">Choose a type</option>
                    @foreach (F::TYPES as $t)
                        <option value="{{ $t }}">{{ Format::incidentType($t) }}</option>
                    @endforeach
                </select>
                @error('type') <p id="incident-type-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
            </div>

            <fieldset>
                <legend class="mb-1 text-sm font-semibold">Severity</legend>
                <div class="flex flex-wrap gap-2">
                    @foreach (F::SEVERITIES as $s)
                        <label class="inline-flex min-h-11 items-center gap-2 rounded-full border border-line px-4 has-[:checked]:border-accent has-[:checked]:bg-accent-soft has-[:checked]:font-medium">
                            <input type="radio" name="severity" value="{{ $s }}" wire:model="severity" class="size-5"> {{ Format::severity($s) }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <fieldset>
                <legend class="mb-1 text-sm font-semibold">When it happened (company time)</legend>
                <div class="flex flex-wrap gap-4">
                    <label class="flex flex-col text-sm">Date
                        <input type="date" wire:model="date" max="{{ $maxDate }}" @error('occurredAt') aria-invalid="true" aria-describedby="occurred-error" @enderror
                               class="min-h-11 rounded-md border border-line bg-raised px-3 text-base">
                    </label>
                    <label class="flex flex-col text-sm">Time
                        <input type="time" wire:model="time" @error('occurredAt') aria-invalid="true" aria-describedby="occurred-error" @enderror
                               class="min-h-11 rounded-md border border-line bg-raised px-3 text-base">
                    </label>
                </div>
                @error('occurredAt') <p id="occurred-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
            </fieldset>

            @include('livewire.portal.partials.report-field', ['id' => 'location', 'label' => 'Location (optional)', 'model' => 'location', 'error' => 'location', 'input' => 'text', 'max' => F::MAX_SHORT])
            @include('livewire.portal.partials.report-field', ['id' => 'description', 'label' => 'What happened', 'model' => 'description', 'error' => 'description', 'max' => F::MAX_LONG, 'rows' => 5])
            @include('livewire.portal.partials.report-field', ['id' => 'immediate', 'label' => 'Immediate action taken (optional)', 'model' => 'immediateAction', 'error' => 'immediateAction', 'max' => F::MAX_TEXT])
            @include('livewire.portal.partials.report-field', ['id' => 'people', 'label' => 'People involved (optional)', 'model' => 'peopleInvolved', 'error' => 'peopleInvolved', 'max' => F::MAX_TEXT])
            @include('livewire.portal.partials.report-field', ['id' => 'witnesses', 'label' => 'Witness notes (optional)', 'model' => 'witnessNotes', 'error' => 'witnessNotes', 'max' => F::MAX_TEXT])

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" wire:loading.attr="disabled" wire:target="save"
                        class="inline-flex min-h-11 items-center rounded-md bg-accent px-5 font-medium text-on-accent hover:opacity-90 disabled:opacity-60">{{ $publicId === null ? 'Report incident' : 'Save changes' }}</button>
                <a href="{{ $publicId ? route('portal.incident', $publicId) : route('portal.reports', ['type' => 'incidents']) }}" class="inline-flex min-h-11 items-center rounded-md px-4 hover:bg-hover">Cancel</a>
            </div>
        </form>
    @endif
</div>
