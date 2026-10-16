<div>
    <x-portal.back :href="route('admin.section', 'administration')">Administration</x-portal.back>
    <h1 class="sr-only">Users and roles</h1>

    @if ($noProfile || ($errorMessage && ! $meta))
        <x-portal.error :message="$errorMessage ?? 'Only Administrators can manage accounts.'" />
    @else
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <p class="min-w-0 flex-1 basis-72 text-muted">Everyone who can sign in. A person's role decides what they can do, as set on <a href="{{ route('admin.roles') }}" class="text-accent-ink underline underline-offset-4">Roles &amp; permissions</a>. A change applies from their next action.</p>
            <a href="{{ route('admin.users.new') }}" class="inline-flex min-h-11 items-center gap-2 rounded-full bg-accent px-5 font-medium text-on-accent hover:opacity-90">
                <x-portal.icon name="add" class="size-5" /> New account
            </a>
        </div>

        @if ($resetAccount)
            <div class="mb-4">
                @if ($temporaryPassword)
                    @include('livewire.admin.partials.temporary-password', ['heading' => 'Password reset for '.$resetAccount['name'], 'account' => $resetAccount, 'password' => $temporaryPassword])
                @else
                    <p role="status" class="rounded-xl border border-line bg-raised p-5">The password for {{ $resetAccount['name'] }} was reset. The temporary password is no longer shown; reset again if it wasn't passed on.</p>
                @endif
                <button type="button" wire:click="dismissReset" class="mt-2 inline-flex min-h-11 items-center rounded-full px-4 font-medium text-accent-ink hover:bg-hover">Done</button>
            </div>
        @endif

        <div class="mb-4">
            <label for="user-search" class="block text-sm font-medium">Search by name or email</label>
            <input id="user-search" type="search" wire:model.live.debounce.400ms="search" autocomplete="off"
                   class="mt-1 min-h-11 w-full rounded-md border border-line bg-raised px-3 sm:max-w-sm">
        </div>

        @if ($errorMessage)
            <p role="alert" class="mb-4 font-medium text-danger">{{ $errorMessage }}</p>
        @endif

        @if ($users === [])
            <x-portal.empty>No accounts match.</x-portal.empty>
        @else
            <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-raised">
                @foreach ($users as $user)
                    <li wire:key="user-{{ $user['public_id'] }}" class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 px-5 py-3"
                        x-data="{ role: @js($user['role']['name'] ?? ''), labels: @js(collect($meta['roles'])->pluck('label', 'name')) }">
                        <span class="min-w-0 flex-1 basis-56">
                            <span class="block font-medium">{{ $user['name'] }} @if ($user['is_me']) <span class="text-sm font-normal text-muted">(you)</span> @endif</span>
                            <span class="block break-all text-sm text-muted">{{ $user['email'] }}</span>
                            <span class="mt-1 flex flex-wrap gap-2">
                                @if ($user['status'] !== 'active')
                                    <x-portal.chip tone="danger">{{ ucfirst($user['status']) }}</x-portal.chip>
                                @endif
                                @if ($user['must_change_password'] ?? false)
                                    <x-portal.chip tone="info">Must choose a password</x-portal.chip>
                                @endif
                                @if ($user['staff'] ?? null)
                                    <a href="{{ route('admin.staff.show', $user['staff']['public_id']) }}" class="text-sm text-accent-ink underline underline-offset-4">Staff record</a>
                                @else
                                    <x-portal.chip tone="muted">No staff record</x-portal.chip>
                                @endif
                            </span>
                            @unless ($user['is_me'])
                                <span class="mt-1 flex flex-wrap gap-1">
                                    <x-portal.confirm :title="'Reset '.$user['name'].'\'s password?'" body="They're signed out everywhere and get a temporary password, shown to you once. They choose their own at their next sign-in." confirm="Reset password"
                                                      :action="'resetPassword(\''.$user['public_id'].'\')'"
                                                      class="inline-flex min-h-11 items-center rounded-md px-3 text-sm text-accent-ink hover:bg-hover">Reset password<span class="sr-only"> for {{ $user['name'] }}</span></x-portal.confirm>
                                    @if ($user['status'] === 'active')
                                        <x-portal.confirm :title="'Suspend '.$user['name'].'?'" body="They're signed out everywhere and can't sign in until you reactivate the account. Nothing is deleted." confirm="Suspend"
                                                          :action="'setStatus(\''.$user['public_id'].'\', \'suspended\')'" :danger="true"
                                                          class="inline-flex min-h-11 items-center rounded-md px-3 text-sm text-danger hover:bg-hover">Suspend<span class="sr-only"> {{ $user['name'] }}</span></x-portal.confirm>
                                    @else
                                        <x-portal.confirm :title="'Reactivate '.$user['name'].'?'" body="They can sign in again with their password." confirm="Reactivate"
                                                          :action="'setStatus(\''.$user['public_id'].'\', \'active\')'"
                                                          class="inline-flex min-h-11 items-center rounded-md px-3 text-sm text-accent-ink hover:bg-hover">Reactivate<span class="sr-only"> {{ $user['name'] }}</span></x-portal.confirm>
                                    @endif
                                </span>
                            @endunless
                        </span>
                        @if ($user['is_me'])
                            <span class="text-sm">{{ $user['role']['label'] ?? 'No role' }} <span class="block text-muted">You can't change your own role.</span></span>
                        @else
                            <span class="flex flex-wrap items-center gap-2">
                                <label for="role-{{ $user['public_id'] }}" class="sr-only">Role for {{ $user['name'] }}</label>
                                <select id="role-{{ $user['public_id'] }}" x-model="role" class="min-h-11 rounded-md border border-line bg-surface px-3">
                                    @unless ($user['role'])
                                        <option value="">No role</option>
                                    @endunless
                                    @foreach ($meta['roles'] as $option)
                                        <option value="{{ $option['name'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                                <button type="button" x-bind:disabled="role === @js($user['role']['name'] ?? '') || role === ''"
                                        x-on:click="$refs.confirm.showModal()"
                                        class="inline-flex min-h-11 items-center rounded-full bg-accent px-4 font-medium text-on-accent hover:opacity-90 disabled:opacity-40">
                                    Change role<span class="sr-only"> for {{ $user['name'] }}</span>
                                </button>
                                <dialog x-ref="confirm" aria-labelledby="confirm-{{ $user['public_id'] }}" class="app-dialog bg-raised p-6 text-ink shadow-xl">
                                    <h2 id="confirm-{{ $user['public_id'] }}" class="text-lg font-semibold">
                                        Make {{ $user['name'] }} <span x-text="labels[role]"></span>?
                                    </h2>
                                    <p class="mt-2 text-muted">They'll have that role's permissions from their next action. The change is recorded in the audit log.</p>
                                    <div class="mt-6 flex flex-wrap justify-end gap-2">
                                        <button type="button" x-on:click="$refs.confirm.close()" autofocus class="inline-flex min-h-11 items-center rounded-full px-5 font-medium text-accent-ink hover:bg-hover">Cancel</button>
                                        <button type="button" x-on:click="$refs.confirm.close(); $wire.changeRole(@js($user['public_id']), role)"
                                                class="inline-flex min-h-11 items-center rounded-full bg-accent px-5 font-medium text-on-accent hover:opacity-90">Change role</button>
                                    </div>
                                </dialog>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
            <x-portal.pager :meta="$meta" />
        @endif

        <x-portal.toast :message="$savedMessage" :nonce="$toastNonce" />
    @endif
</div>
