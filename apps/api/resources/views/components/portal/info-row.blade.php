@props(['label', 'value' => null, 'copyLabel' => null])
<div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b border-line py-3 last:border-0">
    <dt class="text-sm text-muted">{{ $label }}</dt>
    <dd class="flex flex-wrap items-center gap-2 font-medium">
        <span class="break-all">{{ ($value === null || $value === '') ? 'Not set' : $value }}</span>
        @if ($copyLabel !== null && $value !== null && $value !== '')
            <span x-data="{ copied: false }" class="inline-flex items-center gap-2">
                <button type="button"
                        x-on:click="navigator.clipboard.writeText(@js($value)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                        class="inline-flex min-h-11 items-center rounded-md px-3 text-sm text-accent-ink hover:bg-hover">
                    {{ $copyLabel }}
                </button>
                <span x-show="copied" x-cloak role="status" class="text-sm text-muted">Copied</span>
            </span>
        @endif
    </dd>
</div>
