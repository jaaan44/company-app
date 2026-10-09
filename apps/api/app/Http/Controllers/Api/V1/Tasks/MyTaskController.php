<?php

namespace App\Http\Controllers\Api\V1\Tasks;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\User;
use App\Support\CompanyTimezone;
use App\Support\Reporting\OverdueTasks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * My tasks (Phase 29A — Tasks (Mobile), R-2/R-3): `GET /api/v1/me/tasks`,
 * the tasks assigned to the authenticated person, for the mobile Tasks
 * tab. See docs/phases/V1_PHASE_29_DEFINITION.md §5.1.
 *
 * **Always the authenticated person's own assigned tasks.** The subject
 * is the token's linked Staff record, matched on `assignee_staff_id`
 * only — no `tasks.view` (Administrator/Manager) or Project Lead branch
 * widens it, mirroring `/me/home` (DEC-052). No permission is involved.
 *
 * **Open/closed and overdue reuse the canonical definitions:** "closed"
 * is OverdueTasks' terminal set (completed, cancelled) and "today" is
 * OverdueTasks::todayInCompanyTimezone(), so `is_overdue`/`is_due_today`
 * agree with `/me/home`'s counts and with Phase 20's reports.
 *
 * **No linked Staff record is required** (the `/me/home`/`/me/profile`
 * precedent): `tasks` is `null` (with `200`) instead of a `403`.
 *
 * Task status changes are NOT made here — they use the existing
 * `PATCH /api/v1/tasks/{public_id}` with its assignee status-only rule.
 */
class MyTaskController extends Controller
{
    private const WITH_RELATIONS = ['project', 'assignee', 'creator.staff'];

    private const TERMINAL_STATUSES = [TaskStatus::Completed, TaskStatus::Cancelled];

    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'state' => ['sometimes', Rule::in(['open', 'closed'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $staff = $user->staff;
        $today = OverdueTasks::todayInCompanyTimezone();

        $companyDay = [
            'date' => $today,
            'timezone' => CompanyTimezone::value(),
        ];

        if ($staff === null) {
            return response()->json([
                'data' => ['company_day' => $companyDay, 'tasks' => null],
                'meta' => null,
            ]);
        }

        $open = ($validated['state'] ?? 'open') === 'open';
        $terminal = array_map(fn (TaskStatus $s) => $s->value, self::TERMINAL_STATUSES);

        $query = Task::query()
            ->with(self::WITH_RELATIONS)
            ->where('assignee_staff_id', $staff->id);

        if ($open) {
            // Soonest due first; undated tasks last; `id` makes the order
            // total, so pages never skip or repeat a task.
            $query->whereNotIn('status', $terminal)
                ->orderByRaw('due_date IS NULL')
                ->orderBy('due_date')
                ->orderBy('id');
        } else {
            // Most recently finished first. A cancelled task has no
            // completed_at, so updated_at (when it was cancelled, absent
            // later edits) places it among the completed ones.
            $query->whereIn('status', $terminal)
                ->orderByRaw('completed_at IS NULL')
                ->orderByDesc('completed_at')
                ->orderByDesc('updated_at')
                ->orderBy('id');
        }

        $page = $query->paginate((int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE));

        $tasks = $page->getCollection()->map(function (Task $task) use ($request, $today) {
            $isOpen = ! in_array($task->status, self::TERMINAL_STATUSES, true);
            $due = $task->due_date?->toDateString();

            return [
                ...(new TaskResource($task))->resolve($request),
                'is_overdue' => $isOpen && $due !== null && $due < $today,
                'is_due_today' => $isOpen && $due === $today,
            ];
        })->values()->all();

        return response()->json([
            'data' => ['company_day' => $companyDay, 'tasks' => $tasks],
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
