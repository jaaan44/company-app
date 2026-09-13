<?php

namespace App\Http\Controllers\Api\V1\ServiceReports;

use App\Enums\ServiceReportActionType;
use App\Enums\ServiceReportStatus;
use App\Enums\StaffStatus;
use App\Http\Controllers\Api\V1\ServiceReports\Concerns\AuthorizesServiceReportAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceReports\RejectServiceReportRequest;
use App\Http\Requests\ServiceReports\ReturnServiceReportToDraftRequest;
use App\Http\Requests\ServiceReports\ReviewServiceReportRequest;
use App\Http\Requests\ServiceReports\StoreServiceReportRequest;
use App\Http\Requests\ServiceReports\SubmitServiceReportRequest;
use App\Http\Requests\ServiceReports\UpdateServiceReportRequest;
use App\Http\Resources\ServiceReportResource;
use App\Models\Client;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ServiceReport;
use App\Models\Staff;
use App\Models\Task;
use App\Services\Attachments\AttachmentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;

/**
 * Service Reports (Phase 18), built on top of Clients (Phase 8), Projects
 * (Phase 10), Tasks (Phase 11), and Staff (Phase 7). A flat, top-level
 * resource (`/api/v1/service-reports`) — mirroring Tasks/Schedule
 * Entries: no `can:<permission>` route middleware anywhere. Visibility
 * and authority are resolved entirely in-controller
 * (AuthorizesServiceReportAccess) — no new permission was introduced, per
 * the governing Phase 18 instructions' explicit rejection of a broad
 * `service-reports.view` that would grant every Manager company-wide
 * visibility. See docs/phases/V1_PHASE_18_DEFINITION.md and DEC-041.
 */
class ServiceReportController extends Controller
{
    use AuthorizesServiceReportAccess;

    private const WITH_RELATIONS = ['client', 'project', 'task', 'creator', 'participants', 'actions.actor.staff', 'attachments', 'createdByUser.staff'];

