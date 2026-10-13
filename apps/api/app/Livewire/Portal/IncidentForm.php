<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\ReportForm;
use App\Services\Portal\PortalApiException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Portal report / edit an incident (Phase 34E): the mobile form (Phase
 * 33, R-5) — the type, the severity (Medium by default), when it
 * happened (company date and time; by default now, never in the future),
 * the location, what happened (required), immediate action, people
 * involved and witness notes. Created as reported (`POST
 * /incident-reports`) or changed while mine and still reported (`PATCH`).
 */
class IncidentForm extends Component
{
    use CallsInternalApi;
    use ReportForm;

    public const TYPES = ['workplace_safety', 'client_site', 'operational', 'property_equipment_damage', 'it_security', 'other'];

    public const SEVERITIES = ['low', 'medium', 'high', 'critical'];

    #[Locked]
    public ?string $publicId = null;

    #[Locked]
    public string $utcOffset = '+00:00';

    #[Locked]
    public bool $notFound = false;

    public string $type = '';

    public string $severity = 'medium';

    public string $date = '';

    public string $time = '';

    public string $location = '';

    public string $description = '';

    public string $immediateAction = '';

    public string $peopleInvolved = '';

    public string $witnessNotes = '';

    public function mount(?string $publicId = null): void
    {
        $this->publicId = $publicId;
        try {
            $myStaffId = $this->api()->get('/me/profile')['data']['staff']['public_id'] ?? null;
            if ($myStaffId === null) {
                $this->noProfile = true;

                return;
            }
            $this->utcOffset = $this->api()->get('/incident-reports', ['per_page' => 1])['meta']['company_day']['utc_offset'] ?? '+00:00';
            if ($publicId === null) {
                $now = CarbonImmutable::now()->setTimezone($this->utcOffset);
                $this->date = $now->toDateString();
                $this->time = $now->format('H:i');

                return;
            }
            $report = $this->api()->get('/incident-reports/'.rawurlencode($publicId))['data'] ?? null;
            if ($report === null || $report['status'] !== 'reported' || ($report['reporter']['public_id'] ?? null) !== $myStaffId) {
                $this->notFound = true;

                return;
            }
            $at = CarbonImmutable::parse($report['occurred_at'])->setTimezone($this->utcOffset);
            $this->type = $report['incident_type'];
            $this->severity = $report['severity'];
            $this->date = $at->toDateString();
            $this->time = $at->format('H:i');
            $this->location = (string) ($report['location'] ?? '');
            $this->description = (string) $report['description'];
            $this->immediateAction = (string) ($report['immediate_action_taken'] ?? '');
            $this->peopleInvolved = (string) ($report['people_involved'] ?? '');
            $this->witnessNotes = (string) ($report['witness_notes'] ?? '');
        } catch (PortalApiException $e) {
            if ($e->isNotFound()) {
                $this->notFound = true;
            } else {
                $this->failed($e, 'Something went wrong loading the form.');
            }
        }
    }

    public function save(): void
    {
        $this->generalError = null;
        $this->resetErrorBag();
        if (! in_array($this->type, self::TYPES, true)) {
            $this->addError('type', 'Choose the type of incident.');
        }
        if (! in_array($this->severity, self::SEVERITIES, true)) {
            $this->severity = 'medium';
        }
        $occurredAt = null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->date) !== 1 || preg_match('/^\d{2}:\d{2}$/', $this->time) !== 1) {
            $this->addError('occurredAt', 'Choose the date and time.');
        } else {
            $occurredAt = CarbonImmutable::parse($this->date.' '.$this->time, $this->utcOffset)->utc();
            if ($occurredAt->isAfter(CarbonImmutable::now()->addMinute())) {
                $this->addError('occurredAt', 'The time cannot be in the future.');
            }
        }
        $this->checkText('location', $this->location, self::MAX_SHORT, false, '');
        $this->checkText('description', $this->description, self::MAX_LONG, true, 'Describe what happened.');
        $this->checkText('immediateAction', $this->immediateAction, self::MAX_TEXT, false, '');
        $this->checkText('peopleInvolved', $this->peopleInvolved, self::MAX_TEXT, false, '');
        $this->checkText('witnessNotes', $this->witnessNotes, self::MAX_TEXT, false, '');
        if (! $this->getErrorBag()->isEmpty() || $occurredAt === null) {
            return;
        }
        // Never later than the server's now: a few seconds' skew would refuse "now".
        $now = CarbonImmutable::now()->utc();

        $body = [
            'incident_type' => $this->type,
            'severity' => $this->severity,
            'occurred_at' => ($occurredAt->isAfter($now) ? $now : $occurredAt)->toIso8601String(),
            'location' => $this->nullable($this->location),
            'description' => trim($this->description),
            'immediate_action_taken' => $this->nullable($this->immediateAction),
            'people_involved' => $this->nullable($this->peopleInvolved),
            'witness_notes' => $this->nullable($this->witnessNotes),
        ];

        try {
            $report = $this->publicId === null
                ? $this->api()->send('POST', '/incident-reports', json: $body)['data']
                : $this->api()->send('PATCH', '/incident-reports/'.rawurlencode($this->publicId), json: $body)['data'];
            session()->flash('status', $this->publicId === null ? 'Incident reported.' : 'Changes saved.');
            $this->redirect(route('portal.incident', $report['public_id']));
        } catch (PortalApiException $e) {
            $this->refused($e, [
                'incident_type' => 'type', 'severity' => 'severity', 'occurred_at' => 'occurredAt', 'location' => 'location',
                'description' => 'description', 'immediate_action_taken' => 'immediateAction',
                'people_involved' => 'peopleInvolved', 'witness_notes' => 'witnessNotes',
            ]);
        }
    }

    public function render(): View
    {
        return view('livewire.portal.incident-form', [
            'maxDate' => CarbonImmutable::now()->setTimezone($this->utcOffset)->toDateString(),
        ]);
    }
}
