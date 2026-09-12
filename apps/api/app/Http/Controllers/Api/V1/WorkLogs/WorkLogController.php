<?php

namespace App\Http\Controllers\Api\V1\WorkLogs;

use App\Http\Controllers\Api\V1\WorkLogs\Concerns\AuthorizesWorkLogVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\WorkLogs\StoreWorkLogRequest;
use App\Http\Requests\WorkLogs\UpdateWorkLogRequest;
use App\Http\Resources\WorkLogResource;
use App\Models\Project;
use App\Models\Staff;
use App\Models\Task;
use App\Models\WorkLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;

/**
 * The supervisory/administrative Work Log surface (Phase 12) — self-
 * service lives entirely in MyWorkLogController instead (a genuinely
 * separate purpose, not a duplicate: the performer identity here is
 * always an explicit staff_id, never server-derived). Reads (`index`/
 * `show`) are scoped in-controller (AuthorizesWorkLogVisibility),
 * mirroring Phase 9's location.view precedent, not Phase 10/11's
 * company-wide Manager grant. Writes require `work-logs.manage`
 * (Administrator-only, route middleware) — no Project Lead/Manager
 * write authority exists anywhere in this module.
 */
class WorkLogController extends Controller
{
    use AuthorizesWorkLogVisibility;

    private const WITH_RELATIONS = ['staff', 'task', 'project', 'creator.staff'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'staff' => ['sometimes', 'string'],
            'project' => ['sometimes', 'string'],
            'task' => ['sometimes', 'string'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $query = $this->scopeVisibleWorkLogs($request, WorkLog::query()->with(self::WITH_RELATIONS));

        $workLogs = $query
            ->when(
                $request->filled('staff'),
                fn ($query) => $query->where('staff_id', $this->resolveId(Staff::class, $request->string('staff')->toString()) ?? -1)
            )
            ->when(
                $request->filled('project'),
                fn ($query) => $query->where('project_id', $this->resolveId(Project::class, $request->string('project')->toString()) ?? -1)
            )
            ->when(
                $request->filled('task'),
                fn ($query) => $query->where('task_id', $this->resolveId(Task::class, $request->string('task')->toString()) ?? -1)
            )
            ->when($request->filled('from'), fn ($query) => $query->whereDate('work_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('work_date', '<=', $request->date('to')))
            ->orderByDesc('work_date')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 50));

        return WorkLogResource::collection($workLogs);
    }

    public function show(Request $request, WorkLog $workLog): WorkLogResource
    {
        $this->authorizeShow($request, $workLog);

        return new WorkLogResource($workLog->load(self::WITH_RELATIONS));
    }

    /**
     * Administrator-only creation naming another Staff member as performer
     * (docs/phases/V1_PHASE_12_DEFINITION.md — Administrative /
     * Supervisory Logging). `work-logs.manage` is enforced by route
     * middleware.
     */
    public function store(StoreWorkLogRequest $request): JsonResponse
    {
        $data = $request->validated();

        $data['staff_id'] = $this->resolveId(Staff::class, $data['staff_id']);

        $taskId = array_key_exists('task_id', $data) ? $this->resolveId(Task::class, $data['task_id']) : null;
        $data['task_id'] = $taskId;
        $data['project_id'] = $taskId !== null
            ? Task::query()->whereKey($taskId)->value('project_id')
            : (array_key_exists('project_id', $data) ? $this->resolveId(Project::class, $data['project_id']) : null);

        $data['created_by_user_id'] = $request->user()->id;

        $workLog = WorkLog::create($data);

        return (new WorkLogResource($workLog->load(self::WITH_RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Only work_date/duration_minutes/description are ever applied —
     * staff_id/task_id/project_id are immutable after creation (docs/
     * phases/V1_PHASE_12_DEFINITION.md). Explicitly whitelisted rather
     * than passing the full validated() payload through: Laravel's
     * 'prohibited' rule treats an explicit client-supplied `null` as
     * "empty" and lets it pass validation, which would otherwise let a
     * request silently null out one of those columns via update().
     */
    public function update(UpdateWorkLogRequest $request, WorkLog $workLog): WorkLogResource
    {
        $workLog->update(Arr::only($request->validated(), ['work_date', 'duration_minutes', 'description']));

        return new WorkLogResource($workLog->load(self::WITH_RELATIONS));
    }

    public function destroy(WorkLog $workLog): JsonResponse
    {
        $workLog->delete();

        return response()->json(status: 204);
    }

    /**
     * @param  class-string<Staff|Project|Task>  $modelClass
     */
    private function resolveId(string $modelClass, ?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        return $modelClass::query()->where('public_id', $publicId)->value('id');
    }
}
