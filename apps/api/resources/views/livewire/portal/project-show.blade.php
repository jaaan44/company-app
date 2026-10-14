@php use App\Support\Portal\Format; @endphp
<div>
    <x-portal.back :href="route('portal.projects')">Projects</x-portal.back>

    @if ($errorMessage)
        <h1 class="sr-only">Project</h1>
        <x-portal.error :message="$errorMessage" />
    @elseif ($project)
        <h1 class="text-2xl font-semibold">{{ $project['name'] }}</h1>
        @if ($project['project_code'] ?? null)
            <p class="text-muted">{{ $project['project_code'] }}</p>
        @endif
        <p class="mt-2"><x-portal.chip :tone="Format::tone($project['status'])">{{ Format::projectStatus($project['status']) }}</x-portal.chip></p>

        @if ($mayEdit || $mayDelete || $mayAddTask)
            <div class="mt-3 flex flex-wrap gap-2">
                @if ($mayAddTask)
                    <a href="{{ route('portal.tasks.new', ['project' => $project['public_id']]) }}" class="inline-flex min-h-11 items-center gap-2 rounded-full bg-accent px-4 font-medium text-on-accent hover:opacity-90"><x-portal.icon name="add" class="size-5" /> New task</a>
                @endif
                @if ($mayEdit)
                    <a href="{{ route('portal.projects.edit', $project['public_id']) }}" class="inline-flex min-h-11 items-center rounded-full border border-line px-4 font-medium hover:bg-hover">Edit project</a>
                @endif
                @if ($mayDelete)
                    <x-portal.confirm title="Delete this project?" body="A project with members, tasks, work logs or reports can't be deleted. Set its status to Cancelled instead." confirm="Delete" action="delete()" :danger="true"
                                      class="inline-flex min-h-11 items-center rounded-full border border-line px-4 font-medium text-danger hover:bg-hover">Delete project</x-portal.confirm>
                @endif
            </div>
        @endif
        @if ($generalError)
            <p role="alert" class="mt-3 font-medium text-danger">{{ $generalError }}</p>
        @endif
        <x-portal.toast :message="$savedMessage" :nonce="$toastNonce" />

        <dl class="mt-6 rounded-xl border border-line bg-raised px-5">
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b border-line py-3">
                <dt class="text-sm text-muted">Client</dt>
                <dd class="font-medium">
                    @if ($project['client'] ?? null)
                        <a href="{{ route('portal.client', $project['client']['public_id']) }}" class="inline-flex min-h-11 items-center text-accent-ink underline-offset-4 hover:underline">{{ $project['client']['name'] }}</a>
                    @else
                        Not set
                    @endif
                </dd>
            </div>
            <x-portal.info-row label="My role" :value="($project['my_role'] ?? null) ? Format::projectRole($project['my_role']) : 'Not a member'" />
            <x-portal.info-row label="Start date" :value="Format::date($project['start_date'] ?? null)" />
            <x-portal.info-row label="Target end date" :value="Format::date($project['target_end_date'] ?? null)" />
            @if ($project['completed_date'] ?? null)
                <x-portal.info-row label="Completed" :value="Format::date($project['completed_date'])" />
            @endif
        </dl>

        @if (filled($project['description'] ?? null))
            <section aria-labelledby="project-description" class="mt-6 rounded-xl border border-line bg-raised p-5">
                <h2 id="project-description" class="mb-2 font-semibold">Description</h2>
                <p class="whitespace-pre-line">{{ $project['description'] }}</p>
            </section>
        @endif

        <section aria-labelledby="project-members" class="mt-6">
            <h2 id="project-members" class="mb-2 font-semibold">Members ({{ $membersTotal }})</h2>
            @if (count($members) === 0)
                <p class="rounded-lg border border-line bg-raised p-4">No members yet.</p>
            @else
                <ul class="divide-y divide-line rounded-xl border border-line bg-raised">
                    @foreach ($members as $member)
                        <li wire:key="member-{{ $member['staff']['public_id'] }}" class="{{ $mayMembers ? 'flex flex-wrap items-center justify-between gap-2 pr-3' : '' }}">
                            <a href="{{ route('portal.staff', $member['staff']['public_id']) }}" class="{{ $mayMembers ? 'min-w-0 flex-1' : 'chevron' }} block min-h-11 px-5 py-3 hover:bg-hover">
                                <span class="block font-medium">{{ $member['staff']['display_name'] }}</span>
                                <span class="block text-sm text-muted">{{ Format::projectRole($member['role']) }}</span>
                            </a>
                            @if ($mayMembers)
                                <span class="flex flex-wrap items-center gap-1 pb-2 sm:pb-0">
                                    <button type="button" wire:click="setMemberRole(@js($member['staff']['public_id']), '{{ $member['role'] === 'project_lead' ? 'member' : 'project_lead' }}')"
                                            class="inline-flex min-h-11 items-center rounded-md px-3 text-sm text-accent-ink hover:bg-hover">
                                        {{ $member['role'] === 'project_lead' ? 'Make member' : 'Make lead' }}<span class="sr-only">: {{ $member['staff']['display_name'] }}</span>
                                    </button>
                                    <x-portal.confirm :title="'Remove '.$member['staff']['display_name'].'?'" body="They'll no longer see this project's private details." confirm="Remove"
                                                      :action="'removeMember(\''.$member['staff']['public_id'].'\')'" :danger="true"
                                                      class="inline-flex min-h-11 items-center rounded-md px-3 text-sm text-danger hover:bg-hover">Remove<span class="sr-only"> {{ $member['staff']['display_name'] }}</span></x-portal.confirm>
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($membersTotal > count($members))
                    <p class="mt-2 text-sm text-muted">Showing {{ count($members) }} of {{ $membersTotal }}</p>
                @endif
            @endif
        </section>

        @if ($mayMembers)
            <form wire:submit="addMember" class="mt-3 space-y-3 rounded-xl border border-line bg-raised p-4" novalidate>
                <h3 class="font-semibold">Add a member</h3>
                @if ($newMemberId !== '')
                    <p class="font-medium">{{ $newMemberName }}</p>
                @endif
                <div>
                    <label for="member-search" class="mb-1 block text-sm font-semibold">{{ $newMemberId === '' ? 'Find a person' : 'Find someone else' }}</label>
                    <input id="member-search" type="search" wire:model.live.debounce.400ms="memberSearch" autocomplete="off" placeholder="Search by name"
                           @error('memberSearch') aria-invalid="true" aria-describedby="member-error" @enderror class="min-h-11 w-full rounded-md border border-line bg-raised px-3">
                    @error('memberSearch') <p id="member-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                    @if (count($people) > 0)
                        <ul class="mt-2 divide-y divide-line rounded-md border border-line">
                            @foreach ($people as $person)
                                <li wire:key="find-{{ $person['public_id'] }}">
                                    <button type="button" wire:click="chooseMember(@js($person['public_id']), @js($person['display_name']))" class="block min-h-11 w-full px-3 py-2 text-left hover:bg-hover">{{ $person['display_name'] }}</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                <div class="flex flex-wrap items-end gap-2">
                    <div>
                        <label for="member-role" class="mb-1 block text-sm font-semibold">Role</label>
                        <select id="member-role" wire:model="newMemberRole" class="min-h-11 rounded-md border border-line bg-raised px-3">
                            <option value="member">Member</option>
                            <option value="project_lead">Project lead</option>
                        </select>
                    </div>
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-full bg-accent px-5 font-medium text-on-accent hover:opacity-90">Add member</button>
                </div>
            </form>
        @endif

        <section aria-labelledby="project-milestones" class="mt-6">
            <h2 id="project-milestones" class="mb-2 font-semibold">Milestones</h2>
            @if (count($milestones) === 0)
                <p class="rounded-lg border border-line bg-raised p-4">No milestones yet.</p>
            @else
                <ul class="divide-y divide-line rounded-xl border border-line bg-raised">
                    @foreach ($milestones as $milestone)
                        <li wire:key="milestone-{{ $milestone['public_id'] }}" class="flex flex-wrap items-center justify-between gap-2 px-5 py-3">
                            <span>
                                <span class="block font-medium">{{ $milestone['title'] }}</span>
                                <span class="block text-sm text-muted">{{ Format::date($milestone['due_date']) }}</span>
                            </span>
                            @if ($mayMilestones)
                                <span class="flex flex-wrap items-center gap-1">
                                    <label for="ms-{{ $milestone['public_id'] }}" class="sr-only">Status of {{ $milestone['title'] }}</label>
                                    <select id="ms-{{ $milestone['public_id'] }}" x-data x-on:change="$wire.setMilestoneStatus(@js($milestone['public_id']), $event.target.value)"
                                            class="min-h-11 rounded-md border border-line bg-raised px-2 text-sm">
                                        @foreach (['pending', 'completed', 'cancelled'] as $value)
                                            <option value="{{ $value }}" @selected($milestone['status'] === $value)>{{ Format::milestoneStatus($value) }}</option>
                                        @endforeach
                                    </select>
                                    <x-portal.confirm :title="'Remove '.$milestone['title'].'?'" body="This can't be undone." confirm="Remove"
                                                      :action="'removeMilestone(\''.$milestone['public_id'].'\')'" :danger="true"
                                                      class="inline-flex min-h-11 items-center rounded-md px-3 text-sm text-danger hover:bg-hover">Remove<span class="sr-only"> {{ $milestone['title'] }}</span></x-portal.confirm>
                                </span>
                            @else
                                <x-portal.chip :tone="Format::tone($milestone['status'])">{{ Format::milestoneStatus($milestone['status']) }}</x-portal.chip>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if ($milestonesTotal > count($milestones))
                    <p class="mt-2 text-sm text-muted">Showing {{ count($milestones) }} of {{ $milestonesTotal }}</p>
                @endif
            @endif
            @if ($mayMilestones)
                <form wire:submit="addMilestone" class="mt-3 flex flex-wrap items-end gap-3 rounded-xl border border-line bg-raised p-4" novalidate>
                    <div class="min-w-0 flex-1 basis-48">
                        <label for="milestone-title" class="mb-1 block text-sm font-semibold">New milestone</label>
                        <input id="milestone-title" type="text" wire:model="milestoneTitle" maxlength="255" @error('milestoneTitle') aria-invalid="true" aria-describedby="milestone-title-error" @enderror
                               class="min-h-11 w-full rounded-md border border-line bg-raised px-3">
                        @error('milestoneTitle') <p id="milestone-title-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="milestone-due" class="mb-1 block text-sm font-semibold">Due</label>
                        <input id="milestone-due" type="date" wire:model="milestoneDue" @error('milestoneDue') aria-invalid="true" aria-describedby="milestone-due-error" @enderror
                               class="min-h-11 rounded-md border border-line bg-raised px-3">
                        @error('milestoneDue') <p id="milestone-due-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="inline-flex min-h-11 items-center rounded-full bg-accent px-5 font-medium text-on-accent hover:opacity-90">Add milestone</button>
                </form>
            @endif
        </section>
    @endif
</div>
