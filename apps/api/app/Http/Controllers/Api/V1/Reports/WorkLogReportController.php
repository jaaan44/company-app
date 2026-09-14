<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkLogResource;
use App\Models\Project;
use App\Models\Staff;
use App\Models\Task;
use App\Models\WorkLog;
use App\Services\Audit\AuditLogger;
use App\Services\Reporting\WorkLogVisibility;
use App\Support\Audit\AuditActions;
use App\Support\Reporting\CsvExport;
use App\Support\Reporting\PublicIdResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Work Logs report (Phase 20 — Reports, DEC-043). No `can:<permission>`
 * route middleware — visibility is scoped in-controller via
 * App\Services\Reporting\WorkLogVisibility, the exact rule
 * WorkLogController's own supervisory `GET /api/v1/work-logs` surface
 * already enforces (plus a plain-Staff own-records fallback — see that
 * class). Operational activity reporting only: hours-by-Staff/Project/
 * Task is exposed as plain totals, never a ranking, comparison,
 * productivity score, or leaderboard.
 */
class WorkLogReportController extends Controller
{
    private const WITH_RELATIONS = ['staff', 'task', 'project', 'creator.staff'];

    public function __construct(
        private readonly WorkLogVisibility $visibility,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return WorkLogResource::collection($this->filteredQuery($request)->paginate($request->integer('per_page', 50)));
    }

    public function export(Request $request): StreamedResponse
    {
        $this->auditLogger->recordForRequest(
            $request,
            AuditActions::REPORT_EXPORTED,
            entityType: 'Report',
            entityPublicId: null,
            after: ['report' => 'work-logs'],
        );

        $rows = $this->filteredQuery($request)->cursor()->map(fn (WorkLog $log) => [
            $log->public_id,
            $log->staff?->public_id,
            $log->staff?->displayName(),
            $log->project?->public_id,
            $log->project?->name,
            $log->task?->public_id,
            $log->task?->title,
            $log->work_date->toDateString(),
            $log->duration_minutes,
            $log->description,
        ]);

        return CsvExport::stream('work-logs.csv', [
            'Public ID', 'Staff Public ID', 'Staff Name', 'Project Public ID', 'Project Name',
            'Task Public ID', 'Task Title', 'Work Date', 'Duration (Minutes)', 'Description',
        ], $rows);
    }

    /**
     * @return Builder<WorkLog>
     */
    private function filteredQuery(Request $request): Builder
    {
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'staff' => ['sometimes', 'string'],
            'project' => ['sometimes', 'string'],
            'task' => ['sometimes', 'string'],
        ]);

        return $this->visibility->visibleQuery($request)
            ->with(self::WITH_RELATIONS)
            ->when(
                $request->filled('staff'),
                fn ($query) => $query->where('staff_id', PublicIdResolver::resolve(Staff::class, $request->string('staff')->toString()) ?? -1)
            )
            ->when(
                $request->filled('project'),
                fn ($query) => $query->where('project_id', PublicIdResolver::resolve(Project::class, $request->string('project')->toString()) ?? -1)
            )
            ->when(
                $request->filled('task'),
                fn ($query) => $query->where('task_id', PublicIdResolver::resolve(Task::class, $request->string('task')->toString()) ?? -1)
            )
            ->when($request->filled('from'), fn ($query) => $query->whereDate('work_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('work_date', '<=', $request->date('to')))
            ->orderByDesc('work_date')
            ->orderByDesc('created_at');
    }
}
