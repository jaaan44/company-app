<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\PicksStaff;
use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use App\Support\CompanyTimezone;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * An incident in the Backoffice (Phase 36C): the report, its files and
 * history, and the workflow the API allows by status — Assign or
 * Reassign (a person), Start investigation, Resolve (resolution and
 * corrective action), Close and Reopen, each with an optional note.
 */
class IncidentRecord extends Component
{
    use CallsInternalApi;
    use PicksStaff;
    use WritesThroughApi;

    public const ACTIONS = [
        'assign' => 'Assign', 'reassign' => 'Reassign', 'start-investigation' => 'Start investigation',
        'resolve' => 'Resolve', 'close' => 'Close', 'reopen' => 'Reopen',
    ];

    #[Locked]
    public string $publicId;

    #[Locked]
    public bool $notFound = false;

    #[Locked]
    public ?string $confirming = null;

    public string $note = '';

    public string $resolution = '';

    public string $correctiveAction = '';

    public ?string $savedMessage = null;

    public int $toastNonce = 0;

    public function mount(string $publicId): void
    {
        $this->publicId = $publicId;
    }

    public function ask(string $action): void
    {
        $this->confirming = array_key_exists($action, self::ACTIONS) ? $action : null;
        $this->reset('note', 'resolution', 'correctiveAction', 'staffId', 'staffName', 'staffSearch');
        $this->generalError = null;
        $this->resetErrorBag();
    }

    public function dismiss(): void
    {
        $this->confirming = null;
    }

    public function confirm(): void
    {
        $action = $this->confirming;
        if ($action === null) {
            return;
        }
        $this->resetErrorBag();
        $needsPerson = in_array($action, ['assign', 'reassign'], true);
        if ($needsPerson && $this->staffId === '') {
            $this->addError('staffSearch', 'Choose who handles it.');
        }
        if (mb_strlen($this->note) > 1000) {
            $this->addError('note', 'Use at most 1,000 characters.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        $optional = fn (string $value) => trim($value) === '' ? null : trim($value);
        $body = ['note' => $optional($this->note)];
        if ($needsPerson || ($action === 'start-investigation' && $this->staffId !== '')) {
            $body['assigned_to_staff_id'] = $this->staffId;
        }
        if ($action === 'resolve') {
            $body += ['resolution' => $optional($this->resolution), 'corrective_action' => $optional($this->correctiveAction)];
        }
        try {
            $this->api()->send('POST', '/incident-reports/'.rawurlencode($this->publicId).'/'.$action, json: $body);
            $this->savedMessage = match ($action) {
                'assign', 'reassign' => 'Assigned to '.$this->staffName.'.',
                'start-investigation' => 'Investigation started.',
                'resolve' => 'Incident resolved.',
                'close' => 'Incident closed.',
                default => 'Incident reopened.',
            };
            $this->toastNonce++;
            $this->confirming = null;
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [
                'assigned_to_staff_id' => 'staffSearch', 'note' => 'note', 'resolution' => 'resolution', 'corrective_action' => 'correctiveAction',
            ], "Couldn't send that. Please try again.");
            if ($e->status !== 422) {
                $this->confirming = null;
            }
        }
    }

    /**
     * @return list<string>
     */
    public static function actionsFor(?string $status, bool $assigned): array
    {
        return match ($status) {
            'reported' => [$assigned ? 'reassign' : 'assign', 'start-investigation'],
            'under_investigation' => [$assigned ? 'reassign' : 'assign', 'resolve'],
            'resolved' => ['close', 'reopen'],
            'closed' => ['reopen'],
            default => [],
        };
    }

    public function render(): View
    {
        $report = null;
        try {
            $report = $this->api()->get('/incident-reports/'.rawurlencode($this->publicId))['data'];
        } catch (PortalApiException $e) {
            $e->isNotFound() ? $this->notFound = true : $this->failed($e, 'Something went wrong loading the incident.', "You don't have access to this incident.");
        }

        return view('livewire.admin.incident-record', [
            'report' => $report,
            'actions' => self::actionsFor($report['status'] ?? null, ($report['assigned_to'] ?? null) !== null),
            'people' => $this->confirming !== null ? $this->staffMatches() : [],
            'timezone' => CompanyTimezone::value(),
        ]);
    }
}
