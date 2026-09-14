# Phase 24 — Staging Deployment — Handoff

## 1. Phase Identification

**Phase:** 24 — Staging Deployment
**Date:** 2026-09-14
**Type:** Three-step. (1) A discovery/planning session (`docs/phases/V1_PHASE_24_STAGING_DEPLOYMENT_PLAN.md`, no code changes) followed, in the same session after product-owner VPS discovery and explicit authorization, by a narrow repository-side implementation. (2) A product-owner review requesting two targeted corrections (Nginx design verification, `APP_KEY` workflow safety) plus real Docker build verification where possible. (3) **The resulting PR (#28) was merged into `main` (`eb227826aea776a303e05126743fa9c056ea2852`) and the product owner executed the deployment runbook against the real staging VPS — server-side execution is now done and verified.** This handoff (updated after step 3) covers all three; see `docs/phases/V1_PHASE_24_STAGING_DEPLOYMENT_PLAN.md` Addendum 3 for the full real-deployment account. This documentation-closure update itself changed no application code, migration, Docker asset, or environment template.

## 2. Objective

Per `docs/ROADMAP.md`, Phase 24 is Company App's "first real deployment." The authorized scope (following VPS discovery) was to implement the smallest maintainable, production-like staging deployment for the existing Laravel 13/PHP 8.4 + MySQL 8.4 application on a real, personal, multi-project VPS — without introducing infrastructure the application doesn't already use (no Redis/queue/scheduler/WebSockets), without a shared reverse-proxy/platform layer, and without inventing TLS/domain decisions that belong to the product owner.

## 3. Scope Implemented

- A dedicated staging Docker Compose file and two staging-specific Dockerfiles (application + Nginx), replacing the previously-discovered ad-hoc, bind-mounted deployment already running on the VPS.
- A host-port reservation policy for this shared VPS (Company App: HTTP 8012, HTTPS 8442 reserved).
- A secrets-never-committed environment strategy: two placeholder-only `.example` templates, with the real files created by the VPS operator.
- A full deployment runbook covering first deployment, migrating the pre-existing ad-hoc deployment (preserving its MySQL data), migrations/seeding, verification, logs, restart/stop/start, upgrade/redeploy, rollback, and mobile staging configuration.
- Documentation updates across `docs/phases/`, `docs/handoffs/` (this file), `docs/DECISIONS.md` (DEC-047), `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/ROADMAP.md`, `docs/02_ARCHITECTURE.md` (new §31), and `docs/05_SECURITY_MODEL.md`.

**Not implemented** (explicitly excluded by the authorization): any server access or change, TLS/domain provisioning, any UFW firewall change, a DB-aware `/api/v1/health`, Redis/queue/scheduler/WebSocket infrastructure, a shared reverse proxy across VPS projects, Kubernetes, an automated CD workflow, mobile UI work, and any application feature change.

## 4. Implementation Summary

**Docker images (`docker/php/Dockerfile.staging`, `docker/nginx/Dockerfile.staging`):** the application image bakes source and a `--no-dev`/`--optimize-autoloader` Composer install into the image, in an order chosen to keep the Composer-dependency layer cacheable and the autoloader classmap correct (`composer install --no-autoloader` before application source is copied in, then `composer dump-autoload --optimize` once it is — building the classmap before `app/` exists would have baked in an incomplete one). Writable directories (`storage/`, `bootstrap/cache/`) are `chown`'d to `www-data` at build time — a narrow, deterministic alternative to the local-dev image's broad runtime `chmod`, which exists only to cope with bind-mounted files of unknown ownership this image never has.

**Nginx image — revised after product-owner review.** The original design shipped an intentionally empty `public/` directory, justified only by `@vite` appearing solely in the unused default Laravel `welcome.blade.php`. Review correctly identified that this wasn't sufficient proof: `docker/nginx/default.conf` was read line-by-line, confirming two genuinely different code paths. Every `.php`-routed request (100% of real traffic) is matched by `location ~ \.php$`, which never consults `try_files`/the filesystem — `SCRIPT_FILENAME` is a plain string built from the `root` directive and handed to PHP-FPM over `fastcgi_pass app:9000`, resolved entirely on the **`app` container's own filesystem** (nginx never needs `index.php` for this). But `location = /favicon.ico`/`= /robots.txt` bypass `try_files` entirely and read straight from nginx's own `root` — an empty `public/` made both genuinely 404, a real (if minor) gap versus local dev. **Fixed:** the image now `COPY apps/api/public/`s actual four small static files (`.htaccess`, `favicon.ico`, `index.php`, `robots.txt`) in at build time — a plain, immutable `COPY`, not a bind mount, and not meaningfully "source code" (nginx never executes `index.php`). See `docs/phases/V1_PHASE_24_STAGING_DEPLOYMENT_PLAN.md` Addendum 2 for the full reasoning.

**Compose file (`docker-compose.staging.yml`):** three services on a dedicated `company-app-staging` bridge network. `mysql` receives its credentials entirely via `${DB_*}` Compose-substitution from an uncommitted root `.env.staging`, publishes no host port by default (commented-out, loopback-only option preserved for a justified future need), and its healthcheck authenticates via the container's own `$MYSQL_ROOT_PASSWORD` env var rather than a literal password. `app` receives its full runtime config via a single-file, read-only bind mount of `apps/api/.env` (a configuration file, not source code — chosen over Compose `env_file:`/`environment:` injection specifically so Laravel's own `.env`-reading behavior is identical to every other environment, with no Dotenv-vs-preset-env-var subtlety to reason about) and gets a new `app-storage` named volume for `storage/app/private`, so Service/Incident Report attachments survive redeploys despite the image now baking an empty `storage/app/private` into every build. `nginx` publishes only `8012:80`; `8442` is documented as reserved but deliberately unmapped.

