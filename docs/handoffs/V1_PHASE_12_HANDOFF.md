# Phase 12 Handoff — Work Logs

**Phase:** 12 — Work Logs
**Date:** 2026-09-12
**Branch:** `claude/focused-johnson-189yg8` (branched from `main`, not merged)
**Depends on:** Phase 11 (Tasks), Phase 10 (Projects & Project Membership), Phase 7 (Staff)

## 1. Objective

Build the foundational Work Logs module on top of Staff/Projects/Project Membership/Tasks: historical records of work performed by Staff, optionally referencing a Task and/or a Project (never neither), with clear performer/creator separation, creation-time-only eligibility, strict historical preservation, and authorization deliberately stricter than Tasks (no Project Lead/Manager write authority). This is explicitly not payroll, attendance, billing, or a full timesheet system.

## 2. Scope Implemented

- `work_logs` table and `App\Models\WorkLog` — `public_id` (ULID), required `staff_id` (performer), nullable `task_id`/`project_id` (at least one required, application-enforced), nullable `created_by_user_id` (accountability), `work_date` (not future), `duration_minutes` (1–1440), `description` (required, ≤2000 chars).
- Task↔Project single source of truth: when `task_id` is supplied, `project_id` is always server-derived from the Task's own `project_id` and a client-supplied `project_id` alongside `task_id` is rejected — inconsistency is structurally impossible, not merely validated.
- Self-service creation eligibility (creation-time only, never re-validated): active Staff; current Project member (any role) for a Project-linked Task or direct Project reference; current assignee for an independent Task. Administrator-entered Work Logs skip this eligibility check entirely.
- Historical preservation: Project Membership removal, Task reassignment, Staff status change, and Task status change never alter or remove an existing Work Log; new logging is not gated by Task status at all.
- `TaskController::destroy`/`ProjectController::destroy`/`StaffController::destroy` (Phases 11/10/7) extended to reject deletion (`409`) while a Work Log references them.
- Editing/deletion: performer may edit (`work_date`/`duration_minutes`/`description` only) or hard-delete their own Work Log, any time; Administrator may correct/delete any Work Log with the same field restriction. `staff_id`/`task_id`/`project_id` are immutable for everyone after creation.
- Two new permissions (`work-logs.view`: Manager-only, scoped to direct reports; `work-logs.manage`: Administrator-only) extend `RolePermissionSeeder`. A Project Lead gets read-only, in-controller-scoped visibility into their led Projects' Work Logs and no write authority.
- Two endpoint families: self-service `/api/v1/me/work-logs` (list/create/edit/delete own) and supervisory `/api/v1/work-logs` (scoped reads; Administrator-only writes).
- `WorkLogFactory`, `WorkLogResource`, `StoreMyWorkLogRequest`/`UpdateMyWorkLogRequest`/`StoreWorkLogRequest`/`UpdateWorkLogRequest`.
- 46 new Work-Log-specific tests (`WorkLogTest`, `WorkLogsAuthorizationTest`) plus 2 small regression additions to `RolePermissionSeederTest`.

## 3. Implementation Summary

**Domain model & parent reference.** `03_DATABASE_MODEL.md` already described the intended shape ("`work_logs` belong to `staff` and optionally to a `task` and/or `project`"). Resolved for V1: both `task_id`/`project_id` are nullable FKs, but **at least one is required** — a Work Log referencing neither would be a generic, un-anchored activity log, explicitly out of scope. When `task_id` is supplied, `project_id` is **always server-derived** from that Task's own `project_id` (itself possibly `null` for an independent Task, per DEC-006) rather than accepted from the client — this makes `Work Log.project = A` while `Work Log.task` belongs to Project B *structurally impossible*, not merely rejected by a validation rule that a future code path could bypass. `project_id` remains a genuinely persisted, indexed column (not purely derived at read time) because a Work Log may also reference a Project directly with no specific Task.

