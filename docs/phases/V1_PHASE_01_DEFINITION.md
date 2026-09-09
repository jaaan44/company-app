# Phase 1 — Project Bootstrap — Specification

**Status:** COMPLETE
**Depends on:** Phase 0

## Objective

Bootstrap the actual application codebase (Laravel backend/API and Flutter staff mobile app) under a clean monorepo structure, establishing a healthy, lean development baseline for both applications — no business functionality.

## In Scope

- Monorepo structure: `apps/api` (Laravel), `apps/mobile` (Flutter), `docs/` (existing).
- Laravel application bootstrap under `apps/api`: framework install, `.env.example`, generated `.env`/app key locally (not committed), framework-default migrations only, dependency install verified, Pint formatting verified, baseline test suite verified.
- Flutter application bootstrap under `apps/mobile`: project scaffold for Android/iOS, demo counter content replaced with a minimal neutral shell (no Company App feature screens), dependency resolution verified, `dart format`/`flutter analyze`/`flutter test` verified.
- Environment/secret safety: confirm `.env` and other secret-bearing files are git-ignored; only safe example config committed.
- Root and technical documentation updated to reflect the real repository structure and the versions/commands actually in use.

## Explicitly Out of Scope

- Any Company App business module (Staff, Clients, Projects, Tasks, Leave, Messaging, Service Reports, Incidents, custom RBAC, business APIs, Admin dashboards, domain migrations).
- Authentication implementation (Phase 4).
- Full CI pipeline (Phase 2) — Laravel's upstream skeleton bundled a full GitHub Actions CI matrix, dependabot automation, and issue/PR bots; these were deliberately removed as premature (see Deviations in the Phase 1 handoff).
- Redis, WebSockets, real-time/broadcast infrastructure, or queue drivers beyond Laravel's framework defaults (database-backed queue/cache/session — no Redis required to run this baseline).
- Deciding PostgreSQL vs. MySQL for production — local bootstrap uses SQLite (Laravel's own default for a fresh install); this does not resolve the open `02_ARCHITECTURE.md` §9 question.
- Deciding the Admin Backoffice's rendering approach (Blade/Livewire/Inertia/SPA) — only its *location* (inside `apps/api`) is confirmed by the monorepo structure itself.
- Monorepo orchestration tooling (Nx/Turborepo/Melos) — not introduced; Laravel and Flutter operate independently.

## Acceptance Criteria

See AC-01 through AC-15 in the governing Phase 1 instruction; mirrored and checked off in `docs/handoffs/V1_PHASE_01_HANDOFF.md`.

## Validation Requirements

**Laravel (`apps/api`):** `composer install`/`composer validate`, `php artisan test`, `vendor/bin/pint --test`. All executed; results in the handoff.

**Flutter (`apps/mobile`):** `flutter pub get`, `dart format --set-exit-if-changed`, `flutter analyze`, `flutter test`. All executed; results in the handoff.

## Notes

- Environment note: this session's container did not have the Flutter/Dart SDK preinstalled. It was installed at `/opt/flutter` (stable channel, cloned from the official `flutter/flutter` GitHub repository) to satisfy this phase's Flutter validation requirements. This is a session/environment detail, not a repository dependency — nothing in the repository assumes this specific install path.
- Laravel's `composer create-project laravel/laravel` skeleton included two AI-agent bootstrap stub files (`CLAUDE.md`, `AGENTS.md`, identical "Laravel Boost" content instructing installation of an unrequested `laravel/boost` package) and a full upstream CI/maintenance toolchain (`.github/workflows/*`, `.github/dependabot.yml`, `.styleci.yml`, and a `CHANGELOG.md` that was actually the upstream `laravel/laravel` template repo's own release notes). All were removed — they conflict with this repository's single-`CLAUDE.md` governance model (DEC-001) and/or are premature CI (out of Phase 1 scope; belongs to Phase 2 if adopted at all, per repository resource-efficiency direction).
