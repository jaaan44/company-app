<?php

namespace App\Http\Controllers\Api\V1\Home;

use App\Enums\ScheduleSourceType;
use App\Enums\TaskStatus;
use App\Http\Controllers\Api\V1\Announcements\Concerns\ScopesAnnouncementVisibility;
use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Notification;
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
use Illuminate\Support\Facades\DB;

/**
 * The employee Home (Phase 27 — Employee Home / Dashboard (Mobile),
 * DEC-052): `GET /api/v1/me/home`, a single read-only, bounded, self-scoped
 * summary of the authenticated person's own data. See
 * docs/phases/V1_PHASE_27_DEFINITION.md §6 for the full contract.
 *
 * **Always the authenticated person's own Home.** The subject is derived
 * exclusively from the authenticated token (`$request->user()` and its
 * linked Staff record) — no request parameter of any kind is read. No
 * role widens anything: there is deliberately no `tasks.view`/Project Lead/
 * Manager direct-report/Administrator (`Gate::before`) branch anywhere in
 * this class, unlike GET /api/v1/dashboard (DEC-043), which is
 * visibility-scoped and therefore not reused here.
 *
 * **No linked Staff record is required** (the Notifications precedent,
 * DEC-038): such a user still gets `200`, with `user`, `company_day` and
 * `notifications` populated and every Staff-dependent section `null`.
 *
 * Every definition below reuses an existing module's own semantics —
 * OverdueTasks (Phase 20), CompanyTimezone (Phase 17),
 * ScopesAnnouncementVisibility (Phase 14), ConversationMember::
 * unreadCount()'s predicate (Phase 16), and NotificationController::
 * myUnreadCount()'s predicate (Phase 15) — rather than inventing
 * Home-specific ones. Every collection is bounded in the database (at
 * most 5 Today items, at most 3 announcements), and the query count is
 * independent of how much data the employee has.
 */
class MyHomeController extends Controller
{
    use ScopesAnnouncementVisibility;

    private const TODAY_ITEMS_LIMIT = 5;

    private const LATEST_ANNOUNCEMENTS_LIMIT = 3;

    /**
     * The terminal Task statuses — the exact set OverdueTasks::scope()
     * already excludes as "not open" (Phase 20, DEC-043). Tasks has no
     * other "open" definition, so Home reuses this one set for
     * open_count, due_today_count and the Today task items alike.
     */
    private const TERMINAL_TASK_STATUSES = [TaskStatus::Completed, TaskStatus::Cancelled];

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('role');

        $staff = $user->staff;
        $staff?->loadMissing(['position', 'department', 'team']);

        $companyDate = OverdueTasks::todayInCompanyTimezone();
        $dayStart = CompanyTimezone::startOfDayUtc($companyDate);
        $dayEnd = CompanyTimezone::endOfDayUtc($companyDate);

