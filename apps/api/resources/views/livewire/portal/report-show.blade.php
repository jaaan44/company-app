@php
    use App\Support\Portal\Format;
    use App\Livewire\Portal\ReportShow;
@endphp
<div>
    <x-portal.back :href="route('portal.reports', $kind === 'incidents' ? ['type' => 'incidents'] : [])">Reports</x-portal.back>

    @if ($noProfile)
        <p class="rounded-lg border border-line bg-raised p-4">No staff profile is linked to this account.</p>
    @elseif ($errorMessage)
        <h1 class="sr-only">{{ $kind === 'incidents' ? 'Incident' : 'Service report' }}</h1>
        @if (in_array($errorMessage, ['This report is no longer available.', "You don't have access to this report."], true))
            <p class="rounded-lg border border-line bg-raised p-4">{{ $errorMessage }}</p>
        @else
            <x-portal.error :message="$errorMessage" />
        @endif
    @elseif ($report)
        @php
            $offset = $companyDay['utc_offset'] ?? '+00:00';
            $attachments = $report['attachments'] ?? [];
            $sections = $kind === 'incidents'
                ? ['What happened' => 'description', 'Immediate action taken' => 'immediate_action_taken', 'People involved' => 'people_involved', 'Witness notes' => 'witness_notes', 'Root cause' => 'root_cause', 'Corrective action' => 'corrective_action', 'Preventive action' => 'preventive_action', 'Follow-up actions' => 'follow_up_actions', 'Resolution' => 'resolution']
                : ['Work performed' => 'work_performed', 'Findings' => 'findings', 'Recommendations' => 'recommendations', 'Follow-up actions' => 'follow_up_actions', 'Site representative' => 'site_representative_name'];
            $editRoute = $kind === 'incidents' ? route('portal.incident.edit', $report['public_id']) : route('portal.service-report.edit', $report['public_id']);
        @endphp

        @if ($kind === 'incidents')
            <h1 class="text-2xl font-semibold">{{ Format::incidentType($report['incident_type']) }}</h1>
            <p class="mt-2 flex flex-wrap items-center gap-2">
                <x-portal.chip :tone="Format::severityTone($report['severity'])"><span class="sr-only">Severity: </span>{{ Format::severity($report['severity']) }}</x-portal.chip>
                <x-portal.chip :tone="Format::reportTone($report['status'])">{{ Format::incidentStatus($report['status']) }}</x-portal.chip>
            </p>
            <dl class="mt-4 rounded-xl border border-line bg-raised px-5">
                <x-portal.info-row label="When" :value="Format::reportTime($report['occurred_at'], $offset)" />
                <x-portal.info-row label="Location" :value="$report['location'] ?? null" />
                <x-portal.info-row label="Reported by" :value="$report['reporter']['display_name'] ?? null" />
                @if ($report['assigned_to'] ?? null)
                    <x-portal.info-row label="Assigned to" :value="$report['assigned_to']['display_name']" />
                @endif
            </dl>
        @else
            <h1 class="text-2xl font-semibold">Service report · {{ $report['client']['name'] ?? '' }}</h1>
            <p class="mt-2"><x-portal.chip :tone="Format::reportTone($report['status'])">{{ Format::serviceStatus($report['status']) }}</x-portal.chip></p>
            <dl class="mt-4 rounded-xl border border-line bg-raised px-5">
                <x-portal.info-row label="Client" :value="$report['client']['name'] ?? null" />
                <x-portal.info-row label="Project" :value="$report['project']['name'] ?? 'No project'" />
                <x-portal.info-row label="Service date" :value="Format::reportDate($report['service_date'])" />
                <x-portal.info-row label="By" :value="$report['creator']['display_name'] ?? null" />
            </dl>
        @endif

        <div aria-live="polite">
            <x-portal.toast :message="$doneMessage" :nonce="$toastNonce ?? 0" />
            @if ($actionMessage)
                <p role="alert" class="mt-4 rounded-md bg-danger-soft p-3 font-medium text-danger">{{ $actionMessage }}</p>
            @endif
        </div>

        @if ($canEdit || $canReturn)
            <div class="mt-4 flex flex-wrap gap-3">
                @if ($canEdit)
                    <a href="{{ $editRoute }}" class="inline-flex min-h-11 items-center rounded-md border border-line px-4 hover:bg-hover">Edit</a>
                    @if ($kind === 'service')
                        <x-portal.confirm title="Submit this report?" body="Your manager will review it. You can't edit it after submitting." confirm="Submit" action="submitReport()"
                                          class="inline-flex min-h-11 items-center rounded-md bg-accent px-4 font-medium text-on-accent hover:opacity-90">Submit</x-portal.confirm>
                        <x-portal.confirm title="Delete this draft?" body="This can't be undone." confirm="Delete" action="deleteDraft()" :danger="true"
                                          class="inline-flex min-h-11 items-center rounded-md border border-line px-4 text-danger hover:bg-hover">Delete draft</x-portal.confirm>
                    @endif
                @endif
                @if ($canReturn)
                    <x-portal.confirm title="Return to draft?" body="You can then edit it and submit it again." confirm="Return to draft" action="returnToDraft()"
                                      class="inline-flex min-h-11 items-center rounded-md bg-accent px-4 font-medium text-on-accent hover:opacity-90">Return to draft</x-portal.confirm>
                @endif
            </div>
        @endif

        @foreach ($sections as $title => $field)
            @if (filled($report[$field] ?? null))
                <section class="mt-6 rounded-xl border border-line bg-raised p-5" aria-label="{{ $title }}">
                    <h2 class="mb-2 font-semibold">{{ $title }}</h2>
                    <p class="whitespace-pre-line break-words">{{ $report[$field] }}</p>
                </section>
            @endif
        @endforeach

        <section aria-labelledby="files" class="mt-6 rounded-xl border border-line bg-raised p-5">
            <h2 id="files" class="mb-3 font-semibold">Photos and files ({{ count($attachments) }})</h2>
            @if (count($attachments) === 0)
                <p class="text-muted">No photos or files yet.</p>
            @else
                <ul class="grid gap-3 sm:grid-cols-2">
                    @foreach ($attachments as $a)
                        @php
                            $url = route('portal.report-file', ['kind' => $kind, 'publicId' => $report['public_id'], 'attachmentId' => $a['public_id']]);
                            $image = in_array($a['mime_type'], ['image/jpeg', 'image/png'], true);
                        @endphp
                        <li wire:key="file-{{ $a['public_id'] }}" class="rounded-lg border border-line p-3">
                            @if ($image)
                                <a href="{{ $url }}" target="_blank" rel="noopener" class="block">
                                    <img src="{{ $url }}" alt="Photo: {{ $a['original_filename'] }}" loading="lazy" class="max-h-48 w-full rounded object-cover">
                                </a>
                            @endif
                            <p class="mt-2 break-all text-sm font-medium">{{ $a['original_filename'] }}</p>
                            <p class="text-sm text-muted">{{ Format::fileSize((int) $a['size_bytes']) }}</p>
                            <div class="mt-1 flex flex-wrap gap-2">
                                <a href="{{ $url }}" @if ($image) target="_blank" rel="noopener" @endif class="inline-flex min-h-11 items-center rounded-md px-3 text-accent-ink hover:bg-hover">{{ $image ? 'Open photo' : 'Download' }}<span class="sr-only"> {{ $a['original_filename'] }}</span></a>
                                @if ($canEdit)
                                    <x-portal.confirm title="Remove this file?" :body="$a['original_filename']" confirm="Remove" :action="'removeFile(\''.$a['public_id'].'\')'" :danger="true"
                                                      class="inline-flex min-h-11 items-center rounded-md px-3 text-danger hover:bg-hover">Remove<span class="sr-only"> {{ $a['original_filename'] }}</span></x-portal.confirm>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($canEdit)
                <form wire:submit="addFile" class="mt-4 border-t border-line pt-4">
                    <label for="report-file" class="mb-1 block text-sm font-semibold">Add a photo or PDF (JPEG, PNG or PDF, up to 10 MB)</label>
                    <input id="report-file" type="file" wire:model="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf"
                           @error('file') aria-invalid="true" aria-describedby="report-file-error" @enderror
                           class="block w-full text-sm file:mr-3 file:min-h-11 file:rounded-md file:border file:border-line file:bg-raised file:px-4">
                    @error('file') <p id="report-file-error" class="mt-1 text-sm font-medium text-danger">{{ $message }}</p> @enderror
                    <p wire:loading wire:target="file" class="mt-2 text-sm text-muted">Preparing the file…</p>
                    <button type="submit" wire:loading.attr="disabled" wire:target="file,addFile"
                            class="mt-3 inline-flex min-h-11 items-center rounded-md bg-accent px-4 font-medium text-on-accent hover:opacity-90 disabled:opacity-60">Upload</button>
                    <span wire:loading wire:target="addFile" class="ml-2 text-sm text-muted">Uploading…</span>
                </form>
            @endif
        </section>

        <section aria-labelledby="history" class="mt-6 rounded-xl border border-line bg-raised p-5">
            <h2 id="history" class="mb-2 font-semibold">History</h2>
            @if (count($report['history'] ?? []) === 0)
                <p class="text-muted">No history yet.</p>
            @else
                <ol class="divide-y divide-line">
                    @foreach ($report['history'] as $step)
                        <li class="py-2">
                            <span class="block">{{ implode(' · ', array_filter([Format::reportAction($step['action']).(($step['acted_by']['display_name'] ?? null) ? ' by '.$step['acted_by']['display_name'] : ''), ($step['created_at'] ?? null) ? Format::reportTime($step['created_at'], $offset) : null])) }}</span>
                            @if (filled($step['note'] ?? null))
                                <span class="block whitespace-pre-line text-sm text-muted">{{ $step['note'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    @endif
</div>
