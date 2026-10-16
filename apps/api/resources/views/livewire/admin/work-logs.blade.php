@php use App\Support\Portal\Format; @endphp
<div>
    <x-portal.back :href="route('admin.section', 'work')">Work</x-portal.back>
    <h1 class="sr-only">Work logs</h1>

    @if ($errorMessage && ! $meta)
        <x-portal.error :message="$errorMessage" />
    @else
        <div class="mb-4 flex flex-wrap items-end gap-4 rounded-xl border border-line bg-raised p-5">
            <div class="min-w-0 flex-1 basis-72">
                @include('livewire.admin.partials.staff-picker', ['label' => 'Person (optional)'])
            </div>
            <div>
                <label for="wl-from" class="mb-1 block text-sm font-semibold">From</label>
                <input id="wl-from" type="date" wire:model.live="from" class="min-h-11 rounded-md border border-line bg-surface px-3">
            </div>
            <div>
                <label for="wl-to" class="mb-1 block text-sm font-semibold">To</label>
                <input id="wl-to" type="date" wire:model.live="to" class="min-h-11 rounded-md border border-line bg-surface px-3">
            </div>
        </div>

        @if ($generalError && $editing === null)
            <p role="alert" class="mb-4 font-medium text-danger">{{ $generalError }}</p>
        @endif

        @if ($logs === [])
            <p class="rounded-xl border border-line bg-raised p-5 text-muted">No work logs match.</p>
        @else
            <p class="mb-2 text-sm text-muted">{{ $meta['total'] ?? count($logs) }} {{ ($meta['total'] ?? count($logs)) === 1 ? 'log' : 'logs' }}</p>
            <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-raised">
                @foreach ($logs as $log)
                    <li wire:key="wl-{{ $log['public_id'] }}" class="px-5 py-3">
                        @if ($editing === $log['public_id'])
                            <form wire:submit="save" class="space-y-4" novalidate>
                                <p class="font-medium">{{ $log['staff']['display_name'] }} @if ($log['task']) · {{ $log['task']['title'] }} @endif</p>
                                @if ($generalError)
                                    <p role="alert" class="font-medium text-danger">{{ $generalError }}</p>
                                @endif
                                <div class="flex flex-wrap items-end gap-3">
                                    @include('livewire.portal.partials.field', ['id' => 'wl-date', 'label' => 'Date', 'model' => 'workDate', 'error' => 'workDate', 'type' => 'date'])
                                    <div>
                                        <label for="wl-hours" class="mb-1 block text-sm font-semibold">Hours</label>
                                        <input id="wl-hours" type="number" min="0" max="24" wire:model="hours" @error('hours') aria-invalid="true" aria-describedby="wl-hours-error" @enderror class="min-h-11 w-24 rounded-md border border-line bg-raised px-3">
                                    </div>
                                    <div>
                                        <label for="wl-minutes" class="mb-1 block text-sm font-semibold">Minutes</label>
                                        <input id="wl-minutes" type="number" min="0" max="59" wire:model="minutes" class="min-h-11 w-24 rounded-md border border-line bg-raised px-3">
                                    </div>
                                </div>
                                @error('hours') <p id="wl-hours-error" class="text-sm font-medium text-danger">{{ $message }}</p> @enderror
                                @include('livewire.portal.partials.field', ['id' => 'wl-description', 'label' => 'Description', 'model' => 'description', 'error' => 'description', 'textarea' => true, 'rows' => 3, 'max' => 2000])
                                <p class="text-sm text-muted">The person, task and project can't be changed.</p>
                                <div class="flex flex-wrap gap-2">
                                    <button type="submit" class="inline-flex min-h-11 items-center rounded-full bg-accent px-5 font-medium text-on-accent hover:opacity-90">Save correction</button>
                                    <button type="button" wire:click="cancel" class="inline-flex min-h-11 items-center rounded-full px-5 font-medium text-accent-ink hover:bg-hover">Cancel</button>
                                </div>
                            </form>
                        @else
                            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                                <span class="min-w-0 flex-1 basis-56">
                                    <span class="block font-medium">{{ $log['staff']['display_name'] }} · {{ Format::duration((int) $log['duration_minutes']) }}</span>
                                    <span class="block text-sm text-muted">
                                        {{ Format::date($log['work_date']) }}
                                        @if ($log['project']) · {{ $log['project']['name'] }} @endif
                                        @if ($log['task']) · {{ $log['task']['title'] }} @endif
                                    </span>
                                    <span class="mt-1 block line-clamp-2 text-sm">{{ $log['description'] }}</span>
                                </span>
                                @if ($canManage)
                                    <span class="flex gap-1">
                                        <button type="button" wire:click="edit(@js($log['public_id']))" class="inline-flex min-h-11 items-center rounded-md px-3 text-sm text-accent-ink hover:bg-hover">Correct<span class="sr-only"> {{ $log['staff']['display_name'] }}'s log of {{ Format::date($log['work_date']) }}</span></button>
                                        <x-portal.confirm title="Remove this work log?" body="It disappears from their logs and from reports. This can't be undone." confirm="Remove"
                                                          :action="'remove(\''.$log['public_id'].'\')'" :danger="true"
                                                          class="inline-flex min-h-11 items-center rounded-md px-3 text-sm text-danger hover:bg-hover">Remove<span class="sr-only"> {{ $log['staff']['display_name'] }}'s log of {{ Format::date($log['work_date']) }}</span></x-portal.confirm>
                                    </span>
                                @endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
            <x-portal.pager :meta="$meta" />
        @endif

        <x-portal.toast :message="$savedMessage" :nonce="$toastNonce" />
    @endif
</div>
