# Phase 20 — Admin Dashboard & Reporting — Handoff

## 1. Phase Identification

- **Phase:** 20 — Admin Dashboard & Reporting
- **Date:** 2026-09-13
- **Branch:** `claude/phase-20-admin-dashboard-reporting` (branched from `main`, pushed to `origin`, not merged, no PR opened)

## 2. Objective

Implement Phase 20 per the product owner's explicit, detailed decisions following a preceding planning audit (delivered as a chat message ahead of this phase): two read-only API surfaces — a cross-module Admin Dashboard and seven administrative Reports with CSV export — governed by a strict rule that aggregation must never widen the visibility any source module's own endpoint already grants a requester. No pull request, merge, or Phase 21 work was authorized as part of this objective.

## 3. Scope Implemented

- `GET /api/v1/dashboard` — a single JSON aggregation endpoint with sections for People, Clients, Projects, Tasks, Work Logs, Leave, Service Reports, Incident Reports, and Schedule, exactly as specified.
- Seven Reports resources, each `GET /api/v1/reports/{resource}` (paginated JSON) plus `GET /api/v1/reports/{resource}/export` (CSV): Staff Directory, Work Logs, Leave Requests, Projects, Tasks, Service Reports, Incident Reports.
- The governing "never widen source visibility" authorization rule, implemented via eight new `App\Services\Reporting\*Visibility` classes, one per source module.
- The canonical "overdue Task" and "open Incident Report" definitions, each in its own reusable helper class, used by both the Dashboard and the relevant Reports.
- Dashboard date semantics (`App\Support\Reporting\ReportPeriod`): optional `from`/`to`, defaulting period-based metrics to the current calendar month in the configured company timezone; point-in-time metrics unaffected; a fixed 7-day Schedule horizon independent of the Dashboard's own period.
- CSV export infrastructure (`App\Support\Reporting\CsvExport`) with streaming, UTF-8 BOM, stable headers, no internal ids, and formula-injection mitigation, shared by all seven report exports.
- Full automated test coverage (80 new tests) and a full Phase 1–19 regression run (1000 tests total, all passing).
- Documentation: `docs/DECISIONS.md` (DEC-043), this handoff, `docs/phases/V1_PHASE_20_DEFINITION.md`, and updates to `docs/ROADMAP.md`, `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`.

Not implemented, per the product owner's explicit exclusions (§4/§12–§20/§22 of the governing instructions): check-in/attendance reporting of any kind; Announcement/Notification/Messaging reporting; Audit Log reporting; Master Data/Application Settings management; Excel/PDF/printable export; a custom report builder, saved/scheduled/emailed reports, or CSV imports; employee rankings/performance/productivity/utilization scoring; any Flutter, Blade/Livewire, or other Admin Backoffice UI; any caching/queue/materialized-view/snapshot-table/data-warehouse infrastructure; any new database index (none was justified by the queries this phase actually runs).

## 4. Implementation Summary

**Architecture.** Two read-only API surfaces, zero schema change. Dashboard is a single controller (`DashboardController`) composing eight small, focused section methods. Reports are seven small, parallel controllers (one per resource, matching this codebase's established one-controller-per-resource convention), each with an `index()` (paginated JSON, reusing the domain's existing API Resource class) and an `export()` (CSV) sharing one private `filteredQuery()` method — so the JSON and CSV paths can never drift out of sync on filters or visibility.

