# Phase 11 Handoff — Tasks

**Phase:** 11 — Tasks
**Date:** 2026-09-12
**Branch:** `claude/nice-fermi-c8irr2` (branched from `main`, not merged)
**Depends on:** Phase 10 (Projects & Project Membership)

## 1. Objective

Build the foundational Tasks module on top of Projects & Project Membership (Phase 10): a clean, small Task model with a practical lifecycle, clear Project ownership (including the independent-Task case DEC-006 requires), Staff assignment governed by explicit eligibility rules, Project-Lead/assignee-scoped authority, and visibility that never exceeds the equivalent Project's own visibility. Work Logs, task activity/comments/collaboration, notifications, and reporting are explicitly deferred to later phases — this phase builds only the foundation those will reference.

## 2. Scope Implemented

- `tasks` table and `App\Models\Task` — `public_id` (ULID), nullable `project_id`, `title`, `description`, `status` (`App\Enums\TaskStatus`), `priority` (`App\Enums\TaskPriority`), nullable `assignee_staff_id`, nullable `created_by_user_id`, nullable `due_date`, nullable server-controlled `completed_at`, timestamps.
- Honors DEC-006 (Phase 0): a Task may exist independently of a Project. `project_id` is nullable and `restrictOnDelete()`.
- Single nullable assignee (`assignee_staff_id`), not a `task_assignees` pivot — resolves `03_DATABASE_MODEL.md`'s open question for V1.
- New-assignment eligibility: active Staff only, and — for a Project-linked Task — a current member of that Project. Enforced server-side in Form Requests, never client-side.
- Removing a Project Membership never disturbs an existing Task assignment (assignment references `staff_id` directly, not through Membership) — assignment/business history is preserved.
- `completed_at` is server-controlled only: set on transition to `completed`, cleared on reopening.
- Two new permissions (`tasks.view`: Manager-only; `tasks.manage`: Administrator-only) extend `RolePermissionSeeder`.
- Project Lead scoped Task-management authority and assignee status-only self-service, both enforced in-controller (`App\Http\Controllers\Api\V1\Tasks\Concerns\AuthorizesTaskAccess`) — the first time this codebase scopes write authority (not just reads) outside the permission system.
- Flat, top-level `/api/v1/tasks` REST resource (not nested under `/projects/{project}/tasks`), filterable by `project`/`status`/`assignee`/`priority`/`q`.
- `ProjectController::destroy`/`StaffController::destroy` extended with Task-reference delete-protection (`409`).
- A narrow, Administrator-only hard-delete rule (only while a Task is still `todo`); anything with real activity is cancelled via ordinary status update.
- `TaskFactory`, `TaskResource`, `StoreTaskRequest`/`UpdateTaskRequest`.
- 49 new Task-specific tests (`TaskTest`, `TasksAuthorizationTest`) plus 5 small regression additions (`RolePermissionSeederTest`, `ProjectTest`, `StaffTest`).

## 3. Implementation Summary

**Domain model.** `Task` mirrors `Project`'s established shape (ULID `public_id` set in `booted()`, `getRouteKeyName()`, enum casts) but with a nullable `project_id` throughout, per DEC-006. Rather than a `task_assignees` many-to-many pivot (which `03_DATABASE_MODEL.md` flagged as an open question), a single nullable `assignee_staff_id` was chosen — no demonstrated V1 requirement for multiple simultaneous assignees, and a pivot would multiply eligibility/removal-semantics complexity without one. `created_by_user_id` is accountability metadata only, `nullOnDelete()` (mirrors `staff_statuses.changed_by_user_id`, Phase 9), never an authorization primitive beyond Task creation.

**Lifecycle.** `App\Enums\TaskStatus` (`todo`/`in_progress`/`blocked`/`completed`/`cancelled`) has no restricted transition graph — any status may move to any other via ordinary update, keeping the lifecycle simple per the governing instructions' explicit warning against workflow engines. `completed_at` is deliberately **server-controlled and re-derived**, not user-supplied and preserved like Project's `completed_date`: it's set to `now()` whenever status transitions to `completed` (creation or update) and cleared when a completed Task is reopened, keeping the invariant "`completed_at` is non-null iff status is `completed`" simple and testable.

