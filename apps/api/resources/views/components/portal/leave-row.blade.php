{{-- One leave request in a list (Phase 34C): the type (or, for a team list, the person and type), the dates and days, and the status. --}}
@props(['request', 'href', 'showRequester' => false])
@php use App\Support\Portal\Format; @endphp
<a href="{{ $href }}" class="chevron flex min-h-16 items-start gap-3 px-4 py-3 hover:bg-hover">
    <x-portal.icon-tile icon="leave" :tone="Format::tileTone(Format::tone($request['status']))" />
    <span class="block min-w-0 flex-1">
        @if ($showRequester && ($request['staff']['display_name'] ?? null))
            <span class="block font-semibold [overflow-wrap:anywhere]">{{ $request['staff']['display_name'] }}</span>
            <span class="block text-sm">{{ $request['leave_type']['name'] }}</span>
        @else
            <span class="block font-semibold [overflow-wrap:anywhere]">{{ $request['leave_type']['name'] }}</span>
        @endif
        <span class="block text-sm text-muted">{{ Format::leaveDates($request['start_date'], $request['end_date']) }} · {{ Format::days((int) $request['total_days']) }}</span>
        <span class="mt-1 block text-sm">
            <x-portal.chip :tone="Format::tone($request['status'])">{{ Format::leaveStatus($request['status']) }}</x-portal.chip>
        </span>
    </span>
</a>