**Visibility reuse, not duplication.** Eight new `App\Services\Reporting\*Visibility` classes (`StaffVisibility`, `ClientVisibility`, `ProjectVisibility`, `TaskVisibility`, `WorkLogVisibility`, `LeaveRequestVisibility`, `ServiceReportVisibility`, `IncidentReportVisibility`) each `use` their source module's own existing authorization trait (`AuthorizesProjectVisibility`, `AuthorizesTaskAccess`, `AuthorizesWorkLogVisibility`, `AuthorizesLeaveRequestVisibility`, `AuthorizesServiceReportAccess`, `AuthorizesIncidentReportAccess`) to reuse its pure, non-aborting predicates (`isAdministrator`, `canViewAllProjects`, `canViewAllTasks`, `canViewAsManager`, `isCreator`, `isDirectManagerOf...`, `isProjectLeadOf`, `ledProjectIds`, ...). Where a source trait's own visibility method is built to `abort()` for its single-purpose endpoint, the matching Reporting service reproduces that trait's exact composed WHERE clause as a query, adapted to degrade to an empty result instead of aborting — precisely the pattern `ScheduleController` already established in Phase 17 for exactly this situation. **No existing Phase 7–19 controller or trait file was modified.** `WorkLogVisibility`/`LeaveRequestVisibility` each add one narrow, documented fallback (a plain Staff member sees their own records, mirroring `/me/work-logs`/`/me/leave-requests`) since those two source modules' supervisory-only rules would otherwise silently show "0" to someone who does have visibility into their own data through a different existing route.

**Canonical definitions.** `App\Support\Reporting\OverdueTasks` (`due_date < today` in the company timezone, status not Completed/Cancelled) and `App\Support\Reporting\OpenIncidents` (`status IN (reported, under_investigation)`) are each a single, small, reused class — never redefined per-consumer.

**Date semantics.** `App\Support\Reporting\ReportPeriod::resolve()` computes `fromDate`/`toDate` (plain company-timezone calendar dates, for DATE-typed columns) and `utcFrom()`/`utcTo()` (true UTC instants, via the existing `CompanyTimezone::startOfDayUtc()`/`endOfDayUtc()`, for Incident Reports' genuine `occurred_at` datetime column) — reusing `App\Support\CompanyTimezone` exactly as-is, no modification.

**Schedule reuse.** `DashboardController::schedule()` builds a synthesized internal `Request` (`from=now`, `to=now+7d`, carrying the *same* authenticated user via `setUserResolver()`) and calls `app(ScheduleController::class)->index($scheduleRequest)` directly, then reads the standard paginated response shape via `->response($request)->getData(true)`. This reuses 100% of the Scheduler's already-tested unified visibility (Phase 17, DEC-040) — no second calendar aggregation model was written.

## 5. Files Changed

**New:**
- `apps/api/app/Support/Reporting/PublicIdResolver.php`, `ReportPeriod.php`, `OverdueTasks.php`, `OpenIncidents.php`, `EnumStatusCounts.php`, `CsvExport.php`
- `apps/api/app/Services/Reporting/StaffVisibility.php`, `ClientVisibility.php`, `ProjectVisibility.php`, `TaskVisibility.php`, `WorkLogVisibility.php`, `LeaveRequestVisibility.php`, `ServiceReportVisibility.php`, `IncidentReportVisibility.php`
- `apps/api/app/Http/Controllers/Api/V1/Dashboard/DashboardController.php`
- `apps/api/app/Http/Controllers/Api/V1/Reports/StaffDirectoryReportController.php`, `WorkLogReportController.php`, `LeaveRequestReportController.php`, `ProjectReportController.php`, `TaskReportController.php`, `ServiceReportReportController.php`, `IncidentReportReportController.php`
- `apps/api/tests/Feature/Api/V1/Dashboard/DashboardTest.php`
- `apps/api/tests/Feature/Api/V1/Reports/StaffDirectoryReportTest.php`, `WorkLogReportTest.php`, `LeaveRequestReportTest.php`, `ProjectReportTest.php`, `TaskReportTest.php`, `ServiceReportReportTest.php`, `IncidentReportReportTest.php`
- `docs/phases/V1_PHASE_20_DEFINITION.md`, `docs/handoffs/V1_PHASE_20_HANDOFF.md` (this file)

**Modified:**
- `apps/api/routes/api/v1.php` (new routes, imports)
- `docs/DECISIONS.md` (DEC-043), `docs/ROADMAP.md`, `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/02_ARCHITECTURE.md` (§30), `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`

No file under `apps/api/database/migrations/`, no `app/Models/`, no existing `app/Http/Controllers/Api/V1/{Projects,Tasks,WorkLogs,Leave,ServiceReports,IncidentReports}/**` file, and no existing `app/Http/Controllers/Api/V1/*/Concerns/Authorizes*.php` trait was touched.

## 6. Database/Schema Changes

**None.** `php artisan migrate:fresh` runs the same 44 migrations Phase 19 left in place. Every Dashboard/Report query runs against the existing schema's existing indexes (see `docs/03_DATABASE_MODEL.md`'s new Phase 20 entry for the full list reused). No new index was added — none was justified by the queries this phase actually exercises at the target ~100-employee scale.

