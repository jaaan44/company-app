# CURRENT STATE

*Read this first. Kept intentionally short — for depth, follow the pointers, don't expect this file to contain everything.*

**Product:** Company App — internal operations & communication platform
**Current phase:** Phase 4 — Authentication
**Phase status:** COMPLETE (pending user review)
**Last completed phase:** Phase 4 (Phases 1–3 are merged into `main`)
**Next planned phase:** Phase 5 — Roles & Permissions (see `ROADMAP.md`) — **not authorized yet**

## Current Objective

Awaiting review of Phase 4 and authorization for Phase 5.

## Completed

- **Phase 0:** Full documentation/governance foundation.
- **Phase 1:** Monorepo bootstrap — `apps/api` (Laravel) and `apps/mobile` (Flutter). Merged into `main`.
- **Phase 2:** Development environment & CI (Larastan, GitHub Actions). Merged into `main`.
- **Phase 3:** Core architecture — `/api/v1` routing, health endpoint, MySQL production direction (DEC-016), numeric+ULID identifier strategy (DEC-017), Blade+Livewire Admin direction (DEC-019). Merged into `main`.
- **Phase 4:** Authentication. See `docs/handoffs/V1_PHASE_04_HANDOFF.md` for full detail.
  - Backend: `users` table gained `public_id` (ULID), `status` (`active`/`suspended`/`inactive`), and a transitional `is_admin` boolean. Laravel Sanctum installed (DEC-022) for mobile bearer-token auth; `personal_access_tokens` table added.
  - Admin Backoffice: Blade + Livewire login (`App\Livewire\Auth\LoginForm`), session regeneration, logout, a protected `/home` placeholder, account-state and `is_admin`-gated access, rate limiting.
  - Mobile API: `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/me`, all under `/api/v1/auth`. Account status (`account.active` middleware) enforced on already-authenticated access, not just login.
  - No public self-registration (DEC-023); accounts are seeded via a local-only `AdminUserSeeder`.
  - Flutter: `lib/features/auth/` — login screen, `AuthApiClient` (`package:http`), `AuthController` (`ChangeNotifier`, DEC-025), secure token storage (`flutter_secure_storage`, DEC-026), `AuthGate` flow. Existing `HomePage` placeholder reused as the authenticated destination with a logout action added.
  - All local checks pass, including `vendor/bin/phpstan analyse` — this session was able to fully recover/verify the backend toolchain locally (see Known Blockers) — and the full Flutter toolchain (`flutter analyze`, `flutter test`) after fetching Flutter 3.47.2 into this sandbox.
  - No RBAC, Staff Management, or other future-phase business functionality was introduced.

## Pending / Not Started

- Roles & Permissions (Phase 5) and everything after it on the roadmap.

## Known Blockers / Issues

- **Session-specific, resolved this phase:** earlier phases (2–3) could not run `vendor/bin/phpstan analyse` locally because this sandbox's outbound access to `api.github.com` (needed for Composer's dist-zip downloads) is unreliable/blocked, and relied on GitHub Actions for that one check. This session hit the same limitation — more severely, an interrupted `composer require laravel/sanctum` briefly left the entire `vendor/` directory in a broken state — but fully recovered it (and PHPStan itself) using this sandbox's working plain `git clone` access to GitHub's git protocol (as opposed to Composer's API-based dist downloads) to manually restore/verify packages at their exact locked commits, then let Composer regenerate its own metadata locally. `composer.json`/`composer.lock` are correctly resolved regardless of this session's local recovery; GitHub Actions remains the authoritative, unrestricted-network verification. See the Phase 4 handoff §20 for the full account.
- **New this session:** the Flutter SDK was not preinstalled in this sandbox (earlier phases' sessions apparently had it available). Flutter 3.47.2 (matching the project's pinned version exactly) was fetched via `git clone` from the official `flutter/flutter` repository to run the real toolchain locally rather than relying solely on CI.
- Open design questions: real-time transport, object storage provider, Departments/Teams hierarchy shape — see `docs/02_ARCHITECTURE.md` §9.

## Repository / Branch Information

- Repository: `jaaan44/company-app`
- Default branch: `main` (contains the approved Phase 0–3 baseline)
- Phase 4 branch: `claude/v1-phase-04-authentication-grt1ca` (branched from `main`, not merged)

## Latest Relevant Handoff

`docs/handoffs/V1_PHASE_04_HANDOFF.md`

## For the Next Session

Read `CLAUDE.md`, then this file, then `docs/ROADMAP.md`, then `docs/handoffs/V1_PHASE_04_HANDOFF.md` if working on anything authentication- or authorization-related. Phase 5 (Roles & Permissions) needs explicit user authorization before any implementation starts — do not begin it based on the roadmap alone.
