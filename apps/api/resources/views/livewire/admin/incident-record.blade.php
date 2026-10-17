@php use App\Support\Portal\Format; use App\Livewire\Admin\IncidentRecord; @endphp
<div>
    <x-portal.back :href="route('admin.incidents')">Incidents</x-portal.back>

    @if ($notFound)
        <h1 class="sr-only">Incident</h1>
        <x-portal.notice>This incident no longer exists.</x-portal.notice>
    @elseif ($errorMessage && ! $report)
        <h1 class="sr-only">Incident</h1>
        <x-portal.error :message="$errorMessage" />
    @elseif ($report)
        <h1 class="text-xl font-semibold">{{ Format::incidentType($report['incident_type']) }} · {{ Format::dateTime($report['occurred_at'], $timezone) }}</h1>
        <p class="mt-2 flex flex-wrap gap-2">
            <x-portal.chip :tone="Format::severityTone($report['severity'])">{{ Format::severity($report['severity']) }}</x-portal.chip>
            <x-portal.chip :tone="Format::reportTone($report['status'])">{{ Format::incidentStatus($report['status']) }}</x-portal.chip>
        </p>

        @if ($generalError)
            <p role="alert" class="mt-4 rounded-md bg-danger-soft p-3 font-medium text-danger">{{ $generalError }}</p>
        @endif

        <section aria-labelledby="details" class="mt-6 rounded-xl border border-line bg-raised px-5">
            <h2 id="details" class="pt-4 font-semibold">Details</h2>
            <dl>
                <x-portal.info-row label="Reported by" :value="$report['reporter']['display_name'] ?? null" />
                <x-portal.info-row label="Handled by" :value="$report['assigned_to']['display_name'] ?? null" />
                <x-portal.info-row label="Location" :value="$report['location']" />
                <x-portal.info-row label="Client" :value="$report['client']['name'] ?? null" />
                <x-portal.info-row label="Project" :value="$report['project']['name'] ?? null" />
                @foreach (['description' => 'What happened', 'immediate_action_taken' => 'Immediate action', 'people_involved' => 'People involved', 'witness_notes' => 'Witness notes', 'root_cause' => 'Root cause', 'corrective_action' => 'Corrective action', 'preventive_action' => 'Preventive action', 'resolution' => 'Resolution'] as $field => $label)
                    @if (filled($report[$field] ?? null))
                        <div class="border-b border-line py-3 last:border-0">
                            <dt class="text-sm text-muted">{{ $label }}</dt>
                            <dd class="mt-1 whitespace-pre-line">{{ $report[$field] }}</dd>
                        </div>
                    @endif
                @endforeach
            </dl>
        </section>

        @if ($actions !== [])
            <section aria-label="Actions" class="mt-6 rounded-xl border border-line bg-raised p-5">
                @if ($confirming === null)
                    <div class="flex flex-wrap gap-3">
                        @foreach ($actions as $action)
                            <button type="button" wire:click="ask('{{ $action }}')"
                                    class="btn {{ $loop->last && $action !== 'reopen' ? 'btn-primary' : 'btn-secondary' }}">{{ IncidentRecord::ACTIONS[$action] }}</button>
                        @endforeach
                    </div>
                @else
                    <form wire:submit="confirm" class="space-y-4" novalidate>
                        <h2 class="font-semibold">{{ IncidentRecord::ACTIONS[$confirming] }}</h2>
                        @if (in_array($confirming, ['assign', 'reassign', 'start-investigation'], true))
                            @include('livewire.admin.partials.staff-picker', ['label' => $confirming === 'start-investigation' ? 'Who investigates (optional)' : 'Who handles it'])
                        @endif
                        @if ($confirming === 'resolve')
                            <p class="text-sm text-muted">Resolving needs a resolution, and a corrective action unless an immediate action is already recorded above.</p>
                            @include('livewire.portal.partials.field', ['id' => 'inc-resolution', 'label' => 'Resolution', 'model' => 'resolution', 'error' => 'resolution', 'textarea' => true, 'rows' => 3, 'max' => 5000, 'optional' => true])
                            @include('livewire.portal.partials.field', ['id' => 'inc-corrective', 'label' => 'Corrective action', 'model' => 'correctiveAction', 'error' => 'correctiveAction', 'textarea' => true, 'rows' => 3, 'max' => 5000, 'optional' => true])
                        @endif
                        @include('livewire.portal.partials.field', ['id' => 'inc-note', 'label' => 'Note for the history', 'model' => 'note', 'error' => 'note', 'textarea' => true, 'rows' => 2, 'max' => 1000, 'optional' => true])
                        <div class="flex flex-wrap gap-3">
                            <button type="submit" class="btn btn-primary">{{ IncidentRecord::ACTIONS[$confirming] }}</button>
                            <button type="button" wire:click="dismiss" class="btn btn-text">Back</button>
                        </div>
                    </form>
                @endif
            </section>
        @endif

        @include('livewire.admin.partials.report-files-history', ['kind' => 'incidents'])
        <x-portal.toast :message="$savedMessage" :nonce="$toastNonce" />
    @endif
</div>
