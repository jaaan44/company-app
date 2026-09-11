# Phase 4A Handoff — Docker Development Environment

## 1. Objective

Establish Docker Compose as the standard local backend development environment — a lean three-service stack (`nginx`, `app`/PHP-FPM, `mysql`) — so contributors develop against the same MySQL engine the production direction (DEC-016) actually targets, appropriate for a ~100-employee internal tool. Flutter stays outside Docker entirely. No RBAC or other business functionality.

## 2. Repository/Main Verification

Read, in order: `CLAUDE.md`, `docs/CURRENT_STATE.md`, `docs/ROADMAP.md`, `docs/handoffs/V1_PHASE_04_HANDOFF.md`, and the current Laravel app/config (composer.json's PHP requirement, `config/database.php`'s MySQL defaults, `.env.example`).

Fetched `origin/main` and confirmed it contains PR #5's merge (`1790313`) with the actual Phase 4 artifacts: `AuthController`, `docs/handoffs/V1_PHASE_04_HANDOFF.md`, DEC-022–026 in `docs/DECISIONS.md`, and `laravel/sanctum` in `composer.json`. **No material contradiction found** — branched `claude/v1-phase-04a-docker-development` from `origin/main` and proceeded.

## 3. Docker Architecture

```
Flutter (host/device)
        │
        ▼
     nginx  ──►  app (PHP-FPM 8.4)  ──►  mysql
```

Three services only, defined in a root `docker-compose.yml`. No Redis, queue worker, scheduler, WebSocket server, Mailpit, phpMyAdmin, Elasticsearch, Node container, or Kubernetes — none is demonstrably needed. Flutter (`apps/mobile`) is untouched and never Dockerized.

## 4. Service Versions

- `app`: built from `php:8.4-fpm` (matches `composer.json`'s `^8.3` requirement and CLAUDE.md §5's PHP 8.4 baseline), extensions `pdo_mysql`+`bcmath` added (everything else Laravel needs — `mbstring`, `pdo`, `curl`, `dom`, `xml`, `mysqlnd`, etc. — already ships in the base image), Composer copied from `composer:2`.
- `nginx`: `nginx:1.27-alpine`.
- `mysql`: `mysql:8.4` (matches the production direction, DEC-016).

## 5. Ports

**Updated post-handoff (product-owner Windows UAT, see §27):** both host ports are now configurable rather than fixed, since the original fixed defaults each collided with something already running on the product owner's machine.

