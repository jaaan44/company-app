<?php

namespace App\Http\Controllers\Api\V1\WorkLogs;

use App\Http\Controllers\Api\V1\StaffOperations\Concerns\RequiresLinkedStaff;
use App\Http\Controllers\Controller;
use App\Http\Requests\WorkLogs\StoreMyWorkLogRequest;
use App\Http\Requests\WorkLogs\UpdateMyWorkLogRequest;
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
 * Self-service Work Log logging (Phase 12) — reuses Phase 9's `/me/...`
 * precedent exactly: the performer's Staff identity is always server-
 * derived from the authenticated User's own linked Staff record, never a
 * client-supplied public ID. Requires a linked Staff record and (for
 * creation only) an active employment status — no permission is needed.
 * A public_id that exists but belongs to a different Staff member is
 * reported as 404, not 403 (docs/phases/V1_PHASE_12_DEFINITION.md —
 * existence of another Staff member's Work Log is itself sensitive).
 */
class MyWorkLogController extends Controller
{
    use RequiresLinkedStaff;

    private const WITH_RELATIONS = ['staff', 'task', 'project', 'creator.staff'];

    public function myIndex(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'project' => ['sometimes', 'string'],
            'task' => ['sometimes', 'string'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $staff = $this->resolveAuthenticatedStaff($request);

        $workLogs = $staff->workLogs()
            ->with(self::WITH_RELATIONS)
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

    public function myStore(StoreMyWorkLogRequest $request): JsonResponse
    {
        $staff = $this->resolveAuthenticatedStaff($request);

        $data = $request->validated();

        $taskId = array_key_exists('task_id', $data) ? $this->resolveId(Task::class, $data['task_id']) : null;
        $data['task_id'] = $taskId;
        $data['project_id'] = $taskId !== null
            ? Task::query()->whereKey($taskId)->value('project_id')
            : (array_key_exists('project_id', $data) ? $this->resolveId(Project::class, $data['project_id']) : null);

        $data['staff_id'] = $staff->id;
        $data['created_by_user_id'] = $request->user()->id;

        $workLog = WorkLog::create($data);

        return (new WorkLogResource($workLog->load(self::WITH_RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Only work_date/duration_minutes/description are ever applied — see
     * WorkLogController::update()'s identical note on why the validated()
     * payload isn't passed through as-is.
     */
    public function myUpdate(UpdateMyWorkLogRequest $request, WorkLog $workLog): WorkLogResource
    {
        $staff = $this->resolveAuthenticatedStaff($request);
        $this->authorizeOwnership($workLog, $staff);

        $workLog->update(Arr::only($request->validated(), ['work_date', 'duration_minutes', 'description']));

        return new WorkLogResource($workLog->load(self::WITH_RELATIONS));
    }

    public function myDestroy(Request $request, WorkLog $workLog): JsonResponse
    {
        $staff = $this->resolveAuthenticatedStaff($request);
        $this->authorizeOwnership($workLog, $staff);

        $workLog->delete();

        return response()->json(status: 204);
    }

    private function authorizeOwnership(WorkLog $workLog, Staff $staff): void
    {
        if ($workLog->staff_id !== $staff->id) {
            abort(404);
        }
    }

    /**
     * @param  class-string<Project|Task>  $modelClass
     */
    private function resolveId(string $modelClass, ?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        return $modelClass::query()->where('public_id', $publicId)->value('id');
    }
}
