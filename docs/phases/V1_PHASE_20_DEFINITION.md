# V1 Phase 20 — Admin Dashboard & Reporting: Definition

**Status:** Authorized and implemented. Preceded by a product-owner-reviewed planning audit (delivered as a chat message, not a file, ahead of this phase) that surveyed every implemented module for dashboard/reporting implications and resolved the roadmap's terse "Cross-module dashboard and administrative reports" line into concrete architecture — see `docs/DECISIONS.md` DEC-043 for the accepted decisions.

## 1. Scope

Two read-only API surfaces, both backed by ordinary indexed Eloquent/query-builder queries — no persisted report entity, no reporting/snapshot/denormalized table, no data warehouse, OLAP, Elasticsearch, materialized view, caching layer, queue, background worker, or cron/scheduled aggregation:

- **Dashboard** (`GET /api/v1/dashboard`) — a single cross-module aggregation endpoint returning KPI/summary/chart-ready data.
- **Reports** (`GET /api/v1/reports/{resource}` × 7, plus `.../export` for CSV) — filterable, paginated administrative detail reports.

## 2. Governing authorization rule (DEC-043)

**Dashboard and Report aggregation must never widen the visibility already granted by the underlying source module.** If a requester can see only a subset of source records through that module's existing authorization model, every Phase 20 aggregate/report for that source is computed from that same visible subset — including aggregate counts with no drill-down. There are no coarse-count privacy exceptions in this phase.

No new permission (`dashboard.view`, `reports.view`, or otherwise) was introduced anywhere in this phase. Every section/report reuses the exact row-level visibility rule its source module's own endpoint already enforces, via a dedicated `App\Services\Reporting\*Visibility` class per source (see `02_ARCHITECTURE.md` §30 for the full list). Where a source module's own trait exposes only abort()-oriented methods built for a single endpoint (e.g. `AuthorizesIncidentReportAccess::scopeVisibleIncidentReports()`, which `abort(403)`s when a requester has no staff link at all), the Reporting service reuses that trait's pure, non-aborting predicates (`isAdministrator`, `isCreator`, `isDirectManagerOf...`, `isProjectLeadOf`, `canViewAsManager`, ...) and reproduces the exact same composed WHERE clause itself, adapted to return an *empty* result instead of aborting — the identical adaptation `ScheduleController` already established for Task/Leave/Milestone visibility in Phase 17 (DEC-040), applied here to five more sources. Existing source-module trait/controller files were **not modified** — this phase carries zero regression risk to Phases 7–19's own tested authorization behavior.

Two source modules (Work Logs, Leave Requests) have supervisory visibility rules that deliberately exclude an ordinary Staff member entirely (their own records are reachable only via a separate `/me/...` self-service surface). Phase 20's `WorkLogVisibility`/`LeaveRequestVisibility` services add one narrow, explicitly-documented fallback: a plain Staff member with no supervisory access still sees **their own** records — exactly what they already see via `GET /api/v1/me/work-logs`/`/me/leave-requests`. This is required by the governing rule itself (never *withhold* what a source module already grants, just as it must never *widen* beyond it) and is called out in every relevant service class's docblock and in DEC-043.

## 3. Dashboard (`GET /api/v1/dashboard`)

Query params: `from`, `to` (both optional dates; `to` must be `>= from` when both are given).

**Date semantics (App\Support\Reporting\ReportPeriod):**
- Period-based metrics (`work_logs.total_hours`, `leave.approved_count_in_period`, `service_reports.by_status_in_period`, `incident_reports.by_severity_in_period`) use `[from, to]` when supplied; when omitted, they default to the **current calendar month in the configured company timezone** (`config('scheduling.company_timezone')`, `App\Support\CompanyTimezone`, Phase 17) — never the server process's own default timezone.
- Point-in-time metrics (`people.active_staff_count`, `clients.active_clients_count`, `projects.active_projects_count`, `projects.by_status`, `tasks.by_status`, `tasks.overdue_count`, `leave.pending_count`, `incident_reports.open_count`) never change because a `from`/`to` was supplied — they always reflect current data.
- `schedule` uses its own fixed V1 horizon — **now through the next 7 days** — never redefined by this endpoint's own `from`/`to`.
- `period.source` in the response is `"explicit"` when either `from` or `to` was supplied, `"default_current_month"` otherwise.

