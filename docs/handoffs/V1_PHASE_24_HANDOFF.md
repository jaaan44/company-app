# Phase 24 — Staging Deployment — Handoff

## 1. Phase Identification

**Phase:** 24 — Staging Deployment
**Date:** 2026-09-14
**Type:** Two-step, matching Phase 22's precedent — a discovery/planning session (`docs/phases/V1_PHASE_24_STAGING_DEPLOYMENT_PLAN.md`, no code changes) followed, in the same session after product-owner VPS discovery and explicit authorization, by a narrow repository-side implementation. **Server-side execution has not happened** — this handoff covers the repository implementation only.

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

**Docker images (`docker/php/Dockerfile.staging`, `docker/nginx/Dockerfile.staging`):** the application image bakes source and a `--no-dev`/`--optimize-autoloader` Composer install into the image, in an order chosen to keep the Composer-dependency layer cacheable and the autoloader classmap correct (`composer install --no-autoloader` before application source is copied in, then `composer dump-autoload --optimize` once it is — building the classmap before `app/` exists would have baked in an incomplete one). Writable directories (`storage/`, `bootstrap/cache/`) are `chown`'d to `www-data` at build time — a narrow, deterministic alternative to the local-dev image's broad runtime `chmod`, which exists only to cope with bind-mounted files of unknown ownership this image never has. The Nginx image reuses `docker/nginx/default.conf` byte-for-byte and ships an intentionally empty `public/` directory, verified safe because `@vite` appears only in the unused default Laravel `welcome.blade.php` (confirmed via `grep -rln "@vite" apps/api/resources/views`) — no real request path in this application depends on nginx serving a static asset directly.

**Compose file (`docker-compose.staging.yml`):** three services on a dedicated `company-app-staging` bridge network. `mysql` receives its credentials entirely via `${DB_*}` Compose-substitution from an uncommitted root `.env.staging`, publishes no host port by default (commented-out, loopback-only option preserved for a justified future need), and its healthcheck authenticates via the container's own `$MYSQL_ROOT_PASSWORD` env var rather than a literal password. `app` receives its full runtime config via a single-file, read-only bind mount of `apps/api/.env` (a configuration file, not source code — chosen over Compose `env_file:`/`environment:` injection specifically so Laravel's own `.env`-reading behavior is identical to every other environment, with no Dotenv-vs-preset-env-var subtlety to reason about) and gets a new `app-storage` named volume for `storage/app/private`, so Service/Incident Report attachments survive redeploys despite the image now baking an empty `storage/app/private` into every build. `nginx` publishes only `8012:80`; `8442` is documented as reserved but deliberately unmapped.

