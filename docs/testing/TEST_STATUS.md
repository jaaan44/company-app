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
| Company timezone | Manual, real VPS | **Resolved** | Was the effective `UTC` default; the product owner decided `Asia/Manila`, now applied and verified (see the pre-UAT section). |
| Public `/up`, `/login`, HTTP→HTTPS | Manual, real VPS | PASS | 200, 200, 301 → `https://…/up`. |
| `GET /api/v1/me/home` unauthenticated | Manual, real VPS | PASS | 401 (deployed and authentication-protected). An authenticated response was not tested. |
| Ports / containers | Manual, real VPS | PASS | Only `127.0.0.1:8012`; no `8442`; `app` recreated and up; `mysql` healthy; `nginx` not recreated — its inputs are unchanged in Phase 27. |
| Other hosted projects | Manual | NOT RUN | Not checked in this gate. |
| Flutter gates on the build machine | Automated | PASS | `dart format`, `flutter analyze` clean; `flutter test` 129/129. Hit-test warnings from `home_page_test.dart`'s `fling`/`drag` calls: see the note below. |
| Release APK | Automated | PASS | 51,582,167 bytes; SHA-256 `4C95BA4D5D58B50B4AA9388B9C5F52AA4AB1A669FE25D16DAADAB94A757EBC1C`; `com.companyapp.mobile` 1.0.0 (1); INTERNET permission present; debug-signed. The build machine's checkout SHA and Flutter/Dart versions were not captured in the output. |
| Test-quality note | Manual | **Corrected** | Found at the staging gate: the 200%-text/phone overflow tests' final `drag` (and the refresh `fling`s) missed their hit-test target, so the lower sections were never scrolled to. Corrected in the pre-UAT gate (below).
| UAT | — | NOT RUN | UAT-27-01…08 `NOT RUN`. |

---

## Phase 27 — Pre-UAT remediation (test-only correction)

| Check | Type | Status | Notes |
|---|---|---|---|
| 200%-text/phone traversal | Automated | PASS | The overflow tests now `scrollUntilVisible` Home's scrollable to `home-today`, each tile and `home-announcements`, and assert each is hit-testable and exception-free, plus the last announcement row. All 5 variants pass (light/dark phone at 100% and 200%, light tablet at 200%). |
| Pull-to-refresh gestures | Automated | PASS | `fling` now targets Home's scrollable; both refresh tests pass with no hit-test warning. |
| Missed-gesture guard | Automated | In place | `WidgetController.hitTestWarningShouldBeFatal = true` for `home_page_test.dart`. |
| Proof that the fix catches real overflows | Manual (scratch, reverted) | PASS | An overflow injected into the Announcements section failed all 4 corrected phone variants; the original test missed it in both 200%-text phone variants. The production file was restored. |
| `flutter test` / `test/features/home` | Automated | PASS | 129/129 / 64/64; no hit-test warnings. |
| `dart format --set-exit-if-changed`, `flutter analyze`, `flutter pub get` | Automated | PASS | No production, `pubspec.yaml` or `pubspec.lock` change. |
| Staging timezone `Asia/Manila` applied and verified | Manual, real VPS (operator) | PASS | Appended in place (inode `1128804`, `deploy:deploy`, `664` unchanged); only `app` recreated; `config:cache`. `config:show scheduling.company_timezone` → `Asia/Manila`; company now `2026-09-23T21:29:06+08:00`. `app.env` staging, `app.debug` false, `app.url` correct, `session.secure` true; `/up` 200, `/login` 200, `/me/home` 401; 8012 loopback only; MySQL untouched (up 9 days, healthy). |
| UAT | — | NOT RUN | UAT-27-01…08 `NOT RUN`. |

---

## Phase 27 — Final UAT preparation (2026-09-23)

Source: `main` = `origin/main` = `093441a9526a96a285afe6fe7a0e21d66bc3f764` (UAT source baseline), clean tree. Running on staging: `be43663`. `git diff be43663 093441a` touches no production path (`apps/api/{app,routes,config,database}`, `apps/mobile/{lib,android}`, `composer.*`, `pubspec.*`, `docker/`, `docker-compose.staging.yml`).

| Check | Type | Status | Notes |
|---|---|---|---|
| Mobile quality gates at `093441a` (AI sandbox: Flutter 3.47.2 / Dart 3.13.2 / OpenJDK 21.0.10) | Automated | PASS | `flutter pub get` 0; `dart format --set-exit-if-changed` 0; `flutter analyze` no issues; `test/features/home` 64/64; Gate 2 (`test/core/network` + `test/features/auth`) 45/45; full `flutter test` 129/129; 0 hit-test warnings. **Not** the APK build environment. |
| Quality gates on the APK build machine | Automated | NOT RUN | Operator; `PHASE_27_UAT_PREPARATION.md` §2. |
| Final UAT APK from `093441a` | Build | NOT RUN | Operator (this sandbox has no Android SDK; `dl.google.com` is denied). Provenance fields to record: §2. The pre-remediation APK `4C95BA4D…EBC1C` is superseded. |
| UAT27 data script (`plan`/`seed`/`verify`/`announce`) | Manual, scratch SQLite DB with `SCHEDULING_COMPANY_TIMEZONE=Asia/Manila` + all 45 migrations + `RolePermissionSeeder` | PASS | The Home results match §3's expectations. `seed` is idempotent (re-run: 0 new accounts; still 1 unread message/notification). Times are stored in UTC (09:00 Manila → 01:00Z). Real HTTP login + `GET /me/home` as `uat27.staff` returned Asia/Manila, Today 3, 1 unread message, 1 unread notification. The scratch run happened when UTC was still 2026-09-23 and Manila was already 2026-09-24; Home correctly used 2026-09-24. Script SHA-256 `924844478eb5033365512c86d0a903b5a034014a05b296fda8c33659735ab688`. |
| UAT27 data on staging | Manual, real VPS | NOT RUN | Operator: `PHASE_27_UAT_PREPARATION.md` §4. |
| Fresh staging reachability | Manual | NOT RUN (this session) | The proxy denies the staging host. Latest operator evidence (2026-09-23): `/up` 200, `/login` 200, `/me/home` 401, `Asia/Manila`/+08:00. |
| UAT | — | NOT RUN | UAT-27-01…08 `NOT RUN`. |

---

## Phase 27 — UAT data on staging: incident and recovery (2026-09-24)

Operator-reported, 2026-09-24 (Asia/Manila). Script revision 1 (SHA-256 `924844478eb5033365512c86d0a903b5a034014a05b296fda8c33659735ab688`).

| Step | Type | Status | Notes |
|---|---|---|---|
| `plan` | Manual, real VPS (operator) | PASS | All six UAT27 accounts absent; non-UAT27 published company-wide announcements `0`; roles `3/3`. |
| `seed` | Manual, real VPS (operator) | PASS | Seeded for company date 2026-09-24 (Asia/Manila). |
| `verify` (server-side) | Manual, real VPS (operator) | PASS | Matched `PHASE_27_UAT_PREPARATION.md` §3 exactly. This is a data check, **not** UAT. |
| First-generation UAT27 passwords | Incident | **EXPOSED — invalid for UAT** | All six were pasted into an external AI chat. They must be rotated before any UAT sign-in. |
| `announce` | Incident | **Run too early** | Run before physical-device UAT-27-03. `[UAT27] Office closed Friday` is published on staging, so UAT-27-03's "no announcements" state is currently not observable. |
| Recovery (§4a: `exposure` → `rotate` → archive via the admin API → verify) | Design | Rehearsed; **NOT executed on staging** | Script revision 2 SHA-256 `398dab9cebe8b0b34fe33020d2cf050a1db227471d94e384025956572e57a88b`; `uat27_archive.sh` SHA-256 `e057bfa4dd5ddf6fd816dd1e9cbf8a21e71df49d3280f8eb81a4fee2d2808fe9`. |
| Recovery rehearsal (scratch SQLite, Asia/Manila, 45 migrations + `RolePermissionSeeder`, non-UAT27 control user/token/web session/draft announcement) | Manual, AI sandbox | PASS (0 failures) | See the list below the table. |
| UAT-27-04 helper (§5) | Manual, AI sandbox | PASS | Now pipes the password and passes the token through a header file (previously both were `curl` arguments). Scratch: message `201` and logout `200`; unread messages and notifications both 1 → 2; a wrong password STOPs with nothing sent. |
| UAT-27-01…08 | — | NOT RUN | Server-side `verify` is not UAT-27-03. Physical-device UAT-27-03 is `NOT RUN`. |

**Rehearsal results:**
- **Revision-1 behaviour:** `plan`/`seed`/`verify` behave as before; revision 1 reproduced the accidental publish.
- **`exposure` is read-only:**
  - the database file was byte-identical afterwards (`verify` and `plan` too);
  - it reported a simulated misuse (a staff sign-in and token with an old password, and an admin web session) and the published announcement's `public_id`.
