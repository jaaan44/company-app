# Phase 10 — Projects & Project Membership — Specification

**Status:** COMPLETE
**Depends on:** Phase 7 (Staff), Phase 8 (Clients)

## Objective

Build the foundational Projects & Project Membership module: Projects as a first-class business entity, optionally associated with a Client, with a small practical lifecycle; and Staff membership/assignment to Projects with a project-level role. This is the foundation later modules (Tasks, Work Logs, project activity, reporting, messaging) will reference — not those modules themselves.

## Core Domain Separation

- `Client` = external company/customer (Phase 8). `Project` = a piece of company work/engagement. `Staff` = internal employee (Phase 7). `ProjectMembership` = Staff participation in a Project. A project role (e.g. Project Lead) is scoped to one Project, never confused with the global Administrator/Manager/Staff application role.
- Organization Structure (Departments/Teams/Positions, Phase 6) is internal hierarchy; Projects are temporary/business-work structures — no relationship between the two is introduced.

## Project Domain

`projects`: `public_id` (ULID, DEC-017), `project_code` (nullable, unique when present, admin-supplied — mirrors `clients.client_code`, no auto-numbering), `name` (required), `description` (nullable), `client_id` (nullable FK to `clients`, `restrictOnDelete()` — a Project may be internal/client-less), `status` (`App\Enums\ProjectStatus`), `start_date`/`target_end_date`/`completed_date` (nullable dates), `notes` (nullable), timestamps.

**No `project_manager_staff_id` column.** Project leadership is expressed entirely through Project Membership's `role` (`project_lead`), avoiding two competing sources of truth for "who leads this project" — the governing instructions explicitly favored this when Membership already needs roles.

## Project Lifecycle

`App\Enums\ProjectStatus`: `planned` / `active` / `on_hold` / `completed` / `cancelled` — five states covering realistic project flow without workflow complexity. One lifecycle mechanism only — no soft deletes, no separate archival flag. `completed_date` is required when the effective status is `completed` (mirrors `StaffStatus::Separated`/`separation_date`, Phase 7); reopening a completed project (changing status away from `completed`) is permitted and does not clear `completed_date` (preserved as historical information) — no approval workflow.

## Project ↔ Client Relationship

A Project optionally belongs to one Client (`client_id` nullable) — internal projects have `client_id = null`. No many-to-many Project↔Client relationship. An inactive Client retains its existing Projects; a new Project may still be created against an inactive Client (no status cascade or restriction — history and future re-engagement are both preserved, per the governing instructions).

## Project Membership

