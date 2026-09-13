# Phase 17 — Scheduler — Handoff

**Date:** 2026-09-12
**Branch:** `claude/phase-17-scheduler` (from `main` @ `7917ee8`, not merged)
**Status:** COMPLETE (pending product-owner review)

## 1. Objective

Implement Company App V1 Phase 17 — Scheduler, per the product-owner-approved planning audit and the 18 numbered decisions it resolved. Build: (1) a unified, read-time aggregation API over four existing/new sources; (2) a lightweight Scheduler-owned `schedule_entries` entity; (3) the minimal Project Milestone concept the Phase 17 roadmap dependency incorrectly assumed Phase 10 had already built.

## 2. Scope Implemented

Everything in the approved decision set was implemented as specified:

- Hybrid Scheduler architecture: `GET /api/v1/schedule` aggregates at read time; no calendar rows copied into a generic table.
- Exactly four sources: manually created Schedule Entries, Task due dates, approved Leave Requests, Project Milestones — tagged with `App\Enums\ScheduleSourceType`.
- `schedule_entries`/`schedule_entry_participants` — manually created activities with a closed `App\Enums\ScheduleEntryActivityType` (`meeting`/`client_visit`/`service_appointment`/`company_event`/`training`/`internal_activity`/`other`), a simple Staff participant list with no RSVP state, optional Project link.
- `project_milestones` — minimal target-date marker (`title`, `due_date`, `App\Enums\ProjectMilestoneStatus`: `pending`/`completed`/`cancelled`).
- Row-level authorization for both new entities — no new permission introduced anywhere.
- Real time-of-day scheduling with a single, configurable company timezone.
- Full CRUD for both entities; relational-integrity protections extending `StaffController`/`ProjectController::destroy`.
- Documentation corrections: the false "Phase 10 (Milestones)" roadmap dependency.

Nothing in the "Explicitly out of scope" list (recurrence, RSVP, reminders/notifications, conflict detection, Department/Team linkage, attachments, external calendar integration, Flutter/Admin UI, Scheduler-specific audit logging, background/queue infrastructure, per-user timezones) was built — confirmed by review of the final diff.

## 3. Implementation Summary — Key Structural Decisions

- **No calendar-row duplication.** `ScheduleController` queries each source's own table directly at request time (date-range + cheap filters at the SQL level, then a per-item, non-aborting visibility predicate in PHP, mirroring that source's own established authorization rule). Verified by `ScheduleAggregationTest::test_updating_the_source_task_is_reflected_live_never_a_stale_copy`.
- **Single UTC datetime pair for both timed and all-day Schedule Entries** (`starts_at`/`ends_at`), normalized via `App\Support\Scheduling\ScheduleEntryTiming` — one shared implementation used by both Form Request validation and controller persistence. All-day boundaries are computed via `App\Support\CompanyTimezone` (`config('scheduling.company_timezone')`, env `SCHEDULING_COMPANY_TIMEZONE`, default `UTC`) — the single place this codebase's one new timezone concept lives.
- **No new permission.** Schedule Entry authorization (`AuthorizesScheduleEntryAccess`) mirrors `AuthorizesTaskAccess`'s exact shape (Administrator via direct role check; creator/participant/Project-visibility for others; creator/Project-Lead/Administrator for writes). Milestone authorization (`AuthorizesMilestoneAccess`) reuses `AuthorizesProjectVisibility` verbatim for reads and the existing `projects.manage` permission (already Administrator-unconditional via `Gate::before`) plus a Project Lead check for writes.
- **Manager does not automatically see a direct report's private Schedule Entries** — a deliberate departure from Work Logs'/Leave's Manager-of-direct-reports scoping, per the approved decision.
- **Aggregation pagination**: fed by an in-memory merge of each source's own small, independently authorized query, then wrapped in a real `Illuminate\Pagination\LengthAwarePaginator` — same `data`/`links`/`meta` response contract as every other endpoint, just constructed manually rather than via a single Eloquent `->paginate()` call. This was a necessary, documented adaptation (see §12 Deviations) — a single portable SQL query across four differently-shaped, differently-secured tables was not feasible.
- **Leave self-visibility in aggregation.** A Staff member always sees their own approved leave in `/schedule`, mirroring their existing `/me/leave-requests` access (not a new grant) — `AuthorizesLeaveRequestVisibility`'s own supervisory rule (Manager-of-direct-reports) is additionally reused for the Manager case.
- **Milestones nested, Schedule Entries flat** — Milestone mirrors Project Membership's genuine nesting (no independent existence apart from its Project); Schedule Entry mirrors Task's flat, top-level shape.

