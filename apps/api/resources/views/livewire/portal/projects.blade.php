@php use App\Support\Portal\Format; @endphp
<div>
    <h1 class="sr-only">Projects</h1>

    @if ($mayAdd)
        <x-portal.fab :href="route('portal.projects.new')" label="New project" />
    @endif
    @if ($mayViewAll)
        <div role="group" aria-label="Whose projects" class="mb-4 inline-flex flex-wrap gap-2">
            @foreach (['mine' => 'Mine', 'all' => 'All'] as $value => $label)
                <button type="button" wire:click="showScope('{{ $value }}')" aria-pressed="{{ $currentScope === $value ? 'true' : 'false' }}"
                        class="filter-chip">{{ $label }}</button>
            @endforeach
        </div>
    @endif

    @if ($noProfile)
        <x-portal.no-profile />
    @else
        <x-portal.search id="project-search" label="Find a project" placeholder="Search by name or code" />

        @if ($errorMessage)
            <x-portal.error :message="$errorMessage" />
        @elseif (count($projects) === 0)
            <x-portal.notice>{{ $query === '' ? ($currentScope === 'all' ? 'No projects yet.' : "You aren't a member of any projects yet.") : 'No project matches "'.$query.'".' }}</x-portal.notice>
        @else
            <ul class="divide-y divide-line overflow-hidden card">
                @foreach ($projects as $project)
                    @php($details = implode(' · ', array_filter([$project['project_code'] ?? null, $project['client']['name'] ?? null])))
                    <li wire:key="project-{{ $project['public_id'] }}">
                        <a href="{{ route('portal.project', $project['public_id']) }}" class="chevron flex min-h-16 items-start gap-3 px-4 py-3 hover:bg-hover">
                            <x-portal.icon-tile icon="projects" :tone="Format::tileTone(Format::tone($project['status'] ?? null))" />
                            <span class="block min-w-0 flex-1">
                                <span class="block font-semibold [overflow-wrap:anywhere]">{{ $project['name'] }}</span>
                                @if ($details !== '')
                                    <span class="block text-sm text-muted">{{ $details }}</span>
                                @endif
                                <span class="mt-1 flex flex-wrap items-center gap-2 text-sm">
                                    <x-portal.chip :tone="Format::tone($project['status'])">{{ Format::projectStatus($project['status']) }}</x-portal.chip>
                                    @if (($project['my_role'] ?? null) === 'project_lead')
                                        <span class="font-medium text-accent-ink">Project lead</span>
                                    @endif
                                </span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
            <x-portal.pager :meta="$meta" />
        @endif
    @endif
</div>