        return response()->json([
            'data' => [
                'user' => [
                    'public_id' => $user->public_id,
                    'name' => $user->name,
                    'role' => $user->role?->name,
                ],
                'staff' => $staff === null ? null : $this->staffProfile($staff),
                'company_day' => [
                    'date' => $companyDate,
                    'timezone' => CompanyTimezone::value(),
                    'starts_at' => $dayStart->toIso8601String(),
                    'ends_at' => $dayEnd->toIso8601String(),
                    'utc_offset' => Carbon::parse($companyDate, CompanyTimezone::value())->startOfDay()->format('P'),
                ],
                'today' => $staff === null ? null : $this->today($staff, $companyDate, $dayStart, $dayEnd),
                'tasks' => $staff === null ? null : $this->taskCounts($staff, $companyDate),
                'messages' => $staff === null ? null : ['unread_count' => $this->unreadMessageCount($staff)],
                'notifications' => ['unread_count' => $this->unreadNotificationCount($user)],
                'announcements' => $staff === null ? null : ['latest' => $this->latestAnnouncements($staff)],
            ],
        ]);
    }

    /**
     * Only the approved Home profile fields — never contact details,
     * employee number, manager, employment status, operational status, or
     * location.
     *
     * @return array<string, mixed>
     */
    private function staffProfile(Staff $staff): array
    {
        return [
            'public_id' => $staff->public_id,
            'display_name' => $staff->displayName(),
            'first_name' => $staff->first_name,
            'preferred_name' => $staff->preferred_name,
            'position' => $staff->position === null ? null : [
                'public_id' => $staff->position->public_id,
                'title' => $staff->position->title,
            ],
            'department' => $staff->department === null ? null : [
                'public_id' => $staff->department->public_id,
                'name' => $staff->department->name,
            ],
            'team' => $staff->team === null ? null : [
                'public_id' => $staff->team->public_id,
                'name' => $staff->team->name,
            ],
        ];
    }

    /**
     * Today (spec §6.3): the employee's own Schedule Entries (creator or
     * participant — never project-derived visibility) overlapping the
     * company day, plus open Tasks assigned to them due on the company
     * date. No Leave, no Project Milestones.
     *
     * Each source is fetched in the database with the same ordering the
     * merge below applies, limited to TODAY_ITEMS_LIMIT rows, so the merged
     * top 5 is exactly the top 5 of the full union without loading an
     * unbounded collection. total_count comes from two COUNT queries.
     *
     * @return array<string, mixed>
     */
    private function today(Staff $staff, string $companyDate, Carbon $dayStart, Carbon $dayEnd): array
    {
        $entriesQuery = $this->ownScheduleEntriesQuery($staff)
            ->where('starts_at', '<=', $dayEnd)
            ->where('ends_at', '>=', $dayStart);

        $tasksQuery = $this->openAssignedTasksQuery($staff)
            ->whereDate('due_date', '=', $companyDate);

        $entries = (clone $entriesQuery)
            ->select(['public_id', 'title', 'activity_type', 'starts_at', 'ends_at', 'is_all_day'])
            ->orderBy('starts_at')
            ->orderByDesc('is_all_day')
            ->orderByRaw($this->byteWiseTitleOrder())
            ->orderBy('public_id')
            ->limit(self::TODAY_ITEMS_LIMIT)
            ->get();

        // Every Today task shares the same company-day starts_at and is
        // all-day, so the database ordering starts at title.
        $tasks = (clone $tasksQuery)
            ->select(['public_id', 'title', 'status', 'due_date'])
            ->orderByRaw($this->byteWiseTitleOrder())
            ->orderBy('public_id')
            ->limit(self::TODAY_ITEMS_LIMIT)
            ->get();

        $items = $entries->map(fn (ScheduleEntry $entry) => [
            'source_type' => ScheduleSourceType::ScheduleEntry->value,
            'public_id' => $entry->public_id,
            'title' => $entry->title,
            'activity_type' => $entry->activity_type->value,
            'task_status' => null,
            'starts_at' => $entry->starts_at->toIso8601String(),
            'ends_at' => $entry->ends_at->toIso8601String(),
            'is_all_day' => $entry->is_all_day,
        ])->concat($tasks->map(fn (Task $task) => [
            // Identical representation to ScheduleController's own Task
            // items (Phase 17): a date-only due date projected onto the
            // company day, never an invented time of day.
            'source_type' => ScheduleSourceType::Task->value,
            'public_id' => $task->public_id,
            'title' => $task->title,
            'activity_type' => null,
            'task_status' => $task->status->value,
            'starts_at' => CompanyTimezone::startOfDayUtc($task->due_date)->toIso8601String(),
            'ends_at' => CompanyTimezone::endOfDayUtc($task->due_date)->toIso8601String(),
            'is_all_day' => true,
        ]))->all();

        usort($items, fn (array $a, array $b) => $this->compareTodayItems($a, $b));

        return [
            'total_count' => $entriesQuery->count() + $tasksQuery->count(),
            'items' => array_slice($items, 0, self::TODAY_ITEMS_LIMIT),
        ];
    }

    /**
     * The approved deterministic Today ordering (spec §6.3 point 4):
     * starts_at ascending, all-day first, schedule entries before tasks,
     * title byte-wise ascending, then public_id (unique — a total order).
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function compareTodayItems(array $a, array $b): int
    {
        $sourceRank = [
            ScheduleSourceType::ScheduleEntry->value => 0,
            ScheduleSourceType::Task->value => 1,
        ];

        // strcmp(), not `<=>`, for the string keys: PHP compares two
        // numeric-looking strings (e.g. titles "10" and "9") numerically.
        return (Carbon::parse($a['starts_at'])->getTimestamp() <=> Carbon::parse($b['starts_at'])->getTimestamp())
            ?: ((int) $b['is_all_day'] <=> (int) $a['is_all_day'])
            ?: ($sourceRank[$a['source_type']] <=> $sourceRank[$b['source_type']])
            ?: (strcmp($a['title'], $b['title']) <=> 0)
            ?: (strcmp($a['public_id'], $b['public_id']) <=> 0);
    }

    /**
     * Byte-wise title ordering, so the database's per-source ordering is
     * the same comparison PHP's `<=>` applies to strings in the merge
     * above. SQLite (the test database) already orders TEXT with its
     * default BINARY collation; MySQL's default utf8mb4 collations are
     * case-insensitive, so the column is compared as binary there.
     */
    private function byteWiseTitleOrder(): string
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? 'CAST(title AS BINARY) ASC'
            : 'title ASC';
    }

    /**
     * Schedule Entries the employee created or participates in — exactly
     * the two personal clauses of AuthorizesScheduleEntryAccess::
     * scopeVisibleScheduleEntries() (Phase 17). Its third clause
     * (visibility through a Project) grants visibility, not ownership,
     * and is deliberately excluded.
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
     * Tasks whose single assignee (DEC-034) is the employee and whose
     * status is not terminal. Never project membership, `tasks.view`, or
     * Project Lead visibility.
     *
     * @return Builder<Task>
     */
    private function openAssignedTasksQuery(Staff $staff): Builder
    {
        return Task::query()
            ->where('assignee_staff_id', $staff->id)
            ->whereNotIn('status', array_map(fn (TaskStatus $status) => $status->value, self::TERMINAL_TASK_STATUSES));
    }

    /**
     * @return array<string, int>
     */
    private function taskCounts(Staff $staff, string $companyDate): array
    {
        $assignedQuery = Task::query()->where('assignee_staff_id', $staff->id);

        return [
            'open_count' => $this->openAssignedTasksQuery($staff)->count(),
            // The canonical overdue definition (Phase 20), reused verbatim.
            'overdue_count' => OverdueTasks::scope($assignedQuery)->count(),
            'due_today_count' => $this->openAssignedTasksQuery($staff)
                ->whereDate('due_date', '=', $companyDate)
                ->count(),
        ];
    }

    /**
     * Σ over the employee's current conversation memberships of the exact
     * ConversationMember::unreadCount() predicate (messages after
     * last_read_message_id, or every message when nothing has been read)
     * — computed as a single aggregate query rather than one query per
     * conversation.
     */
    private function unreadMessageCount(Staff $staff): int
    {
        return DB::table('messages')
            ->join('conversation_members', 'conversation_members.conversation_id', '=', 'messages.conversation_id')
            ->where('conversation_members.staff_id', $staff->id)
            ->where(function ($query) {
                $query->whereNull('conversation_members.last_read_message_id')
                    ->orWhereColumn('messages.id', '>', 'conversation_members.last_read_message_id');
            })
            ->count();
    }

    /**
     * Identical to NotificationController::myUnreadCount() (Phase 15,
     * `GET /me/notifications/unread-count`).
     */
    private function unreadNotificationCount(User $user): int
    {
        return Notification::query()
            ->where('recipient_user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * The existing self-service eligibility rule (published, company-wide
     * or scoped to the employee's current Department/Team) — reused via
     * ScopesAnnouncementVisibility, so Home can never show an
     * announcement GET /me/announcements would not. Preview fields only.
     *
     * @return list<array<string, mixed>>
     */
    private function latestAnnouncements(Staff $staff): array
    {
        return $this->scopeVisibleToStaff(Announcement::query(), $staff)
            ->select(['id', 'public_id', 'title', 'published_at'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::LATEST_ANNOUNCEMENTS_LIMIT)
            ->get()
            ->map(fn (Announcement $announcement) => [
                'public_id' => $announcement->public_id,
                'title' => $announcement->title,
                'published_at' => $announcement->published_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
