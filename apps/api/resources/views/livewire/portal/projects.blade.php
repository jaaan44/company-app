@php use App\Support\Portal\Format; @endphp
<div>
    <h1 class="sr-only">Projects</h1>

    @if ($noProfile)
        <p class="rounded-lg border border-line bg-raised p-4">No staff profile is linked to this account.</p>
    @else
        <x-portal.search id="project-search" label="Find a project" placeholder="Search by name or code" />

        @if ($errorMessage)
            <x-portal.error :message="$errorMessage" />
        @elseif (count($projects) === 0)
            <p class="rounded-lg border border-line bg-raised p-4">{{ $query === '' ? "You aren't a member of any projects yet." : 'No project matches "'.$query.'".' }}</p>
        @else
            <ul class="divide-y divide-line rounded-xl border border-line bg-raised">
                @foreach ($projects as $project)
                    @php($details = implode(' · ', array_filter([$project['project_code'] ?? null, $project['client']['name'] ?? null])))
                    <li wire:key="project-{{ $project['public_id'] }}">
                        <a href="{{ route('portal.project', $project['public_id']) }}" class="chevron block min-h-11 px-5 py-3 hover:bg-hover">
                            <span class="block font-medium">{{ $project['name'] }}</span>
                            @if ($details !== '')
                                <span class="block text-sm text-muted">{{ $details }}</span>
                            @endif
                            <span class="mt-1 flex flex-wrap items-center gap-2 text-sm">
                                <x-portal.chip :tone="Format::tone($project['status'])">{{ Format::projectStatus($project['status']) }}</x-portal.chip>
                                @if (($project['my_role'] ?? null) === 'project_lead')
                                    <span class="font-medium text-accent-ink">Project lead</span>
                                @endif
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
            <x-portal.pager :meta="$meta" />
        @endif
    @endif
</div>
