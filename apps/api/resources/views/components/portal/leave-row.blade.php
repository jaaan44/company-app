{{-- One leave request in a list (Phase 34C): the type (or, for a team list, the person and type), the dates and days, and the status. --}}
@props(['request', 'href', 'showRequester' => false])
@php use App\Support\Portal\Format; @endphp
<a href="{{ $href }}" class="chevron block min-h-11 px-5 py-3 hover:bg-hover">
    @if ($showRequester && ($request['staff']['display_name'] ?? null))
        <span class="block font-medium">{{ $request['staff']['display_name'] }}</span>
        <span class="block text-sm">{{ $request['leave_type']['name'] }}</span>
    @else
        <span class="block font-medium">{{ $request['leave_type']['name'] }}</span>
    @endif
    <span class="block text-sm text-muted">{{ Format::leaveDates($request['start_date'], $request['end_date']) }} · {{ Format::days((int) $request['total_days']) }}</span>
    <span class="mt-1 block text-sm">
        <x-portal.chip :tone="Format::tone($request['status'])">{{ Format::leaveStatus($request['status']) }}</x-portal.chip>
    </span>
</a>