## 4. Files Changed

**New (24 application files + 3 migrations + 2 factories + 3 test files):**
```
apps/api/app/Enums/ProjectMilestoneStatus.php
apps/api/app/Enums/ScheduleEntryActivityType.php
apps/api/app/Enums/ScheduleSourceType.php
apps/api/app/Models/ProjectMilestone.php
apps/api/app/Models/ScheduleEntry.php
apps/api/app/Support/CompanyTimezone.php
apps/api/app/Support/Scheduling/ScheduleEntryTiming.php
apps/api/app/Http/Controllers/Api/V1/Projects/Concerns/AuthorizesMilestoneAccess.php
apps/api/app/Http/Controllers/Api/V1/Projects/ProjectMilestoneController.php
apps/api/app/Http/Controllers/Api/V1/Scheduling/Concerns/AuthorizesScheduleEntryAccess.php
apps/api/app/Http/Controllers/Api/V1/Scheduling/ScheduleEntryController.php
apps/api/app/Http/Controllers/Api/V1/Scheduling/ScheduleController.php
apps/api/app/Http/Requests/Projects/StoreProjectMilestoneRequest.php
apps/api/app/Http/Requests/Projects/UpdateProjectMilestoneRequest.php
apps/api/app/Http/Requests/Scheduling/StoreScheduleEntryRequest.php
apps/api/app/Http/Requests/Scheduling/UpdateScheduleEntryRequest.php
apps/api/app/Http/Resources/ProjectMilestoneResource.php
apps/api/app/Http/Resources/ScheduleEntryResource.php
apps/api/app/Http/Resources/ScheduleItemResource.php
apps/api/config/scheduling.php
apps/api/database/factories/ProjectMilestoneFactory.php
apps/api/database/factories/ScheduleEntryFactory.php
apps/api/database/migrations/2026_09_12_170000_create_project_milestones_table.php
apps/api/database/migrations/2026_09_12_170001_create_schedule_entries_table.php
apps/api/database/migrations/2026_09_12_170002_create_schedule_entry_participants_table.php
apps/api/tests/Feature/Api/V1/Projects/ProjectMilestoneTest.php
apps/api/tests/Feature/Api/V1/Scheduling/ScheduleEntryTest.php
apps/api/tests/Feature/Api/V1/Scheduling/ScheduleAggregationTest.php
docs/phases/V1_PHASE_17_DEFINITION.md
docs/handoffs/V1_PHASE_17_HANDOFF.md
```

**Modified:**
```
apps/api/app/Http/Controllers/Api/V1/Projects/ProjectController.php   (destroy(): +milestones/+scheduleEntries checks)
apps/api/app/Http/Controllers/Api/V1/Staff/StaffController.php        (destroy(): +createdScheduleEntries/+scheduleEntryParticipations checks)
apps/api/app/Models/Project.php                                       (+milestones(), +scheduleEntries() relations)
apps/api/app/Models/Staff.php                                         (+createdScheduleEntries(), +scheduleEntryParticipations() relations)
apps/api/routes/api/v1.php                                            (+schedule, +schedule-entries, +projects/{project}/milestones routes)
docs/02_ARCHITECTURE.md, docs/03_DATABASE_MODEL.md, docs/04_API_CONVENTIONS.md, docs/05_SECURITY_MODEL.md
docs/ROADMAP.md, docs/CURRENT_STATE.md, docs/CHANGELOG.md, docs/DECISIONS.md
```

No `RolePermissionSeeder` change — confirmed no new permission was genuinely required (see §8).

## 5. Database/Schema Changes

Three new tables (33 migrations total, up from 30):

- **`project_milestones`**: `id`, `public_id` (ULID), `project_id` (required, `restrictOnDelete()`), `title`, `due_date`, `status` (default `pending`), timestamps. Index `(project_id, due_date)`.
- **`schedule_entries`**: `id`, `public_id` (ULID), `creator_staff_id` (required, `restrictOnDelete()`), `title`, `description` (nullable), `activity_type`, `starts_at`/`ends_at` (UTC `datetime`), `is_all_day` (default `false`), `project_id` (nullable, `restrictOnDelete()`), timestamps. Indexes on `(starts_at, ends_at)`, `(project_id, starts_at)`, `(creator_staff_id, starts_at)`.
- **`schedule_entry_participants`**: composite-PK pivot (`schedule_entry_id` `cascadeOnDelete()`, `staff_id` `restrictOnDelete()`), no surrogate key.

