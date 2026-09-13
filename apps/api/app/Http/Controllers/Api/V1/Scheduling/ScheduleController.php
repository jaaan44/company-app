<?php

namespace App\Http\Controllers\Api\V1\Scheduling;

use App\Enums\ScheduleEntryActivityType;
use App\Enums\ScheduleSourceType;
use App\Http\Controllers\Api\V1\Scheduling\Concerns\AuthorizesScheduleEntryAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\ScheduleItemResource;
use App\Models\LeaveRequest;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ProjectMilestone;
use App\Models\Role;
use App\Models\ScheduleEntry;
use App\Models\Task;
use App\Support\CompanyTimezone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rules\Enum;

/**
 * The unified Scheduler read (`GET /api/v1/schedule`, Phase 17) —
 * exactly the four approved sources (manually created Schedule Entries,
 * Task due dates, approved Leave Requests, Project Milestones), computed
 * at read time. No calendar rows are copied or cached into a generic
 * table for any of these — each source module remains its own system of
 * record ("derive, don't cache", DEC-032/036/039); this endpoint only
 * projects each source's existing data into one common shape.
 *
 * Each source's own existing visibility rule is applied exactly as it
 * already exists elsewhere in this codebase (Task→AuthorizesTaskAccess,
 * Leave→AuthorizesLeaveRequestVisibility plus "I always see my own
 * approved leave" for this personal-schedule context specifically,
 * Milestone→AuthorizesProjectVisibility, Schedule Entry→
 * AuthorizesScheduleEntryAccess) — never a blanket company-wide
 * calendar (docs/phases/V1_PHASE_17_DEFINITION.md).
 *
 * Pagination note: this endpoint still returns Laravel's standard
 * LengthAwarePaginator shape (the same `data`/`links`/`meta` contract as
 * every other collection endpoint), but is fed by an in-memory merge of
 * each source's own small, independently authorized, date-range-scoped
 * query rather than a single SQL query — a genuine multi-source
 * aggregation across four differently-shaped, differently-secured tables
 * cannot be expressed as one portable SQL query without a fragile,
 * per-source-duplicated UNION, and at this company's scale (~100
 * employees) the total rows within a realistic date range is small
 * enough that fetching each source's matching rows and paginating the
 * merged, sorted result in PHP is simple, correct, and not a performance
 * concern. This is a documented deviation in *how* the paginator is fed,
 * not a new pagination *convention* — see the Phase 17 handoff.
 */
