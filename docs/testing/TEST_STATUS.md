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
| `php artisan test` (apps/api) — Service Reports only | Automated, local | PASS | `{"tool":"phpunit","result":"passed","tests":82,"passed":82,"assertions":173}` — `ServiceReportTest` (creation/coherence/eligibility/filters), `ServiceReportLifecycleTest` (workflow/visibility/review authority/history, plus 14 post-review draft relationship-editability tests — see below), `ServiceReportAttachmentTest` (upload/download/removal/cleanup), plus new relational-integrity tests added to `ClientTest`/`ProjectTest`/`TaskTest`/`StaffTest` |
| `php artisan test` (apps/api) — full suite | Automated, local | PASS | `{"tool":"phpunit","result":"passed","tests":794,"passed":794,"assertions":2120}` — full Phase 1–17 regression suite unaffected, run together with this phase's new tests |
| Post-review correction: draft Client/Project/Task editability (apps/api) | Automated, local | PASS | Implementation review found the original delivery made Client/Project/Task immutable immediately after creation — stricter than approved. Corrected so a `draft` report's Client/Project/Task remain correctable (creator/Administrator, re-validated for coherence on every update) until submission; `creator_staff_id` remains immutable at every status. All quality gates and the full suite (rows above) re-run clean after the correction — see DEC-041's Correction and the Phase 18 handoff's Deviations section. |
| No Flutter mobile UI, Admin Backoffice UI, offline entry, external/customer portal, digital signatures, PDF generation, printable export, report numbering, Scheduler integration, Notification/Messaging integration, parts/materials inventory, labor/time tracking, GPS capture, antivirus scanning, or multi-level approval introduced | Manual | PASS | Confirmed by reviewing the full staged diff before commit |
| Docker validation | — | NOT RUN (this session) | No Docker configuration changed this phase; consistent with recent sessions, Docker-based re-verification was not attempted. |
| GitHub Actions CI | — | NOT RUN (this session) | No PR opened and no push to `main` this session — the path-filtered trigger (DEC-015) never fired. All commands the workflow runs were executed directly and passed (rows above). |
| UAT | — | NOT RUN | No UAT scenario recorded yet for Phase 18 — see `docs/testing/UAT_LOG.md` (`UAT-18-01` through `UAT-18-04`). This phase introduced no Admin Backoffice UI or Flutter mobile screens, so there is nothing yet for the product owner to click through visually. |

## Phase 19 — Incident Reports

