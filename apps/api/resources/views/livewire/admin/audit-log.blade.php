@php use App\Support\Portal\Format; @endphp
<div>
    <x-portal.back :href="route('admin.section', 'administration')">Administration</x-portal.back>
    <h1 class="sr-only">Audit log</h1>

    @if ($noProfile || $errorMessage)
        <x-portal.error :message="$errorMessage ?? 'Only Administrators can see the audit log.'" />
    @else
        <p class="mb-4 text-muted">Every recorded change, newest first: who did it, what changed, and from where.</p>

        <div class="mb-4">
            <label for="audit-action" class="block text-sm font-medium">Action</label>
            <select id="audit-action" wire:model.live="action" class="mt-1 min-h-11 w-full rounded-md border border-line bg-raised px-3 sm:max-w-sm">
                <option value="">All actions</option>
                @foreach ($actions as $value)
                    <option value="{{ $value }}">{{ $value }}</option>
                @endforeach
            </select>
        </div>

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
                            {{ $entry['actor']['name'] ?? 'No one signed in' }}
                            · {{ $entry['entity_type'] }}@if ($entry['entity_public_id']) <span class="font-mono break-all">{{ $entry['entity_public_id'] }}</span>@endif
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
