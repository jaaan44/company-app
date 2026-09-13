<?php

namespace App\Http\Requests\ServiceReports\Concerns;

use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\Validation\Validator;

/**
 * Shared by Service Report Form Requests (Phase 18). Resolves client-
 * supplied public IDs to internal numeric IDs (never trusting/exposing
 * the numeric ID itself), and enforces the relational-coherence rule the
 * governing Phase 18 instructions require: a Project, if supplied, must
 * genuinely belong to the selected Client; a Task, if supplied, must not
 * contradict the selected Client/Project. Client remains the required
 * business anchor throughout — this never becomes a generic "attach any
 * three IDs" validator.
 *
 * Task has no direct client_id of its own (only via its own optional
 * Project) — this follows the actual existing schema (Client -> Project
 * -> Task) rather than assuming a relationship that isn't there.
 */
trait ResolvesServiceReportReferences
{
    private function resolveClientId(): ?int
    {
        return $this->resolvePublicId(Client::class, 'client_id');
    }

    private function resolveProjectId(): ?int
    {
        return $this->resolvePublicId(Project::class, 'project_id');
    }

    private function resolveTaskId(): ?int
    {
        return $this->resolvePublicId(Task::class, 'task_id');
    }

    /**
     * Validates that a supplied Project genuinely belongs to the
     * supplied Client, and that a supplied Task does not contradict
     * either — never permitting a Client/Project/Task combination that
     * is merely three independently-existing foreign keys with no real
     * relationship to one another (docs/phases/V1_PHASE_18_DEFINITION.md's
     * Relational Coherence). Adds field-level errors rather than
     * aborting, consistent with every other Form Request coherence check
     * in this codebase (e.g. Work Log's
     * ResolvesWorkLogReferences::validateAtLeastOneReference()).
     */
    private function validateClientProjectTaskCoherence(Validator $validator): void
    {
        $clientId = $this->resolveClientId();
        $explicitProjectId = $this->filled('project_id') ? $this->resolveProjectId() : null;
        $projectSupplied = $this->filled('project_id');
        $taskId = $this->filled('task_id') ? $this->resolveTaskId() : null;

        if ($clientId === null) {
            // The 'exists' rule on client_id already reports this — avoid
            // a confusing secondary error against an unresolvable client.
            return;
        }

        if ($projectSupplied && $explicitProjectId !== null) {
            $projectClientId = Project::query()->whereKey($explicitProjectId)->value('client_id');

            if ($projectClientId !== $clientId) {
                $validator->errors()->add('project_id', 'The selected project does not belong to the selected client.');

                return;
            }
        }

        if (! $this->filled('task_id') || $taskId === null) {
            return;
        }

        $taskProjectId = Task::query()->whereKey($taskId)->value('project_id');

        if ($taskProjectId === null) {
            // An independent (project-less) Task — it cannot contradict a
            // supplied project_id, because it has no project to compare
            // against; but supplying one anyway would misrepresent the
            // Task as Project-linked when it is not.
            if ($projectSupplied) {
                $validator->errors()->add('task_id', 'The selected task is not linked to any project and cannot be combined with a project_id.');
            }

            return;
        }

        if ($projectSupplied && $explicitProjectId !== $taskProjectId) {
            $validator->errors()->add('task_id', 'The selected task does not belong to the selected project.');

            return;
        }

        if (! $projectSupplied) {
            // project_id is server-derived from the task (the same
            // "single source of truth" rule Work Log applies, DEC-035) —
            // the derived project must still belong to the selected
            // client.
            $derivedProjectClientId = Project::query()->whereKey($taskProjectId)->value('client_id');

            if ($derivedProjectClientId !== $clientId) {
                $validator->errors()->add('task_id', 'The selected task\'s project does not belong to the selected client.');
            }
        }
    }

    /**
     * The project_id to actually persist — explicit when supplied,
     * otherwise server-derived from task_id (mirrors WorkLogController's
     * identical derivation), otherwise null.
     */
    private function resolvedProjectIdForPersistence(): ?int
    {
        if ($this->filled('project_id')) {
            return $this->resolveProjectId();
        }

        $taskId = $this->resolveTaskId();

        return $taskId !== null ? Task::query()->whereKey($taskId)->value('project_id') : null;
    }

    /**
     * @param  class-string<Client|Project|Task>  $modelClass
     */
    private function resolvePublicId(string $modelClass, string $field): ?int
    {
        $publicId = $this->input($field);

        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        return $modelClass::query()->where('public_id', $publicId)->value('id');
    }
}
