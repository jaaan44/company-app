<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\Staff;
use App\Models\Task;
use App\Services\Reporting\TaskVisibility;
use App\Support\Reporting\CsvExport;
use App\Support\Reporting\OverdueTasks;
use App\Support\Reporting\PublicIdResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tasks report (Phase 20 — Reports, DEC-043). No `can:<permission>`
 * route middleware — visibility is scoped in-controller via
 * App\Services\Reporting\TaskVisibility, the exact rule
 * TaskController's own `GET /api/v1/tasks` already enforces. `?overdue=1`
 * applies the single canonical overdue definition
 * (App\Support\Reporting\OverdueTasks — due_date before "today" in the
 * configured company timezone, status not Completed/Cancelled), the
 * same definition the Dashboard's `tasks.overdue_count` card uses.
 */
class TaskReportController extends Controller
{
    private const WITH_RELATIONS = ['project', 'assignee', 'creator.staff'];

    public function __construct(private readonly TaskVisibility $visibility) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return TaskResource::collection($this->filteredQuery($request)->paginate($request->integer('per_page', 50)));
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->filteredQuery($request)->cursor()->map(fn (Task $task) => [
            $task->public_id,
            $task->title,
            $task->status->value,
            $task->priority->value,
            $task->project?->name,
            $task->assignee?->displayName(),
            $task->due_date?->toDateString(),
            $task->completed_at?->toDateString(),
        ]);

        return CsvExport::stream('tasks.csv', [
            'Public ID', 'Title', 'Status', 'Priority', 'Project', 'Assignee', 'Due Date', 'Completed At',
        ], $rows);
    }

    /**
     * @return Builder<Task>
     */
    private function filteredQuery(Request $request): Builder
    {
        $request->validate([
            'project' => ['sometimes', 'string'],
            'assignee' => ['sometimes', 'string'],
            'status' => ['sometimes', new Enum(TaskStatus::class)],
            'priority' => ['sometimes', new Enum(TaskPriority::class)],
            'overdue' => ['sometimes', 'boolean'],
        ]);

        $query = $this->visibility->visibleQuery($request)
            ->with(self::WITH_RELATIONS)
            ->when(
                $request->filled('project'),
                fn ($query) => $query->where('project_id', PublicIdResolver::resolve(Project::class, $request->string('project')->toString()) ?? -1)
            )
            ->when(
                $request->filled('assignee'),
                fn ($query) => $query->where('assignee_staff_id', PublicIdResolver::resolve(Staff::class, $request->string('assignee')->toString()) ?? -1)
            )
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('priority'), fn ($query) => $query->where('priority', $request->string('priority')));

        if ($request->boolean('overdue')) {
            $query = OverdueTasks::scope($query);
        }

        return $query->orderBy('due_date');
    }
}
