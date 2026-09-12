# Phase 12 — Work Logs — Specification

**Status:** COMPLETE
**Depends on:** Phase 11 (Tasks), Phase 10 (Projects & Project Membership), Phase 7 (Staff)

## Objective

Build the foundational Work Logs module: historical records of work performed by Staff, optionally tied to a Task and/or a Project, built on top of Staff/Projects/Project Membership/Tasks. This is explicitly **not** a payroll, attendance, billing, or full timesheet system — see Explicit Scope Exclusions.

## Critical Domain Boundary

A Work Log is a historical record of work performed. It is never treated as attendance, clock-in/clock-out, payroll hours, overtime, billable client hours, an invoice line item, a utilization score, or employee monitoring. No running timer of any kind is built.

## Work Log Parent Model (Task ↔ Project Consistency)

`03_DATABASE_MODEL.md`'s own Work Management entity group already described the intended shape: "`work_logs` belong to `staff` and optionally to a `task` and/or `project`." Resolved for V1:

- `work_logs.task_id` — nullable FK to `tasks`, `restrictOnDelete()`.
- `work_logs.project_id` — nullable FK to `projects`, `restrictOnDelete()`.
- **At least one of `task_id`/`project_id` must be present.** A Work Log with neither would be a generic, task/project-independent activity log — explicitly out of scope; Work Logs stay anchored to the Work Management domain (Staff/Projects/Tasks), never becoming a general activity/attendance log.
- **Single source of truth, no client-supplied inconsistency:** when `task_id` is supplied, `project_id` is **server-derived** from the Task's own `project_id` (which may itself be `null` for an independent Task — no fake Project is ever forced) and any client-supplied `project_id` alongside a `task_id` is rejected (a custom `after()` validation check in `ResolvesWorkLogReferences::validateAtLeastOneReference()` — this Laravel version has no declarative "prohibited if another field is present" rule; `prohibited_with` does not exist here, only `prohibited_if`/`prohibited_unless`, which compare against a specific value rather than "is present"). This makes it structurally impossible to persist `Work Log.project = A` while `Work Log.task` belongs to Project B. `project_id` is still a real, queried/indexed column (not purely derived at read time) because a Work Log may also reference a Project directly with no specific Task (e.g. general project activity) — persisting it is not redundant in that case, and keeping one column for both cases (task-derived or project-only) keeps `(project_id, work_date)` filtering/indexing simple.
- `project_id` is **immutable** after creation, same as `task_id` — moving a Work Log between Tasks/Projects post-creation is out of scope for V1 (mirrors Task's own `project_id` immutability, Phase 11/DEC-034); a wrongly-attributed Work Log is deleted and recreated instead of migrated.

## Staff / Performer vs Creator

- `work_logs.staff_id` — **required**, `restrictOnDelete()` — the Staff member who performed the work (the "performer"). Never nullable, never inferred implicitly beyond the two creation paths below.
- `work_logs.created_by_user_id` — nullable, `nullOnDelete()` — accountability metadata only (who entered the record), mirroring `tasks.created_by_user_id` (Phase 11) exactly. Never confused with the performer, and never itself an authorization primitive.
- **Self-service:** the performer is always the authenticated User's own linked Staff record (`$request->user()->staff`), never a client-supplied Staff public ID — reusing Phase 9's secure self-service precedent (`RequiresLinkedStaff`).
- **Administrative entry:** only an Administrator (`work-logs.manage`) may create/correct a Work Log naming a different Staff member as performer, via an explicit `staff_id` (public ID) in the payload. No other role may create or edit a Work Log on behalf of another Staff member — see Authorization below.

## Self-Service Logging

Any User with a linked Staff record may create their own Work Logs via `/api/v1/me/work-logs`, mirroring Phase 9's `/me/status`/`/me/check-ins` pattern. Eligibility (checked only at creation, see below) requires the performer's own Staff record to be `active`.

## Administrative / Supervisory Logging

- **Administrator only** may create or correct a Work Log for another Staff member (`work-logs.manage`), via the top-level `/api/v1/work-logs` endpoint. This is a deliberately narrow grant: no Project Lead or Manager correction/creation authority was introduced — Work Logs are historical records and get **stricter** editing rules than Tasks (Phase 11's Project Lead Task-management authority is **not** inherited here).
- Administrator entry does not re-validate the eligibility rules below (active staff / current membership) — an Administrator is trusted to backfill/correct a historical record for a Staff member who may since have left a Project or become inactive; the resolved `staff_id`/`task_id`/`project_id` must simply exist and remain internally consistent (task↔project single-source-of-truth, above).

## Project Lead Authority

A Project Lead (`ProjectMembershipRole::ProjectLead` on a Project they lead) may **view** Work Logs within that Project (whether Project-linked-only or Task-linked to one of that Project's Tasks) but has **no** edit/delete/create-for-others authority — a deliberate, stricter boundary than Phase 11's Task authority, per the governing instructions' explicit warning not to inherit Task authority automatically.

## Manager Authority

Work Log duration/timing data is treated as materially more sensitive than the company-wide Staff/Task/Project directories (which a Manager already sees broadly) — closer in sensitivity to Phase 9's location data. **Manager visibility is scoped to their own direct reports** (`Staff.manager_id`), not company-wide, mirroring `location.view`'s exact precedent (Phase 9, DEC-032) rather than `tasks.view`'s/`projects.view`'s company-wide Manager visibility. `work-logs.view` is attached to Manager only (not Staff) and grants no edit/delete/create-for-others authority — view only.

## Ordinary Staff Visibility

An ordinary Staff member (no `work-logs.view`, not a Project Lead) sees **only their own** Work Logs, via `/api/v1/me/work-logs`. Project membership never grants visibility into another member's logged work/duration — explicitly guarded against in this design and tested.

## Work Date / Duration Representation

Duration model, not start/end timestamps (no timer concept exists to derive start/end from):

- `work_date` — required date, must not be in the future (`before_or_equal:today`) — a Work Log is inherently retrospective.
- `duration_minutes` — required positive integer, `min:1`, `max:1440` (24 hours) — a simple sanity ceiling rejecting obvious data-entry mistakes, not a payroll-accurate cap.
- `description` — required, `string`, `max:2000` — a single free-text field (no separate "note"/"summary" fields).

No running timer (start/pause/resume/heartbeat/presence) exists anywhere in this phase.

## Eligibility at Log Creation (Self-Service Only)

Checked only when a Work Log is newly created via self-service (`/me/work-logs`), never re-validated afterward (see Historical Preservation):

- The performer's own Staff record must be `active` (`App\Enums\StaffStatus::Active`).
- If the Work Log references a Task belonging to a Project: the performer must currently be a member of that Project (**any** role — any active Project member may log work against a Project-linked Task, not only its assignee; a Project member may legitimately contribute without being the Task's primary assignee).
- If the Work Log references an independent (project-less) Task: the performer must currently be that Task's **assignee** — an independent Task has no membership concept, and this mirrors the only linked-Staff visibility route Phase 11 already grants for an independent Task (Task assignee self-service).
- If the Work Log references a Project directly (no Task): the performer must currently be a member of that Project (any role).

Task assignee status is **not** required for a Project-linked Task — any current Project member may log work on it, per the above.

## Historical Preservation

Once a Work Log exists, later changes never alter or destroy it:

- **Project Membership removed:** existing Work Logs referencing that Project/its Tasks are untouched — `staff_id`/`task_id`/`project_id` are never rewritten or nulled. Eligibility is a creation-time gate only.
- **Task reassigned:** a Work Log's `staff_id` (the performer) is never migrated to the new assignee — it was never derived from the assignee in the first place (any Project member could have logged it).
- **Staff becomes inactive/separated:** existing Work Logs remain fully intact; only *new* self-service creation is blocked for a non-active Staff member.
- **Task status changes (including `completed`/`cancelled`):** existing Work Logs remain. New Work Log creation is **not** gated by Task status at all — a Task's lifecycle state and Work Log eligibility are different concerns, and blocking logging against a just-completed or cancelled Task would block legitimate final/retroactive entries.

## Task Deletion Interaction

`TaskController::destroy` (Phase 11) is extended: a Task with any Work Log referencing it (`work_logs.task_id`) cannot be hard-deleted (`409`), checked **before** the existing `todo`-only status check (a Task cannot have real work logged against it while still meaningfully `todo`, but the check is defensive and explicit either way).

## Project Deletion Interaction

`ProjectController::destroy` (Phase 10) is extended: a Project with any Work Log directly referencing it (`work_logs.project_id`) cannot be deleted (`409`) — in addition to the existing Project Membership/Task checks (which already transitively protect Task-linked Work Logs, since the Task itself can't be deleted while referenced).

## Staff Deletion Interaction

`StaffController::destroy` (Phase 7) is extended: a Staff member with any Work Log referencing them as performer (`work_logs.staff_id`) cannot be deleted (`409`), regardless of the Work Log's associated Task/Project state — consistent with the existing Task-assignment protection.

## Work Log Lifecycle

No status/approval workflow (draft/submitted/approved) is introduced — a Work Log is a simple record from creation until an authorized edit/delete. This keeps V1 lean and avoids building a timesheet-approval system the governing instructions explicitly warn against.

## Editing Rules

- **Self-edit:** the performer may edit their **own** Work Log's `work_date`, `duration_minutes`, and `description` only — no arbitrary time-window restriction (e.g. "same day only") is invented, per the governing instructions' explicit preference for a simple authorization rule over an arbitrary window. `staff_id`, `task_id`, and `project_id` are immutable for everyone, including the performer (`prohibited`).
- **Administrator correction:** may edit any Work Log's `work_date`/`duration_minutes`/`description` (`work-logs.manage`). `staff_id`/`task_id`/`project_id` remain immutable even for Administrator — a wrongly-attributed Work Log is deleted and recreated rather than reassigned, keeping the update surface identical (and simple) for both self and admin.
- **Project Lead / Manager:** no edit authority at all (view-only, see above).

## Delete Rules

- **Self-delete:** the performer may hard-delete their **own** Work Log, at any time — no time-window restriction, no soft-delete column. Rationale: no downstream module in this phase (payroll/billing/reporting) yet references Work Logs, so a genuine, narrow self-correction capability (removing an obvious mistake) carries no real risk of destroying relied-upon history; access is still strictly limited to the performer themselves or an Administrator, never a third party.
- **Administrator:** may hard-delete any Work Log (`work-logs.manage`).
- **Project Lead / Manager:** no delete authority.

## Authorization

Two new permissions extend `RolePermissionSeeder` (Phase 5–11 pattern):

- `work-logs.view` — **Manager only** (not Staff — self-service needs no permission; mirrors `location.view`'s Phase 9 precedent, not `tasks.view`'s/`projects.view`'s company-wide Manager grant), scoped in-controller to the Manager's own direct reports.
- `work-logs.manage` — **Administrator-only.** Create-for-others, edit-any, delete-any.

No `work-logs.create`/`work-logs.edit`/`work-logs.delete`/`work-logs.approve`/`work-logs.submit` permissions were introduced. Self-service authority (create/edit/delete own) requires no permission at all — a domain check only (linked Staff record), mirroring Phase 9. Project Lead's view authority over their led Projects' Work Logs is a row-level, in-controller check (`AuthorizesWorkLogVisibility`), not a permission — mirroring Phase 11's `AuthorizesTaskAccess` pattern.

## API

```
GET    /api/v1/me/work-logs                self-service list (own only)
POST   /api/v1/me/work-logs                self-service create (performer derived from auth)
PUT/PATCH /api/v1/me/work-logs/{public_id} self-edit own (work_date/duration_minutes/description only)
DELETE /api/v1/me/work-logs/{public_id}    self-delete own

GET    /api/v1/work-logs                   scoped (Administrator: all; Manager: direct reports; Project Lead: led-Project logs, view only)
GET    /api/v1/work-logs/{public_id}       scoped, same rule
POST   /api/v1/work-logs                   work-logs.manage (Administrator-only) — staff_id required
PUT/PATCH /api/v1/work-logs/{public_id}    work-logs.manage (Administrator-only)
DELETE /api/v1/work-logs/{public_id}       work-logs.manage (Administrator-only)
```

`/me/work-logs/{public_id}` returns `404` (not `403`) when the `public_id` exists but belongs to a different Staff member — existence of another Staff member's Work Log is itself sensitive (per the Manager/Project-visibility privacy stance above), so it is never confirmed to an unauthorized self-service caller.

Two endpoint families, not one, because each has a genuinely distinct purpose (per the governing instructions' "do not blindly create both"): `/me/work-logs` guarantees the performer identity is always server-derived and can never be spoofed via a client-supplied Staff public ID (Phase 9's precedent); `/work-logs` is the supervisory/administrative surface with its own, separately scoped visibility and Administrator-only writes.

## Resource Shape

`public_id`, `staff` (minimal `{public_id, employee_number, display_name}`), `task` (minimal `{public_id, title}` or `null`), `project` (minimal `{public_id, name}` or `null`), `work_date`, `duration_minutes`, `description`, `created_by` (minimal Staff shape or `null`, mirrors `TaskResource`), timestamps. No internal numeric ID; no nested Project roster, full Task, or full Staff/User record.

## Filters

- `/api/v1/work-logs` (privileged): `?staff=<public_id>`, `?project=<public_id>`, `?task=<public_id>`, `?from=YYYY-MM-DD`, `?to=YYYY-MM-DD` (both bound `work_date`), applied within the visibility-scoped base query.
- `/api/v1/me/work-logs` (self): `?project=`, `?task=`, `?from=`, `?to=` (no `?staff=` — always self).

No weekly aggregation, payroll-period grouping, or utilization dashboard — plain filtered pagination only.

## Database Constraints and Indexes

`work_logs`: `id`, `public_id` (ULID, DEC-017 — independently addressable via `/api/v1/work-logs/{public_id}`, unlike `project_memberships`/`staff_statuses`), `staff_id` (`restrictOnDelete()`), `task_id` (nullable, `restrictOnDelete()`), `project_id` (nullable, `restrictOnDelete()`), `created_by_user_id` (nullable, `nullOnDelete()`), `work_date` (date), `duration_minutes` (unsigned int), `description` (string, ≤2000), timestamps.

Indexes: `(staff_id, work_date)`, `(project_id, work_date)`, `(task_id, work_date)` — matches the query patterns actually used (self-history, Project-scoped, Task-scoped, all latest-first). No DB-level `CHECK` constraint enforcing "at least one of task_id/project_id" — enforced at the application/Form Request layer, consistent with this codebase's existing precedent for cross-field business rules (e.g. Contact's single-primary-per-Client, Phase 8) over portability-fragile DB constraints.

## Explicitly Out of Scope

Per the governing instructions: payroll, salary/overtime calculation, billable rates, invoicing/client billing, attendance, clock-in/clock-out, geolocation/background tracking, timers (start/pause/resume/heartbeat/presence), timesheet approval workflow, weekly timesheet submission, payroll-period locking, utilization analytics, productivity/performance scoring, automatic overtime detection, leave/holidays/shift scheduling/work schedules, comments/messaging/notifications/reminders/attachments/screenshots, Project financials, approval chains. No Admin Backoffice (Blade/Livewire) CRUD UI and no Flutter mobile screens — API/backend only, consistent with every prior business-module phase.

## Relevant Documentation

- `docs/02_ARCHITECTURE.md` §4/§15/§19/§20/§21 (Authorization precedents)
- `docs/03_DATABASE_MODEL.md` §1 (Work Management entity group — `work_logs`, resolved here)
- `docs/04_API_CONVENTIONS.md` (self-scoped `/me/...` precedent, Phase 9)
- `docs/05_SECURITY_MODEL.md` (data isolation — Phase 9's Manager→direct-reports scoping is this phase's closest precedent, not Phase 10/11's company-wide Manager grant)
- `docs/DECISIONS.md` DEC-006 (independent Tasks), DEC-017 (identifiers), DEC-028 (authorization pattern), DEC-032 (Manager-scoped sensitive-data precedent), DEC-033/DEC-034 (Project/Task deletion-protection precedent)

## Acceptance Criteria

- A linked-Staff User can create/view/edit/delete their own Work Logs via `/me/work-logs`, subject to creation-time eligibility.
- An Administrator can create/view/edit/delete any Staff member's Work Log via `/work-logs`.
- A Manager sees only their direct reports' Work Logs; a Project Lead sees (read-only) Work Logs within Projects they lead; an ordinary Staff member never sees another Staff member's Work Logs merely via shared Project membership.
- Task/Project consistency cannot be violated — persisting `project_id` inconsistent with `task_id`'s own Project is structurally impossible.
- Historical preservation holds across Project Membership removal, Task reassignment, Staff status change, and Task status change.
- Task/Project/Staff deletion is blocked while referenced by a Work Log.
- No internal numeric ID is ever exposed or accepted — only `public_id`.
- All `CLAUDE.md` §5 quality gates pass, including the full Phase 1–11 regression suite.
