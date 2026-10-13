<div>
    <x-portal.back :href="route('admin.section', 'administration')">Administration</x-portal.back>
    <h1 class="sr-only">Roles &amp; permissions</h1>

    @if ($noProfile || ($errorMessage && ! $meta))
        <x-portal.error :message="$errorMessage ?? 'Only Administrators can change roles and permissions.'" />
    @elseif ($meta)
        <p class="mb-4 text-muted">
            Choose what Managers and Staff can view, add, edit and delete. Changes apply from each person's next action, in the app and on the web.
            Adding, editing or deleting always includes viewing.
        </p>

        <section class="mb-6 rounded-xl border border-line bg-raised p-5" aria-labelledby="always-admin">
            <h2 id="always-admin" class="font-semibold">Always Administrators only</h2>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                @foreach ($meta['locked'] as $locked)
                    <li>{{ $locked['label'] }}</li>
                @endforeach
                <li>{{ $meta['administrator_only'] }}</li>
            </ul>
            <p class="mt-2 text-sm text-muted">Administrators always have every permission.</p>
        </section>

        <div class="space-y-4">
            @foreach ($meta['modules'] as $module)
                <section class="rounded-xl border border-line bg-raised" aria-labelledby="module-{{ $loop->index }}">
                    <div class="border-b border-line px-5 py-3">
                        <h2 id="module-{{ $loop->index }}" class="font-semibold">{{ $module['title'] }}</h2>
                        @if ($module['note'])
                            <p class="mt-1 text-sm text-muted">{{ $module['note'] }}</p>
                        @endif
                    </div>
                    <ul class="divide-y divide-line">
                        @foreach ($module['permissions'] as $permission)
                            <li class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 px-5 py-3">
                                <span class="min-w-0 flex-1 basis-56">{{ $permission['label'] }}</span>
                                <span class="flex flex-wrap items-center gap-x-5 gap-y-1">
                                    @foreach (array_keys($saved) as $role)
                                        <label class="inline-flex min-h-11 items-center gap-2">
                                            <input type="checkbox" wire:model.live="grants.{{ $role }}" value="{{ $permission['name'] }}" class="size-5 accent-[var(--color-accent)]">
                                            {{ $roleLabels[$role] ?? ucfirst($role) }}<span class="sr-only">: {{ $permission['label'] }}</span>
                                        </label>
                                    @endforeach
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>

        @if ($errorMessage)
            <p role="alert" class="mt-4 font-medium text-danger">{{ $errorMessage }}</p>
        @endif

        {{-- Save, with every change listed first (an in-app dialog, as in 34F). --}}
        <div class="sticky bottom-0 mt-6 flex flex-wrap items-center gap-2 border-t border-line bg-surface py-3" x-data>
            <button type="button" x-on:click="$refs.confirm.showModal()" @disabled($changes === [])
                    class="inline-flex min-h-11 items-center rounded-full bg-accent px-5 font-medium text-on-accent hover:opacity-90 disabled:opacity-40">
                Save changes
            </button>
            @if ($changes !== [])
                <button type="button" wire:click="discard" class="inline-flex min-h-11 items-center rounded-full px-4 font-medium text-accent-ink hover:bg-hover">Undo changes</button>
                <span class="text-sm text-muted">{{ count($changes) === 1 ? '1 role changed' : count($changes).' roles changed' }}, not saved yet</span>
            @endif
            <button type="button" wire:click="resetToDefaults" class="ml-auto inline-flex min-h-11 items-center rounded-full px-4 font-medium text-accent-ink hover:bg-hover">Reset to defaults</button>

            <dialog x-ref="confirm" aria-labelledby="confirm-title" class="app-dialog bg-raised p-6 text-ink shadow-xl">
                <h2 id="confirm-title" class="text-lg font-semibold">Save these changes?</h2>
                <div class="mt-3 max-h-[60vh] space-y-3 overflow-y-auto text-sm">
                    @foreach ($changes as $change)
                        <div>
                            <p class="font-semibold">{{ $change['role'] }}</p>
                            <ul class="mt-1 space-y-1">
                                @foreach ($change['added'] as $label)
                                    <li><span class="font-medium text-success">Can now:</span> {{ $label }}</li>
                                @endforeach
                                @foreach ($change['removed'] as $label)
                                    <li><span class="font-medium text-danger">No longer:</span> {{ $label }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-sm text-muted">Every change is recorded in the audit log.</p>
                <div class="mt-6 flex flex-wrap justify-end gap-2">
                    <button type="button" x-on:click="$refs.confirm.close()" autofocus class="inline-flex min-h-11 items-center rounded-full px-5 font-medium text-accent-ink hover:bg-hover">Cancel</button>
                    <button type="button" x-on:click="$refs.confirm.close(); $wire.save()" class="inline-flex min-h-11 items-center rounded-full bg-accent px-5 font-medium text-on-accent hover:opacity-90">Save</button>
                </div>
            </dialog>
        </div>

        <x-portal.toast :message="$savedMessage" :nonce="$toastNonce" />
    @endif
</div>
