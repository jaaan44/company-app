# 05 — Security Model (Initial)

Status: **Authentication implemented as of Phase 4, Authorization (RBAC foundation) implemented as of Phase 5, first permission-gated business endpoints as of Phase 6** (see `docs/handoffs/V1_PHASE_04_HANDOFF.md`, `docs/handoffs/V1_PHASE_05_HANDOFF.md`, `docs/handoffs/V1_PHASE_06_HANDOFF.md`). Do not overstate guarantees to users or the product owner beyond what's actually built and tested.

## Authentication

- **Implemented (DEC-022):** the Admin Backoffice authenticates via Laravel's session/secure-cookie `web` guard (Blade + Livewire login). The Flutter mobile app authenticates via Laravel Sanctum personal access tokens (`Authorization: Bearer <token>`) — bearer-token only, no cookie-based SPA/stateful authentication. No OAuth server, JWT infrastructure, or Passport.
- Passwords hashed with Laravel's default facilities (bcrypt, `password` cast to `hashed`) — never rolled by hand, never logged, never returned in any response (`UserResource` exposes only `public_id`, `name`, `email`, `status`).
- **Implemented:** rate limiting on both login surfaces — see Rate Limiting below.
- **No public self-registration (DEC-023):** no `POST /register` route on either surface; accounts are company-provisioned.
- **Not implemented (deliberately deferred):** password reset and email verification workflows. `email_verified_at` is retained on `users` for future use but nothing populates or checks it yet.

## Authorization

