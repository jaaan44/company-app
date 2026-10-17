<div>
    <x-portal.back :href="route('admin.section', 'reports')">Reports</x-portal.back>
    <h1 class="sr-only">{{ $definition['title'] }} report</h1>

    @php($control = 'field mt-1 max-w-full')
    <div class="mb-4 flex flex-wrap items-end gap-3">
        @foreach ($definition['filters'] as $name => $options)
            <div>
                @if ($options === 'bool')
                    <label class="flex min-h-11 items-center gap-2 pt-6">
                        <input type="checkbox" value="1" wire:model.live="filters.{{ $name }}" class="size-5 accent-[var(--color-accent)]"> {{ ucfirst($name) }} only
                    </label>
                @else
                    <label for="rf-{{ $name }}" class="block text-sm font-semibold">{{ ucfirst($name) }}</label>
                    @if ($options === 'date')
                        <input id="rf-{{ $name }}" type="date" wire:model.live="filters.{{ $name }}" class="{{ $control }}">
                    @else
                        <select id="rf-{{ $name }}" wire:model.live="filters.{{ $name }}" class="{{ $control }}">
                            <option value="">All</option>
                            @foreach ($options as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    @endif
                @endif
            </div>
        @endforeach
        @if (array_filter($filters) !== [])
            <button type="button" wire:click="clearFilters" class="btn btn-text btn-compact">Clear filters</button>
        @endif
        <a href="{{ $exportUrl }}" data-no-navigate download class="btn btn-primary ml-auto">Download CSV</a>
    </div>

    @if ($errorMessage && ! $meta)
        <x-portal.error :message="$errorMessage" />
    @elseif ($rows === [])
        <x-portal.empty>Nothing matches these filters.</x-portal.empty>
    @else
        <p class="mb-2 text-sm text-muted">{{ $meta['total'] ?? count($rows) }} {{ ($meta['total'] ?? count($rows)) === 1 ? 'row' : 'rows' }}. The CSV has every matching row and more columns.</p>
        <div class="overflow-x-auto rounded-xl border border-line bg-raised" tabindex="0" role="region" aria-label="{{ $definition['title'] }} report rows">
            <table class="w-full min-w-[36rem] text-left text-sm tabular-nums">
                <thead class="border-b border-line">
                    <tr>
                        @foreach (array_keys($definition['columns']) as $label)
                            <th scope="col" class="px-4 py-3 font-semibold">{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($rows as $row)
                        <tr wire:key="row-{{ $row['public_id'] ?? $loop->index }}">
                            @foreach ($definition['columns'] as $cell)
                                <td class="px-4 py-3">{{ $cell($row, $timezone) ?? '—' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <x-portal.pager :meta="$meta" />
    @endif
</div>
