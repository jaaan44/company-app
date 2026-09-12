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
     * At least one of task_id/project_id is required — a Work Log never
     * references neither (it would be a generic, un-anchored activity
     * log, explicitly out of scope). A client-supplied project_id
     * alongside a task_id is already rejected declaratively
     * ('prohibited_with:task_id' in rules()); this only guards the "both
     * absent" case the declarative rules can't express.
     */
    private function validateAtLeastOneReference(Validator $validator): void
    {
        if (! $this->filled('task_id') && ! $this->filled('project_id')) {
            $validator->errors()->add('task_id', 'A work log must reference a task, a project, or both.');
        }
    }
}
