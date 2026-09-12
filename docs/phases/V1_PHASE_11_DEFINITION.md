# Phase 11 — Tasks — Specification

**Status:** COMPLETE
**Depends on:** Phase 10 (Projects & Project Membership)

## Objective

Build the foundational Tasks module: a clean, small Task model with a practical lifecycle, Staff assignment governed by clear eligibility rules, and visibility/authority that stays consistent with Phase 10's Project visibility and authorization model. This is the foundation later modules (Work Logs, task activity, collaboration, notifications, reporting) will reference — not those modules themselves.

## Task ↔ Project Relationship (DEC-006)

`docs/DECISIONS.md` DEC-006 (recorded at Phase 0) is explicit: **"Tasks may optionally belong to a project. Internal company tasks must be possible without creating artificial projects."** This overrides a naive "every Task belongs to a Project" default — `tasks.project_id` is **nullable**, `restrictOnDelete()`. A Task belongs to at most one Project; a Project has many Tasks. An independent (project-less) Task and a Project-linked Task share the same model, lifecycle, and (mostly) the same authorization rules — see Authorization below for where they differ.

## Task Domain Model

`tasks`: `public_id` (ULID, DEC-017), `project_id` (nullable FK to `projects`, `restrictOnDelete()`), `title` (required), `description` (nullable), `status` (`App\Enums\TaskStatus`), `priority` (`App\Enums\TaskPriority`), `assignee_staff_id` (nullable FK to `staff`, `restrictOnDelete()`), `created_by_user_id` (nullable FK to `users`, `nullOnDelete()` — accountability only), `due_date` (nullable date), `completed_at` (nullable timestamp, server-controlled only), timestamps.

**Single nullable assignee, not a pivot.** `03_DATABASE_MODEL.md` flagged `task_assignees` (many-to-many) as an open question for this phase ("supports multiple assignees, or could be simplified to one primary... — open question"). Resolved for V1: a single nullable `assignee_staff_id` — no demonstrated V1 requirement for multiple simultaneous assignees, and a pivot table adds real complexity (eligibility/removal semantics multiplied per row) this phase's instructions explicitly warn against introducing without one.

## Task Lifecycle

`App\Enums\TaskStatus`: `todo` / `in_progress` / `blocked` / `completed` / `cancelled` — five states, no restricted transition graph (any status may move to any other status via ordinary update), no workflow engine, no approval states, no configurable pipeline, no Kanban.

`App\Enums\TaskPriority`: `low` / `normal` / `high` / `urgent`, default `normal`.

**Completion timestamp.** `completed_at` is **server-controlled only** — both `StoreTaskRequest`/`UpdateTaskRequest` reject a client-supplied `completed_at` (`prohibited` validation rule). It is set to `now()` whenever a Task's status transitions to `completed` (at creation or via update), and **cleared** when a `completed` Task is reopened (status moves away from `completed`). This deliberately differs from Project's `completed_date` (Phase 10), which is user-supplied and preserved through reopening: a Project's completion date is a meaningful historical fact an admin records, while a Task's `completed_at` is a derived workflow timestamp recomputed fresh each time — clearing it on reopen keeps the invariant "`completed_at` is non-null if and only if status is `completed`" simple and unsurprising. No status-history table, audit timeline, or change feed was introduced to support this — the single timestamp is sufficient for V1.

## Priority and Due Date

`priority` always has a value (defaults to `normal`), validated as `App\Enums\TaskPriority`. `due_date` is a plain nullable date — no scheduling/calendar logic, no reminders, no automatic notifications, no overdue-escalation engine.

## Created By

`created_by_user_id` records the authenticated `User` who created the Task, server-set (never client-supplied) — accountability only, never confused with the assignee and never itself an authorization primitive beyond Task creation (see Authorization). `TaskResource` exposes it as a minimal Staff shape (`{public_id, employee_number, display_name}`, mirroring the assignee shape) only when the creating User has a linked Staff record, and `null` otherwise — never a raw `User` identity, avoiding exposing unnecessary internal account information.

## Assignee Eligibility

A **new** Task assignment (`assignee_staff_id` supplied on create, or changed on update) must be:
- an **active** Staff member (`App\Enums\StaffStatus::Active`) — the same "new assignment only" pattern as Project Membership (Phase 10): an existing assignment is left untouched if the assignee's employment status later changes.
- when the Task belongs to a Project, a **current member of that Project** (any role) — enforced server-side in `StoreTaskRequest`/`UpdateTaskRequest`, never left to client-side filtering. An independent (project-less) Task has no membership to check — only the active-Staff rule applies.

