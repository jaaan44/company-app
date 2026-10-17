<div>
    <x-portal.back :href="route('portal.work-logs')">My work logs</x-portal.back>
    <h1 class="sr-only">{{ $publicId === null ? 'Log work' : 'Edit work log' }}</h1>

    @if ($noProfile)
        <x-portal.no-profile />
    @elseif ($notFound)
        <x-portal.notice>This work log is no longer available.</x-portal.notice>
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @else
        <form wire:submit="save" class="space-y-5 rounded-xl border border-line bg-raised p-5" novalidate>
            @if ($generalError)
                <p role="alert" class="font-medium text-danger">{{ $generalError }}</p>
            @endif

            <div>
                @if ($fixedLabel !== null)
                    <p class="mb-1 text-sm font-semibold">What was this for?</p>
                    <p class="font-medium">{{ $fixedLabel }}</p>
                    @if ($fixedProject)
                        <p class="text-sm text-muted">{{ $fixedProject }}</p>
                    @endif
                @else
                    <label for="target" class="mb-1 block text-sm font-semibold">What was this for?</label>
                    @if (count($tasks) === 0 && count($projects) === 0)
                        <p class="text-muted">You aren't a member of any open project.</p>
                    @endif
                    <select id="target" wire:model="target" @error('target') aria-invalid="true" aria-describedby="target-error" @enderror
                            class="field w-full">
                        <option value="">Choose a task or project</option>
                        @if (count($tasks) > 0)
                            <optgroup label="My open tasks">
                                @foreach ($tasks as $task)
                                    <option value="task:{{ $task['public_id'] }}">{{ $task['title'] }}{{ ($task['project']['name'] ?? null) ? ' — '.$task['project']['name'] : '' }}</option>
                                @endforeach
                            </optgroup>
                        @endif
                        @if (count($projects) > 0)
                            <optgroup label="My projects">
                                @foreach ($projects as $project)
                                    <option value="project:{{ $project['public_id'] }}">{{ $project['name'] }}{{ $project['project_code'] ? ' ('.$project['project_code'].')' : '' }}</option>
                                @endforeach
                            </optgroup>
                        @endif
                    </select>
                    @error('target') <p id="target-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                @endif
            </div>

            <div>
                <label for="work-date" class="mb-1 block text-sm font-semibold">Date worked</label>
                <input id="work-date" type="date" wire:model="workDate" max="{{ $companyDate }}" min="{{ $minDate }}"
                       @error('workDate') aria-invalid="true" aria-describedby="date-error" @enderror
                       class="field">
                @error('workDate') <p id="date-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
            </div>

            <fieldset>
                <legend class="mb-1 text-sm font-semibold">Time spent</legend>
                <div class="flex flex-wrap gap-4">
                    <label class="flex items-center gap-2">
                        <input type="number" inputmode="numeric" min="0" max="24" wire:model="hours" @error('duration') aria-invalid="true" aria-describedby="duration-error" @enderror
                               class="field w-24"> Hours
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="number" inputmode="numeric" min="0" max="59" wire:model="minutes" @error('duration') aria-invalid="true" aria-describedby="duration-error" @enderror
                               class="field w-24"> Minutes
                    </label>
                </div>
                @error('duration') <p id="duration-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
            </fieldset>

            <div>
                <label for="description" class="mb-1 block text-sm font-semibold">What did you do?</label>
                <textarea id="description" wire:model="description" rows="4" maxlength="{{ \App\Livewire\Portal\WorkLogForm::MAX_DESCRIPTION }}"
                          @error('description') aria-invalid="true" aria-describedby="description-error" @enderror
                          class="field w-full"></textarea>
                @error('description') <p id="description-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" wire:loading.attr="disabled" wire:target="save,delete"
                        class="btn btn-primary">
                    {{ $publicId === null ? 'Save' : 'Save changes' }}
                </button>
                <a href="{{ route('portal.work-logs') }}" class="btn btn-plain">Cancel</a>
                @if ($publicId !== null)
                    <x-portal.confirm title="Delete this work log?" body="This can't be undone." confirm="Delete" action="delete()" :danger="true"
                                      class="btn btn-danger-outline ml-auto">Delete</x-portal.confirm>
                @endif
            </div>
        </form>
    @endif
</div>
