@php use App\Support\Portal\Format; @endphp
<div>
    <x-portal.back :href="route('admin.section', 'administration')">Administration</x-portal.back>
    <h1 class="sr-only">Audit log</h1>

    @if ($noProfile || $errorMessage)
        <x-portal.error :message="$errorMessage ?? 'Only Administrators can see the audit log.'" />
    @else
        <p class="mb-4 text-muted">Every recorded change, newest first: who did it, what changed, and from where.</p>

        @php($control = 'mt-1 min-h-11 rounded-md border border-line bg-raised px-3')
        <div class="mb-4 flex flex-wrap items-end gap-3">
            <div class="min-w-0 basis-64">
                <label for="audit-action" class="block text-sm font-medium">Action</label>
                <select id="audit-action" wire:model.live="action" class="{{ $control }} w-full">
                    <option value="">All actions</option>
                    @foreach ($actions as $value)
                        <option value="{{ $value }}">{{ $value }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="audit-from" class="block text-sm font-medium">From (UTC)</label>
                <input id="audit-from" type="date" wire:model.live="from" class="{{ $control }}">
            </div>
            <div>
                <label for="audit-to" class="block text-sm font-medium">To (UTC)</label>
                <input id="audit-to" type="date" wire:model.live="to" class="{{ $control }}">
            </div>
            <div>
                <label for="audit-record" class="block text-sm font-medium">Record ID</label>
                <input id="audit-record" type="search" wire:model.live.debounce.500ms="record" autocomplete="off" class="{{ $control }} font-mono">
            </div>
        </div>
        @if ($actor !== '' || $action !== '' || $from !== '' || $to !== '' || $record !== '')
            <p class="mb-4 flex flex-wrap items-center gap-2 text-sm">
                @if ($actor !== '')
                    <span>Only changes by <strong>{{ $actorName !== '' ? $actorName : $actor }}</strong>.</span>
                @endif
                <button type="button" wire:click="clearFilters" class="inline-flex min-h-11 items-center rounded-full px-3 font-medium text-accent-ink hover:bg-hover">Clear filters</button>
            </p>
        @endif

        @if ($entries === [])
            <p class="rounded-xl border border-line bg-raised p-5 text-muted">No entries.</p>
        @else
            <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-raised">
                @foreach ($entries as $entry)
                    <li class="px-5 py-3">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4">
                            <span class="font-mono text-sm font-medium break-all">{{ $entry['action'] }}</span>
                            <span class="text-sm text-muted">{{ Format::dateTime($entry['created_at'], $utcOffset) }}</span>
                        </div>
                        <p class="text-sm">
                            @if ($entry['actor'])
                                <button type="button" wire:click="filterActor(@js($entry['actor']['public_id']), @js($entry['actor']['name']))" class="inline-flex min-h-6 items-center text-accent-ink underline underline-offset-4">{{ $entry['actor']['name'] }}<span class="sr-only"> — show only their changes</span></button>
                            @else
                                No one signed in
                            @endif
                            · {{ $entry['entity_type'] }}@if ($entry['entity_public_id']) <button type="button" wire:click="filterRecord(@js($entry['entity_public_id']))" class="inline-flex min-h-6 items-center font-mono break-all text-accent-ink underline underline-offset-4">{{ $entry['entity_public_id'] }}<span class="sr-only"> — show this record's history</span></button>@endif
                            · from {{ $entry['source'] }}
                        </p>
                        @if ($entry['changed_fields'] || $entry['before'] || $entry['after'])
                            <details class="mt-1 text-sm">
                                <summary class="cursor-pointer text-accent-ink">Details{{ $entry['changed_fields'] ? ': '.implode(', ', $entry['changed_fields']) : '' }}</summary>
                                <dl class="mt-2 space-y-2">
                                    @foreach (['before' => 'Before', 'after' => 'After'] as $key => $label)
                                        @if ($entry[$key])
                                            <div>
                                                <dt class="font-medium">{{ $label }}</dt>
                                                <dd><pre class="whitespace-pre-wrap break-all rounded-md bg-surface p-2 text-xs">{{ json_encode($entry[$key], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre></dd>
                                            </div>
                                        @endif
                                    @endforeach
                                </dl>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>
            <x-portal.pager :meta="$meta" />
        @endif
    @endif
</div>
