<ul class="divide-y divide-line overflow-hidden card">
    @foreach ($items as $entry)
        <li wire:key="status-{{ $entry['recorded_at'] }}-{{ $loop->index }}" class="px-4 py-3">
            <x-portal.status-dot :status="$entry['status']" />
            @if ($companyDay)
                <span class="block text-sm text-muted">{{ \App\Support\Portal\Format::companyTime($entry['recorded_at'], $companyDay['date'], $companyDay['utc_offset']) }}</span>
            @endif
        </li>
    @endforeach
</ul>
