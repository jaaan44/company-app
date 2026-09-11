# Phase 7 — Staff — Specification

**Status:** COMPLETE
**Depends on:** Phase 6 (Organization Structure)

## Objective

Build the foundational Staff/Employee module for Company App: the canonical company personnel record, connected to the Phase 6 Organization Structure (Department/Team/Position) and optionally to a Phase 4/5 `User` login account. This is the personnel-directory and employment-profile foundation later modules (Staff Directory UI, Staff Status/Check-in, Projects, Leave, etc.) reference — not those modules themselves.

## In Scope

- `staff` table/model — `id`, `public_id` (ULID, DEC-017), `employee_number` (unique, admin-supplied — not system-generated), `first_name`, `last_name`, `preferred_name` (nullable), `company_email` (nullable, unique when present), `company_phone` (nullable), `status` (`App\Enums\StaffStatus`: `active`/`inactive`/`separated`), `hire_date` (nullable), `separation_date` (nullable), `department_id`/`team_id`/`position_id` (nullable FKs to Phase 6 tables, `restrictOnDelete`), `manager_id` (nullable, self-referencing FK to `staff.id`, `restrictOnDelete`), `user_id` (nullable, **unique** FK to `users.id`, `nullOnDelete`), timestamps.
- **Staff↔User separation:** `user_id` is nullable and unique — a Staff record may exist with no login account, and a User may exist with no Staff record (e.g. the seeded local Administrator). One User can link to at most one Staff record (enforced by a unique constraint, not merely convention). No authentication data is duplicated onto `staff`; `User` remains the sole authentication/account model.
- **Organization consistency:** a Staff record with both a `team_id` and a `department_id` must be consistent — if the assigned Team itself belongs to a Department, the Staff's `department_id` (when supplied) must match the Team's; when omitted, it's auto-derived from the Team.
- **Manager relationship:** self-referencing `manager_id`. A staff member cannot be their own manager; assigning a manager that would create a reporting cycle (walking the proposed manager's own chain, bounded — see Notes) is rejected. Full general-purpose cycle detection is not built.
- **Relational integrity extends into Phase 6:** `DepartmentController`/`TeamController`/`PositionController::destroy` are extended (a small, necessary Phase 6 touch) to also reject deletion (`409`) when Staff still reference that Department/Team/Position — the same protection Phase 6 already applies for Team/Position dependents.
- Two new permissions added to the existing `RolePermissionSeeder`: `staff.view` (attached to Manager and Staff — the Staff Directory is a company-wide, not admin-only, feature) and `staff.manage` (Administrator-only, via the existing centralized `Gate::before` override).
- Versioned REST API under `/api/v1/staff` — full CRUD, route-model-bound by `public_id`, permission-gated (`staff.view` reads / `staff.manage` writes). Status changes (including offboarding) go through the same `update` endpoint as Department/Team/Position's `status` field — no separate action route, matching the established Phase 6 pattern.
- Filtering: `?status=`, `?department=<public_id>`, `?team=<public_id>`, `?position=<public_id>`, `?manager=<public_id>`, and a simple `?q=` directory search (name/employee number) — genuinely useful for a personnel directory, not filters-for-completeness.
- `StaffResource` — a single, directory-shaped resource (not a separate "public" vs "admin" resource): name/contact/department/team/position/manager/status for anyone with `staff.view`; the linked `User`'s own identity (beyond a plain `has_user_account` boolean) is conditionally included only for a requester who also holds `staff.manage` — this is how "Staff Directory data" and "sensitive staff-management data" are kept distinct, per a single shared resource rather than two near-duplicate ones.
- Form Request validation: unique employee number; valid Department/Team/Position/Manager/User references (submitted and resolved by `public_id`, never an internal numeric id); Team/Department consistency; manager existence, non-self, and shallow-cycle rejection; unique User linkage; `separation_date >= hire_date` and required when `status` is `separated`.
- `StaffFactory` for tests. No production/demo seeder — Staff is business-owned personnel data, not fixed system catalog data (same reasoning as Phase 6's Department/Team/Position).
- Automated tests: CRUD, organization-relationship validation, User-relationship validation, manager/self-reference/cycle validation, authorization (Administrator/Manager/Staff/no-role/unauthenticated/suspended), and the extended Department/Team/Position delete-protection.

## Explicitly Out of Scope

- Payroll, salary/compensation, government/tax IDs, bank details.
- Attendance/timekeeping, biometric integration.
- Leave balances/leave requests (Phase 13).
- Employee documents, medical data, emergency contacts, performance reviews, recruitment, onboarding workflow, benefits, expense claims.
- Work logs, project assignment, task management (Phases 10–12).
- Messaging, notifications unrelated to Staff (Phases 15–16).
- Client management (Phase 8).
- Current/operational status tracking (available, on leave, in the field, off duty) and location check-ins — that's Phase 9; Phase 7's `status` is an **employment** lifecycle (active/inactive/separated), not day-to-day operational presence.
- Admin Backoffice (Blade/Livewire) CRUD UI — no such UI pattern exists yet for any module (Phase 6's precedent); this phase is API/backend only.
- Full general-purpose org-chart cycle detection, department hierarchy, or any many-to-many staff↔department/team structure.
- Auto-generated employee numbers — admin-supplied and validated unique in this phase.

## Relevant Documentation

- `docs/02_ARCHITECTURE.md` §4/§15 (Authorization), §16 (Organization Structure)
- `docs/03_DATABASE_MODEL.md` §1 (Identity & Organization — `staff`)
- `docs/04_API_CONVENTIONS.md` (Resource Naming — `/staff` is the document's own example; Filtering)
- `docs/05_SECURITY_MODEL.md` (Authorization, Least Privilege, Sensitive Information)
- `docs/DECISIONS.md` DEC-017 (identifiers), DEC-028 (authorization pattern), DEC-029 (organization structure)

## Acceptance Criteria

- Staff can be created, listed, viewed, updated, and deleted through versioned API endpoints, independently authorized server-side.
- A Staff record's Department/Team/Position/Manager/User references are always valid and internally consistent; invalid combinations are rejected with clear validation errors, not silently accepted or a raw DB error.
- A Department/Team/Position with Staff still assigned cannot be deleted; a Staff record with direct reports cannot be deleted.
- Manager/Staff can view the directory but not manage it; a user with no role can do neither; unauthenticated/suspended access is rejected.
- No internal numeric ID is ever exposed by an API response or accepted as client input — only `public_id`.
- All CLAUDE.md §5 quality gates pass, including the full Phase 1–6 regression suite.

## Testing Expectations

- Feature tests for Staff CRUD (list/create/view-by-public-id/update/status transition/delete), organization-relationship validation (valid and invalid Department/Team/Position references, Team/Department consistency), manager validation (self-reference, cycle, nonexistent manager), User-relationship validation (staff without a user, staff linked to a user, duplicate user linkage rejected), and directory filters/search.
- A cross-cutting authorization test file mirroring `OrganizationAuthorizationTest`.
- Extended `DepartmentTest`/`TeamTest`/`PositionTest` coverage: deletion is rejected when Staff still reference the record.
- Full Phase 1–6 regression suite continues passing unmodified in behavior.

## Notes

- "Shallow-cycle rejection" for the manager relationship means: when assigning a manager, walk that manager's own `manager_id` chain (bounded to a small fixed maximum, since a real ~100-person org's reporting depth is never close to it) and reject if the staff member being saved appears in that chain. This is a few lines of straightforward, maintainable code — not a general graph-cycle-detection subsystem — satisfying "prevent obviously invalid reporting structures" without over-engineering.
- `company_email`/`company_phone` are professional contact details for the directory, deliberately distinct from `User.email` (the login identity) — they are never synced automatically and may differ or be absent.
