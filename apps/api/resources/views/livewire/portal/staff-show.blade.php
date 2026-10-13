@php use App\Support\Portal\Format; @endphp
<div>
    <p class="mb-4"><a href="{{ route('portal.directory') }}" class="inline-flex min-h-11 items-center text-accent-ink underline-offset-4 hover:underline">← Staff directory</a></p>

    @if ($notFound)
        <h1 class="mb-4 text-2xl font-semibold">Staff</h1>
        <p class="rounded-lg border border-line bg-raised p-4">This person isn't in the directory.</p>
    @elseif ($errorMessage)
        <h1 class="mb-4 text-2xl font-semibold">Staff</h1>
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