## 7. API Changes

New endpoints under `/api/v1`, all behind `auth:sanctum` + `account.active`:

- `GET /dashboard` — no permission gate; every section is scoped to the requester's own visibility.
- `GET /reports/staff` (+ `/export`) — gated by `can:staff.view` (route middleware), mirroring `GET /api/v1/staff` exactly.
- `GET /reports/work-logs` (+ `/export`), `GET /reports/leave-requests` (+ `/export`), `GET /reports/projects` (+ `/export`), `GET /reports/tasks` (+ `/export`), `GET /reports/service-reports` (+ `/export`), `GET /reports/incident-reports` (+ `/export`) — no `can:<permission>` route middleware; visibility resolved in-controller via the matching `*Visibility` service.

Filters per resource, response shapes, and CSV column headers are documented in full in `docs/phases/V1_PHASE_20_DEFINITION.md` §3/§4/§5.

## 8. Authorization/Security Changes

**No new permission was introduced.** The `RolePermissionSeeder` permission catalog is byte-for-byte unchanged from Phase 19 (confirmed: `php artisan migrate:fresh --seed` output unchanged, and the full regression suite's `RolePermissionSeederTest` — which hardcodes the total permission count — passed unmodified). Authorization for every Dashboard section and every Report resource is resolved by reusing an existing source module's own authorization trait via the new `App\Services\Reporting\*Visibility` classes (see §4 above and DEC-043) — this is the entire authorization-relevant change in this phase. Nothing about any existing endpoint's authorization behavior changed.

## 9. Tests Added or Changed

- `DashboardTest` — 27 tests covering: authentication requirement; period defaulting to the current calendar month and company-timezone boundary behavior (tested against `Pacific/Kiritimati`, UTC+14, to catch a naive UTC-only implementation); explicit `from`/`to` and `to < from` rejection; point-in-time vs. period-based metric independence; Administrator/Manager/Staff/no-role scoping for People, Clients, Projects, Tasks, Work Logs, Leave, Service Reports, and Incident Reports; the canonical overdue-Task and open-Incident-Report definitions (including the company-timezone-boundary case and the point-in-time-vs-period-filtered distinction for `open_count`); a Project Lead's explicit *non*-visibility into Incident Reports; and the upcoming-Schedule widget's reuse of the Scheduler's own visibility and fixed 7-day horizon.
- `StaffDirectoryReportTest`, `WorkLogReportTest`, `LeaveRequestReportTest`, `ProjectReportTest`, `TaskReportTest`, `ServiceReportReportTest`, `IncidentReportReportTest` — 8/8/8/7/7/8/7 tests each covering authentication, role/visibility scoping (including a stranger-sees-nothing case for Service/Incident Reports, and the Incident Reports/Service Reports Project-Lead-visibility contrast), every documented filter, standard pagination shape with no internal numeric id, and CSV export (successful download, same filters/authorization as JSON, no internal ids, stable headers, formula-injection neutralization).
- 80 new tests total; full suite (including all pre-existing Phase 1–19 tests) is 1000 tests, all passing.

## 10. Commands/Checks Executed

```sh
composer install --no-interaction --prefer-dist --no-progress
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan migrate:fresh
php artisan migrate:fresh --seed
php artisan test --filter="DashboardTest|StaffDirectoryReportTest|WorkLogReportTest|LeaveRequestReportTest|ProjectReportTest|TaskReportTest|ServiceReportReportTest|IncidentReportReportTest"
php artisan test
```

## 11. Results

- `composer install`: completed successfully as a background command (`EXIT_CODE=0`) — see §13 for the environment note (the recurring `phpstan/phpstan` git-mirror-clone delay documented since Phase 6/9).
- `composer validate --strict`: `./composer.json is valid`.
- `vendor/bin/pint --test`: initially found 4 files needing auto-fixable style corrections (import ordering, brace style, quote style) in newly written code; `vendor/bin/pint` applied them; re-run: `{"tool":"pint","result":"passed"}`.
- `vendor/bin/phpstan analyse`: initially found 4 real errors — `App\Support\Reporting\EnumStatusCounts::forColumn()`'s `Builder<Model>` parameter rejected callers passing `Builder<Project>`/`Builder<Task>`/`Builder<ServiceReport>`/`Builder<IncidentReport>` (PHPStan's `Builder` template is not covariant). Fixed by making the method itself generic (`@template TModel of Model`, `@param Builder<TModel> $query`) rather than widening the type or suppressing the error. Re-run: `{"tool":"phpstan","result":"passed","errors":0}`.
- `php artisan migrate:fresh`: all 44 migrations ran cleanly — zero new migrations.
- `php artisan migrate:fresh --seed`: `RolePermissionSeeder` ran cleanly, permission catalog unchanged.
- Isolated Phase 20 test run: initially 72/79 passing, 7 failures — every one a test-assertion defect, not an application bug (see §12 for the full account: two `assertJsonPath` strict-float-identity mismatches, one test with an incorrect point-in-time expectation, three CSV-header assertions that assumed unquoted fields, one `assertSee()`-on-a-`StreamedResponse` misuse). All seven fixed; re-run: `{"tool":"phpunit","result":"passed","tests":80,"passed":80,"assertions":278}`.
- `vendor/bin/pint --test` and `vendor/bin/phpstan analyse` re-confirmed clean after the test fixes.
- Full suite: `{"tool":"phpunit","result":"passed","tests":1000,"passed":1000,"assertions":2658}` — 920 pre-existing (Phase 1–19) + 80 new = 1000, confirming zero regression.