An unassigned Task (`assignee_staff_id = null`) is always allowed.

## Project Membership Removal — Assignment History Is Preserved

`Task.assignee_staff_id` references `staff` **directly**, not through `project_memberships` — exactly the design Phase 10 itself anticipated ("Future Tasks/Work Logs are expected to reference `staff_id`/`project_id` directly rather than through Membership, so historical work records are not lost when a membership row is later removed"). Consequently: **removing a Staff member's Project Membership never touches any Task's `assignee_staff_id`.** The smallest safe rule was chosen deliberately — membership removal is not blocked by open Task assignments, Tasks are not auto-unassigned, and no reassignment is required first. This preserves assignment/business history (strategy 3 of the phase's candidate strategies) rather than silently destroying it. New assignment eligibility (above) is checked only when a Task is newly assigned — a staff member's continuing Task assignment is never re-validated against their current membership or employment status.

## Employment Status Changes

If an assigned Staff member later becomes `inactive`/`separated` (Phase 7's `StaffStatus`), their existing Task assignments are untouched — only *new* assignments require `active` status. Task state is never cascaded from employment state.

## Authorization

Two new permissions extend `RolePermissionSeeder` (Phase 5–10 pattern):
- `tasks.view` — Administrator (via the centralized `Gate::before` override) and **Manager only** (not Staff) — mirrors `projects.view`'s reasoning exactly: Tasks may carry the same client-engagement sensitivity as their Project.
- `tasks.manage` — **Administrator-only.** Covers unrestricted Task create/update/delete.

No `tasks.assign`/`tasks.complete`/`tasks.delete`/`tasks.edit`/`tasks.transition` permissions were introduced — Project Lead authority and assignee self-service (below) are both enforced as **row-level, in-controller checks** (`App\Http\Controllers\Api\V1\Tasks\Concerns\AuthorizesTaskAccess`), not new permissions, keeping the catalog as small as the governing instructions ask.

### Task Management Authority

- **Administrator** — full create/update/delete on every Task (`tasks.manage`).
- **Project Lead** — a Staff member holding `App\Enums\ProjectMembershipRole::ProjectLead` on a Task's own Project may create/update Tasks scoped to that Project. This is a **Task-specific, bounded authorization decision**, not a retroactive grant of Project-management authority: it does not touch `projects.manage`, does not let a Project Lead edit the Project itself or manage its membership (Phase 10's rules are unchanged), and has no meaning for an independent (project-less) Task — there is no Project to be lead of.
- **Assignee self-service** — the Staff member a Task is currently assigned to may update **only its `status` field** (any Task, Project-linked or independent). Any other field present in the same request is rejected (`403`) — `title`, `description`, `priority`, `due_date`, and (given it's `prohibited` outright) `project_id`/`assignee_staff_id` all require the broader authority above. This is enforced by field-diffing the validated payload against the requester's authority tier in `TaskController::update()`, not a separate permission.
- **Ordinary Project Member** (not lead, not assignee) — view-only.
- **Manager** — `tasks.view` grants company-wide read access; Manager holds no Task-management authority (matches the governing instructions' "not necessarily manage unless explicitly intended").
- **Independent (project-less) Task creation/management** is **Administrator-only** — there is no Project Lead concept without a Project, and the governing "potential V1 model" does not extend general task-creation authority to every Staff member. This keeps DEC-006's "internal company tasks must be possible" support real without turning Tasks into an open personal-task system for every account in V1.

## Visibility

`GET /api/v1/tasks` and `GET /api/v1/tasks/{public_id}` are **not** gated by a bare `can:tasks.view` route middleware, mirroring Phase 10's `AuthorizesProjectVisibility` precedent — extended here to `POST`/`PATCH` as well (the first time this codebase scopes *writes*, not just reads, outside route middleware):
- A requester holding `tasks.view` (Administrator/Manager) sees every Task.
- Otherwise, a requester with a linked Staff record sees: Tasks belonging to a Project where they hold a Project Membership (any role — matching "Project members can view Tasks within Projects where they are members"), **plus** any Task assigned to them directly (covers an independent Task assigned to them, which has no Project membership to match).
- A requester with neither `tasks.view` nor a linked Staff record sees nothing (`403`).

Task visibility is never broader than Project visibility: a non-member cannot see a Project-linked Task merely by knowing its `public_id`, because `authorizeView()` re-checks Project Membership directly against the Task's own `project_id`, the same underlying check `AuthorizesProjectVisibility` uses for the Project itself.

## API

```
GET    /api/v1/tasks                       scoped (see Visibility)
GET    /api/v1/tasks/{public_id}           scoped (see Visibility)
POST   /api/v1/tasks                       scoped (see Task Management Authority — Administrator or Project Lead of the target project)
PUT/PATCH /api/v1/tasks/{public_id}        scoped (see Task Management Authority; assignee is limited to `status` only)
DELETE /api/v1/tasks/{public_id}           tasks.manage (Administrator-only) AND task.status === 'todo' (see Delete Behavior)
```

**Flat, top-level resource — not nested under `/projects/{project}/tasks`.** Unlike Project Membership (Phase 10), which is inherently contextual to a Project and therefore genuinely nested, a Task is not always owned by a Project at all (DEC-006). `04_API_CONVENTIONS.md`'s own "prefer a top-level filterable resource... when a resource is more independent than owned" guidance (established by Contact→Client, Phase 8) applies directly. This also sidesteps Phase 10's two-consecutive-Eloquent-parameter nested route-model-binding bug entirely — `/api/v1/tasks/{task:public_id}` is a single bound parameter, never two.

## Task Resource Shape

`public_id`, `title`, `description`, `status`, `priority`, `due_date`, `completed_at`, `project` (minimal `{public_id, name}` or `null`), `assignee` (minimal `{public_id, employee_number, display_name}` or `null`), `created_by` (same minimal Staff shape, or `null` if the creator has no linked Staff record), timestamps. No internal numeric ID anywhere; no nested Project membership roster or full Staff/User record.

## Filters

`?project=<public_id>`, `?status=`, `?assignee=<staff public_id>`, `?priority=`, `?q=` (title search) — applied within the visibility-scoped base query, same pattern as Phase 10's Project filters. No due-date/overdue filter, no advanced query syntax, no reporting endpoints — consistent with "at current scale, do not over-engineer search infrastructure."

## Validation

- `title` required; `description`/`due_date` optional; `status`/`priority` valid enum values (each defaults sensibly when omitted on create).
- `project_id` resolved server-side from a Project `public_id`; **immutable after creation** — `UpdateTaskRequest` rejects `project_id` outright (`prohibited`) rather than silently ignoring it. Moving a Task between Projects is out of scope for V1 (it would need to re-validate assignee membership and re-derive management authority) — a deliberate, documented simplification, not an oversight.
- `assignee_staff_id` resolved server-side from a Staff `public_id`, rejecting an unknown public ID (`422`) and enforcing the eligibility rules above for a new assignment; explicit `null` unassigns without any eligibility check.
- `completed_at` is `prohibited` in both Store/Update requests — always server-derived from `status`.

## Delete Behavior

Tasks are business history — a full hard delete would risk destroying that history once real work (and, later, Work Logs) references a Task. The chosen rule is narrower than either extreme the governing instructions floated:
- **Hard delete** (`DELETE`) is **Administrator-only** (`tasks.manage`) and allowed **only while the Task is still `todo`** — nothing has happened yet, so there is no history to lose; this is a genuine data-entry-mistake escape hatch, not a general deletion capability. Any other status returns `409`.
- **Cancellation** — a Task with real activity (`in_progress`/`blocked`/`completed`) is retired via the ordinary `PATCH .../{public_id}` with `status: cancelled`, per whichever management authority tier the requester holds — preserving its full history rather than destroying it. No soft-delete column was added; `cancelled` already serves that purpose, consistent with the governing instructions' preference.

## Project Deletion Protection

`ProjectController::destroy` (Phase 10) is extended: a Project with any Task still referencing it (`tasks.project_id`) cannot be deleted (`409`), returned as a clean domain error rather than the raw `restrictOnDelete()` database exception the same foreign key backs at the DB level — identical pattern to the existing Project Membership check.

## Staff Deletion Protection

`StaffController::destroy` (Phase 7) is extended: a Staff member with any Task still referencing them as assignee (`tasks.assignee_staff_id`) cannot be deleted (`409`), **regardless of the Task's status** — since assignment history is preserved even after Project Membership removal (see above), a Staff member who has ever done real work is never silently orphaned out of that history via deletion. Consistent with the existing direct-reports/project-memberships checks on the same endpoint; `Staff.status` (Phase 7's `inactive`/`separated`) remains the correct tool for offboarding, not deletion.

## Explicitly Out of Scope

Per the governing instructions: work logs, time tracking, timers, timesheets, billable hours, payroll, attendance, task comments, task attachments, task reactions, messaging, notifications, mentions, Kanban boards, configurable workflows, subtasks, task dependencies, recurring tasks, calendars, reminders, Gantt charts, milestones, resource forecasting, project budgeting/financials, utilization metrics, task activity timeline, audit/event stream, approval workflows, AI task generation, a `task_assignees` many-to-many pivot. No Admin Backoffice (Blade/Livewire) CRUD UI and no Flutter mobile screens — API/backend only, consistent with every prior business-module phase's precedent.

**Scope note relative to `docs/ROADMAP.md`:** the Roadmap's one-line Phase 11 description also mentions "comments." The governing Phase 11 instructions for this session are explicit and repeated that task comments/collaboration are out of scope for this phase (deferred alongside Work Logs/activity/notifications to a future phase) — the same kind of narrowing Phase 10 recorded for Project milestones. `docs/ROADMAP.md` is updated to reflect this narrower, actually-implemented boundary.

## Relevant Documentation

- `docs/02_ARCHITECTURE.md` §4/§15 (Authorization)
- `docs/03_DATABASE_MODEL.md` §1 (Work Management entity group — `tasks`/`task_assignees` open question, resolved here)
- `docs/04_API_CONVENTIONS.md` (Resource Naming — flat filterable resource over nesting where a resource is more independent than owned)
- `docs/05_SECURITY_MODEL.md` (data isolation)
- `docs/DECISIONS.md` DEC-006 (independent Tasks), DEC-017 (identifiers), DEC-028 (authorization pattern), DEC-030 (Staff eligibility precedent), DEC-033 (Project visibility/authorization precedent this phase extends to writes)

## Acceptance Criteria

- Administrator can create, view, update (any field), and (while `todo`) delete any Task; a Project Lead can do the same, scoped to Tasks in Projects where they lead; a Task's assignee can update only its `status`.
- An ordinary Project Member can view Tasks in their Project but not manage them; a Staff non-member cannot view a Project's Tasks; a linked-Staff user can always view/self-service a Task assigned to them even without Project membership (e.g. an independent Task).
- Assignee eligibility (active Staff; a Project member when the Task has a Project) is enforced server-side for new assignments only; removing a Project Membership never alters existing Task assignments.
- No internal numeric ID is ever exposed by an API response or accepted as client input — only `public_id`.
- Project/Staff deletion is blocked while referenced by a Task.
- All `CLAUDE.md` §5 quality gates pass, including the full Phase 1–10 regression suite.

## Testing Expectations

- Task CRUD: create (with/without a Project), list, view by `public_id`, internal-numeric-id rejection, invalid Project `public_id` rejection, update, status transition, delete-while-`todo` allowed, delete-while-not-`todo` rejected (`409`).
- Assignment: assign a valid, eligible Staff member; leave unassigned; reject an unknown Staff `public_id`; reject an inactive/separated Staff member on new assignment; reject a Staff member who isn't a Project member (for a Project-linked Task); reassignment; removing a Project Membership does not disturb an existing Task assignment.
- Lifecycle: valid/invalid status values; `completed_at` set on completion and cleared on reopen; priority validation.
- Visibility: Administrator, Manager, Project Lead, Project Member (non-lead), Staff non-member, a Staff-linked user assigned to an independent Task, no-role user with/without linked Staff, unauthenticated, suspended account.
- Management: Administrator full management; Project Lead scoped management (including rejection outside their own Project); ordinary Project Member rejected from management; assignee self-status-update allowed, other-field self-update rejected; cross-Project Project-Lead access rejected.
- Integrity: Project deletion blocked while Tasks exist; Staff deletion blocked while assigned Tasks exist (any status); Project Membership removal while a Task is assigned does not destroy the assignment.
- Filters: project, status, assignee, priority, title search.
- Full Phase 1–10 regression suite continues passing unmodified in behavior.
