@php use App\Support\Portal\Format; @endphp
<div>
    <x-portal.back :href="route('admin.staff')">Staff records</x-portal.back>

    @if ($notFound)
        <h1 class="sr-only">Staff record</h1>
        <p class="rounded-lg border border-line bg-raised p-4">This staff record no longer exists.</p>
    @elseif ($errorMessage && ! $staff)
        <h1 class="sr-only">Staff record</h1>
        <x-portal.error :message="$errorMessage" />
    @elseif ($staff)
        @php($section = 'mb-6 rounded-xl border border-line bg-raised px-5')
        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ $staff['display_name'] }}</h1>
                <p class="text-muted">{{ $staff['employee_number'] }}@if ($staff['position']) · {{ $staff['position']['title'] }}@endif</p>
                @if ($staff['status'] !== 'active')
                    <x-portal.chip tone="muted" class="mt-1">{{ ucfirst($staff['status']) }}</x-portal.chip>
                @endif
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($can['update'])
                    <a href="{{ route('admin.staff.edit', $staff['public_id']) }}" class="inline-flex min-h-11 items-center rounded-full bg-accent px-5 font-medium text-on-accent hover:opacity-90">Edit</a>
                @endif
                @if ($can['delete'])
                    <x-portal.confirm :title="'Delete '.$staff['display_name'].'\'s record?'" body="A record with any history (tasks, work logs, leave, messages, reports…) can't be deleted. Set it to Separated instead." confirm="Delete" action="delete()" :danger="true"
                                      class="inline-flex min-h-11 items-center rounded-full border border-line px-4 font-medium text-danger hover:bg-hover">Delete</x-portal.confirm>
                @endif
            </div>
        </div>

        @if ($generalError)
            <p role="alert" class="mb-4 font-medium text-danger">{{ $generalError }}</p>
        @endif

        <section aria-labelledby="details" class="{{ $section }}">
            <h2 id="details" class="pt-4 font-semibold">Details</h2>
            <dl>
                <x-portal.info-row label="Name" :value="trim($staff['first_name'].' '.$staff['last_name'])" />
                @if ($staff['preferred_name'])<x-portal.info-row label="Preferred name" :value="$staff['preferred_name']" />@endif
                <x-portal.info-row label="Company email" :value="$staff['company_email'] ?? null" />
                <x-portal.info-row label="Company phone" :value="$staff['company_phone'] ?? null" />
                <x-portal.info-row label="Department" :value="$staff['department']['name'] ?? null" />
                <x-portal.info-row label="Team" :value="$staff['team']['name'] ?? null" />
                <x-portal.info-row label="Manager" :value="$staff['manager']['display_name'] ?? null" />
                <x-portal.info-row label="Hire date" :value="Format::date($staff['hire_date']) ?? null" />
                @if ($staff['separation_date'])<x-portal.info-row label="Separation date" :value="Format::date($staff['separation_date'])" />@endif
            </dl>
        </section>

        @if ($can['accounts'])
            <section aria-labelledby="account" class="{{ $section }} pb-4">
                <h2 id="account" class="pt-4 font-semibold">Sign-in account</h2>
                @if ($staff['user'] ?? null)
                    <p class="mt-2 break-all">{{ $staff['user']['email'] }}</p>
                    <p class="mt-1 flex flex-wrap gap-2">
                        <x-portal.chip :tone="$staff['user']['status'] === 'active' ? 'success' : 'muted'">{{ ucfirst($staff['user']['status']) }}</x-portal.chip>
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <a href="{{ route('admin.users', ['q' => $staff['user']['email']]) }}" class="inline-flex min-h-11 items-center rounded-full border border-line px-4 font-medium text-accent-ink hover:bg-hover">Manage the account</a>
                        <x-portal.confirm title="Unlink this sign-in account?" body="The account stays, but it no longer belongs to this staff record: the app shows no tasks, leave or messages for it until it is linked again." confirm="Unlink" action="unlinkAccount()" :danger="true"
                                          class="inline-flex min-h-11 items-center rounded-full px-4 font-medium text-danger hover:bg-hover">Unlink</x-portal.confirm>
                    </div>
                @else
                    <p class="mt-2 text-muted">No sign-in account. They can't use the app or the web.</p>
                    <a href="{{ route('admin.users.new', ['staff' => $staff['public_id']]) }}" class="mt-3 inline-flex min-h-11 items-center rounded-full border border-line px-4 font-medium text-accent-ink hover:bg-hover">Create a sign-in account</a>
                    <div class="mt-4">
                        <label for="account-search" class="mb-1 block text-sm font-semibold">Or link an existing account</label>
                        <input id="account-search" type="search" wire:model.live.debounce.400ms="accountSearch" autocomplete="off" placeholder="Search accounts by name or email"
                               class="min-h-11 w-full rounded-md border border-line bg-surface px-3 sm:max-w-md">
                        @if (mb_strlen(trim($accountSearch)) >= 2)
                            @if ($accounts === [])
                                <p class="mt-1 text-sm text-muted">No account without a staff record matches "{{ trim($accountSearch) }}".</p>
                            @else
                                <ul class="mt-2 divide-y divide-line rounded-md border border-line sm:max-w-md">
                                    @foreach ($accounts as $account)
                                        <li wire:key="link-{{ $account['public_id'] }}">
                                            <button type="button" wire:click="linkAccount(@js($account['public_id']))" class="block min-h-11 w-full px-3 py-2 text-left hover:bg-hover">
                                                {{ $account['name'] }} <span class="block break-all text-sm text-muted">{{ $account['email'] }}</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        @endif
                    </div>
                @endif
            </section>
        @endif

        @if ($statuses !== null)
            @php($current = $statuses['data'][0] ?? null)
            <section aria-labelledby="status" class="{{ $section }} pb-4">
                <h2 id="status" class="pt-4 font-semibold">Status</h2>
                <p class="mt-2">
                    @if ($current)
                        <span class="font-medium">{{ Format::operationalStatus($current['status']) }}</span>
                        <span class="text-muted">since {{ Format::dateTime($current['recorded_at'], $timezone) }}</span>
                    @else
                        <span class="text-muted">No status set yet.</span>
                    @endif
                </p>
                @if ($can['setStatus'])
                    <form wire:submit="setStatus" class="mt-3 flex flex-wrap items-end gap-2">
                        <div>
                            <label for="new-status" class="block text-sm font-medium">Correct the status</label>
                            <select id="new-status" wire:model="newStatus" class="mt-1 min-h-11 rounded-md border border-line bg-surface px-3">
                                @foreach (['available', 'busy', 'in_meeting', 'in_field', 'off_duty'] as $value)
                                    <option value="{{ $value }}">{{ Format::operationalStatus($value) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-full border border-line px-4 font-medium text-accent-ink hover:bg-hover">Set status</button>
                    </form>
                    <p class="mt-2 text-sm text-muted">This adds to their status history. They see it in the app.</p>
                @endif
            </section>
        @endif

        @if ($checkIns !== null)
            <section aria-labelledby="check-ins" class="{{ $section }} pb-4">
                <h2 id="check-ins" class="pt-4 font-semibold">Check-ins</h2>
                @if (($checkIns['data'] ?? []) === [])
                    <p class="mt-2 text-muted">No check-ins.</p>
                @else
                    <ul class="mt-2 divide-y divide-line">
                        @foreach ($checkIns['data'] as $checkIn)
                            <li wire:key="check-in-{{ $checkIn['public_id'] }}" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 py-3">
                                <span class="min-w-0 flex-1 basis-56">
                                    <span class="block font-medium">{{ Format::dateTime($checkIn['checked_in_at'], $timezone) }}</span>
                                    <span class="block text-sm text-muted">
                                        {{ $checkIn['location_label'] ?? number_format($checkIn['latitude'], 5).', '.number_format($checkIn['longitude'], 5) }}
                                        @if ($checkIn['accuracy_meters'] !== null) · ±{{ $checkIn['accuracy_meters'] }} m @endif
                                        @if ($checkIn['note']) · {{ $checkIn['note'] }} @endif
                                    </span>
                                </span>
                                @if ($can['removeCheckIn'])
                                    <x-portal.confirm title="Remove this check-in?" body="It disappears from their history and their manager's view. This can't be undone." confirm="Remove"
                                                      :action="'removeCheckIn(\''.$checkIn['public_id'].'\')'" :danger="true"
                                                      class="inline-flex min-h-11 items-center rounded-md px-3 text-sm text-danger hover:bg-hover">Remove<span class="sr-only"> the check-in of {{ Format::dateTime($checkIn['checked_in_at'], $timezone) }}</span></x-portal.confirm>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <x-portal.pager :meta="$checkIns['meta']" />
                @endif
            </section>
        @endif

        <x-portal.toast :message="$savedMessage" :nonce="$toastNonce" />
    @endif
</div>