class ScheduleController extends Controller
{
    use AuthorizesScheduleEntryAccess;

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'source' => ['sometimes', new Enum(ScheduleSourceType::class)],
            'activity_type' => ['sometimes', new Enum(ScheduleEntryActivityType::class)],
            'project' => ['sometimes', 'string'],
        ]);

        $from = $request->date('from')->startOfDay();
        $to = $request->date('to')->endOfDay();
        $source = $request->filled('source') ? ScheduleSourceType::from($request->string('source')->toString()) : null;
        $activityType = $request->filled('activity_type') ? $request->string('activity_type')->toString() : null;
        $projectId = $this->resolveProjectId($request->string('project')->toString());

        if ($request->filled('project') && $projectId === null) {
            // An unknown project public_id can never match anything —
            // short-circuit rather than silently ignoring the filter.
            return ScheduleItemResource::collection($this->paginateItems(collect(), $request));
        }

        $items = collect();

        // An activity_type filter only ever describes a Schedule Entry
        // (docs/phases/V1_PHASE_17_DEFINITION.md — never confuse a
        // Scheduler source type with a Schedule Entry's own activity
        // type) — Task/Leave/Milestone contribute nothing when it's set.
        $includeOtherSources = $activityType === null;

        if ($source === null || $source === ScheduleSourceType::ScheduleEntry) {
            $items = $items->merge($this->scheduleEntryItems($request, $from, $to, $projectId, $activityType));
        }

        if ($includeOtherSources && ($source === null || $source === ScheduleSourceType::Task)) {
            $items = $items->merge($this->taskItems($request, $from, $to, $projectId));
        }

        // Leave has no Project association at all — a `project` filter
        // excludes it entirely rather than matching nothing "by luck".
        if ($includeOtherSources && $projectId === null && ($source === null || $source === ScheduleSourceType::Leave)) {
            $items = $items->merge($this->leaveItems($request, $from, $to));
        }

        if ($includeOtherSources && ($source === null || $source === ScheduleSourceType::ProjectMilestone)) {
            $items = $items->merge($this->milestoneItems($request, $from, $to, $projectId));
        }

        $sorted = $items->sortBy('starts_at')->values()->all();

        return ScheduleItemResource::collection($this->paginateItems(collect($sorted), $request));
    }

    private function scheduleEntryItems(Request $request, Carbon $from, Carbon $to, ?int $projectId, ?string $activityType): Collection
    {
        $entries = ScheduleEntry::query()
            ->with(['project', 'creator'])
            ->whereDate('ends_at', '>=', $from)
            ->whereDate('starts_at', '<=', $to)
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->when($activityType !== null, fn ($query) => $query->where('activity_type', $activityType))
            ->get();

        return $entries
            ->filter(fn (ScheduleEntry $entry) => $this->canView($request, $entry))
            ->map(fn (ScheduleEntry $entry) => [
                'source_type' => ScheduleSourceType::ScheduleEntry->value,
                'public_id' => $entry->public_id,
                'title' => $entry->title,
                'description' => $entry->description,
                'activity_type' => $entry->activity_type->value,
                'status' => null,
                'starts_at' => $entry->starts_at->toIso8601String(),
                'ends_at' => $entry->ends_at->toIso8601String(),
                'is_all_day' => $entry->is_all_day,
                'project' => $entry->project === null ? null : [
                    'public_id' => $entry->project->public_id,
                    'name' => $entry->project->name,
                ],
            ])
            ->values();
    }

    /**
     * Mirrors AuthorizesTaskAccess's exact visibility rule (DEC-034) —
     * duplicated here as a small, self-contained, non-aborting predicate
     * rather than modifying TaskController's own trait, which is
     * established, tested code outside this phase's scope.
     */
    private function taskItems(Request $request, Carbon $from, Carbon $to, ?int $projectId): Collection
    {
        $tasks = Task::query()
            ->with('project')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '>=', $from)
            ->whereDate('due_date', '<=', $to)
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->get();

        return $tasks
            ->filter(fn (Task $task) => $this->canViewTask($request, $task))
            ->map(fn (Task $task) => [
                'source_type' => ScheduleSourceType::Task->value,
                'public_id' => $task->public_id,
                'title' => $task->title,
                'description' => null,
                'activity_type' => null,
                'status' => $task->status->value,
                'starts_at' => CompanyTimezone::startOfDayUtc($task->due_date)->toIso8601String(),
                'ends_at' => CompanyTimezone::endOfDayUtc($task->due_date)->toIso8601String(),
                'is_all_day' => true,
                'project' => $task->project === null ? null : [
                    'public_id' => $task->project->public_id,
                    'name' => $task->project->name,
                ],
            ])
            ->values();
    }

    private function canViewTask(Request $request, Task $task): bool
    {
        if ($request->user()?->can('tasks.view')) {
            return true;
        }

        $staff = $request->user()?->staff;

        if ($staff === null) {
            return false;
        }

        if ($task->assignee_staff_id === $staff->id) {
            return true;
        }

        if ($task->project_id === null) {
            return false;
        }

        return ProjectMembership::query()
            ->where('project_id', $task->project_id)
            ->where('staff_id', $staff->id)
            ->exists();
    }

    /**
     * Only approved Leave Requests ever appear (never
     * pending/rejected/cancelled). Visibility mirrors
     * AuthorizesLeaveRequestVisibility (Administrator, or a Manager
     * holding `leave-requests.view` for their own direct reports) plus
     * one addition specific to this personal-schedule context: a Staff
     * member always sees their own approved leave here, exactly as they
     * already can via /api/v1/me/leave-requests — this is not a new
     * visibility grant, just this endpoint reusing that existing
     * self-service access instead of requiring a second round-trip.
     * Leave has no Project association, so it never contributes when a
     * `project` filter is present.
     */
    private function leaveItems(Request $request, Carbon $from, Carbon $to): Collection
    {
        $leaveRequests = LeaveRequest::query()
            ->with('staff')
            ->where('status', 'approved')
            ->whereDate('end_date', '>=', $from)
            ->whereDate('start_date', '<=', $to)
            ->get();

        return $leaveRequests
            ->filter(fn (LeaveRequest $leaveRequest) => $this->canViewLeaveRequest($request, $leaveRequest))
            ->map(fn (LeaveRequest $leaveRequest) => [
                'source_type' => ScheduleSourceType::Leave->value,
                'public_id' => $leaveRequest->public_id,
                'title' => 'Leave',
                'description' => null,
                'activity_type' => null,
                'status' => $leaveRequest->status->value,
                'starts_at' => CompanyTimezone::startOfDayUtc($leaveRequest->start_date)->toIso8601String(),
                'ends_at' => CompanyTimezone::endOfDayUtc($leaveRequest->end_date)->toIso8601String(),
                'is_all_day' => true,
                'project' => null,
            ])
            ->values();
    }

    private function canViewLeaveRequest(Request $request, LeaveRequest $leaveRequest): bool
    {
        if ($request->user()?->hasRole(Role::ADMINISTRATOR)) {
            return true;
        }

        $staff = $request->user()?->staff;

        if ($staff === null) {
            return false;
        }

        if ($leaveRequest->staff_id === $staff->id) {
            return true;
        }

        return $request->user()->can('leave-requests.view')
            && $leaveRequest->staff->manager_id === $staff->id;
    }

    /**
     * Mirrors AuthorizesProjectVisibility's exact rule.
     */
    private function milestoneItems(Request $request, Carbon $from, Carbon $to, ?int $projectId): Collection
    {
        $milestones = ProjectMilestone::query()
            ->with('project')
            ->whereDate('due_date', '>=', $from)
            ->whereDate('due_date', '<=', $to)
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->get();

        return $milestones
            ->filter(fn (ProjectMilestone $milestone) => $this->canViewMilestone($request, $milestone))
            ->map(fn (ProjectMilestone $milestone) => [
                'source_type' => ScheduleSourceType::ProjectMilestone->value,
                'public_id' => $milestone->public_id,
                'title' => $milestone->title,
                'description' => null,
                'activity_type' => null,
                'status' => $milestone->status->value,
                'starts_at' => CompanyTimezone::startOfDayUtc($milestone->due_date)->toIso8601String(),
                'ends_at' => CompanyTimezone::endOfDayUtc($milestone->due_date)->toIso8601String(),
                'is_all_day' => true,
                'project' => $milestone->project === null ? null : [
                    'public_id' => $milestone->project->public_id,
                    'name' => $milestone->project->name,
                ],
            ])
            ->values();
    }

    private function canViewMilestone(Request $request, ProjectMilestone $milestone): bool
    {
        if ($request->user()?->can('projects.view')) {
            return true;
        }

        $staff = $request->user()?->staff;

        if ($staff === null) {
            return false;
        }

        return ProjectMembership::query()
            ->where('project_id', $milestone->project_id)
            ->where('staff_id', $staff->id)
            ->exists();
    }

    private function resolveProjectId(?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        return Project::query()->where('public_id', $publicId)->value('id');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginateItems(Collection $items, Request $request): LengthAwarePaginator
    {
        $page = max(1, $request->integer('page', 1));
        $perPage = max(1, $request->integer('per_page', 50));
        $slice = $items->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $slice,
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }
}
