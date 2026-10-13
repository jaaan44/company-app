@php use App\Support\Portal\Format; @endphp
<div>
    <x-portal.back :href="route('portal.projects')">Projects</x-portal.back>

    @if ($errorMessage)
        <h1 class="mb-4 text-2xl font-semibold">Project</h1>
        <x-portal.error :message="$errorMessage" />
    @elseif ($project)
        <h1 class="text-2xl font-semibold">{{ $project['name'] }}</h1>
        @if ($project['project_code'] ?? null)
            <p class="text-muted">{{ $project['project_code'] }}</p>
        @endif
        <p class="mt-2"><x-portal.chip :tone="Format::tone($project['status'])">{{ Format::projectStatus($project['status']) }}</x-portal.chip></p>

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
                        <li wire:key="member-{{ $member['staff']['public_id'] }}">
                            <a href="{{ route('portal.staff', $member['staff']['public_id']) }}" class="block min-h-11 px-5 py-3 hover:bg-hover">
                                <span class="block font-medium">{{ $member['staff']['display_name'] }}</span>
                                <span class="block text-sm text-muted">{{ Format::projectRole($member['role']) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
                @if ($membersTotal > count($members))
                    <p class="mt-2 text-sm text-muted">Showing {{ count($members) }} of {{ $membersTotal }}</p>
                @endif
            @endif
        </section>

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
                            <x-portal.chip :tone="Format::tone($milestone['status'])">{{ Format::milestoneStatus($milestone['status']) }}</x-portal.chip>
                        </li>
                    @endforeach
                </ul>
                @if ($milestonesTotal > count($milestones))
                    <p class="mt-2 text-sm text-muted">Showing {{ count($milestones) }} of {{ $milestonesTotal }}</p>
                @endif
            @endif
        </section>
    @endif
</div>
