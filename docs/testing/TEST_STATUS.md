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

*(Future phases append their own section above this line, oldest first.)*
