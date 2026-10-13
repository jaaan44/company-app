<ul class="divide-y divide-line rounded-xl border border-line bg-raised">
    @foreach ($items as $entry)
        <li wire:key="status-{{ $entry['recorded_at'] }}-{{ $loop->index }}" class="px-5 py-3">
            <span class="block font-medium">{{ \App\Support\Portal\Format::operationalStatus($entry['status']) }}</span>
            @if ($companyDay)
                <span class="block text-sm text-muted">{{ \App\Support\Portal\Format::companyTime($entry['recorded_at'], $companyDay['date'], $companyDay['utc_offset']) }}</span>
            @endif
        </li>
    @endforeach
</ul>
