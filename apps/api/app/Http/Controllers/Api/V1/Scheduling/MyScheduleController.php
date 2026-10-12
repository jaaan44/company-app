<?php

namespace App\Http\Controllers\Api\V1\Scheduling;

use App\Enums\LeaveRequestStatus;
use App\Enums\ScheduleSourceType;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\ScheduleEntry;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use App\Support\CompanyTimezone;
use App\Support\Reporting\OverdueTasks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * My schedule (Phase 30A, docs/phases/V1_PHASE_30_DEFINITION.md §5.1,
 * R-2…R-7): the authenticated person's own agenda over a range of company
 * days — Schedule Entries they created or participate in, their open
 * assigned tasks due in the range, and their approved leave. Never
 * milestones (R-3), and never widened by any role or permission: unlike
 * `GET /schedule` (Phase 17), which is visibility-scoped, this is always
 * the requester's own items.
 *
 * The range is a closed interval of company dates (`from`…`to`, at most
 * 31 days). A timed entry belongs to it when it overlaps the company-day
 * bounds in UTC; date-only items (tasks, leave) compare their dates with
 * the company dates — the DEC-052 "today" rule, extended to a range.
 *
 * Unpaginated (R-6): every item for the range in one response, in a total
 * order — start, all-day first, entries before tasks before leave, title
 * (byte-wise), then public_id. The query count is constant.
 */
class MyScheduleController extends Controller
{
    /** Longest range, in days, `to` may lie after `from` (31 days inclusive). */
    private const MAX_RANGE_DAYS = 30;

    private const TERMINAL_TASK_STATUSES = [TaskStatus::Completed, TaskStatus::Cancelled];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = $validated['from'];
        $to = $validated['to'];

        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > self::MAX_RANGE_DAYS) {
            validator([], [])->after(function (Validator $validator) {
                $validator->errors()->add('to', 'The range can be at most 31 days.');
            })->validate();
        }

        /** @var User $user */
        $user = $request->user();
        $staff = $user->staff;
        $today = OverdueTasks::todayInCompanyTimezone();

        return response()->json([
            'data' => [
                'company_day' => [
                    'date' => $today,
                    'timezone' => CompanyTimezone::value(),
                    'utc_offset' => Carbon::parse($today, CompanyTimezone::value())->startOfDay()->format('P'),
                ],
                'range' => ['from' => $from, 'to' => $to],
                'items' => $staff === null ? null : $this->items($staff, $from, $to),
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(Staff $staff, string $from, string $to): array
    {
        $rangeStart = CompanyTimezone::startOfDayUtc($from);
        $rangeEnd = CompanyTimezone::endOfDayUtc($to);

        $entries = $this->ownScheduleEntriesQuery($staff)
            ->with('project')
            ->where('starts_at', '<=', $rangeEnd)
            ->where('ends_at', '>=', $rangeStart)
            ->get()
            ->map(fn (ScheduleEntry $entry) => [
                'source_type' => ScheduleSourceType::ScheduleEntry->value,
                'public_id' => $entry->public_id,
                'title' => $entry->title,
                'description' => $entry->description,
                'activity_type' => $entry->activity_type->value,
                'status' => null,
                'task_status' => null,
                'starts_at' => $entry->starts_at->toIso8601String(),
                'ends_at' => $entry->ends_at->toIso8601String(),
                'is_all_day' => $entry->is_all_day,
                'project' => $entry->project === null ? null : [
                    'public_id' => $entry->project->public_id,
                    'name' => $entry->project->name,
                ],
            ]);

        $tasks = Task::query()
            ->with('project')
            ->where('assignee_staff_id', $staff->id)
            ->whereNotIn('status', array_map(fn (TaskStatus $status) => $status->value, self::TERMINAL_TASK_STATUSES))
            ->whereNotNull('due_date')
            ->whereDate('due_date', '>=', $from)
            ->whereDate('due_date', '<=', $to)
            ->get()
            ->map(fn (Task $task) => [
                'source_type' => ScheduleSourceType::Task->value,
                'public_id' => $task->public_id,
                'title' => $task->title,
                'description' => null,
                'activity_type' => null,
                'status' => $task->status->value,
                'task_status' => $task->status->value,
                // A date-only due date projected onto the company day, as
                // GET /schedule and Home's Today do — never an invented time.
                'starts_at' => CompanyTimezone::startOfDayUtc($task->due_date)->toIso8601String(),
                'ends_at' => CompanyTimezone::endOfDayUtc($task->due_date)->toIso8601String(),
                'is_all_day' => true,
                'project' => $task->project === null ? null : [
                    'public_id' => $task->project->public_id,
                    'name' => $task->project->name,
                ],
            ]);

        $leave = LeaveRequest::query()
            ->with('leaveType')
            ->where('staff_id', $staff->id)
            ->where('status', LeaveRequestStatus::Approved->value)
            ->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from)
            ->get()
            ->map(fn (LeaveRequest $leaveRequest) => [
                'source_type' => ScheduleSourceType::Leave->value,
                'public_id' => $leaveRequest->public_id,
                // The person's own leave, so its type is no secret (a
                // required, restrict-on-delete reference — always present).
                'title' => $leaveRequest->leaveType->name,
                'description' => null,
                'activity_type' => null,
                'status' => $leaveRequest->status->value,
                'task_status' => null,
                'starts_at' => CompanyTimezone::startOfDayUtc($leaveRequest->start_date)->toIso8601String(),
                'ends_at' => CompanyTimezone::endOfDayUtc($leaveRequest->end_date)->toIso8601String(),
                'is_all_day' => true,
                'project' => null,
            ]);

        $items = $entries->concat($tasks)->concat($leave)->all();
        usort($items, fn (array $a, array $b) => $this->compare($a, $b));

        return $items;
    }

    /**
     * Schedule Entries the person created or participates in — the two
     * personal clauses of AuthorizesScheduleEntryAccess (Phase 17), exactly
     * as Home's Today uses them (MyHomeController). Visibility through a
     * Project grants sight, not ownership, and is excluded.
     *
     * @return Builder<ScheduleEntry>
     */
    private function ownScheduleEntriesQuery(Staff $staff): Builder
    {
        return ScheduleEntry::query()->where(function (Builder $query) use ($staff) {
            $query->where('creator_staff_id', $staff->id)
                ->orWhereHas('participants', fn (Builder $q) => $q->whereKey($staff->id));
        });
    }

    /**
     * The total order (R-6): start ascending, all-day first, then schedule
     * entries, tasks, leave, then title byte-wise, then public_id (unique).
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function compare(array $a, array $b): int
    {
        $sourceRank = [
            ScheduleSourceType::ScheduleEntry->value => 0,
            ScheduleSourceType::Task->value => 1,
            ScheduleSourceType::Leave->value => 2,
        ];

        // strcmp(), not `<=>`, for strings: PHP compares numeric-looking
        // strings (titles "10" and "9") numerically.
        return (Carbon::parse($a['starts_at'])->getTimestamp() <=> Carbon::parse($b['starts_at'])->getTimestamp())
            ?: ((int) $b['is_all_day'] <=> (int) $a['is_all_day'])
            ?: ($sourceRank[$a['source_type']] <=> $sourceRank[$b['source_type']])
            ?: (strcmp($a['title'], $b['title']) <=> 0)
            ?: (strcmp($a['public_id'], $b['public_id']) <=> 0);
    }
}
