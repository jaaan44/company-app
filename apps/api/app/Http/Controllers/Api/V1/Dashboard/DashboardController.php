<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Enums\ClientStatus;
use App\Enums\IncidentSeverity;
use App\Enums\LeaveRequestStatus;
use App\Enums\ProjectStatus;
use App\Enums\ServiceReportStatus;
use App\Enums\StaffStatus;
use App\Enums\TaskStatus;
use App\Http\Controllers\Api\V1\Scheduling\ScheduleController;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Staff;
use App\Services\Reporting\ClientVisibility;
use App\Services\Reporting\IncidentReportVisibility;
use App\Services\Reporting\LeaveRequestVisibility;
use App\Services\Reporting\ProjectVisibility;
use App\Services\Reporting\ServiceReportVisibility;
use App\Services\Reporting\StaffVisibility;
use App\Services\Reporting\TaskVisibility;
use App\Services\Reporting\WorkLogVisibility;
use App\Support\CompanyTimezone;
use App\Support\Reporting\EnumStatusCounts;
use App\Support\Reporting\OpenIncidents;
use App\Support\Reporting\OverdueTasks;
use App\Support\Reporting\ReportPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The Admin Dashboard (Phase 20 — Admin Dashboard & Reporting, DEC-043):
 * a single read-only, cross-module aggregation endpoint
 * (`GET /api/v1/dashboard`) returning KPI/summary/chart-ready data. See
 * docs/phases/V1_PHASE_20_DEFINITION.md for the full specification.
 *
 * **Governing visibility rule (DEC-043):** every section below is
 * computed exclusively from records the requester could already see
 * through that source module's own existing endpoint — never a
 * company-wide default, and never widened for an aggregate/count merely
 * because no individual row is returned. Each section delegates to the
 * matching App\Services\Reporting\*Visibility class, which reuses each
 * source module's own established authorization trait/predicates. No new
 * permission was introduced anywhere in this phase.
 *
 * **Point-in-time vs. period-based metrics:** `people`/`clients`/
 * `projects.active_projects_count`/`projects.by_status`/
 * `tasks.by_status`/`tasks.overdue_count`/`incident_reports.open_count`
 * are point-in-time — they reflect the current state of the data and are
 * never affected by `from`/`to`. `work_logs.total_hours`,
 * `leave.approved_count_in_period`, `service_reports.by_status_in_period`,
 * and `incident_reports.by_severity_in_period` are period-based — see
 * App\Support\Reporting\ReportPeriod for the exact default-current-month
 * (company timezone) semantics when `from`/`to` are omitted. `leave.
 * pending_count` is point-in-time (a "right now" snapshot of outstanding
 * requests, not scoped to any period).
 *
 * **Schedule** reuses ScheduleController::index() directly (the exact,
 * already-tested unified Scheduler visibility, Phase 17/DEC-040) rather
 * than re-implementing a second calendar aggregation — see schedule()
 * below. Its horizon is a fixed "now through the next 7 days," never
 * affected by this endpoint's own `from`/`to`.
 *
 * **Historical semantics:** every section is a live relational query
 * against current data — no snapshot table exists. In particular,
 * `people`/Staff-related breakdowns always reflect a Staff member's
 * *current* Department/Team/manager relationship; this Dashboard makes
 * no claim about a Staff member's organizational placement at any past
 * date (see docs/03_DATABASE_MODEL.md — no historical-period tracking
 * exists for Department/Team/manager assignment).
 */
class DashboardController extends Controller
{
    private const UPCOMING_SCHEDULE_ITEMS_LIMIT = 10;

    private const UPCOMING_SCHEDULE_HORIZON_DAYS = 7;

