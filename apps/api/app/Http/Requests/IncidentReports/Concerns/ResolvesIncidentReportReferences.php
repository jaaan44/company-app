<?php

namespace App\Http\Requests\IncidentReports\Concerns;

use App\Models\Client;
use App\Models\IncidentReport;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\Validation\Validator;

/**
 * Shared by Incident Report Form Requests (Phase 19). Resolves client-
 * supplied public IDs to internal numeric IDs, and enforces the same
 * relational-coherence principle Service Reports established (Phase 18,
 * `ResolvesServiceReportReferences`): a supplied Project must belong to a
 * supplied Client; a supplied Task must not contradict the supplied
 * Client/Project.
 *
 * A fresh trait, not a literal reuse of ResolvesServiceReportReferences,
 * because Client is genuinely optional here (DEC-042) — every check
 * below is conditioned on whether a Client was actually supplied at all,
 * which would have forced ResolvesServiceReportReferences' own "Client is
 * always present" logic into an awkward, harder-to-follow set of
 * branches rather than a clean shared abstraction (docs/phases/
 * V1_PHASE_19_DEFINITION.md's Reference Resolution).
 */
trait ResolvesIncidentReportReferences
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
     * Creation-time coherence check. Unlike Service Reports, a missing
     * Client is not itself an error — the checks below simply have
     * nothing to compare against when Client wasn't supplied at all.
     */
    private function validateClientProjectTaskCoherence(Validator $validator): void
    {
        $clientSupplied = $this->filled('client_id');
        $clientId = $clientSupplied ? $this->resolveClientId() : null;

        $projectSupplied = $this->filled('project_id');
        $projectId = $projectSupplied ? $this->resolveProjectId() : null;

        $taskSupplied = $this->filled('task_id');
        $taskId = $taskSupplied ? $this->resolveTaskId() : null;

        if ($projectSupplied && $projectId !== null && $clientSupplied && $clientId !== null) {
            $projectClientId = Project::query()->whereKey($projectId)->value('client_id');

            if ($projectClientId !== $clientId) {
                $validator->errors()->add('project_id', 'The selected project does not belong to the selected client.');

                return;
            }
        }

        if (! $taskSupplied || $taskId === null) {
            return;
        }

        $taskProjectId = Task::query()->whereKey($taskId)->value('project_id');

        if ($taskProjectId === null) {
            // An independent (project-less) Task cannot contradict a
            // supplied project_id, but supplying one anyway would
            // misrepresent the Task as Project-linked when it is not.
            if ($projectSupplied && $projectId !== null) {
                $validator->errors()->add('task_id', 'The selected task is not linked to any project and cannot be combined with a project_id.');
            }

            return;
        }

        if ($projectSupplied && $projectId !== null && $projectId !== $taskProjectId) {
            $validator->errors()->add('task_id', 'The selected task does not belong to the selected project.');

            return;
        }

        if (! $projectSupplied && $clientSupplied && $clientId !== null) {
            // project_id will be server-derived from the task (mirrors
            // Service Reports/Work Log's single-source-of-truth rule) —
            // the derived project must still belong to the selected
            // client.
            $derivedProjectClientId = Project::query()->whereKey($taskProjectId)->value('client_id');

            if ($derivedProjectClientId !== $clientId) {
                $validator->errors()->add('task_id', 'The selected task\'s project does not belong to the selected client.');
            }
        }
    }

    /**
     * The update-time counterpart — validates the *effective* combination
     * (a changed field's new value, or the existing report's current
     * value for any field left untouched), mirroring
     * ResolvesServiceReportReferences::validateClientProjectTaskCoherenceForUpdate()
     * exactly, but with every Client-related check conditioned on a
     * Client actually being present (explicitly or via the existing
     * record) since Client is optional for Incident Reports.
     */
    private function validateClientProjectTaskCoherenceForUpdate(Validator $validator, IncidentReport $report): void
    {
        $clientId = $this->has('client_id') ? $this->resolveClientId() : $report->client_id;

        $projectSupplied = $this->has('project_id');
        $explicitProjectId = $projectSupplied ? $this->resolveProjectId() : null;

        $taskSupplied = $this->has('task_id');
        $taskId = $taskSupplied ? $this->resolveTaskId() : $report->task_id;

        if ($projectSupplied) {
            $projectId = $explicitProjectId;
        } elseif ($taskId !== null) {
            $projectId = Task::query()->whereKey($taskId)->value('project_id');
        } else {
            $projectId = $report->project_id;
        }

        if ($projectId !== null && $clientId !== null) {
            $projectClientId = Project::query()->whereKey($projectId)->value('client_id');

            if ($projectClientId !== $clientId) {
                $validator->errors()->add('project_id', 'The selected project does not belong to the selected client.');

                return;
            }
        }

        if ($taskId === null) {
            return;
        }

        $taskProjectId = Task::query()->whereKey($taskId)->value('project_id');

        if ($taskProjectId === null) {
            if ($projectId !== null) {
                $validator->errors()->add('task_id', 'The selected task is not linked to any project and cannot be combined with a project_id.');
            }

            return;
        }

        if ($projectSupplied && $explicitProjectId !== $taskProjectId) {
            $validator->errors()->add('task_id', 'The selected task does not belong to the selected project.');
        }
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
