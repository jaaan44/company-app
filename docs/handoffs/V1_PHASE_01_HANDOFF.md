# Phase 1 Handoff — Project Bootstrap

## 1. Phase Identification

- **Phase:** 1 — Project Bootstrap
- **Date:** 2026-09-09
- **Branch:** `claude/v1-phase-01-project-bootstrap` (from `main` @ `853156c`)

## 2. Objective

Bootstrap the actual Laravel and Flutter application codebases under a clean monorepo structure (`apps/api`, `apps/mobile`), establishing a lean, healthy development baseline. No Company App business functionality.

## 3. Scope Implemented

- Monorepo layout: `apps/api` (Laravel), `apps/mobile` (Flutter), `docs/` (unchanged).
- Laravel bootstrap: clean `composer create-project laravel/laravel` install, verified booting, framework-default migrations only, Pint + PHPUnit + `composer validate` all passing.
- Flutter bootstrap: `flutter create` (Android + iOS platforms), demo counter replaced with a minimal neutral shell, `dart format` + `flutter analyze` + `flutter test` all passing.
- Environment/secret safety verified.
- Documentation updated per `CLAUDE.md` §6 (see §15 below).

Full scope/out-of-scope statement: `docs/phases/V1_PHASE_01_DEFINITION.md`.

## 4. Laravel Version

**Laravel Framework 13.31.0** (`composer.json` requires `laravel/framework: ^13.17`).

## 5. PHP Requirement/Version Used

`composer.json` requires `^8.3`. Built and validated against **PHP 8.4.19** (available in this environment) — not downgraded to match an older baseline, per instruction.

## 6. Flutter Version

**Flutter 3.47.2** (stable channel).

## 7. Dart Version

**Dart 3.13.2** (bundled with the above Flutter SDK).

## 8. Repository Structure Created

```
apps/
  api/       — Laravel 13 application (backend/API + future Admin Backoffice)
  mobile/    — Flutter application (staff mobile app), Android + iOS platforms
.gitignore   — new, root-level, covers shared editor/OS artifacts
```

Each app retains its own framework-generated `.gitignore` for build artifacts, dependencies, and environment files (see §13).

## 9. Dependencies Introduced

**Backend (`apps/api`, via Composer, standard Laravel skeleton defaults):** `laravel/framework ^13.17`, `laravel/tinker ^3.0`; dev: `fakerphp/faker`, `laravel/pail`, `laravel/pint`, `mockery/mockery`, `nunomaduro/collision`, `phpunit/phpunit`. Frontend tooling (via npm, also skeleton defaults): `vite`, `laravel-vite-plugin`, `tailwindcss`, `@tailwindcss/vite`, `concurrently`. `npm install` run and verified clean (0 vulnerabilities).

**Mobile (`apps/mobile`, via `flutter create` defaults):** `flutter` SDK, `cupertino_icons ^1.0.8`; dev: `flutter_test`, `flutter_lints ^6.0.0`.

No packages were added beyond what each scaffolding tool generates by default — nothing extra was introduced during bootstrap.

## 10. Files/Major Areas Changed

- Added: `apps/api/**` (full Laravel skeleton minus items removed — see §16), `apps/mobile/**` (full Flutter skeleton, `lib/main.dart` and `test/widget_test.dart` rewritten to a neutral shell), root `.gitignore`.
- Updated: `CLAUDE.md` (§5 quality commands, §9 repo layout), `README.md` (structure + setup commands), `docs/02_ARCHITECTURE.md` (§0 resource-efficiency direction added, §2 layout confirmed with versions, §6 queue driver note, §9/§10 open questions and non-goals updated), `docs/DECISIONS.md` (DEC-011, DEC-012 added), `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/testing/TEST_STATUS.md`.
- Added: `docs/phases/V1_PHASE_01_DEFINITION.md`, `docs/handoffs/V1_PHASE_01_HANDOFF.md` (this file).

## 11. Database/Schema State

SQLite (`apps/api/database/database.sqlite`, git-ignored — see `apps/api/database/.gitignore`), Laravel's own default for a fresh install. Only **framework-default migrations** ran: `create_users_table`, `create_cache_table`, `create_jobs_table`. **No business/domain migrations** were created, per scope. Production database engine (PostgreSQL vs. MySQL) remains an open decision (DEC-012) — not resolved here.

## 12. API State

None beyond the framework default: `GET /` returns the stock Laravel welcome view. No versioned API, no business endpoints — per scope.

## 13. Security/Environment Handling

- `apps/api/.env` (generated locally, contains a real `APP_KEY`) is **not committed** — confirmed via `git check-ignore -v apps/api/.env` → ignored by `apps/api/.gitignore` line 3.
- Only `apps/api/.env.example` (no secrets, placeholder values) is committed.
- `vendor/`, `node_modules/`, and all Flutter build/`.dart_tool` output confirmed git-ignored (`git check-ignore -v` spot-checked on `apps/api/vendor/autoload.php`, `apps/api/node_modules/.bin`, `apps/mobile/build`, `apps/mobile/.dart_tool`).
- Before committing, the full staged file list (`git status --porcelain`) was reviewed for anything secret-bearing — none found.
- No authentication or authorization was implemented (out of scope — Phase 4/5).