## 12. Deviations from Specification

None in the implemented application code — every architectural decision in this handoff traces directly to the governing instructions (two API surfaces, the "never widen" rule, the canonical overdue/open definitions, the date semantics, the seven report resources and their filters, CSV-only export with formula-injection mitigation, no schema change, no new permission, API-only scope).

Two categories of issue were found and fixed **entirely within this phase's own newly written test code**, never in application code:
1. **Test-assertion defects** (§11): a strict-float-identity comparison that didn't account for PHP's `json_encode()` rendering a whole-number float without a fractional part; one test whose seeded data contradicted the Dashboard's own documented point-in-time semantics for `incident_reports.open_count` (the *test's* expectation was wrong — the implementation was already correct, and a new test was added specifically to prove the correct point-in-time behavior explicitly); CSV header assertions that assumed unquoted fields when PHP's `fputcsv()` quotes any field containing a space; and one use of `assertSee()` against a `StreamedResponse`, which Laravel's test helpers don't support (`streamedContent()` exists for exactly this reason).
2. **A real PHPStan generic-covariance issue** (§11) — the only defect in this phase's actual application code, in a brand-new helper class, fixed by making that helper's method generic rather than by widening or suppressing.

No scope was silently expanded or narrowed relative to the governing instructions.

## 13. Known Issues/Limitations

