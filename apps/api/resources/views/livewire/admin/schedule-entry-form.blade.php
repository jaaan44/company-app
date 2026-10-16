@php use App\Livewire\Admin\ScheduleEntryForm; @endphp
<div>
    <x-portal.back :href="route('admin.schedule')">Schedule</x-portal.back>
    <h1 class="sr-only">{{ $publicId === null ? 'New schedule entry' : 'Edit schedule entry' }}</h1>

    @if ($notFound)
        <p class="rounded-lg border border-line bg-raised p-4">This entry no longer exists.</p>
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @else
        @php($control = 'min-h-11 w-full rounded-md border border-line bg-raised px-3')
        <form wire:submit="save" class="space-y-5 rounded-xl border border-line bg-raised p-5" novalidate>
            @if ($generalError)
                <p role="alert" class="font-medium text-danger">{{ $generalError }}</p>
            @endif
            <div class="grid gap-5 sm:grid-cols-2">
                @include('livewire.portal.partials.field', ['id' => 'se-title', 'label' => 'Title', 'model' => 'title', 'error' => 'title', 'max' => 255])
                <div>
                    <label for="se-type" class="mb-1 block text-sm font-semibold">Activity</label>
                    <select id="se-type" wire:model="activityType" class="{{ $control }}">
                        @foreach (ScheduleEntryForm::TYPES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <label class="flex min-h-11 items-center gap-3">
                <input type="checkbox" wire:model.live="allDay" class="size-5 accent-[var(--color-accent)]"> <span class="font-semibold">All day</span>
            </label>
            <fieldset class="grid gap-5 sm:grid-cols-4">
                <legend class="sr-only">When (company time)</legend>
                @include('livewire.portal.partials.field', ['id' => 'se-start-date', 'label' => 'Starts', 'model' => 'startDate', 'error' => 'startDate', 'type' => 'date'])
                @unless ($allDay)
                    @include('livewire.portal.partials.field', ['id' => 'se-start-time', 'label' => 'Start time', 'model' => 'startTime', 'error' => 'startTime', 'type' => 'time'])
                @endunless
                @include('livewire.portal.partials.field', ['id' => 'se-end-date', 'label' => 'Ends', 'model' => 'endDate', 'error' => 'endDate', 'type' => 'date'])
                @unless ($allDay)
                    @include('livewire.portal.partials.field', ['id' => 'se-end-time', 'label' => 'End time', 'model' => 'endTime', 'error' => 'endTime', 'type' => 'time'])
                @endunless
            </fieldset>
            @if ($publicId === null)
                <div>
                    <label for="se-project" class="mb-1 block text-sm font-semibold">Project <span class="font-normal text-muted">(optional; can't be changed later)</span></label>
                    <select id="se-project" wire:model="projectId" class="{{ $control }} sm:max-w-md" @error('projectId') aria-invalid="true" aria-describedby="se-project-error" @enderror>
                        <option value="">None</option>
                        @foreach ($projects as $project)
                            <option value="{{ $project['public_id'] }}">{{ $project['name'] }}</option>
                        @endforeach
                    </select>
                    @error('projectId') <p id="se-project-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                </div>
            @elseif ($projectName !== '')
                <p><span class="text-sm text-muted">Project:</span> <span class="font-medium">{{ $projectName }}</span></p>
            @endif

            <div>
                @include('livewire.admin.partials.staff-picker', ['label' => 'Add people', 'hint' => 'They see this entry in their schedule.'])
                @if ($participants !== [])
                    <ul class="mt-3 flex flex-wrap gap-2" aria-label="People">
                        @foreach ($participants as $id => $name)
                            <li wire:key="participant-{{ $id }}" class="inline-flex items-center gap-1 rounded-full border border-line pl-3">
                                {{ $name }}
                                <button type="button" wire:click="removeParticipant(@js($id))" class="inline-flex min-h-11 items-center rounded-full px-3 text-accent-ink hover:bg-hover">Remove<span class="sr-only"> {{ $name }}</span></button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            @include('livewire.portal.partials.field', ['id' => 'se-description', 'label' => 'Description', 'model' => 'description', 'error' => 'description', 'textarea' => true, 'rows' => 3, 'max' => 5000, 'optional' => true])

            <div class="flex flex-wrap items-center gap-2">
                <button type="submit" class="inline-flex min-h-11 items-center rounded-full bg-accent px-5 font-medium text-on-accent hover:opacity-90">{{ $publicId === null ? 'Add entry' : 'Save changes' }}</button>
                <a href="{{ route('admin.schedule') }}" class="inline-flex min-h-11 items-center rounded-full px-5 font-medium text-accent-ink hover:bg-hover">Cancel</a>
                @if ($publicId !== null)
                    <x-portal.confirm title="Delete this schedule entry?" body="It disappears from everyone's schedule. This can't be undone." confirm="Delete" action="delete()" :danger="true"
                                      class="ml-auto inline-flex min-h-11 items-center rounded-full border border-line px-4 font-medium text-danger hover:bg-hover">Delete</x-portal.confirm>
                @endif
            </div>
        </form>
    @endif
</div>
