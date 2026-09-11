# Phase 4 — Authentication — Specification

**Status:** COMPLETE
**Depends on:** Phase 3 (Core Architecture)

## Objective

Establish the first real security/business functionality: who a user is, how they prove it, and the minimum account-state and Admin-access distinctions needed to use that identity safely — for both the Admin Backoffice (Blade + Livewire, session/cookie auth) and the Flutter mobile app (Laravel Sanctum bearer tokens). No authorization/RBAC, no Staff domain, no other business module.

## In Scope

- `users` table gains `public_id` (ULID), `status` (`active`/`suspended`/`inactive`), and a transitional `is_admin` boolean.
- Laravel Sanctum installed and configured for bearer-token API authentication.
- Admin Backoffice: Blade + Livewire login page, session regeneration on success, logout, a protected neutral placeholder (`/home`), guest/auth route protection.
- Mobile API: `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/me`.
- Central account-status enforcement (`EnsureAccountIsActive` middleware) applied to already-authenticated access, not just login.
- Rate limiting on both login surfaces.
- Flutter: login screen, `AuthApiClient`, `AuthController` (`ChangeNotifier`), secure token storage, `AuthGate` flow (loading → login/home), logout.
- A documented, local-only seeder for the first Admin account.
- Automated tests (backend PHPUnit, Flutter `flutter_test`) covering the acceptance criteria below.

## Explicitly Out of Scope

- Roles & Permissions / RBAC (Phase 5) — `is_admin` is a deliberately temporary stand-in, not a permissions system.
- The Staff domain table/profile — authentication identity (`users`) and employee profile remain separate (per `03_DATABASE_MODEL.md`).
- Password reset — not required by this specification; documented as future work.
- Email verification workflow — the `email_verified_at` column is retained but unused; no verification emails are sent.
- Any other business module (Clients, Projects, Tasks, Leave, Messaging, etc.).
- Multi-device/refresh-token infrastructure beyond what Sanctum provides natively.
- The real Admin Dashboard and any Admin business navigation.

## Relevant Documentation

- `docs/02_ARCHITECTURE.md` §3 (API layer / auth), §12 (core conventions)
- `docs/03_DATABASE_MODEL.md` §1 (Identity & Organization — `users`)
- `docs/04_API_CONVENTIONS.md` (response shapes, error conventions, rate limiting)
- `docs/05_SECURITY_MODEL.md` (Authentication, Account States, Rate Limiting, API Access)
- `docs/DECISIONS.md` DEC-022 through DEC-026

## Acceptance Criteria

See CLAUDE.md's governing Phase 4 instructions, AC-01 through AC-20 — all satisfied; see `docs/handoffs/V1_PHASE_04_HANDOFF.md` for the evidence (commands run, exact results, CI status).

## Testing Expectations

- Backend: admin login/logout/session-regeneration/suspended-inactive-denied/unauthenticated-redirect; API login/logout/me/token-revocation/rate-limiting/no-registration — all as automated PHPUnit feature tests.
- Flutter: unauthenticated shows login, loading state, successful auth transition, invalid credentials remain logged out, logout returns to login, token restoration on boot — as automated `flutter_test` widget/unit tests, with the network and secure-storage boundaries faked (no live server dependency).

## Notes

- `is_admin` is intentionally minimal and will be replaced by Phase 5's permission-based authorization — this is recorded so no future session mistakes it for a permanent design choice.
- Password reset and email verification are open items for a future phase's authorization; not addressed here per the governing instructions.
