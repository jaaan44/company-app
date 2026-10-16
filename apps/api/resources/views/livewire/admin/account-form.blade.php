<div>
    <x-portal.back :href="route('admin.users')">Users and roles</x-portal.back>
    <h1 class="sr-only">New sign-in account</h1>

    @if ($created)
        @if ($temporaryPassword)
            @include('livewire.admin.partials.temporary-password', ['heading' => 'Account created', 'account' => $created, 'password' => $temporaryPassword])
        @else
            <p role="status" class="rounded-xl border border-line bg-raised p-5">Account created for {{ $created['name'] }}. The temporary password is no longer shown; if it wasn't passed on, use Reset password.</p>
        @endif
        <a href="{{ route('admin.users', ['q' => $created['email']]) }}" class="mt-4 inline-flex min-h-11 items-center rounded-full bg-accent px-5 font-medium text-on-accent hover:opacity-90">Done</a>
    @else
        <form wire:submit="save" class="space-y-5 rounded-xl border border-line bg-raised p-5" novalidate>
            @if ($generalError)
                <p role="alert" class="font-medium text-danger">{{ $generalError }}</p>
            @endif
            <div class="grid gap-5 sm:grid-cols-2">
                @include('livewire.portal.partials.field', ['id' => 'account-name', 'label' => 'Name', 'model' => 'name', 'error' => 'name', 'max' => 255])
                @include('livewire.portal.partials.field', ['id' => 'account-email', 'label' => 'Sign-in email', 'model' => 'email', 'error' => 'email', 'max' => 255, 'type' => 'email'])
                <div>
                    <label for="account-role" class="mb-1 block text-sm font-semibold">Role</label>
                    <select id="account-role" wire:model="role" class="min-h-11 w-full rounded-md border border-line bg-raised px-3"
                            @error('role') aria-invalid="true" aria-describedby="account-role-error" @enderror>
                        <option value="staff">Staff</option>
                        <option value="manager">Manager</option>
                        <option value="administrator">Administrator</option>
                    </select>
                    @error('role') <p id="account-role-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="staff-search" class="mb-1 block text-sm font-semibold">Staff record <span class="font-normal text-muted">(optional)</span></label>
                <p class="mb-1 text-sm text-muted">Link it so the app shows their tasks, leave and messages. Only records without an account are listed.</p>
                @if ($staffId !== '')
                    <p class="flex flex-wrap items-center gap-3">
                        <span class="font-medium">{{ $staffName }}</span>
                        <button type="button" wire:click="clearStaff" class="inline-flex min-h-11 items-center rounded-md px-3 text-accent-ink hover:bg-hover">Remove<span class="sr-only"> {{ $staffName }}</span></button>
                    </p>
                @endif
                <input id="staff-search" type="search" wire:model.live.debounce.400ms="staffSearch" autocomplete="off"
                       placeholder="{{ $staffId === '' ? 'Search by name or employee number' : 'Search to change' }}"
                       @error('staffSearch') aria-invalid="true" aria-describedby="staff-search-error" @enderror
                       class="min-h-11 w-full rounded-md border border-line bg-raised px-3 sm:max-w-md">
                @error('staffSearch') <p id="staff-search-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                @if (mb_strlen(trim($staffSearch)) >= 2)
                    @if (count($people) === 0)
                        <p class="mt-1 text-sm text-muted">No staff record without an account matches "{{ trim($staffSearch) }}".</p>
                    @else
                        <ul class="mt-2 divide-y divide-line rounded-md border border-line sm:max-w-md">
                            @foreach ($people as $person)
                                <li wire:key="staff-{{ $person['public_id'] }}">
                                    <button type="button" wire:click="chooseStaff(@js($person['public_id']), @js($person['display_name']))"
                                            class="block min-h-11 w-full px-3 py-2 text-left hover:bg-hover">
                                        {{ $person['display_name'] }} <span class="text-sm text-muted">{{ $person['employee_number'] }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </div>

            <p class="text-sm text-muted">A temporary password is created and shown to you once. They must choose their own at their first sign-in.</p>
            <div class="flex flex-wrap gap-2">
                <button type="submit" wire:loading.attr="disabled" wire:target="save"
                        class="inline-flex min-h-11 items-center rounded-full bg-accent px-5 font-medium text-on-accent hover:opacity-90 disabled:opacity-60">Create account</button>
                <a href="{{ route('admin.users') }}" class="inline-flex min-h-11 items-center rounded-full px-5 font-medium text-accent-ink hover:bg-hover">Cancel</a>
            </div>
        </form>
    @endif
</div>