**Assignment & eligibility.** `StoreTaskRequest`/`UpdateTaskRequest` each resolve the submitted `assignee_staff_id` public ID locally (mirroring `StoreProjectMembershipRequest`'s pattern) and validate, in an `after()` hook: (1) the target Staff member's employment status is `active` (Phase 7's `StaffStatus`) — new assignment only, an existing assignment is never re-validated; (2) when the Task belongs to a Project, the target Staff member currently holds a Project Membership on that Project (any role) — an independent Task has no such check. `UpdateTaskRequest` treats an explicit `assignee_staff_id: null` as "unassign," skipping eligibility entirely (`$this->filled()` returns `false` for `null`).

**Membership removal is a non-event for Task assignment**, by design: `Task.assignee_staff_id`'s foreign key targets `staff` directly, never `project_memberships`. `ProjectMembershipController::destroy` (Phase 10, unmodified) has no knowledge of Tasks at all — the smallest safe rule (preserve history unconditionally) rather than a more complex "block removal while open Tasks are assigned" or "auto-unassign" rule. A regression test (`TaskTest::test_removing_a_project_membership_does_not_disturb_an_existing_task_assignment`) proves this end-to-end.

**Authorization — the phase's central design decision.** Two permissions only: `tasks.view` (Manager, mirroring `projects.view`'s "not company-wide, may carry client-engagement sensitivity" reasoning) and `tasks.manage` (Administrator-only, unrestricted). Everything else is row-level, inside `App\Http\Controllers\Api\V1\Tasks\Concerns\AuthorizesTaskAccess`:
- `authorizeView()` — `tasks.view` holders see everything; otherwise a linked-Staff requester may view a Task if they're a member of its Project (any role) or its direct assignee; anyone else (including a no-Staff-linked user) gets `403`.
- `authorizeCreate()` — `tasks.manage` holders, or a Project Lead creating within a Project they lead; anyone else gets `403`. An independent (project-less) Task is Administrator-only — there's no Project to be lead of.
- `authorizeUpdate()` — returns `true` (full field access) for `tasks.manage`/Project-Lead-of-this-Project, `false` (status-only self-service) for the Task's own assignee, and aborts `403` otherwise. `TaskController::update()` then diffs the validated payload's keys against `['status']` when the return is `false`, rejecting (`403`) if anything else was submitted — even alongside a valid `status` change, so a self-service request can never smuggle another field through.

This is the first time write authority (not just reads, as in Phase 9/10) is scoped entirely outside route middleware in this codebase — `POST`/`PUT`/`PATCH /api/v1/tasks` carry no `can:<permission>` middleware at all. `DELETE` remains a conventional `can:tasks.manage` route (Administrator-only in practice, since only Administrator ever holds `tasks.manage`), further narrowed in-controller to Tasks still `todo`.

**API shape.** `/api/v1/tasks` is flat and top-level, not nested under `/projects/{project}/tasks` — a deliberate deviation from Project Membership's genuinely-nested precedent, justified because a Task is not inherently owned by a Project (DEC-006), matching the "prefer a top-level filterable resource... when a resource is more independent than owned" guidance `04_API_CONVENTIONS.md` already documents from Contact→Client (Phase 8). This also sidesteps Phase 10's two-consecutive-Eloquent-parameter nested route-model-binding bug entirely, since `/api/v1/tasks/{task:public_id}` never has a second bound parameter.

**Delete behavior.** A genuine hard `DELETE` is allowed only while `status === 'todo'` — nothing has happened yet, so there's no history to lose. Anything further along returns `409` and must instead be retired via ordinary `PATCH .../{public_id}` with `status: cancelled`, which fully preserves the record. This is narrower than either extreme the governing instructions floated ("Administrator-only hard delete" vs. "no hard delete at all") and was chosen specifically to give a genuine data-entry-mistake escape hatch without ever risking real work history.

**Dependency protection.** `ProjectController::destroy` and `StaffController::destroy` each gained one more `409` check (Task-referencing), following the exact existing pattern (a plain `->exists()` query before the row's `restrictOnDelete()`-backed foreign key would otherwise throw a raw DB exception). Staff deletion is blocked regardless of the referencing Task's status — since assignment history outlives even Project Membership removal, a Staff member who has ever done real, tracked work is never silently orphaned out of that record via deletion.

## 4. Files Changed

**Added:**
- `apps/api/database/migrations/2026_09_11_110000_create_tasks_table.php`
- `apps/api/app/Enums/TaskStatus.php`, `apps/api/app/Enums/TaskPriority.php`
- `apps/api/app/Models/Task.php`
- `apps/api/app/Http/Controllers/Api/V1/Tasks/TaskController.php`
- `apps/api/app/Http/Controllers/Api/V1/Tasks/Concerns/AuthorizesTaskAccess.php`
- `apps/api/app/Http/Requests/Tasks/StoreTaskRequest.php`, `UpdateTaskRequest.php`
- `apps/api/app/Http/Resources/TaskResource.php`
- `apps/api/database/factories/TaskFactory.php`
- `apps/api/tests/Feature/Api/V1/Tasks/TaskTest.php`
- `apps/api/tests/Feature/Authorization/TasksAuthorizationTest.php`
- `docs/phases/V1_PHASE_11_DEFINITION.md`
- `docs/handoffs/V1_PHASE_11_HANDOFF.md` (this file)

**Modified:**
- `apps/api/app/Models/Project.php` (added `tasks(): HasMany`)
- `apps/api/app/Models/Staff.php` (added `assignedTasks(): HasMany`)
- `apps/api/app/Http/Controllers/Api/V1/Projects/ProjectController.php` (`destroy()` Task-reference check)
- `apps/api/app/Http/Controllers/Api/V1/Staff/StaffController.php` (`destroy()` Task-reference check)
- `apps/api/database/seeders/RolePermissionSeeder.php` (`tasks.view`/`tasks.manage`)
- `apps/api/routes/api/v1.php` (Tasks route group)
- `apps/api/tests/Feature/Api/V1/Projects/ProjectTest.php`, `tests/Feature/Api/V1/Staff/StaffTest.php`, `tests/Feature/Authorization/RolePermissionSeederTest.php` (regression additions)
- `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/CHANGELOG.md`, `docs/CURRENT_STATE.md`, `docs/DECISIONS.md` (DEC-034), `docs/ROADMAP.md` (Phase 11 scope narrowed to exclude comments — see §12), `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`

## 5. Database/Schema Changes

One new migration: `2026_09_11_110000_create_tasks_table.php` — `tasks` (`id`, `public_id` ULID unique, `project_id` nullable FK `restrictOnDelete()`, `title`, `description` nullable, `status` string default `todo`, `priority` string default `normal`, `assignee_staff_id` nullable FK `restrictOnDelete()`, `created_by_user_id` nullable FK `nullOnDelete()`, `due_date` nullable date, `completed_at` nullable timestamp, timestamps, plus `(project_id, status)`/`(assignee_staff_id, status)` indexes). No changes to any existing table.

## 6. API Changes

```
GET    /api/v1/tasks                       scoped (tasks.view, or linked-Staff row-level scoping)
GET    /api/v1/tasks/{public_id}           scoped (same)
POST   /api/v1/tasks                       scoped (tasks.manage, or Project Lead of the target project)
PUT/PATCH /api/v1/tasks/{public_id}        scoped (tasks.manage / Project Lead: full; assignee: status only)
DELETE /api/v1/tasks/{public_id}           tasks.manage AND task.status === 'todo'
```

Filters: `?project=<public_id>`, `?status=`, `?assignee=<staff public_id>`, `?priority=`, `?q=` (title search). Standard Laravel pagination (`?per_page=`). Task Resource fields: `public_id`, `title`, `description`, `status`, `priority`, `due_date`, `completed_at`, `project` (`{public_id, name}`|`null`), `assignee` (`{public_id, employee_number, display_name}`|`null`), `created_by` (same shape|`null`), `created_at`, `updated_at`. No internal numeric ID anywhere.

## 7. Authorization/Security Changes

- New permissions: `tasks.view` (Manager, via `RolePermissionSeeder`), `tasks.manage` (Administrator-only, via the existing centralized `Gate::before` override — no explicit row added).
- New row-level authorization pattern (`AuthorizesTaskAccess`) extending Phase 10's read-only `AuthorizesProjectVisibility` shape to writes.
- No change to Phase 10's Project authorization rules — `project_lead` membership still grants no Project-management authority by itself; its new Task-management authority is a distinct, narrowly-scoped grant.
- Full detail in `docs/05_SECURITY_MODEL.md` (Phase 11 entries) and `docs/DECISIONS.md` DEC-034.

## 8. Tests Added or Changed

- `tests/Feature/Api/V1/Tasks/TaskTest.php` — 34 tests: CRUD (list/create/independent+Project-linked/validation/view/internal-id-rejection/update/immutable `project_id`/`completed_at` prohibition/delete-while-`todo`/delete-rejected-otherwise), lifecycle (status change/invalid status/completion timestamp set+cleared/invalid priority), assignment (unassigned/valid/invalid public ID/inactive-or-separated rejected/Project-membership requirement/reassignment/unassign/membership-removal survives/employment-status-change survives), filters (project/status/assignee/priority/search), creator (linked-Staff shown, unlinked → `null`).
- `tests/Feature/Authorization/TasksAuthorizationTest.php` — 15 tests: Administrator full access, Manager view-only, Project Lead scoped management (own Project, rejected in another Project, rejected for independent Tasks), ordinary Project Member view-only, Staff non-member rejected, assignee self-service (status-only, other-field rejection, delete rejection), no-membership/no-assignment Staff sees nothing, no-role user with/without linked Staff, unauthenticated, suspended account.
- `tests/Feature/Authorization/RolePermissionSeederTest.php` — added `test_it_creates_the_phase_11_tasks_permissions`, `test_only_manager_is_granted_tasks_view_not_staff`; updated the hardcoded total-permission-count assertion (14 → 16).
- `tests/Feature/Api/V1/Projects/ProjectTest.php` — added `test_deleting_a_project_with_tasks_is_rejected`.
- `tests/Feature/Api/V1/Staff/StaffTest.php` — added `test_deleting_a_staff_member_with_an_assigned_task_is_rejected`, `test_deleting_a_staff_member_with_a_completed_assigned_task_is_still_rejected`.

## 9. Commands/Checks Executed

Run from `apps/api`, per `CLAUDE.md` §5, in order:

```
composer install --no-interaction --prefer-dist --no-progress
composer validate --strict
cp .env.example .env && php artisan key:generate
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test
```

Plus: `php artisan migrate:fresh --seed --force` (SQLite), and a manual HTTP smoke test via `php artisan serve` + `curl`/`tinker`.

## 10. Results

- `composer install`: succeeded (see §14 for the environment recovery this needed).
- `composer validate --strict`: `./composer.json is valid`.
- `vendor/bin/pint --test`: `{"tool":"pint","result":"passed"}`.
- `vendor/bin/phpstan analyse`: `{"tool":"phpstan","result":"passed","errors":0}` (level 5).
- `php artisan test`: `{"tool":"phpunit","result":"passed","tests":319,"passed":319,"assertions":917}` — 54 new tests, 265 pre-existing Phase 1–10 tests unaffected in behavior (full regression suite green). One expected fix along the way: `RolePermissionSeederTest`'s hardcoded permission count needed updating from 14 to 16.
- `migrate:fresh --seed`: all 20 migrations (19 pre-existing + `tasks`) ran cleanly against SQLite; `RolePermissionSeeder` seeded successfully.
- Manual HTTP smoke test (`php artisan serve` + `curl`/`tinker`, real server, not PHPUnit's in-process client):
  - Independent-Task CRUD + lifecycle: create (`201`, `project: null`) → list (`200`) → view (`200`) → complete (`completed_at` auto-set) → hard-delete rejected while `completed` (`409`) → reopen (`completed_at` cleared) → hard-delete allowed while `todo` (`204`).
  - Project Lead + assignee self-service: a Project Lead created a Task in their own Project with a Project-member assignee (`201`, minimal nested shapes confirmed) → the same Lead's attempt to create an independent Task was rejected (`403`) → the assignee updated only `status` (`200`) → the same assignee's attempt to also change `title` in the same request was rejected (`403`, no partial write) → the assignee's `DELETE` attempt was rejected (`403`).

## 11. Deviations from Specification

- **`04_API_CONVENTIONS.md`'s Phase 11 illustrative example** (`/projects/{project}/tasks`) was **not** followed — a flat `/api/v1/tasks` resource was used instead, matching this document's own "prefer a top-level filterable resource... when a resource is more independent than owned" guidance and DEC-006's independent-Task requirement, and documented as the actual Phase 11 pattern (mirroring Phase 8's precedent of superseding its own stale illustrative example).
- **`04_API_CONVENTIONS.md`'s `POST /tasks/{id}/complete` illustrative example** was likewise not followed — status changes (including completion) go through the ordinary `PATCH` endpoint, continuing the established Phase 6–10 precedent, not a dedicated action route.
- **`docs/ROADMAP.md`'s Phase 11 one-line description** mentioned "comments" — not implemented in this phase; the governing Phase 11 instructions for this session explicitly and repeatedly scoped task comments/collaboration out, alongside Work Logs/activity/notifications, deferring them to a future phase. `docs/ROADMAP.md` was updated to record this narrower, actually-implemented boundary (the same kind of scope note Phase 10 recorded for Project milestones).
- No other deviation from the phase definition (`docs/phases/V1_PHASE_11_DEFINITION.md`, itself written this session from the governing instructions plus repository inspection).

## 12. Known Issues/Limitations

- No Admin Backoffice (Blade/Livewire) CRUD UI and no Flutter mobile screens for Tasks — API/backend only, consistent with every business-module phase since Phase 6.
- No due-date/overdue filter, no advanced search — consistent with "at current scale, do not over-engineer."
- A Task's `project_id` is immutable after creation (moving a Task between Projects is out of scope for V1) — a deliberate simplification, not an oversight; recorded in the phase definition.
- Task comments, attachments, activity timeline, Work Logs, notifications, subtasks, dependencies, recurring tasks, Kanban boards, and approval workflows remain unimplemented by design — see the phase definition's Explicit Scope Exclusions.

## 13. Manual/UAT Testing Instructions

No Admin Backoffice UI or Flutter mobile screens exist for Tasks yet (consistent with every prior business-module phase), so UAT here means direct API verification:

1. Log in as the seeded Administrator (or any Administrator-role account) and obtain a Sanctum bearer token via `POST /api/v1/auth/login`.
2. `POST /api/v1/tasks` with just `{"title": "..."}` — confirm `201` and `project: null`, `assignee: null`.
3. Create a Project (`POST /api/v1/projects`) and a Staff member who is a member of it (`POST /api/v1/projects/{public_id}/members`), then `POST /api/v1/tasks` with `project_id`/`assignee_staff_id` set to their public IDs — confirm `201` with the minimal nested shapes.
4. `PATCH /api/v1/tasks/{public_id}` with `{"status": "completed"}` — confirm `completed_at` is populated; `PATCH` again with a different status — confirm `completed_at` returns to `null`.
5. As the Task's assignee (a Staff-linked, non-Administrator account), `PATCH` the same Task with `{"status": "in_progress"}` — confirm `200`; retry with `{"title": "x"}` — confirm `403`.
3 UAT scenarios are recorded as `NOT RUN` in `docs/testing/UAT_LOG.md` (`UAT-11-01`, `UAT-11-02`, `UAT-11-03`), ready for the product owner's own review — no `PASS` is recorded on their behalf, per `CLAUDE.md` §7.

## 14. Documentation Updated

`docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/DECISIONS.md` (DEC-034), `docs/02_ARCHITECTURE.md` (§21 added, status line), `docs/03_DATABASE_MODEL.md` (Work Management section, Resolved list), `docs/04_API_CONVENTIONS.md` (Phase 11 note, Filtering section), `docs/05_SECURITY_MODEL.md` (status line, Authorization/API Access sections), `docs/ROADMAP.md` (Phase 11 scope narrowed), `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`, `docs/phases/V1_PHASE_11_DEFINITION.md` (new), this handoff (new).

## 14a. Session Environment Note

This session's container started with no `vendor/` at all. `composer install` reproduced the by-now-familiar pattern (`api.github.com` zipball downloads blocked by the sandbox's network policy, git-mirror-cache fallback needed for every third-party dependency) but this time failed **harder** than Phase 9/10: `phpstan/phpstan`'s own internal `git clone --mirror` again exceeded Composer's 300-second process timeout, and this time that failure aborted the *entire* `composer install` process before it ever wrote `vendor/composer/installed.json`/`vendor/autoload.php` — unlike Phase 9/10, where installed.json already existed from a mostly-successful install and only needed manual patching for the one problem package.

Recovered by running the identical `git clone --mirror -- https://github.com/phpstan/phpstan.git ...` command directly via a plain shell command (not through Composer, so not subject to its 300s internal cap) with a longer allowance — it completed in **5m41s**, just over Composer's cap but well within reach directly. This pre-seeded Composer's local VCS mirror cache with a genuinely complete (non-shallow) mirror, so a subsequent plain `composer install --no-interaction --prefer-dist --no-progress` retry completed normally end-to-end on the first attempt — no manual `vendor/` file surgery, `installed.json` patching, or hand-written proxy scripts were needed this time. All quality gates then ran and passed against real, fully-installed code (§10). This does not affect the correctness of anything committed — `vendor/` is never committed either way.

## 15. Recommended Next Step

Phase 12 — Work Logs (per `docs/ROADMAP.md`), building time/activity logging against Tasks/Projects on top of this phase's foundation. **Not authorized to begin** — per `CLAUDE.md` §8/§10, this session stops here and awaits explicit product-owner authorization before any Phase 12 work starts.
