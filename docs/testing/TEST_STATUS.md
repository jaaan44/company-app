# TEST STATUS — Automated & Manual

Live status of automated test suite health and manual verification, by phase. Append a new section per phase; do not delete history.

Status values: `NOT RUN` · `PASS` · `FAIL` · `BLOCKED` — see `TEST_PLAN.md`.

---

## Phase 0 — Project Definition & Development Governance

| Check | Type | Status | Notes |
|---|---|---|---|
| Documentation internal consistency review | Manual | PASS | Cross-references between CLAUDE.md, ROADMAP.md, CURRENT_STATE.md, DECISIONS.md checked for consistency during authoring. |
| No premature application code introduced | Manual | PASS | Confirmed only documentation/governance files were created; no `backend/`, `mobile/`, migrations, or endpoints exist. |

No automated tests apply to this phase — no application code exists yet.

---

## Phase 1 — Project Bootstrap

| Check | Type | Status | Notes |
|---|---|---|---|
| `composer validate --strict` (apps/api) | Automated | PASS | composer.json integrity |
| `vendor/bin/pint --test` (apps/api) | Automated | PASS | code style |
| `php artisan test` (apps/api) | Automated | PASS | 2 tests, 2 assertions — Laravel's own baseline example tests |
| `dart format --set-exit-if-changed .` (apps/mobile) | Automated | PASS | formatting |
| `flutter analyze` (apps/mobile) | Automated | PASS | no issues found |
| `flutter test` (apps/mobile) | Automated | PASS | 1 test — bootstrap shell smoke test |
| Laravel boots (`php artisan --version`, `migrate:status`) | Manual | PASS | Laravel 13.31.0; framework-default migrations ran cleanly |
| No secrets committed | Manual | PASS | `.env` and other secret-bearing files confirmed git-ignored via `git check-ignore`; only `.env.example` committed |
| No business functionality introduced | Manual | PASS | Confirmed by reviewing the full staged diff before commit |

---

## Phase 2 — Development Environment & CI

