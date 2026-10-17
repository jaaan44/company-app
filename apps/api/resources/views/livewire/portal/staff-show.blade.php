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
        <h1 class="text-2xl font-semibold">{{ $staff['display_name'] }}</h1>
        @if ($full = Format::distinctFullName($staff))
            <p class="text-muted">{{ $full }}</p>
        @endif

        <dl class="mt-6 rounded-xl border border-line bg-raised px-5">
            <x-portal.info-row label="Position" :value="$staff['position']['title'] ?? null" />
            <x-portal.info-row label="Department" :value="$staff['department']['name'] ?? null" />
            <x-portal.info-row label="Team" :value="$staff['team']['name'] ?? null" />
            <x-portal.info-row label="Manager" :value="$staff['manager']['display_name'] ?? null" />
            <x-portal.info-row label="Company email" :value="$staff['company_email']" copy-label="Copy email" />
            <x-portal.info-row label="Company phone" :value="$staff['company_phone']" copy-label="Copy phone" />
        </dl>
    @endif
</div>
