<div>
    <x-portal.back :href="route('admin.section', 'administration')">Administration</x-portal.back>
    <h1 class="sr-only">Users and roles</h1>

    @if ($noProfile || ($errorMessage && ! $meta))
        <x-portal.error :message="$errorMessage ?? 'Only Administrators can change roles.'" />
    @else
        <p class="mb-4 text-muted">A person's role decides what they can do, as set on <a href="{{ route('admin.roles') }}" class="text-accent-ink underline underline-offset-4">Roles &amp; permissions</a>. A change applies from their next action.</p>

        <div class="mb-4">
            <label for="user-search" class="block text-sm font-medium">Search by name or email</label>
            <input id="user-search" type="search" wire:model.live.debounce.400ms="search" autocomplete="off"
                   class="mt-1 min-h-11 w-full rounded-md border border-line bg-raised px-3 sm:max-w-sm">
        </div>

        @if ($errorMessage)
            <p role="alert" class="mb-4 font-medium text-danger">{{ $errorMessage }}</p>
        @endif

        @if ($users === [])
            <p class="rounded-xl border border-line bg-raised p-5 text-muted">No accounts match.</p>
        @else
            <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-raised">
                @foreach ($users as $user)
                    <li wire:key="user-{{ $user['public_id'] }}" class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 px-5 py-3"
                        x-data="{ role: @js($user['role']['name'] ?? ''), labels: @js(collect($meta['roles'])->pluck('label', 'name')) }">
                        <span class="min-w-0 flex-1 basis-56">
                            <span class="block font-medium">{{ $user['name'] }} @if ($user['is_me']) <span class="text-sm font-normal text-muted">(you)</span> @endif</span>
                            <span class="block break-all text-sm text-muted">{{ $user['email'] }}</span>
                            @if ($user['status'] !== 'active')
                                <x-portal.chip tone="muted">{{ ucfirst($user['status']) }}</x-portal.chip>
                            @endif
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
