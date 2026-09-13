<?php

namespace App\Http\Controllers\Api\V1\IncidentReports;

use App\Enums\IncidentReportActionType;
use App\Enums\IncidentReportStatus;
use App\Enums\IncidentReportType;
use App\Enums\IncidentSeverity;
use App\Enums\StaffStatus;
use App\Http\Controllers\Api\V1\IncidentReports\Concerns\AuthorizesIncidentReportAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\IncidentReports\AssignIncidentReportRequest;
use App\Http\Requests\IncidentReports\CloseIncidentReportRequest;
use App\Http\Requests\IncidentReports\ReassignIncidentReportRequest;
use App\Http\Requests\IncidentReports\ReopenIncidentReportRequest;
use App\Http\Requests\IncidentReports\ResolveIncidentReportRequest;
use App\Http\Requests\IncidentReports\StartInvestigationIncidentReportRequest;
use App\Http\Requests\IncidentReports\StoreIncidentReportRequest;
use App\Http\Requests\IncidentReports\UpdateIncidentReportRequest;
use App\Http\Resources\IncidentReportResource;
use App\Models\Client;
use App\Models\IncidentReport;
use App\Models\Project;
use App\Models\Staff;
use App\Models\Task;
use App\Services\Attachments\AttachmentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;

/**
 * Incident Reports (Phase 19), built on top of Clients (Phase 8),
 * Projects (Phase 10), Tasks (Phase 11), and Staff (Phase 7) — and the
 * second authorized consumer of the shared attachment infrastructure
 * (Phase 18, DEC-041). A flat, top-level resource
 * (`/api/v1/incident-reports`) — mirroring Service Reports: no
 * `can:<permission>` route middleware anywhere. Visibility and authority
 * are resolved entirely in-controller (AuthorizesIncidentReportAccess),
 * deliberately narrower than Service Reports' model. See
 * docs/phases/V1_PHASE_19_DEFINITION.md and DEC-042.
 */
class IncidentReportController extends Controller
{
    use AuthorizesIncidentReportAccess;

    private const WITH_RELATIONS = [
        'client', 'project', 'task', 'reporter', 'assignedTo', 'participants',
        'actions.actor.staff', 'attachments', 'createdByUser.staff',
    ];

    public function __construct(private readonly AttachmentStorage $attachmentStorage) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'client' => ['sometimes', 'string'],
            'project' => ['sometimes', 'string'],
            'task' => ['sometimes', 'string'],
            'reporter' => ['sometimes', 'string'],
            'assigned' => ['sometimes', 'string'],
            'status' => ['sometimes', new Enum(IncidentReportStatus::class)],
            'severity' => ['sometimes', new Enum(IncidentSeverity::class)],
            'incident_type' => ['sometimes', new Enum(IncidentReportType::class)],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $query = $this->scopeVisibleIncidentReports($request, IncidentReport::query()->with(self::WITH_RELATIONS));