## 6. API Changes

New endpoints under `/api/v1` (all behind `auth:sanctum` + `account.active`):

| Method | Path | Notes |
|---|---|---|
| `GET` | `/schedule` | Unified aggregation. `from`/`to` required. Filters: `source`, `activity_type`, `project`. |
| `GET` | `/schedule-entries` | Visibility-scoped list. Filters: `project`, `activity_type`, `from`, `to`. |
| `POST` | `/schedule-entries` | Create (requires linked Staff). |
| `GET` | `/schedule-entries/{public_id}` | Show. |
| `PUT`/`PATCH` | `/schedule-entries/{public_id}` | Update (creator/Project-Lead/Administrator). |
| `DELETE` | `/schedule-entries/{public_id}` | Delete (same authority). |
| `GET` | `/projects/{project}/milestones` | List (Project-visibility-scoped). Filter: `status`. |
| `POST` | `/projects/{project}/milestones` | Create (Administrator/Project-Lead). |
| `GET` | `/projects/{project}/milestones/{public_id}` | Show. |
| `PUT`/`PATCH` | `/projects/{project}/milestones/{public_id}` | Update. |
| `DELETE` | `/projects/{project}/milestones/{public_id}` | Delete. |

No response exposes an internal numeric ID anywhere (confirmed by `assertJsonMissingPath('data.id')`/`'data.0.id'` assertions across the new test suites).

## 7. Scheduler Aggregation Architecture

`ScheduleController::index()`:
1. Validates `from`/`to` (required, `to >= from`) and optional `source`/`activity_type`/`project`.
2. Resolves the `project` filter to an internal id (short-circuits to an empty result for an unknown public_id rather than erroring).
3. For each of the four sources not excluded by the `source`/`activity_type`/`project` filters, runs a small, source-scoped, date-range query (cheap SQL-level filters only), then filters the (small) resulting collection in PHP against a non-aborting replica of that source's own established visibility rule, then maps to a normalized array (`source_type`, `public_id`, `title`, `description`, `activity_type`, `status`, `starts_at`, `ends_at`, `is_all_day`, `project`).
4. Merges, sorts by `starts_at`, and paginates the resulting plain-array collection via a manually constructed `LengthAwarePaginator` — same response contract as every other collection endpoint.

Date-only sources (Task due dates, Leave start/end dates, Milestone due dates) are projected using `CompanyTimezone::startOfDayUtc()`/`endOfDayUtc()` — the exact same day-boundary rule an all-day Schedule Entry itself uses — never a fabricated time-of-day.

## 8. Authorization/Security Changes

**No new permission was added.** Verified: `RolePermissionSeeder` unchanged; `php artisan migrate:fresh --seed` runs cleanly; `RolePermissionSeederTest::test_running_it_twice_does_not_duplicate_rows` (asserting a permission count of 23) still passes unmodified.

