# CHANGELOG

Notable repository-level changes. Follows a simple date-ordered log; not tied to semantic versioning while pre-release.

## [Unreleased]

### 2026-09-09 — Phase 1: Project Bootstrap
- Established monorepo structure: `apps/api` (Laravel) and `apps/mobile` (Flutter).
- Bootstrapped Laravel 13.31.0 (PHP 8.4.19) under `apps/api` via `composer create-project laravel/laravel`. Framework-default migrations only (users, cache, jobs); no business tables. Local dev database is SQLite (DEC-012) — production engine remains open.
- Removed from the Laravel skeleton: `CLAUDE.md`/`AGENTS.md` "Laravel Boost" AI-agent bootstrap stubs (conflicted with this repo's single-`CLAUDE.md` governance, DEC-001), and the upstream `laravel/laravel` template repo's own CI/maintenance tooling (`.github/workflows/*`, `.github/dependabot.yml`, `.styleci.yml`, `CHANGELOG.md`) as premature/out-of-scope CI.
- Verified: `composer validate --strict`, `vendor/bin/pint --test`, `php artisan test` — all pass.
- Bootstrapped Flutter 3.47.2 (Dart 3.13.2) under `apps/mobile` via `flutter create` (Android + iOS platforms). Replaced the default demo counter app with a minimal neutral shell (`CompanyApp` / `BootstrapHomePage`) — no Company App feature screens.
- Verified: `dart format`, `flutter analyze`, `flutter test` — all pass.
- Added root `.gitignore` for shared editor/OS artifacts (each app also carries its own framework-specific `.gitignore`).
- Recorded DEC-011 (monorepo structure confirmed) and DEC-012 (SQLite for local bootstrap only, production DB engine still open).
- Updated `docs/02_ARCHITECTURE.md` (confirmed repository layout, versions, resource-efficiency direction, updated open questions/non-goals), `CLAUDE.md` (§5 quality commands now established, §9 repo layout), `README.md` (setup instructions), `docs/CURRENT_STATE.md`.
- Added `docs/phases/V1_PHASE_01_DEFINITION.md` and `docs/handoffs/V1_PHASE_01_HANDOFF.md`.
- No Company App business functionality was implemented.

### 2026-09-09 — Phase 0: Project Definition & Development Governance
- Established repository documentation foundation (this was an empty repository — no prior commits, no prior files).
- Added `CLAUDE.md` operating manual for future AI sessions.
- Added `docs/00_PROJECT_CHARTER.md` through `docs/06_UI_UX_GUIDELINES.md`.
- Added `docs/ROADMAP.md` defining Phase 0 through Phase 26.
- Added `docs/DECISIONS.md` with DEC-001 through DEC-010.
- Added `docs/CURRENT_STATE.md` as the compact live-state tracker.
- Added `docs/testing/TEST_PLAN.md`, `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`.
- Added `docs/handoffs/README.md` (handoff format standard) and `docs/handoffs/V1_PHASE_00_HANDOFF.md`.
- Added `docs/phases/V1_PHASE_00_DEFINITION.md` (this phase's own specification, recorded retroactively) and `docs/phases/PHASE_TEMPLATE.md` for future phases.
- Added `README.md`.
- No application code, database schema, or infrastructure was created — none was in scope for this phase.