        $reports = $query
            ->when(
                $request->filled('client'),
                fn ($query) => $query->where('client_id', $this->resolveId(Client::class, $request->string('client')->toString()) ?? -1)
            )
            ->when(
                $request->filled('project'),
                fn ($query) => $query->where('project_id', $this->resolveId(Project::class, $request->string('project')->toString()) ?? -1)
            )
            ->when(
                $request->filled('task'),
                fn ($query) => $query->where('task_id', $this->resolveId(Task::class, $request->string('task')->toString()) ?? -1)
            )
            ->when(
                $request->filled('reporter'),
                fn ($query) => $query->where('reporter_staff_id', $this->resolveId(Staff::class, $request->string('reporter')->toString()) ?? -1)
            )
            ->when(
                $request->filled('assigned'),
                fn ($query) => $query->where('assigned_to_staff_id', $this->resolveId(Staff::class, $request->string('assigned')->toString()) ?? -1)
            )
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('severity'), fn ($query) => $query->where('severity', $request->string('severity')))
            ->when($request->filled('incident_type'), fn ($query) => $query->where('incident_type', $request->string('incident_type')))
            ->when($request->filled('from'), fn ($query) => $query->where('occurred_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->where('occurred_at', '<=', $request->date('to')))
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 50));

        return IncidentReportResource::collection($reports);
    }

    public function show(Request $request, IncidentReport $incidentReport): IncidentReportResource
    {
        $this->authorizeView($request, $incidentReport);

        return new IncidentReportResource($incidentReport->load(self::WITH_RELATIONS));
    }

    /**
     * Any active User with a linked, active Staff record may report their
     * own Incident Report; Administrator may name another Staff member as
     * reporter via `reporter_staff_id`, mirroring Service Reports'
     * Administrator-on-behalf precedent (DEC-041) — that path skips the
     * active-status check entirely. A non-Administrator supplying
     * `reporter_staff_id` for anyone but themselves is rejected.
     * `assigned_to_staff_id` is never accepted at creation — an incident
     * may be reported unassigned (docs/phases/V1_PHASE_19_DEFINITION.md's
     * Assignment); assignment always goes through the dedicated /assign
     * endpoint afterward, so it is captured in incident_report_actions.
     */
    public function store(StoreIncidentReportRequest $request): JsonResponse
    {
        $data = $request->validated();
        $isAdmin = $this->isAdministrator($request);

        $reporterStaff = $this->resolveReporterStaff($request, $data, $isAdmin);

        $clientId = array_key_exists('client_id', $data) ? $this->resolveId(Client::class, $data['client_id']) : null;
        $taskId = array_key_exists('task_id', $data) ? $this->resolveId(Task::class, $data['task_id']) : null;
        $projectId = array_key_exists('project_id', $data)
            ? $this->resolveId(Project::class, $data['project_id'])
            : ($taskId !== null ? Task::query()->whereKey($taskId)->value('project_id') : null);

        $report = DB::transaction(function () use ($request, $data, $reporterStaff, $clientId, $projectId, $taskId) {
            $report = IncidentReport::create([
                'client_id' => $clientId,
                'project_id' => $projectId,
                'task_id' => $taskId,
                'reporter_staff_id' => $reporterStaff->id,
                'created_by_user_id' => $request->user()->id,
                'occurred_at' => $data['occurred_at'],
                'location' => $data['location'] ?? null,
                'incident_type' => $data['incident_type'],
                'severity' => $data['severity'] ?? IncidentSeverity::Medium,
                'description' => $data['description'],
                'immediate_action_taken' => $data['immediate_action_taken'] ?? null,
                'root_cause' => $data['root_cause'] ?? null,
                'corrective_action' => $data['corrective_action'] ?? null,
                'preventive_action' => $data['preventive_action'] ?? null,
                'follow_up_actions' => $data['follow_up_actions'] ?? null,
                'resolution' => $data['resolution'] ?? null,
                'people_involved' => $data['people_involved'] ?? null,
                'witness_notes' => $data['witness_notes'] ?? null,
            ]);

            if (array_key_exists('participant_staff_ids', $data)) {
                $report->participants()->sync($this->resolveParticipantIds($data['participant_staff_ids'], $reporterStaff->id));
            }

            return $report;
        });

        return (new IncidentReportResource($report->load(self::WITH_RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * `reporter_staff_id`/`assigned_to_staff_id`/`status` are never
     * accepted — the reporter is immutable at every status, and
     * assignment/status changes always go through their own dedicated
     * action endpoints so every change is captured in
     * incident_report_actions (docs/phases/V1_PHASE_19_DEFINITION.md's
     * Editing Authority).
     */
    public function update(UpdateIncidentReportRequest $request, IncidentReport $incidentReport): IncidentReportResource
    {
        $this->authorizeManageContent($request, $incidentReport);

        if (! $incidentReport->status->isContentMutable()) {
            abort(409, 'This incident report is resolved or closed and cannot be edited; reopen it first.');
        }

        $data = $request->validated();

        if (array_key_exists('client_id', $data)) {
            $data['client_id'] = $this->resolveId(Client::class, $data['client_id']);
        }

        $taskSupplied = array_key_exists('task_id', $data);

        if ($taskSupplied) {
            $data['task_id'] = $this->resolveId(Task::class, $data['task_id']);
        }

        if (array_key_exists('project_id', $data)) {
            $data['project_id'] = $this->resolveId(Project::class, $data['project_id']);
        } elseif ($taskSupplied && $data['task_id'] !== null) {
            $data['project_id'] = Task::query()->whereKey($data['task_id'])->value('project_id');
        }

        $participantIds = null;

        if (array_key_exists('participant_staff_ids', $data)) {
            $participantIds = $this->resolveParticipantIds($data['participant_staff_ids'], $incidentReport->reporter_staff_id);
            unset($data['participant_staff_ids']);
        }

        $incidentReport->update($data);

        if ($participantIds !== null) {
            $incidentReport->participants()->sync($participantIds);
        }

        return new IncidentReportResource($incidentReport->load(self::WITH_RELATIONS));
    }

    /**
     * Only the reporter or Administrator may delete, and only while the
     * report is `reported` and still unassigned (docs/phases/
     * V1_PHASE_19_DEFINITION.md's Deletion) — once assigned or
     * investigated, it is permanent operational history; use resolution/
     * closure instead. Physical attachment files are removed BEFORE the
     * database row, mirroring ServiceReportController::destroy()'s
     * identical consistency-boundary discipline (Phase 18).
     */
    public function destroy(Request $request, IncidentReport $incidentReport): JsonResponse
    {
        $this->authorizeView($request, $incidentReport);

        if (! ($this->isAdministrator($request) || $this->isReporter($request, $incidentReport))) {
            abort(403, 'You do not have permission to delete this incident report.');
        }

        if ($incidentReport->status !== IncidentReportStatus::Reported || $incidentReport->assigned_to_staff_id !== null) {
            abort(409, 'Only an unassigned, reported incident report may be deleted.');
        }

        foreach ($incidentReport->attachments as $attachment) {
            $this->attachmentStorage->delete($attachment->storage_path);
        }

        $incidentReport->delete();

        return response()->json(status: 204);
    }

    /**
     * Assigns an investigator to a currently-unassigned Incident Report.
     * Authority: the reporter's current Manager, or Administrator —
     * never the reporter, never an arbitrary Staff member (docs/phases/
     * V1_PHASE_19_DEFINITION.md's Assignment Authority).
     */
    public function assign(AssignIncidentReportRequest $request, IncidentReport $incidentReport): IncidentReportResource
    {
        $this->authorizeView($request, $incidentReport);

        if (! $this->canManageAssignment($request, $incidentReport)) {
            abort(403, 'You do not have authority to assign this incident report.');
        }

        if (! $incidentReport->status->isContentMutable()) {
            abort(409, 'This incident report is resolved or closed; reopen it first.');
        }

        if ($incidentReport->assigned_to_staff_id !== null) {
            abort(409, 'This incident report is already assigned; use reassign instead.');
        }

        $data = $request->validated();
        $assigneeId = $this->resolveId(Staff::class, $data['assigned_to_staff_id']);

        DB::transaction(function () use ($request, $incidentReport, $assigneeId, $data) {
            $incidentReport->update(['assigned_to_staff_id' => $assigneeId]);

            $incidentReport->actions()->create([
                'action' => IncidentReportActionType::Assigned,
                'acted_by_user_id' => $request->user()->id,
                'note' => $data['note'] ?? null,
            ]);
        });

        return new IncidentReportResource($incidentReport->load(self::WITH_RELATIONS));
    }

    /**
     * Reassigns an already-assigned Incident Report to a different
     * investigator. Same authority as assign().
     */
    public function reassign(ReassignIncidentReportRequest $request, IncidentReport $incidentReport): IncidentReportResource
    {
        $this->authorizeView($request, $incidentReport);

        if (! $this->canManageAssignment($request, $incidentReport)) {
            abort(403, 'You do not have authority to reassign this incident report.');
        }

        if (! $incidentReport->status->isContentMutable()) {
            abort(409, 'This incident report is resolved or closed; reopen it first.');
        }

        if ($incidentReport->assigned_to_staff_id === null) {
            abort(409, 'This incident report is not yet assigned; use assign instead.');
        }

        $data = $request->validated();
        $assigneeId = $this->resolveId(Staff::class, $data['assigned_to_staff_id']);

        DB::transaction(function () use ($request, $incidentReport, $assigneeId, $data) {
            $incidentReport->update(['assigned_to_staff_id' => $assigneeId]);

            $incidentReport->actions()->create([
                'action' => IncidentReportActionType::Reassigned,
                'acted_by_user_id' => $request->user()->id,
                'note' => $data['note'] ?? null,
            ]);
        });

        return new IncidentReportResource($incidentReport->load(self::WITH_RELATIONS));
    }

    /**
     * reported -> under_investigation. An assignee must already exist or
     * be supplied in this same request (docs/phases/
     * V1_PHASE_19_DEFINITION.md's Workflow Meaning) —
     * StartInvestigationIncidentReportRequest already validated that. If
     * this request supplies (or changes) the assignee, that requires
     * assignment authority specifically (reporter's current Manager or
     * Administrator); if it merely starts investigation on an
     * already-assigned report, investigation-management authority
     * (assigned investigator included) suffices.
     */
    public function startInvestigation(StartInvestigationIncidentReportRequest $request, IncidentReport $incidentReport): IncidentReportResource
    {
        $this->authorizeView($request, $incidentReport);

        $data = $request->validated();
        $suppliesAssignee = array_key_exists('assigned_to_staff_id', $data) && $data['assigned_to_staff_id'] !== null;

        if ($suppliesAssignee) {
            if (! $this->canManageAssignment($request, $incidentReport)) {
                abort(403, 'You do not have authority to assign this incident report.');
            }
        } elseif (! $this->canManageInvestigation($request, $incidentReport)) {
            abort(403, 'You do not have authority to start an investigation on this incident report.');
        }

        DB::transaction(function () use ($request, $incidentReport, $data, $suppliesAssignee) {
            $incidentReport->refresh();

            if ($incidentReport->status !== IncidentReportStatus::Reported) {
                abort(409, 'Only a reported incident report may enter investigation.');
            }

            if ($suppliesAssignee) {
                $newAssigneeId = $this->resolveId(Staff::class, $data['assigned_to_staff_id']);
                $wasUnassigned = $incidentReport->assigned_to_staff_id === null;

                $incidentReport->update(['assigned_to_staff_id' => $newAssigneeId]);

                $incidentReport->actions()->create([
                    'action' => $wasUnassigned ? IncidentReportActionType::Assigned : IncidentReportActionType::Reassigned,
                    'acted_by_user_id' => $request->user()->id,
                ]);
            }

            $incidentReport->update(['status' => IncidentReportStatus::UnderInvestigation]);

            $incidentReport->actions()->create([
                'action' => IncidentReportActionType::InvestigationStarted,
                'acted_by_user_id' => $request->user()->id,
                'note' => $data['note'] ?? null,
            ]);
        });

        return new IncidentReportResource($incidentReport->load(self::WITH_RELATIONS));
    }

    /**
     * under_investigation -> resolved. Requires a non-empty `resolution`
     * and at least one of `corrective_action`/`immediate_action_taken` —
     * enforced by ResolveIncidentReportRequest against the *effective*
     * value. Content and attachments become immutable once resolved.
     */
    public function resolve(ResolveIncidentReportRequest $request, IncidentReport $incidentReport): IncidentReportResource
    {
        $this->authorizeView($request, $incidentReport);

        if (! $this->canManageInvestigation($request, $incidentReport)) {
            abort(403, 'You do not have authority to resolve this incident report.');
        }

        $data = $request->validated();

        DB::transaction(function () use ($request, $incidentReport, $data) {
            $incidentReport->refresh();

            if ($incidentReport->status !== IncidentReportStatus::UnderInvestigation) {
                abort(409, 'Only an incident report under investigation may be resolved.');
            }

            $updates = ['status' => IncidentReportStatus::Resolved];

            foreach (['resolution', 'corrective_action', 'immediate_action_taken'] as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[$field] = $data[$field];
                }
            }

            $incidentReport->update($updates);

            $incidentReport->actions()->create([
                'action' => IncidentReportActionType::Resolved,
                'acted_by_user_id' => $request->user()->id,
                'note' => $data['note'] ?? null,
            ]);
        });

        return new IncidentReportResource($incidentReport->load(self::WITH_RELATIONS));
    }

    /**
     * resolved -> closed — the final state. Project Lead gains no
     * automatic close authority merely because a Project is linked
     * (docs/phases/V1_PHASE_19_DEFINITION.md's Closing Authority).
     */
    public function close(CloseIncidentReportRequest $request, IncidentReport $incidentReport): IncidentReportResource
    {
        $this->authorizeView($request, $incidentReport);

        if (! $this->canManageInvestigation($request, $incidentReport)) {
            abort(403, 'You do not have authority to close this incident report.');
        }

        DB::transaction(function () use ($request, $incidentReport) {
            $incidentReport->refresh();

            if ($incidentReport->status !== IncidentReportStatus::Resolved) {
                abort(409, 'Only a resolved incident report may be closed.');
            }

            $incidentReport->update(['status' => IncidentReportStatus::Closed]);

            $incidentReport->actions()->create([
                'action' => IncidentReportActionType::Closed,
                'acted_by_user_id' => $request->user()->id,
                'note' => $request->validated('note'),
            ]);
        });

        return new IncidentReportResource($incidentReport->load(self::WITH_RELATIONS));
    }

    /**
     * resolved|closed -> under_investigation. `reopened` is recorded only
     * as an action-history event — there is no persisted "reopened"
     * status. Restores content/attachment mutability to authorized
     * investigation actors.
     */
    public function reopen(ReopenIncidentReportRequest $request, IncidentReport $incidentReport): IncidentReportResource
    {
        $this->authorizeView($request, $incidentReport);

        if (! $this->canManageInvestigation($request, $incidentReport)) {
            abort(403, 'You do not have authority to reopen this incident report.');
        }

        DB::transaction(function () use ($request, $incidentReport) {
            $incidentReport->refresh();

            if (! in_array($incidentReport->status, [IncidentReportStatus::Resolved, IncidentReportStatus::Closed], true)) {
                abort(409, 'Only a resolved or closed incident report may be reopened.');
            }

            $incidentReport->update(['status' => IncidentReportStatus::UnderInvestigation]);

            $incidentReport->actions()->create([
                'action' => IncidentReportActionType::Reopened,
                'acted_by_user_id' => $request->user()->id,
                'note' => $request->validated('note'),
            ]);
        });

        return new IncidentReportResource($incidentReport->load(self::WITH_RELATIONS));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveReporterStaff(Request $request, array $data, bool $isAdmin): Staff
    {
        if ($isAdmin && array_key_exists('reporter_staff_id', $data)) {
            return Staff::query()->where('public_id', $data['reporter_staff_id'])->firstOrFail();
        }

        $selfStaff = $request->user()?->staff;

        if ($selfStaff === null) {
            abort(403, 'No staff record is linked to this account.');
        }

        if (array_key_exists('reporter_staff_id', $data) && $data['reporter_staff_id'] !== $selfStaff->public_id) {
            abort(403, 'You may only report an incident for yourself.');
        }

        if ($selfStaff->status !== StaffStatus::Active) {
            abort(403, 'Only active staff may report an incident.');
        }

        return $selfStaff;
    }

    /**
     * The reporter is deliberately never duplicated into the participant
     * pivot — mirrors ServiceReportController's identical precedent.
     *
     * @param  array<int, string>  $publicIds
     * @return array<int, int>
     */
    private function resolveParticipantIds(array $publicIds, ?int $excludeStaffId): array
    {
        return Staff::query()
            ->whereIn('public_id', $publicIds)
            ->when($excludeStaffId !== null, fn ($query) => $query->whereKeyNot($excludeStaffId))
            ->pluck('id')
            ->all();
    }

    /**
     * @param  class-string<Client|Project|Task|Staff>  $modelClass
     */
    private function resolveId(string $modelClass, ?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        return $modelClass::query()->where('public_id', $publicId)->value('id');
    }
}
