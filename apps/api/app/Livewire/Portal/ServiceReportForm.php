<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\ReportForm;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Portal new / edit service report (Phase 34E): the mobile form (Phase
 * 33, R-3) — a client (searching the active clients), an optional
 * project (one of my open projects for that client), a service date (by
 * default, and at most, the company today), work performed (required)
 * and the optional texts. Saved as a draft (`POST /service-reports`) or
 * changed (`PATCH …/{id}`, my draft only), then its page opens.
 */
class ServiceReportForm extends Component
{
    use CallsInternalApi;
    use ReportForm;

    #[Locked]
    public ?string $publicId = null;

    #[Locked]
    public ?string $companyDate = null;

    #[Locked]
    public ?string $myStaffId = null;

    #[Locked]
    public bool $notFound = false;

    public string $client = '';

    #[Locked]
    public string $clientName = '';

    public string $clientSearch = '';

    public string $project = '';

    public string $serviceDate = '';

    public string $workPerformed = '';

    public string $findings = '';

    public string $recommendations = '';

    public string $followUpActions = '';

    public string $siteRepresentative = '';

    public function mount(?string $publicId = null): void
    {
        $this->publicId = $publicId;
        try {
            $this->myStaffId = $this->api()->get('/me/profile')['data']['staff']['public_id'] ?? null;
            if ($this->myStaffId === null) {
                $this->noProfile = true;

                return;
            }
            $this->companyDate = $this->api()->get('/service-reports', ['per_page' => 1])['meta']['company_day']['date'] ?? null;
            if ($publicId === null) {
                $this->serviceDate = (string) $this->companyDate;

                return;
            }
            $report = $this->api()->get('/service-reports/'.rawurlencode($publicId))['data'] ?? null;
            if ($report === null || $report['status'] !== 'draft' || ($report['creator']['public_id'] ?? null) !== $this->myStaffId) {
                $this->notFound = true;

                return;
            }
            $this->client = $report['client']['public_id'];
            $this->clientName = $report['client']['name'];
            $this->project = $report['project']['public_id'] ?? '';
            $this->serviceDate = $report['service_date'];
            $this->workPerformed = (string) $report['work_performed'];
            $this->findings = (string) ($report['findings'] ?? '');
            $this->recommendations = (string) ($report['recommendations'] ?? '');
            $this->followUpActions = (string) ($report['follow_up_actions'] ?? '');
            $this->siteRepresentative = (string) ($report['site_representative_name'] ?? '');
        } catch (PortalApiException $e) {
            if ($e->isNotFound()) {
                $this->notFound = true;
            } else {
                $this->failed($e, 'Something went wrong loading the form.');
            }
        }
    }

    public function chooseClient(string $publicId): void
    {
        if (preg_match('/^[0-9A-Za-z]{1,64}$/', $publicId) !== 1) {
            return;
        }
        try {
            $client = $this->api()->get('/clients/'.rawurlencode($publicId))['data'] ?? null;
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading clients.');

            return;
        }
        if ($client === null || ($client['status'] ?? null) !== 'active') {
            return;
        }
        if ($client['public_id'] !== $this->client) {
            $this->project = '';
        }
        $this->client = $client['public_id'];
        $this->clientName = $client['name'];
        $this->clientSearch = '';
        $this->resetErrorBag('client');
    }

    public function changeClient(): void
    {
        $this->client = '';
        $this->clientName = '';
        $this->project = '';
    }

    public function save(): void
    {
        $this->generalError = null;
        $this->resetErrorBag();
        if ($this->client === '') {
            $this->addError('client', 'Choose a client.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->serviceDate) !== 1) {
            $this->addError('serviceDate', 'Choose the service date.');
        } elseif ($this->companyDate !== null && $this->serviceDate > $this->companyDate) {
            $this->addError('serviceDate', 'The service date cannot be later than today.');
        }
        $this->checkText('workPerformed', $this->workPerformed, self::MAX_LONG, true, 'Describe the work performed.');
        $this->checkText('findings', $this->findings, self::MAX_TEXT, false, '');
        $this->checkText('recommendations', $this->recommendations, self::MAX_TEXT, false, '');
        $this->checkText('followUpActions', $this->followUpActions, self::MAX_TEXT, false, '');
        $this->checkText('siteRepresentative', $this->siteRepresentative, self::MAX_SHORT, false, '');
        if (! $this->getErrorBag()->isEmpty()) {
            return;
        }

        $body = [
            'client_id' => $this->client,
            'project_id' => $this->project === '' ? null : $this->project,
            'service_date' => $this->serviceDate,
            'work_performed' => trim($this->workPerformed),
            'findings' => $this->nullable($this->findings),
            'recommendations' => $this->nullable($this->recommendations),
            'follow_up_actions' => $this->nullable($this->followUpActions),
            'site_representative_name' => $this->nullable($this->siteRepresentative),
        ];

        try {
            $report = $this->publicId === null
                ? $this->api()->send('POST', '/service-reports', json: $body)['data']
                : $this->api()->send('PATCH', '/service-reports/'.rawurlencode($this->publicId), json: $body)['data'];
            session()->flash('status', $this->publicId === null ? 'Draft saved.' : 'Changes saved.');
            $this->redirect(route('portal.service-report', $report['public_id']));
        } catch (PortalApiException $e) {
            $this->refused($e, [
                'client_id' => 'client', 'project_id' => 'project', 'service_date' => 'serviceDate',
                'work_performed' => 'workPerformed', 'findings' => 'findings', 'recommendations' => 'recommendations',
                'follow_up_actions' => 'followUpActions', 'site_representative_name' => 'siteRepresentative',
            ]);
        }
    }

    public function render(): View
    {
        $clients = [];
        $projects = [];
        if (! $this->noProfile && ! $this->notFound && $this->errorMessage === null) {
            try {
                $search = trim($this->clientSearch);
                if ($this->client === '' && $search !== '') {
                    $clients = $this->api()->get('/clients', ['status' => 'active', 'per_page' => 10, 'q' => $search])['data'] ?? [];
                }
                if ($this->client !== '') {
                    for ($page = 1; $page <= 5; $page++) {
                        $result = $this->api()->get('/projects', ['member' => $this->myStaffId, 'per_page' => 100, 'page' => $page]);
                        foreach ($result['data'] ?? [] as $p) {
                            if (($p['client']['public_id'] ?? null) === $this->client && ! in_array($p['status'], ['completed', 'cancelled'], true)) {
                                $projects[] = $p;
                            }
                        }
                        if (($result['meta']['last_page'] ?? 1) <= $page) {
                            break;
                        }
                    }
                }
            } catch (PortalApiException $e) {
                $this->failed($e, 'Something went wrong loading the form.');
            }
        }

        return view('livewire.portal.service-report-form', ['clients' => $clients, 'projects' => $projects]);
    }
}