**Performer vs creator.** `staff_id` (required, `restrictOnDelete()`) is the performer; `created_by_user_id` (nullable, `nullOnDelete()`) is accountability-only, mirroring `tasks.created_by_user_id` exactly. Self-service always derives the performer from `$request->user()->staff` (reusing Phase 9's `RequiresLinkedStaff`); only an Administrator may name a different Staff member as performer, via an explicit `staff_id` on the top-level `/api/v1/work-logs` endpoint.

**Eligibility — self-service only, creation-time only.** `App\Http\Requests\WorkLogs\Concerns\ValidatesSelfServiceEligibility` (used only by `StoreMyWorkLogRequest`) checks: the performer's own Staff record is `active`; for a Project-linked Task or a direct Project reference, the performer is a **current Project member in any role** (not only the Task's assignee — any active member may legitimately contribute); for an independent Task, the performer is that Task's **current assignee** (the only linked-Staff visibility route Phase 11 grants for an independent Task). This is checked once, at creation, and never re-validated — the direct mechanism by which historical preservation holds, since no code path ever re-evaluates it. Administrator-entered Work Logs (`StoreWorkLogRequest`) deliberately skip this check entirely — an Administrator is trusted to backfill/correct a record for a Staff member who may since have left a Project or become inactive; only existence and Task/Project consistency are still enforced.

**Historical preservation** was proven, not just designed: `WorkLogTest` includes dedicated tests removing a Project Membership, reassigning a Task, changing a Staff member's employment status, and completing/cancelling a Task, each asserting the existing Work Log (and, for reassignment, its unchanged `staff_id`) survives untouched. New logging is also **not** gated by Task status at all — a completed Task can still receive a legitimate final/retroactive entry.