`project_memberships`: `project_id` (FK `projects`, `restrictOnDelete()`), `staff_id` (FK `staff`, `restrictOnDelete()`), `role` (`App\Enums\ProjectMembershipRole`: `project_lead` / `member`, default `member`), timestamps. Unique constraint on `(project_id, staff_id)` — a staff member has at most one membership row per project; there is no historical-period tracking (membership represents the *current* roster only, mirroring how `staff.department_id`/`team_id` are mutable placement fields rather than a history table — unlike Phase 9's genuinely historical status/check-in tables, a project roster has no demonstrated need for period history in V1). No `public_id` — a membership is never independently addressed by URL; it is addressed via its Project's `public_id` plus its Staff's `public_id` (nested route), consistent with DEC-017's "pivot/history tables generally don't need one."

**No single-project-lead enforcement.** Unlike Contact's `is_primary` (a genuine scarce "the one primary contact" concept), `project_lead` is a responsibility tag — multiple co-leads are permitted; no transactional clear-then-set is built.

**Membership lifecycle:** removal is a hard delete of the membership row (no `status`/`ended_date` column) — the simplest model satisfying "current assignment" semantics. Future Tasks/Work Logs are expected to reference `staff_id`/`project_id` directly rather than through Membership, so historical work records are not lost when a membership row is later removed.

**Staff eligibility:** only a Staff member whose employment `status` is `active` may be **newly** assigned to a Project (validated server-side in `StoreProjectMembershipRequest`); an existing membership is left untouched if the staff member later becomes `inactive`/`separated` — Phase 7's employment lifecycle is never modified by this phase.

## Authorization

Two new permissions, extending the existing `RolePermissionSeeder` (Phase 5–9 pattern, no new mechanism):
- `projects.view` — Administrator (via the centralized `Gate::before` override) and **Manager** — company-wide oversight of every Project, mirroring Manager's access to Staff/Clients/Organization directories.
- `projects.manage` — **Administrator-only.** Covers Project CRUD *and* all Project Membership writes (add/change-role/remove) — no separate `project-membership.*` pair, and no project-lead management carve-out: every prior `*.manage` permission in this codebase is Administrator-only, and the governing instructions explicitly warn against complicated role inheritance. A future phase may reconsider this if the product owner wants project leads to self-manage membership.

Ordinary **Staff** are not granted `projects.view` — Projects are membership-scoped business information (potentially referencing sensitive client engagements), not company-wide directory data like Staff/Clients/Organization. A Staff member sees only Projects where they hold a Project Membership.

## Visibility (the phase's central design question)

`GET /api/v1/projects` and `GET /api/v1/projects/{public_id}` (and the nested members listing) are **not** gated by `can:projects.view` route middleware alone, because that would blanket-deny every ordinary Staff member instead of scoping them to their own memberships. Instead:
- A requester holding `projects.view` (Administrator/Manager) sees every Project, full stop.
- Otherwise, a requester with a linked Staff record (Phase 7's optional `staff.user_id`) sees only Projects where that Staff record holds a Project Membership — enforced in the controller (`AuthorizesProjectVisibility` trait), the second real row-level authorization pattern in this codebase after Phase 9's Manager→direct-reports scoping.
- A requester with neither `projects.view` nor a linked Staff record sees nothing (`403`).

This mirrors Phase 9's precedent of self-scoped access requiring no permission, only a linked Staff record (`/me/status`, `/me/check-ins`) — extended here to the primary resource endpoint itself rather than a separate `/me/projects` route, since "the same data, differently scoped by privilege" is a better fit than duplicating the resource under `/me/...`.

## API

```
GET    /api/v1/projects                                    scoped (see Visibility)
GET    /api/v1/projects/{public_id}                        scoped (see Visibility)
POST   /api/v1/projects                                     projects.manage
PUT/PATCH /api/v1/projects/{public_id}                       projects.manage
DELETE /api/v1/projects/{public_id}                          projects.manage

GET    /api/v1/projects/{public_id}/members                 scoped (same as viewing the project)
POST   /api/v1/projects/{public_id}/members                  projects.manage
PUT/PATCH /api/v1/projects/{public_id}/members/{staff_public_id}  projects.manage
DELETE /api/v1/projects/{public_id}/members/{staff_public_id}     projects.manage
```

Nested member routes (not a flat `/project-memberships` resource) — Membership is inherently contextual to a Project, unlike Contact→Client (Phase 8), which was deliberately kept flat despite being genuinely owned. Addressed by the member's Staff `public_id` within the nested collection (unique per `(project_id, staff_id)`), not a separate membership `public_id`.

## Project Resource Shape

`public_id`, `project_code`, `name`, `description`, `status`, `start_date`, `target_end_date`, `completed_date`, `client` (minimal `{public_id, name}` or `null`), `members_count`, `my_role` (the requester's own membership role if they are a member, else `null`), `notes`, timestamps. No nested membership roster — a dedicated endpoint serves that.

## Membership Resource Shape

`staff` (minimal `{public_id, employee_number, display_name}`), `role`, timestamps. No nested Project reference (always fetched in a Project's own nested context) and no membership `public_id` (see above). No internal numeric ID anywhere.

## Filters

Project: `?status=`, `?client=<public_id>`, `?member=<staff public_id>`, `?q=` (name/project_code search) — applied within the visibility-scoped base query. Membership: `?role=`. No advanced reporting queries.

## Validation

Project: `name` required; `project_code` nullable, unique when present; `client_id` resolved server-side from `public_id`; `status` a valid `ProjectStatus`; `start_date`/`target_end_date`/`completed_date` chronologically consistent (each not before `start_date`); `completed_date` required when the effective status is `completed`. Membership: `staff_id` required, resolved from `public_id`, must exist; `role` a valid `ProjectMembershipRole`; the target Staff member's employment status must be `active` for a **new** membership; rejects a duplicate `(project_id, staff_id)` pair with a clear `422` (backed by the DB unique constraint).

## Delete Behavior

- **Project:** cannot be deleted while any Project Membership references it (`409`, `restrictOnDelete()`-backed) — same relational-integrity philosophy as Department/Client/Staff, and preserves the anchor Tasks/Work Logs will need later.
- **Membership:** freely removable (`DELETE`) — represents only the current roster, not history (see above).
- **Client:** `ClientController::destroy` is extended to also reject deletion (`409`) while any Project still references the Client (alongside the existing Contact check).
- **Staff:** `StaffController::destroy` is extended to also reject deletion (`409`) while any Project Membership still references the Staff member (alongside the existing direct-reports check).

## Explicitly Out of Scope

Per the governing instructions: tasks, task assignment, subtasks, Kanban, task comments, task dependencies, task status/due dates/notifications, work logs, time tracking, timesheets, payroll, billing, project invoicing, quotations, contracts, CRM opportunity pipelines, file/document management, messaging, notifications, calendars, Gantt charts, resource forecasting, project budgeting/financials, utilization metrics, project profitability, performance scoring, approval workflows. No Admin Backoffice (Blade/Livewire) CRUD UI and no Flutter mobile screens — this phase is API/backend only, consistent with every prior business-module phase's precedent.

## Relevant Documentation

- `docs/02_ARCHITECTURE.md` §4/§15 (Authorization), §17/§18 (Staff/Clients precedent)
- `docs/03_DATABASE_MODEL.md` §1 (Work Management entity group)
- `docs/04_API_CONVENTIONS.md` (Resource Naming — nested resources where genuinely contextual)
- `docs/05_SECURITY_MODEL.md` (data isolation)
- `docs/DECISIONS.md` DEC-017 (identifiers), DEC-028 (authorization pattern), DEC-030 (Staff precedent), DEC-031 (Client precedent, optional-relationship/delete-protection pattern), DEC-032 (row-level visibility precedent)

## Acceptance Criteria

- Administrator/Manager can create, view, update, change lifecycle, and (when unreferenced) delete Projects; Staff can view only Projects they are a member of.
- Administrator can manage Project Membership (add/change role/remove); Staff eligibility (`active` only) is enforced on new assignments only.
- No internal numeric ID is ever exposed by an API response or accepted as client input — only `public_id`.
- Client/Staff deletion is blocked while referenced by a Project/Project Membership respectively.
- All `CLAUDE.md` §5 quality gates pass, including the full Phase 1–9 regression suite.

## Testing Expectations

- Project: create, list, view by `public_id`, internal-id rejection, update, lifecycle/status changes (including the `completed_date` requirement), client relationship (including invalid client `public_id` and internal/no-client projects), unique `project_code`, filters/search, deletion behavior (blocked while members exist, allowed once empty).
- Membership: add, list, duplicate rejected, role change, remove, invalid Staff/Project `public_id`, inactive/separated Staff rejected on new assignment.
- Authorization: Administrator, Manager, Staff member of a project, Staff non-member, no-role user (with and without a linked Staff record), unauthenticated, suspended account.
- Data integrity: Client deletion blocked while Projects reference it; Staff deletion blocked while Project Memberships reference it; Project deletion blocked while Memberships exist.
- Full Phase 1–9 regression suite continues passing unmodified in behavior.
