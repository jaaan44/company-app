# Phase 5 — Roles & Permissions — Specification

**Status:** COMPLETE
**Depends on:** Phase 4 (Authentication)

## Objective

Establish the reusable authorization foundation for Company App: what roles a user can have, what permissions each role grants, how Laravel consistently authorizes protected actions, and how the transitional Phase 4 `users.is_admin` flag is retired in favor of a real (but deliberately small) permission-based mechanism. This phase builds the authorization *foundation*, not any future business module's permission catalog.

## In Scope

- A small V1 role catalog — `Administrator`, `Manager`, `Staff` — stored in a `roles` table, one role per user (`users.role_id`, nullable FK).
- A `permissions` table, conceptually distinct from roles, with a many-to-many `role_permissions` join table.
- A minimal, foundational permission catalog sufficient to prove the mechanism: `admin.access` (may enter the Admin Backoffice), `authorization.manage` (may manage users' role/authorization assignments — mechanism only, no UI yet).
- A centralized, reusable enforcement pattern: a single `Gate::before` callback (`App\Providers\AppServiceProvider::boot()`) that (a) grants every ability to users holding the Administrator role, and (b) otherwise resolves any ability name against the authenticated user's role-derived permission set. This makes Laravel's own `can` middleware, `Gate::allows()`/`authorize()`, `@can` in Blade, and `$user->can()` inside Policies all work against permission names with no per-permission `Gate::define` boilerplate.
- Retirement of `users.is_admin`: the column is dropped; existing `is_admin = true` rows are migrated onto the Administrator role; the Admin Backoffice login check and the `/home` route middleware both move to permission-based checks (`admin.access`).
- Admin Backoffice access chain: `auth` → `account.active` → `can:admin.access` → `/home`.
- `App\Models\Role` and `App\Models\Permission` Eloquent models; `User::role()`, `User::hasRole()`, `User::hasPermission()`.
- A deterministic, idempotent `RolePermissionSeeder` (safe system data, run in every environment via `DatabaseSeeder`), separate from the local-only, unsafe-by-design `AdminUserSeeder` (updated to assign the Administrator role instead of `is_admin`).
- `UserResource` gains a stable `role` name (e.g. `"administrator"`) — never the internal numeric `role_id`.
- Automated tests covering the authorization mechanism itself (Gate/role/permission behavior), not just UI visibility.

## Explicitly Out of Scope

- Staff, Clients, Projects, Leave, Tasks, Work Logs, Messaging, or any later business module — including their permissions.
- Many-to-many user↔role assignment (multiple roles per user) — V1 is one role per user.
- Department/Team structures.
- A full permission catalog for modules that don't exist yet — only enough permissions to prove the architecture.
- Third-party RBAC packages, external IAM, an OAuth authorization server, a policy engine service, Redis-backed permission caching, or multi-tenant/organization-level ACL infrastructure.
- Any Admin Backoffice UI for managing roles/permissions/users (that's a future phase; `authorization.manage` exists as a permission identifier only).
- Redesigning login, Sanctum, secure token storage, or account-status behavior beyond what's strictly required to replace `is_admin`.

## Relevant Documentation

- `docs/02_ARCHITECTURE.md` §4 (Authorization Layer)
- `docs/03_DATABASE_MODEL.md` §1 (Identity & Organization — `roles`, `permissions`)
- `docs/05_SECURITY_MODEL.md` (Authorization, Least Privilege, Administrative Access)
- `docs/DECISIONS.md` DEC-004 (granular authorization), DEC-022–DEC-027 (Phase 4/4A)

## Acceptance Criteria

See CLAUDE.md's governing Phase 5 instructions §22 — all satisfied; see `docs/handoffs/V1_PHASE_05_HANDOFF.md` for the evidence (commands run, exact results, CI status).

## Testing Expectations

- Backend: Administrator/Manager/Staff role behavior, default-deny permission resolution, Administrator's centralized override, Admin Backoffice access/denial by role and by account status, `is_admin` fully absent from schema/model/behavior, `AdminUserSeeder` assigns the Administrator role correctly — all as automated PHPUnit feature/unit tests.
- Existing Phase 3/4 tests (health endpoint, admin login, API login/me/logout) continue passing unmodified in behavior, only updated where they referenced the retired `is_admin`/`admin()` factory state.

## Notes

- Administrator's "may do everything" behavior is implemented as a single centralized `Gate::before` override, not scattered `if ($user->is_admin)`/`if ($user->role === 'admin')` checks — see `docs/05_SECURITY_MODEL.md` and DEC-028.
- Manager and Staff intentionally start with no attached permissions in V1 — this is acceptable per the governing instructions and is not a defect; later phases attach real permissions to these roles as their modules are built.