| Check | Type | Status | Notes |
|---|---|---|---|
| `composer validate --strict` (apps/api) | Automated, local | PASS | composer.json integrity, re-verified after Larastan added |
| `vendor/bin/pint --test` (apps/api) | Automated, local | PASS | code style, unaffected by Phase 2 changes |
| `php artisan test` (apps/api) | Automated, local | PASS | 2 tests, 2 assertions, unaffected |
| `vendor/bin/phpstan analyse` (apps/api) | Automated, local | BLOCKED (local only) | Could not install `phpstan/phpstan` locally in this session (sandboxed GitHub API access) — see `docs/handoffs/V1_PHASE_02_HANDOFF.md` §15/16. **Confirmed PASS via GitHub Actions** (see below): "[OK] No errors", 3 files analyzed. |
| `dart format --set-exit-if-changed .` (apps/mobile) | Automated, local | PASS | unaffected |
| `flutter analyze` (apps/mobile) | Automated, local | PASS | unaffected |
| `flutter test` (apps/mobile) | Automated, local | PASS | unaffected |
| Backend CI workflow (`.github/workflows/backend-ci.yml`) | Automated, GitHub Actions | PASS | Run [34367259866](https://github.com/jaaan44/company-app/actions/runs/34367259866), commit `fb4c548`, ~20s. All steps green including PHPStan. |
| Mobile CI workflow (`.github/workflows/mobile-ci.yml`) | Automated, GitHub Actions | PASS | Run [34367259884](https://github.com/jaaan44/company-app/actions/runs/34367259884), commit `fb4c548`, ~91s. |
| No real secrets in CI config | Manual | PASS | Workflows generate an ephemeral `APP_KEY` via `php artisan key:generate` after copying `.env.example`; no credentials committed or referenced |
| No business functionality introduced | Manual | PASS | Confirmed by reviewing the full staged diff before commit |

---

## Phase 3 — Core Architecture

| Check | Type | Status | Notes |
|---|---|---|---|
| `composer validate --strict` (apps/api) | Automated, local | PASS | re-verified after adding livewire/livewire |
| `vendor/bin/pint --test` (apps/api) | Automated, local | PASS | includes new HealthController/routes/tests |
| `php artisan test` (apps/api) | Automated, local | PASS | 4 tests, 11 assertions (2 baseline + 2 new health-endpoint tests) |
| `vendor/bin/phpstan analyse` (apps/api) | Automated, local | BLOCKED (local only) | Same session-specific limitation as Phase 2 — see `docs/handoffs/V1_PHASE_03_HANDOFF.md`. |
| `dart format --set-exit-if-changed .` (apps/mobile) | Automated, local | PASS | after restructuring into app/core/features |
| `flutter analyze` (apps/mobile) | Automated, local | PASS | no issues found |
| `flutter test` (apps/mobile) | Automated, local | PASS | widget test updated for new import path, still passes |
| `GET /api/v1/health` routing | Manual | PASS | confirmed via `php artisan route:list --path=api` |
| Backend CI workflow | Automated, GitHub Actions | PASS | Run [34542479092](https://github.com/jaaan44/company-app/actions/runs/34542479092), commit `6308c4a`, ~18s. PHPStan: "[OK] No errors" on 4 files. |
| Mobile CI workflow | Automated, GitHub Actions | PASS | Run [34542479141](https://github.com/jaaan44/company-app/actions/runs/34542479141), commit `6308c4a`, ~44s. |
| No business functionality introduced | Manual | PASS | Confirmed by reviewing the full staged diff before commit |

---

---

## Phase 4 — Authentication

| Check | Type | Status | Notes |
|---|---|---|---|
| `composer validate --strict` (apps/api) | Automated, local | PASS | re-verified after adding laravel/sanctum |
| `vendor/bin/pint --test` (apps/api) | Automated, local | PASS | includes all new auth code/tests |
| `vendor/bin/phpstan analyse` (apps/api) | Automated, local | PASS | **Ran successfully in this session** (unlike Phases 2/3) — see handoff §20 for how the sandbox's dependency-download limitation was worked around; 0 errors after two genuine type-safety fixes it surfaced |
| `php artisan test` (apps/api) | Automated, local | PASS | 31 tests, 96 assertions (23 new: 8 admin auth, 6 API login, 6 API me/logout, 3 pre-existing unaffected) |
| `dart format --output=none --set-exit-if-changed .` (apps/mobile) | Automated, local | PASS | **Ran successfully in this session** — Flutter SDK not preinstalled in this sandbox; 3.47.2 was fetched to match the pinned version (see handoff) |
| `flutter analyze` (apps/mobile) | Automated, local | PASS | no issues found |
| `flutter test` (apps/mobile) | Automated, local | PASS | 17 tests (1 pre-existing, rewritten for the new auth flow; 16 new across auth_controller/login_page/auth_gate) |
| Admin login page accessible to guests | Automated | PASS | `AdminLoginTest::test_login_page_is_accessible_to_guests` |
| Admin valid login / session regeneration | Automated | PASS | `test_admin_user_can_log_in_with_valid_credentials`, `test_session_id_is_regenerated_after_successful_login` |
| Admin invalid credentials / non-admin / suspended / inactive rejected | Automated | PASS | 4 tests in `AdminLoginTest` |
| Admin logout / unauthenticated redirect / mid-session suspension | Automated | PASS | 3 tests in `AdminLoginTest` |
| Admin login rate limiting | Automated | PASS | `test_login_is_rate_limited_after_repeated_failures` |
| API login (valid/invalid/unknown-email/suspended/inactive/required-fields/rate-limited) | Automated | PASS | 7 tests in `LoginTest` |
| API no public registration | Automated | PASS | `test_no_public_registration_endpoint_exists` |
| API `/me` authenticated/unauthenticated | Automated | PASS | `MeAndLogoutTest` |
| API logout revokes current token only; revoked token rejected; suspended-mid-session revokes access | Automated | PASS | `MeAndLogoutTest` |
| Flutter: unauthenticated shows login, loading, successful/failed login, logout, token restoration | Automated | PASS | `auth_gate_test.dart`, `auth_controller_test.dart`, `login_page_test.dart` — network and secure storage faked, no live server dependency |
| Manual: full login→me→logout→revoked-token cycle via `php artisan serve` + curl | Manual | PASS | see handoff for exact commands/output |
| Manual: Admin login page renders Livewire component; `/home` redirects when unauthenticated | Manual | PASS | see handoff |
| Backend CI workflow | Automated, GitHub Actions | PASS | Run [34548321478](https://github.com/jaaan44/company-app/actions/runs/34548321478), commit `7eb3717`, ~18s. |
| Mobile CI workflow | Automated, GitHub Actions | PASS | Run [34548321404](https://github.com/jaaan44/company-app/actions/runs/34548321404), commit `7eb3717`, ~49s. |
| No unrelated business functionality introduced | Manual | PASS | confirmed by reviewing the full staged diff before commit |

---

## Phase 4A — Docker Development Environment

| Check | Type | Status | Notes |
|---|---|---|---|
| `docker compose config` | Automated, local | PASS | Validates cleanly against the real, committed `docker-compose.yml`. |
| `docker compose build app` (real, committed Dockerfile) | Automated, local | BLOCKED (local only) | Fails at the `apt-get` step — this sandbox's network policy blocks `deb.debian.org` (confirmed a deliberate block, not a transient failure). A real developer's or CI's unrestricted network has no such restriction. |
| Full stack build/startup/validation (temporary, uncommitted Dockerfile variant skipping only the network-blocked `apt-get` step) | Automated, local | PASS | Genuinely built and ran; see handoff for exact steps. Confirms every other line of the real Dockerfile and the full compose stack. |
| MySQL healthcheck | Automated, local | PASS | `mysqladmin ping` — `app` container correctly waited for `service_healthy` before starting. |
| Laravel ↔ MySQL connectivity | Automated, local | PASS | `DB::connection()->getPdo()` succeeded inside the `app` container. |
| Migrations against real MySQL 8.4 | Automated, local | PASS | All 5 migrations ran cleanly. |
| `AdminUserSeeder` inside Docker | Automated, local | PASS | Created the local dev admin account as expected. |
| `composer validate --strict` / `vendor/bin/pint --test` / `vendor/bin/phpstan analyse` / `php artisan test` inside the `app` container | Automated, local | PASS | 31/31 tests, 96 assertions; PHPStan 0 errors (after raising `memory_limit` to 512M — see Known Issues in the handoff); Pint clean. |
| `GET /api/v1/health` through Nginx | Automated, local | PASS | `curl http://localhost:8000/api/v1/health` → `200`. |
| `GET /login` (Admin) through Nginx | Automated, local | PASS | `200`, Livewire component present — after fixing the entrypoint permissions bug (see handoff). |
| `POST /api/v1/auth/login` through Nginx | Automated, local | PASS | Full request → MySQL → Sanctum token issuance cycle confirmed working end-to-end through the Docker stack. |
| Host (non-Docker) `composer validate --strict` / `vendor/bin/pint --test` / `vendor/bin/phpstan analyse` / `php artisan test` | Automated, local | PASS | Unaffected by this phase — 31/31 tests, 0 PHPStan errors, Pint clean. |
| Backend CI workflow | Automated, GitHub Actions | PASS | Run [34552289717](https://github.com/jaaan44/company-app/actions/runs/34552289717), commit `f13de5a`, ~25s. |
| Mobile CI workflow | Automated, GitHub Actions | N/A (did not trigger) | No `apps/mobile` changes in this phase — correctly respects the path-filtered CI design (DEC-015). |
| No RBAC/business functionality introduced | Manual | PASS | Confirmed by reviewing the full staged diff before commit. |
| Windows UAT — `docker compose up` | Manual, product owner | **FAIL → FIXED** | `app` container failed: `exec /usr/local/bin/entrypoint.sh: no such file or directory`. Root cause: no `.gitattributes`, so Windows `core.autocrlf=true` checked out `docker/php/entrypoint.sh` as CRLF (`git ls-files --eol` showed `i/lf w/crlf`), breaking its shebang inside the Linux container. Fixed by adding `.gitattributes` (LF enforced for `*.sh`/`Dockerfile`, repo-wide `text=auto eol=lf` default). See handoff §26. |
| CRLF fix verification: reproduce failure from a deliberately CRLF-converted `entrypoint.sh` | Automated, local | PASS | Built a throwaway image from the CRLF copy; got the identical reported error, confirming root-cause diagnosis before trusting the fix. |
| CRLF fix verification: simulated Windows checkout (`git -c core.autocrlf=true clone` + checkout) | Automated, local | PASS | `docker/php/entrypoint.sh` and `docker/php/Dockerfile` both check out with pure LF after the fix — the exact scenario the product owner hit. |
| CRLF fix verification: all three containers up post-fix | Automated, local | PASS | `nginx`, `app`, `mysql` all started; `mysql` reported `healthy`; `app` did not crash-loop; `GET /api/v1/health` → `200` through the full stack — matches the product owner's own successful Windows retest. |
| Host (non-Docker) quality gates re-verified after the CRLF fix | Automated, local | PASS | `composer validate --strict`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test` (31/31) — unaffected, confirming the fix touched nothing beyond line-ending normalization. |
| Backend CI workflow (re-confirmed after CRLF fix) | Automated, GitHub Actions | PASS | Run [34573583090](https://github.com/jaaan44/company-app/actions/runs/34573583090), commit `272625e`, ~17s. |
| Windows UAT — full first-time setup + Authentication (Admin and Flutter) against Docker | Manual, product owner | **PASS** | Full list in `docs/testing/UAT_LOG.md` (UAT-04-01, 04-03, 04-04, 04-06, 04A-01–03) — Docker stack, `composer install`, `key:generate`, migrations, `AdminUserSeeder`, `/api/v1/health`, Admin `/login`/login/logout/logged-out redirect, Flutter login/invalid-password/state-restoration/logout. Reported directly by the product owner; recorded as `PASS` only for the exact scenarios reported — see the handoff §27.2. |
| `docker compose config` (port defaults) | Automated, local | PASS | Resolved config shows `nginx` → `published: "8012"`, `target: 80`; `mysql` → `published: "3347"`, `target: 3306` — internal ports unchanged. |
| `docker compose config` (`APP_PORT`/`MYSQL_PORT` overrides) | Automated, local | PASS | Tested with `APP_PORT=9000 MYSQL_PORT=3399` — both override correctly with no `docker-compose.yml` edit needed. |
| Full stack on new default ports (temporary Dockerfile variant, same method as §109) | Automated, local | PASS | All 3 containers up; `docker compose ps` confirmed `0.0.0.0:8012->80/tcp` and `127.0.0.1:3347->3306/tcp`; `mysql` healthy. |
| `GET /api/v1/health`, `GET /login`, `POST /api/v1/auth/login` on port `8012` | Automated, local | PASS | All `200`; login returned a valid token via a full MySQL round-trip. |
| Migrations / `AdminUserSeeder` / `php artisan test` (31/31) / `vendor/bin/pint --test` / `vendor/bin/phpstan analyse` (0 errors) inside `app`, post-port-change | Automated, local | PASS | Re-verified after the port change; unaffected. |
| Host (non-Docker) quality gates, post-port-change | Automated, local | PASS | `composer validate --strict`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test` (31/31) — unaffected, confirming the change is scoped to Docker port configuration only. |
| Backend CI workflow (re-confirmed after port configuration + UAT documentation) | Automated, GitHub Actions | PASS | Run [34577973012](https://github.com/jaaan44/company-app/actions/runs/34577973012), commit `3b418ce`, ~20s. |

---

## Phase 5 — Roles & Permissions

| Check | Type | Status | Notes |
|---|---|---|---|
| `composer validate --strict` (apps/api) | Automated, local | PASS | unaffected — no new dependencies added |
| `vendor/bin/pint --test` (apps/api) | Automated, local | PASS | includes all new Role/Permission/authorization code and tests |
| `vendor/bin/phpstan analyse` (apps/api) | Automated, local | PASS | 0 errors — `Gate::before` closure, Role/Permission generics, and all new relations type-check cleanly at level 5 |
| `php artisan test` (apps/api) | Automated, local | PASS | 51 tests, 136 assertions (20 new: 8 `PermissionMechanismTest`, 7 `AdminBackofficeAuthorizationTest`, 2 `AdminUserSeederTest`, 3 `RolePermissionSeederTest`; 31 pre-existing Phase 3/4 tests unaffected in behavior, `AdminLoginTest` updated only for the retired `admin()` factory state) |
| Migrations (`migrate:fresh`) against SQLite | Automated, local | PASS | All 9 migrations (5 pre-existing + 4 new) run cleanly |
| `RolePermissionSeeder` / `AdminUserSeeder` / `DatabaseSeeder` chain | Automated, local | PASS | Confirmed idempotent; Administrator role correctly assigned to the seeded local Admin account |
| Manual: `php artisan serve` + curl — `/login`, `/api/v1/health`, `/api/v1/auth/login` | Manual | PASS | Login response confirmed to include `"role":"administrator"`; `is_admin` absent |
| Manual: `tinker` — role/permission/Gate behavior | Manual | PASS | Administrator `can('admin.access')` → true; Staff → false; `Schema::hasColumn('users','is_admin')` → false |
| Docker build (real, committed `docker/php/Dockerfile` — unchanged by this phase) | Automated, local | BLOCKED (local only) | Same documented sandbox network-policy limitation as Phase 4A (`apt-get` → `deb.debian.org` blocked) — not a regression, not caused by this phase. Confirmed via a temporary, uncommitted Dockerfile variant skipping only that step (same technique as Phase 4A), discarded after use. |
| Full Docker stack (temporary Dockerfile variant, same method as Phase 4A) | Automated, local | PASS | All 3 containers up, `mysql` healthy |
| Migrations against real MySQL 8.4 (Docker) | Automated, local | PASS | All 9 migrations, including the 4 new Phase 5 ones, ran cleanly against MySQL |
| `AdminUserSeeder` inside Docker (MySQL) | Automated, local | PASS | Administrator role assigned correctly |
| `php artisan test` inside Docker (MySQL-configured env; tests still isolated to SQLite per `phpunit.xml`) | Automated, local | PASS | 51/51, 136 assertions |
| `vendor/bin/pint --test` / `vendor/bin/phpstan analyse` inside Docker | Automated, local | PASS | Pint clean (57 files); PHPStan 0 errors |
| `GET /api/v1/health`, `GET /login`, `POST /api/v1/auth/login` through Nginx (Docker) | Automated, local | PASS | All `200`; login response includes the new `role` field via a full MySQL round-trip |
| No Staff/Clients/Projects/Leave/Tasks/Work Logs/Messaging or other business module introduced | Manual | PASS | Confirmed by reviewing the full staged diff before commit |
| No third-party RBAC package introduced | Manual | PASS | `composer.json` diff contains no new dependencies |
| UAT-05-01 — Admin: sign in with the seeded Administrator account, reach `/home` | UAT, product owner | **PASS** | Tested on the DigitalOcean VPS Dockerized environment. See `docs/testing/UAT_LOG.md`; recorded by the product owner, not this session, per CLAUDE.md §7. |
| UAT-05-02 — Admin: a Staff-role account is denied Admin Backoffice access | UAT, product owner | **PASS** | Tested on the DigitalOcean VPS Dockerized environment, using a Staff-role test account created via the Phase 5 role system. See `docs/testing/UAT_LOG.md`; recorded by the product owner, not this session, per CLAUDE.md §7. |

## Phase 6 — Organization Structure

| Check | Type | Status | Notes |
|---|---|---|---|
| `composer validate --strict` (apps/api) | Automated, local | PASS | unaffected — no new dependencies added |
| `vendor/bin/pint --test` (apps/api) | Automated, local | PASS | includes all new Department/Team/Position/authorization code and tests |
| `vendor/bin/phpstan analyse` (apps/api) | Automated, local | PASS | 0 errors at level 5 |
| `php artisan test` (apps/api) | Automated, local | PASS | 93 tests, 249 assertions (42 new: 14 `DepartmentTest`, 11 `TeamTest`, 9 `PositionTest`, 6 `OrganizationAuthorizationTest`, 2 new in `RolePermissionSeederTest`; 51 pre-existing Phase 1–5 tests unaffected in behavior — full regression suite healthy) |
| Migrations (`migrate:fresh`) against SQLite | Automated, local | PASS | All 12 migrations (9 pre-existing + 3 new) run cleanly |
| `RolePermissionSeeder` (extended) / `AdminUserSeeder` chain | Automated, local | PASS | New `organization.view`/`organization.manage` permissions created; `organization.view` correctly attached to Manager and Staff; confirmed idempotent |
| Manual: `php artisan serve` + curl — full CRUD smoke test | Manual | PASS | Administrator login → create Department → list Departments (with `teams_count`/`positions_count`) → create Team scoped to that Department (via its `public_id`) → attempt to delete the Department while the Team exists (`409`, correctly rejected) → fetch a Department by its internal numeric id (`404`, confirming `public_id`-only route binding) — all through a real HTTP server, not just PHPUnit's in-process client |
| No Staff/Clients/Projects/Leave/Tasks/Work Logs/Messaging or other business module introduced | Manual | PASS | Confirmed by reviewing the full staged diff before commit |
| No department hierarchy / no many-to-many Team↔Department | Manual | PASS | `teams.department_id`/`positions.department_id` are single nullable FKs; confirmed by schema review and tests (`test_team_names_can_repeat_across_different_departments`, etc.) |
| Docker validation | — | NOT RUN (this session) | This session's sandbox could not reach `deb.debian.org` for an image *build* (same pre-existing, already-documented limitation as Phases 4A/5 — see `docs/handoffs/V1_PHASE_04A_HANDOFF.md`/`V1_PHASE_05_HANDOFF.md`); given this session's *additional* difficulty reaching `api.github.com` for `composer install` itself (see the handoff's Environment/Deviations section), Docker-based re-verification was not attempted this session. All checks above were run directly (non-Docker). Since this phase changed no Docker configuration, the Phase 5 Docker verification (MySQL 8.4, Nginx, full stack) remains the last genuine Docker confirmation; a future session should re-verify Phase 6's migrations/tests inside Docker when network conditions allow, per CLAUDE.md §5. |
| Backend CI workflow | Automated, GitHub Actions | NOT RUN (this session) | Unlike Phases 2–5, no PR was opened this session (this session's operating instructions direct not to open one unless the user explicitly asks) and no push to `main` was made, so the path-filtered `pull_request`/`push`-to-`main` trigger (DEC-015) never fired. All of the same commands the workflow runs were executed directly and passed (see rows above) — this is a CI-confirmation gap relative to prior phases, not a quality gap. Opening a PR for this branch in a future session will produce a real run to record here. |
| UAT | — | NOT RUN | No UAT scenario recorded yet for Phase 6 — see `docs/testing/UAT_LOG.md`. This phase introduced no Admin Backoffice UI, so there is nothing yet for the product owner to click through; UAT for organization structure becomes meaningful once a later phase (Staff Directory, or an Admin UI phase) actually surfaces it visually. |

## Phase 7 — Staff

| Check | Type | Status | Notes |
|---|---|---|---|
| `composer validate --strict` (apps/api) | Automated, local | PASS | unaffected — no new dependencies added |
| `vendor/bin/pint --test` (apps/api) | Automated, local | PASS | includes all new Staff code and tests (initial run flagged two files for import-ordering; fixed by `vendor/bin/pint` and re-verified clean) |
| `vendor/bin/phpstan analyse` (apps/api) | Automated, local | PASS | 0 errors at level 5 |
| `php artisan test` (apps/api) | Automated, local | PASS | 135 tests, 370 assertions (42 new: 26 `StaffTest`, 6 `StaffAuthorizationTest`, 3 new delete-protection tests across `DepartmentTest`/`TeamTest`/`PositionTest`, 2 new in `RolePermissionSeederTest`, plus the surviving Phase 1–6 suite unaffected in behavior — full regression suite healthy). Two real bugs were caught by this suite and fixed before it went green — see Deviations in the handoff. |
| Migrations (`migrate:fresh`) against SQLite | Automated, local | PASS | All 13 migrations (12 pre-existing + 1 new `staff` table) run cleanly |
| `RolePermissionSeeder` (extended) / `AdminUserSeeder` chain | Automated, local | PASS | New `staff.view`/`staff.manage` permissions created; `staff.view` correctly attached to Manager and Staff; confirmed idempotent |
| Manual: `php artisan serve` + curl — full CRUD + relationship smoke test | Manual | PASS | Administrator login → create Department/Team/Position → create Staff assigned only to the Team (department correctly auto-derived from the Team's own department in the response and the DB) → list staff → attempt to delete the Department while Staff reference it (`409`, correctly rejected) → create a second Staff reporting to the first (`manager` field correctly nested) → attempt to delete the manager while they have a direct report (`409`, correctly rejected) → attempt to set a staff member as their own manager (`422`, correctly rejected) — all through a real HTTP server, not just PHPUnit's in-process client |
| No payroll/attendance/leave/HR-document/performance-review/project-task/messaging/client functionality introduced | Manual | PASS | Confirmed by reviewing the full staged diff before commit |
| No Admin Backoffice CRUD UI | Manual | PASS | Confirmed — API/backend only, consistent with Phase 6's precedent |
| Docker validation | — | NOT RUN (this session) | No Docker configuration changed this phase; consistent with Phase 6's session, Docker-based re-verification was not attempted. The last genuine Docker confirmation remains Phase 5's. |
| GitHub Actions CI | — | NOT RUN (this session) | No PR opened and no push to `main` this session (same as Phase 6's session) — the path-filtered trigger (DEC-015) never fired. All commands the workflow runs were executed directly and passed (rows above). |
| UAT | — | NOT RUN | No UAT scenario recorded yet for Phase 7 — see `docs/testing/UAT_LOG.md`. This phase introduced no Admin Backoffice UI, so there is nothing yet for the product owner to click through visually. |

## Phase 8 — Clients & Contacts

| Check | Type | Status | Notes |
|---|---|---|---|
| `composer validate --strict` (apps/api) | Automated, local | PASS | unaffected — no new dependencies added |
| `vendor/bin/pint --test` (apps/api) | Automated, local | PASS | `{"tool":"pint","result":"passed"}` — includes all new Client/Contact code and tests |
| `vendor/bin/phpstan analyse` (apps/api) | Automated, local | PASS | `{"tool":"phpstan","result":"passed","errors":0}` at level 5 |
| `php artisan test` (apps/api) | Automated, local | PASS | `{"tool":"phpunit","result":"passed","tests":176,"passed":176,"assertions":495}` (41 new: 16 `ClientTest`, 17 `ContactTest`, 6 `ClientsAuthorizationTest`, 2 new in `RolePermissionSeederTest`; 135 pre-existing Phase 1–7 tests unaffected in behavior — full regression suite healthy) |
| Migrations (`migrate:fresh`) against SQLite | Automated, local | PASS | All 15 migrations (13 pre-existing + 2 new: `clients`, `contacts`) run cleanly |
| `RolePermissionSeeder` (extended) / `AdminUserSeeder` chain | Automated, local | PASS | New `clients.view`/`clients.manage` permissions created; `clients.view` correctly attached to Manager and Staff; confirmed idempotent |
| Manual: `php artisan serve` + curl — full CRUD + relationship smoke test | Manual | PASS | Administrator login → create Client (`CL-0001`, with address/email/website) → list clients (`contacts_count: 0`) → create a primary Contact for that Client → create a second Contact also marked primary → confirmed the first Contact's `is_primary` was automatically cleared (only one primary per client, enforced in a DB transaction) → attempted to delete the Client while Contacts still reference it (`409`, correctly rejected) → confirmed an unauthenticated request to `/api/v1/clients` returns `401` — all through a real HTTP server, not just PHPUnit's in-process client |
| No projects/opportunities/sales-pipeline/leads/quotations/contracts/invoices/billing/payments/tasks/work-logs/portals/tickets/campaigns/messaging/notifications/documents/account-manager-ACLs/custom-fields/activity-timeline functionality introduced | Manual | PASS | Confirmed by reviewing the full staged diff before commit |
| No Admin Backoffice CRUD UI | Manual | PASS | Confirmed — API/backend only, consistent with Phase 6/7's precedent |
| Docker validation | — | NOT RUN (this session) | No Docker configuration changed this phase; consistent with Phase 6/7's sessions, Docker-based re-verification was not attempted. The last genuine Docker confirmation remains Phase 5's. |
| GitHub Actions CI | — | NOT RUN (this session) | No PR opened and no push to `main` this session (same as Phase 6/7's sessions) — the path-filtered trigger (DEC-015) never fired. All commands the workflow runs were executed directly and passed (rows above). |
| UAT | — | NOT RUN | No UAT scenario recorded yet for Phase 8 — see `docs/testing/UAT_LOG.md`. This phase introduced no Admin Backoffice UI, so there is nothing yet for the product owner to click through visually. |

## Phase 9 — Staff Status & Location Check-in

| Check | Type | Status | Notes |
|---|---|---|---|
| `composer validate --strict` (apps/api) | Automated, local | PASS | `./composer.json is valid` — unaffected, no new dependencies added |
| `vendor/bin/pint --test` (apps/api) | Automated, local | PASS | `{"tool":"pint","result":"passed"}` — includes all new Staff Operations code and tests (initial run flagged one test file for trailing-comma style; fixed by `vendor/bin/pint` and re-verified clean) |
| `vendor/bin/phpstan analyse` (apps/api) | Automated, local | PASS | `{"tool":"phpstan","result":"passed","errors":0}` at level 5 |
| `php artisan test` (apps/api) | Automated, local | PASS | `{"tool":"phpunit","result":"passed","tests":216,"passed":216,"assertions":623}` (40 new: 11 `OperationalStatusTest`, 17 `CheckInTest`, 9 `StaffOperationsAuthorizationTest`, 2 new in `RolePermissionSeederTest`, 1 new in `StaffTest`; 176 pre-existing Phase 1–8 tests unaffected in behavior — full regression suite healthy) |
| Migrations (`migrate:fresh`) against SQLite | Automated, local | PASS | All 17 migrations (15 pre-existing + 2 new: `staff_statuses`, `staff_checkins`) run cleanly |
| `RolePermissionSeeder` (extended) / `AdminUserSeeder` chain | Automated, local | PASS | New `staff-status.view`/`staff-status.manage`/`location.view`/`location.manage` permissions created; `staff-status.view` attached to Manager and Staff, `location.view` attached to Manager only; confirmed idempotent |
| Manual: `php artisan serve` + curl — self-service, manager scoping, admin correction, deletion smoke test | Manual | PASS | Staff-linked user login → `POST /me/status` (`in_field`, `201`) → `GET /me/status` (current-first list, `200`) → `POST /me/check-ins` (coordinates + label, `201`) → `GET /me/check-ins` (`200`) → Manager login → `GET /staff/{direct-report}/check-ins` (`200`) → `GET /staff/{non-report}/check-ins` (`403`, correctly scoped) → Administrator login → `POST /staff/{other}/status` (correction, `201`) → `GET /staff/{other}` confirms `operational_status: "off_duty"` in the Staff Directory response → Staff attempts `DELETE /check-ins/{public_id}` on their own check-in (`403`, `location.manage` is Administrator-only) → Administrator `DELETE /check-ins/{public_id}` (`204`) → unauthenticated `GET /me/status` (`401`) — all through a real HTTP server, not just PHPUnit's in-process client |
| No attendance/timesheet/payroll/leave/biometric/continuous-GPS/geocoding/messaging/notification functionality introduced | Manual | PASS | Confirmed by reviewing the full staged diff before commit |
| `Staff.status` (employment) unaffected by operational status changes | Automated | PASS | Asserted directly in `OperationalStatusTest::test_setting_operational_status_never_changes_employment_status` |
| No Admin Backoffice CRUD UI or Flutter mobile screens | Manual | PASS | Confirmed — API/backend only, consistent with Phase 6/7/8's precedent |
| Docker validation | — | NOT RUN (this session) | No Docker configuration changed this phase; consistent with prior sessions, Docker-based re-verification was not attempted. The last genuine Docker confirmation remains Phase 5's. |
| GitHub Actions CI | — | NOT RUN (this session) | No PR opened and no push to `main` this session — the path-filtered trigger (DEC-015) never fired. All commands the workflow runs were executed directly and passed (rows above). |
| UAT | — | NOT RUN | No UAT scenario recorded yet for Phase 9 — see `docs/testing/UAT_LOG.md` (`UAT-09-01`, `UAT-09-02`). This phase introduced no Admin Backoffice UI or Flutter mobile screens, so there is nothing yet for the product owner to click through visually. |

## Phase 10 — Projects & Project Membership

| Check | Type | Status | Notes |
|---|---|---|---|
| `composer validate --strict` (apps/api) | Automated, local | PASS | `./composer.json is valid` — unaffected, no new dependencies added |
| `vendor/bin/pint --test` (apps/api) | Automated, local | PASS | `{"tool":"pint","result":"passed"}` — clean on the first run, includes all new Projects/Project Membership code and tests |
| `vendor/bin/phpstan analyse` (apps/api) | Automated, local | PASS | `{"tool":"phpstan","result":"passed","errors":0}` at level 5 |
| `php artisan test` (apps/api) | Automated, local | PASS | `{"tool":"phpunit","result":"passed","tests":265,"passed":265,"assertions":766}` (49 new: 23 `ProjectTest`, 13 `ProjectMembershipTest`, 9 `ProjectsAuthorizationTest`, 2 new in `RolePermissionSeederTest`, 1 new in `ClientTest`, 1 new in `StaffTest`; 216 pre-existing Phase 1–9 tests unaffected in behavior — full regression suite healthy). A real routing bug (Laravel's automatic nested-route-binding scoping guessing a nonexistent `Project::staff()` relation) was caught by 3 failing tests and fixed before this suite went green — see Deviations in the handoff. |
| Migrations (`migrate:fresh`) against SQLite | Automated, local | PASS | All 19 migrations (17 pre-existing + 2 new: `projects`, `project_memberships`) ran cleanly |
| `RolePermissionSeeder` (extended) / `AdminUserSeeder` chain | Automated, local | PASS | New `projects.view`/`projects.manage` permissions created (14 total); `projects.view` correctly attached to Manager only, confirmed absent from Staff via `tinker`; confirmed idempotent |
| Manual: `php artisan serve` + curl — full CRUD + membership + delete-protection smoke test | Manual | PASS | Administrator login → create Client → create Project linked to that Client (minimal nested Client object confirmed in response) → create Staff → add as `project_lead` (`201`) → list members (`200`) → change role to `member` (`200`) → attempt to delete the Project while the member still existed (`409`) → remove the member (`204`) → delete the now-empty Project (`204`) — all through a real HTTP server, not just PHPUnit's in-process client |
| Manual: Staff-scoped visibility smoke test | Manual | PASS | A Staff-linked, Staff-role account was added to one of two Projects; `GET /api/v1/projects` correctly returned only that one Project (with the correct `my_role`); `GET` on the other Project returned `403`; an unauthenticated request returned `401` |
| No tasks/work-logs/time-tracking/billing/quotations/contracts/CRM-pipeline/file-management/messaging/notifications/calendar/Gantt/budgeting/utilization/performance-scoring/approval-workflow functionality introduced | Manual | PASS | Confirmed by reviewing the full staged diff before commit |
| No Admin Backoffice CRUD UI or Flutter mobile screens | Manual | PASS | Confirmed — API/backend only, consistent with Phase 6/7/8/9's precedent |
| Docker validation | — | NOT RUN (this session) | No Docker configuration changed this phase; consistent with prior sessions, Docker-based re-verification was not attempted. The last genuine Docker confirmation remains Phase 5's. |
| GitHub Actions CI | — | NOT RUN (this session) | No PR opened and no push to `main` this session — the path-filtered trigger (DEC-015) never fired. All commands the workflow runs were executed directly and passed (rows above). |
| UAT | — | NOT RUN | No UAT scenario recorded yet for Phase 10 — see `docs/testing/UAT_LOG.md` (`UAT-10-01`, `UAT-10-02`, `UAT-10-03`). This phase introduced no Admin Backoffice UI or Flutter mobile screens, so there is nothing yet for the product owner to click through visually. |

## Phase 11 — Tasks

| Check | Type | Status | Notes |
|---|---|---|---|
| `composer validate --strict` (apps/api) | Automated, local | PASS | `./composer.json is valid` — unaffected, no new dependencies added |
| `vendor/bin/pint --test` (apps/api) | Automated, local | PASS | `{"tool":"pint","result":"passed"}` — clean on the first run, includes all new Tasks code and tests |
| `vendor/bin/phpstan analyse` (apps/api) | Automated, local | PASS | `{"tool":"phpstan","result":"passed","errors":0}` at level 5 |
| `php artisan test` (apps/api) | Automated, local | PASS | `{"tool":"phpunit","result":"passed","tests":319,"passed":319,"assertions":917}` (54 new: 34 `TaskTest`, 15 `TasksAuthorizationTest`, 2 new in `RolePermissionSeederTest`, 1 new in `ProjectTest`, 2 new in `StaffTest`; 265 pre-existing Phase 1–10 tests unaffected in behavior — full regression suite healthy). One expected regression fix: `RolePermissionSeederTest::test_running_it_twice_does_not_duplicate_rows`'s hardcoded permission count updated from 14 to 16 to reflect the two new `tasks.*` permissions. |
| Migrations (`migrate:fresh --seed`) against SQLite | Automated, local | PASS | All 20 migrations (19 pre-existing + 1 new: `tasks`) ran cleanly; `RolePermissionSeeder` seeded successfully |
| `RolePermissionSeeder` (extended) | Automated, local | PASS | New `tasks.view`/`tasks.manage` permissions created (16 total); `tasks.view` correctly attached to Manager only, confirmed absent from Staff |
| Manual: `php artisan serve` + curl — independent-task CRUD + lifecycle smoke test | Manual | PASS | Administrator login → create an independent (no-Project) Task (`201`, `project: null`) → list (`200`) → view by `public_id` (`200`) → mark `completed` (`completed_at` auto-set) → attempt hard delete while `completed` (`409`) → reopen to `todo` (`completed_at` cleared to `null`) → hard delete now allowed (`204`) — all through a real HTTP server, not just PHPUnit's in-process client |
| Manual: Project Lead scoped authority + assignee self-service smoke test | Manual | PASS | A Staff-linked Project Lead created a Task inside their own Project with an assignee who is a Project member (`201`, minimal Project/assignee/creator shapes confirmed in the response) → the same Lead's attempt to create an independent Task was rejected (`403`) → the assignee updated only `status` (`200`) → the same assignee's attempt to also change `title` in one request was rejected (`403`, no partial update applied) → the assignee's attempt to `DELETE` the Task was rejected (`403`) |
| No work logs/time tracking/timesheets/billing/payroll/task comments/attachments/reactions/messaging/notifications/mentions/Kanban/configurable workflows/subtasks/dependencies/recurring tasks/calendars/reminders/milestones/activity timeline/audit stream/approval workflow functionality introduced | Manual | PASS | Confirmed by reviewing the full staged diff before commit |
| No Admin Backoffice CRUD UI or Flutter mobile screens | Manual | PASS | Confirmed — API/backend only, consistent with Phase 6/7/8/9/10's precedent |
| Docker validation | — | NOT RUN (this session) | No Docker configuration changed this phase; consistent with prior sessions, Docker-based re-verification was not attempted. The last genuine Docker confirmation remains Phase 5's. |
| GitHub Actions CI | — | NOT RUN (this session) | No PR opened and no push to `main` this session — the path-filtered trigger (DEC-015) never fired. All commands the workflow runs were executed directly and passed (rows above). |
| UAT | — | NOT RUN | No UAT scenario recorded yet for Phase 11 — see `docs/testing/UAT_LOG.md` (`UAT-11-01`, `UAT-11-02`, `UAT-11-03`). This phase introduced no Admin Backoffice UI or Flutter mobile screens, so there is nothing yet for the product owner to click through visually. |

## Phase 13 — Leave Management

| Check | Type | Status | Notes |
|---|---|---|---|
| `composer validate --strict` (apps/api) | Automated, local | PASS | `./composer.json is valid` — unaffected, no new dependencies added |
| `vendor/bin/pint --test` (apps/api) | Automated, local | PASS | `{"tool":"pint","result":"passed"}` — clean, includes all new Leave Management code and tests |
| `vendor/bin/phpstan analyse` (apps/api) | Automated, local | PASS | `{"tool":"phpstan","result":"passed","errors":0}` at level 5 |
| `php artisan test` (apps/api) | Automated, local | PASS | `{"tool":"phpunit","result":"passed","tests":467,"passed":467,"assertions":1273}` (100 new: 18 `LeaveTypeTest`, 35 `LeaveRequestTest`, 22 `LeaveRequestLifecycleTest`, 13 `LeaveBalanceTest`, 12 `LeaveAuthorizationTest`, 2 new in `RolePermissionSeederTest`; 367 pre-existing Phase 1–12 tests unaffected in behavior — full regression suite healthy). Two correctness issues were found and fixed before/via product-owner review: (1) a same-day-boundary overlap-detection defect from a plain string comparison against a `date`-cast column's SQLite storage format, and (2) a post-review-identified balance-model inconsistency — Administrator-created Leave Requests skipped the paid-leave balance check entirely, an unintended negative-balance override — corrected by extracting a single shared balance-check implementation used identically by self-service and Administrator creation. See the handoff's Deviations/Post-Review Correction sections. |
| Migrations (`migrate:fresh`) against SQLite | Automated, local | PASS | All 25 migrations (21 pre-existing + 4 new: `leave_types`, `leave_requests`, `leave_request_actions`, `leave_balances`) ran cleanly |
| `RolePermissionSeeder` (extended) | Automated, local | PASS | New `leave-types.view`/`leave-types.manage`/`leave-requests.view`/`leave-requests.manage` permissions created (22 total); `leave-types.view` confirmed on Manager and Staff, `leave-requests.view` confirmed Manager-only |
| Manual: `php artisan serve` + curl — full lifecycle smoke test | Manual | PASS | Administrator login → create Leave Type → set a Staff member's Leave Balance allocation → Staff submits a Leave Request → the Staff member's direct Manager approves it → Staff's `GET /api/v1/me/leave-balances` correctly reflects consumption (`used_days: 3`, `remaining_days: 7`) → Staff confirmed `403` on the top-level `/api/v1/leave-requests` → Staff/Leave-Type deletion both confirmed `409` while referenced → Staff cancels the approved (not-yet-started) request, which succeeds and appends a `cancelled` history entry alongside `submitted`/`approved` — all through a real HTTP server, not just PHPUnit's in-process client |
| Manual: `php artisan serve` + curl — post-review balance-integrity correction smoke test | Manual | PASS | Administrator on-behalf creation against a real running server: (1) inactive Staff member **with** a sufficient allocation → `201` (eligibility bypass intact); (2) Staff member with **no** allocation → `422` (`"Insufficient leave balance: 0 day(s) remaining..."`); (3) 2-day allocation, 5-day request → `422` (`"...2 day(s) remaining..."`); (4) same Staff member, 2-day request → `201`, then `GET .../leave-balances` correctly showed `used_days: 0, pending_days: 2, remaining_days: 0` — never negative |
| No payroll/attendance/time-tracking/Work-Log-integration/shift-scheduling/overtime/holiday-pay/accrual-engine/carry-forward/encashment/medical-attachments/multi-level-approval/calendar-sync/notifications/forecasting functionality introduced | Manual | PASS | Confirmed by reviewing the full staged diff before commit |
| No Admin Backoffice CRUD UI or Flutter mobile screens | Manual | PASS | Confirmed — API/backend only, consistent with Phase 6–12's precedent |
| Docker validation | — | NOT RUN (this session) | No Docker configuration changed this phase; consistent with prior sessions, Docker-based re-verification was not attempted. The last genuine Docker confirmation remains Phase 5's. |
| GitHub Actions CI | — | NOT RUN (this session) | No PR opened and no push to `main` this session — the path-filtered trigger (DEC-015) never fired. All commands the workflow runs were executed directly and passed (rows above). |
| UAT | — | NOT RUN | No UAT scenario recorded yet for Phase 13 — see `docs/testing/UAT_LOG.md` (`UAT-13-01`, `UAT-13-02`, `UAT-13-03`). This phase introduced no Admin Backoffice UI or Flutter mobile screens, so there is nothing yet for the product owner to click through visually. |

## Phase 14 — Announcements

| Check | Type | Status | Notes |
|---|---|---|---|
| `composer validate --strict` (apps/api) | Automated, local | PASS | `./composer.json is valid` — unaffected, no new dependencies added |
| `vendor/bin/pint --test` (apps/api) | Automated, local | PASS | `{"tool":"pint","result":"passed"}` — clean, includes all new Announcements code and tests (one file needed auto-fixing on first run, resolved via `vendor/bin/pint`; re-verified after the post-review correction) |
| `vendor/bin/phpstan analyse` (apps/api) | Automated, local | PASS | `{"tool":"phpstan","result":"passed","errors":0}` at level 5 |
| `php artisan test` (apps/api) | Automated, local | PASS | `{"tool":"phpunit","result":"passed","tests":535,"passed":535,"assertions":1421}` (68 new: 34 `AnnouncementTest`, 22 `MyAnnouncementTest`, 7 `AnnouncementsAuthorizationTest`, 2 new in `RolePermissionSeederTest`; 467 pre-existing Phase 1–13 tests unaffected in behavior — full regression suite healthy). Two tests initially failed on a test-harness artifact (`Sanctum::actingAs()` reusing the same in-memory `User` object across two simulated requests within one test, so its cached `staff` relation went stale after a mid-test `Staff` update) — not a real application bug, since every genuine HTTP request resolves the User fresh from the database; fixed by re-authenticating with `$user->fresh()` between the two requests. **Post-review correction:** the incorrect `test_administrator_can_edit_a_published_announcement` (asserting success) was replaced with tests asserting `409` for title/body/audience edits against a published Announcement, plus acknowledged-published-edit rejection and archive-preserves-acknowledgement coverage — see DEC-037's Correction and the Phase 14 handoff's §11. |
| Migrations (`migrate:fresh`) against SQLite | Automated, local | PASS | All 29 migrations (25 pre-existing + 4 new: `announcements`, `announcement_departments`, `announcement_teams`, `announcement_acknowledgements`) ran cleanly (no schema change was needed for the post-review correction) |
| `RolePermissionSeeder` (extended) | Automated, local | PASS | New `announcements.manage` permission created (23 total, Administrator-only — no companion `announcements.view`, confirmed neither Manager nor Staff holds it) |
| Manual: `php artisan serve` + curl — full lifecycle smoke test | Manual | PASS | Administrator login → create a Department-scoped draft Announcement → publish it → an eligible Staff member's `/me/announcements` feed contains it → that Staff member acknowledges it (idempotent, `acknowledged_at` populated) → an unrelated-Department Staff member's feed does **not** contain it and its detail endpoint returns `404` → the eligible Staff member confirmed `403` on the top-level `/api/v1/announcements` management endpoint → Administrator confirmed `acknowledgements_count: 1` on the management view → Administrator archives it → the archived Announcement disappears from the eligible Staff member's feed — all through a real HTTP server, not just PHPUnit's in-process client |
| Manual: `php artisan serve` + curl — post-review immutability correction smoke test | Manual | PASS | Administrator creates a draft, edits it successfully (draft editing intact) → publishes it → an eligible Staff member acknowledges it → Administrator attempts `PATCH` on the now-published Announcement → confirmed `409` → Administrator archives it → the acknowledgement record confirmed still present and unchanged → a further edit attempt on the archived Announcement confirmed still `409` — all through a real running server |
| No direct messaging/chat/comments/reactions/polls/social feed/attachments/rich-text/push-email-SMS-delivery/notification-queue/calendar/tasks/project-activity/mandatory-acknowledgement-compliance/engagement-analytics/revision-history/categories-tags/scheduled-publication/expiry functionality introduced | Manual | PASS | Confirmed by reviewing the full staged diff before commit |
| No Admin Backoffice CRUD UI or Flutter mobile screens | Manual | PASS | Confirmed — API/backend only, consistent with Phase 6–13's precedent |
| Docker validation | — | NOT RUN (this session) | No Docker configuration changed this phase; consistent with prior sessions, Docker-based re-verification was not attempted. The last genuine Docker confirmation remains Phase 5's. |
| GitHub Actions CI | — | NOT RUN (this session) | No PR opened and no push to `main` this session — the path-filtered trigger (DEC-015) never fired. All commands the workflow runs were executed directly and passed (rows above). |
| UAT | — | NOT RUN | No UAT scenario recorded yet for Phase 14 — see `docs/testing/UAT_LOG.md` (`UAT-14-01`, `UAT-14-02`, `UAT-14-03`). This phase introduced no Admin Backoffice UI or Flutter mobile screens, so there is nothing yet for the product owner to click through visually. |

## Phase 18 — Service Reports

| Check | Type | Status | Notes |
|---|---|---|---|
| `composer validate --strict` (apps/api) | Automated, local | PASS | `./composer.json is valid` — unaffected, no new dependencies added |
| `vendor/bin/pint --test` (apps/api) | Automated, local | PASS | `{"tool":"pint","result":"passed"}` — clean, includes all new Service Reports/Attachments code and tests |
| `vendor/bin/phpstan analyse` (apps/api) | Automated, local | PASS | `{"tool":"phpstan","result":"passed","errors":0}` at level 5 (two real type issues found and fixed during this phase — see the Phase 18 handoff's Deviations section) |
| `php artisan migrate:fresh` (apps/api) | Automated, local | PASS | All 40 migrations (36 pre-existing + 4 new: `service_reports`, `service_report_participants`, `service_report_actions`, `attachments`) ran cleanly against SQLite |
| `php artisan migrate:fresh --seed` (apps/api) | Automated, local | PASS | `RolePermissionSeeder` ran cleanly — no new permission was introduced this phase, so the catalog is unchanged |
| `php artisan test` (apps/api) — Service Reports only | Automated, local | PASS | `{"tool":"phpunit","result":"passed","tests":68,"passed":68,"assertions":142}` — `ServiceReportTest` (creation/coherence/eligibility/filters), `ServiceReportLifecycleTest` (workflow/visibility/review authority/history), `ServiceReportAttachmentTest` (upload/download/removal/cleanup), plus new relational-integrity tests added to `ClientTest`/`ProjectTest`/`TaskTest`/`StaffTest` |
| `php artisan test` (apps/api) — full suite | Automated, local | PASS | `{"tool":"phpunit","result":"passed","tests":780,"passed":780,"assertions":2089}` — full Phase 1–17 regression suite unaffected, run together with this phase's new tests |
| No Flutter mobile UI, Admin Backoffice UI, offline entry, external/customer portal, digital signatures, PDF generation, printable export, report numbering, Scheduler integration, Notification/Messaging integration, parts/materials inventory, labor/time tracking, GPS capture, antivirus scanning, or multi-level approval introduced | Manual | PASS | Confirmed by reviewing the full staged diff before commit |
| Docker validation | — | NOT RUN (this session) | No Docker configuration changed this phase; consistent with recent sessions, Docker-based re-verification was not attempted. |
| GitHub Actions CI | — | NOT RUN (this session) | No PR opened and no push to `main` this session — the path-filtered trigger (DEC-015) never fired. All commands the workflow runs were executed directly and passed (rows above). |
| UAT | — | NOT RUN | No UAT scenario recorded yet for Phase 18 — see `docs/testing/UAT_LOG.md` (`UAT-18-01` through `UAT-18-04`). This phase introduced no Admin Backoffice UI or Flutter mobile screens, so there is nothing yet for the product owner to click through visually. |

*(Future phases append their own section above this line, oldest first.)*
