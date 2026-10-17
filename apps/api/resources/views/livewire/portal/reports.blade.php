@php use App\Support\Portal\Format; @endphp
<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="sr-only">Reports</h1>
        @unless ($noProfile || $errorMessage)
            @if ($currentType === 'incidents')
                <x-portal.fab :href="route('portal.incident.new')" label="Report an incident" />
            @else
                <x-portal.fab :href="route('portal.service-report.new')" label="New service report" />
            @endif
        @endunless
    </div>

    <div role="group" aria-label="Which reports" class="mb-4 inline-flex rounded-full border border-line bg-raised p-1">
        @foreach (['service' => 'Service reports', 'incidents' => 'Incidents'] as $value => $label)
            <button type="button" wire:click="show('{{ $value }}')" aria-pressed="{{ $currentType === $value ? 'true' : 'false' }}"
                    class="inline-flex min-h-11 items-center rounded-full px-4 {{ $currentType === $value ? 'bg-accent-soft font-semibold text-accent-ink' : 'hover:bg-hover' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if ($noProfile)
        <x-portal.no-profile />
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif (count($reports) === 0)
        <x-portal.notice>{{ $currentType === 'incidents' ? 'No incidents reported.' : "You haven't written a service report yet." }}</x-portal.notice>
    @else
        <ul class="divide-y divide-line rounded-xl border border-line bg-raised">
            @foreach ($reports as $report)
                <li wire:key="report-{{ $report['public_id'] }}">
                    @if ($currentType === 'incidents')
                        <a href="{{ route('portal.incident', $report['public_id']) }}" class="chevron block min-h-11 px-5 py-3 hover:bg-hover">
                            <span class="block font-medium">{{ Format::incidentType($report['incident_type']) }}</span>
                            <span class="block text-sm text-muted">{{ implode(' · ', array_filter([$companyDay ? Format::reportTime($report['occurred_at'], $companyDay['utc_offset']) : null, $report['location'] ?? null])) }}</span>
                            <span class="mt-1 flex flex-wrap gap-2 text-sm">
                                <x-portal.chip :tone="Format::severityTone($report['severity'])"><span class="sr-only">Severity: </span>{{ Format::severity($report['severity']) }}</x-portal.chip>
                                <x-portal.chip :tone="Format::reportTone($report['status'])">{{ Format::incidentStatus($report['status']) }}</x-portal.chip>
                            </span>
                        </a>
                    @else
                        <a href="{{ route('portal.service-report', $report['public_id']) }}" class="chevron block min-h-11 px-5 py-3 hover:bg-hover">
                            <span class="block font-medium">{{ $report['client']['name'] ?? '' }}</span>
                            <span class="block text-sm text-muted">{{ implode(' · ', array_filter([Format::reportDate($report['service_date']), $report['project']['name'] ?? null])) }}</span>
                            <span class="mt-1 block text-sm"><x-portal.chip :tone="Format::reportTone($report['status'])">{{ Format::serviceStatus($report['status']) }}</x-portal.chip></span>
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
        <x-portal.pager :meta="$meta" />
    @endif
</div>