    public function __construct(
        private readonly StaffVisibility $staffVisibility,
        private readonly ClientVisibility $clientVisibility,
        private readonly ProjectVisibility $projectVisibility,
        private readonly TaskVisibility $taskVisibility,
        private readonly WorkLogVisibility $workLogVisibility,
        private readonly LeaveRequestVisibility $leaveRequestVisibility,
        private readonly ServiceReportVisibility $serviceReportVisibility,
        private readonly IncidentReportVisibility $incidentReportVisibility,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        $period = ReportPeriod::resolve($request);

        return response()->json([
            'data' => [
                'period' => [
                    'from' => $period->fromDate,
                    'to' => $period->toDate,
                    'source' => $period->wasExplicit ? 'explicit' : 'default_current_month',
                ],
                'people' => $this->people($request),
                'clients' => $this->clients($request),
                'projects' => $this->projects($request),
                'tasks' => $this->tasks($request),
                'work_logs' => $this->workLogs($request, $period),
                'leave' => $this->leave($request, $period),
                'service_reports' => $this->serviceReports($request, $period),
                'incident_reports' => $this->incidentReports($request, $period),
                'schedule' => $this->schedule($request),
            ],
        ]);
    }

    /**
     * Point-in-time. `active_staff_count` is company-wide for any
     * `staff.view` holder (Administrator/Manager/Staff all hold it by
     * default) — never scoped further, since the source Staff Directory
     * itself grants exactly that (see App\Services\Reporting\
     * StaffVisibility).
     *
     * @return array<string, mixed>
     */
    private function people(Request $request): array
    {
        if (! $this->staffVisibility->canView($request)) {
            return ['active_staff_count' => 0];
        }

        return [
            'active_staff_count' => Staff::query()->where('status', StaffStatus::Active->value)->count(),
        ];
    }

    /**
     * Point-in-time. Mirrors people() exactly for Clients.
     *
     * @return array<string, mixed>
     */
    private function clients(Request $request): array
    {
        if (! $this->clientVisibility->canView($request)) {
            return ['active_clients_count' => 0];
        }

        return [
            'active_clients_count' => Client::query()->where('status', ClientStatus::Active->value)->count(),
        ];
    }

    /**
     * Point-in-time.
     *
     * @return array<string, mixed>
     */
    private function projects(Request $request): array
    {
        $query = $this->projectVisibility->visibleQuery($request);

        return [
            'active_projects_count' => (clone $query)->where('status', ProjectStatus::Active->value)->count(),
            'by_status' => EnumStatusCounts::forColumn($query, 'status', ProjectStatus::cases()),
        ];
    }

    /**
     * Point-in-time — by_status and overdue_count both reflect the
     * currently visible Task set, never filtered by this endpoint's
     * from/to (a "current backlog" snapshot, not a historical one).
     *
     * @return array<string, mixed>
     */
    private function tasks(Request $request): array
    {
        $query = $this->taskVisibility->visibleQuery($request);

        return [
            'by_status' => EnumStatusCounts::forColumn($query, 'status', TaskStatus::cases()),
            'overdue_count' => OverdueTasks::scope(clone $query)->count(),
        ];
    }

    /**
     * Period-based. `duration_minutes` is summed and converted to hours
     * (rounded to 2 decimal places) — this is operational activity
     * reporting only, never a ranking/comparison/performance score (see
     * docs/phases/V1_PHASE_20_DEFINITION.md's Work Log framing).
     *
     * @return array<string, mixed>
     */
    private function workLogs(Request $request, ReportPeriod $period): array
    {
        $totalMinutes = (int) $this->workLogVisibility->visibleQuery($request)
            ->whereDate('work_date', '>=', $period->fromDate)
            ->whereDate('work_date', '<=', $period->toDate)
            ->sum('duration_minutes');

        return [
            'total_hours' => round($totalMinutes / 60, 2),
        ];
    }

    /**
     * `pending_count` is point-in-time (outstanding requests right now);
     * `approved_count_in_period` counts Approved requests whose date
     * range overlaps [from, to] — the identical overlap semantics
     * LeaveRequestController's own `?from=&to=` filter already uses.
     *
     * @return array<string, mixed>
     */
    private function leave(Request $request, ReportPeriod $period): array
    {
        $baseQuery = $this->leaveRequestVisibility->visibleQuery($request);

        $pendingCount = (clone $baseQuery)->where('status', LeaveRequestStatus::Pending->value)->count();

        $approvedInPeriodCount = (clone $baseQuery)
            ->where('status', LeaveRequestStatus::Approved->value)
            ->whereDate('end_date', '>=', $period->fromDate)
            ->whereDate('start_date', '<=', $period->toDate)
            ->count();

        return [
            'pending_count' => $pendingCount,
            'approved_count_in_period' => $approvedInPeriodCount,
        ];
    }

