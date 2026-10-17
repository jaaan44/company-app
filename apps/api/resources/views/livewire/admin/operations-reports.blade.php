@php use App\Support\Portal\Format; use App\Livewire\Admin\OperationsReports; @endphp
<div>
    <x-portal.back :href="route('admin.section', 'operations')">Operations</x-portal.back>
    <h1 class="sr-only">{{ $kind === 'service' ? 'Service reports' : 'Incidents' }}</h1>

    @if ($errorMessage && ! $meta)
        <x-portal.error :message="$errorMessage" />
    @else
        @php($control = 'field mt-1 max-w-full')
        <div class="mb-4 flex flex-wrap items-end gap-3">
            <div>
                <label for="op-status" class="block text-sm font-semibold">Status</label>
                <select id="op-status" wire:model.live="status" class="{{ $control }}">
                    @foreach (OperationsReports::STATUSES[$kind] as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                    <option value="all">All</option>
                </select>
            </div>
            @if ($kind === 'incidents')
                <div>
                    <label for="op-severity" class="block text-sm font-semibold">Severity</label>
                    <select id="op-severity" wire:model.live="severity" class="{{ $control }}">
                        <option value="">All</option>
                        @foreach (OperationsReports::SEVERITIES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div>
                <label for="op-from" class="block text-sm font-semibold">From</label>
                <input id="op-from" type="date" wire:model.live="from" class="{{ $control }}">
            </div>
            <div>
                <label for="op-to" class="block text-sm font-semibold">To</label>
                <input id="op-to" type="date" wire:model.live="to" class="{{ $control }}">
            </div>
            @if ($status !== ($kind === 'service' ? 'submitted' : 'open') || $severity !== '' || $from !== '' || $to !== '')
                <button type="button" wire:click="clearFilters" class="btn btn-text btn-compact">Clear filters</button>
            @endif
        </div>

        @if ($reports === [])
            <x-portal.empty>Nothing matches.</x-portal.empty>
        @else
            <p class="mb-2 text-sm text-muted">{{ $meta['total'] ?? count($reports) }} {{ ($meta['total'] ?? count($reports)) === 1 ? 'report' : 'reports' }}</p>
            <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-raised">
                @foreach ($reports as $report)
                    <li wire:key="op-{{ $report['public_id'] }}">
                        <a href="{{ route($kind === 'service' ? 'admin.service-reports.show' : 'admin.incidents.show', $report['public_id']) }}" class="chevron flex flex-wrap items-center justify-between gap-x-6 gap-y-1 px-5 py-3 hover:bg-hover">
                            <span class="min-w-0 flex-1 basis-56">
                                @if ($kind === 'service')
                                    <span class="block font-medium">{{ $report['client']['name'] }} · {{ Format::date($report['service_date']) }}</span>
                                    <span class="block text-sm text-muted">{{ $report['creator']['display_name'] ?? '' }}@if ($report['project']) · {{ $report['project']['name'] }}@endif</span>
                                @else
                                    <span class="block font-medium">{{ Format::incidentType($report['incident_type']) }} · {{ Format::dateTime($report['occurred_at'], $timezone) }}</span>
                                    <span class="block line-clamp-1 text-sm text-muted">{{ $report['reporter']['display_name'] ?? '' }} · {{ $report['description'] }}</span>
                                @endif
                            </span>
                            <span class="flex flex-wrap gap-2">
                                @if ($kind === 'incidents')
                                    <x-portal.chip :tone="Format::severityTone($report['severity'])">{{ Format::severity($report['severity']) }}</x-portal.chip>
                                @endif
                                <x-portal.chip :tone="Format::reportTone($report['status'])">{{ $kind === 'service' ? Format::serviceStatus($report['status']) : Format::incidentStatus($report['status']) }}</x-portal.chip>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
            <x-portal.pager :meta="$meta" />
        @endif
    @endif
</div>