- `AuthorizesScheduleEntryAccess` (new concern): Administrator (direct `hasRole()` check) sees/manages everything; otherwise visibility is creator-or-participant-or-Project-visible (`AuthorizesProjectVisibility`'s exact rule reused); manage authority is creator/Project-Lead/Administrator; a mere participant is read-only. 404 for no visibility at all (existence sensitive), 403 for visible-but-unauthorized-to-write.
- `AuthorizesMilestoneAccess` (new concern): composes `AuthorizesProjectVisibility` (reads) with `projects.manage`/Project-Lead (writes) — 403 for both no-visibility and no-write-authority cases, matching Project's own existing convention exactly.
- `StaffController::destroy()`/`ProjectController::destroy()` extended with two/two new `409` relational-integrity checks respectively.

## 9. Date/Time and All-Day Design

Single pair of UTC `datetime` columns (`starts_at`/`ends_at`) for both timed and all-day Schedule Entries — no separate date-only column pair. `App\Support\Scheduling\ScheduleEntryTiming::resolve()` is the one shared implementation: for `is_all_day=true`, both inputs must be plain `Y-m-d` dates, normalized to start/end-of-day in the configured company timezone; for `is_all_day=false`, both must be parseable full datetimes. Used identically by the Form Requests (422 on malformed input or `end < start`) and the controller (final persisted Carbon values) — one rule, never two copies. `App\Support\CompanyTimezone` centralizes the single `config('scheduling.company_timezone')` access point (default `UTC`); no per-User/per-Staff timezone field exists.

## 10. Milestone Implementation

`project_milestones`: `project_id` (required), `title`, `due_date`, `status` (`pending`/`completed`/`cancelled` — deliberately smaller than `TaskStatus`, no percent-complete/dependencies/nesting/recurrence/workflow). Nested under its Project (`/projects/{project}/milestones`), mirroring Project Membership. Visibility: current Project members (any role) or `projects.view` holders (Administrator/Manager). Management: Administrator (`projects.manage`) or the Project's own Project Lead.

## 11. Task/Leave/Milestone Aggregation

- **Task:** only tasks with a non-null `due_date` within range; visibility is a self-contained, non-aborting replica of `AuthorizesTaskAccess`'s rule (kept local to `ScheduleController` rather than modifying Tasks' own established trait). No Task data is duplicated or editable through Scheduler endpoints.
- **Leave:** only `status = approved` requests within range (confirmed by `ScheduleAggregationTest::test_non_approved_leave_is_never_aggregated`); visibility is Administrator, the requester themself (mirroring `/me/leave-requests`), or the requester's current direct Manager holding `leave-requests.view`. No Leave data is duplicated; the projection is read-only.
- **Milestone:** all milestones within range, visibility as in §10.

## 12. Tests and Assertions

New: `tests/Feature/Api/V1/Scheduling/ScheduleEntryTest.php` (32 methods, 38 executed cases incl. the 7-way activity-type data provider), `tests/Feature/Api/V1/Scheduling/ScheduleAggregationTest.php` (22 methods), `tests/Feature/Api/V1/Projects/ProjectMilestoneTest.php` (14 methods, 16 executed cases incl. the 3-way status data provider). **76 new tests, isolated run: 76/76 passing, 201 assertions** (`60` in `Scheduling` + `16` in `ProjectMilestoneTest`).

Coverage against the requested checklist: Schedule Entry CRUD ✅; every activity enum value ✅ (data-provider); creator authorization ✅; participant read-only visibility ✅; Project Lead management of Project-linked entries ✅; Administrator management ✅; unrelated Staff privacy (404) ✅; Manager-does-not-auto-see-direct-report-private-entries ✅; participant add/replace on create and update ✅; timed-entry validation (end-before-start rejected, equal accepted) ✅; all-day validation (date-only format enforced, date-order enforced, partial-update-preserves-semantics) ✅; UTC/API date-time representation (timezone-offset input normalized to UTC) ✅; required `from`/`to` ✅; date-range boundaries (inclusive edges, outside-range excluded) ✅; filters (`source`, `activity_type`, `project`) ✅; Project Milestone CRUD/authorization ✅; milestone status validation (each value + invalid rejected) ✅; Task due-date aggregation + visibility preservation ✅; approved-Leave aggregation + non-approved exclusion + Leave privacy preservation (self/Manager/Administrator tiers) ✅; Milestone aggregation + visibility preservation ✅; unified source discriminator ✅; pagination/response shape (`data`/`links`/`meta`, `meta.total`/`meta.per_page`, second-page slicing) ✅; no internal numeric IDs ✅; Staff/Project relational-integrity protections (4 new tests) ✅; suspended-account behavior ✅; full Phase 1–16 regression ✅ (unaffected — see §14).

## 13. Commands/Checks Executed

```
composer validate --strict
vendor/bin/pint --test          (one file auto-fixed via vendor/bin/pint, re-verified clean)
vendor/bin/phpstan analyse      (9 errors found and genuinely fixed — see §16 Deviations — then 0 errors)
php artisan migrate:fresh
php artisan migrate:fresh --seed
php artisan test                (full suite)
php artisan test --filter=Scheduling
php artisan test --filter=ProjectMilestoneTest
```

## 14. Results

- `composer validate --strict` → `./composer.json is valid`
- `vendor/bin/pint --test` → `{"result":"passed"}`
- `vendor/bin/phpstan analyse` (level 5) → `{"result":"passed","errors":0}`
- `php artisan migrate:fresh` → all 33 migrations run cleanly (30 pre-existing + 3 new)
- `php artisan migrate:fresh --seed` → migrations clean; `RolePermissionSeeder` completes with its existing, unmodified permission catalog
- **`php artisan test` (full regression) → `{"result":"passed","tests":707,"passed":707,"assertions":1937}`** — 707 = 631 pre-existing (Phases 1–16) + 76 new Phase 17 tests, all passing, zero regressions
- `php artisan test --filter=Scheduling` → `60/60 passed, 158 assertions`
- `php artisan test --filter=ProjectMilestoneTest` → `16/16 passed, 43 assertions`