**Response shape** — `{"data": {...}}`, sections:

| Section | Fields | Kind |
|---|---|---|
| `people` | `active_staff_count` | point-in-time |
| `clients` | `active_clients_count` | point-in-time |
| `projects` | `active_projects_count`, `by_status` (all `ProjectStatus` cases, 0-filled) | point-in-time |
| `tasks` | `by_status` (all `TaskStatus` cases, 0-filled), `overdue_count` | point-in-time |
| `work_logs` | `total_hours` (sum of `duration_minutes` / 60, rounded to 2 dp) | period-based |
| `leave` | `pending_count` (point-in-time), `approved_count_in_period` | mixed |
| `service_reports` | `by_status_in_period` (all `ServiceReportStatus` cases, 0-filled, by `service_date`) | period-based |
| `incident_reports` | `open_count` (point-in-time), `by_severity_in_period` (all `IncidentSeverity` cases, 0-filled, by `occurred_at`) | mixed |
| `schedule` | `horizon` (`from`/`to`), `upcoming_count`, `items` (≤10, reused `ScheduleItemResource` shape) | fixed 7-day horizon |

**Canonical definitions (reused everywhere in this phase, never redefined per-consumer):**
- **Overdue Task** (`App\Support\Reporting\OverdueTasks`): `due_date < today AND status NOT IN (completed, cancelled)`, where "today" is computed in the configured company timezone.
- **Open Incident Report** (`App\Support\Reporting\OpenIncidents`): `status IN (reported, under_investigation)` — explicitly excluding `resolved`/`closed`.

**Work Log framing:** `work_logs.total_hours` (Dashboard) and the Work Logs report's hours-per-row data are operational activity reporting only. This phase deliberately does **not** implement rankings, productivity/utilization scores, or a "top employee" leaderboard of any kind — see DEC-035's own "not... productivity/performance scoring" exclusion, which this phase does not reverse.

**Schedule** reuses `App\Http\Controllers\Api\V1\Scheduling\ScheduleController::index()` verbatim via a synthesized internal request carrying the same authenticated user — the exact, already-tested unified Scheduler visibility (Phase 17, DEC-040) governs this widget; no second calendar aggregation model was built.

**Historical semantics:** every section is a live relational query against current data — no snapshot table exists anywhere in this phase. In particular, Staff/Department/Team/manager relationships reflected in any aggregate are always the *current* relationship; this phase makes no claim about a Staff member's organizational placement at any earlier date (no historical-period tracking exists for that data — see `03_DATABASE_MODEL.md`).

## 4. Reports

Seven resources, each a flat, paginated `GET /api/v1/reports/{resource}` (standard Laravel pagination — `data`/`links`/`meta`, no internal numeric id ever exposed) plus `GET /api/v1/reports/{resource}/export` (CSV, identical filters/authorization):

| Resource | Route | Filters | Visibility source |
|---|---|---|---|
| Staff Directory | `reports/staff` | `department`, `team`, `status` | `can:staff.view` (route middleware — company-wide for any holder, identical to `GET /api/v1/staff`) |
| Work Logs | `reports/work-logs` | `from`, `to`, `staff`, `project`, `task` | `App\Services\Reporting\WorkLogVisibility` |
| Leave Requests | `reports/leave-requests` | `from`, `to`, `staff`, `type`, `status` | `App\Services\Reporting\LeaveRequestVisibility` |
| Projects | `reports/projects` | `client`, `status` | `App\Services\Reporting\ProjectVisibility` |
| Tasks | `reports/tasks` | `project`, `assignee`, `status`, `priority`, `overdue` | `App\Services\Reporting\TaskVisibility` |
| Service Reports | `reports/service-reports` | `from`, `to` (service date), `client`, `project`, `task`, `staff`, `status` | `App\Services\Reporting\ServiceReportVisibility` |
| Incident Reports | `reports/incident-reports` | `from`, `to` (occurrence date), `client`, `project`, `task`, `reporter`, `assigned`, `status`, `severity`, `incident_type` | `App\Services\Reporting\IncidentReportVisibility` |

