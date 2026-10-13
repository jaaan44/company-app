<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Services\Portal\PortalApiException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Portal "Log work" / "Edit work log" (Phase 34B): the mobile form
 * (Phase 29B). Create sends `POST /me/work-logs` with a task or one of my
 * projects; edit sends `PATCH /me/work-logs/{id}` with only the date,
 * time and description (the API forbids changing the rest); delete sends
 * `DELETE`. The form checks what the mobile form checks before sending;
 * the API stays authoritative and its 422 errors land on the same fields.
 * Dates are judged against the API's company day, never the server clock.
 */
class WorkLogForm extends Component
{
    use CallsInternalApi;

    /** The API's limits (Phase 12) and the picker's reach (R-17). */
    public const MAX_MINUTES = 1440;

    public const MAX_DESCRIPTION = 2000;

    public const DAYS_BACK = 365;

    /** Edit: the log's own page size when looking it up. */
    private const LOOKUP_PAGE_SIZE = 100;

    private const LOOKUP_MAX_PAGES = 20;

    /** The picker's project pages (mobile: 50 per page, at most 10 pages). */
    private const PROJECT_PAGE_SIZE = 50;

    private const PROJECT_MAX_PAGES = 10;

    #[Locked]
    public ?string $publicId = null;

    #[Locked]
    public ?string $fixedTarget = null;

    #[Locked]
    public ?string $fixedLabel = null;

    #[Locked]
    public ?string $fixedProject = null;

    #[Locked]
    public ?string $companyDate = null;

    #[Locked]
    public bool $notFound = false;

    public string $target = '';

    public string $workDate = '';

    public string $hours = '';

    public string $minutes = '';

    public string $description = '';

    public ?string $generalError = null;

    public function mount(?string $publicId = null, ?string $task = null): void
    {
        try {
            $this->companyDate = $this->api()->get('/me/work-logs', ['per_page' => 1])['meta']['company_day']['date'] ?? null;
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading the form.');

            return;
        }

        if ($publicId !== null) {
            $this->publicId = $publicId;
            $this->loadExisting($publicId);

            return;
        }

        $this->workDate = (string) $this->companyDate;
        if ($task !== null && $task !== '') {
            try {
                $found = $this->api()->get('/tasks/'.rawurlencode($task))['data'] ?? null;
                if ($found !== null) {
                    $this->fixedTarget = 'task:'.$found['public_id'];
                    $this->fixedLabel = $found['title'];
                    $this->fixedProject = $found['project']['name'] ?? null;
                    $this->target = $this->fixedTarget;
                }
            } catch (PortalApiException $e) {
                if ($e->isSessionExpired()) {
                    $this->failed($e, '');
                }
                // Otherwise the picker is shown instead.
            }
        }
    }

    public function save(): void
    {
        $this->generalError = null;
        if (! $this->validateForm()) {
            return;
        }

        $body = [
            'work_date' => $this->workDate,
            'duration_minutes' => (int) $this->hours * 60 + (int) $this->minutes,
            'description' => trim($this->description),
        ];

        try {
            if ($this->publicId === null) {
                [$kind, $id] = explode(':', $this->target, 2);
                $body[$kind === 'task' ? 'task_id' : 'project_id'] = $id;
                $this->api()->send('POST', '/me/work-logs', json: $body);
                session()->flash('status', 'Work logged.');
            } else {
                $this->api()->send('PATCH', '/me/work-logs/'.rawurlencode($this->publicId), json: $body);
                session()->flash('status', 'Changes saved.');
            }
            $this->redirect(route('portal.work-logs'), navigate: true);
        } catch (PortalApiException $e) {
            $this->applyFailure($e, 'save');
        }
    }

    public function delete(): void
    {
        $this->generalError = null;
        if ($this->publicId === null) {
            return;
        }

        try {
            $this->api()->send('DELETE', '/me/work-logs/'.rawurlencode($this->publicId));
            session()->flash('status', 'Work log deleted.');
            $this->redirect(route('portal.work-logs'), navigate: true);
        } catch (PortalApiException $e) {
            $this->applyFailure($e, 'delete');
        }
    }

    public function render(): View
    {
        $tasks = [];
        $projects = [];
        if ($this->publicId === null && $this->fixedTarget === null && ! $this->noProfile && $this->errorMessage === null) {
            [$tasks, $projects] = $this->pickerOptions();
        }

        return view('livewire.portal.work-log-form', [
            'tasks' => $tasks,
            'projects' => $projects,
            'minDate' => $this->companyDate === null ? null : CarbonImmutable::parse($this->companyDate)->subDays(self::DAYS_BACK)->toDateString(),
        ]);
    }