**Volume-name continuity:** `mysql-data` declares no explicit `name:`, so invoking Compose with `-p company-app` (documented in the Compose file's own header and throughout the runbook) resolves it to `company-app_mysql-data` — verified in this session, via `docker compose -p company-app -f docker-compose.staging.yml config`, to match the exact volume name the pre-existing ad-hoc deployment's own Compose project (run from a directory named `company-app`) already created. This is how the new stack reuses rather than replaces that data, with no manual export/import step.

**Environment templates:** `.env.staging.example` (root) covers only the four DB credential values Compose substitution needs. `apps/api/.env.staging.example` covers Laravel's full runtime config, differing from local dev's `.env.example` specifically where staging must (`APP_ENV=staging`, `APP_DEBUG=false`, `LOG_LEVEL=info`, unique DB credentials, `APP_URL`/mail placeholders) while leaving everything with no demonstrated need to change untouched (`CACHE_STORE`/`QUEUE_CONNECTION`/`SESSION_DRIVER` all remain `database`, Sanctum's 30-day expiration is left as the already-decided default). `SESSION_SECURE_COOKIE` is deliberately `false` with an explanatory comment — flipping it before TLS exists would silently break Admin Backoffice login.

**Runbook (`docs/DEPLOYMENT_STAGING.md`):** every command is labeled `[Repository — already done]` or `[Run on the VPS]`. Section 10 is the ad-hoc-deployment migration: back up the database first (`mysqldump`, using the ad-hoc deployment's own already-documented dev-only root password one last time), identify and verify the existing `company-app_mysql-data` volume, stop the ad-hoc deployment without `-v`, check for (and optionally migrate) any real files in the ad-hoc deployment's bind-mounted attachment storage, then bring up the new stack and verify row counts survived. Section 6 explains why `AdminUserSeeder` must never run against staging and gives a Tinker-based alternative for creating the first real Administrator account.

**`APP_KEY` generation — revised after product-owner review.** Section 4 already used `php artisan key:generate --show` (which returns before attempting any file write), so the command itself was already safe against `apps/api/.env`'s read-only bind mount. Its explanatory text, however, incorrectly claimed "writing directly would also work" — false, since an ordinary `key:generate` would fail outright against a `:ro` mount. **Fixed:** the section now states this plainly and gives the exact safe sequence — generate via `--show`, edit the value directly into the real host-side `.env` (e.g. via `sed`), then (re)start the application — with an explicit warning never to commit or print the generated value. Section 5 also gained an immediate post-`up` smoke test (`curl` the health endpoint through nginx, with a logs-to-check list for each service if it fails), so the first server-side step genuinely exercises build → startup → status → a real HTTP request → logs-on-failure, per the review's request.

## 5. Files Changed

**Added:**
- `docker/php/Dockerfile.staging`
- `docker/nginx/Dockerfile.staging`
- `docker-compose.staging.yml`
- `.dockerignore` (repository root)
- `.env.staging.example` (repository root)
- `apps/api/.env.staging.example`
- `docs/DEPLOYMENT_STAGING.md`
- `docs/handoffs/V1_PHASE_24_HANDOFF.md` (this file)

**Modified:**
- `docs/phases/V1_PHASE_24_STAGING_DEPLOYMENT_PLAN.md` (status line + a new addendum recording confirmed VPS facts and this implementation; original body preserved unchanged)
- `docs/DECISIONS.md` (new DEC-047)
- `docs/CURRENT_STATE.md` (current phase, Completed list, Pending list, Repository/Branch Information, Latest Relevant Handoff, For the Next Session)
- `docs/CHANGELOG.md` (new entry)
- `docs/ROADMAP.md` (Phase 24 line annotated)
- `docs/02_ARCHITECTURE.md` (Status line; new §31 — Staging Deployment)
- `docs/05_SECURITY_MODEL.md` (Status line; Production Environment Separation section)

**Not touched:** any file under `apps/api/app|config|routes|database|tests`, `apps/api/composer.json`, `docker-compose.yml` (Phase 4A local dev, unchanged), `docker/php/Dockerfile`/`entrypoint.sh` (unchanged), `docker/nginx/default.conf` (unchanged, only reused), and anything under `apps/mobile`.

## 6. Database/Schema Changes

None. No migration was added or modified. The 45 existing migrations are what the runbook's `php artisan migrate --force` step applies to a fresh staging database (or, per §10 of the runbook, to the already-migrated database inherited from the ad-hoc deployment, in which case that command is a no-op confirming nothing is pending).

## 7. API Changes

None. No route, controller, resource, or permission was added, changed, or removed.

## 8. Authorization/Security Changes

No application-level authorization change. Security-relevant repository changes are entirely deployment-configuration: `APP_DEBUG=false` enforced in the staging template, unique/generated database credentials required (never the dev Compose file's shared `secret` value), MySQL never bound to a public interface (Docker-network-only by default, loopback-only if the commented-out host mapping is ever re-enabled), no secret committed anywhere, `SESSION_SECURE_COOKIE` deliberately held at `false` until TLS exists (documented, not an oversight), and an explicit runbook instruction never to run the dev-only `AdminUserSeeder` against a reachable host.

## 9. Tests Added or Changed

None — no PHP application code was touched, so no PHPUnit test was relevant to add or change.

## 10. Commands/Checks Executed

First round:
- `git status`, `git fetch origin main`, `git merge-base --is-ancestor`, `git log` — confirmed branch state at the start of the planning session.
- `docker compose -p company-app -f docker-compose.staging.yml --env-file <temporary, uncommitted placeholder> config` — validated the staging Compose file's syntax and confirmed the `mysql-data` volume resolves to `company-app_mysql-data`.
- `grep -rln "@vite" apps/api/resources/views` — confirmed no route this application actually serves depends on a compiled front-end asset.
- `git ls-files apps/api/storage apps/api/bootstrap/cache` — confirmed Laravel's writable-directory placeholders are tracked `.gitignore` files (not `.gitkeep`), informing the `.dockerignore` design.

Second round (product-owner review, real build verification):
- Read `docker/nginx/default.conf` line-by-line to answer, from the config itself, whether nginx needs `public/index.php`/other files present on its own filesystem (see §4 above) — not re-relying on the `@vite` grep alone.
- `service docker start` (failed — a `ulimit` call in the init script errors with "Operation not permitted") then `nohup dockerd &` (succeeded — a fully functional daemon, confirmed persisting across separate tool invocations via `docker info`).
- Pulled `nginx:1.27-alpine`, `php:8.4-fpm`, `composer:2`, `mysql:8.4` via `mirror.gcr.io` (the same working Docker Hub mirror `docs/handoffs/V1_PHASE_04A_HANDOFF.md` already documented) and locally re-tagged them to their expected names.
- `docker build -f docker/nginx/Dockerfile.staging -t company-app-nginx:staging-verify .` and `docker compose -p company-app -f docker-compose.staging.yml build nginx` — both succeeded.
- `docker run --rm company-app-nginx:staging-verify ls -la /var/www/html/public` — confirmed the four real static files are present inside the built image.
- `docker run --rm company-app-nginx:staging-verify nginx -t` — failed with `host not found in upstream "app"` (expected: an isolated single-container run has no `app` service on its network to resolve; not a defect).
- `docker build -f docker/php/Dockerfile.staging -t company-app-api:staging-verify .` — failed at the `apt-get update` layer (`403 Forbidden` reaching `deb.debian.org`), reproducing `docs/handoffs/V1_PHASE_04A_HANDOFF.md`'s own documented finding.
- A temporary, uncommitted Dockerfile variant (outside the repository, `/tmp`) skipping only that `apt-get` line, purely to test whether the *rest* of the build logic was sound — failed at `composer install` for a different, unrelated reason (missing `unzip`/`git`, themselves only installable via the same blocked `apt-get`).
- `docker run --rm -v "$(pwd)/apps/api":/app -w /app composer:2 composer install ...` (the official Composer image, which bundles git/unzip, run directly against the real `apps/api` directory) — failed: HTTPS requests to `api.github.com`/`repo.packagist.org` fail TLS verification inside any container in this sandbox (self-signed certificate in the chain), and Composer's `--prefer-source` git fallback then hung indefinitely; `docker exec <container> ps aux` showed it stuck on `git clone --mirror -- git@github.com:doctrine/lexer.git` via SSH. The container was killed and the partial, uncommitted `apps/api/vendor` directory removed.
- `docker compose -p company-app -f docker-compose.staging.yml --env-file <temporary, uncommitted placeholder> config` — re-run after both fixes; still succeeds with the same correct volume-name resolution.

## 11. Results

- The static Compose validation succeeded both before and after this round's fixes, with the expected, correct output (`mysql-data: name: company-app_mysql-data`).
- **The staging Nginx image is now genuinely build-verified** — it builds successfully (standalone and via the real Compose service), and its `public/` contents were confirmed present by direct inspection of the built image.
- **The staging application image could not be built to completion in this sandbox** — blocked by this sandbox's pre-existing, documented network policy (`apt-get` to `deb.debian.org`, and, separately, Composer's dependency downloads via both dist-zip and git/SSH fallback) at every path attempted. This is a sandbox limitation, not a defect found in the Dockerfile's own command sequence, which was otherwise reviewed by hand and is unchanged by this round.
- No PHP quality gate (`composer validate --strict`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test`) was run, because no file those gates cover was changed.

## 12. Deviations from Specification

- The original planning document (§6, item 5) flagged a DB-aware `/api/v1/health` as an optional, separately-authorizable enhancement. The Phase 24 authorization explicitly excluded it — implemented as excluded, not as a deviation from what was asked.
- The planning document did not anticipate the exact single-file-bind-mount-for-`.env` mechanism ultimately chosen for the `app` container (it discussed the need for "secrets supplied through runtime environment" without prescribing the exact mechanism) — this was a deliberate implementation choice, reasoned through in this session: a single configuration-file bind mount was judged clearer and more Laravel-idiomatic than Compose `env_file:`/`environment:` injection, and avoids any ambiguity around Laravel's Dotenv behavior toward already-set environment variables. It is not source-code bind-mounting (the authorization's actual constraint) and does not compromise the "never commit secrets" requirement (the file itself is never committed).
- The original Nginx image design (empty `public/`) and the original `APP_KEY` explanatory text (§4) were both found, on review, to need correction — see §4 above and `docs/phases/V1_PHASE_24_STAGING_DEPLOYMENT_PLAN.md` Addendum 2 for the full account. Neither was a change to the *authorized scope* — both are corrections to implementation details within it.

## 13. Known Issues/Limitations

**Resolved by the real VPS deployment (2026-09-14) — see `docs/phases/V1_PHASE_24_STAGING_DEPLOYMENT_PLAN.md` Addendum 3 for full detail:**
- ~~Server-side execution has not happened~~ — **done and verified.** The formal staging stack (dedicated images, not the ad-hoc bind-mounted deployment) is running on the real VPS; `company-app_mysql-data` was preserved (pre-cutover backup taken, no volume deleted, no `down -v`); all 45 migrations already `Ran`; `php artisan about` confirms Laravel 13.31.0/PHP 8.4.25/`environment: staging`/`debug: OFF`; health/`GET /` both return `200`; the existing Administrator account logs in successfully.
- ~~The staging application image remains build-untested~~ — **it built and runs successfully on the real VPS.** This confirms the sandbox's `apt-get`/Composer failure (§10/§11 below) was an environment-specific limitation of the sandbox this repository was developed in, not a defect in `docker/php/Dockerfile.staging`.

**Still open, explicitly not claimed complete:**
- `GET /api/v1/health` remains DB-unaware, per explicit instruction — a real, if narrow, monitoring gap until a future phase addresses it.
- **No TLS, no public (UFW) exposure of port 8012/8442, and no mobile-client verification.** Every real smoke test was run from `127.0.0.1` on the VPS itself — staging is not yet reachable from outside the VPS, and no Flutter build has been run against it. This is unchanged from the original plan's own sequencing (§13 of `docs/DEPLOYMENT_STAGING.md`), not a new gap.
- `public/storage` is not symlinked on the staging deployment. Per explicit instruction this is recorded as an observation only, not silently changed — it has no functional effect on anything Phase 24 built (attachments use the private `attachments` disk exclusively, never `public`/`public/storage`).
- Existing database credentials and the existing `APP_KEY` were **preserved**, not rotated, during the cutover (a deliberate, reasonable choice for migrating a live system — see Addendum 3's "Deviations from the runbook's illustrative example values"); their strength was not independently re-audited by this documentation session.
- If the ad-hoc deployment ever received real Service/Incident Report attachment uploads, those files would have lived on the VPS host filesystem, not in a Docker volume — not separately confirmed one way or the other by the reported deployment facts, though the original VPS discovery (Addendum 1) suggested this deployment was health-check-only.

## 14. Manual/UAT Testing Instructions

**Executed and verified on the real VPS (2026-09-14):** health endpoint and `GET /` both `200`; `php artisan migrate:status` confirms all 45 migrations `Ran`; `php artisan about` confirms `debug: OFF` and every staging-specific config value; existing Administrator login tested manually and works. See `docs/testing/UAT_LOG.md` `UAT-24-01` through `UAT-24-03` (recorded `PASS`, reported directly by the product owner) and `docs/phases/V1_PHASE_24_STAGING_DEPLOYMENT_PLAN.md` Addendum 3 for the full account.

**Not yet executed** (see `docs/testing/UAT_LOG.md` `UAT-24-04`, `NOT RUN`): a deliberately-invalid-route 404 check specifically (debug-off was confirmed via `php artisan about` instead, which is the more direct signal), attachment upload/download round-trip verification, and — requiring public reachability first — mobile-client (`--dart-define=API_BASE_URL=...`) verification.

## 15. Documentation Updated

`docs/phases/V1_PHASE_24_STAGING_DEPLOYMENT_PLAN.md`, `docs/DECISIONS.md` (DEC-047), `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/ROADMAP.md`, `docs/02_ARCHITECTURE.md`, `docs/05_SECURITY_MODEL.md`, `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`, and this handoff. `docs/DEPLOYMENT_STAGING.md` is dedicated deployment-runbook documentation, referenced from `docs/CURRENT_STATE.md` and the planning document rather than duplicated into either; it was not modified in this documentation-closure round (nothing in it proved incorrect).

## 16. Recommended Next Step

Server-side execution is now done and verified — Phase 24's core objective (a working, production-like staging deployment) is achieved. What remains before Phase 24 could be called *fully* closed (TLS/domain decision, public UFW exposure, mobile-client verification) is a separate, explicitly deferred set of decisions, not a blocker to reporting the deployment itself as successful. Recommended next steps, in order: (1) the product owner decides a staging domain and TLS approach (a new decision, not assumed here); (2) UFW is opened for 8012 (and later 8442) once that decision is made; (3) mobile-client reachability is verified against the now-public endpoint; (4) only then does Phase 25 (UAT) become meaningful to authorize — this document does not assume or request that authorization.
