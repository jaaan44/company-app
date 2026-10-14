<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * New project / Edit project (Phase 35D): every field of POST and PATCH
 * /api/v1/projects, for holders of `projects.create` / `projects.update`.
 * Members and milestones are managed on the project's page.
 */
class ProjectForm extends Component
{
    use CallsInternalApi;
    use WritesThroughApi;

    public const STATUSES = ['planned', 'active', 'on_hold', 'completed', 'cancelled'];

    private const CLIENT_PAGES = 10;

    #[Locked]
    public ?string $publicId = null;

    #[Locked]
    public bool $notFound = false;

    #[Locked]
    public ?string $currentClientName = null;

    public string $name = '';

    public string $code = '';

    public string $client = '';

    public string $status = 'planned';

    public string $startDate = '';

    public string $targetEndDate = '';

    public string $completedDate = '';

    public string $description = '';

    public string $notes = '';

    public function mount(?string $publicId = null): void
    {
        if ($publicId === null) {
            return;
        }

        $this->publicId = $publicId;
        try {
            $p = $this->api()->get('/projects/'.rawurlencode($publicId))['data'];
            $this->name = (string) $p['name'];
            $this->code = (string) ($p['project_code'] ?? '');
            $this->client = (string) ($p['client']['public_id'] ?? '');
            $this->currentClientName = $p['client']['name'] ?? null;
            $this->status = (string) $p['status'];
            $this->startDate = (string) ($p['start_date'] ?? '');
            $this->targetEndDate = (string) ($p['target_end_date'] ?? '');
            $this->completedDate = (string) ($p['completed_date'] ?? '');
            $this->description = (string) ($p['description'] ?? '');
            $this->notes = (string) ($p['notes'] ?? '');
        } catch (PortalApiException $e) {
            if ($e->isNotFound()) {
                $this->notFound = true;
            } else {
                $this->failed($e, 'Something went wrong loading the project.', "You don't have access to this project.");
            }
        }
    }

    public function save(): void
    {
        $this->generalError = null;
        $this->resetErrorBag();

        $name = trim($this->name);
        if ($name === '') {
            $this->addError('name', 'Give the project a name.');
        } elseif (mb_strlen($name) > 255) {
            $this->addError('name', 'Use at most 255 characters.');
        }
        if (mb_strlen(trim($this->code)) > 50) {
            $this->addError('code', 'Use at most 50 characters.');
        }
        if (! in_array($this->status, self::STATUSES, true)) {
            $this->addError('status', 'Choose a status.');
        }
        foreach (['startDate', 'targetEndDate', 'completedDate'] as $field) {
            if ($this->{$field} !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->{$field})) {
                $this->addError($field, 'Choose a date.');
            }
        }
        if ($this->status === 'completed' && $this->completedDate === '') {
            $this->addError('completedDate', 'Choose when the project was completed.');
        }
        if (mb_strlen(trim($this->description)) > 5000) {
            $this->addError('description', 'Use at most 5000 characters.');
        }
        if (mb_strlen(trim($this->notes)) > 2000) {
            $this->addError('notes', 'Use at most 2000 characters.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $blank = fn (string $v) => trim($v) === '' ? null : trim($v);
        $body = [
            'name' => $name,
            'project_code' => $blank($this->code),
            'client_id' => $blank($this->client),
            'status' => $this->status,
            'start_date' => $blank($this->startDate),
            'target_end_date' => $blank($this->targetEndDate),
            'completed_date' => $blank($this->completedDate),
            'description' => $blank($this->description),
            'notes' => $blank($this->notes),
        ];

        try {
            if ($this->publicId === null) {
                $p = $this->api()->send('POST', '/projects', json: $body)['data'];
                session()->flash('status', 'Project added.');
            } else {
                $p = $this->api()->send('PATCH', '/projects/'.rawurlencode($this->publicId), json: $body)['data'];
                session()->flash('status', 'Changes saved.');
            }
            $this->redirect(route('portal.project', $p['public_id']), navigate: true);
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [
                'name' => 'name', 'project_code' => 'code', 'client_id' => 'client', 'status' => 'status',
                'start_date' => 'startDate', 'target_end_date' => 'targetEndDate', 'completed_date' => 'completedDate',
                'description' => 'description', 'notes' => 'notes',
            ], "Couldn't save the project. Please try again.");
        }
    }

    public function render(): View
    {
        $clients = [];
        if (! $this->notFound && $this->errorMessage === null) {
            try {
                for ($page = 1; $page <= self::CLIENT_PAGES; $page++) {
                    $result = $this->api()->get('/clients', ['status' => 'active', 'per_page' => 50, 'page' => $page]);
                    array_push($clients, ...($result['data'] ?? []));
                    if ($page >= ($result['meta']['last_page'] ?? 1)) {
                        break;
                    }
                }
            } catch (PortalApiException $e) {
                if ($e->isSessionExpired()) {
                    $this->failed($e, '');
                }
                // Without client access the project is saved without a client.
            }
            if ($this->client !== '' && ! collect($clients)->contains('public_id', $this->client)) {
                $clients[] = ['public_id' => $this->client, 'name' => ($this->currentClientName ?? 'Current client').' (inactive)'];
            }
        }

        return view('livewire.portal.project-form', ['clients' => $clients]);
    }
}
