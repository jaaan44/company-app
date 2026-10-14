<?php

namespace App\Livewire\Portal;

use App\Livewire\Portal\Concerns\CallsInternalApi;
use App\Livewire\Portal\Concerns\WritesThroughApi;
use App\Services\Portal\PortalApiException;
use App\Support\Portal\Format;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Portal task detail (Phase 34B): the mobile task screen (Phase 29A) —
 * `GET /tasks/{id}` and the assignee's status change, `PATCH` with
 * `{"status": …}`. Saving is confirmed, not optimistic: the shown status
 * changes only when the API accepts it. Cancelled is never offered and a
 * cancelled task is read-only; after a `403` the control stays locked.
 * The due state is judged against the company day from `/me/tasks`.
 */
class TaskShow extends Component
{
    use CallsInternalApi;
    use WritesThroughApi;

    /** The statuses the portal offers (mobile R-4): never Cancelled. */
    public const SELECTABLE = ['todo', 'in_progress', 'blocked', 'completed'];

    #[Locked]
    public string $publicId = '';

    #[Locked]
    public bool $locked = false;

    public ?string $saveMessage = null;

    public ?string $savedMessage = null;

    public function mount(string $publicId): void
    {
        $this->publicId = $publicId;
    }

    public function setStatus(string $status): void
    {
        $this->saveMessage = null;
        $this->savedMessage = null;
        if ($this->locked || ! in_array($status, self::SELECTABLE, true)) {
            return;
        }

        try {
            $task = $this->api()->send('PATCH', $this->path(), json: ['status' => $status])['data'] ?? [];
            $this->savedMessage = 'Status changed to '.Format::taskStatus($task['status'] ?? $status).'.';
        } catch (PortalApiException $e) {
            if ($e->isSessionExpired()) {
                $this->failed($e, '');

                return;
            }
            $this->saveMessage = match (true) {
                $e->isForbidden() => $e->getMessage() !== '' ? $e->getMessage() : "You don't have access to this task.",
                $e->status === 422 => $e->firstErrorFor('status') ?? $e->getMessage(),
                $e->isNotFound() => 'This task is no longer available.',
                default => "Couldn't save the change. Please try again.",
            };
            if ($e->isForbidden()) {
                $this->locked = true;
            }
        }
    }

    /** Phase 35D (D-5): Delete, after the in-app confirmation; the API's own rules decide. */
    public function delete(): void
    {
        $this->generalError = null;
        try {
            $this->api()->send('DELETE', $this->path());
            session()->flash('status', 'Task deleted.');
            $this->redirect(route('portal.tasks'), navigate: true);
        } catch (PortalApiException $e) {
            $this->applyWriteFailure($e, [], "Couldn't delete the task. Please try again.");
        }
    }

    public function render(): View
    {
        $this->errorMessage = null;
        $task = null;
        $companyDay = null;

        try {
            $task = $this->api()->get($this->path())['data'] ?? null;
        } catch (PortalApiException $e) {
            if ($e->isNotFound()) {
                $this->errorMessage = 'This task is no longer available.';
            } else {
                $this->failed($e, 'Something went wrong loading this task.', forbidden: "You don't have access to this task.");
            }
        }

        if ($task !== null) {
            try {
                $companyDay = $this->api()->get('/me/tasks', ['state' => 'open', 'per_page' => 1])['data']['company_day'] ?? null;
            } catch (PortalApiException) {
                // Without the company day the due date shows without "Overdue" / "Due today".
            }
        }

        // D-3: Edit for holders of tasks.update and the task's project lead; Delete for tasks.delete.
        $mayEdit = false;
        if ($task !== null) {
            $mayEdit = $this->may('tasks.update');
            if (! $mayEdit && ($task['project']['public_id'] ?? null) !== null) {
                try {
                    $mayEdit = ($this->api()->get('/projects/'.rawurlencode($task['project']['public_id']))['data']['my_role'] ?? null) === 'project_lead';
                } catch (PortalApiException) {
                    $mayEdit = false;
                }
            }
        }

        return view('livewire.portal.task-show', [
            'task' => $task,
            'companyDay' => $companyDay,
            'mayEdit' => $mayEdit,
            'mayDelete' => $task !== null && $this->may('tasks.delete'),
        ]);
    }

    private function path(): string
    {
        return '/tasks/'.rawurlencode($this->publicId);
    }
}
