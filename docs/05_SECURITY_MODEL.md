# 05 — Security Model (Initial)

Status: **Strategy and principles only.** No authentication/authorization implementation exists yet. This document should not be read as a claim that any of these protections currently exist in code — it is the target to build toward, phase by phase. Do not overstate guarantees to users or the product owner based on this document alone.

## Authentication

- Token-based authentication suitable for both the Flutter app and the Admin Backoffice (mechanism confirmed at the Authentication phase, e.g. Laravel Sanctum).
- Passwords hashed with a strong, framework-standard algorithm (Laravel default: bcrypt/argon2) — never rolled by hand.
- Account lockout / throttling on repeated failed logins is expected (see Rate Limiting below), tuned when the Authentication phase is built.

## Authorization

- **Permission-oriented, not purely role-hardcoded** (DEC-004). Every protected action checks a specific permission (e.g. `leave.approve`), not just "is this user an Administrator."
- Roles are named bundles of permissions for manageability, not the authorization primitive itself.
- **Data isolation is deliberate**, not implicit: e.g. a Supervisor's visibility into staff records, work logs, or leave requests must be explicitly scoped (own team vs. everyone) at the query/policy level, not just hidden in the UI. Server-side enforcement is mandatory; the UI hiding a button is never sufficient.

## Least Privilege

- Default-deny: new permissions/endpoints are inaccessible until explicitly granted to a role.
- Super Administrator access is expected to be rare and itself auditable (see Audit Logging).

## Account States

- Staff/user accounts need at least: active, suspended, and (likely) a distinct "offboarded/inactive" state — distinguishing "temporarily blocked" from "no longer employed." Exact state machine confirmed at the Staff / Authentication phases.
- A suspended/inactive account must lose API access immediately, not just UI visibility.

## Administrative Access

- Administrative actions (staff suspension, role changes, settings changes, data exports) are higher-risk and are candidates for stricter checks (e.g. requiring a specific elevated permission, and always audit-logged).

## API Access

- The API is the enforcement boundary (see `04_API_CONVENTIONS.md`) — every endpoint independently authorizes, regardless of what the calling client (mobile/admin) already filtered client-side.
- Tokens scoped appropriately per client type where the auth mechanism supports it (e.g. Sanctum token abilities), to limit blast radius of a leaked mobile token vs. an admin session.
- **`GET /api/v1/health` (Phase 3) is the one deliberate exception** — intentionally public, no authentication middleware, and returns only `{status, timestamp}` (no environment, database, dependency-version, or configuration details). Any future unauthenticated endpoint must be an equally deliberate, narrow, documented exception — not a default.

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

- Standard Laravel throttling middleware on authentication endpoints and, where appropriate, on write-heavy or abuse-prone endpoints (e.g. messaging). Exact thresholds tuned per endpoint when built, not decided in the abstract here.

## Secrets & Configuration

- Secrets live in environment configuration (`.env`, or a proper secrets manager in production), never committed to the repository.
- Environment-specific config (API keys, DB credentials, mail/queue credentials) follows standard Laravel config practice — no hardcoded credentials in code at any point.

## Production Environment Separation

- Distinct configuration and credentials per environment (local/staging/production) — non-negotiable before any real deployment. Staging/production separation is expected to be established no later than the Development Environment/CI phase and the Staging Deployment phase respectively.

## Honesty Clause

This document describes the **intended** security posture. Any session working on a security-relevant phase must update this document to reflect what is actually implemented and tested, and must not claim a protection exists (in this doc, in `CURRENT_STATE.md`, or to the user) unless it has actually been built and, ideally, verified.