| Check | Type | Status | Notes |
|---|---|---|---|
| `composer validate --strict` (apps/api) | Automated, local | PASS | `./composer.json is valid` — unaffected, no new dependencies added |
| `vendor/bin/pint --test` (apps/api) | Automated, local | PASS | `{"tool":"pint","result":"passed"}` — clean, includes all new Incident Reports code and tests (an initial run found two auto-fixable style issues — import ordering, a fully-qualified enum reference in a test — both fixed by `vendor/bin/pint`, re-verified clean) |
| `vendor/bin/phpstan analyse` (apps/api) | Automated, local | PASS | `{"tool":"phpstan","result":"passed","errors":0}` at level 5 (one real type issue found and fixed during this phase — a nullsafe call on `IncidentReport::occurred_at`, a non-nullable property, in `IncidentReportResource`; corrected to a plain `->` mirroring `ServiceReportResource::service_date`'s identical non-nullable precedent) |
| `php artisan migrate:fresh` (apps/api) | Automated, local | PASS | All 44 migrations (40 pre-existing + 4 new: `incident_reports`, `incident_report_participants`, `incident_report_actions`, and the `attachments`-extension migration) ran cleanly against SQLite, including the migration that widens `attachments.service_report_id` to nullable via `->change()` |
| `php artisan migrate:fresh --seed` (apps/api) | Automated, local | PASS | `RolePermissionSeeder` ran cleanly — no new permission was introduced this phase, so the catalog is unchanged |
| `php artisan test` (apps/api) — Incident Reports only | Automated, local | PASS | `{"tool":"phpunit","result":"passed","tests":120,"passed":120,"assertions":248}` — `IncidentReportTest` (creation/optional-anchors/coherence/filters/pagination), `IncidentReportLifecycleTest` (visibility/assignment/editing-authority/workflow/resolution/deletion/action-history — 110 original + 10 added in the post-review clarification round below), `IncidentReportAttachmentTest` (upload/download/removal/cleanup/second-owner-type compatibility), plus new relational-integrity tests added to `ClientTest`/`ProjectTest`/`TaskTest`/`StaffTest` |
| `php artisan test` (apps/api) — full suite | Automated, local | PASS | `{"tool":"phpunit","result":"passed","tests":920,"passed":920,"assertions":2380}` — full Phase 1–18 regression suite unaffected (794 tests, matching Phase 18's own recorded count, plus this phase's 120 + 6 new relational-integrity tests = 920), run together with this phase's new tests |
| Post-review clarification: assign/reassign final-state (`resolved`/`closed`) immutability (apps/api) | Automated, local | PASS | Implementation review requested explicit confirmation that `assign`/`reassign` respect `resolved`/`closed` immutability, including for Administrator, restored only via `reopen`. Inspection confirmed `IncidentReportController::assign()`/`reassign()` already called `status->isContentMutable()` unconditionally — no controller change was needed. Closed a real test-coverage gap instead: 10 new tests added (closed-state 409 for both endpoints, `under_investigation` reassign success, Administrator receiving the same 409 in three final-state scenarios, and reassignment succeeding again — with a `reassigned` history row — after `reopen` from both `resolved` and `closed`), all passing against the unmodified controller. `docs/DECISIONS.md` (DEC-042) and `docs/phases/V1_PHASE_19_DEFINITION.md` were updated to state the rule explicitly. This handoff's date was also corrected (`2026-09-14` → `2026-09-13`, a generated-date slip). All quality gates and the full suite (rows above) re-run clean after this round — see the Phase 19 handoff's Deviations section. |
| Two real bugs found and fixed during this session (apps/api) | Automated, local | PASS (after fix) | (1) `occurred_at` needed an explicit `Attribute` set-mutator (`Carbon::parse($value)->utc()`) on `IncidentReport` — Eloquent's `datetime` cast alone does not convert timezones, only reformats for storage, so a submitted non-UTC offset was being stored as the same wall-clock time mislabeled UTC; a dedicated test (`test_occurred_at_is_returned_in_iso8601_utc`) caught this. (2) `IncidentReportFactory`'s `immediate_action_taken` uses `fake()->optional()->sentence()` (mirroring `ServiceReportFactory`'s realistic-variability style) — one resolution-requirement test needed to explicitly pin both `corrective_action`/`immediate_action_taken` to `null` in its fixture to make the "at least one required" precondition deterministic, rather than relying on the factory's random default. Both fixes are in the diff; all gates above re-run clean afterward. |
| Existing Service Report attachments unaffected by the shared-table extension | Automated, local | PASS | Verified directly by `test_a_service_report_attachment_is_unaffected_by_the_incident_report_extension`/`test_incident_report_and_service_report_attachments_coexist_independently` in `IncidentReportAttachmentTest`, and by the full Phase 18 `ServiceReport*Test` suites passing unchanged within the full-suite run above |
| No Flutter mobile UI, Admin Backoffice UI, offline entry, external/customer incident submission or portal, structured named-witness records, multi-investigator assignment, confidentiality flag, HR/harassment/whistleblower workflow, medical/injury-records subsystem, workers'-compensation workflow, regulatory reporting, digital signatures, PDF generation, printable export, incident numbering, Notification/Messaging/Scheduler integration, automatic Service Report/Work Log creation, SLA/escalation timers, reminders, or cron/queue/background-worker/Redis/WebSocket infrastructure introduced | Manual | PASS | Confirmed by reviewing the full staged diff before commit |
| Docker validation | — | NOT RUN (this session) | No Docker configuration changed this phase; consistent with recent sessions, Docker-based re-verification was not attempted. |
| GitHub Actions CI | — | NOT RUN (this session) | No PR opened and no push to `main` this session — the path-filtered trigger (DEC-015) never fired. All commands the workflow runs were executed directly and passed (rows above). |
| UAT | — | NOT RUN | No UAT scenario recorded yet for Phase 19 — see `docs/testing/UAT_LOG.md` (`UAT-19-01` through `UAT-19-05`). This phase introduced no Admin Backoffice UI or Flutter mobile screens, so there is nothing yet for the product owner to click through visually. |

## Phase 20 — Admin Dashboard & Reporting

| Check | Type | Status | Notes |
|---|---|---|---|
| `composer validate --strict` (apps/api) | Automated, local | PASS | `./composer.json is valid` — unaffected, no new dependencies added |
| `vendor/bin/pint --test` (apps/api) | Automated, local | PASS | `{"tool":"pint","result":"passed"}` — clean after one auto-fix pass (import ordering/brace style/quote style across 4 new files, applied via `vendor/bin/pint`), includes all new Dashboard/Reports code and tests |
| `vendor/bin/phpstan analyse` (apps/api) | Automated, local | PASS | `{"tool":"phpstan","result":"passed","errors":0}` at level 5 — one real generic-covariance issue found and fixed during this phase: `App\Support\Reporting\EnumStatusCounts::forColumn()`'s `Builder<Model>` parameter rejected a caller's `Builder<Project>`/`Builder<Task>`/`Builder<ServiceReport>`/`Builder<IncidentReport>` (PHPStan's Builder template is not covariant); corrected by making the method itself generic (`@template TModel of Model`, `@param Builder<TModel> $query`) rather than widening or ignoring the error |
| `php artisan migrate:fresh` (apps/api) | Automated, local | PASS | All 44 migrations ran cleanly against SQLite — **zero new migrations**, confirming Phase 20 introduced no schema change at all |
| `php artisan migrate:fresh --seed` (apps/api) | Automated, local | PASS | `RolePermissionSeeder` ran cleanly — no new permission was introduced this phase, so the catalog is unchanged |
| `php artisan test` (apps/api) — Phase 20 only | Automated, local | PASS | `{"tool":"phpunit","result":"passed","tests":80,"passed":80,"assertions":278}` — `DashboardTest` (27 tests: role-scoping for every section, period defaulting/company-timezone boundary, canonical overdue/open definitions, upcoming-schedule reuse) plus `StaffDirectoryReportTest`/`WorkLogReportTest`/`LeaveRequestReportTest`/`ProjectReportTest`/`TaskReportTest`/`ServiceReportReportTest`/`IncidentReportReportTest` (8/8/8/7/7/8/7 tests — visibility, filters, pagination, no-internal-id shape, CSV parity, formula-injection mitigation, per resource) |
| `php artisan test` (apps/api) — full suite | Automated, local | PASS | `{"tool":"phpunit","result":"passed","tests":1000,"passed":1000,"assertions":2658}` — full Phase 1–19 regression suite unaffected (920 tests, matching Phase 19's own recorded count, plus this phase's 80 = 1000), run together with this phase's new tests; confirms no existing Phase 7–19 controller, trait, or route was modified or regressed |
| Two real test-only bugs found and fixed during this session (apps/api) | Automated, local | PASS (after fix) | (1) Two `DashboardTest` assertions compared `work_logs.total_hours` against a float literal (`3.0`/`1.0`) via strict `assertJsonPath` — PHP's `json_encode()` renders a whole-number float without `JSON_PRESERVE_ZERO_FRACTION` as a bare integer (`3`, not `3.0`), so the *value* was correct but the strict-identity assertion wasn't; fixed by asserting numeric equality with a delta instead. (2) `test_incident_open_count_and_severity_breakdown_for_administrator` asserted `open_count: 2` while seeding a fourth, out-of-period `reported` (therefore open) incident — `open_count` is deliberately point-in-time and correctly counted it as a third open incident; the test's own expectation was wrong, not the implementation. Fixed by making that specific seeded incident `resolved()` (isolating the assertion to proving `by_severity_in_period` excludes out-of-period data) and adding a new, dedicated test (`test_incident_open_count_is_point_in_time_and_includes_open_incidents_outside_the_period`) that explicitly proves the point-in-time behavior the original test had accidentally been contradicting. Neither finding required any change to `DashboardController`/`IncidentReportVisibility`/application code — both were test-assertion corrections. |
| Two real CSV-formatting test-assertion corrections (apps/api) | Automated, local | PASS (after fix) | PHP's `fputcsv()` (this PHP 8.4 build) quotes any field containing a space (RFC 4180-compliant but stricter than assumed) — three CSV tests (`StaffDirectoryReportTest`/`WorkLogReportTest`/`LeaveRequestReportTest`) originally asserted an unquoted, comma-joined header substring; fixed by parsing the header line with `str_getcsv()` before comparing. `ServiceReportReportTest`'s stranger-sees-nothing test called `assertSee()` against a `StreamedResponse`, which Laravel's test helpers don't buffer into `getContent()` the way `assertSee()` expects (`streamedContent()` exists specifically for this reason); fixed by asserting against `streamedContent()` directly. `App\Support\Reporting\CsvExport` itself needed no change — every finding was in test assertions, not the CSV writer. |
| No new migration, table, permission, or index (apps/api) | Manual | PASS | Confirmed by reviewing the full staged diff before commit — `git diff` shows zero files under `database/migrations/`; `RolePermissionSeederTest`'s hardcoded permission count is unaffected (unchanged from Phase 19) |
| Governing "never widen source visibility" rule spot-checked end-to-end | Automated | PASS | `DashboardTest`/`*ReportTest` explicitly assert: a plain Staff member sees only their own Work Logs/Leave Requests/Tasks/Projects (never company-wide); a Manager sees only direct reports' Work Logs/Leave/Incidents (never company-wide) where the source module itself scopes that way; a Project Lead gains Service Report visibility but explicitly gains **no** Incident Report visibility merely from the linked Project (`test_a_project_lead_gains_no_incident_report_visibility_merely_from_the_linked_project_unlike_service_reports`); a stranger to a Service/Incident Report sees a `0`-row/header-only CSV export, not merely a `0`-row JSON list, proving the existence of a report they cannot see is unobservable through either surface |
| No Flutter mobile UI, Admin Backoffice UI, Excel/PDF/printable export, employee rankings/performance/productivity/utilization scoring, attendance/timekeeping analytics, Announcement/Notification/Messaging reporting, Audit Log reporting, Master Data/Application Settings management, or reporting infrastructure (caching/queues/materialized views/snapshot tables) introduced | Manual | PASS | Confirmed by reviewing the full staged diff before commit |
| Docker validation | — | NOT RUN (this session) | No Docker configuration changed this phase; consistent with recent sessions, Docker-based re-verification was not attempted. |
| GitHub Actions CI | — | NOT RUN (this session) | No PR opened this session — the path-filtered `pull_request` trigger (DEC-015) never fired. All commands the workflow runs were executed directly and passed (rows above). |
| UAT | — | NOT RUN | No UAT scenario recorded yet for Phase 20 — see `docs/testing/UAT_LOG.md` (`UAT-20-01` through `UAT-20-06`). This phase introduced no Admin Backoffice UI or Flutter mobile screens, so there is nothing yet for the product owner to click through visually; ready for direct API review. |

## Phase 21 — Integration Audit

| Check | Type | Status | Notes |
|---|---|---|---|
| `composer validate --strict` (apps/api) | Automated, local | PASS | `./composer.json is valid` — unaffected, no new dependencies added |
| `vendor/bin/pint --test` (apps/api) | Automated, local | PASS | `{"tool":"pint","result":"passed"}` — clean after one auto-fix pass (import ordering/fully-qualified-strict-types across 2 new test files) |
| `vendor/bin/phpstan analyse` (apps/api) | Automated, local | PASS | `{"tool":"phpstan","result":"passed","errors":0}` at level 5 — two real findings from this phase's own new code, both fixed: `AuditLogController::export()` and `AuditLogResource::toArray()` each used `$log->created_at?->` (nullsafe) against a PHPDoc-typed non-nullable `Carbon` — corrected to a plain `->` (PHPStan's `nullsafe.neverNull`) |
| `php artisan migrate:fresh` (apps/api) | Automated, local | PASS | All 45 migrations ran cleanly against SQLite — one new migration (`create_audit_logs_table`), confirmed additive-only |
| `php artisan migrate:fresh --seed` (apps/api) | Automated, local | PASS | `RolePermissionSeeder` ran cleanly — no new permission was introduced this phase, so the catalog is unchanged |
| `php artisan test` (apps/api) — Phase 21 only (`--filter=Audit`) | Automated, local | PASS | `{"tool":"phpunit","result":"passed","tests":68,"passed":68,"assertions":230}` — `AuditLogApiTest` (18: authorization for Administrator/Manager/Staff/no-role/suspended, immutability, pagination/filters, CSV parity/formula-injection/no-internal-ids, the export's own audit event), `AuditLoggingCoverageTest` (32: every catalogued event firing exactly once end-to-end, every excluded action firing none, integration regression spot-checks against `leave_request_actions`/`service_report_actions`/`incident_report_actions`), `AuditLogRedactionTest` (7: no password/token/PII/narrative content in any audit row across a representative cross-section of flows), `AuditLogTransactionTest` (6, added during implementation review: a real, controlled DB-level audit-insert failure — dropping the `audit_logs` table, since `AuditLogger` is `final` and not mockable — proves a Staff create does not persist, a Staff update leaves prior state unchanged, a Department delete leaves the row intact, an Announcement publish leaves its status/timestamps untouched, and neither a Phase 20 report export nor the Audit Log's own export returns successfully), `AuditLoggerTest` (unit, 8: `diff()` allowlist behavior, append-only persistence, null actor, actor-survives-user-deletion, no `updated_at`, empty-array-to-null normalization) |
| `php artisan test` (apps/api) — full suite | Automated, local | PASS | `{"tool":"phpunit","result":"passed","tests":1068,"passed":1068,"assertions":2889}` — full Phase 1–20 regression suite unaffected (1000 tests, matching Phase 20's own recorded count, plus this phase's 68 = 1068), run together with this phase's new tests; confirms no existing Phase 4–20 controller, trait, route, domain-history table, or authorization rule was modified or regressed |
| Post-review correction: every audited relational mutation and its audit write now commit/roll back together (apps/api) | Automated, local | PASS (after fix) | Implementation review found that single-statement CRUD writes (Staff/Organization/Client/Project/Task/Project-Membership create/update/delete) issued their audit write as a plain follow-on statement, not inside the same `DB::transaction()` as the business mutation — meaning a failed audit insert could leave a committed, un-audited business change. Corrected: every such mutation now wraps the business write and its required audit write in one transaction, proven fail-closed by `AuditLogTransactionTest`'s forced DB-level failures (see row above). Authentication (no business mutation to roll back) and report exports (the audit write is their only durable effect, written before streaming begins) were confirmed to need no transaction wrapping and were left as-is. See `docs/handoffs/V1_PHASE_21_HANDOFF.md` §17 for the full account. |
| Three real test-assertion bugs found and fixed during this session (apps/api) | Automated, local | PASS (after fix) | (1)/(2) Two of this phase's own new tests used a fragile substring check (`assertStringNotContainsString("{$id},...")`) to prove no internal numeric id leaks into a CSV export; a randomly-generated IP address/employee-number coincidentally produced the same digit-then-comma sequence, causing a false failure. Fixed by parsing each CSV line with `str_getcsv()` and asserting the internal id is not present as an exact field value, in both `AuditLogApiTest` and — (3) — a **pre-existing Phase 20 test** (`StaffDirectoryReportTest::test_csv_export_succeeds_with_stable_headers_and_no_internal_ids`) that had the identical latent flakiness, surfaced for the first time during this session's full-suite run purely by chance (a randomly-generated `employee_number` happened to end in the same digit as `$staff->id`). Fixed identically. No application code changed for any of the three — all were test-assertion corrections; the underlying CSV export behavior (no internal id ever exposed) was never actually wrong. (4) Two of this phase's own new tests had incorrect status-code expectations discovered on first run: `test_staff_separation_is_audited_as_a_distinct_event` omitted the required `separation_date` field (`UpdateStaffRequest` requires it when transitioning to `separated`, pre-existing Phase 7 validation, not a Phase 21 change) — fixed by supplying it; `test_message_send_is_not_audited` asserted `assertOk()` (200) for `POST /conversations/direct`, which correctly returns `201` for a newly-created conversation per Phase 16's own documented `wasRecentlyCreated`-driven status convention — fixed to `assertCreated()`. |
| No existing Phase 4–20 controller, trait, route, or domain `*_actions` history table was modified beyond adding an explicit `AuditLogger` call (and, where needed, wrapping an existing single statement in `DB::transaction`) | Manual | PASS | Confirmed by reviewing the full staged diff before commit — every changed file under `app/Http/Controllers` shows only additive `AuditLogger`/`AuditActions` usage and, in a few cases, a `Request $request` parameter added to a `destroy()` signature that previously lacked one (matching this codebase's own existing convention elsewhere, e.g. `ServiceReportController::destroy()`); no authorization/visibility/validation logic was altered |
| Redaction discipline spot-checked end-to-end | Automated | PASS | `AuditLogRedactionTest` explicitly exercises: a failed login with a real, distinctive attempted password; a successful login's own issued bearer token and stored password hash; a Staff/Contact/Client update with distinctive sensitive contact details; an Announcement publish with distinctive title/body content; an attachment upload with a distinctive, sensitive-looking original filename — asserting none of these values appear anywhere across every column of every `audit_logs` row |
| Domain action-history integrity | Automated | PASS | `AuditLoggingCoverageTest` explicitly asserts that Leave Request approval, Service Report submission, and Incident Report resolution each still write to their own `leave_request_actions`/`service_report_actions`/`incident_report_actions` table exactly as before, and create **no** general Audit Log entry — the domain tables remain the sole authoritative record for their own workflow, unmodified by this phase |
| No new permission, no Manager/Staff Audit Log access of any kind | Automated | PASS | `AuditLogApiTest` explicitly asserts Manager and Staff both receive `403` from both `GET /api/v1/audit-logs` and `.../export`, and that a suspended Administrator (blocked by the pre-existing `account.active` middleware, unmodified by this phase) also receives `403` |
| No Flutter mobile UI, Admin Backoffice UI, Redis/queue/background-worker/Kafka/Elasticsearch/external-SIEM/audit-microservice/materialized-view infrastructure, retention/purge job, or new User account-management (suspend/reactivate/role-change) mutation surface introduced | Manual | PASS | Confirmed by reviewing the full staged diff before commit — the codebase investigation in DEC-044 found no existing mutation surface for User suspension/reactivation/role assignment, and none was added; those three reserved action names remain unwired |
| Docker validation | — | NOT RUN (this session) | No Docker configuration changed this phase; consistent with recent sessions, Docker-based re-verification was not attempted. |
| GitHub Actions CI | — | NOT RUN (this session) | No PR opened this session — the path-filtered `pull_request` trigger (DEC-015) never fired. All commands the workflow runs were executed directly and passed (rows above). |
| UAT | — | NOT RUN | No UAT scenario recorded yet for Phase 21 — see `docs/testing/UAT_LOG.md` (`UAT-21-01` through `UAT-21-05`). This phase introduced no Admin Backoffice UI or Flutter mobile screens, so there is nothing yet for the product owner to click through visually; ready for direct API review. |

## Phase 22 — Security Audit

| Check | Type | Status | Notes |
|---|---|---|---|
| Audit/planning session (repository inspection, no code changes) | Manual | PASS | `docs/phases/V1_PHASE_22_SECURITY_AUDIT.md` — evidence-based, code-verified audit across authentication, authorization/IDOR, input validation/injection, file uploads, secrets/config, logging, CI/CD, dependencies, and the Flutter mobile client. No Critical/High findings; one Medium (F-01), remainder Low/Informational. |
| `composer validate --strict` (apps/api) | Automated, local | PASS | `./composer.json is valid` — unaffected, no new dependencies added |
| `composer audit --locked` (apps/api) | Automated, local | PASS | `No security vulnerability advisories found.` — run twice this session (once during the audit-only pass, once during implementation), both clean across all 114 locked PHP packages |
| `vendor/bin/pint --test` (apps/api) | Automated, local | PASS | `{"tool":"pint","result":"passed"}` |
| `vendor/bin/phpstan analyse` (apps/api) | Automated, local | PASS | `{"tool":"phpstan","result":"passed","errors":0}` at level 5 |
| `php artisan test` (apps/api) — Phase 22 only (`--filter=Auth`) | Automated, local | PASS | `{"tool":"phpunit","result":"passed","tests":187,"passed":187,"assertions":517}` — every Auth-namespaced test (existing + 12 new: 3 in `LoginTest`, 3 in `AdminLoginTest`, 6 in new `TokenExpirationTest`) |
| `php artisan test` (apps/api) — full suite | Automated, local | PASS | `{"tool":"phpunit","result":"passed","tests":1080,"passed":1080,"assertions":2918}` — full Phase 1–21 regression (1068 tests) plus this phase's 12 new tests; confirms no existing controller, trait, route, or authorization rule was modified or regressed |
| F-01 regression: suspended/inactive account gets the identical message as wrong credentials (API + Admin) | Automated | PASS | `LoginTest::test_suspended_account_with_correct_password_gets_the_same_response_as_wrong_password`, `test_inactive_account_with_correct_password_gets_the_same_response_as_wrong_password`; `AdminLoginTest::test_suspended_admin_account_gets_the_same_error_message_as_wrong_password`, `test_inactive_admin_account_gets_the_same_error_message_as_wrong_password` |
| F-01 regression: the fix never actually lets a suspended/inactive account authenticate | Automated | PASS | `LoginTest::test_suspended_account_never_receives_a_token_regardless_of_message_unification` (asserts no token issued, `tokens()->count() === 0`); `AdminLoginTest::test_suspended_admin_account_never_gains_a_session_regardless_of_message_unification` (asserts guest state and `/home` still redirects to `/login`) |
| F-02 regression: token expiration is deterministic, not real-time | Automated | PASS | `TokenExpirationTest` (6 tests) — configured value assertion, fresh token succeeds, a token just under 30 days succeeds, a token just past 30 days is rejected (`401`), logout still revokes immediately regardless of expiration config, a suspended account with an otherwise-unexpired token still loses access (`403`, token revoked). All via Laravel's `travel()`/`travelTo()` test helpers (Carbon test-now, auto-reset) — no real-time waiting. |
| F-03: investigated, confirmed no gap, no code change | Manual | PASS | `RolePermissionSeeder` read directly — `work-logs.manage` is never synced to Manager or Staff; only the `Gate::before` Administrator override satisfies it. `WorkLogController::store/update/destroy` are fully wrapped in `can:work-logs.manage` route middleware. Audit's own optional item correctly dropped per authorization, not silently expanded. |
| Existing authorization/IDOR test coverage re-run with no regressions | Automated, local | PASS | Full suite (row above) includes `tests/Feature/Authorization/*` (14 files) and every module's own authorization-adjacent tests — all still pass unmodified. |
| Hardening items (CI permissions, `.gitignore`, `filesystems.php` `serve`, README note, `dependabot.yml`) | Manual | PASS | Confirmed by reviewing the full staged diff before commit — each change maps to exactly one audited finding (F-04/F-05/F-07/F-08/F-10/F-11); no unrelated file touched. |
| No deferred item implemented (User account-management surface, `config/cors.php`, refresh-token architecture, new infrastructure) | Manual | PASS | Confirmed by reviewing the full staged diff before commit — none of these appear anywhere in the diff. |
| Docker validation | — | NOT RUN (this session) | No Docker configuration changed this phase; consistent with recent sessions, Docker-based re-verification was not attempted. |
| GitHub Actions CI | — | NOT RUN (this session) | No PR opened this session — the path-filtered `pull_request` trigger never fired. All commands the workflow now runs (including the newly added `composer audit --locked` step) were executed directly and passed (rows above). |
| UAT | — | NOT RUN | No UAT scenario recorded yet for Phase 22 — see `docs/testing/UAT_LOG.md` (`UAT-22-01`/`UAT-22-02`). This phase introduced no Admin Backoffice UI or Flutter mobile screens, so there is nothing for the product owner to click through visually; ready for direct API review. |

## Phase 23 — Mobile UI/UX Audit & Foundation

| Check | Type | Status | Notes |
|---|---|---|---|
| Full inventory of `apps/mobile/lib/` (every file read, none skipped) | Manual | PASS | Confirmed against `docs/phases/V1_PHASE_23_MOBILE_UIUX_AUDIT.md` §2 — two screens (`LoginPage`, `HomePage`), one theme, one auth controller; no business-module UI, consistent with every Phase 6–21 handoff's own exclusion. |
| Audit against every `06_UI_UX_GUIDELINES.md` principle and the product owner's inspection checklist | Manual | PASS | See `docs/phases/V1_PHASE_23_MOBILE_UIUX_AUDIT.md` §4 — every checklist item addressed with a specific finding, not a generic pass/fail. |
| No finding manufactured merely because the UI is intentionally minimal | Manual | PASS | §11 of the phase document explicitly separates real defects (G-01, G-02) from missing foundation (design system/accessibility/navigation, resolved this phase) from screens that simply don't exist yet (never counted as a finding). |
| `flutter test` / `dart format` / `flutter analyze` (apps/mobile) | — | NOT RUN (not applicable) | No Flutter source file was changed this phase — a documentation/decision-only phase, per explicit scope. Nothing to regress. |
| No Flutter code, dependency, business-module screen, bottom-navigation shell, or routing package added | Manual | PASS | Confirmed by reviewing the full staged diff before commit — the diff touches only `docs/` files. |
| Docker validation | — | NOT RUN (this session) | No Docker/backend configuration changed this phase. |
| GitHub Actions CI | — | NOT RUN (this session) | No `apps/api`/`apps/mobile` source changed — the path-filtered CI workflows (DEC-015) would not fire on a docs-only diff. |
| UAT | — | NOT APPLICABLE | This phase changed no application behavior (documentation/decisions only) — there is nothing for the product owner to click through or observe differently in either the Flutter app or the Admin Backoffice. The audit findings and resolved decisions are reviewable directly in `docs/phases/V1_PHASE_23_MOBILE_UIUX_AUDIT.md` and `docs/DECISIONS.md` DEC-046. |

## Phase 24 — Staging Deployment (repository implementation)

| Check | Type | Status | Notes |
|---|---|---|---|
| Discovery/planning session (repository inspection, no code changes) | Manual | PASS | `docs/phases/V1_PHASE_24_STAGING_DEPLOYMENT_PLAN.md` — confirmed every existing Docker/CI/env asset is local-development-only; no staging asset existed before this phase. |
| `docker compose -p company-app -f docker-compose.staging.yml --env-file <temp, uncommitted> config` | Automated, local | PASS | Statically validated the staging Compose file — correct service/network/volume resolution; confirmed `mysql-data` resolves to `company-app_mysql-data`, matching the pre-existing ad-hoc deployment's own volume. Temporary placeholder env files deleted immediately after, never committed. |
| `grep -rln "@vite" apps/api/resources/views` | Automated, local | PASS | Exactly one match (`welcome.blade.php`, unused) — one input to the Nginx design, superseded as *sole* justification after review (see the two rows below). |
| `docker/nginx/default.conf` read line-by-line against the actual `try_files`/`fastcgi_pass`/`SCRIPT_FILENAME` behavior (product-owner review) | Manual | PASS | Confirmed `.php`-routed requests never need `public/` to exist on nginx's own filesystem (PHP-FPM resolves `SCRIPT_FILENAME` on the `app` container's filesystem instead), but `location = /favicon.ico`/`= /robots.txt` do read directly from nginx's own `root` — a real, if minor, gap in the original empty-`public/` design. Fixed: `docker/nginx/Dockerfile.staging` now bakes in `apps/api/public/`'s real (four-file) contents. |
| `git ls-files apps/api/storage apps/api/bootstrap/cache` | Automated, local | PASS | Confirmed writable-directory placeholders are tracked `.gitignore` files, not `.gitkeep` — informed the `.dockerignore` design (no wildcard exclusion risking an emptied-out directory). |
| Docker daemon availability (re-checked after product-owner review) | Manual | **PASS (corrected finding)** | `service docker start` fails (a `ulimit` call errors "Operation not permitted"), but `nohup dockerd &` starts a fully functional daemon, confirmed persisting across separate tool invocations. Base images pulled via `mirror.gcr.io` and re-tagged locally. |
| `docker build`/`docker compose build` — `docker/nginx/Dockerfile.staging` | Automated, local | **PASS** | Built successfully both standalone and via the real Compose service name; `docker run --rm ... ls -la /var/www/html/public` confirmed all four real static files present inside the built image. `nginx -t` run in isolation fails with `host not found in upstream "app"` — expected (no `app` service on the network in an isolated single-container run), not a defect. |
| `docker build` — `docker/php/Dockerfile.staging` (this sandbox) | — | FAILED — sandbox network policy, not a Dockerfile defect (historical finding, resolved below) | Fails at `apt-get update` (`403 Forbidden` reaching `deb.debian.org`), reproducing `docs/handoffs/V1_PHASE_04A_HANDOFF.md`'s own documented finding. A separate attempt to populate `vendor/` via the `composer:2` image directly (to test the Dockerfile's later steps independent of `apt-get`) failed harder than any prior phase's documented Composer recovery: HTTPS to `api.github.com`/`repo.packagist.org` fails TLS verification inside any container here, and Composer's git-source fallback then hangs indefinitely on an outbound **SSH** `git clone` (confirmed via the container's own process list) — a protocol this sandbox does not appear to permit outbound at all, unlike the HTTPS-based stalls Phases 9–22 eventually recovered from by waiting longer. The Dockerfile's own `COPY`/Composer-flag ordering was reviewed by hand and unchanged. |
| `docker build` — `docker/php/Dockerfile.staging` (real staging VPS, 2026-09-14) | Manual, real VPS | **PASS** | Built successfully with normal network access — confirming the sandbox failure above was environment-specific, not a Dockerfile defect. See the "Real VPS deployment" rows below. |
| `composer validate --strict` / `vendor/bin/pint --test` / `vendor/bin/phpstan analyse` / `php artisan test` (apps/api) | — | NOT RUN (not applicable) | No file under `apps/api/app|config|routes|database|tests`, and no `composer.json` change — nothing these gates cover was touched. |
| No secret committed | Manual | PASS | Confirmed by reviewing the full staged diff before commit — `apps/api/.env`/`.env.staging` (the real files) never exist in the diff, only their placeholder-only `.example` templates; `.dockerignore` additionally keeps any stray real `.env*` out of a future image build. |
| No source-code bind mount reintroduced for staging | Manual | PASS | `docker-compose.staging.yml` reviewed — the only `app` volumes are a single-file `.env` bind mount (configuration, not source) and the new `app-storage` named volume; application source is `COPY`'d into the image at build time. |
| MySQL never bound to `0.0.0.0` | Manual | PASS | `docker-compose.staging.yml`'s `mysql` service publishes no host port by default; the commented-out alternative is explicitly `127.0.0.1`-only. |
| PR #28 merged into `main` | Automated (GitHub) | PASS | Merge commit `eb227826aea776a303e05126743fa9c056ea2852`. `Backend quality gates (PHP 8.4)` check passed (the only check the path-filtered diff triggered); mergeable state was `clean` (no conflicts) before merge. |
| **Real VPS deployment (2026-09-14, reported by the product owner)** | | | |
| Formal staging stack running (`company-app-api:staging`/`company-app-nginx:staging`/`mysql:8.4`), replacing the ad-hoc bind-mounted deployment | Manual, real VPS | **PASS** | Deployed from `main` at `eb227826aea776a303e05126743fa9c056ea2852`. `app`→9000 internal, `nginx` 8012→80, `mysql` no host port exposed — matches the designed topology exactly. |
| `company-app_mysql-data` preserved and reused | Manual, real VPS | **PASS** | Pre-cutover `mysqldump` backup taken; no Docker volume deleted; `docker compose down -v` never used; existing `company_app`/`company_app` database and credentials survived the cutover intact. |
| `php artisan migrate:status` — all migrations applied | Manual, real VPS | **PASS** | All 45 migrations already `Ran`; no migration execution was required for this cutover. |
| `php artisan about` — runtime configuration correct | Manual, real VPS | **PASS** | Laravel 13.31.0, PHP 8.4.25, `environment: staging`, `debug: OFF`, MySQL database driver, database cache/session/queue drivers — matches every staging-specific `.env` value. |
| `GET /api/v1/health` / `GET /` via nginx | Manual, real VPS | **PASS** | Both returned `200` (health: `{"data":{"status":"ok",...}}`) from `127.0.0.1` on the VPS — the full nginx→PHP-FPM→Laravel path confirmed working for real, closing the one thing this project's own sandbox couldn't prove (see the two `docker/php/Dockerfile.staging` rows above). |
| Existing Administrator account login | Manual, real VPS | **PASS** | 3 Users, 1 Administrator in the database; the existing (preserved, not freshly Tinker-created) Administrator account logs in successfully on staging. |
| Public (UFW) exposure of port 8012/8442 | — | **NOT DONE, deferred** | UFW still does not expose either port publicly — every check above ran from `127.0.0.1` on the VPS itself. Staging is not reachable from outside the VPS. |
| TLS/HTTPS | — | **NOT DONE, deferred** | Port 8442 remains reserved, unmapped, unconfigured — no domain/certificate decided. |
| Mobile-client (`--dart-define=API_BASE_URL=...`) reachability | — | **NOT RUN, deferred** | Untested; would require public reachability (above) to be meaningful. |
| `public/storage` symlink | — | Not linked (observation only, not changed) | Per explicit instruction, recorded without action — has no functional effect, since attachments never use the `public` disk. |
| Docker validation (local dev, `docker-compose.yml`) | — | NOT APPLICABLE | Phase 4A's local-development Compose file/image were not modified — this phase added a separate, dedicated staging file instead. |
| GitHub Actions CI | — | PASS (on PR #28, see above) | Path-filtered workflows (DEC-015) triggered correctly for the diff's `apps/api/**` files; mobile workflow correctly did not trigger. |
| UAT | — | See `docs/testing/UAT_LOG.md` | `UAT-24-01` through `UAT-24-03` now recorded `PASS` (reported directly by the product owner from the real VPS deployment); `UAT-24-04` (deliberate-404/attachment/mobile checks) remains `NOT RUN`. |

## Phase 27 — Employee Home / Dashboard (Mobile) — Gate 1 (Backend Home API)

In progress. Gate 1 = backend `GET /api/v1/me/home` only; Gate 2 (Flutter) not started.

| Check | Type | Status | Notes |
|---|---|---|---|
| Focused Phase 27 suite (`php artisan test tests/Feature/Api/V1/Home`) | Automated | PASS | 50 tests, 229 assertions: `MyHomeTest` (auth 401 variants incl. expired/revoked token; inactive account → 403 + token revoked; exact response shape; no internal ids/unapproved fields; request parameters ignored; profile/no-profile; employee, Administrator, Manager-with-direct-reports, Project Lead isolation), `MyHomeTodayTest` (creator/participant ownership; project-only entries, leave, milestones, terminal/other-day/others' tasks excluded; inclusive overlap boundaries; company-timezone day in a non-UTC zone; ordering and tie-breaks incl. byte-wise and numeric-looking titles; limit 5 + `total_count`; bounded fetch equals a full sort), `MyHomeCountsTest` (open/overdue/due-today definitions; `OverdueTasks` parity; unread-message parity with `ConversationMember::unreadCount()`; notification parity with `/me/notifications/unread-count`; announcement eligibility, limit 3, preview fields, tie-break, subset of `/me/announcements`; constant query count for 1 vs 20 of everything). |
| Mutation check of the new tests | Manual | PASS | Nine deliberate controller mutations (announcement limit, unread-message null branch, DB and PHP all-day ordering, strict overlap, announcement tie-break, project-visibility leak, UTC instead of company date, numeric title compare) — each made at least one test fail. One (DB-side all-day ordering) initially survived, which led to adding `test_the_per_source_limit_keeps_an_all_day_entry_ahead_of_timed_entries_sharing_its_start`. The controller was restored byte-for-byte afterwards. |
| `php artisan test` (full suite) | Automated | PASS | 1,130/1,130 (1,080 pre-existing + 50 new), 3,147 assertions. No existing test changed. |
| `vendor/bin/pint --test` | Automated | PASS | |
| `vendor/bin/phpstan analyse` (level 5) | Automated | PASS | 0 errors. |
| `composer validate --strict` / `composer audit --locked` | Automated | PASS | No advisories. |
| MySQL byte-wise title ordering (`CAST(title AS BINARY)`) — Gate 1A | Automated (temporary, not committed) + manual | PASS | Run against MySQL 8.4.11 (the repo's `docker-compose.yml` `mysql:8.4` service, bound to `127.0.0.1` only, a throwaway `company_app_phase27_test` database, torn down afterwards; `tasks.title` collation `utf8mb4_unicode_ci`). The committed Home suite (50 tests) passes on MySQL. A temporary verification test drove the real `GET /api/v1/me/home` and confirmed: both bounded source queries execute `CAST(title AS BINARY)`; for titles `b, C, a, 10, 9, B, A` MySQL binary order `10, 9, A, B, C, a, b` equals the PHP `strcmp` comparator order, and the API returns `10, 9, A, B, C` with `total_count` 7 (the column's default collation order would be `10, 9, a, A, b, B, C`); a same-start entry/task merge across the `LIMIT 5` boundary returns the expected items; and a randomized 28-item fixture's top 5 equals a full PHP sort. Negative control: with the `CAST` branch temporarily replaced by plain `title ASC`, the API returned `10, 9, A, a, b` and the test failed, so the fixture does detect collation-dependent ordering. The controller was restored byte-for-byte and no backend source changed. |
| Flutter checks | — | NOT APPLICABLE (Gate 1) | No Flutter change in Gate 1. |
| UAT | — | NOT RUN | UAT-27-01…08 added to `docs/testing/UAT_LOG.md` as `NOT RUN`; not runnable until Gate 2. |

---

## Phase 27 — Gate 2 (Flutter authenticated API client & session lifecycle)

| Check | Type | Status | Notes |
|---|---|---|---|
| `flutter pub get` | Automated | PASS | Flutter 3.47.2 stable. `pubspec.yaml`/`pubspec.lock` unchanged. |
| `dart format --output=none --set-exit-if-changed .` | Automated | PASS | |
| `flutter analyze` | Automated | PASS | No issues. |
| `flutter test` (full) | Automated | PASS | 65/65 — the 22 pre-existing Phase 4/25 tests unchanged, plus 43 new. |
| Focused Gate 2 suites | Automated | PASS | `api_client_test.dart` (bearer token, configured and default base URL, success decode, non-JSON body, 4xx/5xx/network propagation with session kept, no request without a token, token never in error text); `session_lifecycle_test.dart` (401 → local expiry with notice, no `/auth/logout`, no re-check; two simultaneous 401s and a 401 during deletion → one deletion, one transition; 403 + `/auth/me` 200/401/403/500/503/network with exactly one re-check each; 401 racing manual logout in both orders; stale token-A 401 and stale token-A 403→401 after token-B login leave B signed in; repeated expiry no-op; login clears the notice; token-deletion failure still signs out); `session_expiry_test.dart` (the real `CompanyApp`: 401 and 403→401 return to Login with the notice and dispose the shell; 403→200 and 403→5xx keep the shell; network/server errors never show the notice; manual logout and fresh launch show no notice; re-login after expiry; stale 401 after re-login keeps the shell); `login_page_test.dart` (notice shown in a live region, cleared on submit, absent after manual logout). |
| Mutation check | Manual | PASS | Eight deliberate mutations (no stale-token check, no single-flight, 403 always expires, inconclusive re-check treated as invalid, 401 not expiring, no notice on expiry, storage failure rethrown, notice on manual logout) — each failed at least one test; sources restored byte-for-byte. |
| Device/emulator verification | Manual | NOT RUN | No device or emulator in this sandbox. |
| UAT | — | NOT RUN | UAT-27-01…08 unchanged (`NOT RUN`); Home UI (Gate 3) not built. |

---

## Phase 27 — Gate 3 (Flutter employee Home screen)

| Check | Type | Status | Notes |
|---|---|---|---|
| `flutter pub get` | Automated | PASS | `pubspec.yaml`/`pubspec.lock` unchanged. |
| `dart format --output=none --set-exit-if-changed .` | Automated | PASS | |
| `flutter analyze` | Automated | PASS | No issues. |
| `flutter test` (full) | Automated | PASS | 129/129 (65 before Gate 3 + 64 new). |
| Focused Home suites (`flutter test test/features/home`) | Automated | PASS | 64: `home_summary_test.dart` (populated, nullable, no-profile, schedule and task items, company_day and offsets, counts, announcements, 7 malformed-payload cases; company-time formatting incl. multi-day from/until and labels); `home_controller_test.dart` (loading, loaded, no-profile, network/server/shape/ordinary-403 errors, retry, refresh success/failure, one request in flight, 401 via Gate 2, late response after dispose); `home_page_test.dart` through the real `CompanyApp` (section order, greeting/profile, Today items/order/company time/"+N more"/empty, counts and zero wording, announcements and empty, no-profile, no interactive widgets or chevrons in content, taps navigate nowhere, shell tabs still work, spinner, error + Try again, generic server message, pull-to-refresh success/failure, no re-fetch on rebuild/theme/tab switch, `/me/home` 401 → Login + notice, ordinary 403 → Try again with session kept, logout, light/dark/200% text with long content at 360×740 and 1024×768, semantics labels and tap-target/label guidelines). |
| Mutation check | Manual | PASS | 10 valid mutations (tappable tile, chevron on a row, null messages shown as 0, client re-sorting Today, load removed from `initState`, fetch on every build, device timezone instead of `utc_offset`, expiry shown as a Home error, refresh failure dropping data, time-of-day greeting) each failed at least one test. The fetch-on-build mutation initially survived, which led to forcing real page rebuilds in the no-re-fetch test. Sources restored byte-for-byte. |
| Device/emulator verification | Manual | NOT RUN | No device or emulator in this sandbox. |
| UAT | — | NOT RUN | UAT-27-01…08 remain `NOT RUN`. |

---

## Phase 27 — Gate 4 (final integration review)

| Check | Type | Status | Notes |
|---|---|---|---|
| Backend `php artisan test` (full) | Automated | PASS | 1,130/1,130, 3,147 assertions. |
| Backend `php artisan test tests/Feature/Api/V1/Home` | Automated | PASS | 50/50, 229 assertions. |
| `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` (level 5) | Automated | PASS | 0 PHPStan errors. |
| `composer validate --strict`, `composer audit --locked` | Automated | PASS | No advisories. |
| Flutter `flutter test` (full) | Automated | PASS | 129/129. |
| Flutter focused: `test/features/home` / Gate 2 suites | Automated | PASS | 64/64 / 46/46. |
| `flutter pub get`, `dart format --set-exit-if-changed`, `flutter analyze` | Automated | PASS | `pubspec.yaml`/`pubspec.lock` unchanged from `b63599d`. |
| Cross-layer contract review | Manual | PASS | `MyHomeController` output compared field by field with `HomeSummary.fromJson` (names, types, nullability, `source_type` values, ISO 8601 instants, `utc_offset` format); every nullable column/relation matches a nullable Dart field and every non-null one is `NOT NULL` in its migration. |
| MySQL ordering (Gate 1A) | Automated (temporary) | PASS | Recorded in the Gate 1A row above; not re-run in Gate 4 (no backend change since). |
| CI (GitHub Actions) | Automated | See PR | Result recorded on the Phase 27 implementation PR. |
| Device/emulator verification | Manual | NOT RUN | No device in this sandbox. |
| UAT | — | NOT RUN | UAT-27-01…08 `NOT RUN`. |

---

## Phase 27 — Staging deployment & pre-UAT readiness (2026-09-23)

Run by the operator on the staging VPS and a Windows build machine (this AI session has no VPS or Android SDK access); results as reported.

| Check | Type | Status | Notes |
|---|---|---|---|
| Staging checkout fast-forwarded to `be43663` | Manual, real VPS | PASS | From `4cf55c0`; `git rev-parse HEAD` = `be43663f1e3527867c04adb73071eb3bace01ba5`. |
| Pre-deploy DB backup | Manual, real VPS | PASS | `company-app-20260923-092600.sql`, 88,356 bytes. |
| `migrate:status` | Manual, real VPS | PASS | All 45 migrations Ran; none pending (Phase 27 adds none). |
| Staging config (`app.env`/`app.debug`/`app.url`/`session.secure`) | Manual, real VPS | PASS | staging / false / `https://company-staging.storm-ark.com` / true. |
| Company timezone | Manual, real VPS | **Decision needed** | Effective `UTC`; `SCHEDULING_COMPANY_TIMEZONE` not set (default). Blocks UAT-27-02 until decided. |
| Public `/up`, `/login`, HTTP→HTTPS | Manual, real VPS | PASS | 200, 200, 301 → `https://…/up`. |
| `GET /api/v1/me/home` unauthenticated | Manual, real VPS | PASS | 401 (deployed and authentication-protected). An authenticated response was not tested. |
| Ports / containers | Manual, real VPS | PASS | Only `127.0.0.1:8012`; no `8442`; `app` recreated and up; `mysql` healthy; `nginx` not recreated — its inputs are unchanged in Phase 27. |
| Other hosted projects | Manual | NOT RUN | Not checked in this gate. |
| Flutter gates on the build machine | Automated | PASS | `dart format`, `flutter analyze` clean; `flutter test` 129/129. Hit-test warnings from `home_page_test.dart`'s `fling`/`drag` calls: see the note below. |
| Release APK | Automated | PASS | 51,582,167 bytes; SHA-256 `4C95BA4D5D58B50B4AA9388B9C5F52AA4AB1A669FE25D16DAADAB94A757EBC1C`; `com.companyapp.mobile` 1.0.0 (1); INTERNET permission present; debug-signed. The build machine's checkout SHA and Flutter/Dart versions were not captured in the output. |
| Test-quality note | Manual | Recorded | The 200%-text/phone overflow tests' final `drag` (and the refresh `fling`s) miss their hit-test target, so the scroll to the lower sections is skipped. A scratch re-run with `scrollUntilVisible` reached every section with no overflow in all five variants, so the product is unaffected. Tightening the test is a small follow-up. |
| UAT | — | NOT RUN | UAT-27-01…08 `NOT RUN`. |

---

*(Future phases append their own section above this line, oldest first.)*
