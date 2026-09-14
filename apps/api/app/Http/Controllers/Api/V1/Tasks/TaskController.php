<?php

namespace App\Http\Controllers\Api\V1\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Http\Controllers\Api\V1\Tasks\Concerns\AuthorizesTaskAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\StoreTaskRequest;
use App\Http\Requests\Tasks\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Staff;
use App\Models\Task;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;

/**
 * Tasks (Phase 11), built on top of Projects & Project Membership
 * (Phase 10). A flat, top-level resource (`/api/v1/tasks`, filterable by
 * `?project=`) rather than nested under `/projects/{project}/tasks` —
 * unlike Project Membership (Phase 10), a Task is not inherently
 * contextual to a Project (DEC-006), so the established "prefer a
 * top-level filterable resource" convention (Contact→Client, Phase 8)
 * applies here too, and avoids reproducing Phase 10's two-Eloquent-
 * parameter nested-binding issue entirely. Neither reads nor writes are
 * gated by a single `can:<permission>` route middleware (beyond
 * `DELETE`) — see AuthorizesTaskAccess and docs/phases/
 * V1_PHASE_11_DEFINITION.md.
 */
class TaskController extends Controller
{
    use AuthorizesTaskAccess;

    private const WITH_RELATIONS = ['project', 'assignee', 'creator.staff'];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'project' => ['sometimes', 'string'],
            'status' => ['sometimes', new Enum(TaskStatus::class)],
            'assignee' => ['sometimes', 'string'],
            'priority' => ['sometimes', new Enum(TaskPriority::class)],
            'q' => ['sometimes', 'string', 'max:255'],
        ]);

        $canViewAll = $this->canViewAllTasks($request);
        $staff = $request->user()?->staff;

        if (! $canViewAll && $staff === null) {
            abort(403, 'You do not have access to view any tasks.');
        }

        $tasks = Task::query()
            ->with(self::WITH_RELATIONS)
            ->when(! $canViewAll, function ($query) use ($staff) {
                $query->where(function ($query) use ($staff) {
                    $query->whereHas('project.memberships', fn ($query) => $query->where('staff_id', $staff->id))
                        ->orWhere('assignee_staff_id', $staff->id);
                });
            })
            ->when(
                $request->filled('project'),
                fn ($query) => $query->where('project_id', $this->resolveId(Project::class, $request->string('project')->toString()) ?? -1)
            )
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when(
                $request->filled('assignee'),
                fn ($query) => $query->where('assignee_staff_id', $this->resolveId(Staff::class, $request->string('assignee')->toString()) ?? -1)
            )
            ->when($request->filled('priority'), fn ($query) => $query->where('priority', $request->string('priority')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $query->where('title', 'like', '%'.$request->string('q').'%');
            })
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 50));

        return TaskResource::collection($tasks);
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        $data = $request->validated();

        $projectId = array_key_exists('project_id', $data)
            ? $this->resolveId(Project::class, $data['project_id'])
            : null;

        $this->authorizeCreate($request, $projectId);

        $data['project_id'] = $projectId;

        if (array_key_exists('assignee_staff_id', $data)) {
            $data['assignee_staff_id'] = $this->resolveId(Staff::class, $data['assignee_staff_id']);
        }

        $data['created_by_user_id'] = $request->user()->id;

        if (($data['status'] ?? TaskStatus::Todo->value) === TaskStatus::Completed->value) {
            $data['completed_at'] = now();
        }

        $task = Task::create($data);

        return (new TaskResource($task->load(self::WITH_RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Task $task): TaskResource
    {
        $this->authorizeView($request, $task);

        return new TaskResource($task->load(self::WITH_RELATIONS));
    }

    public function update(UpdateTaskRequest $request, Task $task): TaskResource
    {
        $fullAccess = $this->authorizeUpdate($request, $task);
        $data = $request->validated();

        if (! $fullAccess) {
            $disallowedFields = array_diff(array_keys($data), ['status']);

            if ($disallowedFields !== []) {
                abort(403, 'You may only update the status of a task assigned to you.');
            }
        }

        if (array_key_exists('assignee_staff_id', $data)) {
            $data['assignee_staff_id'] = $this->resolveId(Staff::class, $data['assignee_staff_id']);
        }

        if (array_key_exists('status', $data)) {
            if ($data['status'] === TaskStatus::Completed->value) {
                $data['completed_at'] = now();
            } elseif ($task->status === TaskStatus::Completed) {
                $data['completed_at'] = null;
            }
        }

        $task->update($data);

        return new TaskResource($task->load(self::WITH_RELATIONS));
    }

    /**
     * A narrow, Administrator-only hard-delete escape hatch for a Task
     * that has not moved beyond its initial creation (status still
     * `todo`) — a genuine data-entry mistake, nothing to preserve.
     * Anything with real activity must be cancelled instead
     * (`PATCH .../{public_id}` with `status: cancelled`), preserving
     * business history rather than destroying it (docs/phases/
     * V1_PHASE_11_DEFINITION.md). A Task with any Work Log referencing it
     * can never be deleted, regardless of status (docs/phases/
     * V1_PHASE_12_DEFINITION.md) — checked first, since real work logged
     * against it is an even stronger signal than status that history
     * would be lost.
     */
    public function destroy(Request $request, Task $task): JsonResponse
    {
        if ($task->workLogs()->exists()) {
            return response()->json([
                'message' => 'This task has work logs and cannot be deleted.',
            ], 409);
        }

        if ($task->serviceReports()->exists()) {
            return response()->json([
                'message' => 'This task has service reports and cannot be deleted.',
            ], 409);
        }

        if ($task->incidentReports()->exists()) {
            return response()->json([
                'message' => 'This task has incident reports and cannot be deleted.',
            ], 409);
        }

        if ($task->status !== TaskStatus::Todo) {
            return response()->json([
                'message' => 'This task has moved beyond its initial creation and cannot be deleted; cancel it instead.',
            ], 409);
        }

        $publicId = $task->public_id;
        $before = ['status' => $task->status->value, 'project_id' => $task->project?->public_id];

        DB::transaction(function () use ($request, $task, $publicId, $before) {
            $task->delete();

            $this->auditLogger->recordForRequest(
                $request,
                AuditActions::TASK_DELETED,
                entityType: 'Task',
                entityPublicId: $publicId,
                before: $before,
            );
        });

        return response()->json(status: 204);
    }

    /**
     * @param  class-string<Project|Staff>  $modelClass
     */
    private function resolveId(string $modelClass, ?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        return $modelClass::query()->where('public_id', $publicId)->value('id');
    }
}