**Volume-name continuity:** `mysql-data` declares no explicit `name:`, so invoking Compose with `-p company-app` (documented in the Compose file's own header and throughout the runbook) resolves it to `company-app_mysql-data` — verified in this session, via `docker compose -p company-app -f docker-compose.staging.yml config`, to match the exact volume name the pre-existing ad-hoc deployment's own Compose project (run from a directory named `company-app`) already created. This is how the new stack reuses rather than replaces that data, with no manual export/import step.

**Environment templates:** `.env.staging.example` (root) covers only the four DB credential values Compose substitution needs. `apps/api/.env.staging.example` covers Laravel's full runtime config, differing from local dev's `.env.example` specifically where staging must (`APP_ENV=staging`, `APP_DEBUG=false`, `LOG_LEVEL=info`, unique DB credentials, `APP_URL`/mail placeholders) while leaving everything with no demonstrated need to change untouched (`CACHE_STORE`/`QUEUE_CONNECTION`/`SESSION_DRIVER` all remain `database`, Sanctum's 30-day expiration is left as the already-decided default). `SESSION_SECURE_COOKIE` is deliberately `false` with an explanatory comment — flipping it before TLS exists would silently break Admin Backoffice login.

**Runbook (`docs/DEPLOYMENT_STAGING.md`):** every command is labeled `[Repository — already done]` or `[Run on the VPS]`. Section 10 is the ad-hoc-deployment migration: back up the database first (`mysqldump`, using the ad-hoc deployment's own already-documented dev-only root password one last time), identify and verify the existing `company-app_mysql-data` volume, stop the ad-hoc deployment without `-v`, check for (and optionally migrate) any real files in the ad-hoc deployment's bind-mounted attachment storage, then bring up the new stack and verify row counts survived. Section 6 explains why `AdminUserSeeder` must never run against staging and gives a Tinker-based alternative for creating the first real Administrator account.

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

- `git status`, `git fetch origin main`, `git merge-base --is-ancestor`, `git log` — confirmed branch state at the start of the planning session (documented in the planning report).
- `docker compose -p company-app -f docker-compose.staging.yml --env-file <temporary, uncommitted placeholder> config` — validated the staging Compose file's syntax and confirmed the `mysql-data` volume resolves to `company-app_mysql-data`.
- `grep -rln "@vite" apps/api/resources/views` — confirmed no route this application actually serves depends on a compiled front-end asset, justifying the Nginx image's empty `public/` directory.
- `git ls-files apps/api/storage apps/api/bootstrap/cache` — confirmed Laravel's writable-directory placeholders are tracked `.gitignore` files (not `.gitkeep`), informing the `.dockerignore` design (no wildcard exclusion of `storage/**` contents, to avoid Docker dropping an emptied-out directory from the build context).
- `docker --version` / `docker compose version` / `docker build ...` — confirmed the CLI is present in this sandbox but no Docker daemon is reachable (`/var/run/docker.sock` does not exist here); actual image builds were therefore **not** run.

## 11. Results

- The static Compose validation succeeded with the expected, correct output (see the planning document's addendum for the literal resolved YAML excerpt confirming volume naming).
- The `@vite` grep returned exactly one match (`resources/views/welcome.blade.php`), confirming the Nginx image design is safe.
- No PHP quality gate (`composer validate --strict`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test`) was run, because no file those gates cover was changed.
- No Dockerfile was actually built in this session (no daemon available) — reviewed by hand instead (see §12/§13).

## 12. Deviations from Specification

- The original planning document (§6, item 5) flagged a DB-aware `/api/v1/health` as an optional, separately-authorizable enhancement. The Phase 24 authorization explicitly excluded it — implemented as excluded, not as a deviation from what was asked.
- The planning document did not anticipate the exact single-file-bind-mount-for-`.env` mechanism ultimately chosen for the `app` container (it discussed the need for "secrets supplied through runtime environment" without prescribing the exact mechanism) — this was a deliberate implementation choice, reasoned through in this session: a single configuration-file bind mount was judged clearer and more Laravel-idiomatic than Compose `env_file:`/`environment:` injection, and avoids any ambiguity around Laravel's Dotenv behavior toward already-set environment variables. It is not source-code bind-mounting (the authorization's actual constraint) and does not compromise the "never commit secrets" requirement (the file itself is never committed).

## 13. Known Issues/Limitations

- **Server-side execution has not happened.** Nothing in this handoff should be read as confirming the staging stack runs, that migrations have been applied against the real staging database, or that the ad-hoc deployment has actually been migrated. `docs/DEPLOYMENT_STAGING.md` is a checklist, not a completion record.
- **Docker builds were not verified in this session** (no daemon available in this sandbox) — the two staging Dockerfiles were reviewed by hand for correctness (layer ordering, autoloader-generation timing) but not build-tested. The VPS operator's first `docker compose ... build` is this session's first real test of them.
- `GET /api/v1/health` remains DB-unaware, per explicit instruction — a real, if narrow, monitoring gap until a future phase addresses it.
- TLS/domain/UFW firewall changes remain fully open — this deployment is not yet reachable from outside the VPS itself (or an SSH tunnel) until the operator explicitly opens the relevant port(s).
- If the ad-hoc deployment ever received real Service/Incident Report attachment uploads (unconfirmed — likely not, since it was health-check-only per the VPS discovery), those files live on the VPS host filesystem, not in a Docker volume, and require the optional manual copy step documented in the runbook's §10 Step 4.

## 14. Manual/UAT Testing Instructions

See `docs/DEPLOYMENT_STAGING.md` §8 in full. In summary, once the VPS operator has executed §§2–7 of that runbook: `curl` both health endpoints, confirm `php artisan migrate:status` shows all 45 migrations applied, sign in to the Admin Backoffice and via the mobile-equivalent API login with a real (Tinker-created) Administrator account, confirm a deliberately invalid route returns a plain 404 (not a debug page), and confirm attachment upload/download round-trips against the configured disk.

## 15. Documentation Updated

`docs/phases/V1_PHASE_24_STAGING_DEPLOYMENT_PLAN.md`, `docs/DECISIONS.md` (DEC-047), `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/ROADMAP.md`, `docs/02_ARCHITECTURE.md`, `docs/05_SECURITY_MODEL.md`, and this handoff. `docs/DEPLOYMENT_STAGING.md` is new, dedicated deployment-runbook documentation, referenced from `docs/CURRENT_STATE.md` and the planning document rather than duplicated into either.

## 16. Recommended Next Step

Server-side execution of `docs/DEPLOYMENT_STAGING.md` by the VPS operator — migrating the ad-hoc deployment, building/starting the staging stack, running migrations, creating a real Administrator account, and completing the full verification checklist. Only once that is genuinely done and verified does Phase 25 (UAT) become meaningful to authorize; this session does not assume or request that authorization.