- **`rotate` is all-or-nothing:**
  - With an injected failure on the 5th account it exited `1`, wrote nothing to stdout, and left every password hash, token and session unchanged.
  - A normal run created the credentials file with mode `0600`, containing exactly six `email password` lines. Stderr held only the header and six status lines.
  - It revoked the pre-rotation token (`401`) and the UAT27 web session.
  - All six old passwords are rejected (`422`); all six new ones work.
  - Non-UAT27 users, tokens, sessions, announcements and staff were unchanged, as were the seeded schedule, task, message and notification rows.
- **`uat27_archive.sh`:**
  - A wrong password: STOP, archive never sent.
  - A malformed id: STOP before login.
  - A non-UAT27 announcement id: STOP, nothing archived, token revoked.
  - The correct id: `200`, archived, token revoked, 1 `announcement.archived` audit entry.
  - A re-run: STOP (not published). A login throttle (`429`) also STOPs safely.
  - No UAT27 tokens remain afterwards.
- **After archiving:** no announcement appears on any Home. Staff Home is intact (Today 3; tasks 2/1/1; 1 unread message; 1 unread notification), and empty Home is all zero.
- **Revised `announce`:**
  - it created a fresh announcement and left the archived one archived;
  - a re-run reused the same one (idempotent);
  - the new one is visible on all 5 accounts with profiles;
  - it created no notifications, and non-UAT27 data was unchanged end to end.

---

## Phase 27 — Recovery execution, final UAT APK and physical-device UAT (2026-09-24)

Operator- and product-owner-reported, 2026-09-24 (Asia/Manila). The earlier sections above are kept as written; their `NOT RUN`/"not executed" entries describe the state at the time. Full record: `docs/testing/PHASE_27_UAT_PREPARATION.md` §8.

| Check | Type | Status | Notes |
|---|---|---|---|
| Recovery tools extracted from merged `origin/main` | Manual, real VPS (operator) | PASS | Script revision 2 `398dab9c…a57a88b` and `uat27_archive.sh` `e057bfa4…808fe9` matched exactly. |
| `exposure` before recovery (read-only) | Manual, real VPS (operator) | PASS | All six UAT27 accounts: `api_tokens=0 web_sessions=0 audit:none`. One UAT27 announcement, `01M38G2Z97D8H6KP2WDJ48X1WH`, `published`, `archived_audit=0`. No evidence that the exposed first-generation passwords were used. |
| `rotate` (six UAT27 accounts) | Manual, real VPS (operator) | PASS | `exit=0`; credentials file `600`, owner `deploy`, 6 lines; stored privately, then removed with `shred`. No password is recorded in the repository. |
| Archive of the accidental announcement | Manual, real VPS (operator) | PASS | `archived: 01M38G2Z97D8H6KP2WDJ48X1WH ([UAT27] Office closed Friday)`; `logout: token revoked`. It stays archived as historical evidence. |
| Post-recovery `exposure`/`verify` | Manual, real VPS (operator) | PASS | Old announcement `archived`, `archived_audit=1`; all UAT27 tokens and web sessions zero; the only admin auth audit was the archive's sign-in/sign-out. Completed **before** physical UAT. |
| Quality gates on the APK build machine (at `093441a`) | Automated (operator, Windows) | PASS | `flutter pub get`; format 32 files, 0 changed; `flutter analyze` no issues; Home 64/64; core network + auth 45/45; full 129/129. Flutter 3.47.2 (`d3b14c8769` / engine `a804b26164`), Dart 3.13.2, Temurin JDK 17.0.15+6, Android SDK/build-tools 36.0.0. |
| Final UAT APK from `093441a` | Build (operator) | PASS | Source `093441a9526a96a285afe6fe7a0e21d66bc3f764` (operator-confirmed; no build-time `git` transcript is preserved in the repository). 51,582,167 bytes; SHA-256 `4C95BA4D5D58B50B4AA9388B9C5F52AA4AB1A669FE25D16DAADAB94A757EBC1C`; `com.companyapp.mobile` 1.0.0 (1); compile/min/target SDK 36/24/36; INTERNET present; v2 debug signature (deferred to Phase 38). Byte-identical to the earlier APK, which **remains superseded** because its provenance was not recorded. The identical hash does not retroactively establish that earlier build's provenance. |
| Replacement announcement (§4a step 5) | Manual, real VPS (operator) | PASS | Published only **after** UAT-27-03 passed: `01M38R3M012BFS7JMWFVJ36QDM`, `2026-09-24T03:42:20+00:00`. |
| UAT-27-01…08 (physical device, staging `be43663`) | UAT (product owner) | **PASS** | All eight `PASS`, with per-scenario evidence in `docs/testing/UAT_LOG.md`. |
| Final `verify`/`exposure` (04:21:51Z / 04:21:56Z) | Manual, real VPS (operator) | PASS | Matches the expected post-UAT state (`PHASE_27_UAT_PREPARATION.md` §8.4). The one Staff API token is expected: the device was left signed in. This state is intentional and was not cleaned up. |

**Phase 27 formally closed 2026-09-24.**

---

## Phase 28 — Gate 1 (backend `GET /api/v1/me/profile` and `/staff` tie-breaker)

