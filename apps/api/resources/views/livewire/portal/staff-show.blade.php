@php use App\Support\Portal\Format; @endphp
<div>
    <x-portal.back :href="route('portal.directory')">Staff directory</x-portal.back>

    @if ($notFound)
        <h1 class="sr-only">Staff</h1>
        <x-portal.notice>This person isn't in the directory.</x-portal.notice>
    @elseif ($errorMessage)
        <h1 class="sr-only">Staff</h1>
        <x-portal.error :message="$errorMessage" />
    @elseif ($staff)
        @php
            $leave = $mayViewStatus ? ($staff['current_leave'] ?? null) : null;
            $status = $mayViewStatus && $leave === null ? ($staff['operational_status'] ?? null) : null;
        @endphp
        {{-- 37D: a header card with their initials, as in the reference. --}}
        <div class="card flex items-center gap-4 p-5">
            <x-portal.avatar :name="$staff['display_name']" :status="$status" size="lg" />
            <div class="min-w-0">
                <h1 class="text-2xl leading-tight font-bold tracking-tight [overflow-wrap:anywhere]">{{ $staff['display_name'] }}</h1>
                @if ($full = Format::distinctFullName($staff))
                    <p class="text-muted">{{ $full }}</p>
                @endif
                @if ($line = Format::roleLine($staff))
                    <p class="text-sm text-muted">{{ $line }}</p>
                @endif
                @if ($leave)
                    {{-- 37E (DEC-072): approved leave covering today, with its dates (never its type). --}}
                    <p class="mt-1 flex flex-wrap items-center gap-x-3 text-sm"><span class="inline-flex items-center gap-1.5 font-medium text-danger"><span class="size-2 rounded-full bg-danger" aria-hidden="true"></span>On leave</span><span class="text-muted">{{ Format::leaveDates($leave['start_date'], $leave['end_date']) }}</span></p>
                @elseif ($status)
                    <x-portal.status-dot :status="$status" class="mt-1" />
                @endif
            </div>
        </div>

        <dl class="mt-6 card px-5">
            @if ($staff['working_on'] ?? null)
                <x-portal.info-row label="Working on" :value="$staff['working_on']['name']" />
            @endif
            <x-portal.info-row label="Position" :value="$staff['position']['title'] ?? null" />
            <x-portal.info-row label="Department" :value="$staff['department']['name'] ?? null" />
            <x-portal.info-row label="Team" :value="$staff['team']['name'] ?? null" />
            <x-portal.info-row label="Manager" :value="$staff['manager']['display_name'] ?? null" />
            <x-portal.info-row label="Company email" :value="$staff['company_email']" copy-label="Copy email" />
            <x-portal.info-row label="Company phone" :value="$staff['company_phone']" copy-label="Copy phone" />
        </dl>
    @endif
</div>