## 14. Tests/Checks Executed

All commands below were actually run in this session (not assumed):

| Command | Location | Result |
|---|---|---|
| `composer install` | `apps/api` | Completed successfully |
| `composer validate --strict` | `apps/api` | `./composer.json is valid` |
| `vendor/bin/pint --test` | `apps/api` | `{"tool":"pint","result":"passed"}` |
| `php artisan test` | `apps/api` | `{"tool":"phpunit","result":"passed","tests":2,"passed":2,"assertions":2}` |
| `php artisan --version` / `migrate:status` | `apps/api` | Laravel Framework 13.31.0; 3 framework migrations ran |
| `npm install` | `apps/api` | 91 packages added, 0 vulnerabilities |
| `flutter pub get` | `apps/mobile` | Dependencies resolved |
| `dart format --output=none --set-exit-if-changed .` | `apps/mobile` | Exit 0 (clean; one file was reformatted and re-verified during development) |
| `flutter analyze` | `apps/mobile` | `No issues found!` |
| `flutter test` | `apps/mobile` | `+1: All tests passed!` (1 test) |

## 15. Results

All checks above passed on the final run — see the table in §14 and `docs/testing/TEST_STATUS.md` (Phase 1 section) for the same information in the repository's standard testing-record format.

## 16. Deviations from Specification

- **Removed two AI-agent bootstrap stub files** (`apps/api/CLAUDE.md`, `apps/api/AGENTS.md`) that `composer create-project laravel/laravel` includes by default. Both were byte-identical "Laravel Boost" content instructing an AI session to install an unrequested `laravel/boost` package. Kept, they would have created a second, conflicting `CLAUDE.md` inside the repo — directly against this repository's single-source-of-truth governance model (DEC-001) and against "do not add unnecessary packages during bootstrap."
- **Removed the upstream `laravel/laravel` template repo's own CI/maintenance tooling**: `apps/api/.github/workflows/*` (a full PHP 8.3–8.5 test matrix, dependabot auto-merge, issue/PR bots referencing `laravel/.github` reusable workflows), `apps/api/.github/dependabot.yml`, `apps/api/.styleci.yml` (legacy StyleCI config, superseded by Pint), and `apps/api/CHANGELOG.md` (which was literally the upstream `laravel/laravel` skeleton repo's own release notes, not this project's). These are elaborate CI/automation artifacts explicitly out of scope per "NO PREMATURE CI IMPLEMENTATION" — CI hardening belongs to Phase 2.
- **Flutter SDK was not preinstalled** in this session's container; it was installed to `/opt/flutter` (official `flutter/flutter` GitHub repo, stable channel) to satisfy the phase's Flutter validation requirements. This is a session/environment setup detail, not a repository dependency.

No other deviations from the governing Phase 1 instruction.

## 17. Known Issues/Limitations

- PHPStan/Larastan is intentionally not installed — deferred to Phase 2 (Development Environment & CI) per instruction.
- Production database engine (PostgreSQL vs. MySQL) remains an open decision (DEC-012); local bootstrap uses SQLite only.
- Admin Backoffice rendering approach (Blade/Livewire/Inertia/SPA) remains an open decision; only its location (`apps/api`) is confirmed.
- Flutter SDK location (`/opt/flutter`) is environment-specific to this session's container and is not recorded as a repository requirement/path anywhere — a future session/developer may need to install Flutter themselves.
- No CI pipeline exists yet — all checks in §14 were run manually in this session.

## 18. Manual Verification Instructions

**Backend:**
```sh
cd apps/api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
# then GET http://localhost:8000 — expect the stock Laravel welcome page
```

**Mobile:**
```sh
cd apps/mobile
flutter pub get
flutter run
# expect an app bar titled "Company App" and body text "Company App — bootstrap shell"
```

This phase has no user-facing business behavior, so no UAT scenario is proposed yet — `docs/testing/UAT_LOG.md` is unchanged.

## 19. Documentation Updated

`CLAUDE.md`, `README.md`, `docs/02_ARCHITECTURE.md`, `docs/DECISIONS.md`, `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/testing/TEST_STATUS.md`, `docs/phases/V1_PHASE_01_DEFINITION.md` (new), `docs/handoffs/V1_PHASE_01_HANDOFF.md` (this file, new).

## 20. Recommended Next Phase

**Phase 2 — Development Environment & CI**, per `docs/ROADMAP.md`: local dev environment tooling and a real CI pipeline (lint, static analysis incl. PHPStan/Larastan, test run) for both apps, replacing the manual verification performed in this phase.

## Business Functionality Statement

**No Company App business functionality was introduced in this phase.** No Staff, Clients, Projects, Tasks, Leave, Messaging, Service Reports, Incidents, custom RBAC, business APIs, Admin dashboards, or domain migrations exist. Both applications are clean, minimal scaffolds.

---

*Per `CLAUDE.md` §8 (Stop Discipline): this phase is complete. Not merged into `main`. Phase 2 is not authorized by this handoff and will not begin without explicit user instruction.*