    public function __construct(private readonly AttachmentStorage $attachmentStorage) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'client' => ['sometimes', 'string'],
            'project' => ['sometimes', 'string'],
            'task' => ['sometimes', 'string'],
            'staff' => ['sometimes', 'string'],
            'status' => ['sometimes', new Enum(ServiceReportStatus::class)],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $query = $this->scopeVisibleServiceReports($request, ServiceReport::query()->with(self::WITH_RELATIONS));

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
                $request->filled('staff'),
                fn ($query) => $query->where('creator_staff_id', $this->resolveId(Staff::class, $request->string('staff')->toString()) ?? -1)
            )
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('service_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('service_date', '<=', $request->date('to')))
            ->orderByDesc('service_date')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 50));

        return ServiceReportResource::collection($reports);
    }

    public function show(Request $request, ServiceReport $serviceReport): ServiceReportResource
    {
        $this->authorizeView($request, $serviceReport);

        return new ServiceReportResource($serviceReport->load(self::WITH_RELATIONS));
    }

    /**
     * Any active User with a linked, active Staff record may create their
     * own Service Report; Administrator may name another Staff member as
     * creator via `creator_staff_id`, mirroring Work Log's Administrator-
     * on-behalf precedent (DEC-035) — that path skips the active-status
     * and Project/Task eligibility checks entirely (Administrator is
     * trusted to backfill/correct a historical record). A non-
     * Administrator supplying `creator_staff_id` for anyone but
     * themselves is rejected (docs/phases/V1_PHASE_18_DEFINITION.md's
     * Creation Authority).
     */
    public function store(StoreServiceReportRequest $request): JsonResponse
    {
        $data = $request->validated();
        $isAdmin = $this->isAdministrator($request);

        $creatorStaff = $this->resolveCreatorStaff($request, $data, $isAdmin);

        $clientId = $this->resolveId(Client::class, $data['client_id']);
        $taskId = array_key_exists('task_id', $data) ? $this->resolveId(Task::class, $data['task_id']) : null;
        $projectId = array_key_exists('project_id', $data)
            ? $this->resolveId(Project::class, $data['project_id'])
            : ($taskId !== null ? Task::query()->whereKey($taskId)->value('project_id') : null);

        if (! $isAdmin) {
            $this->authorizeSelfServiceEligibility($creatorStaff, $projectId, $taskId);
        }

        $report = DB::transaction(function () use ($request, $data, $creatorStaff, $clientId, $projectId, $taskId) {
            $report = ServiceReport::create([
                'client_id' => $clientId,
                'project_id' => $projectId,
                'task_id' => $taskId,
                'creator_staff_id' => $creatorStaff->id,
                'created_by_user_id' => $request->user()->id,
                'service_date' => $data['service_date'],
                'work_performed' => $data['work_performed'],
                'findings' => $data['findings'] ?? null,
                'recommendations' => $data['recommendations'] ?? null,
                'follow_up_actions' => $data['follow_up_actions'] ?? null,
                'site_representative_name' => $data['site_representative_name'] ?? null,
            ]);

            if (array_key_exists('participant_staff_ids', $data)) {
                $report->participants()->sync($this->resolveParticipantIds($data['participant_staff_ids'], $creatorStaff->id));
            }

            return $report;
        });

        return (new ServiceReportResource($report->load(self::WITH_RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateServiceReportRequest $request, ServiceReport $serviceReport): ServiceReportResource
    {
        $this->authorizeManageDraft($request, $serviceReport);

        if (! $serviceReport->isEditable()) {
            abort(409, 'This service report is not a draft and cannot be edited; return it to draft first if it was rejected.');
        }

        $data = $request->validated();

        $participantIds = null;

        if (array_key_exists('participant_staff_ids', $data)) {
            $participantIds = $this->resolveParticipantIds($data['participant_staff_ids'], $serviceReport->creator_staff_id);
            unset($data['participant_staff_ids']);
        }

        $serviceReport->update($data);

        if ($participantIds !== null) {
            $serviceReport->participants()->sync($participantIds);
        }

        return new ServiceReportResource($serviceReport->load(self::WITH_RELATIONS));
    }

    /**
     * Only a draft may ever be hard-deleted (docs/phases/
     * V1_PHASE_18_DEFINITION.md's Deletion) — a submitted, reviewed, or
     * rejected report is permanent history. Physical attachment files are
     * removed BEFORE the database row so a failure never leaves an
     * orphaned file with no remaining reference — see
     * App\Services\Attachments\AttachmentStorage and the attachments
     * migration's Attachment Architecture note for the accepted
     * consistency boundary.
     */
    public function destroy(Request $request, ServiceReport $serviceReport): JsonResponse
    {
        $this->authorizeManageDraft($request, $serviceReport);

        if ($serviceReport->status !== ServiceReportStatus::Draft) {
            abort(409, 'Only a draft service report may be deleted.');
        }

        foreach ($serviceReport->attachments as $attachment) {
            $this->attachmentStorage->delete($attachment->storage_path);
        }

        $serviceReport->delete();

        return response()->json(status: 204);
    }

    /**
     * draft -> submitted. Recorded as `resubmitted` (rather than
     * `submitted`) if this report has ever been submitted before — i.e.
     * it was previously rejected and returned to draft (docs/phases/
     * V1_PHASE_18_DEFINITION.md's Workflow Action History).
     */
    public function submit(SubmitServiceReportRequest $request, ServiceReport $serviceReport): ServiceReportResource
    {
        $this->authorizeManageDraft($request, $serviceReport);

        DB::transaction(function () use ($request, $serviceReport) {
            $serviceReport->refresh();

            if ($serviceReport->status !== ServiceReportStatus::Draft) {
                abort(409, 'Only a draft service report may be submitted.');
            }

            $isResubmission = $serviceReport->actions()
                ->where('action', ServiceReportActionType::Submitted)
                ->exists();

            $serviceReport->update(['status' => ServiceReportStatus::Submitted]);

            $serviceReport->actions()->create([
                'action' => $isResubmission ? ServiceReportActionType::Resubmitted : ServiceReportActionType::Submitted,
                'acted_by_user_id' => $request->user()->id,
                'note' => $request->validated('note'),
            ]);
        });

        return new ServiceReportResource($serviceReport->load(self::WITH_RELATIONS));
    }

    /**
     * submitted -> reviewed — the final successful state for V1.
     */
    public function review(ReviewServiceReportRequest $request, ServiceReport $serviceReport): ServiceReportResource
    {
        $this->authorizeReview($request, $serviceReport);

        DB::transaction(function () use ($request, $serviceReport) {
            $serviceReport->refresh();

            if ($serviceReport->status !== ServiceReportStatus::Submitted) {
                abort(409, 'Only a submitted service report may be reviewed.');
            }

            $serviceReport->update(['status' => ServiceReportStatus::Reviewed]);

            $serviceReport->actions()->create([
                'action' => ServiceReportActionType::Reviewed,
                'acted_by_user_id' => $request->user()->id,
                'note' => $request->validated('note'),
            ]);
        });

        return new ServiceReportResource($serviceReport->load(self::WITH_RELATIONS));
    }

    /**
     * submitted -> rejected.
     */
    public function reject(RejectServiceReportRequest $request, ServiceReport $serviceReport): ServiceReportResource
    {
        $this->authorizeReview($request, $serviceReport);

        DB::transaction(function () use ($request, $serviceReport) {
            $serviceReport->refresh();

            if ($serviceReport->status !== ServiceReportStatus::Submitted) {
                abort(409, 'Only a submitted service report may be rejected.');
            }

            $serviceReport->update(['status' => ServiceReportStatus::Rejected]);

            $serviceReport->actions()->create([
                'action' => ServiceReportActionType::Rejected,
                'acted_by_user_id' => $request->user()->id,
                'note' => $request->validated('reason'),
            ]);
        });

        return new ServiceReportResource($serviceReport->load(self::WITH_RELATIONS));
    }

    /**
     * rejected -> draft, reopening it for correction. Only the creator or
     * Administrator — never a reviewer or participant.
     */
    public function returnToDraft(ReturnServiceReportToDraftRequest $request, ServiceReport $serviceReport): ServiceReportResource
    {
        $this->authorizeManageDraft($request, $serviceReport);

        DB::transaction(function () use ($request, $serviceReport) {
            $serviceReport->refresh();

            if ($serviceReport->status !== ServiceReportStatus::Rejected) {
                abort(409, 'Only a rejected service report may be returned to draft.');
            }

            $serviceReport->update(['status' => ServiceReportStatus::Draft]);

            $serviceReport->actions()->create([
                'action' => ServiceReportActionType::ReturnedToDraft,
                'acted_by_user_id' => $request->user()->id,
                'note' => $request->validated('note'),
            ]);
        });

        return new ServiceReportResource($serviceReport->load(self::WITH_RELATIONS));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveCreatorStaff(Request $request, array $data, bool $isAdmin): Staff
    {
        if ($isAdmin && array_key_exists('creator_staff_id', $data)) {
            return Staff::query()->where('public_id', $data['creator_staff_id'])->firstOrFail();
        }

        $selfStaff = $request->user()?->staff;

        if ($selfStaff === null) {
            abort(403, 'No staff record is linked to this account.');
        }

        if (array_key_exists('creator_staff_id', $data) && $data['creator_staff_id'] !== $selfStaff->public_id) {
            abort(403, 'You may only create a service report for yourself.');
        }

        if ($selfStaff->status !== StaffStatus::Active) {
            abort(403, 'Only active staff may create a service report.');
        }

        return $selfStaff;
    }

    /**
     * Self-service eligibility (docs/phases/V1_PHASE_18_DEFINITION.md's
     * Creation Authority): a Project-linked report requires current
     * Project membership (any role, mirroring Work Log's identical
     * precedent, DEC-035); an independent-Task-linked report requires
     * the performer to be that Task's current assignee. Never checked
     * for an Administrator-entered report.
     */
    private function authorizeSelfServiceEligibility(Staff $creatorStaff, ?int $projectId, ?int $taskId): void
    {
        if ($projectId !== null) {
            $isMember = ProjectMembership::query()
                ->where('project_id', $projectId)
                ->where('staff_id', $creatorStaff->id)
                ->exists();

            if (! $isMember) {
                abort(403, 'You are not a member of this project.');
            }

            return;
        }

        if ($taskId !== null) {
            $assigneeId = Task::query()->whereKey($taskId)->value('assignee_staff_id');

            if ($assigneeId !== $creatorStaff->id) {
                abort(403, 'You may only create a service report for a task assigned to you.');
            }
        }
    }

    /**
     * The creator/primary performer is never duplicated into the
     * participant pivot — mirrors ScheduleEntryController's identical
     * precedent.
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