**Authorization — deliberately stricter than Tasks.** Two permissions: `work-logs.view` (**Manager only**, scoped in-controller to the Manager's own direct reports via `Staff.manager_id` — mirroring Phase 9's `location.view` precedent, not Phase 10/11's company-wide Manager grant, since logged work duration/timing is treated as materially more sensitive than the Staff/Task/Project directories) and `work-logs.manage` (Administrator-only). `App\Http\Controllers\Api\V1\WorkLogs\Concerns\AuthorizesWorkLogVisibility` additionally grants a Project Lead **read-only** visibility into Work Logs referencing a Project they lead (directly, or via one of its Tasks) — and, unlike Phase 11's Task authority, **no** write authority of any kind exists for Project Lead or Manager anywhere in this module. An ordinary Staff member never sees another Staff member's Work Logs merely via shared Project membership — proven by `WorkLogsAuthorizationTest::test_an_ordinary_project_member_cannot_see_another_members_work_log_via_shared_project_membership`.

**Two endpoint families, not one.** `/api/v1/me/work-logs` (self-service: list/create/edit/delete own, performer always server-derived) and `/api/v1/work-logs` (supervisory: `GET`/index/show scoped in-controller with no `can:` middleware — mirroring Phase 10/11's read-scoping pattern; `POST`/`PUT`/`PATCH`/`DELETE` gated by a conventional `can:work-logs.manage` route, Administrator-only). This is the opposite shape from Phase 11's Tasks (there, writes were also scoped in-controller for Project Lead authority; here, Work Logs get no equivalent write carve-out at all, so a plain permission-gated route suffices). A `public_id` that exists but belongs to a different Staff member, requested via `/me/work-logs/{public_id}`, returns `404` (not `403`) — its existence is itself sensitive information.

**A validation-layer correctness fix during quality gates.** The original design called for a declarative `prohibited_with:task_id` rule on `project_id`, but this Laravel version's `Validator` does not implement that rule (only `prohibited`, `prohibited_if`, `prohibited_if_accepted`, `prohibited_if_declined`, `prohibited_unless` exist — confirmed by inspecting `vendor/laravel/framework`). This surfaced immediately as a `BadMethodCallException` (500) in the first quality-gate test run. Fixed by moving the "not both" check into the same custom `after()` validation hook (`ResolvesWorkLogReferences::validateAtLeastOneReference()`) that already enforced "not neither" — no behavior change from what was designed, just a different (correct) implementation mechanism. All governing documentation was updated to describe the actual mechanism, not the originally-assumed rule name.

**A second, self-inflicted correctness check that held.** Laravel's `prohibited` rule (used for `staff_id`/`task_id`/`project_id` immutability on update) treats an explicit client-supplied `null` as "empty," and therefore lets it *pass* validation — meaning a naive `$workLog->update($request->validated())` could have silently nulled out an immutable column if a client sent e.g. `{"task_id": null}` on an edit. Caught during code review (before running tests) and fixed by explicitly whitelisting only `work_date`/`duration_minutes`/`description` when applying an update in both `WorkLogController::update()` and `MyWorkLogController::myUpdate()`, rather than passing the full validated payload through. A dedicated regression test (`test_sending_an_explicit_null_for_an_immutable_field_does_not_clear_it`) proves this.

**Dependency protection.** `TaskController::destroy`, `ProjectController::destroy`, and `StaffController::destroy` each gained one more `409` check (Work-Log-referencing), following the exact existing pattern. The Task check runs **before** the existing `todo`-status check — real work logged against a Task is an even stronger signal than status that history would be lost.

## 4. Files Changed

**Added:**
- `apps/api/database/migrations/2026_09_12_120000_create_work_logs_table.php`
- `apps/api/app/Models/WorkLog.php`
- `apps/api/app/Http/Controllers/Api/V1/WorkLogs/WorkLogController.php`, `MyWorkLogController.php`
- `apps/api/app/Http/Controllers/Api/V1/WorkLogs/Concerns/AuthorizesWorkLogVisibility.php`
- `apps/api/app/Http/Requests/WorkLogs/StoreMyWorkLogRequest.php`, `UpdateMyWorkLogRequest.php`, `StoreWorkLogRequest.php`, `UpdateWorkLogRequest.php`
- `apps/api/app/Http/Requests/WorkLogs/Concerns/ResolvesWorkLogReferences.php`, `ValidatesSelfServiceEligibility.php`
- `apps/api/app/Http/Resources/WorkLogResource.php`
- `apps/api/database/factories/WorkLogFactory.php`
- `apps/api/tests/Feature/Api/V1/WorkLogs/WorkLogTest.php`
- `apps/api/tests/Feature/Authorization/WorkLogsAuthorizationTest.php`
- `docs/phases/V1_PHASE_12_DEFINITION.md`
- `docs/handoffs/V1_PHASE_12_HANDOFF.md` (this file)

**Modified:**
- `apps/api/app/Models/Task.php`, `Project.php`, `Staff.php` — added `workLogs()` relations.
- `apps/api/app/Http/Controllers/Api/V1/Tasks/TaskController.php` — `destroy()` Work-Log-reference check.
- `apps/api/app/Http/Controllers/Api/V1/Projects/ProjectController.php` — `destroy()` Work-Log-reference check.
- `apps/api/app/Http/Controllers/Api/V1/Staff/StaffController.php` — `destroy()` Work-Log-reference check.
- `apps/api/database/seeders/RolePermissionSeeder.php` — `work-logs.view`/`work-logs.manage`.
- `apps/api/routes/api/v1.php` — `/me/work-logs`, `/work-logs` route groups.
- `apps/api/tests/Feature/Authorization/RolePermissionSeederTest.php` — updated permission count (16→18), added `work-logs.view`/Manager-only test.
- `docs/02_ARCHITECTURE.md`, `03_DATABASE_MODEL.md`, `04_API_CONVENTIONS.md`, `05_SECURITY_MODEL.md`, `CURRENT_STATE.md`, `CHANGELOG.md`, `DECISIONS.md`, `ROADMAP.md`.

## 5. Database/Schema Changes

One new migration: `work_logs` (`id`, `public_id` ULID unique, `staff_id` FK→`staff` `restrictOnDelete()`, `task_id` nullable FK→`tasks` `restrictOnDelete()`, `project_id` nullable FK→`projects` `restrictOnDelete()`, `created_by_user_id` nullable FK→`users` `nullOnDelete()`, `work_date` date, `duration_minutes` unsigned int, `description` string(2000), timestamps). Indexes: `(staff_id, work_date)`, `(project_id, work_date)`, `(task_id, work_date)`. No DB-level `CHECK` constraint for "at least one of task_id/project_id" — enforced at the application layer, consistent with this codebase's existing precedent (e.g. Contact's single-primary-per-Client).

## 6. API Changes

```
GET    /api/v1/me/work-logs                self-service list (own only; ?project=/?task=/?from=/?to=)
POST   /api/v1/me/work-logs                self-service create (performer derived from auth)
PUT/PATCH /api/v1/me/work-logs/{public_id} self-edit own (work_date/duration_minutes/description only)
DELETE /api/v1/me/work-logs/{public_id}    self-delete own

GET    /api/v1/work-logs                   scoped (Administrator: all; Manager: direct reports; Project Lead: led-Project logs, view only; ?staff=/?project=/?task=/?from=/?to=)
GET    /api/v1/work-logs/{public_id}       scoped, same rule
POST   /api/v1/work-logs                   work-logs.manage (Administrator-only) — staff_id required
PUT/PATCH /api/v1/work-logs/{public_id}    work-logs.manage (Administrator-only)
DELETE /api/v1/work-logs/{public_id}       work-logs.manage (Administrator-only)
```

`WorkLogResource`: `public_id`, `staff`/`task`/`project`/`created_by` (minimal shapes or `null`), `work_date`, `duration_minutes`, `description`, timestamps — no internal numeric ID anywhere.

## 7. Authorization/Security Changes

- New permissions: `work-logs.view` (Manager only, scoped to direct reports), `work-logs.manage` (Administrator-only).
- `App\Http\Controllers\Api\V1\WorkLogs\Concerns\AuthorizesWorkLogVisibility` — Administrator sees all; Manager scoped to direct reports; Project Lead read-only scoped to led Projects; no other role has access to `/work-logs` (must use `/me/work-logs`).
- No Project Lead or Manager write authority anywhere in this module — deliberately stricter than Phase 11's Task authority.
- Self-service requires no permission — only a linked Staff record (and, for creation, `active` status) — mirroring Phase 9's domain-check pattern.

## 8. Tests Added or Changed

- `WorkLogTest` (33 tests): self-service creation (Project-linked, Task-linked in both Project-linked and independent-Task forms, task/project consistency, eligibility, field validation), historical preservation (4 tests), self-edit/self-delete (including the immutable-field-null regression test), Administrator management, deletion protection (Task/Project/Staff), filters.
- `WorkLogsAuthorizationTest` (13 tests): Administrator, Manager (direct-report-scoped, both positive and negative), Project Lead (led/not-led, view-only), ordinary Project Member privacy, no-permission/no-lead-role denial, unauthenticated, suspended account.
- `RolePermissionSeederTest`: updated permission count (16→18) and added a `work-logs.view` Manager-only/not-Staff test, mirroring `tasks.view`'s existing test.
- Full Phase 1–11 regression suite verified passing unmodified in behavior (aside from the necessary count update above).

## 9. Commands/Checks Executed

```
composer install --no-interaction --prefer-dist --no-progress
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan migrate:fresh --force
php artisan db:seed --class=Database\Seeders\RolePermissionSeeder --force
php artisan test
```

Plus a real end-to-end HTTP smoke test: `php artisan serve`, seeded an Administrator (`AdminUserSeeder`) and a linked Staff/Project/Membership via Tinker, then exercised the actual running server with `curl` — login, `GET /api/v1/auth/me`, self-service `POST`/`GET /api/v1/me/work-logs` (created and listed a real Work Log end-to-end), Administrator `GET /api/v1/work-logs` (saw it), and confirmed the plain-Staff 403 boundary on `GET`/`POST /api/v1/work-logs`.

## 10. Results

- `composer validate --strict`: **valid**.
- `vendor/bin/pint --test`: **passed** (no style violations).
- `vendor/bin/phpstan analyse` (level 5): **0 errors** (one real nullsafe-on-non-nullable-type finding on first run, in `WorkLogResource`, fixed).
- `php artisan migrate:fresh --force`: all 21 migrations run cleanly, including the new `work_logs` table.
- `php artisan db:seed --class=RolePermissionSeeder`: runs cleanly, produces the expected 18 permissions including `work-logs.view`/`work-logs.manage`.
- `php artisan test`: **370/370 passing, 1053 assertions** (full suite — Phases 1–12).
- Real HTTP smoke test: all requests behaved as designed (self-service create/list succeeded; Administrator saw the same record via the supervisory endpoint; plain-Staff access to `/work-logs` correctly returned `403`).

## 11. Deviations from Specification

- The original design (per this phase's own `V1_PHASE_12_DEFINITION.md`, written before implementation) called for a declarative `prohibited_with:task_id` validation rule to reject a client-supplied `project_id` alongside a `task_id`. This Laravel version's `Validator` does not implement `prohibited_with` (confirmed by inspecting `vendor/laravel/framework/src/Illuminate/Validation/Concerns/ValidatesAttributes.php` — only `prohibited`, `prohibited_if`, `prohibited_if_accepted`, `prohibited_if_declined`, `prohibited_unless` exist), which surfaced as a `BadMethodCallException` (500 response) on the very first test run. Fixed by enforcing the same rule in the existing custom `after()` validation hook instead — no behavioral change, only a different (correct) implementation mechanism. All governing documentation (`02_ARCHITECTURE.md`, `DECISIONS.md`, the phase definition) was corrected to describe the actual mechanism.
- No other deviation from the phase definition.

## 12. Known Issues/Limitations

- A Manager who is *also* a Project Lead of some Project sees the union of their direct reports' Work Logs and their led Projects' Work Logs (the visibility-scoping query combines both conditions with `OR`) — this was a deliberate design choice during implementation, not left ambiguous, but is called out here since it's a genuine edge case not explicitly specified in the governing instructions.
- No pagination/filter for "own Work Logs across multiple Projects at once with a Project-name search" (`?q=`) was added — not requested, and filtering by `?project=`/`?task=`/`?from=`/`?to=` was judged sufficient at V1/~100-employee scale.
- As with every prior module, there is no Admin Backoffice (Blade/Livewire) CRUD UI and no Flutter mobile screens — API/backend only.

## 13. Manual/UAT Testing Instructions

1. Seed the database: `php artisan migrate:fresh --seed` (uses `RolePermissionSeeder`; add `AdminUserSeeder` separately for a local Administrator login, `admin@example.test` / `password`).
2. Log in as Administrator via `POST /api/v1/auth/login`, then create a Staff record linked to a second User, a Project, and a Project Membership for that Staff member (e.g. via Tinker, since there is no Admin UI yet).
3. Log in as the linked Staff User and `POST /api/v1/me/work-logs` with a `project_id` (or a `task_id` for a Task within that Project) — confirm `201` and the resource shape (no internal numeric IDs).
4. `GET /api/v1/me/work-logs` as that same user — confirm the entry appears.
5. As Administrator, `GET /api/v1/work-logs` — confirm the same entry is visible; `PUT` it to correct `duration_minutes`; `DELETE` it.
6. As a Manager (with the Staff member as a direct report via `manager_id`), confirm `GET /api/v1/work-logs` shows the report's logs; as an unrelated Manager, confirm it does not.
7. Remove the Staff member's Project Membership, then re-fetch the Work Log by `public_id` (as Administrator) — confirm it is untouched.
8. Attempt to delete the Task/Project/Staff record referenced by a Work Log — confirm each is blocked with `409`.

No UAT `PASS` is recorded here — per CLAUDE.md §7, only the product owner may record that in `docs/testing/UAT_LOG.md`.

## 14. Documentation Updated

`docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/ROADMAP.md`, `docs/02_ARCHITECTURE.md` (new §22), `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/DECISIONS.md` (new DEC-035), `docs/phases/V1_PHASE_12_DEFINITION.md`, this handoff.

## 15. Recommended Next Step

Per `docs/ROADMAP.md`, the next planned phase is **Phase 13 — Leave Management** (leave types, requests, approvals with history, balances) — not authorized to begin without explicit product-owner direction.
