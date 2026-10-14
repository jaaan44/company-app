<div>
    @if ($publicId === null)
        <x-portal.back :href="route('portal.announcements.manage')">Manage announcements</x-portal.back>
    @else
        <x-portal.back :href="route('portal.announcements.manage.show', $publicId)">Announcement</x-portal.back>
    @endif
    <h1 class="sr-only">{{ $publicId === null ? 'New announcement' : 'Edit draft' }}</h1>

    @if ($notFound)
        <p class="rounded-lg border border-line bg-raised p-4">This announcement no longer exists.</p>
    @elseif ($notDraft)
        <p class="rounded-lg border border-line bg-raised p-4">Only a draft can be edited. Archive this announcement and write a new one instead.</p>
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @else
        <form wire:submit="save" class="space-y-5 rounded-xl border border-line bg-raised p-5" novalidate>
            @if ($generalError)
                <p role="alert" class="font-medium text-danger">{{ $generalError }}</p>
            @endif
            @include('livewire.portal.partials.field', ['id' => 'title', 'label' => 'Title', 'model' => 'title', 'error' => 'title', 'max' => 200])
            @include('livewire.portal.partials.field', ['id' => 'body', 'label' => 'Announcement', 'model' => 'body', 'error' => 'body', 'max' => 10000, 'textarea' => true, 'rows' => 8])

            <fieldset @error('audience') aria-describedby="audience-error" @enderror>
                <legend class="mb-1 text-sm font-semibold">Who should see it?</legend>
                <label class="flex min-h-11 items-center gap-3"><input type="radio" wire:model.live="audience" value="company_wide" class="size-5 accent-[var(--color-accent)]"> Everyone</label>
                <label class="flex min-h-11 items-center gap-3"><input type="radio" wire:model.live="audience" value="scoped" class="size-5 accent-[var(--color-accent)]"> Selected departments or teams</label>
                @if ($audience === 'scoped')
                    <div class="mt-2 grid gap-4 sm:grid-cols-2">
                        <div>
                            <p class="mb-1 text-sm font-semibold">Departments</p>
                            @forelse ($allDepartments as $d)
                                <label class="flex min-h-11 items-center gap-3"><input type="checkbox" wire:model="departments" value="{{ $d['public_id'] }}" class="size-5 accent-[var(--color-accent)]"> {{ $d['name'] }}</label>
                            @empty
                                <p class="text-sm text-muted">None.</p>
                            @endforelse
                        </div>
                        <div>
                            <p class="mb-1 text-sm font-semibold">Teams</p>
                            @forelse ($allTeams as $t)
                                <label class="flex min-h-11 items-center gap-3"><input type="checkbox" wire:model="teams" value="{{ $t['public_id'] }}" class="size-5 accent-[var(--color-accent)]"> {{ $t['name'] }}</label>
                            @empty
                                <p class="text-sm text-muted">None.</p>
                            @endforelse
                        </div>
                    </div>
                @endif
                @error('audience') <p id="audience-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
            </fieldset>

            <div class="flex flex-wrap gap-2">
                <button type="submit" wire:loading.attr="disabled" wire:target="save"
                        class="inline-flex min-h-11 items-center rounded-full bg-accent px-5 font-medium text-on-accent hover:opacity-90 disabled:opacity-60">Save draft</button>
                <a href="{{ $publicId === null ? route('portal.announcements.manage') : route('portal.announcements.manage.show', $publicId) }}" class="inline-flex min-h-11 items-center rounded-full px-5 font-medium text-accent-ink hover:bg-hover">Cancel</a>
            </div>
        </form>
    @endif
</div>