- **This session's environment:** the container started with no `vendor/` at all. `composer install` ran as a background command; `phpstan/phpstan`'s own internal `git clone --mirror` again took several minutes to resolve (the same recurring `api.github.com` zipball-access limitation documented since Phase 6 — every dependency fell back to a per-package Git-source clone), but completed within the extended `COMPOSER_PROCESS_TIMEOUT=1800` with no manual `vendor/` file surgery needed. This does not affect the correctness of what was committed (`vendor/` is never committed either way).
- **Docker/GitHub Actions CI:** not re-verified this session (no Docker configuration changed; no PR opened, so the path-filtered CI workflow never triggered) — consistent with every phase since Phase 6's precedent of not re-running Docker verification when no Docker-relevant file changed.
- **No UI exists for this phase** (by design — see §3/§18 of the governing instructions) — Dashboard/Reports are reachable only via direct API calls (e.g. curl/Postman with a Sanctum bearer token) until a future UI phase surfaces them visually.
- **Work Log/Leave "own records" fallback is a documented, deliberate design choice**, not an oversight — see DEC-043 and `App\Services\Reporting\WorkLogVisibility`/`LeaveRequestVisibility`'s own docblocks for the full reasoning (a plain Staff member's own records remain visible in Dashboard/Reports because they're already visible via `/me/work-logs`/`/me/leave-requests` — omitting this fallback would have made those two sections silently show "0" for a large fraction of requesters despite those requesters genuinely having *some* visibility into the underlying data).

## 14. Manual/UAT Testing Instructions

No Admin Backoffice UI or Flutter mobile screens exist for this phase — verification is via direct API calls against a running instance (Docker or direct install), using a Sanctum bearer token obtained via `POST /api/v1/auth/login`:

1. **Dashboard, Administrator:** `GET /api/v1/dashboard` (and with `?from=2026-01-01&to=2026-01-31`) — confirm every section reflects real seeded data, and that `period.source` reads `"default_current_month"` when `from`/`to` are omitted and `"explicit"` when supplied.
2. **Dashboard, Manager/Staff scoping:** repeat as a Manager and as an ordinary Staff-linked user; confirm each section's figures match exactly what that same user already sees by calling the corresponding source endpoint directly (e.g. `GET /api/v1/work-logs` vs. `data.work_logs.total_hours`) — never a company-wide default.
3. **Reports:** `GET /api/v1/reports/{staff,work-logs,leave-requests,projects,tasks,service-reports,incident-reports}` with various filter combinations documented in `docs/phases/V1_PHASE_20_DEFINITION.md` §4 — confirm pagination (`data`/`links`/`meta`) and no `id` field anywhere in `data`.
4. **CSV export:** `GET /api/v1/reports/{resource}/export` for each of the seven resources — download the file and open it in a real spreadsheet application (Excel/Google Sheets/LibreOffice) to confirm it opens cleanly with correct headers, readable UTF-8 text, and no internal numeric ids.
5. **Incident Report privacy:** as a Staff member who leads a Project linked to an Incident Report but has no other relationship to it, confirm `GET /api/v1/reports/incident-reports` returns zero rows for that incident and its CSV export is header-only — contrast with the same scenario for a Service Report, where that same Project Lead *should* see it.

See `docs/testing/UAT_LOG.md` (`UAT-20-01` through `UAT-20-06`) for the corresponding tracked scenarios — all recorded `NOT RUN` per CLAUDE.md §7; only the product owner may record a `PASS`.

## 15. Documentation Updated

`docs/DECISIONS.md` (DEC-043), `docs/phases/V1_PHASE_20_DEFINITION.md` (new), `docs/handoffs/V1_PHASE_20_HANDOFF.md` (this file, new), `docs/ROADMAP.md`, `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/02_ARCHITECTURE.md` (new §30), `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`.

## 16. Recommended Next Step

Per `docs/ROADMAP.md`, **Phase 21 — Integration Audit** (cross-module consistency review) is next. This is a recommendation only — per CLAUDE.md §8 Stop Discipline, Phase 21 must not begin without explicit product-owner authorization, and this session has not begun it.
