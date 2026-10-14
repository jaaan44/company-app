<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * New task / Edit task (Phase 35D, D-2/D-3): every field of
 * POST and PATCH /api/v1/tasks. Holders of `tasks.create` / `tasks.update`
 * may choose any project (or none); a project lead without them may choose
 * only projects they lead, as the API allows. The assignee is found by
 * searching the staff directory.
 */
class TaskForm extends Component
{
    use CallsInternalApi;
    use WritesThroughApi;

    public const STATUSES = ['todo', 'in_progress', 'blocked', 'completed', 'cancelled'];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    private const PROJECT_PAGES = 10;

    #[Locked]
    public ?string $publicId = null;

    #[Locked]
    public bool $notFound = false;

    /** On edit the project is fixed: the API doesn't move a task between projects. */
    #[Locked]
    public ?string $fixedProjectName = null;

    public string $title = '';

    public string $description = '';

    public string $project = '';

    public string $priority = 'normal';

    public string $status = 'todo';

    public string $dueDate = '';

    public string $assigneeId = '';

    public string $assigneeName = '';

    public string $assigneeSearch = '';

    /** `forProject` (not `project`): Livewire would otherwise copy the route value straight into the `project` property. */
    public function mount(?string $publicId = null, ?string $forProject = null): void
    {
        if ($publicId === null) {
            $this->project = preg_match('/^[0-9A-Za-z]{1,64}$/', (string) $forProject) ? (string) $forProject : '';

            return;
        }

        $this->publicId = $publicId;
        try {
            $task = $this->api()->get('/tasks/'.rawurlencode($publicId))['data'];
            $this->title = (string) $task['title'];
            $this->description = (string) ($task['description'] ?? '');
            $this->project = (string) ($task['project']['public_id'] ?? '');
            $this->fixedProjectName = $task['project']['name'] ?? 'No project (independent task)';
            $this->priority = (string) $task['priority'];
            $this->status = (string) $task['status'];
            $this->dueDate = (string) ($task['due_date'] ?? '');
            $this->assigneeId = (string) ($task['assignee']['public_id'] ?? '');
            $this->assigneeName = (string) ($task['assignee']['display_name'] ?? '');
        } catch (PortalApiException $e) {
            if ($e->isNotFound()) {
                $this->notFound = true;
            } else {
                $this->failed($e, 'Something went wrong loading the task.', "You don't have access to this task.");
            }
        }
    }

    public function chooseAssignee(string $publicId, string $name): void
    {
        $this->assigneeId = $publicId;
        $this->assigneeName = $name;
        $this->assigneeSearch = '';
    }

    public function clearAssignee(): void
    {
        $this->assigneeId = '';
        $this->assigneeName = '';
    }

    public function save(): void
    {
        $this->generalError = null;
        $this->resetErrorBag();

        $title = trim($this->title);
        if ($title === '') {
            $this->addError('title', 'Give the task a title.');
        } elseif (mb_strlen($title) > 255) {
            $this->addError('title', 'Use at most 255 characters.');
        }
        if (mb_strlen(trim($this->description)) > 5000) {
            $this->addError('description', 'Use at most 5000 characters.');
        }
        if ($this->dueDate !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->dueDate)) {
            $this->addError('dueDate', 'Choose a date.');
        }
        if (! in_array($this->status, self::STATUSES, true)) {
            $this->addError('status', 'Choose a status.');
        }
        if (! in_array($this->priority, self::PRIORITIES, true)) {
            $this->addError('priority', 'Choose a priority.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $body = [
            'title' => $title,
            'description' => trim($this->description) === '' ? null : trim($this->description),
            'project_id' => $this->project === '' ? null : $this->project,
            'assignee_staff_id' => $this->assigneeId === '' ? null : $this->assigneeId,
            'priority' => $this->priority,
            'status' => $this->status,
            'due_date' => $this->dueDate === '' ? null : $this->dueDate,
        ];

        if ($this->publicId !== null) {
            unset($body['project_id']);
        }

        try {
            if ($this->publicId === null) {
                $task = $this->api()->send('POST', '/tasks', json: $body)['data'];
                session()->flash('status', 'Task added.');
            } else {
                $task = $this->api()->send('PATCH', '/tasks/'.rawurlencode($this->publicId), json: $body)['data'];
                session()->flash('status', 'Changes saved.');
            }
            $this->redirect(route('portal.task', $task['public_id']), navigate: true);
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [
                'title' => 'title', 'description' => 'description', 'project_id' => 'project',
                'assignee_staff_id' => 'assigneeSearch', 'priority' => 'priority', 'status' => 'status', 'due_date' => 'dueDate',
            ], "Couldn't save the task. Please try again.");
        }
    }

    public function render(): View
    {
        $mayAny = $this->may($this->publicId === null ? 'tasks.create' : 'tasks.update');
        $projects = [];
        $people = [];
        $staffSearchFailed = false;
        $ready = ! $this->notFound && $this->errorMessage === null;

        // Projects are chosen only for a new task (the API doesn't move a task between projects).
        if ($ready && $this->publicId === null) {
            try {
                $query = $mayAny ? [] : ['member' => $this->api()->get('/me/profile')['data']['staff']['public_id'] ?? 'none'];
                for ($page = 1; $page <= self::PROJECT_PAGES; $page++) {
                    $result = $this->api()->get('/projects', [...$query, 'per_page' => 50, 'page' => $page]);
                    foreach ($result['data'] ?? [] as $p) {
                        $open = ! in_array($p['status'], ['completed', 'cancelled'], true) || $p['public_id'] === $this->project;
                        if ($open && ($mayAny || ($p['my_role'] ?? null) === 'project_lead')) {
                            $projects[] = $p;
                        }
                    }
                    if ($page >= ($result['meta']['last_page'] ?? 1)) {
                        break;
                    }
                }
            } catch (PortalApiException $e) {
                $this->failed($e, 'Something went wrong loading your projects.');
            }
        }

        if ($ready && mb_strlen(trim($this->assigneeSearch)) >= 2) {
            try {
                $people = $this->api()->get('/staff', ['q' => trim($this->assigneeSearch), 'status' => 'active', 'per_page' => 8])['data'] ?? [];
            } catch (PortalApiException) {
                $staffSearchFailed = true;
            }
        }

        return view('livewire.portal.task-form', [
            'projects' => $projects,
            'allowNoProject' => $mayAny,
            'people' => $people,
            'staffSearchFailed' => $staffSearchFailed,
        ]);
    }
}