Branch `claude/amazing-brahmagupta-dbsrjc`, from `main` at `0bb3a64` (PR #55). Run in this AI sandbox (PHP 8.4.19, SQLite in-memory per `phpunit.xml`).

| Check | Type | Status | Notes |
|---|---|---|---|
| `tests/Feature/Api/V1/Profile/MyProfileTest.php` (15 tests) | Automated | PASS | Covers authentication: unauthenticated or invalid token → 401; suspended account → 403. Exact `user` fields. Own record for Staff/Manager/Administrator. `staff` identical to `GET /staff/{public_id}` for each role. The `staff.manage`-only `user` block appears for the Administrator only. No internal ids. Null placement/manager. A separated status still returns the own record. An unlinked Administrator gets `staff: null` with 200. A no-role user reads their own profile but not `/staff`. Query parameters cannot change the subject. Query count ≤ 12. |
| `StaffTest` additions (2 tests) | Automated | PASS | Three same-named staff across `per_page=1` pages come back as 3 distinct records in id order, and the SQL ends `order by "last_name" asc, "first_name" asc, "id" asc`. Distinct names keep their visible order. **Proven to catch the bug:** with the tie-breaker temporarily removed, the first test fails on the SQL assertion; the file was then restored. |
| Full `php artisan test` | Automated | PASS | 1,147/1,147 (3,215 assertions): the 1,130 from Phase 27 plus these 17. No existing test changed. |
| `composer validate --strict` | Automated | PASS | — |
| `vendor/bin/pint --test` | Automated | PASS | — |
| `vendor/bin/phpstan analyse` (level 5) | Automated | PASS | 0 errors. |
| `composer audit --locked` | Automated | **FAIL (pre-existing, not caused by this gate)** | `league/commonmark` 2.10.1 (transitive, via `laravel/framework` ^2.8.1). GHSA-3q6v-r5mr-hxv8 (high: quadratic-time DoS in the GFM table extension) and GHSA-97jj-33gv-5xf9 (medium: `DisallowedRawHtml` bypass), both published 2026-09-30, affecting ≤ 2.10.1. Patched 2.10.2/2.10.3 exist. `composer.lock` is identical to `main`, so `main` fails this check too. Application code does not call Markdown rendering. Not fixed in Gate 1: a lockfile change is outside Gate 1's authorized scope. Awaiting a product-owner decision. |
| Mobile | — | Not affected | No `apps/mobile` change in Gate 1. |
| UAT | — | NOT RUN | UAT-28-01…07 `NOT RUN` (not yet runnable). |

---

## Phase 28 — Gate 2 (Flutter People data and state)

Same branch, after merging `main` at `116526f` (PR #56, the `league/commonmark` 2.10.3 fix). Run in this AI sandbox: Flutter 3.47.2 (framework `d3b14c8769`) / Dart 3.13.2, downloaded from `storage.googleapis.com` as in Phases 25/27. No device or emulator; nothing here is manually verified.

| Check | Type | Status | Notes |
|---|---|---|---|
| `test/features/people/people_models_test.dart` (15) | Automated | PASS | Strict parsing of `StaffMember`, `MyProfile` and `StaffDirectoryPage`. Null placement and manager stay null. `roleLine` skips a missing part. A preferred name vs full name is detected. Role labels map correctly. A missing or wrongly typed field is a `FormatException`. |
| `test/features/people/people_api_client_test.dart` (13) | Automated | PASS | Exact paths, and the bearer token is sent. The directory always sends `status=active&per_page=25&page=N` (R-1). An empty or whitespace query is not sent. Search text is trimmed and fully encoded, including `&`, `?`, `=`, `#` and non-ASCII. A public id can never change the path. A contract mismatch → `ApiRequestException`. 401 ends the session; 403 with a valid session is forbidden, not a sign-out. |
| `test/features/people/resource_controllers_test.dart` (14) | Automated | PASS | `MyProfileController` / `StaffDetailController`: loading → loaded. No-profile is loaded, not an error. Error messages for network, 5xx, bad shape, 404 and 403. Try again recovers. A failed refresh keeps data and returns false. Concurrent calls make one request. 401 → signed out with no error state. No notifications after dispose. |
| `test/features/people/staff_directory_controller_test.dart` (19) | Automated | PASS | First page, load more to the last page, one request for overlapping load-more calls, no duplicate person, and a load-more failure that is kept and retried. Search is debounced (last text only), the same query sends nothing, and a search is paged on load more. A slow earlier-search response is dropped, and a load-more overtaken by a refresh is dropped. Refresh keeps the list while loading and replaces it on success; a failed refresh keeps the list. An empty result is loaded. Error messages cover network, 403 and 5xx. Try again recovers. 401 → signed out. A pending search is cancelled by dispose. **Proven to catch the bug:** with the generation guard removed, exactly the two stale-response tests fail; the file was then restored. |
| `flutter pub get` | Automated | PASS | `pubspec.yaml` and `pubspec.lock` unchanged: no new dependency (R-3). |
| `dart format --output=none --set-exit-if-changed .` | Automated | PASS | 45 files, 0 changed. |
| `flutter analyze` | Automated | PASS | No issues. |
| Full `flutter test` | Automated | PASS | 190/190: the 129 from Phase 27 plus these 61. Home 64/64; core network + auth 45/45 (unchanged). |
| Backend after merging `main` | — | Unchanged | Gate 2 changes no `apps/api` file. The merge brought only `composer.lock` (PR #56, already CI-green on `main`). |
| UAT | — | NOT RUN | UAT-28-01…07 `NOT RUN` (no screens yet; Gate 3). |

---

## Phase 28 — Gate 3 (Flutter People screens and routes)

Same branch. AI sandbox, Flutter 3.47.2 / Dart 3.13.2. No device or emulator: everything below is **tested automatically**, nothing is manually verified.

| Check | Type | Status | Notes |
|---|---|---|---|
| `test/features/people/people_pages_test.dart` (23) | Automated (widget, through the real `CompanyApp`, router, shell, `AuthController` and `ApiClient`) | PASS | **More (R-8):** exactly two rows; the account name as subtitle; no "coming soon"; no request. **My profile:** every field, including employee number, hire date `1 Mar 2024`, login email and role; full name when the preferred name differs; read-only (no text field, no edit icon) with the administrator note. No-profile shows the message and Account only. Missing values read "Not set" (4 of them). Error → Try again recovers. **Directory:** server order; "position · department" with missing parts skipped; no subtitle when both are missing; `status=active` sent; no operational status shown (R-4). Search is debounced (only the paused text is sent); clear resets. Both empty states. Scrolling loads page 2. A failed page shows a retry row that works. Pull-to-refresh refetches. 403 → access message with the session kept; 401 → Login with the session-ended notice. **Detail:** approved fields only, with no employee number, dates or status (R-5). Copy sends the email/phone to the platform clipboard and shows "Copied" (R-3). The manager opens their entry, a manager-less entry has a plain row, and back returns (R-7). Switching tabs keeps the More stack. Android tap-target and labelled-tap-target guidelines met. **Resilience:** light/dark phone at 100% and 200% text, and a light 200% tablet, with long names: More, profile (walked to the last row), directory (walked to the last row) and detail (walked to the copy-phone button) throw no exception and stay hit-testable. Hit-test warnings are fatal in this file. **Proven to catch the bug:** with the footer's load-more trigger disabled, exactly the two paging tests fail; the file was then restored. |
| `test/app/router_test.dart` | Automated | PASS (updated) | The More tab now expects `MorePage` with My profile and Staff directory, instead of the removed `MorePlaceholderPage`. No other existing test changed. |
| `flutter pub get` | Automated | PASS | `pubspec.yaml` and `pubspec.lock` unchanged: no `url_launcher` (R-3). |
| `dart format --output=none --set-exit-if-changed .` | Automated | PASS | 51 files, 0 changed. |
| `flutter analyze` | Automated | PASS | No issues. |
| Full `flutter test` | Automated | PASS | 213/213 (190 + 23). Home 64/64; core network + auth 45/45; `test/app` (router + session expiry) 19/19. |
| UAT | — | NOT RUN | UAT-28-01…07 `NOT RUN`: they need a staging deployment and a device (after Gate 4). |

---

## Phase 28 — Gate 4 (final integration review)

Final tree on `claude/amazing-brahmagupta-dbsrjc` (contains `main` at `116526f`). AI sandbox: PHP 8.4.19; Flutter 3.47.2 / Dart 3.13.2.

| Check | Type | Status | Notes |
|---|---|---|---|
| Backend `CLAUDE.md` §5 gates | Automated | PASS | `composer validate --strict` valid. `composer audit --locked` has **no advisories** (PR #56 merged in). Pint pass. PHPStan level 5: 0 errors. `php artisan test` 1,147/1,147 (3,215 assertions). `vendor/` re-synced to the merged lock (`league/commonmark` 2.10.3) before the run. |
| Mobile `CLAUDE.md` §5 gates | Automated | PASS | `flutter pub get` ok; `pubspec` unchanged. `dart format`: 51 files, 0 changed. `flutter analyze`: no issues. `flutter test` 213/213 with 0 hit-test warnings. |
| Contract parity (scratch, not committed) | Automated (temporary) | PASS | Real Laravel `/me/profile`, `/staff/{id}` and `/staff` responses parsed by the production Flutter models. Cases: Staff, Manager and Administrator; a no-profile account; a record with every optional field null; non-ASCII names; paginator `meta`. Both scratch tests were deleted afterwards and the tree was clean. |
| Full Phase 28 diff review | Manual (code review) | Done | Backend and mobile match spec §6–§8 and R-1…R-8. Deviations and limitations are in the handoff §12/§13. No migration, permission, dependency, environment, infrastructure or CI change. |
| Device / emulator | Manual | NOT RUN | None available in this sandbox. |
| UAT | — | NOT RUN | UAT-28-01…07 `NOT RUN`: they need the merge, a staging deployment, UAT data and an APK. |

---

## Phase 28 — UAT preparation (runbook and data script rehearsal, 2026-10-09)

`docs/testing/PHASE_28_UAT_PREPARATION.md`; script `uat28_data.php` revision 1, SHA-256 `4e846aec912c5ebc48b29d334311468c590542e82b4fbbcc3ba1474c088cabbd`. Rehearsed in this AI sandbox on disposable scratch SQLite databases (all 45 migrations + `RolePermissionSeeder`) against `main` at `b6e85c5`. **Nothing was run on staging.** No password was displayed during the rehearsal (only lengths), and the scratch credential files were shredded afterwards.

| Check | Type | Status | Notes |
|---|---|---|---|
| `plan` (read-only) | Manual, scratch DB | PASS | Route `api.v1.me.profile` detected; 3 accounts absent; 0 of 33 UAT28 staff records; roles 3/3. |
| `seed` | Manual, scratch DB | PASS | Exit 0. STDOUT went to a `600` file with exactly 3 `email password` lines (20-character passwords); all status went to STDERR. 33 staff records, 3 accounts, 3 organization records. |
| `seed` re-run (idempotent) | Manual, scratch DB | PASS | 0 bytes on STDOUT and no password changed, both before and after `rotate`. |
| `verify` (real controllers) | Manual, scratch DB | PASS | Staff profile shows `Ros (full: Rosalind UAT28-Abad)` with position, department, team, manager, contact details, `UAT28-001`, hired 2024-03-01. Manager profile correct. No-profile admin → `staff: none`. `/staff?q=UAT28&status=active&per_page=25`: page 1/2 has 25 rows, page 2/2 has 6. 31 distinct; inactive and separated absent. Same-name pair distinct at `per_page=1` (R-6). SQLite sorted `UAT28-Ñuñez` after `UAT28-Twin`; MySQL collation may differ (noted in the runbook). |
| `exposure` (read-only) | Manual, scratch DB | PASS | Reported a pre-created token on `uat28.staff`. |
| `rotate` | Manual, scratch DB | PASS | Exit 0; `600` file, 3 lines; that token revoked. Old passwords rejected, new ones accepted (`Hash::check`). |
| Refusals | Manual, scratch DB | PASS | `rotate` with one account missing: exit 1, nothing on STDOUT, nothing changed. `seed` when a UAT28 staff record is linked to a non-UAT28 account: exit 1, nothing on STDOUT, staff table byte-identical (PHP snapshot; the first check used a missing `sqlite3` CLI and was redone). Unknown stage: exit 1. |
| All-or-nothing `rotate` | Manual, scratch DB (injected failure) | PASS | A failure on the 3rd account: exit 1, nothing on STDOUT, all three password hashes unchanged (rolled back). |
| Non-UAT28 data untouched | Manual, scratch DB | PASS | A control user, staff record and token were identical before and after `seed`, re-`seed` and `rotate`. |
| Runbook extraction path | Manual, scratch DB | PASS | The `awk` command in runbook §5 step 0 reproduces exactly `4e846aec…cabbd`. That extracted copy ran `plan` → `seed` → `verify` on a fresh scratch DB with the same results. |
| Staging deployment, APK, data, UAT | Operator / product owner | NOT RUN | Runbook §2–§6. UAT-28-01…07 `NOT RUN`. |

**Session note (honest record):** while this runbook was being written, an unquoted shell heredoc made bash run backtick-quoted words from the document text as commands in the AI sandbox. Only harmless "command not found" errors and a `shred` with no operand ran before a bare `sh` blocked and the task was stopped. Verified afterwards:
- git HEAD, `origin/main` and the working tree were unchanged;
- `FETCH_HEAD` was older than the incident;
- `/home/deploy` does not exist;
- no Docker daemon was running.

No effect on the repository, staging or any data. The runbook was then written with the file tool and a quoted heredoc.

---

## Phase 28 — staging deployment, UAT data and physical-device UAT (2026-10-09)

Operator- and product-owner-reported; this AI session has no VPS or device access. The values below are transcribed from the terminal output the operator shared. No password appears anywhere.

| Check | Type | Status | Notes |
|---|---|---|---|
| Checkout fast-forwarded to `b6e85c5` | Manual, real VPS | PASS | `git rev-parse HEAD` = `b6e85c5a5b8bceac4328805af562fd7a2bb4f5a9`. |
| Pre-deploy DB backup | Manual, real VPS | PASS | `company-app-20261009-064725.sql`, 101,566 bytes. |
| Images rebuilt; `app` and `nginx` recreated | Manual, real VPS | PASS (after the incident below) | Per runbook §2. |
| **MySQL network incident during deploy** | Incident, real VPS | **Resolved** | See the notes below this table. |
| `migrate:status` | Manual, real VPS | PASS | All 45 migrations Ran, none pending. Run as `docker compose exec app …` rather than `$C exec …`; it reached the staging container, but `$C` is the documented form. |
| Company timezone | Manual, real VPS | PASS | `scheduling.company_timezone` → `Asia/Manila`. |
| Config, route and view caches | Manual, real VPS | Reported done | The operator reported "all ok" after the fix. The individual cache outputs were not shared. `/me/profile` answering `401` (below) shows the route cache includes the new route. |
| Public smoke tests | Manual (operator's Windows `curl.exe`) | PASS | `up 200`, `login 200`, `home 401`, **`profile 401`**, `staff 401`. |
| Listener check (`127.0.0.1:8012` only) | Manual | Not recorded | The `ss` output was not shared. |
| UAT28 script integrity | Manual, real VPS | PASS | Extracted from `origin/main`; SHA-256 `4e846aec912c5ebc48b29d334311468c590542e82b4fbbcc3ba1474c088cabbd`. |
| `plan` (07:45:08Z) | Manual, real VPS | PASS | Phase 28 deployed (route present); 3 accounts absent; 0 of 33 UAT28 records; roles 3/3; 5 active staff in the directory before seeding. |
| `seed` (07:45:43Z) | Manual, real VPS | PASS | 3 accounts created; 33 UAT28 staff records; exit 0. Credentials file `600 deploy`, 3 lines; moved to a password manager and removed with `shred -u` (an `ls` afterwards confirmed it was gone). |
| `verify` (07:47:30Z, real controllers) | Manual, real VPS | PASS | `/me/profile`: staff = `Ros (full: Rosalind UAT28-Abad)`, with the expected position, department, team, manager, contact details, `UAT28-001` and hired 2024-03-01; manager correct; `admin_noprofile` → `staff: none`. `/staff?q=UAT28`: page 1/2 has 25 rows, page 2/2 has 6. 31 distinct; inactive and separated absent. Tie-breaker: distinct. On staging's MySQL collation `José UAT28-Ñuñez` sorts before the two `Jamie UAT28-Twin` (SQLite in the rehearsal sorted it after); the runbook anticipated this. |
| `exposure` before UAT (07:47:31Z) | Manual, real VPS | PASS | All three UAT28 accounts: `api_tokens=0`, `web_sessions=0`, audit none. |
| Final UAT APK | Build (operator) | **Provenance not supplied** | The APK used for UAT was to be built from `b6e85c5` per runbook §3. Its details were not supplied: the literal `git rev-parse HEAD`/`git status --porcelain`, `flutter --version`, test count, size and SHA-256. They can be added in a follow-up docs update if supplied. |
| UAT-28-01…07 (physical device, staging `b6e85c5`) | UAT (product owner) | **PASS** | Reported by the product owner on 2026-10-09: "everything passed". `UAT_LOG.md` records all seven as `PASS`. No per-scenario observations were reported. |
| `exposure` after UAT | Manual, real VPS | Not supplied | Recommended in runbook §6; the output was not shared. |

**MySQL network incident during the deploy (resolved):**
- **What happened:** after `app` and `nginx` were recreated on the compose-defined `company-app-staging` network, `migrate:status` failed with `getaddrinfo for mysql failed`.
- **Diagnosis:** `company-app-mysql` had been running for about 4 hours, attached only to a network named `company-app_company-app`. That network is not defined by `docker-compose.staging.yml`, which has used `company-app-staging` since Phase 24.
- **Fix:** `$C up -d mysql` recreated it at `2026-10-09T07:01:38Z` from `docker-compose.staging.yml` (project `company-app`), followed by `$C restart app`.
- **Verified afterwards:**
  - mount `company-app_mysql-data -> /var/lib/mysql` (the real staging volume);
  - network `company-app-staging` with the alias `mysql`;
  - the same database as before (9 users, including the 6 Phase 27 `uat27.*` accounts; all 45 migrations);
  - the server had not been rebooted (up 33 days).
- **Still open:**
  - `company-app_company-app` is now empty and was left in place;
  - what attached MySQL to it about 4 hours before the deploy is **not known**; it may be another tool or project on this shared VPS. The operator was asked to check shell history.
- No data loss; the deploy backup was taken before the incident.

**Phase 28 formally closed 2026-10-09.**

---

## Phase 29A — Gate 1 (backend `GET /api/v1/me/tasks` and `/tasks` tie-breaker)

Branch `claude/amazing-brahmagupta-dbsrjc`, from `main` at `d8d550b` (PR #60). Run in this AI sandbox (PHP 8.4.19, SQLite in-memory per `phpunit.xml`).

| Check | Type | Status | Notes |
|---|---|---|---|
| `tests/Feature/Api/V1/Tasks/MyTaskTest.php` (21 tests) | Automated | PASS | Covers: unauthenticated → 401; suspended account → 403. Exact response shape and `meta`; no internal ids anywhere. No Staff record → 200 with `tasks: null`, `meta: null`; no tasks → empty list. Staff, Manager (with a direct report's task) and Administrator each see only their own assigned tasks, not tasks they created for others or unassigned ones; foreign `assignee`/`staff` parameters are ignored. Open is the default and excludes completed/cancelled; closed is exactly those; an unknown `state` → 422. Open order by due date with undated last; closed order by `completed_at` with cancelled after. Seven same-due-date tasks over three pages come back once each in id order. `per_page` default 25, 50 allowed, 51 and 0 → 422. Flags for overdue/today/future/undated, always false when closed. At 23:59 and 00:00 Manila (15:59/16:00 UTC) the same task flips from due today to overdue and `company_day.date` advances. Totals and flags match `/me/home`'s `open_count`/`overdue_count`/`due_today_count`. Query count identical for 1 and 11 tasks with distinct projects and linked creators. Both ORDER BY clauses end in the `id` tie-breaker. |
| `TaskTest` addition (1 test) | Automated | PASS | Five tasks with an identical `created_at` across `per_page=2` pages come back once each in id order, and the SQL contains `order by "created_at" desc, "id" asc`. |
| Mutation checks | Manual (AI) | PASS | Each change was made temporarily, the tests run, and the file restored: removing the `/tasks` tie-breaker fails the `TaskTest` SQL assertion; removing the `/me/tasks` open or closed `id` tie-breaker fails the ORDER BY test; inverting nulls-last fails 3 tests; dropping the assignee filter fails 5. The first `/me/tasks` tie-breaker mutation initially survived because SQLite returns equal keys in rowid order, which is why the ORDER BY assertion was added. |
| Full `php artisan test` | Automated | PASS | 1,169/1,169 (3,310 assertions): the 1,147 from Phase 28 plus these 22. No existing test changed. |
| `composer validate --strict` | Automated | PASS | — |
| `composer audit --locked` | Automated | PASS | No security vulnerability advisories found. |
| `vendor/bin/pint --test` | Automated | PASS | — |
| `vendor/bin/phpstan analyse` (level 5) | Automated | PASS | 0 errors. |
| MySQL ordering | — | Not run | The ORDER BY uses `due_date IS NULL` / `completed_at IS NULL`, valid in MySQL and SQLite. Not exercised against MySQL in this gate (Phase 27 Gate 1A precedent); to be checked at staging. |
| Mobile | — | Not affected | No `apps/mobile` change in Gate 1. |
| UAT | — | NOT RUN | UAT-29A-01…07 `NOT RUN` (not yet runnable). |

---

## Phase 29A — Gate 2 (mobile write foundation and Tasks data and state)

Branch `claude/amazing-brahmagupta-dbsrjc`, on Gate 1 (`3f5045e`). Run in this AI sandbox with Flutter 3.47.2 / Dart 3.13.2 (`/opt/flutter-sdk`).

| Check | Type | Status | Notes |
|---|---|---|---|
| `test/core/network/api_client_write_test.dart` (20 tests) | Automated | PASS | PATCH/POST send JSON bodies with the token and `Content-Type`; POST without a body sends `{}`; DELETE and GET send no body. `204` → `null` for writes; DELETE accepts any 2xx body; a GET `204` and a non-object write body are request failures. `422` → `ApiValidationException` with message and field errors (malformed entries skipped, default message, non-JSON body, also on GET; still an `ApiRequestException`). On writes: 401 ends the session with the write sent once and no `/auth/logout`; 403 → one `/auth/me` re-check, kept session and server message, no resend; 403 + `/auth/me` 401 ends it; network failure and 5xx keep the session, no retry; a write and a read both 401 end the session once; no token → nothing sent. |
| `test/features/tasks/task_models_test.dart` (21 tests) | Automated | PASS | Every `TaskResource` field; nullable fields stay null; missing title, unknown status/priority, a timestamp as `due_date`, a wrong-typed flag and a malformed project are `FormatException`s. Status wire values, closed set, and no Cancelled in `selectable`. Due state: server flags win; otherwise the company date (before/equal/after); unknown without either; none without a due date; closed never overdue. `withServerUpdate` keeps flags only while still open with the same due date, clears them when closed, drops them on reopen or a changed due date. `/me/tasks` page parsing, no-profile vs empty, malformed bodies. |
| `test/features/tasks/tasks_api_client_test.dart` (11 tests) | Automated | PASS | Exact `/me/tasks` path and `state`/`per_page`/`page`; default page 1; no-profile; bad shape → `ApiRequestException`. `/tasks/{id}` encoding and 404. `PATCH` sends only `{"status"}` once with wire values (`in_progress`); 204 or malformed → request failure; 403 keeps the session with the server message; 401 ends it. |
| `test/features/tasks/my_tasks_controller_test.dart` (19 tests) | Automated | PASS | Loading → page 1; Done requests `closed`; load more to the last page; no duplicates; failed load more keeps the list and retries; a load more overtaken by a refresh is dropped; concurrent loads share one request; no-profile state; first-load error and recovery; failed refresh keeps the list; session expiry leaves state alone. Task changes: replaced in place keeping flags; completed leaves Open; removed; unknown task only marks stale; Done drops a reopened task; `refreshIfStale` refreshes once; a change during a refresh keeps it stale; dispose removes the listener. |
| `test/features/tasks/task_detail_controller_test.dart` (22 tests) | Automated | PASS | Loading with and without an initial item (quiet refresh; list flags survive a flagless GET); network/403/404/500 messages; failed refresh keeps the task; due state from the company date. Saves: status changes only after the server confirms (not optimistic) and is recorded; complete then reopen; Cancelled, the current status, a second concurrent save, a cancelled task, and an unloaded task send nothing; 403 → message, `PATCH` then `GET`, locked, recorded as removed; 403 with a failed reload keeps the task; 422 → the `status` error or the summary; network/500/404 keep the old status and allow a retry; 401 ends the session and changes nothing; a refresh started before a save can't undo it. |
| Mutation checks | Manual (AI) | PASS | Each made temporarily, the Tasks and network tests run, and the file restored; every one failed at least one test: dropping the detail's save-epoch guard; making the save optimistic; not locking after a 403; not recording the list's loaded revision; not removing a task that moved segment; not removing the change listener on dispose; treating 422 as generic; treating 204 as a body; keeping flags when a closed task reopens. |
| Full `flutter test` | Automated | PASS | 306/306: the 213 from Phase 28 plus these 93. No existing test changed. |
| `dart format --output=none --set-exit-if-changed .` | Automated | PASS | After formatting the new test files. |
| `flutter analyze` | Automated | PASS | No issues. |
| `pubspec.yaml` / `pubspec.lock` | — | Unchanged | No new dependency. |
| Backend | — | Not affected | No `apps/api` change in Gate 2. |
| UAT | — | NOT RUN | UAT-29A-01…07 `NOT RUN` (no screens yet). |

---

## Phase 29A — Gate 3 (Tasks screens, Home navigation and router)

Branch `claude/amazing-brahmagupta-dbsrjc`, on Gate 2 (`d1e0fdf`). Run in this AI sandbox with Flutter 3.47.2 / Dart 3.13.2.

| Check | Type | Status | Notes |
|---|---|---|---|
| `test/features/tasks/tasks_pages_test.dart` (22 tests) | Automated | PASS | Through the real `CompanyApp`, router and `ApiClient` against an in-memory task API. **List:** Open shows only open tasks with "Overdue · 3 Sep", "Due today", "Due 12 Oct" and no label, project, and status text; Done loads once on first selection with completed/cancelled (a past due date not shown as overdue), and switching back doesn't refetch; both empty states; no-profile state; load error and Try again; a failed second page becomes the retry row and recovers; a row opens `/tasks/:id` in the Tasks tab and back returns. **Detail:** fields and "3 Sep 2026 · Overdue"; opens with the row's content immediately while reloading; "Not set" due and "No description."; four chips, no Cancelled; a cancelled task is read-only. Saving: chip doesn't move and controls disable while pending, then selected with a notice, one `PATCH {"status"}`; completing removes it from Open, shows it in Done, refreshes Home, and reopening returns it to Open with its overdue label; 403 → server message, reload, locked chips, session kept; offline → message, old status, success after reconnecting; 422 → the status error; 401 → Login with the session-ended notice; failed load without content → Try again; opened from Home, "24 Sep 2026 · Due today" from Home's company day. **Appearance:** light and dark at 200% text: no exceptions on list or detail. |
| `home_page_test.dart` interaction group (5 tests, was 3) | Automated | PASS | **Changed for R-6** (Phase 27's R-1 "nothing tappable" is refined): greeting and announcements still have no interactive widgets; only the Today task row and the My tasks tile have an `InkWell`, with one chevron; tapping schedule rows, the messages and notifications tiles, announcements and "+5 more today" still goes nowhere (one Home request); the tile opens the Tasks tab; a Today task row opens `/tasks/{id}` in the Tasks tab and back shows the list; the shell tabs still navigate (Tasks → `TasksPage`). |
| `router_test.dart` | Automated | PASS | One assertion changed: the Tasks tab is `TasksPage` (the "coming soon" placeholder is gone). |
| Mutation checks (UI) | Manual (AI) | PASS | Each made temporarily and restored. **Caught at once (7):** Home not passing its company date to the detail; no My tasks tile tap; the Open list not refreshing after a change; chips never shown selected; chips enabled while saving; the "Overdue" label dropped; the cancelled read-only branch removed. **Survived, then resolved (2):** forcing Home's change handler to `load()` instead of `refresh()` is equivalent (both re-fetch), so it was replaced by removing Home's listener, which is caught; not passing the row's item to the detail survived because nothing checked the detail opens with content — the "opens with content at once" test was added and the mutant is now caught. |
| Full `flutter test` | Automated | PASS | 330/330: 306 after Gate 2, plus 22 new and a net 2 in the Home group. |
| `dart format --output=none --set-exit-if-changed .` | Automated | PASS | — |
| `flutter analyze` | Automated | PASS | No issues. |
| `pubspec.yaml` / `pubspec.lock` | — | Unchanged | No new dependency. |
| Real device / emulator | — | Not run | No device in this sandbox; covered by widget tests only. UAT will exercise it. |
| Backend | — | Not affected | No `apps/api` change in Gate 3. |
| UAT | — | NOT RUN | UAT-29A-01…07 `NOT RUN` (not merged or deployed). |

---

## Phase 29A — Gate 4 (final integration review)

Branch `claude/amazing-brahmagupta-dbsrjc` at Gate 3 (`793f947`), confirmed up to date with `main` (`d8d550b`).

| Check | Type | Status | Notes |
|---|---|---|---|
| `composer validate --strict` | Automated | PASS | — |
| `composer audit --locked` | Automated | PASS | No advisories. |
| `vendor/bin/pint --test` | Automated | PASS | — |
| `vendor/bin/phpstan analyse` (level 5) | Automated | PASS | 0 errors. |
| `php artisan test` | Automated | PASS | 1,169/1,169 (3,310 assertions). |
| `flutter pub get` / `dart format` / `flutter analyze` | Automated | PASS | `pubspec` unchanged; 0 files to format; no issues. |
| `flutter test` | Automated | PASS | 330/330. |
| Contract parity (real API → app) | Manual (AI), temporary tests | PASS | A scratch Laravel test captured real `/me/tasks` (open; closed at `per_page=2`), `GET /tasks/{id}`, `PATCH` success and `422` for Staff, Manager and Administrator, a `403` for a task assigned to someone else ("You do not have permission to update this task."), a `403` for an assignee changing another field ("You may only update the status of a task assigned to you."), and the no-profile response, in `Asia/Manila` with non-ASCII titles, project and creator names, and null fields. A scratch Flutter test parsed every response with the production models (flags, statuses, priorities, nulls, `completed_at`, `withServerUpdate`) and fed the real `422`/`403` bodies through the real `ApiClient` (`ApiValidationException.firstErrorFor('status')`; `ApiForbiddenException` with the server message and the session kept): 9/9. Both files were deleted; nothing was committed. |
| Real device / emulator | — | Not run | No device in this sandbox. |
| MySQL ordering | — | Not run | Standard `IS NULL` ordering; exercised at staging. |
| UAT | — | NOT RUN | UAT-29A-01…07 `NOT RUN`. |

---

## Phase 29A — UAT preparation (runbook and data script rehearsal, 2026-10-09)

`docs/testing/PHASE_29A_UAT_PREPARATION.md`; script `uat29a_data.php` revision 1, SHA-256 `5091d5bb9ea2004428cefe543d9e98040f220f75892325f81c27632a7245732b`. Rehearsed in this AI sandbox on disposable scratch SQLite databases (all 45 migrations + `RolePermissionSeeder`, `SCHEDULING_COMPANY_TIMEZONE=Asia/Manila`) at `main` `3632ce1`, run as the runbook runs it (`php -- <stage> < script`). Nothing touched staging. MySQL was not available here; the runbook's `verify` step is the first MySQL check.

| Check | Type | Status | Notes |
|---|---|---|---|
| `plan` (read-only) | Manual, scratch DB | PASS | Company day printed; route `api.v1.me.tasks.index` detected; 4 accounts absent; 0 of 4 staff, 0 of 35 tasks, project absent; roles 3/3. |
| `seed` | Manual, scratch DB | PASS | Exit 0. STDOUT went to a `600` file with exactly 4 `email password` lines; all status went to STDERR. 4 staff records, 4 accounts, 1 project with Tomas as member, 35 tasks. Each seeded password verified with `Hash::check`. |
| `seed` re-run | Manual, scratch DB | PASS | 0 bytes on STDOUT, no account created, no password changed. After `reassign`, a re-run restored the plan (29 open again). |
| `verify` (real controllers) | Manual, scratch DB | PASS | Staff open 25 + 4 of 29 in exactly the §4 order (Replace pump seal and Waiting on parts `OVERDUE`, Call the supplier `TODAY`, Routine checks, Tidy the store last); closed 2 (completed, then cancelled); manager and admin 1 each, their own only; no-profile `tasks null`; parity `home=29/2/1 tasks=29/2/1 — OK`. |
| `reassign` | Manual, scratch DB | PASS | Moved "Reassign me" to UAT29A-004; staff open became 28 and parity `28/2/1 — OK`. |
| `revoke` / `exposure` | Manual, scratch DB | PASS | `exposure` reported a pre-created token on `uat29a.staff`; `revoke` deleted it (`api_tokens_revoked=1`). |
| `rotate` | Manual, scratch DB | PASS | Exit 0; `600` file, 4 lines; old passwords rejected and new ones accepted for all four (`Hash::check`). |
| Refusals | Manual, scratch DB | PASS | `rotate` with an account missing: exit 1, nothing on STDOUT, nothing changed. `seed` when a UAT29A staff record is linked to a non-UAT29A account: exit 1, nothing on STDOUT, a deliberately changed UAT29A task left as it was (the transaction never started). Unknown stage: exit 1. |
| All-or-nothing `rotate` | Manual, scratch DB (injected failure) | PASS | A failure on the 3rd account: exit 1, nothing on STDOUT, all four password hashes unchanged (rolled back), although STDERR had already printed `rotated …` for two accounts — the runbook now says such lines are void on a non-zero exit. |
| Non-UAT29A data untouched | Manual, scratch DB | PASS | A control user, staff record and task were identical (hash of all non-UAT29A users, staff and tasks) before and after `seed` and a re-`seed`. |
| Runbook extraction path | Manual | PASS | The §5 `awk` command applied to the committed runbook reproduces the script byte-for-byte (same SHA-256). |
| Credentials handling | — | — | Every scratch credentials file was `shred`ded; only email addresses were ever printed. |
| UAT | — | NOT RUN | UAT-29A-01…07 `NOT RUN`. |

---

## Phase 29A — staging deployment, UAT data and physical-device UAT (2026-10-09/10)

*Operator- and product-owner-reported. This AI session had no VPS or device access; the outputs below were pasted by the operator, except where marked "not supplied".*

| Check | Type | Status | Notes |
|---|---|---|---|
| Staging checkout | Operator, VPS | PASS | `git merge --ff-only` from `b6e85c5` to `3632ce1e821ccaca205f19abf5196e3df54fd35b`; `git rev-parse HEAD` confirmed. `git status --porcelain` showed one untracked file, `uat27_data.php` (the Phase 27 operator script left in the checkout root; outside the `apps/api` build context, so not in any image). Not blocking; left for the operator to move. |
| Backup | Operator, VPS | Reported OK | `mysqldump` ran (only the usual password-on-command-line warning). The runbook's `ls -l … \| tail -1` listed the `fms` directory instead of the dump (a runbook command mistake); the operator then reported the backup as OK. The file name and size were **not supplied**. |
| Build and restart | Operator, VPS | PASS | `company-app-api:staging` and `company-app-nginx:staging` built; `app` recreated; `nginx` kept running (nothing it serves changed); `mysql` untouched and healthy (no repeat of the Phase 28 network incident). |
| `migrate:status` | Operator, VPS | PASS | All 45 Ran, none pending. |
| Caches, timezone | Operator, VPS | PASS | `config:cache`, `route:cache`, `view:cache`; `scheduling.company_timezone` = `Asia/Manila`. |
| Smoke tests (public HTTPS) | Operator, VPS | PASS | `/up` 200, `/login` 200, `/api/v1/me/home` 401, `/api/v1/me/tasks` 401 (route live, not 404), `/api/v1/tasks` 401, `/api/v1/me/profile` 401. Only `127.0.0.1:8012` listening. |
| Script extraction | Operator, VPS | PASS | `uat29a_data.php` from `origin/main`: SHA-256 `5091d5bb9ea2004428cefe543d9e98040f220f75892325f81c27632a7245732b` (matches). |
| `plan` | Operator, VPS | PASS | Company day 2026-10-10 (Asia/Manila); route detected; 4 accounts absent; 0 of 4 staff, 0 of 35 tasks, project absent; roles 3/3. |
| `seed` | Operator, VPS | PASS | Exit 0 at 2026-10-10T00:41:26Z; 4 accounts created; 35 tasks set for company day 2026-10-10; credentials file `600 deploy`, 4 lines, read privately and `shred`ded. No password was recorded anywhere. |
| `verify` (real controllers, **first MySQL run of the new ordering**) | Operator, VPS | PASS | Staff open 25 + 4 of 29 in exactly the planned order (Replace pump seal 2026-10-05 and Waiting on parts 2026-10-09 `OVERDUE`; Call the supplier 2026-10-10 `TODAY`; Inspect wiring, Reassign me, Order filters; Routine check 01…22; Tidy the store, undated, last). Closed: completed, then cancelled. Manager and admin: their own one task each. No-profile: `tasks null`. Parity `home=29/2/1 tasks=29/2/1 — OK`. This confirms the `due_date IS NULL` / `id` ordering on MySQL 8.4. |
| `exposure` (before UAT) | Operator, VPS | PASS | All four accounts `api_tokens=0 web_sessions=0 audit: none`. |
| UAT APK | Operator, Windows | PASS | Checkout `3632ce1e821ccaca205f19abf5196e3df54fd35b`, `git status --porcelain` empty before and after the build. Flutter 3.47.2 (channel shown as `[user-branch]`: pinned by git), Dart 3.13.2, JDK Temurin 17.0.15, Android SDK 36, build-tools 36.0.0 (`flutter doctor` also noted unaccepted Android licences; the build was not affected). `pub get` ok; `dart format` 66 files, 0 changed; `flutter analyze` no issues; `flutter test` **330/330**, tasks **95/95**, home **66/66**, network **29/29**. `flutter build apk --release --dart-define=API_BASE_URL=https://company-staging.storm-ark.com/api/v1` → `app-release.apk`, **52,534,387 bytes**, SHA-256 **`D6242D79B42C0804291551823C7DE86A76BD13A310E33CF512D27427A2131460`**, built 2026-10-10 10:28:56 (operator's local time). An earlier operator build of the same checkout had the identical size and SHA-256 (the release build was reproducible). `aapt2`: `com.companyapp.mobile` versionCode 1 / 1.0.0, minSdk 24, target/compile 36, `INTERNET` present. Signer: Android Debug (SHA-256 `8c97303ec1a4d6eda2f2b9ee83694cf0915e8f9e55077d169f30d23bf4804412`), known and deferred to Phase 38. |
| APK install | Operator, device | Reported | Installed and used for UAT; the `adb install` output was **not supplied**. |
| UAT-29A-01…07 | Product owner, physical device | **PASS** | All seven reported `PASS` on 2026-10-10, scenario by scenario, with the step-by-step expectations given for each (list order and labels; detail, status changes, Done and Home counts and reopening; no Cancelled and read-only cancelled task; Manager/Admin self-scope and the no-profile state; Home tile and Today-row navigation; offline save, reassigned-task 403 and token revocation; dark mode and largest text). No per-scenario observations or defects were reported. |
| `reassign` / `revoke` (UAT-29A-06) | Operator, VPS | Reported | Run during UAT-29A-06, which passed; their output lines were **not supplied**. |
| `exposure` (after UAT) | Operator, VPS | Reported | The product owner reported everything as passed; the output itself was **not supplied**. |

Staging now runs `3632ce1`. The UAT29A data (4 accounts, 4 staff records, project `UAT29A-P1`, 35 tasks, "Reassign me" now with UAT29A-004) stays in place, alongside the UAT28 and UAT27 data; any cleanup needs its own authorization.

---

## Phase 29B — Gate 1 (backend: company-time "today", `/me/work-logs` tie-breaker and company day)

Branch `claude/amazing-brahmagupta-dbsrjc`, from `main` at `496bd5c` (PR #64). Run in this AI sandbox (PHP 8.4, SQLite in-memory per `phpunit.xml`).

| Check | Type | Status | Notes |
|---|---|---|---|
| `tests/Feature/Api/V1/WorkLogs/WorkLogCompanyDayTest.php` (9 tests) | Automated | PASS | Company timezone `Asia/Manila`. At **00:30 Manila** (16:30 UTC the previous day): self-service create for the Manila today → 201, for the Manila tomorrow → 422 with "The work date cannot be later than today."; self-service update the same; Administrator create and update the same. At 23:30 Manila: today 201, tomorrow 422. `meta.company_day` = `{2026-10-10, Asia/Manila}` at 00:30 Manila, with `data`/`links`/`meta` and every paginator `meta` key still present; it turns from 2026-10-09 to 2026-10-10 between 15:59 and 16:00 UTC. No linked Staff record → still `403` with the same message (R-13). Five logs with the same `work_date` and `created_at` over `per_page=2` pages come back once each, newest id first, and the SQL contains `order by "work_date" desc, "created_at" desc, "id" desc`. |
| `WorkLogTest::test_work_date_cannot_be_in_the_future` (rewritten) | Automated | PASS | Now uses the company tomorrow. **Shown to be necessary:** the old `now()->addDay()` version, run in a temporary test at 17:00 UTC with a Manila company day, got `201` instead of `422`; the temporary test was deleted. The other 38 existing work-log tests are unchanged and pass. |
| Mutation checks | Manual (AI) | PASS | Each made temporarily, the work-log tests run, and the file restored; all 8 caught: restoring the UTC `before_or_equal:today` in each of the four requests (1 failure each); dropping the custom message (3); dropping the date ceiling altogether (5); removing the `id` tie-breaker (1); computing `meta.company_day` from the UTC date (2). |
| Full `php artisan test` | Automated | PASS | 1,178/1,178 (3,359 assertions): 1,169 after 29A plus these 9. One existing test changed (above). |
| `composer validate --strict` | Automated | PASS | — |
| `composer audit --locked` | Automated | PASS | No advisories. |
| `vendor/bin/pint --test` | Automated | PASS | — |
| `vendor/bin/phpstan analyse` (level 5) | Automated | PASS | 0 errors. |
| MySQL | — | Not run | The ORDER BY is plain columns; the date rule is PHP-side. To be exercised at staging. |
| Mobile | — | Not affected | No `apps/mobile` change in Gate 1. |
| UAT | — | NOT RUN | UAT-29B-01…08 `NOT RUN` (not yet runnable). |

---

## Phase 29B — Gate 2 (mobile work-log data and state)

Branch `claude/amazing-brahmagupta-dbsrjc`, on Gate 1 (`58e91ec`). Run in this AI sandbox with Flutter 3.47.2 / Dart 3.13.2.

| Check | Type | Status | Notes |
|---|---|---|---|
| `test/features/work_logs/work_log_models_test.dart` (15 tests) | Automated | PASS | Task, project and independent-task logs and their labels; missing description, a timestamp as `work_date`, a string duration, neither task nor project, a malformed task → `FormatException`. `/me/work-logs` paging and `meta.company_day`; missing or malformed company day rejected. Project statuses and `isClosed`. Target request fields. `formatDuration`; `isApiDate`. |
| `test/features/work_logs/work_logs_api_client_test.dart` (13 tests) | Automated | PASS | Exact `/me/work-logs` path and `per_page`/`page`; the company day from `per_page=1`; a `403` keeps the session (one `/auth/me` re-check) and is `ApiForbiddenException`; bad shape → `ApiRequestException`. `POST` with `task_id` only or `project_id` only plus the three fields; a `422`'s field errors. `PATCH` with only date, duration and description (id encoded); `DELETE` 204; `404` as a request failure. Own staff id from `/me/profile` (null without a profile); my projects with `member=` over every page, and the page cap. |
| `test/features/work_logs/my_work_logs_controller_test.dart` (13 tests) | Automated | PASS | First page with the company day; grouping by date, merging a date that spans pages; no duplicates; `403` → no-profile (session kept, no error); first-load error and recovery; failed refresh keeps the list; failed load more retried; a load more overtaken by a refresh dropped; concurrent loads share one request. Changes: a deletion leaves at once; a same-date edit replaces its row; a date change waits for the refresh; a new log marks stale and `refreshIfStale` refreshes once; dispose removes the listener. |
| `test/features/work_logs/work_log_form_controller_test.dart` (30 tests) | Automated | PASS | Create: company day from `/me/work-logs`, default date not counted as an edit, my open tasks (with projects) and my projects with completed/cancelled hidden, `member=` and `state=open` sent; a given company day is used without a request; a fixed task loads nothing; edit fields and read-only target. No profile from a `403`, from `/me/tasks` `null`, or from `/me/profile` without staff; load error and recovery. Date bounds: 365 days back, an older edited log's own date, calendar arithmetic across a leap day. Checks with exact messages per field, at and beyond each limit; editing a field clears its error; nothing invalid is sent. Save: the exact `POST` body (trimmed text, total minutes) and the recorded change; edit sends only three fields; single-flight; server `422`s on fields and eligibility at the top; a `422` with no known field; offline/500/403 keep values and allow a retry; a `404` on edit records removal; a `401` ends the session. Delete: once and recorded; already gone counts as deleted; failure kept with a message; a new log can't be deleted. Dirty state across every field. |
| Mutation checks | Manual (AI) | PASS | Each made temporarily and restored; all 13 caught: the list's and the form's `403` → no-profile mapping (2); grouping that never merges a date; a deletion not removed; closed projects not hidden; the edited log's own date ignored as the floor; the 24-hour limit; no new baseline after saving; eligibility errors not shown; description not trimmed; a `404` delete not counted as done; `member=` not sent; an extra field sent on `PATCH`. (A first attempt at the `403` mutant didn't compile and was redone with a valid substitution.) |
| Full `flutter test` | Automated | PASS | 401/401: 330 after 29A plus these 71. No existing test changed. |
| `dart format` / `flutter analyze` | Automated | PASS | No changes; no issues. |
| `pubspec.yaml` / `pubspec.lock` | — | Unchanged | No new dependency. |
| Backend | — | Not affected | No `apps/api` change in Gate 2. |
| UAT | — | NOT RUN | UAT-29B-01…08 `NOT RUN` (no screens yet). |

---

## Phase 29B — Gate 3 (work-log screens, "Log work" and routes)

Branch `claude/amazing-brahmagupta-dbsrjc`, on Gate 2 (`90dfb70`). Run in this AI sandbox with Flutter 3.47.2 / Dart 3.13.2.

| Check | Type | Status | Notes |
|---|---|---|---|
| `test/features/work_logs/work_logs_pages_test.dart` (20 tests) | Automated | PASS | Through the real `CompanyApp`, router and `ApiClient` against an in-memory work-log API. **List:** More → My work logs; "Today", "Yesterday", "Wed 7 Oct" headers in order; task/project/description/duration per row ("1 h 30 min", "30 min", "2 h"); empty state; no-profile (403) without Add and with the session kept; load error and Try again; paging with a failed page retried and a new date header. **Add:** the form defaults to "Sat 10 Oct 2026"; the picker shows open tasks and open projects, not a cancelled task or a completed project; a project log saves the exact body, shows "Work logged." and appears in the refreshed list; checks before sending (three messages, nothing sent; 25 hours refused); the date picker can't go past the company today (a disabled 11th leaves the date unchanged) and can pick the 9th; a server eligibility `422` shown; offline keeps the text and saves later; a `401` returns to Login. **Edit/delete:** values shown, target not choosable, only three fields sent, "Changes saved." and the row updated; leaving with changes asks — Keep editing stays, Discard leaves and sends nothing; leaving without changes doesn't ask; delete asks, Cancel sends nothing, Delete sends `DELETE`, shows "Work log deleted." and removes the row. **From a task:** "Log work" opens `/tasks/T1/log-work` with the task fixed and today's date, saves with `task_id`, returns to the task, and My work logs then lists it; a cancelled task has no "Log work". **Appearance:** light and dark at 200% text: list, form and picker without exceptions. |
| `people_pages_test.dart` More test | Automated | PASS | **Changed:** More lists exactly three rows now — My profile, Staff directory and My work logs (Phase 28's R-8: each phase adds its own row). |
| Mutation checks (UI) | Manual (AI) | PASS | Each made temporarily and restored. **Caught at once (9):** no tap on the More row; the discard check disabled; delete without confirmation; "Log work" shown for a cancelled task; the projects section removed from the picker; the list not refreshing after a change; the task not fixed when logging from it; "Yesterday" never shown; the success message changed. **Survived, then resolved (1):** allowing 30 future days in the date picker — the test chose the 11th and then the 9th, so the 11th never mattered; it now presses OK after the disabled 11th and checks the date is unchanged, and the mutant is caught. |
| Full `flutter test` | Automated | PASS | 421/421: 401 after Gate 2 plus these 20. One existing test changed (above). |
| `dart format` / `flutter analyze` | Automated | PASS | No changes; no issues. |
| `pubspec.yaml` / `pubspec.lock` | — | Unchanged | No new dependency. |
| Real device / emulator | — | Not run | No device in this sandbox. |
| Backend | — | Not affected | No `apps/api` change in Gate 3. |
| UAT | — | NOT RUN | UAT-29B-01…08 `NOT RUN` (not merged or deployed). |

---

## Phase 29B — Gate 4 (final integration review)

Branch `claude/amazing-brahmagupta-dbsrjc` at Gate 3 (`733d0fc`), confirmed up to date with `main` (`496bd5c`).

| Check | Type | Status | Notes |
|---|---|---|---|
| `composer validate --strict` / `composer audit --locked` | Automated | PASS | Valid; no advisories. |
| `vendor/bin/pint --test` / `vendor/bin/phpstan analyse` (level 5) | Automated | PASS | 0 errors. |
| `php artisan test` | Automated | PASS | 1,178/1,178 (3,359 assertions). |
| `flutter pub get` / `dart format` / `flutter analyze` | Automated | PASS | `pubspec` unchanged; 0 files to format; no issues. |
| `flutter test` | Automated | PASS | 421/421. |
| Contract parity (real API → app) | Manual (AI), temporary tests | PASS | A scratch Laravel test recorded 31 responses for a Staff user and a Manager in `Asia/Manila` at 00:30: `GET /me/work-logs` (25 and 1 per page; `meta.company_day` `{2026-10-10, Asia/Manila}`, the paginator keys intact, same-date logs newest first); `POST` for a project, a project task and an independent task (1440 minutes) → 201; `422` for a future date ("The work date cannot be later than today."), a non-member project, both task and project, and zero minutes plus a missing description; `PATCH` → 200; `PATCH` with `task_id` → 422 prohibited; `DELETE` → 204; someone else's log → 404; `/me/profile`; `/projects?member=` (the Manager sees only their two member projects, one completed); and the no-profile `403`. Non-ASCII project, task and description text. A scratch Flutter test replayed them through the production models, `WorkLogsApiClient`, `WorkLogFormController` (each `422` on the right field or at the top; delete `204` and `404` both "gone") and `MyWorkLogsController` (`403` → no-profile, session kept): **7/7**. Two scratch-test mistakes were fixed on the way (parsing the empty `204` body; a duplicated unique project code). Both files were deleted; nothing was committed. |
| Real device / emulator | — | Not run | No device in this sandbox. |
| MySQL | — | Not run | Plain-column ORDER BY; to be exercised at staging. |
| UAT | — | NOT RUN | UAT-29B-01…08 `NOT RUN`. |

---

## Phase 29B — UAT preparation (runbook and data script rehearsal, 2026-10-10)

`docs/testing/PHASE_29B_UAT_PREPARATION.md`; script `uat29b_data.php` revision 1, SHA-256 `327b56b761740ab8c7f771451250e17690c39933462d6240aadba8a7bb10ad0f`. Rehearsed in this AI sandbox on a disposable scratch SQLite database (all 45 migrations + `RolePermissionSeeder`, `SCHEDULING_COMPANY_TIMEZONE=Asia/Manila`, company day 2026-10-10) at `main` `7e29ffe`, run as the runbook runs it (`php -- <stage> < script`), with a non-UAT control user, staff record, project and work log in place. Nothing touched staging. MySQL was not available here; the runbook's `verify` step is the first MySQL check.

| Check | Type | Status | Notes |
|---|---|---|---|
| `plan` (read-only) | Manual, scratch DB | PASS | Company day printed; both accounts absent; staff record absent; 0 of 3 projects, 0 of 4 tasks, 0 of 30 logs; roles 3/3. |
| `seed` | Manual, scratch DB | PASS | Exit 0. STDOUT went to a `600` file with exactly 2 `email password` lines (20-character passwords); all status went to STDERR. |
| `seed` re-run | Manual, scratch DB | PASS | Exit 0, 0 bytes on STDOUT, "password unchanged" for both. After `unjoin`, a re-run restored the Roof Repair membership. |
| `verify` (real controllers) | Manual, scratch DB | PASS | `meta.company_day` `{2026-10-10, Asia/Manila}`; page 1/2 25 of 30 and page 2/2 5 of 30 in the §4 order (Planning meeting above Checked boiler pressure on the same date; Routine entry 01…21, then 22…26); picker tasks Inspect boiler and Call the vendor only; picker projects Boiler Upgrade and Roof Repair, Archive hidden; no-profile `403 No staff record is linked to this account.` |
| `todaycheck` (R-8) | Manual, scratch DB | PASS | Clock 2026-10-11 00:30 Manila (2026-10-10 16:30 UTC): 2026-10-11 accepted, 2026-10-12 rejected with "The work date cannot be later than today."; `R-8 check: OK`, exit 0. Work-log count unchanged (nothing saved). |
| `todaycheck` mutation | Manual, scratch DB | PASS | With `LimitsWorkDateToCompanyToday` temporarily reverted to `before_or_equal:today` (UTC), the company "today" was rejected and the stage printed `R-8 check: PROBLEM`, exit 1. The file was restored (`git checkout`). |
| `unjoin` | Manual, scratch DB | PASS | `rows=1`; `verify` then listed only Boiler Upgrade in the picker. |
| `revoke` / `exposure` | Manual, scratch DB | PASS | With tokens pre-created for both UAT29B accounts and a control user, `exposure` reported 1 each; `revoke` deleted only `uat29b.staff`'s (`api_tokens_revoked=1`); the control user's token was untouched. |
| `rotate` | Manual, scratch DB | PASS | Exit 0; `600` file, 2 lines; tokens revoked; `exposure` then 0/0 for both. |
| Refusals | Manual, scratch DB | PASS | `rotate` and `revoke` with `uat29b.staff` missing: exit 1, nothing on STDOUT. `seed` when the UAT29B staff record is linked to a non-UAT29B account: exit 1, nothing on STDOUT, data identical (snapshot). Unknown stage: exit 1. |
| All-or-nothing `rotate` | Manual, scratch DB (injected failure) | PASS | A failure on the second account: exit 1, nothing on STDOUT, both password hashes and remember tokens unchanged, although STDERR had already printed `rotated …` for the first. |
| Non-UAT29B data untouched | Manual, scratch DB | PASS | A hash of all non-UAT29B users, staff, projects, memberships, tasks, work logs and tokens was identical across `seed`, re-`seed`, `verify`, `todaycheck`, `unjoin`, `revoke`, `exposure`, `rotate` and the refusals. |
| Runbook extraction path | Manual | PASS | The §5 `awk` command applied to the committed runbook reproduces the script byte-for-byte (same SHA-256). |
| Credentials handling | — | — | Every scratch credentials file was `shred`ded; only email addresses and password lengths were ever printed. |
| UAT | — | NOT RUN | UAT-29B-01…08 `NOT RUN`. |

---

*(Future phases append their own section above this line, oldest first.)*
