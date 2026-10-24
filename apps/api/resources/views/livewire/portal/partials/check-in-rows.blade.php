<ul class="divide-y divide-line overflow-hidden card">
    @foreach ($items as $checkIn)
        @php
            $when = $companyDay ? \App\Support\Portal\Format::companyTime($checkIn['checked_in_at'], $companyDay['date'], $companyDay['utc_offset']) : null;
            $line = implode(' · ', array_filter([$when, $checkIn['accuracy_meters'] !== null ? '±'.round((float) $checkIn['accuracy_meters']).' m' : null]));
        @endphp
        <li wire:key="check-in-{{ $checkIn['public_id'] }}" class="px-4 py-3">
            <span class="block font-semibold">{{ filled($checkIn['location_label'] ?? null) ? $checkIn['location_label'] : 'No place name' }}</span>
            <span class="block text-sm">{{ $line }}</span>
            @if (filled($checkIn['note'] ?? null))
                <span class="block text-sm">{{ $checkIn['note'] }}</span>
            @endif
            <span class="block text-sm text-muted">{{ number_format((float) $checkIn['latitude'], 5, '.', '') }}, {{ number_format((float) $checkIn['longitude'], 5, '.', '') }}</span>
        </li>
    @endforeach
</ul>
