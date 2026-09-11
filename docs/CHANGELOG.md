# CHANGELOG

Notable repository-level changes. Follows a simple date-ordered log; not tied to semantic versioning while pre-release.

## [Unreleased]

### 2026-09-11 — Phase 7: Staff
- **Backend:** added `staff` table; `App\Models\Staff` (ULID `public_id`, DEC-017). `App\Enums\StaffStatus` (`active`/`inactive`/`separated`) is Staff's own three-state employment lifecycle — distinct from `AccountStatus` (login/account state) and the future Phase 9 operational status.
- `staff.user_id` (nullable, **unique** FK to `users`) — a Staff record may exist without login access, a User may exist without a Staff record, and one User can link to at most one Staff record. No authentication data was duplicated onto `staff`.
- `staff.department_id`/`team_id`/`position_id` (nullable FKs to the Phase 6 tables, `restrictOnDelete`) and a self-referencing nullable `manager_id` (`restrictOnDelete`). A staff member cannot be their own manager; a manager assignment that would create a reporting cycle is rejected via a bounded manager-chain walk (`Staff::wouldCreateCycleWith()`), not general-purpose cycle detection. Team/Department assignment is validated for mutual consistency, with `department_id` auto-derived from `team_id` when omitted.
- `DepartmentController`/`TeamController`/`PositionController::destroy` (Phase 6) extended to also reject deletion (`409`) when Staff still reference the record; `StaffController::destroy` itself rejects deleting a staff member with direct reports.
- Two new permissions added to `RolePermissionSeeder`: `staff.view` (Manager/Staff) and `staff.manage` (Administrator-only, via the existing `Gate::before` override).
- New versioned REST endpoints under `/api/v1`: full CRUD for `staff`, route-model-bound by `public_id`, permission-gated, filterable by `status`/`department`/`team`/`position`/`manager` and a directory `q` search (name/employee number).
- `App\Http\Resources\StaffResource` — a single Staff Directory shape; the linked User's own identity (beyond a plain `has_user_account` boolean) is only included for a requester holding `staff.manage`.
- `StaffFactory` added.
- Recorded DEC-030 (Staff domain model, lifecycle, and User separation).
- No payroll, salary/compensation, government/tax IDs, attendance, biometrics, leave balances/requests, employee documents, medical data, emergency contacts, performance reviews, recruitment, onboarding workflow, benefits, expense claims, work logs, project/task assignment, messaging, or client management was introduced; no Admin Backoffice CRUD UI (consistent with Phase 6's precedent); no operational/current-status tracking (Phase 9).

### 2026-09-11 — Phase 6: Organization Structure
- **Backend:** added `departments`, `teams`, `positions` tables; `App\Models\Department`, `App\Models\Team`, `App\Models\Position` (each carries a ULID `public_id`, DEC-017). `App\Enums\OrganizationStatus` (`active`/`inactive`) is the shared lifecycle column for all three — retiring a unit flips this rather than deleting the row.
- Departments are flat (no sub-department hierarchy); Teams/Positions belong to **at most one** Department via a nullable `department_id` FK (resolving `02_ARCHITECTURE.md` §9's open question) — never a many-to-many span across departments. Team names / Position titles are unique within their department scope (or the "no department" scope), not globally.
- Relational integrity: a Department cannot be deleted while any Team or Position still references it (`409`, application-enforced, backed by a `restrictOnDelete()` FK). Teams/Positions may be freely deleted (nothing yet depends on them in this phase).
- Two new permissions added to `RolePermissionSeeder`: `organization.view` (attached to Manager and Staff) and `organization.manage` (Administrator-only, via the existing centralized `Gate::before` override — no new authorization mechanism).
- New versioned REST endpoints under `/api/v1`: full CRUD for `departments`, `teams`, `positions` — route-model-bound by `public_id` (never the internal numeric id), permission-gated (`organization.view` reads / `organization.manage` writes), behind the existing `auth:sanctum` + `account.active` chain. Filterable by `?status=` (all three) and `?department=<public_id>` (Teams/Positions).
- `App\Http\Requests\Organization\*` Form Requests validate all writes, including safe resolution of a client-supplied `department_id` (submitted as the department's public ULID, never its internal numeric id) and department-scoped uniqueness. `App\Http\Resources\{Department,Team,Position}Resource` expose only `public_id` (never internal ids); Team/Position nest a minimal `department` (public_id + name).
- `DepartmentFactory`, `TeamFactory`, `PositionFactory` added.
- Recorded DEC-029 (organization structure architecture).
- No Staff/Employee management, Clients, Projects, Leave, Tasks, Messaging, or other later business module was introduced; no department hierarchy; no Admin Backoffice (Blade/Livewire) CRUD UI (consistent with Phase 5's precedent — no such UI pattern exists yet for any module).

### 2026-09-11 — Phase 5: Roles & Permissions
- **Backend:** added `roles`, `permissions`, `role_permissions` tables; `App\Models\Role` (constants `ADMINISTRATOR`/`MANAGER`/`STAFF`) and `App\Models\Permission` (dot-notation identifiers, e.g. `admin.access`). `users.role_id` (nullable FK, `nullOnDelete`) replaces the retired Phase 4 transitional `users.is_admin` boolean — **one role per user**, not a many-to-many `users`↔`roles` table.
- Retirement migration (`2026_09_11_050003_add_role_id_to_users_table.php`) backfills any pre-existing `is_admin = true` row onto the Administrator role before dropping the column — no two competing authorization mechanisms coexist even transiently.
- Centralized enforcement: a single `Gate::before` callback (`App\Providers\AppServiceProvider::boot()`) grants Administrator every ability unconditionally and resolves every other user's ability checks against their role's attached permissions (`App\Models\User::hasPermission()`), defaulting to deny. Works through Laravel's real Gate resolution, so the built-in `can` middleware, `Gate::allows()`, Blade's `@can`, and future Policies all work against permission names with no per-permission boilerplate.
- Admin Backoffice access chain updated to `auth` → `account.active` → `can:admin.access` → `/home`; `App\Livewire\Auth\LoginForm`'s `is_admin` check replaced with `$user->cannot('admin.access')`.
- `App\Http\Resources\UserResource` gained a `role` field (name only, e.g. `"administrator"` — never the internal numeric `role_id`).
- `Database\Seeders\RolePermissionSeeder` (new) — idempotent, safe system data (Administrator/Manager/Staff roles + the foundational permission catalog), called unconditionally from `DatabaseSeeder`, deliberately separate from the local-only `AdminUserSeeder` (updated to assign the Administrator role instead of `is_admin`).
- `RoleFactory`, `PermissionFactory` added; `UserFactory`'s `admin()` state replaced with `administrator()`/`manager()`/`staff()` (each idempotently look-up-or-creates its role).
- Recorded DEC-028 (roles & permissions architecture, retiring DEC-024).
- 22 new PHPUnit feature tests across `tests/Feature/Authorization/` (permission mechanism, Admin Backoffice HTTP-level authorization, `AdminUserSeeder`, `RolePermissionSeeder`) plus updates to `AdminLoginTest` for the retired `admin()` factory state — full suite passing, including `vendor/bin/phpstan analyse` and `vendor/bin/pint --test`.
- Updated `docs/02_ARCHITECTURE.md` (§4, new §15), `docs/03_DATABASE_MODEL.md`, `docs/05_SECURITY_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/testing/TEST_STATUS.md`, `docs/CURRENT_STATE.md`.
- No Staff/Clients/Projects/Leave/Tasks/Work Logs/Messaging or any other business module was introduced; no third-party RBAC package; no many-to-many user↔role infrastructure.

### 2026-09-11 — Phase 4A correction: Windows UAT results + configurable Docker ports
- **UAT:** product owner completed full Windows UAT — Docker stack (post-CRLF-fix), first-time setup (`composer install`, `key:generate`, migrations, `AdminUserSeeder`), `/api/v1/health`, and the full Admin + Flutter authentication flow against the Dockerized API — all passing. Recorded as `PASS` in `docs/testing/UAT_LOG.md` (UAT-04-01, 04-03, 04-04, 04-06, 04A-01–03), attributed to the product owner per CLAUDE.md §7; untested scenarios (Admin suspended/inactive/non-admin rejection, Flutter simulated network failure) remain `NOT RUN`.
- **Port configuration:** both Docker host ports are now configurable rather than fixed, after the product owner hit port collisions on Windows (host `3306` already in use). `docker-compose.yml`: `nginx`'s host port is `${APP_PORT:-8012}:80` (was fixed `8000:80`); `mysql`'s host port is `127.0.0.1:${MYSQL_PORT:-3347}:3306` (was fixed `127.0.0.1:3306:3306`). Neither container's internal port changed. Updated `apps/api/.env.docker.example` (`APP_URL`), `README.md`, and `docs/02_ARCHITECTURE.md` §14 accordingly. Flutter's own built-in default (`http://localhost:8000/api/v1`) is deliberately unchanged — the Docker-path examples now pass `--dart-define=API_BASE_URL=http://localhost:8012/api/v1` explicitly, per the existing DEC-021 mechanism.
- Re-verified via a genuinely running Docker stack on the new default ports (build, MySQL healthy, migrations, seeder, full 31-test suite, Pint, PHPStan, health/login/login-API endpoints) plus the unaffected host (non-Docker) quality gates. GitHub Actions re-confirmed green.
- Updated `docs/handoffs/V1_PHASE_04A_HANDOFF.md` (§5, §9, §12, §24, new §27), `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`, `docs/CURRENT_STATE.md`.
- No RBAC, Staff Management, or other business functionality was introduced.

### 2026-09-11 — Phase 4A: Docker Development Environment
- Added `docker-compose.yml` (repo root) defining three services — `nginx` (`nginx:1.27-alpine`), `app` (`docker/php/Dockerfile`, `php:8.4-fpm` + `pdo_mysql`/`bcmath` + Composer), `mysql` (`mysql:8.4`) — as the new standard local backend development environment. No Redis, queue worker, scheduler, WebSocket server, Mailpit, phpMyAdmin, or other always-on service added.
- `mysql-data` named volume persists database state; a separate `vendor` named volume isolates the container's Composer install from whatever exists (or doesn't) in the host's `apps/api/vendor`, avoiding host/container dependency mismatches.
- Added `apps/api/.env.docker.example` (MySQL-pointing: `DB_HOST=mysql`, matching the `mysql` service's local-only dev credentials) alongside the existing SQLite-based `.env.example`.
- Added `docker/php/entrypoint.sh` (grants write access to `storage`/`bootstrap/cache` only, not the whole application) and `docker/php/conf.d/local-dev.ini` (raises `memory_limit` to 512M — the base image's 128M default crashes Larastan/PHPStan's parallel workers).
- Genuinely verified in this session by actually building and running the stack: MySQL healthcheck, Laravel↔MySQL connectivity, all 5 migrations, `AdminUserSeeder`, the full 31-test Phase 4 Authentication suite, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, and `/api/v1/health` / `/login` / `POST /api/v1/auth/login` through Nginx — all passing. This validation surfaced and fixed two real bugs: the entrypoint's original `chmod ug+rwX` didn't actually grant the container's `www-data` PHP-FPM process write access (fixed to `a+rwX`, still scoped to just those two directories); and an initial `env_file` directive on the `app` service silently defeated `phpunit.xml`'s testing-environment overrides (PHPUnit's `<env>` doesn't force-replace an already-set variable), which would have pointed `php artisan test` inside the container at the real dev database instead of the isolated in-memory SQLite it uses everywhere else — removed, since Laravel already reads `apps/api/.env` directly via the bind mount.
- Recorded DEC-027 (Docker Compose as the standard local backend environment), formally superseding DEC-013 (marked `SUPERSEDED`, not deleted, per `DECISIONS.md`'s own rules).
- GitHub Actions CI is unchanged — still SQLite-based (DEC-015) and runs directly on the runner, no Docker.
- Flutter (`apps/mobile`) is entirely unaffected — it remains outside Docker.
- Updated `CLAUDE.md` (§5 Docker note, §9 repository layout), `README.md`, `docs/02_ARCHITECTURE.md` (new §14), `docs/03_DATABASE_MODEL.md`, `docs/DECISIONS.md`, `docs/ROADMAP.md` (Phase 4A inserted between Phase 4 and Phase 5), `docs/CURRENT_STATE.md`, `docs/testing/TEST_STATUS.md`.
- No RBAC, Staff Management, or other business functionality was introduced.

### 2026-09-11 — Phase 4 merged into `main`
- PR #5 merged. `main` now contains Authentication.

### 2026-09-11 — Phase 4: Authentication
- **Backend:** added `App\Enums\AccountStatus` (`active`/`suspended`/`inactive`); migrated `users` to add `public_id` (ULID), `status`, and a transitional `is_admin` boolean; installed `laravel/sanctum` (^4.0) with its `personal_access_tokens` migration and config published.
- Admin Backoffice: `App\Livewire\Auth\LoginForm` (Blade + Livewire) at `GET /login`, session regeneration on success, `POST /logout`, a protected `GET /home` placeholder — all gated by `auth`/`guest` middleware plus a new `App\Http\Middleware\EnsureAccountIsActive` (alias `account.active`) that also enforces account status on already-authenticated access (not just login), logging a now-suspended session out.
- Mobile API: `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/me` (`App\Http\Controllers\Api\V1\Auth\AuthController`), returning safe identity data via `App\Http\Resources\UserResource` (`public_id`, `name`, `email`, `status` — never the password hash, remember token, internal numeric id, or `is_admin`).
- Rate limiting: a shared `login` limiter (5/minute, keyed by email+IP) applied via route middleware on the API and enforced directly inside the Livewire component for the Admin surface (Livewire's AJAX update endpoint isn't reachable by route-level throttling).
- No public self-registration; a local-only `Database\Seeders\AdminUserSeeder` (refuses to run outside `local`/`testing`) provisions the first Admin account.
- **Mobile:** added `lib/features/auth/` (domain/data/state/presentation) — `AuthApiClient` (`package:http`), `TokenStorage`/`SecureTokenStorage` (`flutter_secure_storage`), `AuthController` (`ChangeNotifier`), `LoginPage`, `AuthGate`. `CompanyApp` now owns the `AuthController` and renders `AuthGate` as its home. The existing `HomePage` placeholder gained an optional `userName`/`onLogout` for reuse as the authenticated destination.
- Recorded DEC-022 (session for Admin / Sanctum tokens for Mobile), DEC-023 (no public self-registration), DEC-024 (transitional `is_admin` flag), DEC-025 (Flutter state management: `ChangeNotifier`), DEC-026 (Flutter token storage: `flutter_secure_storage`).
- Backend tests: 23 new PHPUnit feature tests (8 Admin auth, 15 API auth) — 31 total, all passing, including `vendor/bin/phpstan analyse` (0 errors) and `vendor/bin/pint --test`. Mobile tests: 16 new `flutter_test` tests (auth controller, login page, auth gate) plus the existing smoke test rewritten for the new flow — 17 total, all passing, alongside `flutter analyze` and `dart format`.
- Updated `docs/02_ARCHITECTURE.md` (new §13), `docs/03_DATABASE_MODEL.md` (`users`/`personal_access_tokens` resolved), `docs/04_API_CONVENTIONS.md` (auth endpoints, authentication section), `docs/05_SECURITY_MODEL.md` (Authentication, Account States, Administrative Access, API Access, Rate Limiting, and a new Client-Side Token Storage section — now describing implemented, tested behavior rather than strategy only), `docs/06_UI_UX_GUIDELINES.md`, `README.md`, `docs/CURRENT_STATE.md`, `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`.
- No RBAC, Staff Management, or other future-phase business functionality was introduced.

### 2026-09-10 — Phase 3 merged into `main`
- PR #3 merged. `main` now contains Core Architecture.

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
