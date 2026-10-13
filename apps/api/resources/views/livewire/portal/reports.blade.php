@php use App\Support\Portal\Format; @endphp
<div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold">Reports</h1>
        @unless ($noProfile || $errorMessage)
            @if ($currentType === 'incidents')
                <a href="{{ route('portal.incident.new') }}" class="inline-flex min-h-11 items-center rounded-md bg-accent px-4 font-medium text-on-accent hover:opacity-90">Report an incident</a>
            @else
                <a href="{{ route('portal.service-report.new') }}" class="inline-flex min-h-11 items-center rounded-md bg-accent px-4 font-medium text-on-accent hover:opacity-90">New service report</a>
            @endif
        @endunless
    </div>

    @if (session('status'))
        <p role="status" class="mb-4 rounded-md border border-line bg-raised p-3">{{ session('status') }}</p>
    @endif

    <div role="group" aria-label="Which reports" class="mb-4 inline-flex rounded-md border border-line bg-raised p-1">
        @foreach (['service' => 'Service reports', 'incidents' => 'Incidents'] as $value => $label)
            <button type="button" wire:click="show('{{ $value }}')" aria-pressed="{{ $currentType === $value ? 'true' : 'false' }}"
                    class="inline-flex min-h-11 items-center rounded px-4 {{ $currentType === $value ? 'bg-accent-soft font-semibold text-accent-ink' : 'hover:bg-hover' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if ($noProfile)
        <p class="rounded-lg border border-line bg-raised p-4">No staff profile is linked to this account.</p>
    @elseif ($errorMessage)
        <x-portal.error :message="$errorMessage" />
    @elseif (count($reports) === 0)
        <p class="rounded-lg border border-line bg-raised p-4">{{ $currentType === 'incidents' ? 'No incidents reported.' : "You haven't written a service report yet." }}</p>
    @else
        <ul class="divide-y divide-line rounded-xl border border-line bg-raised">
            @foreach ($reports as $report)
                <li wire:key="report-{{ $report['public_id'] }}">
                    @if ($currentType === 'incidents')
                        <a href="{{ route('portal.incident', $report['public_id']) }}" class="block min-h-11 px-5 py-3 hover:bg-hover">
                            <span class="block font-medium">{{ Format::incidentType($report['incident_type']) }}</span>
                            <span class="block text-sm text-muted">{{ implode(' · ', array_filter([$companyDay ? Format::reportTime($report['occurred_at'], $companyDay['utc_offset']) : null, $report['location'] ?? null])) }}</span>
                            <span class="mt-1 flex flex-wrap gap-2 text-sm">
                                <x-portal.chip :tone="Format::severityTone($report['severity'])"><span class="sr-only">Severity: </span>{{ Format::severity($report['severity']) }}</x-portal.chip>
                                <x-portal.chip :tone="Format::reportTone($report['status'])">{{ Format::incidentStatus($report['status']) }}</x-portal.chip>
                            </span>
                        </a>
                    @else
                        <a href="{{ route('portal.service-report', $report['public_id']) }}" class="block min-h-11 px-5 py-3 hover:bg-hover">
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