    /**
     * Period-based, filtered by `service_date` (mirrors
     * ServiceReportController's own `?from=&to=` filter semantics).
     *
     * @return array<string, mixed>
     */
    private function serviceReports(Request $request, ReportPeriod $period): array
    {
        $query = $this->serviceReportVisibility->visibleQuery($request)
            ->whereDate('service_date', '>=', $period->fromDate)
            ->whereDate('service_date', '<=', $period->toDate);

        return [
            'by_status_in_period' => EnumStatusCounts::forColumn($query, 'status', ServiceReportStatus::cases()),
        ];
    }

    /**
     * `open_count` is point-in-time (status IN (reported,
     * under_investigation), the canonical definition — App\Support\
     * Reporting\OpenIncidents). `by_severity_in_period` is filtered by
     * `occurred_at` using true company-timezone UTC instant boundaries
     * (App\Support\Reporting\ReportPeriod::utcFrom()/utcTo()), since
     * `occurred_at` is a genuine datetime column, unlike the other
     * period-based metrics' plain DATE columns.
     *
     * Both are derived exclusively from
     * IncidentReportVisibility::visibleQuery() — the same row-level rule
     * GET /api/v1/incident-reports itself enforces, deliberately with no
     * Project-Lead carve-out (DEC-042).
     *
     * @return array<string, mixed>
     */
    private function incidentReports(Request $request, ReportPeriod $period): array
    {
        $baseQuery = $this->incidentReportVisibility->visibleQuery($request);

        $openCount = OpenIncidents::scope(clone $baseQuery)->count();

        $periodQuery = (clone $baseQuery)
            ->where('occurred_at', '>=', $period->utcFrom())
            ->where('occurred_at', '<=', $period->utcTo());

        return [
            'open_count' => $openCount,
            'by_severity_in_period' => EnumStatusCounts::forColumn($periodQuery, 'severity', IncidentSeverity::cases()),
        ];
    }

    /**
     * Reuses ScheduleController::index() verbatim — a synthetic
     * `GET /api/v1/schedule?from=<now>&to=<now+7d>` request carrying the
     * *same* authenticated user, so every visibility rule Scheduler's own
     * unified aggregation already applies (Task/Leave/Milestone/Schedule
     * Entry, each via that endpoint's own established rule, Phase 17/
     * DEC-040) governs this widget exactly as it would the real
     * endpoint — this Dashboard invents no second calendar aggregation
     * model. The horizon is fixed at "now through the next 7 days" and
     * is never affected by this endpoint's own from/to.
     *
     * @return array<string, mixed>
     */
    private function schedule(Request $request): array
    {
        $now = Carbon::now(CompanyTimezone::value());
        $horizonEnd = $now->copy()->addDays(self::UPCOMING_SCHEDULE_HORIZON_DAYS);

        $scheduleRequest = Request::create('/api/v1/schedule', 'GET', [
            'from' => $now->toIso8601String(),
            'to' => $horizonEnd->toIso8601String(),
            'per_page' => self::UPCOMING_SCHEDULE_ITEMS_LIMIT,
        ]);
        $scheduleRequest->setUserResolver($request->getUserResolver());

        $collection = app(ScheduleController::class)->index($scheduleRequest);
        $responseData = $collection->response($scheduleRequest)->getData(true);

        return [
            'horizon' => [
                'from' => $now->toIso8601String(),
                'to' => $horizonEnd->toIso8601String(),
            ],
            'upcoming_count' => $responseData['meta']['total'] ?? count($responseData['data'] ?? []),
            'items' => array_slice($responseData['data'] ?? [], 0, self::UPCOMING_SCHEDULE_ITEMS_LIMIT),
        ];
    }
}