## 15. Documentation Updated

`docs/phases/V1_PHASE_17_DEFINITION.md` (new), `docs/handoffs/V1_PHASE_17_HANDOFF.md` (this document, new), `docs/DECISIONS.md` (DEC-040), `docs/02_ARCHITECTURE.md` (new §27), `docs/03_DATABASE_MODEL.md` (Scheduling group resolved, §3 resolved-questions list, Phase 17 resolved paragraph), `docs/04_API_CONVENTIONS.md` (Phase 17 paragraph), `docs/05_SECURITY_MODEL.md` (Authorization/Data-isolation/API-Access bullets, new "Scheduler Privacy" section), `docs/ROADMAP.md` (Phase 17 entry marked complete + corrected dependency line; Phase 10 entry corrected), `docs/CURRENT_STATE.md` (phase pointer, completed-work bullet block, repository/branch info, next-session pointer), `docs/CHANGELOG.md` (new Phase 17 entry).

## 16. Deviations from Specification

1. **PHPUnit data providers required the modern attribute syntax.** PHPUnit 12 (this project's installed version) removed support for the legacy `@dataProvider` docblock annotation; the two data-provider tests (`activityTypeProvider`, `statusProvider`) were written with `#[DataProvider(...)]` attributes instead — a mechanical correction, not a design change, and consistent with using "the smallest fix that makes the test pass," per this project's own established discipline.
2. **`CompanyTimezone` needed an explicit date-string reduction step.** An early draft passed a Carbon/DateTime instance (e.g. a date-cast Eloquent attribute) straight into `Carbon::parse($date, $tz)`; Carbon ignores the `$tz` argument when `$date` is already a `DateTimeInterface`, which would have silently defeated the company-timezone normalization for aggregated date-only sources. Fixed by reducing any Carbon input to its plain `Y-m-d` string first (`CompanyTimezone::dateString()`) before parsing with the target timezone — caught during code review before running tests, not a defect that shipped.
3. **PHPStan level 5 findings, all fixed as genuine corrections, not suppressions** (per PHPStan's own explicit instruction against `@phpstan-ignore`/type-widening/inline `@var` overrides):
   - Four `Collection<int, array<string, mixed>>` return-type docblocks on `ScheduleController`'s private per-source methods were removed — Laravel's `Collection` generic is invariant, so a collection of a more specific array shape (which is what these methods actually, correctly return) doesn't satisfy a declared supertype annotation. Removing the redundant, overly-generic annotation (rather than fabricating a matching literal-shape annotation, or widening anything) let PHPStan infer the accurate type with no conflict.
   - Three unnecessary nullsafe (`?->`) operators on values PHPStan could already prove non-nullable (`Request::user()` after an earlier non-null narrowing; `ScheduleEntry::starts_at`/`ends_at` and `ProjectMilestone::due_date`, both declared as non-nullable `Carbon` in their model docblocks since the underlying columns are required) were changed to plain `->` — PHPStan's own suggested fix, not a suppression.
   - `ScheduleItemResource`'s docblock used `@mixin array<string, mixed>` — an invalid use of `@mixin` (which only applies to object types) to document that its `$this->resource` is a plain array rather than an Eloquent model; removed and left as prose in the class docblock instead.
4. **Aggregation pagination is fed by an in-memory merge, not a single Eloquent query** — flagged explicitly by the approved decision set as something to document rather than silently introduce. See §7/§12 of this document and DEC-040's own Rationale: a genuine multi-source aggregation across four differently-shaped, differently-secured tables cannot be expressed as one portable SQL query without a fragile per-source UNION; each source's own query remains simple, indexed, and independently authorized, and the *response contract* (Laravel's standard `LengthAwarePaginator` → `data`/`links`/`meta`) is unchanged. At ~100 employees and a realistic date-range query, the total row count is small enough that this is not a performance concern — no pagination convention was invented.

No other deviations from the approved decision set were made.

## 17. Known Issues/Limitations

- **No reminders, no Notification integration for Schedule Entries** — by design (deferred; would require cron/queue infrastructure this application deliberately doesn't have, per DEC-039/`02_ARCHITECTURE.md` §5–6).
- **No recurrence** — by design.
- **No conflict/overlap detection** — by design; overlapping Schedule Entries are valid data.
- **Aggregation is an in-memory merge**, not a single SQL query (§16.4) — acceptable at current company scale (~100 employees); would need revisiting only if a future phase demonstrates a genuine performance need at a much larger scale or much wider date ranges than any test exercised here.
- **Audit Logging** (DEC-009) remains an unbuilt, pre-existing, project-wide gap — no Scheduler-specific substitute was created, consistent with every prior phase's posture.
- **No Flutter/Admin Backoffice UI** — by design, consistent with every business phase since Phase 6.
- **Docker validation not performed this session** — no Docker configuration changed by this phase; consistent with most recent phases' sessions (per `docs/testing/TEST_STATUS.md`'s own precedent), the last genuine Docker confirmation remains Phase 5's. All quality gates above were run directly against the host environment.
- **GitHub Actions CI not triggered this session** — no PR opened and no push to `main`, so the path-filtered CI workflow (DEC-015) never fired. All commands the workflow runs were executed directly above and passed.
- **`docs/testing/TEST_STATUS.md` was not updated** — this file's per-phase table stopped being maintained at Phase 14 (Phases 15/16 did not add entries either); this phase follows that same recent precedent rather than resuming a convention two prior phases had already let lapse. Worth flagging to the product owner as a documentation-hygiene item, not something this phase introduced.

## 18. Manual/UAT Testing Instructions

All scenarios below assume a seeded database (`php artisan migrate:fresh --seed`) and an Administrator account (e.g. via `AdminUserSeeder` or Tinker), and use the Sanctum bearer-token flow (`POST /api/v1/auth/login`) exactly as every prior phase's manual testing has.

1. **Schedule Entry CRUD (self-service):** log in as a User with a linked Staff record → `POST /api/v1/schedule-entries` with `title`, `activity_type: "meeting"`, `starts_at`/`ends_at` (ISO-8601 datetimes) → expect `201` with the creator's own Staff identity in `data.creator`. `GET /api/v1/schedule-entries/{public_id}` → `200`. `PUT` a `title` change → `200`. `DELETE` → `204`.
2. **All-day entry:** `POST /api/v1/schedule-entries` with `is_all_day: true`, `starts_at: "2026-12-25"`, `ends_at: "2026-12-25"` (plain dates, no time component) → expect `201`; confirm `data.starts_at`/`data.ends_at` are full ISO-8601 UTC timestamps at the configured company timezone's day boundaries.
3. **Privacy:** as a second Staff-linked User with no relationship to the entry above, `GET /api/v1/schedule-entries/{public_id}` → expect `404` (not `403`).
4. **Project Milestones:** as Administrator, `POST /api/v1/projects/{project_public_id}/milestones` with `title`/`due_date` → `201`, `status: "pending"`. `PATCH` to `status: "completed"` → `200`. As a Staff member with no membership on that Project, `GET /api/v1/projects/{project_public_id}/milestones` → expect `403`.
5. **Unified aggregation:** create a Task with a `due_date`, an approved Leave Request, a Milestone, and a Schedule Entry all falling within the same month → `GET /api/v1/schedule?from=<month-start>&to=<month-end>` as Administrator → expect all four in `data`, each with the correct `source_type`, sorted by `starts_at`. Repeat as an ordinary Staff member with no relationship to any of them → expect an empty `data` array (all four correctly excluded).
6. **Non-approved Leave exclusion:** create a `pending` Leave Request within the queried range → confirm it never appears in `GET /api/v1/schedule`, regardless of caller.
7. **Required date range:** `GET /api/v1/schedule` with no `from`/`to` → expect `422` naming both fields.

No UAT scenario has been recorded as `PASS` by the product owner — per CLAUDE.md §7, this session records readiness for UAT only, never a `PASS` on the product owner's behalf. `docs/testing/UAT_LOG.md` was not modified this session (no existing UAT entries exist to update, and this session does not fabricate one).

## 19. Recommended Next Step

Phase 18 — Service Reports, per `docs/ROADMAP.md` — **not authorized**. Per CLAUDE.md §8 (Stop Discipline), this session stops here and awaits explicit product-owner authorization before beginning any further work, including opening a pull request for this branch.
