@php use App\Support\Portal\Format; @endphp
<div>
    <h1 class="mb-4 text-2xl font-semibold">My profile</h1>

    @if ($noProfile)
        <p class="rounded-lg border border-line bg-raised p-4">No staff profile is linked to this account.</p>
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif ($profile)
        @php($staff = $profile['staff'])
        @php($user = $profile['user'])

        @if ($staff)
            <section aria-labelledby="profile-name" class="mb-6">
                <h2 id="profile-name" class="text-xl font-semibold">{{ $staff['display_name'] }}</h2>
                @if ($full = Format::distinctFullName($staff))
                    <p class="text-muted">{{ $full }}</p>
                @endif
                @if ($line = Format::roleLine($staff))
                    <p class="mt-1 text-muted">{{ $line }}</p>
                @endif
                @if ($staff['team']['name'] ?? null)
                    <p class="text-muted">{{ $staff['team']['name'] }}</p>
                @endif
            </section>

            <section aria-labelledby="profile-work" class="mb-6 rounded-xl border border-line bg-raised px-5">
                <h2 id="profile-work" class="pt-4 font-semibold">Work</h2>
                <dl>
                    <x-portal.info-row label="Manager" :value="$staff['manager']['display_name'] ?? null" />
                    <x-portal.info-row label="Company email" :value="$staff['company_email']" copy-label="Copy email" />
                    <x-portal.info-row label="Company phone" :value="$staff['company_phone']" copy-label="Copy phone" />
                </dl>
            </section>

            <section aria-labelledby="profile-employment" class="mb-6 rounded-xl border border-line bg-raised px-5">
                <h2 id="profile-employment" class="pt-4 font-semibold">Employment</h2>
                <dl>
                    <x-portal.info-row label="Employee number" :value="$staff['employee_number']" />
                    <x-portal.info-row label="Hire date" :value="Format::date($staff['hire_date'])" />
                </dl>
            </section>
        @else
            <p class="mb-6 rounded-lg border border-line bg-raised p-4">No staff profile is linked to this account.</p>
        @endif

        <section aria-labelledby="profile-account" class="mb-4 rounded-xl border border-line bg-raised px-5">
            <h2 id="profile-account" class="pt-4 font-semibold">Account</h2>
            <dl>
                <x-portal.info-row label="Login email" :value="$user['email']" />
                <x-portal.info-row label="Role" :value="Format::role($user['role'])" />
            </dl>
        </section>
        <p class="text-sm text-muted">To change these details, contact an administrator.</p>
    @endif
</div>
