<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Api\V1\Projects\Concerns\AuthorizesProjectVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Client;
use App\Models\Project;
use App\Models\Staff;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;

/**
 * Projects (Phase 10 — Projects & Project Membership). Writes require
 * `projects.manage` (Administrator-only, route middleware). Reads are
 * *not* gated by a bare `can:projects.view` route middleware — visibility
 * is scoped in-controller (AuthorizesProjectVisibility): an Administrator/
 * Manager (`projects.view`) sees every Project, an ordinary Staff member
 * sees only Projects they are a member of. See docs/phases/
 * V1_PHASE_10_DEFINITION.md. Create/delete and significant updates
 * (status/client) are audited (Phase 21, DEC-044).
 */
class ProjectController extends Controller
{
    use AuthorizesProjectVisibility;

    /** @var array<int, string> */
    private const AUDITED_FIELDS = ['status', 'client_id'];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', new Enum(ProjectStatus::class)],
            'client' => ['sometimes', 'string'],
            'member' => ['sometimes', 'string'],
            'q' => ['sometimes', 'string', 'max:255'],
        ]);

        $canViewAll = $this->canViewAllProjects($request);
        $requesterStaffId = $request->user()?->staff?->id;

        if (! $canViewAll && $requesterStaffId === null) {
            abort(403, 'You do not have access to view any projects.');
        }

        $projects = Project::query()
            ->with('client')
            ->withCount('memberships')
            ->when(
                ! $canViewAll,
                fn ($query) => $query->whereHas('memberships', fn ($query) => $query->where('staff_id', $requesterStaffId))
            )
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when(
                $request->filled('client'),
                fn ($query) => $query->where('client_id', $this->resolveId(Client::class, $request->string('client')->toString()) ?? -1)
            )
            ->when(
                $request->filled('member'),
                fn ($query) => $query->whereHas(
                    'memberships',
                    fn ($query) => $query->where('staff_id', $this->resolveId(Staff::class, $request->string('member')->toString()) ?? -1)
                )
            )
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q')->toString().'%';

                $query->where(function ($query) use ($term) {
                    $query->where('name', 'like', $term)
                        ->orWhere('project_code', 'like', $term);
                });
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50));

        return ProjectResource::collection($projects);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('client_id', $data)) {
            $data['client_id'] = $this->resolveId(Client::class, $data['client_id']);
        }

        $project = DB::transaction(function () use ($request, $data) {
            $project = Project::create($data);
            $project->load('client');

            $this->auditLogger->recordForRequest(
                $request,
                AuditActions::PROJECT_CREATED,
                entityType: 'Project',
                entityPublicId: $project->public_id,
                after: $this->curatedSnapshot($project),
            );

            return $project;
        });

        return (new ProjectResource($project->loadCount('memberships')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Project $project): ProjectResource
    {
        $this->authorizeView($request, $project);

        return new ProjectResource($project->load('client')->loadCount('memberships'));
    }

    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        $project->load('client');
        $before = $this->curatedSnapshot($project);

        $data = $request->validated();

        if (array_key_exists('client_id', $data)) {
            $data['client_id'] = $this->resolveId(Client::class, $data['client_id']);
        }

        DB::transaction(function () use ($request, $project, $data, $before) {
            $project->update($data);
            $project->load('client');

            [$changedFields, $curatedBefore, $curatedAfter] = AuditLogger::diff(
                $before,
                $this->curatedSnapshot($project),
                self::AUDITED_FIELDS,
            );

            if ($changedFields !== []) {
                $this->auditLogger->recordForRequest(
                    $request,
                    AuditActions::PROJECT_UPDATED,
                    entityType: 'Project',
                    entityPublicId: $project->public_id,
                    changedFields: $changedFields,
                    before: $curatedBefore,
                    after: $curatedAfter,
                );
            }
        });

        return new ProjectResource($project->loadCount('memberships'));
    }

    /**
     * A project that still has any membership, task, or directly-linked
     * work log referencing it cannot be deleted — preserving business
     * history (see docs/phases/V1_PHASE_10_DEFINITION.md /
     * V1_PHASE_11_DEFINITION.md / V1_PHASE_12_DEFINITION.md). The
     * `restrictOnDelete()` foreign keys on `project_memberships.
     * project_id`, `tasks.project_id`, and `work_logs.project_id` back
     * this up at the database level; these checks exist to return a
     * clear 409 instead of a raw database constraint error. Work Logs
     * referencing one of this Project's Tasks are already protected
     * transitively (the Task itself can't be deleted while referenced —
     * see TaskController::destroy), so only directly-linked Work Logs
     * need checking here.
     */
    public function destroy(Request $request, Project $project): JsonResponse
    {
        if ($project->memberships()->exists()) {
            return response()->json([
                'message' => 'This project still has members assigned to it and cannot be deleted.',
            ], 409);
        }

        if ($project->tasks()->exists()) {
            return response()->json([
                'message' => 'This project still has tasks and cannot be deleted.',
            ], 409);
        }

        if ($project->workLogs()->exists()) {
            return response()->json([
                'message' => 'This project still has work logs and cannot be deleted.',
            ], 409);
        }

        if ($project->conversation()->exists()) {
            return response()->json([
                'message' => 'This project still has a conversation and cannot be deleted.',
            ], 409);
        }

        if ($project->milestones()->exists()) {
            return response()->json([
                'message' => 'This project still has milestones and cannot be deleted.',
            ], 409);
        }

        if ($project->scheduleEntries()->exists()) {
            return response()->json([
                'message' => 'This project still has schedule entries and cannot be deleted.',
            ], 409);
        }

        if ($project->serviceReports()->exists()) {
            return response()->json([
                'message' => 'This project still has service reports and cannot be deleted.',
            ], 409);
        }

        if ($project->incidentReports()->exists()) {
            return response()->json([
                'message' => 'This project still has incident reports and cannot be deleted.',
            ], 409);
        }

        $project->load('client');
        $publicId = $project->public_id;
        $before = $this->curatedSnapshot($project);

        DB::transaction(function () use ($request, $project, $publicId, $before) {
            $project->delete();

            $this->auditLogger->recordForRequest(
                $request,
                AuditActions::PROJECT_DELETED,
                entityType: 'Project',
                entityPublicId: $publicId,
                before: $before,
            );
        });

        return response()->json(status: 204);
    }

    /**
     * @param  class-string<Client|Staff>  $modelClass
     */
    private function resolveId(string $modelClass, ?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        return $modelClass::query()->where('public_id', $publicId)->value('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function curatedSnapshot(Project $project): array
    {
        return [
            'status' => $project->status->value,
            'client_id' => $project->client?->public_id,
        ];
    }
}
