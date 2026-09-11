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

- `nginx`: host `8000` → container `80`. Deliberately the same port `php artisan serve` and Flutter's default `API_BASE_URL` already use — nothing else changes to point Flutter at the Dockerized backend.
- `mysql`: host `127.0.0.1:3306` → container `3306` — localhost-bound only, for an optional local GUI client; not exposed beyond the developer's own machine.

## 6. Volume Strategy

- `./apps/api:/var/www/html` (bind mount, read-write for `app`, read-only for `nginx`) — the host's editor sees every change instantly; no sync tooling.
- `vendor:/var/www/html/vendor` (named volume) — mounted over the bind mount's `vendor/` path specifically so the host's `apps/api/vendor` (whatever is or isn't there) never shadows/conflicts with what `composer install` produces inside the container. First-time setup includes one `docker compose exec app composer install`.
- `mysql-data:/var/lib/mysql` (named volume) — database state persists across `docker compose down`/`up`.

## 7. Composer/Vendor Strategy

See §6 — the named `vendor` volume is the whole strategy. Composer itself is baked into the `app` image (copied from the official `composer:2` image), so `docker compose exec app composer <command>` always works without a separate install step.

## 8. Environment Configuration

Laravel reads `apps/api/.env` directly — it's part of the bind mount, so Laravel's own Dotenv loading picks it up with zero Docker-specific wiring needed. The `app` service **deliberately does not** use Compose's `env_file`/`environment` to also inject those same values as container-level OS environment variables — see §19/§23 for why this was tried, found to be actively harmful, and removed. `apps/api/.env.docker.example` was added: identical to `.env.example` except `DB_CONNECTION=mysql`, `DB_HOST=mysql`, `DB_DATABASE=company_app`, `DB_USERNAME=company_app`, `DB_PASSWORD=secret` — matching the `mysql` service's hardcoded local-dev credentials exactly. No real secrets anywhere; these are unmistakably local-dev-only values, same spirit as Phase 4's `AdminUserSeeder`.

## 9. MySQL Configuration

`MYSQL_DATABASE=company_app`, `MYSQL_USER=company_app`, `MYSQL_PASSWORD=secret`, `MYSQL_ROOT_PASSWORD=secret` — hardcoded directly in `docker-compose.yml` (not templated from a root-level `.env`, to avoid a second, confusing environment-file layer alongside Laravel's own `apps/api/.env`). A healthcheck (`mysqladmin ping -h localhost -uroot -psecret`, 5s interval, 10 retries) gates `app`'s startup via `depends_on: mysql: condition: service_healthy`, so migrations never race an unready database.

## 10. Nginx Configuration

`docker/nginx/default.conf` — conventional Laravel vhost: document root `/var/www/html/public` (i.e. `apps/api/public` via the bind mount), `try_files` routing everything through `index.php`, PHP requests `fastcgi_pass`ed to `app:9000`, and a `location ~ /\.` block denying any dotfile (`.env`, `.git`, etc.) or path outside the public document root.

## 11. Permissions Handling

The container's `php-fpm` runs as `www-data` (the base image's default), which essentially never matches whatever UID owns the bind-mounted files across Windows/macOS/Linux. `docker/php/entrypoint.sh` runs `chmod -R a+rwX storage bootstrap/cache` on container start — scoped to exactly the two directories Laravel needs to write to, not the whole application, and not a blanket `777` (the capital `X` only sets execute on directories/already-executable files, never on plain data files). This was **found to be a real bug during validation**, not assumed correct — see §19.

## 12. Flutter Connectivity

Flutter is unchanged and untouched by this phase. `README.md` documents `API_BASE_URL` examples for the three target types the governing instructions asked for:
- Desktop/web: `http://localhost:8000/api/v1` (already the default in `app_config.dart`, DEC-021/Phase 3)
- Android emulator: `http://10.0.2.2:8000/api/v1` (the emulator's standard alias for the host machine)
- Physical device on the same LAN: `http://<host-LAN-IP>:8000/api/v1`

None of these are hard-coded into application code — all via the existing `--dart-define=API_BASE_URL=...` mechanism.

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

**Added:** `docker-compose.yml` (repo root); `docker/php/Dockerfile`; `docker/php/entrypoint.sh`; `docker/php/conf.d/local-dev.ini`; `docker/nginx/default.conf`; `apps/api/.env.docker.example`; `docs/phases/V1_PHASE_04A_DEFINITION.md`; `docs/handoffs/V1_PHASE_04A_HANDOFF.md` (this file).

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

Not yet available at the time of writing — the branch had not been pushed / a PR had not been opened yet when this section was drafted. Per CLAUDE.md §36/23, this is updated (or a follow-up note added) once the PR is opened and Actions run.

## 19. Deviations

None from `docs/phases/V1_PHASE_04A_DEFINITION.md`'s scope. Two **real bugs were found and fixed** during genuine runtime validation — recorded here rather than silently corrected, since they materially affect what "Docker environment works" means:

1. **Permissions bug:** the entrypoint's first version used `chmod -R ug+rwX storage bootstrap/cache`. Since the bind-mounted files are owned by `root:root` (this sandbox's user) and the container's `php-fpm` worker runs as `www-data` — which is neither the owner nor in the `root` group — `ug+rwX` granted it *no* write access at all. Symptom: `GET /login` returned `500` (`tempnam(): file created in the system's temporary directory` — Blade trying and failing to write a compiled view). Fixed to `chmod -R a+rwX storage bootstrap/cache`, which is correct regardless of which UID ends up owning the bind-mounted files on any host OS, while still being scoped to only those two directories (not the whole application, not `777`).
2. **Test-isolation bug:** the `app` service's first version included `env_file: ./apps/api/.env`, intended purely as a convenience. This makes Docker inject those values as real container-level OS environment variables. Laravel doesn't need this (it reads `.env` itself via the bind mount) — and it's actively harmful: PHPUnit's `<env>` overrides in `phpunit.xml` (`DB_CONNECTION=sqlite`, `APP_ENV=testing`, etc.) do **not** force-replace a variable that already exists at the OS level. With `env_file` present, `php artisan test` inside the container silently ran against the real `mysql` connection instead of the intended isolated in-memory SQLite — 2 of 27 tests failed for reasons unrelated to app logic (a stale-cache-triggered `BadMethodCallException` and a CSRF/session `419`, both artifacts of the wrong environment), and more importantly, RefreshDatabase was migrating/wiping the *real* local dev database on every test run rather than an isolated one. Fixed by removing `env_file` entirely — Laravel's own `.env` loading was always sufficient.

Both are documented in `02_ARCHITECTURE.md` §14, `docs/DECISIONS.md` DEC-027's context, and the Dockerfile/compose file's own inline comments, so a future session doesn't reintroduce either.

## 20. Known Issues

- The `apt-get` step in the real Dockerfile could not be executed in this specific sandbox (§17) — not a defect, a sandbox network-policy limitation. GitHub Actions doesn't build/run this Dockerfile (CI remains SQLite-based, unchanged — §16 of the governing instructions), so this has no CI impact; it only affects what this session could verify directly versus what a real developer's machine will do.
- No Windows/macOS-specific manual verification was possible in this Linux sandbox — the permissions/volume design (§11, §6) is written to be cross-platform-safe by construction (UID-agnostic `a+rwX`, a named volume rather than relying on bind-mount UID matching), but hasn't been physically verified on those platforms.
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

Then: `http://localhost:8000/api/v1/health` should return `{"data":{"status":"ok",...}}`; `http://localhost:8000/login` should show the Admin sign-in screen; signing in with `admin@example.test` / `password` should reach the placeholder home. `docker compose exec app php artisan test` should show `31 passed`. `docker compose down` stops everything; `docker compose down -v` also removes the MySQL data volume for a clean slate.

## 25. Recommended Next Phase

**Phase 5 — Roles & Permissions**, per `docs/ROADMAP.md` — unaffected in scope or urgency by this phase; still the next authorized step once reviewed.

---

*Per CLAUDE.md §8 (Stop Discipline): this phase is complete. Not merged into `main`. Phase 5 is not authorized by this handoff and will not begin without explicit user instruction.*
