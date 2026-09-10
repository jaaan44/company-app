# CHANGELOG

Notable repository-level changes. Follows a simple date-ordered log; not tied to semantic versioning while pre-release.

## [Unreleased]

### 2026-09-10 — Phase 2 merged into `main`
- PR #2 merged. `main` now contains Development Environment & CI.

### 2026-09-10 — Phase 3: Core Architecture
- Added `/api/v1` routing foundation: `apps/api/routes/api.php` → `apps/api/routes/api/v1.php`, wired via `bootstrap/app.php`. Added `GET /api/v1/health` (`App\Http\Controllers\Api\V1\HealthController`), returning `{"data": {"status": "ok", "timestamp": "..."}}` — public, no sensitive details. Two feature tests added.
- Installed `livewire/livewire` (^4.4) as the confirmed Admin Backoffice foundation — no pages/components built yet.
- Recorded DEC-016 (MySQL production database direction, closing the open question from DEC-012), DEC-017 (numeric ID + ULID public ID identifier strategy), DEC-018 (modular monolith / Laravel organization conventions), DEC-019 (Blade + Livewire Admin Backoffice), DEC-020 (API foundation and response conventions implemented), DEC-021 (Flutter foundation structure; routing/state management deferred).
- Restructured `apps/mobile/lib` into `app/` (root widget), `core/config/` (build-time config, e.g. `API_BASE_URL` via `--dart-define`), `features/home/` (placeholder screen) — no routing package or state-management framework added.
- Updated `docs/02_ARCHITECTURE.md` (new §12, database/Admin Backoffice/API sections resolved), `docs/03_DATABASE_MODEL.md` (identifier strategy resolved), `docs/04_API_CONVENTIONS.md` (versioning/PK sections marked implemented), `docs/05_SECURITY_MODEL.md` (health endpoint noted as the one deliberate public exception), `README.md`, `docs/CURRENT_STATE.md`, `docs/testing/TEST_STATUS.md`.
- **Known limitation (same category as Phase 2):** `vendor/bin/phpstan analyse` and the fresh installation of `livewire/livewire` could not complete locally in this session (sandboxed GitHub API access blocks `phpstan/phpstan`'s dist-only download, which in turn aborts the composer install step for any other newly-added package in the same run). `composer.json`/`composer.lock` are correctly resolved; verified via GitHub Actions — see `docs/handoffs/V1_PHASE_03_HANDOFF.md`.
- No Company App business functionality was implemented.

### 2026-09-09 — Phase 1 merged into `main`
- Fast-forward merged `claude/v1-phase-01-project-bootstrap` into `main` (content unchanged) after Phase 1 review/approval.

### 2026-09-09 — Phase 2: Development Environment & CI
- Installed Larastan (PHPStan for Laravel) v3 as a dev dependency in `apps/api`; configured at `apps/api/phpstan.neon` (level 5, scans `app/`) — DEC-014.
- Added `.github/workflows/backend-ci.yml` and `.github/workflows/mobile-ci.yml`: path-filtered GitHub Actions workflows, triggered on pull requests targeting `main` and pushes to `main` — DEC-015. Backend job runs `composer install`, `composer validate --strict`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test` on PHP 8.4. Mobile job runs `flutter pub get`, `dart format` check, `flutter analyze`, `flutter test` on Flutter 3.47.2. No Docker, no build matrix, no APK/IPA builds.
- Decided against Docker as the default local dev environment (DEC-013) — PHP/Composer/Node/Flutter installed locally remain sufficient.
- Confirmed test database safety: Laravel's existing `phpunit.xml` (from Phase 1) already isolates tests to in-memory SQLite; no changes needed.
- **Known limitation:** `vendor/bin/phpstan analyse` could not be executed in this session — this sandboxed session's GitHub API access is scoped to `jaaan44/company-app` only, and `phpstan/phpstan`'s Composer package is dist-only (no git source), requiring a GitHub API zipball download that this session's access scope blocks. `composer.json`/`composer.lock` correctly declare and resolve the dependency; this is a session/environment constraint, not a defect. See `docs/handoffs/V1_PHASE_02_HANDOFF.md` for the exact GitHub Actions execution status.
- Updated `CLAUDE.md` (§5 — authoritative CI-matching commands, now including PHPStan), `README.md` (Quality Gates / CI and Local Development sections), `docs/02_ARCHITECTURE.md` (§11 — Development Environment & CI), `docs/CURRENT_STATE.md`.
- Added `docs/phases/V1_PHASE_02_DEFINITION.md` and `docs/handoffs/V1_PHASE_02_HANDOFF.md`.
- No Company App business functionality was implemented.

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