    private function loadExisting(string $publicId): void
    {
        try {
            for ($page = 1; $page <= self::LOOKUP_MAX_PAGES; $page++) {
                $result = $this->api()->get('/me/work-logs', ['per_page' => self::LOOKUP_PAGE_SIZE, 'page' => $page]);
                foreach ($result['data'] ?? [] as $log) {
                    if ($log['public_id'] === $publicId) {
                        $this->fixedLabel = $log['task']['title'] ?? $log['project']['name'] ?? '';
                        $this->fixedProject = isset($log['task']) ? ($log['project']['name'] ?? null) : null;
                        $this->workDate = $log['work_date'];
                        $this->hours = (string) intdiv((int) $log['duration_minutes'], 60);
                        $this->minutes = (string) ((int) $log['duration_minutes'] % 60);
                        $this->description = (string) $log['description'];

                        return;
                    }
                }
                if ($page >= ($result['meta']['last_page'] ?? 1)) {
                    break;
                }
            }
            $this->notFound = true;
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading the form.');
        }
    }

    /**
     * My open tasks and the projects I'm a member of that aren't closed
     * (mobile R-14).
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function pickerOptions(): array
    {
        try {
            $tasks = $this->api()->get('/me/tasks', ['state' => 'open', 'per_page' => 50])['data']['tasks'] ?? [];
            $staffId = $this->api()->get('/me/profile')['data']['staff']['public_id'] ?? null;
            $projects = [];
            for ($page = 1; $staffId !== null && $page <= self::PROJECT_MAX_PAGES; $page++) {
                $result = $this->api()->get('/projects', ['member' => $staffId, 'per_page' => self::PROJECT_PAGE_SIZE, 'page' => $page]);
                foreach ($result['data'] ?? [] as $project) {
                    if (! in_array($project['status'], ['completed', 'cancelled'], true)) {
                        $projects[] = $project;
                    }
                }
                if ($page >= ($result['meta']['last_page'] ?? 1)) {
                    break;
                }
            }

            return [$tasks, $projects];
        } catch (PortalApiException $e) {
            $this->failed($e, 'Something went wrong loading the form.');

            return [[], []];
        }
    }

    private function validateForm(): bool
    {
        $this->resetErrorBag();

        if ($this->publicId === null && ! preg_match('/^(task|project):[0-9A-Za-z]+$/', $this->target)) {
            $this->addError('target', 'Choose what this work was for.');
        }

        $today = $this->companyDate;
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->workDate)) {
            $this->addError('workDate', 'Choose a date.');
        } elseif ($today !== null && $this->workDate > $today) {
            $this->addError('workDate', 'The work date cannot be later than today.');
        } elseif ($today !== null && $this->workDate < CarbonImmutable::parse($today)->subDays(self::DAYS_BACK)->toDateString()) {
            $this->addError('workDate', 'Choose a date within the last year.');
        }

        $hours = trim($this->hours) === '' ? '0' : trim($this->hours);
        $minutes = trim($this->minutes) === '' ? '0' : trim($this->minutes);
        if (! ctype_digit($hours) || ! ctype_digit($minutes) || (int) $minutes > 59) {
            $this->addError('duration', 'Enter hours and minutes.');
        } else {
            $total = (int) $hours * 60 + (int) $minutes;
            if ($total < 1) {
                $this->addError('duration', 'Enter how long you worked.');
            } elseif ($total > self::MAX_MINUTES) {
                $this->addError('duration', 'A work log can be at most 24 hours.');
            }
            $this->hours = $hours;
            $this->minutes = $minutes;
        }

        $description = trim($this->description);
        if ($description === '') {
            $this->addError('description', 'Describe the work.');
        } elseif (mb_strlen($description) > self::MAX_DESCRIPTION) {
            $this->addError('description', 'Use at most '.self::MAX_DESCRIPTION.' characters.');
        }

        return $this->getErrorBag()->isEmpty();
    }

    private function applyFailure(PortalApiException $e, string $action): void
    {
        if ($e->isSessionExpired()) {
            $this->failed($e, '');

            return;
        }

        if ($e->status === 422) {
            $map = ['task_id' => 'target', 'project_id' => 'target', 'work_date' => 'workDate', 'duration_minutes' => 'duration', 'description' => 'description'];
            foreach ($e->errors as $field => $messages) {
                if (isset($map[$field]) && $messages !== []) {
                    $this->addError($map[$field], $messages[0]);
                } elseif ($messages !== []) {
                    $this->generalError ??= $messages[0];
                }
            }
            if ($e->errors === []) {
                $this->generalError = $e->getMessage();
            }

            return;
        }

        $this->generalError = match (true) {
            $e->isNotFound() => 'This work log is no longer available.',
            $e->isForbidden(), $e->status === 409 => $e->getMessage() !== '' ? $e->getMessage() : "Couldn't $action. Please try again.",
            default => "Couldn't $action. Please try again.",
        };
    }
}
