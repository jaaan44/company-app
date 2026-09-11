# Phase 6 — Organization Structure — Specification

**Status:** COMPLETE
**Depends on:** Phase 5 (Roles & Permissions)

## Objective

Build the foundational organization structure for Company App: Departments, Teams, and Positions, and the relationships between them, as reusable master data for every later module (Staff, Staff Directory, Announcements scoping, etc.) that needs to reference "where in the company" something sits. This phase resolves `02_ARCHITECTURE.md` §9's open question ("Whether Departments/Teams need a dedicated hierarchy table or a simpler self-referencing structure") and `03_DATABASE_MODEL.md`'s open question ("Whether teams can span departments") with a design sized for ~100 employees — not a speculative hierarchy engine.

## In Scope

- `departments` table/model — `id`, `public_id` (ULID, DEC-017 — externally addressable, admin-manageable business data), `name` (unique), `description` (nullable), `status` (`active`/`inactive`, `App\Enums\OrganizationStatus`), `sort_order` (int, admin-controlled display order), timestamps.
- `teams` table/model — same identifier/status/ordering shape, plus a nullable `department_id` FK (a team belongs to **at most one** department, or none yet). Team `name` unique within its department scope (or within the "no department" scope).
- `positions` table/model — same shape, plus a nullable `department_id` FK. Position `title` unique within its department scope. Positions are standalone organizational/job-title master data in this phase — not linked to any staff record (none exists yet).
- Eloquent relationships: `Department::teams()`/`Department::positions()` (`hasMany`), `Team::department()`/`Position::department()` (`belongsTo`).
- Relational integrity: a Department cannot be deleted while it still has any Team or Position referencing it (enforced at the application layer with a clear `409` response, backed by a DB-level `restrictOnDelete()` foreign key as a defense-in-depth backstop). Teams/Positions may be freely deleted in this phase (nothing yet depends on them).
- Two new permissions, added to the existing `RolePermissionSeeder` (Phase 5 pattern): `organization.view` (list/view Departments/Teams/Positions) and `organization.manage` (create/update/delete them). `organization.view` is attached to the existing Manager and Staff roles (viewing the company's org structure is low-sensitivity, broadly useful company metadata); `organization.manage` is Administrator-only, via the existing centralized `Gate::before` override — not explicitly attached to any role (DEC-028's established pattern).
- Versioned REST API endpoints under `/api/v1`: `departments`, `teams`, `positions` — full CRUD (`index`, `show`, `store`, `update`, `destroy`), route-model-bound by `public_id` (never the internal numeric id), permission-gated (`organization.view` for reads, `organization.manage` for writes), behind the existing `auth:sanctum` + `account.active` chain.
- Filtering: `?department=<public_id>` (Teams/Positions) and `?status=active|inactive` (all three) — matching `04_API_CONVENTIONS.md`'s "top-level filterable resource" guidance rather than deep nesting.
- Form Request validation for all writes, including safe resolution of a client-supplied `department_id` (submitted as the department's public ULID, never its internal numeric id) and department-scoped uniqueness checks.
- API Resources exposing only `public_id` (never internal ids), with a minimal nested `department` (public_id + name) on Team/Position.
- Factories for automated testing.
- Automated tests: CRUD, validation, relational-integrity (delete protection), and permission enforcement (Administrator/Manager/Staff/no-role/unauthenticated/suspended-account), following the existing `tests/Feature/Authorization/*` and `tests/Feature/Api/V1/*` patterns.
- Documentation updates per CLAUDE.md §6.

## Explicitly Out of Scope

- Staff/Employee management, employment records, manager relationships, or any staff↔department/team/position assignment (that's Phase 7 — Staff). Positions exist here purely as organizational master data.
- Clients, Projects, Leave, Tasks, Messaging, Attendance, Payroll, or any later business module.
- Department hierarchy (sub-departments/parent-child departments) — flat, single-level Departments only, per the resource-efficiency direction (`02_ARCHITECTURE.md` §0) and the ~100-employee scale.
- Teams spanning multiple departments (many-to-many) — a Team belongs to at most one Department.
- Admin Backoffice (Blade/Livewire) CRUD screens — no such UI pattern exists yet for any module (Phase 5 also deliberately shipped no management UI); this phase provides the API/backend foundation, consistent with the established incremental pattern. Building this UI is future work.
- Soft-deletes — the `status` (`active`/`inactive`) column is the one lifecycle/retirement mechanism; layering `SoftDeletes` on top would be a second, overlapping lifecycle concept for no demonstrated need at this phase.
- Any generic/polymorphic organization-hierarchy or ACL infrastructure.

## Relevant Documentation

- `docs/02_ARCHITECTURE.md` §4 (Authorization Layer), §9 (Open Questions), §12 (Identifiers, DEC-017)
- `docs/03_DATABASE_MODEL.md` §1 (Identity & Organization — `departments`, `teams`, `positions`)
- `docs/04_API_CONVENTIONS.md` (Resource Naming, Filtering, Success Response Shape)
- `docs/05_SECURITY_MODEL.md` (Authorization, Least Privilege)
- `docs/DECISIONS.md` DEC-017 (identifier strategy), DEC-028 (authorization pattern)

## Acceptance Criteria

- Departments/Teams/Positions can be created, listed, viewed, updated, and deleted through versioned API endpoints, each independently authorized server-side.
- A Department with any Team or Position still referencing it cannot be deleted (`409`, tested).
- Manager/Staff can view organization structure but cannot create/update/delete it; a user with no role can do neither; unauthenticated requests are rejected; a suspended/inactive account loses access even mid-session (mirroring the existing `account.active` pattern).
- No internal numeric ID is ever exposed by an API response or accepted as client input — only `public_id`.
- All CLAUDE.md §5 quality gates pass, including the full Phase 1–5 regression suite.

## Testing Expectations

- Feature tests per resource (Department/Team/Position): index/show/store/update/destroy happy paths, validation failures (missing/duplicate name, invalid `department_id`), filtering by `department`/`status`.
- A dedicated cross-cutting authorization test file mirroring `AdminBackofficeAuthorizationTest`, covering Administrator/Manager/Staff/no-role/unauthenticated/suspended access to both read and write endpoints.
- Relational-integrity tests: deleting a Department with dependents is rejected; deleting one without dependents succeeds; deleting a Team/Position never affects its Department.
- `RolePermissionSeederTest` extended to cover the two new permissions and their Manager/Staff attachment.

## Notes

- `department_id` on Team/Position is deliberately nullable — an org unit can exist before it's assigned to a department (e.g. initial company setup), and Positions are explicitly allowed to exist independently per the governing instructions.
- Manager does not receive `organization.manage` in this phase — scoping management to "a manager's own department/team" would require row-level ownership logic not specified by this phase and is deferred until a real requirement (and Staff/manager-relationship data from Phase 7) exists.
