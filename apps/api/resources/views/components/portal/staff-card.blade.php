@props(['person', 'mayViewStatus' => false, 'compact' => false])
{{--
    One employee as a card (Phase 37E, DEC-072, after docs/design/portal-style-reference.webp's
    "All Employees"): their initials with a status dot, name and role, then
    their status in words, or "On leave" with its dates; and, when the API
    gives it, the project they're working on. The whole card opens their page.
    The status and leave show only with staff-status.view (the API also
    leaves the leave out without it); "Working on" only names a project the
    viewer may see (the API decides).

    `compact` is the reference's dashboard "My Team" card, for Home's
    sideways row: narrow and stacked (initials, status, name, role, then
    Working on below a rule). Without it, the directory's row card.
--}}
@php
    use App\Support\Portal\Format;

    $leave = $mayViewStatus ? ($person['current_leave'] ?? null) : null;
    $status = $mayViewStatus ? ($person['operational_status'] ?? null) : null;
    $project = $person['working_on'] ?? null;
@endphp
@if ($compact)
<a href="{{ route('portal.staff', $person['public_id']) }}" {{ $attributes->merge(['class' => 'card flex h-full flex-col p-4 hover:bg-hover']) }}>
    <x-portal.avatar :name="$person['display_name']" :status="$leave ? null : $status" size="lg" />
    @if ($leave)
        <span class="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-danger"><span class="size-2 rounded-full bg-danger" aria-hidden="true"></span>On leave</span>
        <span class="text-xs text-muted">{{ Format::shortDateRange($leave['start_date'], $leave['end_date']) }}</span>
    @elseif ($status)
        <x-portal.status-dot :status="$status" class="mt-3" />
    @endif
    <span class="mt-1 block font-semibold [overflow-wrap:anywhere]">{{ $person['display_name'] }}</span>
    @if ($role = $person['position']['title'] ?? null)
        <span class="block text-sm text-muted">{{ $role }}</span>
    @endif
    @if ($project)
        {{-- Pushed to the card's foot, so the row's cards line up; at least 12 px below the role. --}}
        <span class="mt-auto block pt-3">
            <span class="flex min-w-0 items-center gap-2 border-t border-line pt-3">
                <x-portal.icon-tile icon="projects" tone="violet" size="sm" />
                <span class="block min-w-0">
                    <span class="block text-xs text-muted">Working on</span>
                    <span class="block text-sm font-semibold [overflow-wrap:anywhere]">{{ $project['name'] }}</span>
                </span>
            </span>
        </span>
    @endif
</a>
@else
<a href="{{ route('portal.staff', $person['public_id']) }}" {{ $attributes->merge(['class' => 'chevron card flex h-full flex-col gap-3 p-4 hover:bg-hover sm:flex-row sm:items-center']) }}>
    <span class="flex min-w-0 flex-1 items-start gap-3">
        <x-portal.avatar :name="$person['display_name']" :status="$leave ? null : $status" />
        <span class="block min-w-0">
            <span class="block font-semibold [overflow-wrap:anywhere]">{{ $person['display_name'] }}</span>
            @if ($line = Format::roleLine($person))
                <span class="block text-sm text-muted">{{ $line }}</span>
            @endif
            @if ($leave)
                <span class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                    <span class="inline-flex items-center gap-1.5 font-medium text-danger"><span class="size-2 rounded-full bg-danger" aria-hidden="true"></span>On leave</span>
                    <span class="inline-flex items-center gap-1 text-muted"><x-portal.icon name="schedule" class="size-4" />{{ Format::leaveDates($leave['start_date'], $leave['end_date']) }}</span>
                </span>
            @elseif ($status)
                <x-portal.status-dot :status="$status" class="mt-1" />
            @endif
        </span>
    </span>
    @if ($project)
        <span class="flex min-w-0 items-center gap-3 border-t border-line pt-3 sm:w-52 sm:shrink-0 sm:border-t-0 sm:border-l sm:pt-0 sm:pl-4">
            <x-portal.icon-tile icon="projects" tone="violet" />
            <span class="block min-w-0">
                <span class="block text-xs text-muted">Working on</span>
                <span class="block text-sm font-semibold [overflow-wrap:anywhere]">{{ $project['name'] }}</span>
            </span>
        </span>
    @endif
</a>
@endif
