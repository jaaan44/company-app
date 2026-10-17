@php use App\Support\Portal\Format; use App\Livewire\Portal\TaskForm; @endphp
<div>
    @if ($publicId === null)
        <x-portal.back :href="route('portal.tasks')">Tasks</x-portal.back>
    @else
        <x-portal.back :href="route('portal.task', $publicId)">Task</x-portal.back>
    @endif
    <h1 class="sr-only">{{ $publicId === null ? 'New task' : 'Edit task' }}</h1>

    @if ($notFound)
        <x-portal.notice>This task is no longer available.</x-portal.notice>
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif ($publicId === null && ! $allowNoProject && count($projects) === 0)
        <x-portal.notice>You can add tasks only to projects you lead, and you don't lead any open project.</x-portal.notice>
    @else
        <form wire:submit="save" class="space-y-5 rounded-xl border border-line bg-raised p-5" novalidate>
            @if ($generalError)
                <p role="alert" class="font-medium text-danger">{{ $generalError }}</p>
            @endif

            @include('livewire.portal.partials.field', ['id' => 'title', 'label' => 'Title', 'model' => 'title', 'error' => 'title', 'max' => 255])

            @if ($publicId !== null)
                <div>
                    <p class="mb-1 text-sm font-semibold">Project</p>
                    <p class="font-medium">{{ $fixedProjectName }}</p>
                    <p class="text-sm text-muted">A task stays in its project.</p>
                </div>
            @else
            <div>
                <label for="project" class="mb-1 block text-sm font-semibold">Project</label>
                <select id="project" wire:model="project" @error('project') aria-invalid="true" aria-describedby="project-error" @enderror
                        class="field w-full">
                    @if ($allowNoProject)
                        <option value="">No project (independent task)</option>
                    @else
                        <option value="">Choose a project you lead</option>
                    @endif
                    @foreach ($projects as $p)
                        <option value="{{ $p['public_id'] }}">{{ $p['name'] }}</option>
                    @endforeach
                </select>
                @error('project') <p id="project-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
            </div>
            @endif

            <div>
                <label for="assignee-search" class="mb-1 block text-sm font-semibold">Assigned to</label>
                @if ($assigneeId !== '')
                    <p class="flex flex-wrap items-center gap-3">
                        <span class="font-medium">{{ $assigneeName }}</span>
                        <button type="button" wire:click="clearAssignee" class="btn btn-text btn-compact">Remove<span class="sr-only"> {{ $assigneeName }} as assignee</span></button>
                    </p>
                @endif
                <input id="assignee-search" type="search" wire:model.live.debounce.400ms="assigneeSearch" autocomplete="off"
                       placeholder="{{ $assigneeId === '' ? 'Search by name (optional)' : 'Search to change' }}"
                       @error('assigneeSearch') aria-invalid="true" aria-describedby="assignee-error" @enderror
                       class="field w-full">
                @error('assigneeSearch') <p id="assignee-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                @if ($staffSearchFailed)
                    <p class="mt-1 text-sm text-muted">You can't search the staff directory.</p>
                @elseif (mb_strlen(trim($assigneeSearch)) >= 2)
                    @if (count($people) === 0)
                        <p class="mt-1 text-sm text-muted">No one matches "{{ trim($assigneeSearch) }}".</p>
                    @else
                        <ul class="mt-2 divide-y divide-line rounded-md border border-line">
                            @foreach ($people as $person)
                                <li wire:key="person-{{ $person['public_id'] }}">
                                    <button type="button" wire:click="chooseAssignee(@js($person['public_id']), @js($person['display_name']))"
                                            class="block min-h-11 w-full px-3 py-2 text-left hover:bg-hover">
                                        {{ $person['display_name'] }} <span class="text-sm text-muted">{{ $person['employee_number'] ?? '' }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </div>

            <div class="grid gap-5 sm:grid-cols-3">
                <div>
                    <label for="priority" class="mb-1 block text-sm font-semibold">Priority</label>
                    <select id="priority" wire:model="priority" class="field w-full">
                        @foreach (TaskForm::PRIORITIES as $value)
                            <option value="{{ $value }}">{{ Format::priority($value) }}</option>
                        @endforeach
                    </select>
                    @error('priority') <p class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="status" class="mb-1 block text-sm font-semibold">Status</label>
                    <select id="status" wire:model="status" class="field w-full">
                        @foreach (TaskForm::STATUSES as $value)
                            <option value="{{ $value }}">{{ Format::taskStatus($value) }}</option>
                        @endforeach
                    </select>
                    @error('status') <p class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="due-date" class="mb-1 block text-sm font-semibold">Due date <span class="font-normal text-muted">(optional)</span></label>
                    <input id="due-date" type="date" wire:model="dueDate" @error('dueDate') aria-invalid="true" aria-describedby="due-error" @enderror
                           class="field w-full">
                    @error('dueDate') <p id="due-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                </div>
            </div>

            @include('livewire.portal.partials.field', ['id' => 'description', 'label' => 'Description', 'model' => 'description', 'error' => 'description', 'max' => 5000, 'textarea' => true, 'optional' => true])

            <div class="flex flex-wrap gap-2">
                <button type="submit" wire:loading.attr="disabled" wire:target="save"
                        class="btn btn-primary">
                    {{ $publicId === null ? 'Add task' : 'Save changes' }}
                </button>
                <a href="{{ $publicId === null ? route('portal.tasks') : route('portal.task', $publicId) }}" class="btn btn-text">Cancel</a>
            </div>
        </form>
    @endif
</div>