- **Permission-oriented, not purely role-hardcoded** (DEC-004). Every protected action checks a specific permission (e.g. `leave.approve`), not just "is this user an Administrator."
- Roles are named bundles of permissions for manageability, not the authorization primitive itself.
- **Implemented as of Phase 5 (DEC-028):** `roles` (`Administrator`/`Manager`/`Staff`, a fixed V1 catalog), `permissions` (dot-notation identifiers), and a many-to-many `role_permissions` join. **One role per user** — `users.role_id`, nullable — not a many-to-many `users`↔`roles` table; a user with no role has no permissions (default-deny).
  - **Centralized enforcement:** a single `Gate::before` callback (`App\Providers\AppServiceProvider::boot()`) is the one place Administrator's "may do everything" behavior is expressed — not `if ($user->is_admin)`/`if ($user->role === 'admin')` checks scattered through controllers, Livewire components, or Blade views. Every other user's ability check resolves against their role's attached permissions via `App\Models\User::hasPermission()`.
  - **Reusable across every Laravel authorization surface:** because this is a real `Gate::before` override (not a bespoke helper function), Laravel's built-in `can` route middleware, `Gate::allows()`/`authorize()`, Blade's `@can`, and `$user->can()`/`$user->cannot()` inside a future Policy all work against permission names with zero per-permission `Gate::define` boilerplate. A future developer protects a new action by asking "does Laravel already have a mechanism for this surface (route, Blade view, Policy method)?" and calling `can('module.action')` through it — not by inventing a new check.
  - **Foundational permission catalog only** — `admin.access`, `authorization.manage` — enough to prove the mechanism; each future module's permissions arrive with that module, not speculatively here (CLAUDE.md §7).
  - Manager and Staff hold **no permissions** as of Phase 5 — acceptable (CLAUDE.md §10); this is not a gap, it's the correct default-deny starting state until real modules attach real permissions to these roles.
  - **Implemented as of Phase 6 (DEC-029):** `organization.view`/`organization.manage` (Departments/Teams/Positions) — the first real permissions attached to Manager/Staff (`organization.view` only; viewing the company's org structure is low-sensitivity company metadata). `organization.manage` (create/update/delete) remains Administrator-only via the same centralized override — no new authorization mechanism was introduced.
- **Data isolation is deliberate**, not implicit: e.g. a Supervisor's visibility into staff records, work logs, or leave requests must be explicitly scoped (own team vs. everyone) at the query/policy level, not just hidden in the UI. Server-side enforcement is mandatory; the UI hiding a button is never sufficient. Not yet applicable — no module with row-level ownership exists yet.

## Least Privilege

- Default-deny: new permissions/endpoints are inaccessible until explicitly granted to a role.
- Super Administrator access is expected to be rare and itself auditable (see Audit Logging).

## Account States

- **Implemented (Phase 4):** `users.status` is one of `active`, `suspended`, `inactive` (`App\Enums\AccountStatus`) — distinguishing "temporarily blocked" from "no longer employed" is deferred to whenever a real offboarding workflow is designed; for now `inactive` and `suspended` both simply block authentication identically.
- Active may authenticate normally. Suspended and inactive may not start a new session/token — enforced explicitly in `AuthController::login` (API) and `LoginForm::login` (Admin), returning a generic-but-honest message rather than a raw 500 or misleading "invalid credentials."
- **A suspended/inactive account also loses already-authenticated access**, not just the ability to log in again: a single `App\Http\Middleware\EnsureAccountIsActive` (alias `account.active`) is applied to `GET /api/v1/auth/me` and the Admin `/home` placeholder. For the API it revokes the current Sanctum token and returns `403`; for the Admin session it logs the user out and redirects to `/login`. Deliberately not applied to either surface's logout route — revoking one's own access is always allowed.
- This is centralized in one middleware plus `User::isActive()`, not scattered per-controller checks (CLAUDE.md §7).

## Administrative Access

- Administrative actions (staff suspension, role changes, settings changes, data exports) are higher-risk and are candidates for stricter checks (e.g. requiring a specific elevated permission, and always audit-logged).
- **Implemented as of Phase 5 (DEC-028), retiring the Phase 4 transitional `is_admin` flag (DEC-024):** the Admin Backoffice's access chain is `auth` → `account.active` → `can:admin.access` → `/home` (`routes/web.php`), enforced both at login (`App\Livewire\Auth\LoginForm`) and on every subsequent request to `/home` via Laravel's built-in `can` route middleware — not a one-time login-only check. `is_admin` no longer exists on `users`; there is no remaining reference to it in application authorization logic.

## API Access

- The API is the enforcement boundary (see `04_API_CONVENTIONS.md`) — every endpoint independently authorizes, regardless of what the calling client (mobile/admin) already filtered client-side.
- **Implemented as of Phase 5:** `UserResource` exposes a stable `role` field (the role's *name*, e.g. `"administrator"` — never the internal numeric `role_id`) for legitimate future mobile UI use (e.g. role-aware navigation).
- **Implemented as of Phase 6:** the first permission-gated endpoints — `/api/v1/departments`, `/teams`, `/positions` — via `Route::middleware(['auth:sanctum', 'account.active', 'can:organization.view'])` (reads) and `can:organization.manage` (writes), exactly the pattern Phase 5 anticipated. `Department`/`Team`/`Position` resources and route model bindings expose/accept only `public_id`, never an internal numeric id — including a client-supplied `department_id`, which is resolved server-side from the submitted public ULID.
- Tokens scoped appropriately per client type where the auth mechanism supports it (e.g. Sanctum token abilities), to limit blast radius of a leaked mobile token vs. an admin session — no token abilities are defined yet (single mobile client type; revisit if a second API-consuming client type is added).
- **`GET /api/v1/health` (Phase 3) and `POST /api/v1/auth/login` (Phase 4) are the deliberate public exceptions** — health returns only `{status, timestamp}`; login is otherwise unauthenticated by necessity but rate-limited and returns a generic failure message that never confirms or denies whether a given email is registered. Any future unauthenticated endpoint must be an equally deliberate, narrow, documented exception — not a default.
- `POST /api/v1/auth/logout` revokes only the token used for the request (`$request->user()->currentAccessToken()->delete()`), not every device's token — a deliberate choice leaving room for future multi-device use without inventing a session-management UI in this phase.

## Input Validation

- All input validated server-side via Form Requests (see `04_API_CONVENTIONS.md`) — never trust client-side validation alone.
- Standard Laravel protections (mass-assignment protection via `$fillable`/`$guarded`, parameterized queries via Eloquent/query builder) are the baseline; no raw SQL string interpolation.

## File Uploads

- Validate file type, size, and (where feasible) content — not just extension — before storage.
- Store uploads outside the public webroot by default, serving via authenticated/authorized routes rather than direct public URLs, unless a specific attachment type is deliberately public (e.g. a public announcement image) — decided per use case, not by default.
- Antivirus/malware scanning is a **future consideration**, not a V1 commitment — note this honestly rather than implying it exists.

## Sensitive Information

- PII (staff personal details, leave reasons, incident details) is handled with the same permission-scoping discipline as any other data — visibility is permission-gated, not "logged-in users can see everything."
- Avoid storing sensitive data in **logs**; be deliberate about what audit log entries capture (metadata about the change, not necessarily full sensitive payloads).

## Audit Logging

- Per DEC-009: administrative and workflow-significant actions are expected to eventually be traceable (actor, action, subject, timestamp, and relevant before/after context).
- Audit logging is intended as shared infrastructure introduced early (see `02_ARCHITECTURE.md` / `03_DATABASE_MODEL.md`) so later modules use a common mechanism rather than each inventing its own.
- Audit logs themselves are administrator-readable only, not general staff-visible.

## Location Data

- V1 favors **explicit check-ins**, not continuous location tracking (DEC-005). This is both a product and a privacy stance — staff location data is only captured at deliberate check-in moments, and retention/visibility of that data should be scoped (e.g. team lead visibility, not company-wide) when the Location Check-in phase is designed.

## Messaging Privacy

- Direct and group messages are visible only to their participants; project conversations are visible to project members. No general "admin can read all messages" default — if administrative access to messages is ever needed (e.g. for a formal investigation), it should be a deliberate, audited, and likely permission-gated capability, not an incidental side effect of admin access elsewhere.

## Rate Limiting

- **Implemented (Phase 4):** both login surfaces are limited to 5 attempts per minute, keyed by `email|ip` (a single named `login` limiter, `App\Providers\AppServiceProvider::boot()`), so one abusive client can't lock out another legitimate user of the same account. The API route (`POST /api/v1/auth/login`) uses the `throttle:login` route middleware; the Admin Livewire component enforces the same limiter directly in `LoginForm::login()` (a route-level `throttle` middleware would not see Livewire's internal AJAX update requests). Exceeding the limit returns `429 Too Many Attempts` (API) or a form validation error (Admin).
- Thresholds for future write-heavy or abuse-prone endpoints (e.g. messaging) remain to be tuned per endpoint when built, not decided in the abstract here.

## Client-Side Token Storage

- **Implemented (Phase 4, DEC-026):** the Flutter app persists its Sanctum bearer token via `flutter_secure_storage` (iOS Keychain; Android EncryptedSharedPreferences/Keystore) — never plain `SharedPreferences`, source code, or an unencrypted file.

## Secrets & Configuration

- Secrets live in environment configuration (`.env`, or a proper secrets manager in production), never committed to the repository.
- Environment-specific config (API keys, DB credentials, mail/queue credentials) follows standard Laravel config practice — no hardcoded credentials in code at any point.

## Production Environment Separation

- Distinct configuration and credentials per environment (local/staging/production) — non-negotiable before any real deployment. Staging/production separation is expected to be established no later than the Development Environment/CI phase and the Staging Deployment phase respectively.

## Honesty Clause

This document describes the **intended** security posture. Any session working on a security-relevant phase must update this document to reflect what is actually implemented and tested, and must not claim a protection exists (in this doc, in `CURRENT_STATE.md`, or to the user) unless it has actually been built and, ideally, verified.