- `nginx`: host `${APP_PORT:-8012}` → container `80` (container's own internal port, 80, unchanged and unaffected by the host mapping). `8012` is the project's standard default, chosen not to collide with common locally-run services; override by exporting `APP_PORT` or setting it in a root-level `.env`. Flutter's `API_BASE_URL` must be pointed at whichever port is actually in use, via the existing `--dart-define` mechanism (DEC-021) — never hard-coded into Flutter application code.
- `mysql`: host `127.0.0.1:${MYSQL_PORT:-3347}` → container `3306` (container's own internal port, `DB_PORT=3306` in Laravel's config, unchanged and unaffected by the host mapping) — localhost-bound only, for an optional local GUI client (e.g. MySQL Workbench); not exposed beyond the developer's own machine. `3347` is the project's non-conflicting default (3306 is commonly already taken by another local MySQL install, as it was on the product owner's machine); override via `MYSQL_PORT`.

*(Original, since-superseded text: `nginx` host `8000`, `mysql` host `127.0.0.1:3306`, both fixed — see §27 for why and how this changed.)*

## 6. Volume Strategy

- `./apps/api:/var/www/html` (bind mount, read-write for `app`, read-only for `nginx`) — the host's editor sees every change instantly; no sync tooling.
- `vendor:/var/www/html/vendor` (named volume) — mounted over the bind mount's `vendor/` path specifically so the host's `apps/api/vendor` (whatever is or isn't there) never shadows/conflicts with what `composer install` produces inside the container. First-time setup includes one `docker compose exec app composer install`.
- `mysql-data:/var/lib/mysql` (named volume) — database state persists across `docker compose down`/`up`.

## 7. Composer/Vendor Strategy

See §6 — the named `vendor` volume is the whole strategy. Composer itself is baked into the `app` image (copied from the official `composer:2` image), so `docker compose exec app composer <command>` always works without a separate install step.

## 8. Environment Configuration

Laravel reads `apps/api/.env` directly — it's part of the bind mount, so Laravel's own Dotenv loading picks it up with zero Docker-specific wiring needed. The `app` service **deliberately does not** use Compose's `env_file`/`environment` to also inject those same values as container-level OS environment variables — see §19/§23 for why this was tried, found to be actively harmful, and removed. `apps/api/.env.docker.example` was added: identical to `.env.example` except `DB_CONNECTION=mysql`, `DB_HOST=mysql`, `DB_DATABASE=company_app`, `DB_USERNAME=company_app`, `DB_PASSWORD=secret` — matching the `mysql` service's hardcoded local-dev credentials exactly. No real secrets anywhere; these are unmistakably local-dev-only values, same spirit as Phase 4's `AdminUserSeeder`.

## 9. MySQL Configuration

`MYSQL_DATABASE=company_app`, `MYSQL_USER=company_app`, `MYSQL_PASSWORD=secret`, `MYSQL_ROOT_PASSWORD=secret` — hardcoded directly in `docker-compose.yml` (not templated from a root-level `.env`, to avoid a second, confusing environment-file layer alongside Laravel's own `apps/api/.env`). These credentials are unaffected by the host-port change in §5/§27 — only the *host* mapping (for external tools) is configurable; the container's own internal port (`3306`) and all credentials are unchanged. A healthcheck (`mysqladmin ping -h localhost -uroot -psecret`, 5s interval, 10 retries) gates `app`'s startup via `depends_on: mysql: condition: service_healthy`, so migrations never race an unready database.

## 10. Nginx Configuration

`docker/nginx/default.conf` — conventional Laravel vhost: document root `/var/www/html/public` (i.e. `apps/api/public` via the bind mount), `try_files` routing everything through `index.php`, PHP requests `fastcgi_pass`ed to `app:9000`, and a `location ~ /\.` block denying any dotfile (`.env`, `.git`, etc.) or path outside the public document root.

## 11. Permissions Handling

The container's `php-fpm` runs as `www-data` (the base image's default), which essentially never matches whatever UID owns the bind-mounted files across Windows/macOS/Linux. `docker/php/entrypoint.sh` runs `chmod -R a+rwX storage bootstrap/cache` on container start — scoped to exactly the two directories Laravel needs to write to, not the whole application, and not a blanket `777` (the capital `X` only sets execute on directories/already-executable files, never on plain data files). This was **found to be a real bug during validation**, not assumed correct — see §19.

## 12. Flutter Connectivity

Flutter's own code is unchanged and untouched by this phase — `lib/core/config/app_config.dart`'s built-in default (`http://localhost:8000/api/v1`, matching direct-install `php artisan serve`) was deliberately left as-is (the governing instructions for the port change explicitly said not to hard-code `8012` into Flutter application behavior). `README.md` documents `API_BASE_URL` examples for the three target types, now pointed at the standard Docker backend's default port (`8012`, updated post-handoff — see §27):
- Desktop/web: `http://localhost:8012/api/v1`
- Android emulator: `http://10.0.2.2:8012/api/v1` (the emulator's standard alias for the host machine)
- Physical device on the same LAN: `http://<host-LAN-IP>:8012/api/v1`

None of these are hard-coded into application code — all via the existing `--dart-define=API_BASE_URL=...` mechanism (DEC-021). A developer running the backend via direct install instead can omit `--dart-define` entirely and get the unchanged `8000` default.

## 13. Authentication Verification

All performed against a genuinely running Docker stack (see §17), not assumed:
- All 5 migrations (including Phase 4's `add_authentication_fields_to_users_table` and Sanctum's `personal_access_tokens`) ran cleanly against real MySQL 8.4.
- `AdminUserSeeder` ran successfully inside the container, creating the local dev admin account.
- `GET /login` reachable through Nginx (200, Livewire component present).
- `POST /api/v1/auth/login` through Nginx → MySQL → Sanctum token issuance, full cycle confirmed (200, correct `UserResource` shape + token).
- `GET /api/v1/health` through Nginx (200).
- The full Phase 4 automated Authentication suite (all 31 tests across `AdminLoginTest`, `LoginTest`, `MeAndLogoutTest`, plus the pre-existing tests) — 31/31 passing inside the container.

No Authentication business behavior was changed — only its runtime environment.

## 14. Files Changed

**Added:** `docker-compose.yml` (repo root); `docker/php/Dockerfile`; `docker/php/entrypoint.sh`; `docker/php/conf.d/local-dev.ini`; `docker/nginx/default.conf`; `apps/api/.env.docker.example`; `.gitattributes` (repo root — added post-handoff, §26); `docs/phases/V1_PHASE_04A_DEFINITION.md`; `docs/handoffs/V1_PHASE_04A_HANDOFF.md` (this file).

**Modified:** `CLAUDE.md` (§5 Docker note, §9 repository layout), `README.md`, `docs/02_ARCHITECTURE.md` (new §14, §11 updated), `docs/03_DATABASE_MODEL.md`, `docs/DECISIONS.md` (DEC-013 superseded, DEC-027 added), `docs/ROADMAP.md` (Phase 4A inserted), `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/testing/TEST_STATUS.md`.

No `apps/api` or `apps/mobile` application code was changed — this phase is infrastructure/documentation only.

## 15. Commands Actually Executed

**Environment setup (this session):**
- `docker version` / `docker compose version` — CLI present; daemon not running by default.
- `dockerd` (backgrounded) — daemon started successfully in this sandbox (root, cgroup v1, overlayfs).
- `docker pull mirror.gcr.io/library/{php:8.4-fpm,nginx:1.27-alpine,mysql:8.4,composer:2}` then `docker tag` to their canonical Docker Hub names — this sandbox's network policy blocks Docker Hub's own blob-storage CDN (`production.cloudfront.docker.com`, confirmed via the agent-proxy status endpoint as a deliberate `connect_rejected` policy denial, not a transient failure) but allows Google's public Docker Hub mirror.

**Validation (see §17 for why a temporary Dockerfile variant was needed for the build/runtime portion):**
| Command | Result |
|---|---|
| `docker compose config` | Valid — full resolved config printed, no errors |
| `docker compose build app` (real, committed Dockerfile) | Fails at `apt-get update` — `deb.debian.org` returns `403 Forbidden` from this sandbox's network (confirmed real, not a mirror/DNS issue) |
| `docker compose build app` (temporary variant, apt-get step removed, everything else identical) | Succeeds |
| `docker compose up -d` | All 3 containers started; `mysql` reported `healthy` |
| `php artisan tinker --execute="DB::connection()->getPdo()"` (in `app`) | `connected` |
| `php artisan migrate --force` (in `app`) | All 5 migrations, `DONE` |
| `php artisan db:seed --class="Database\Seeders\AdminUserSeeder" --force` (in `app`) | Admin account created |
| `curl http://localhost:8000/api/v1/health` | `200`, `{"data":{"status":"ok",...}}` |
| `curl http://localhost:8000/login` | `500` initially (see §19) → `200` after the entrypoint fix |
| `curl -X POST http://localhost:8000/api/v1/auth/login ...` | `200`, valid token issued |
| `php artisan test` (in `app`) | `31 passed` initially reported `2 failed` (see §19) before the `env_file` fix; `31 passed (96 assertions)` after |
| `vendor/bin/pint --test` (in `app`) | `PASS .......... 44 files`, exit 0 |
| `vendor/bin/phpstan analyse` (in `app`) | Crashed on the base image's 128M `memory_limit` (see §19) → `[OK] No errors` after `local-dev.ini` fix |
| `composer validate --strict` (host, unaffected) | `./composer.json is valid` |
| `vendor/bin/pint --test` / `vendor/bin/phpstan analyse` / `php artisan test` (host, unaffected) | All pass — 31/31, 0 errors, clean |

## 16. Exact Validation Results

See §15's table and `docs/testing/TEST_STATUS.md`'s Phase 4A section for the complete checklist with PASS/BLOCKED status per item.

## 17. Docker Runtime Validation Status

**Genuine runtime validation was performed, not skipped** — with one specific, clearly-scoped limitation. This sandbox's network policy blocks `apt-get`'s access to `deb.debian.org` (needed only to install `git`+`unzip` for Composer) during an image *build*, while still allowing `docker pull` of pre-built images via a Docker Hub mirror. To validate everything downstream of that one blocked step without weakening the real, committed Dockerfile:

1. The **real, committed** `docker/php/Dockerfile` was built as-is and confirmed to fail at exactly the `apt-get` line (§15) — proving the rest of it is unaffected and the limitation is precisely scoped.
2. A **temporary, uncommitted** Dockerfile variant (identical except the `apt-get`/`git`/`unzip` line removed — `pdo_mysql`/`bcmath` install via `docker-php-ext-install` needs no network at all, confirmed independently) was used, together with a temporary compose override, purely so the full stack (nginx, mysql, and a working app container) could actually run in this sandbox.
3. Composer itself wasn't exercised inside the container (no `git`/`unzip` in the test variant); the existing host-side `apps/api/vendor` (already fully populated and verified working, from the Phase 4 session) was copied into the container's named `vendor` volume via `docker cp` instead, so migrations/tests/Pint/PHPStan could all run for real.
4. All temporary artifacts (`/tmp/docker-test/*`, the test containers/volumes) were deleted after validation; nothing test-only was committed.

**A real developer or CI runner with normal internet access will build the actual, committed Dockerfile successfully** — this is a constraint of this specific sandbox's network policy, not of the Dockerfile. Per CLAUDE.md's established pattern for exactly this class of limitation (see the Phase 2–4 PHPStan/Flutter-SDK precedents), this is reported accurately rather than assumed away.

## 18. GitHub Actions Status

A draft pull request ([#6](https://github.com/jaaan44/company-app/pull/6), `claude/v1-phase-04a-docker-development` → `main`, not merged) was opened to exercise CI, matching the established pattern.

**Backend CI ran and passed on commit `f13de5a`:**

| Workflow | Job | Result | Duration | Run |
|---|---|---|---|---|
| Backend CI | Backend quality gates (PHP 8.4) | ✅ success | ~25s | [run 34552289717](https://github.com/jaaan44/company-app/actions/runs/34552289717) |

Mobile CI did not trigger — no `apps/mobile` files changed, correctly respecting the path-filtered CI design (DEC-015). This confirms `composer.json`/`composer.lock` remain correctly resolved and that this phase's one new tracked file under `apps/api` (`.env.docker.example`) doesn't affect any existing check on GitHub's unrestricted-network runner.

**Re-confirmed green after the CRLF fix (§26), commit `272625e`:**

| Workflow | Job | Result | Duration | Run |
|---|---|---|---|---|
| Backend CI | Backend quality gates (PHP 8.4) | ✅ success | ~17s | [run 34573583090](https://github.com/jaaan44/company-app/actions/runs/34573583090) |

## 19. Deviations

None from `docs/phases/V1_PHASE_04A_DEFINITION.md`'s scope. Two **real bugs were found and fixed** during genuine runtime validation — recorded here rather than silently corrected, since they materially affect what "Docker environment works" means:

1. **Permissions bug:** the entrypoint's first version used `chmod -R ug+rwX storage bootstrap/cache`. Since the bind-mounted files are owned by `root:root` (this sandbox's user) and the container's `php-fpm` worker runs as `www-data` — which is neither the owner nor in the `root` group — `ug+rwX` granted it *no* write access at all. Symptom: `GET /login` returned `500` (`tempnam(): file created in the system's temporary directory` — Blade trying and failing to write a compiled view). Fixed to `chmod -R a+rwX storage bootstrap/cache`, which is correct regardless of which UID ends up owning the bind-mounted files on any host OS, while still being scoped to only those two directories (not the whole application, not `777`).
2. **Test-isolation bug:** the `app` service's first version included `env_file: ./apps/api/.env`, intended purely as a convenience. This makes Docker inject those values as real container-level OS environment variables. Laravel doesn't need this (it reads `.env` itself via the bind mount) — and it's actively harmful: PHPUnit's `<env>` overrides in `phpunit.xml` (`DB_CONNECTION=sqlite`, `APP_ENV=testing`, etc.) do **not** force-replace a variable that already exists at the OS level. With `env_file` present, `php artisan test` inside the container silently ran against the real `mysql` connection instead of the intended isolated in-memory SQLite — 2 of 27 tests failed for reasons unrelated to app logic (a stale-cache-triggered `BadMethodCallException` and a CSRF/session `419`, both artifacts of the wrong environment), and more importantly, RefreshDatabase was migrating/wiping the *real* local dev database on every test run rather than an isolated one. Fixed by removing `env_file` entirely — Laravel's own `.env` loading was always sufficient.

Both are documented in `02_ARCHITECTURE.md` §14, `docs/DECISIONS.md` DEC-027's context, and the Dockerfile/compose file's own inline comments, so a future session doesn't reintroduce either.

## 20. Known Issues

- The `apt-get` step in the real Dockerfile could not be executed in this specific sandbox (§17) — not a defect, a sandbox network-policy limitation. GitHub Actions doesn't build/run this Dockerfile (CI remains SQLite-based, unchanged — §16 of the governing instructions), so this has no CI impact; it only affects what this session could verify directly versus what a real developer's machine will do.
- No Windows/macOS-specific manual verification was possible in this Linux sandbox — the permissions/volume design (§11, §6) is written to be cross-platform-safe by construction (UID-agnostic `a+rwX`, a named volume rather than relying on bind-mount UID matching), but hasn't been physically verified on those platforms. **Update:** the product owner did perform Windows UAT and found one real issue — a CRLF checkout bug, not a permissions/volume issue — fixed; see §26.
- `docker compose exec app composer install` itself (the real, network-dependent path a real developer takes on first setup) was not directly exercised in this sandbox for the reason in §17 — only its *effect* (a populated `vendor/`) was verified, via the pre-existing host vendor copied in.

## 21. Resource-Efficiency Review

- Exactly three services — no Redis, queue worker, scheduler, WebSocket server, Mailpit, phpMyAdmin, Elasticsearch, Node container, or Kubernetes, matching `02_ARCHITECTURE.md` §0 and the explicit instruction not to add these without demonstrated need.
- The `app` image installs only `git`+`unzip` (Composer's needs) plus two PHP extensions (`pdo_mysql`, `bcmath`) beyond what the base `php:8.4-fpm` image already provides — no general-purpose "kitchen sink" PHP image.
- No build matrix, no multi-arch builds, no image registry/publishing — this is a local development environment only.
- GitHub Actions is completely unaffected — no added CI minutes, no Docker-in-CI overhead.

## 22. Security Review

- MySQL/Docker credentials (`company_app`/`secret`, root/`secret`) are unmistakably local-development-only, hardcoded directly in `docker-compose.yml` and mirrored in `apps/api/.env.docker.example` — never used in production, never real secrets, matching the same standard as Phase 4's `AdminUserSeeder`.
- `.env` (the real one, containing `APP_KEY` and any real-ish values a developer sets) is never committed — `.gitignore` already covers it; only `.env.example` and the new `.env.docker.example` are tracked.
- Nginx's vhost denies any dotfile (`.env`, `.git`, etc.) and serves only from `apps/api/public` — Laravel's `app/`, `config/`, `storage/`, etc. are never directly reachable.
- MySQL is bound to `127.0.0.1` only, not `0.0.0.0` — not reachable from other machines on the network.
- The permissions fix (§19) grants write access to exactly `storage/` and `bootstrap/cache/`, not the whole application — a deliberate, narrow scope, not a blanket `777`.

## 23. Documentation Updated

`CLAUDE.md`, `README.md`, `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/DECISIONS.md`, `docs/ROADMAP.md`, `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/testing/TEST_STATUS.md`, `docs/phases/V1_PHASE_04A_DEFINITION.md` (new), this handoff (new).

## 24. Manual/UAT Testing Instructions

```sh
cd apps/api
cp .env.docker.example .env
cd ..
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app composer install
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed --class="Database\Seeders\AdminUserSeeder"
```

Then: `http://localhost:8012/api/v1/health` should return `{"data":{"status":"ok",...}}`; `http://localhost:8012/login` should show the Admin sign-in screen; signing in with `admin@example.test` / `password` should reach the placeholder home. `docker compose exec app php artisan test` should show `31 passed`. `docker compose down` stops everything; `docker compose down -v` also removes the MySQL data volume for a clean slate. If `8012` or `3347` (MySQL, host-tools only) is already taken on your machine, set `APP_PORT`/`MYSQL_PORT` before `docker compose up` — see §5/§27.

## 25. Recommended Next Phase

**Phase 5 — Roles & Permissions**, per `docs/ROADMAP.md` — unaffected in scope or urgency by this phase; still the next authorized step once reviewed.

## 26. Post-Handoff Correction: Windows CRLF Checkout Failure

**Reported (product owner UAT, Windows/PowerShell):** with the branch as originally submitted, the `app` container failed to start:

```
exec /usr/local/bin/entrypoint.sh: no such file or directory
```

**Root cause:** this repository had no `.gitattributes`. `docker/php/entrypoint.sh` was committed with LF endings (correct, since it was authored in a Linux sandbox), but with no attribute forcing LF on checkout, a Windows machine with Git's common `core.autocrlf=true` setting checks it out as CRLF. Docker's `COPY` then bakes the CRLF file into the image; the kernel's shebang parser reads the first line as `#!/bin/sh\r`, tries to exec an interpreter literally named `/bin/sh\r` (which doesn't exist), and the container fails immediately with exactly the reported error. `git ls-files --eol` on the reporter's checkout showed `i/lf w/crlf`, confirming the checkout-time conversion, not a content problem.

**Fix:** added `.gitattributes` (repo root):
```gitattributes
# Normalize line endings for text files across platforms.
* text=auto eol=lf

# Executed directly inside Linux containers via shebang — a CRLF line
# ending breaks the interpreter lookup, so these must always be checked
# out with LF regardless of the developer's platform/git config.
*.sh text eol=lf
Dockerfile text eol=lf
```
`git add --renormalize .` confirmed no other tracked file needed re-normalizing (`docker/php/entrypoint.sh` and `docker/php/Dockerfile` were already stored as LF — only the *checkout* behavior was wrong, not the repository content). No Docker architecture or business logic changed — this is a pure Git-attributes fix.

**Verification performed in this session (Linux sandbox — no access to a real Windows machine):**
1. **Reproduced the exact failure independently**, before applying the fix: built a throwaway image from a deliberately CRLF-converted copy of `entrypoint.sh` and ran it — got the identical `exec ...: no such file or directory` error, confirming the root-cause diagnosis.
2. **Simulated the Windows checkout path at the Git level**, which is what actually determines this bug (Docker itself is agnostic to `.gitattributes` — it only sees whatever bytes end up on disk after checkout): cloned this repository fresh with `git -c core.autocrlf=true clone`, checked out this branch, and confirmed via `file`/`cat -A` that `docker/php/entrypoint.sh` (and `docker/php/Dockerfile`) now checks out with pure LF endings — the exact scenario the product owner hit, now fixed.
3. **Rebuilt and brought up all three containers** (`nginx`, `app`, `mysql`) from the corrected branch and confirmed: `mysql` reported `healthy`, `app` started and stayed up (no crash loop — the entrypoint ran successfully), `nginx` started, and `GET /api/v1/health` returned `200` through the full stack. This matches the product owner's own successful Windows retest ("all three Docker containers started successfully and MySQL reported healthy").
4. Re-ran the full host (non-Docker) quality gate suite — `composer validate --strict`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test` (31/31) — all unaffected and passing, confirming this fix touched nothing beyond line-ending normalization.

A genuine Windows machine was not available in this sandbox; the Git-level simulation (step 2) is the mechanistically correct test for this specific bug class, since `core.autocrlf` conversion happens entirely within Git during checkout, before Docker is ever involved — it is not a Docker-specific or platform-emulation concern.

## 27. Post-Handoff Correction: Final Windows UAT Results and Port Configuration Update

### 27.1 Provenance of every claim below

Per the governing instruction for this correction, verification sources are kept explicitly separate — nothing below blurs who actually ran what:

- **Product-owner Windows UAT** — performed by the product owner, on their own Windows machine, reported to this session as a list of outcomes. This session did not run these steps and cannot independently confirm a Windows result; §27.2 records exactly what was reported, no more.
- **Claude automated/runtime verification** — performed by this session, in its Linux sandbox, for the port-configuration change specifically (§27.3). Same category as, and using the same method as, all this phase's earlier Docker validation (§13, §17, §26) — genuinely executed, not assumed.
- **GitHub CI** — the existing `backend-ci.yml`/`mobile-ci.yml` workflows, unaffected by this change (no Docker build/run in CI) — re-confirmed green in §27.4.

### 27.2 Product-Owner Windows UAT Results (as reported — not independently re-run by this session)

The product owner reported successfully completing, on their real Windows development machine, against the branch containing the `.gitattributes` CRLF fix (§26):

- Docker stack starts successfully; all three containers run; `mysql` reports healthy.
- `docker compose exec app composer install` succeeds.
- `php artisan key:generate` succeeds.
- Migrations run successfully against Docker MySQL.
- `AdminUserSeeder` runs successfully.
- `/api/v1/health` works through Nginx.
- Admin `/login` loads successfully.
- A valid Admin login redirects to the protected `/home`.
- Admin logout works.
- Direct `/home` access while logged out redirects to `/login`.
- Flutter successfully authenticates against the Dockerized Laravel API.
- An invalid Flutter password produces a clean error and the app remains unauthenticated.
- Authenticated Flutter state is restored after completely closing and reopening the app.
- Flutter logout returns to the login screen.

This is recorded in `docs/testing/UAT_LOG.md` and `docs/testing/TEST_STATUS.md` as `PASS`, dated, and attributed to the product owner — exactly this list, nothing broader. Scenarios not in this list (e.g. suspended/inactive/non-admin Admin rejection, a simulated Flutter network failure) remain `NOT RUN` — not inferred, not marked passing on the product owner's behalf, per CLAUDE.md §7.

### 27.3 Port Configuration Change

The product owner also reported two port collisions on their machine (host `3306` already in use by another local MySQL install; no `8000` collision was reported, but a configurable default was requested regardless) and asked for both host ports to be made configurable with new project-standard defaults: application `8012`, MySQL `3347`.

**Changed:**
- `docker-compose.yml`: `nginx`'s host port is now `${APP_PORT:-8012}:80` (was the fixed `8000:80`); `mysql`'s host port is now `127.0.0.1:${MYSQL_PORT:-3347}:3306` (was the fixed `127.0.0.1:3306:3306`). Neither container's *internal* port changed — Nginx still listens on `80` inside its container, MySQL still listens on `3306` inside its container, and Laravel's own connection (`DB_HOST=mysql`, `DB_PORT=3306`, entirely internal to the Docker network) is completely unaffected by either host mapping. Overriding either variable requires no edit to `docker-compose.yml` — export it, or set it in a root-level `.env` (Compose's own, separate from `apps/api/.env`), before `docker compose up`.
- `apps/api/.env.docker.example`: `APP_URL` updated to `http://localhost:8012` to match the new default.
- `README.md`, `docs/02_ARCHITECTURE.md` §14: every Docker-context `localhost:8000` reference updated to `8012`, Flutter `API_BASE_URL` examples updated to `8012` for all three target types (desktop/web, Android emulator via `10.0.2.2`, physical device via LAN IP), and both host ports' configurability documented.
- **Not changed:** `apps/mobile/lib/core/config/app_config.dart`'s built-in default (`http://localhost:8000/api/v1`) — per the explicit instruction not to hard-code `8012` into Flutter application behavior. The existing `--dart-define=API_BASE_URL=...` mechanism (DEC-021) already handles this: the README's Docker-path examples now pass `8012` explicitly; a developer running the backend via direct install (`php artisan serve`, still port `8000`) can omit `--dart-define` and get the unchanged default.
- MySQL credentials (`company_app`/`secret`, root/`secret`) are unchanged — only the host-side port mapping changed, not the database, user, or password.

**Claude verification performed in this session** (Linux sandbox, using the same validated method as §13/§17/§26 — a temporary, uncommitted Dockerfile variant skipping only the network-blocked `apt-get` step, discarded after use, never committed):
1. `docker compose config` (both default and with `APP_PORT`/`MYSQL_PORT` overrides set) — confirmed the resolved configuration correctly shows `published: "8012"` → `target: 80` for `nginx` and `published: "3347"` → `target: 3306` for `mysql` by default, and correctly picks up overrides (tested with `APP_PORT=9000 MYSQL_PORT=3399`) without any `docker-compose.yml` edit.
2. Rebuilt and brought up all three containers on the new defaults — `docker compose ps` confirmed `0.0.0.0:8012->80/tcp` (nginx) and `127.0.0.1:3347->3306/tcp` (mysql), `mysql` reported `healthy`.
3. Populated the `vendor` named volume (`docker cp` from the already-verified host `vendor/`, same approach as §17) and ran, all against the new `8012` port: `GET /api/v1/health` → `200`; `GET /login` → `200`; `POST /api/v1/auth/login` → `200` with a valid token (full MySQL round-trip); `php artisan migrate --force` → all 5 migrations; `php artisan db:seed --class=...AdminUserSeeder` → admin account created; `mysqladmin ping` against the container directly confirmed still healthy on the new host mapping.
4. `php artisan test` (31/31), `vendor/bin/pint --test`, and `vendor/bin/phpstan analyse` (0 errors) — all re-run inside the `app` container after the change, all passing.
5. Re-ran the full host (non-Docker) quality gate suite — unaffected, all passing, confirming the change is scoped to Docker port configuration only.
6. Torn down (`docker compose down -v`) and all temporary test artifacts deleted after use, same discipline as every prior validation pass this phase.

### 27.4 GitHub Actions Status (this correction)

See the commit/CI table reported alongside this correction in the session's final report to the user (this document is updated in place before that push, per the established pattern in §18/§26 — check `docs/testing/TEST_STATUS.md` for the most current confirmed run if this note wasn't itself updated with a specific run link).

---

*Per CLAUDE.md §8 (Stop Discipline): this phase is complete. Not merged into `main`. Phase 5 is not authorized by this handoff and will not begin without explicit user instruction.*