Projects and Tasks are two separate backend report resources/endpoints (never combined into one), even though a future Reports UI category may group them together visually.

Each JSON report reuses the existing Resource class for that domain (`StaffResource`, `WorkLogResource`, `LeaveRequestResource`, `ProjectResource`, `TaskResource`, `ServiceReportResource`, `IncidentReportResource`) — no separate, incompatible report row format was invented.

## 5. CSV export

`App\Support\Reporting\CsvExport` — shared by all seven `.../export` endpoints:
- Streamed (`response()->streamDownload()`, `fputcsv()` over an Eloquent `cursor()`/`LazyCollection`) — the full result set is never materialized in memory.
- UTF-8 with a leading BOM for Excel-friendly detection.
- Every cell passes through a formula-injection mitigation: a value beginning with `=`, `+`, `-`, `@`, a tab, or a carriage return is prefixed with a single quote before being written, neutralizing it as an Excel/Sheets/LibreOffice formula.
- Fixed, stable column headers per resource (documented in each report controller).
- No internal numeric id in any column.
- No new dependency — no `maatwebsite/excel`, no PDF library. Excel/XLSX, PDF, and printable report generation are explicitly **out of scope** for V1.

## 6. Explicitly out of scope (unchanged from the planning audit)

- Persisted report entities, reporting tables, snapshot tables, a data warehouse, OLAP infrastructure, Elasticsearch, materialized views, denormalized reporting tables.
- Redis, queues, background workers, cron/scheduled aggregation, any caching layer.
- Excel/XLSX export, PDF export, printable reports, chart images rendered server-side.
- A custom report builder, saved reports, scheduled/emailed reports, report subscriptions, CSV imports.
- Employee rankings, performance scoring, productivity scoring, utilization scoring.
- Attendance/timekeeping analytics, absence inference, lateness, missing-check-in reporting, check-in compliance, check-in history reporting, an operational-status dashboard/KPI card — Staff Check-ins remain explicitly not an attendance/timekeeping system (see `05_SECURITY_MODEL.md`'s Location Data section), and this phase does not infer semantics the source module never carried.
- Announcement acknowledgement/read/engagement/compliance-rate reporting — DEC-037's explicit rejection of an "engagement/read-rate dashboard" is not reversed by this phase.
- Notification reporting/analytics — Notifications remain personal inbox data (DEC-038).
- Messaging reporting of any kind — message contents, message counts, conversation analytics, and participant activity are never exposed (DEC-039's Messaging Privacy is preserved exactly).
- Audit Log reporting — DEC-009's general audit-log infrastructure remains unbuilt; this phase does not pull it in, and does not build general project-wide audit logging.
- Master Data management, Application Settings management — both remain future, separately-scoped Administration concerns, per `01_PRODUCT_REQUIREMENTS.md` §1, not part of this phase's roadmap title.
- Flutter Dashboard/Reports UI, Blade/Livewire Admin Dashboard/Reports pages, any Admin Backoffice UI — this phase is API/backend only, consistent with the precedent every phase since Phase 6 has followed.

## 7. Testing

See `docs/testing/TEST_STATUS.md` for the Phase 20 section — automated coverage spans Dashboard role-scoping (Administrator/Manager/Staff/no-role), period defaulting and company-timezone boundary behavior, every canonical definition (overdue Task, open Incident), every report's filters/pagination/no-internal-id shape, CSV export parity with its JSON endpoint (same filters, same authorization, no internal ids, formula-injection neutralization), and a full Phase 1–19 regression run confirming no existing endpoint's behavior changed.
