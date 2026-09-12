<?php

namespace App\Http\Requests\WorkLogs\Concerns;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\Validation\Validator;

/**
 * Shared by every Work Log Form Request (Phase 12). Resolves client-
 * supplied public IDs to internal numeric IDs (never trusting/exposing
 * the numeric ID itself), and enforces the "single source of truth"
 * Task/Project consistency rule: a Work Log references a Task, a
 * Project, or both consistently, but never neither — see
 * docs/phases/V1_PHASE_12_DEFINITION.md.
 */
trait ResolvesWorkLogReferences
{
    private function resolveTaskId(): ?int
    {
        $publicId = $this->input('task_id');

        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        return Task::query()->where('public_id', $publicId)->value('id');
    }

    private function resolveProjectId(): ?int
    {
        $publicId = $this->input('project_id');

        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        return Project::query()->where('public_id', $publicId)->value('id');
    }

    /**
     * A Work Log references a task, a project, or both consistently, but
     * never neither (a generic, un-anchored activity log is out of
     * scope) and never a client-supplied project_id alongside a task_id
     * (the single source of truth — project_id is always server-derived
     * from the task instead, see the controllers). This Laravel version
     * has no declarative "prohibited if another field is present" rule
     * ('prohibited_with' does not exist here — only prohibited_if/
     * prohibited_unless, which compare against a specific value), so both
     * checks are enforced here instead.
     */
    private function validateAtLeastOneReference(Validator $validator): void
    {
        if ($this->filled('task_id') && $this->filled('project_id')) {
            $validator->errors()->add('project_id', 'A work log may not specify both a task and a project — the project is derived from the task automatically.');

            return;
        }

        if (! $this->filled('task_id') && ! $this->filled('project_id')) {
            $validator->errors()->add('task_id', 'A work log must reference a task, a project, or both.');
        }
    }
}
