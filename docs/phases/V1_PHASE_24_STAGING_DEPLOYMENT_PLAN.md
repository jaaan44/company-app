# Phase 24 — Staging Deployment — Discovery & Planning Report

**Status:** PLANNING COMPLETE, REPOSITORY IMPLEMENTATION COMPLETE, **SERVER-SIDE DEPLOYMENT VERIFIED ON THE REAL STAGING VPS** — see **Addendum 3: Real VPS Deployment Verification** at the end of this document. (Addenda 1–2 cover the VPS-discovery/authorization and the review-correction rounds; Addendum 3 covers the actual deployment execution and its results.) The remainder of this document is preserved exactly as originally written (per CLAUDE.md's "do not rewrite historical decisions unnecessarily") — it reflects this session's own planning and open questions at the time it was authored, before the product owner's VPS-discovery answers, implementation authorization, review corrections, and real deployment below. **TLS/domain configuration and public (UFW) exposure of Company App's ports remain not done** — see Addendum 3's "Explicitly deferred" list; staging is currently reachable only from the VPS itself, not from the public internet or a mobile client.

**This is not a phase specification** and it is deliberately **not** named `V1_PHASE_24_DEFINITION.md`, mirroring the precedent `docs/phases/V1_PHASE_22_SECURITY_AUDIT.md` set: this document is the audit/discovery deliverable that precedes a scoped authorization, not a spec for already-approved work.

**Date:** 2026-09-14. **Session type:** repository inspection, deployment discovery, environment planning, and scope determination only. **No application code, configuration file, Docker asset, or CI workflow was changed in this session.** No server (real or otherwise) was touched — this session has no access to any staging VPS.

**Repository state audited:** `main` at `f88c698` (Phase 23 — Mobile UI/UX Audit & Foundation, merged; both post-Phase-22 Dependabot GitHub Actions PRs, #25/#26, merged). Working tree confirmed clean before and after this session; no files were modified.

**Depends on:** Phases 1–23 (all merged, all confirmed present — see §2 below).

---

## 1. Executive Summary

Company App has never been deployed anywhere except a developer's own machine via the Phase 4A Docker Compose stack, which is explicitly documented as **local-development-only** (`docker/php/Dockerfile`'s own first comment: *"Local development image only — not a production deployment artifact"*). No staging or production environment, deployment script, CD workflow, or hosting decision exists anywhere in the repository. `docs/ROADMAP.md` names Phase 24 plainly: **"first real deployment."**

One piece of repository evidence materially changes the starting assumption, though, and needs the product owner's confirmation before anything else: `docs/testing/UAT_LOG.md` (UAT-05-01/02, 2026-09-11) and `docs/handoffs/V1_PHASE_05_HANDOFF.md` both record that the product owner **already ran the Phase 4A Docker stack on a DigitalOcean VPS** to perform Phase 5 UAT. No later phase's UAT entries mention it again — every UAT-06 through UAT-18 row is `NOT RUN`, with no environment named. **It is not clear from the repository alone whether that VPS still exists, is still running anything, or is the machine intended for Phase 24.** This is the single most important open question in §4/§6 below — everything about server prerequisites and the deployment procedure depends on the answer.

Architecturally, this is good news for Phase 24: the application is a lean, ~100-employee-scale Laravel 13/PHP 8.4 monolith with **no queue workers, no scheduler, no Redis, no WebSocket/broadcasting layer, and no cron jobs** (`routes/console.php` defines only the stock `inspire` command) — every module through Phase 20 was deliberately built fully synchronous (`docs/02_ARCHITECTURE.md` §5/§6). MySQL 8.4 is the confirmed production database engine (DEC-016). File storage is local-disk-only today (object storage provider is an explicitly open question, `02_ARCHITECTURE.md` §9). This means a staging environment does not need to reproduce or approximate any background-processing infrastructure — it needs a web server, PHP-FPM, and MySQL, correctly configured for a non-development environment, and nothing else.

The gap is entirely in **environment-hardening and deployment mechanics**, not application architecture: no production-grade Docker image exists (the current one bind-mounts source and is explicitly dev-only), no CD workflow exists, no staging `.env` template exists, no health check verifies real dependencies (the app's `/api/v1/health` returns a static `{status: "ok"}` with no DB/cache probe), and several settings the Phase 22 security audit already flagged as "verify at deployment time" (real `APP_DEBUG=false`, `SESSION_SECURE_COOKIE`, TLS, non-default DB credentials) have never actually been exercised outside `.env.example`'s dev defaults.

**Recommended scope for the Phase 24 implementation (once authorized):** a small set of new, staging-specific repository assets (a production-style Docker image, a staging Compose file or equivalent, a staging `.env` template, a short deployment runbook) plus manual server-side provisioning on a single small VPS — no Kubernetes, no managed PaaS, no multi-server topology, no queue/Redis/WebSocket service, consistent with `02_ARCHITECTURE.md` §0's resource-efficiency direction. This document proposes that scope; it does not implement it.

## 2. Repository Deployment Inventory

Confirmed by direct inspection (not assumed from documentation):

| Asset | Exists? | Nature |
|---|---|---|
| `docker-compose.yml` (root) | Yes | **Local development only** (Phase 4A/DEC-027) — bind-mounts `apps/api` into the `app` container, no image build of application code, MySQL container has hardcoded `secret` credentials, host ports exposed for developer tooling. Self-documented as such in its own header comment. |
| `docker/php/Dockerfile` | Yes | Builds `php:8.4-fpm` + `pdo_mysql`/`bcmath` + Composer; explicitly headed *"Local development image only — not a production deployment artifact"*; no `COPY` of application code (relies on the bind mount); no `--no-dev`/`--optimize-autoloader` install step; runs as root inside the container (no `USER` directive). |
| `docker/php/entrypoint.sh` | Yes | `chmod -R a+rwX storage bootstrap/cache` to work around bind-mount UID mismatches across host OSes — a dev-only concern, meaningless (and undesirable) once code is copied into an image rather than bind-mounted. |
| `docker/nginx/default.conf` | Yes | Otherwise conventional Laravel vhost (correct document root, PHP-FPM `fastcgi_pass`, dotfile deny) — the **application-layer** parts of this are staging-reusable as-is; only the container topology around it (TLS termination, `server_name`) needs staging-specific values. |
| Production/staging Dockerfile | **No** | Does not exist. |
| `docker-compose.staging.yml` / `.prod.yml` or equivalent | **No** | Does not exist. |
| CD/deployment GitHub Actions workflow | **No** | `.github/workflows/` contains only `backend-ci.yml`/`mobile-ci.yml` — both quality-gate-only (lint/static-analysis/test), triggered on PR/push to `main`, no deploy step, no SSH/registry-push action, no environment secrets referenced. |
| Staging/production `.env` template | **No** | Only `apps/api/.env.example` (SQLite, `APP_DEBUG=true`, dev-shaped) and `apps/api/.env.docker.example` (points at the dev `mysql` Compose service, same `APP_DEBUG=true`) exist — both explicitly documented as local-dev-only. |
| Health check beyond a static OK | Partial | `GET /api/v1/health` (`app/Http/Controllers/Api/V1/HealthController.php`) returns `{"status": "ok", "timestamp": ...}` unconditionally — it does not touch the database, cache, or any dependency. Laravel 13's own framework-level `health: '/up'` route is also registered (`bootstrap/app.php`) — a second, independent liveness endpoint, equally dependency-blind by default. Neither currently proves the database is reachable. |
| Deployment scripts (bash/Ansible/etc.) | **No** | None found anywhere in the repository. |
| Object storage (S3/Spaces) configuration | Partial | `config/filesystems.php` already defines an `s3` disk driven entirely by env vars (`AWS_*`); `config/attachments.php`'s `disk` setting is the single switch that would redirect attachments there — but the **provider decision itself is an explicitly open question** (`02_ARCHITECTURE.md` §9), not yet made for any environment. |
| Backup strategy/tooling | **No** | No backup script, scheduled job, or documented procedure exists anywhere (consistent with there being no scheduler/cron infrastructure at all yet). |
| `composer.json` deploy scripts | Yes | A `"setup"` Composer script exists (`install` → `.env` copy → `key:generate` → `migrate --force` → `npm install`/`build`) — written for a fresh checkout, not staging-aware (assumes `.env.example`, does not distinguish environments), but a useful skeleton for a first-deploy runbook step. |
| Seeders | Yes | `AdminUserSeeder` (hardcoded local credentials, `admin@example.test` / `password` — **must not be run as-is against staging**), `RolePermissionSeeder` (idempotent, environment-safe, required in every environment per `CLAUDE.md`). |
| Migrations | Yes | 45 migration files, additive-only history through Phase 20 (no destructive/irreversible migration observed in the set) — standard `php artisan migrate` applies cleanly to a fresh MySQL database. |

## 3. Current Infrastructure Assumptions (from the repository)

- **Database:** MySQL 8.4 is the confirmed production direction (DEC-016). No PostgreSQL/MariaDB path was ever considered or built. SQLite remains test/CI-only.
- **PHP:** 8.4.19 is what Phase 1 bootstrapped against; `composer.json` requires `^8.3`. Required extensions, confirmed from the Docker image and CI's own extension list: `pdo_mysql`, `bcmath`, `mbstring`, `dom`, `curl`, `libxml`, `pdo`, `ctype`, `fileinfo`, `tokenizer`, `xml` (CI additionally needs `pdo_sqlite`/`sqlite3`, which staging does not).
- **Web server:** Nginx + PHP-FPM (the only pattern this repository has ever built or documented) — no Apache/`mod_php` path exists.
- **Queues/scheduler/real-time:** None. `02_ARCHITECTURE.md` §5/§6 document this as a deliberate, standing architectural decision (DEC-039 et al.), not a gap — every write is synchronous. `routes/console.php` has no `Schedule::` calls. Staging does not need a queue worker container, a scheduler cron entry, Redis, or a WebSocket server.
- **Cache/session/queue drivers:** all three default to the framework's `database` driver (`.env.example`) — no Redis is configured or required anywhere. This should carry over to staging unchanged unless a future phase demonstrates a real need (consistent with §0's resource-efficiency direction).
- **File storage:** local disk only, never web-served (`config/filesystems.php`'s `local` disk, `serve => false` since Phase 22/F-07). Production/staging object storage provider is explicitly undecided.
- **Sanctum mobile auth:** bearer tokens, 30-day expiration (Phase 22/F-02) — no cookie-based/stateful Sanctum use, so `SANCTUM_STATEFUL_DOMAINS` is largely moot for the mobile client; it matters only if a browser client is ever added (it is not, in V1).
- **Admin Backoffice auth:** session/cookie (`web` guard) — `SESSION_SECURE_COOKIE`, `SESSION_DOMAIN`, and HTTPS therefore matter for staging in a way they never have locally (local dev is always plain HTTP on `localhost`).
- **CORS:** no `config/cors.php` published — deliberately deferred (Phase 22/F-06) because no browser-based client exists. Still true for Phase 24: staging serves the Flutter app (native HTTP, CORS-irrelevant) and the Admin Backoffice (same-origin Blade/Livewire). No CORS policy is needed for Phase 24 unless the scope changes.
- **Mobile API base URL:** already environment-agnostic — `apps/mobile/lib/core/config/app_config.dart` reads `API_BASE_URL` via `String.fromEnvironment`, supplied at build/run time via `--dart-define` (DEC-021), defaulting to `http://localhost:8000/api/v1` only when omitted. **No Flutter code change is needed for staging** — a staging build simply passes `--dart-define=API_BASE_URL=https://<staging-host>/api/v1`. This is worth stating plainly since the task's own instructions asked it be "determined, not prematurely implemented" — it turns out already implemented, from Phase 3/4.

## 4. Missing Information This Session Cannot Supply

This session has no access to any real server. The following must come from the product owner/a real VPS session before a deployment procedure can be finalized:

1. **Does the DigitalOcean VPS used for Phase 5 UAT (`docs/testing/UAT_LOG.md` UAT-05-01/02) still exist?** If so, is it the intended Phase 24 staging host, and what (if anything) is still running on it? This is not answerable from the repository — no later phase's UAT log or handoff mentions it again.
2. If that VPS is gone, or a different host is intended: OS/version, CPU/RAM/disk, existing installed software (Docker? Nginx? PHP? MySQL?), other applications already occupying it, currently open ports, a deployment user, firewall status (ufw/iptables/cloud firewall), and where the repository would be checked out.
3. Domain/subdomain intended for staging (e.g. `staging.company-app.example`), current DNS status, and who controls the DNS zone.
4. TLS certificate plan (Let's Encrypt/Certbot vs. an already-issued certificate vs. a cloud load balancer terminating TLS).
5. Whether GitHub deploy access to this VPS should be via a deploy key/PAT and manual `git pull`, or a CD workflow (SSH action) — a decision, not something to default to.
6. Object storage decision for staging: continue with local disk (simplest, consistent with V1's current state) or provision S3-compatible storage (e.g. DigitalOcean Spaces, natural fit if the VPS is already DigitalOcean) now. Neither is assumed here.
7. Whether the product owner wants a managed MySQL instance (e.g. DigitalOcean Managed Databases) or a MySQL container/package on the same VPS — materially changes the server prerequisites and backup story.
8. Any budget/resource ceiling for the staging host (informs the "lean architecture" sizing recommendation in §5, currently written assuming a single small VPS).

### Safe commands for the user to run and return the output

Run these on the candidate staging VPS (read-only — nothing here changes system state) and return the output:

```sh
# OS / kernel
lsb_release -a 2>/dev/null || cat /etc/os-release
uname -a

# CPU / RAM / disk
nproc
free -h
df -h

# What's already installed / running
docker --version 2>/dev/null; docker compose version 2>/dev/null
nginx -v 2>/dev/null; apache2 -v 2>/dev/null
php -v 2>/dev/null; php -m 2>/dev/null | sort
mysql --version 2>/dev/null; mysqld --version 2>/dev/null
redis-cli --version 2>/dev/null
systemctl list-units --type=service --state=running --no-pager 2>/dev/null

# Ports already in use
ss -tulpn 2>/dev/null || netstat -tulpn 2>/dev/null

# Firewall status
ufw status verbose 2>/dev/null
iptables -L -n 2>/dev/null | head -50

# Existing web content / prior deployments
ls -la /var/www 2>/dev/null
ls -la /opt 2>/dev/null
ls -la /home 2>/dev/null

# Existing users, for the deployment-user question
cut -d: -f1 /etc/passwd | sort

# Existing cert/DNS-relevant state
which certbot 2>/dev/null
crontab -l 2>/dev/null
```

None of these commands install, modify, or delete anything — they only report existing state, per this session's "read-only discovery first" constraint.

## 5. Recommended Staging Architecture

Given `02_ARCHITECTURE.md` §0's explicit resource-efficiency direction (~100 employees, "do not optimize for massive-scale workloads or introduce infrastructure without demonstrated need") and this application's confirmed absence of queues/scheduler/Redis/WebSockets, the recommendation is:

**A single small VPS, running the same three-service shape Phase 4A already established, hardened for non-development use — not re-architected:**

```
Internet ──HTTPS──► nginx (TLS termination, reverse proxy) ──► app (PHP-FPM 8.4) ──► mysql (8.4)
                     │
                     └── serves Flutter build artifacts only if a web build is ever
                         distributed this way (out of scope — mobile stays native)
```

- **nginx**: same vhost shape as `docker/nginx/default.conf`, plus TLS (Let's Encrypt via Certbot, either as a sidecar container or the host's own Certbot + a bind-mounted certificate volume) and a real `server_name`. Terminates TLS; proxies to `app:9000` exactly as today.
- **app**: PHP-FPM 8.4, but built from a **new, staging-appropriate Dockerfile** that `COPY`s application code into the image (no bind mount), runs `composer install --no-dev --optimize-autoloader`, and does not run as root. The existing `docker/php/Dockerfile` should not be reused for this purpose — it says outright it isn't meant to be.
- **mysql**: 8.4, matching DEC-016 exactly — either a container (mirroring the current Compose shape, with a named volume and *not* exposing its port to the host/internet) or a managed database service if the product owner prefers one (§4, open question). No functional difference to the application either way; `DB_HOST`/`DB_PORT`/credentials are all env-driven already.
- **No Redis, queue worker, scheduler container, WebSocket server, or load balancer tier** — none is used by any code that exists today. Adding one now would be exactly the "infrastructure without demonstrated need" §0 warns against.
- **No Kubernetes, no multi-node topology, no blue/green tooling** — a single Docker Compose stack (or, if the product owner prefers, systemd-managed native services) on one VPS is proportionate to ~100 users and mirrors the local-dev pattern the team already knows, minimizing new operational surface to learn.

This is a deliberate **extension** of Phase 4A's existing pattern, not a new architecture — the three-service shape, the vhost, and the MySQL version all carry over unchanged; only the things that were correctly dev-only (bind mounts, hardcoded credentials, `APP_DEBUG=true`, no TLS, host-exposed database port) need staging-appropriate replacements.

## 6. Repository-Side Changes Likely Required (proposed, not yet implemented)

None of the following has been created in this session. If Phase 24 is authorized, this is the anticipated repository-side deliverable set:

1. **A staging/production-appropriate Docker image** — e.g. `docker/php/Dockerfile.production` or a multi-stage addition to the existing Dockerfile — that copies application code in, runs `composer install --no-dev --optimize-autoloader`, and drops root privileges. The existing `docker/php/Dockerfile` stays exactly as-is for local development (per DEC-027 — not superseded, just not reused here).
2. **A staging Compose file** (e.g. `docker-compose.staging.yml`, used with `-f` alongside or instead of the root file) — no bind mounts, no host-exposed MySQL port, real environment variable injection (via a `.env` on the server or the orchestrator's own secret mechanism, never committed).
3. **`apps/api/.env.staging.example`** (or equivalently named) — mirrors `.env.docker.example`'s role but with staging-appropriate defaults: `APP_ENV=staging`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, a real `APP_URL`, and blank/placeholder secrets (never real credentials committed, per this document's own instruction and `05_SECURITY_MODEL.md`).
4. **A deployment runbook** (README section or a new `docs/DEPLOYMENT.md`) documenting the exact server-side sequence (§8) so it isn't tribal knowledge.
5. **Optional, only if authorized separately:** a `GET /api/v1/health` enhancement (or a second, deliberately unauthenticated-but-minimal endpoint) that verifies real DB connectivity — today's endpoint is a static `{status: ok}` with no dependency check, which is a real gap for a genuine staging verification story (§8/§10). This is a small, self-contained change but is **application code**, not deployment tooling — flagged here as a recommendation for explicit authorization, not assumed in scope.
6. **Optional CD workflow** (`.github/workflows/deploy-staging.yml`) — only if the product owner chooses "push-to-deploy" over manual `git pull`-based deployment (§4, open question #5). Requires GitHub-side secrets (SSH key/host) that this session cannot create or see.

None of these should be built in this session — they are listed so the product owner can authorize precisely which of them Phase 24 should include.

## 7. Server Prerequisites

Assuming a single Ubuntu LTS VPS (the DigitalOcean precedent from Phase 5's UAT, pending confirmation per §4):

- Docker Engine + Docker Compose plugin (if the Docker path is chosen — recommended, for parity with local dev) **or** PHP 8.4-FPM + Composer + Nginx + MySQL 8.4 installed directly (if the product owner prefers a non-Docker staging host — a legitimate alternative CLAUDE.md/`02_ARCHITECTURE.md` don't rule out, since Docker is documented as "the standard local dev environment," not a mandated production mechanism).
- A non-root deployment user with SSH key access and least-privilege sudo (or none, if only Docker commands are needed and that user is in the `docker` group).
- A domain/subdomain pointed at the VPS's IP (A/AAAA record) before requesting a Let's Encrypt certificate (Certbot's HTTP-01 challenge needs this).
- Inbound firewall: 80/443 open publicly; 22 (SSH) restricted as the product owner prefers; MySQL's port **not** exposed publicly under any circumstance (a regression from even the current dev Compose file's `127.0.0.1`-only binding would be a real security downgrade).
- Enough disk for the application, MySQL data, and attachment storage growth — no specific figure is asserted here without real VPS specs (§4).

## 8. Environment-Variable Plan (staging `.env`, no real values here)

Derived directly from `apps/api/.env.example`/`.env.docker.example`, changed only where staging genuinely differs:

| Variable | Local dev value | Staging value | Why |
|---|---|---|---|
| `APP_ENV` | `local` | `staging` | Standard Laravel environment discrimination. |
| `APP_DEBUG` | `true` | `false` | **Non-negotiable** — Phase 22/F-08's own reminder; a debug page on staging leaks stack traces/config to anyone who can trigger a 500. |
| `APP_KEY` | generated locally | generated fresh on the staging host (`php artisan key:generate`) | Never reuse a dev key; never commit it. |
| `APP_URL` | `http://localhost:8012` | `https://<staging-domain>` | Used in generated URLs (e.g. the `public` disk's URL helper). |
| `DB_CONNECTION`/`DB_HOST`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` | `mysql`/`company_app`/`secret` | real MySQL connection, a generated high-entropy password, never `secret` | Phase 22/F-09 already flags the dev password as a known, accepted dev-only weakness — staging must not inherit it. |
| `SESSION_SECURE_COOKIE` | unset (defaults falsy) | `true` | Cookies must only travel over HTTPS once staging actually has TLS. |
| `SESSION_DOMAIN` | `null` | staging domain (or left `null` if single-host) | Only matters if ever serving multiple subdomains. |
| `SANCTUM_STATEFUL_DOMAINS` | framework default | unchanged unless a browser client is added | Mobile uses bearer tokens only — irrelevant until/unless that changes. |
| `SANCTUM_EXPIRATION` | `43200` (30 days, Phase 22/F-02 default) | unchanged unless the product owner wants a different staging policy | No reason to diverge from the already-decided production value. |
| `LOG_LEVEL` | `debug` | `info` or `notice` | Avoid staging logs growing unnecessarily verbose/noisy; still capture real errors. |
| `FILESYSTEM_DISK` / `ATTACHMENTS_DISK` | `local` | `local` (V1) or `s3` (only if object storage is decided, §4) | No code change needed either way — a single env value. |
| `CACHE_STORE`/`QUEUE_CONNECTION`/`SESSION_DRIVER` | `database` | unchanged | No demonstrated need to change; matches §0's direction. |
| `MAIL_MAILER` | `log` | `log` (or a real staging mail sink if the product owner wants to test transactional-adjacent flows — none currently exist, since no email is sent by any built module) | No module in this codebase sends email yet. |
| `AWS_*` | blank | populated only if object storage is provisioned | Otherwise leave blank, exactly as today. |

No real secret value is written here or anywhere in the repository — every staging credential must be generated/supplied at deployment time and stored only in the server's own `.env` (never committed; `apps/api/.gitignore`'s `.env.*` wildcard, Phase 22/F-05, already protects against an accidental commit of a staging file with a predictable name).

## 9. Deployment Procedure (draft sequence — not yet executed)

### A. Repository-side work (Claude can prepare, once authorized)
1. Add the staging Dockerfile/Compose assets and `.env.staging.example` (§6, items 1–3).
2. Write the deployment runbook (§6, item 4) documenting steps B–D below precisely.
3. (Optional, separately authorized) Strengthen `/api/v1/health` to check DB connectivity.

### B. Server-side work (the user executes on the real VPS — Claude cannot)
1. Provision/confirm the VPS per §7; create the deployment user; install Docker (or the direct-install stack).
2. Clone the repository at the deployment path; check out the release commit/tag intended for staging.
3. Copy the staging `.env` template, fill in real generated secrets (never reuse dev values).
4. Build and start the staging stack (`docker compose -f docker-compose.staging.yml up -d --build`, or the direct-install equivalent).
5. Run first-deploy setup inside the app container/host: `composer install --no-dev --optimize-autoloader` (if not baked into the image already), `php artisan key:generate` (staging `.env` only), `php artisan migrate --force`, `php artisan db:seed --class=Database\\Seeders\\RolePermissionSeeder` (idempotent, safe, required). **Do not run `AdminUserSeeder` as-is** — its hardcoded `admin@example.test`/`password` credentials must never exist on a reachable host; the product owner should decide how the first real Administrator account is created for staging (a one-off Tinker command with a real password is the smallest safe option today, since no user-management mutation API exists yet — a documented, pre-existing gap per DEC-044's Known Limitation).
6. `php artisan config:cache`/`route:cache`/`view:cache` for production-appropriate performance (not used in local dev, appropriate here).

### C. External configuration (the user arranges; Claude has no access)
1. DNS: point the chosen staging subdomain at the VPS.
2. TLS: issue a certificate (Certbot/Let's Encrypt against the now-resolving domain).
3. Firewall: open 80/443, restrict/lock down 22 and the database port as decided in §7.
4. GitHub deploy access: a deploy key or PAT if a pull-based or push-based deployment mechanism is chosen (§4, open question #5).

### D. Verification (both sides, after B–C)
- `GET /api/v1/health` (and, once enhanced, its DB-connectivity check) returns `200` over HTTPS.
- Laravel's own `/up` route returns `200`.
- `php artisan migrate:status` shows all 45 migrations applied, none pending.
- A real login (Admin session + a Sanctum bearer-token mobile login) succeeds against the staging database with a freshly created account — never the dev seeder's credentials.
- A representative authenticated API call (e.g. `GET /api/v1/staff`) returns data with valid TLS, no debug output on a deliberately triggered error (e.g. an invalid route) confirming `APP_DEBUG=false` is actually in effect.
- Attachment upload/download round-trips correctly against whichever disk was configured (§8).
- `docker compose logs`/application logs show no unexpected errors at idle.
- A Flutter build with `--dart-define=API_BASE_URL=https://<staging-domain>/api/v1` can reach the staging API from a real device/emulator (mobile API reachability, per the task's own verification list) — no code change, per §3.
- No queue/scheduler verification is needed (none exists) — explicitly note this as "N/A, not a gap" rather than an unverified item.

## 10. Security Checklist (staging-specific, built on Phase 22's audit)

- [ ] `APP_ENV=staging`, `APP_DEBUG=false` (§8) — verified by triggering a deliberate error and confirming no stack trace is returned.
- [ ] HTTPS/TLS enforced; HTTP requests redirect to HTTPS at the nginx layer.
- [ ] `APP_KEY` generated fresh for staging, never copied from a developer's local `.env`.
- [ ] Database credentials are staging-specific, high-entropy, never the dev Compose file's `secret` value (Phase 22/F-09's accepted dev-only weakness must not carry over).
- [ ] MySQL is not reachable from the public internet (bound to `127.0.0.1`/an internal Docker network only, or a managed database's own private-networking option).
- [ ] No `.env` file (staging or otherwise) is ever committed — already enforced by `apps/api/.gitignore`'s wildcard (Phase 22/F-05); confirm the staging file's actual name matches the `.env.*` pattern.
- [ ] Filesystem permissions on the host mirror the container's existing `storage`/`bootstrap/cache` write-scoping — no blanket `777`, no world-writable application code.
- [ ] `storage/app/private` (the attachments disk) remains genuinely non-public — no nginx `location` block or symlink exposes it, mirroring `config/filesystems.php`'s `serve => false`.
- [ ] Nginx document root remains `apps/api/public` only — no `app/`, `config/`, `storage/`, `.env`, or `.git` path is reachable (the existing `docker/nginx/default.conf` dotfile-deny rule carries over; confirm the staging vhost keeps it).
- [ ] Rate limiting on both login surfaces (already implemented, Phase 4) is confirmed active against the real staging host, not assumed from code alone.
- [ ] Sanctum bearer tokens expire in 30 days (already implemented, Phase 22/F-02) — no staging-specific change needed, just confirm `SANCTUM_EXPIRATION` isn't accidentally overridden.
- [ ] CORS: confirmed still unnecessary (no browser client) — revisit only if scope changes.
- [ ] `composer audit --locked` run against the exact dependency set being deployed (not just CI's copy) before first deploy.
- [ ] The Admin seeder's hardcoded dev credentials (`admin@example.test`/`password`) are never present on the staging database (§9.B.5).
- [ ] Logs do not capture secrets/PII beyond what `05_SECURITY_MODEL.md`'s existing redaction discipline already governs — no new logging was introduced by this plan.
- [ ] `/api/v1/health` (and `/up`) remain the only unauthenticated endpoints, and reveal nothing beyond status/timestamp — consistent with the existing documented exception list.

## 11. Verification / UAT Checklist

See §9.D for the technical verification list. For `docs/testing/UAT_LOG.md`, this session may (once Phase 24 is actually implemented and deployed) add new `NOT RUN` rows for staging-specific scenarios — e.g. "Admin: sign in on the real staging domain over HTTPS," "Mobile: build against the staging `API_BASE_URL` and complete a full login" — ready for the product owner's review. **No UAT `PASS` may be recorded by this or any AI session** (CLAUDE.md §7); this document records none.

## 12. Rollback Plan

Kept intentionally simple, per the task's own instruction not to over-engineer this for a ~100-employee staging environment:

- **Application code:** deploy by checking out a specific commit/tag, not floating `main` blindly — rollback is `git checkout <previous-known-good-commit>` followed by rebuilding/restarting the app container. No blue/green or canary mechanism is warranted at this scale.
- **Database migrations:** Laravel's `php artisan migrate:rollback` works for the most recent batch, but **is not a substitute for a real backup** — several of this application's 45 migrations (per standard Laravel migration authoring) may not have lossless `down()` methods for destructive changes. Take a database dump/snapshot immediately before running migrations on staging, every time, and treat rollback via backup restoration as the primary recovery path, not the `down()` method.
- **`.env` preservation:** the staging `.env` is never part of the git-tracked deployment artifact (never committed) — back it up separately (e.g. a copy kept outside the deployment directory, or a secrets manager) so a bad deploy that touches the file doesn't also destroy the environment configuration needed to recover.
- **Service restart:** `docker compose restart`/`up -d` (or the direct-install equivalent service restarts) is sufficient to recover from an application-level fault; no orchestration-level self-healing is being introduced.
- **No automated rollback tooling** is proposed — a manual, documented procedure is proportionate here and avoids building speculative automation ahead of a demonstrated need (§0's own direction, applied to operations as much as application architecture).

## 13. Explicit Exclusions

Consistent with `02_ARCHITECTURE.md` §0/§10 and the task's own instructions, Phase 24 explicitly does **not** include:

- Kubernetes, container orchestration platforms, or multi-node topology.
- Microservices decomposition of any kind.
- Kafka, Elasticsearch/OpenSearch, or any search/messaging middleware.
- Redis, a queue worker, a scheduler/cron service, or a WebSocket/broadcasting server — none is used by any code that exists today.
- Blue/green or canary deployment tooling.
- An enterprise monitoring/observability stack (APM, log aggregation service, alerting platform) — basic log review is sufficient at this scale; revisit only if real staging usage demonstrates a need.
- A CI/CD deploy workflow, unless the product owner explicitly chooses that over manual deployment (§4, open question #5) — not defaulted to.
- Object storage provisioning, unless explicitly authorized (§4, open question #6) — local disk remains valid for staging V1.
- Any change to the local Phase 4A Docker Compose development environment — it remains exactly as Phase 4A left it; staging gets its own, separate assets.
- Any User account-management (suspend/reactivate/role-change) feature — an already-documented, separately-scoped gap (DEC-044), not Phase 24's concern.
- Production deployment itself — Phase 24 is staging only, per the roadmap's own sequencing (Phase 25 is UAT, Phase 26 is release readiness).

## 14. Risks / Assumptions

- **Assumption requiring confirmation:** the Phase 5 DigitalOcean VPS (§1/§4) either still exists and is reusable, or is gone and a fresh host will be provisioned. The entire server-prerequisites/deployment-procedure section is written generically enough to apply either way, but the exact commands in §4 should be run against whichever host is actually in play before finalizing anything further.
- **Risk:** deploying with the dev Docker assets as-is (bind mounts, `secret` MySQL password, `APP_DEBUG=true`) would silently reproduce every Phase 22-documented dev-only weakness in a reachable environment — mitigated by treating §6's new staging-specific assets as required, not optional, before any real deployment.
- **Risk:** the current health endpoint cannot distinguish "the app is up" from "the app is up but the database is unreachable," which would make a real deployment failure look healthy to any external monitor — flagged in §6/§10 as a recommended (not yet authorized) fix.
- **Risk:** no backup tooling exists yet; a destructive mistake against a real staging database has no automated recovery path until the manual procedure in §12 is actually exercised at least once.
- **Assumption:** MySQL 8.4 and PHP 8.4 remain the target versions — consistent with every existing decision (DEC-016, Phase 1 bootstrap); this document does not revisit either.
- **Assumption:** no browser-based client is being added as part of Phase 24 — if that changes, `config/cors.php` and `SANCTUM_STATEFUL_DOMAINS` both need real values, which they do not today.

## 15. Definition of Done (for this planning session)

- [x] Repository state established (branch, `origin/main` HEAD, Phase 23 + Dependabot merges confirmed, clean working tree).
- [x] Phase 24's scope recovered from the repository (roadmap, current state, architecture, security model, decisions, CI, Docker assets, `.env.example`s, Phase 22 audit/handoff, Phase 23 handoff, health endpoint, mobile config).
- [x] Deployable architecture inspected (PHP/DB/queue/scheduler/storage/web-server/health/cache/session/logging/background-worker/migration/build/env-var requirements all determined from actual code, not assumed).
- [x] Existing Docker/deployment assets inventoried and each classified (suitable-as-is / dev-only / incomplete / missing) — §2.
- [x] Security review performed against Phase 22's constraints — §10.
- [x] Server prerequisites and exact safe discovery commands prepared — §7/§4.
- [x] Lean staging architecture recommended with rationale — §5.
- [x] Deployment procedure drafted, separated into repository-side/server-side/external-configuration/verification — §9.
- [x] Rollback plan defined, deliberately simple — §12.
- [x] This document committed to `docs/phases/` under the established naming convention.
- [ ] **Not done, and not to be done without further authorization:** any actual repository change beyond this document, any server access or change, any speculative deployment file.

---

*This document was produced entirely from repository inspection. No staging or production server was accessed, and none exists that this session is aware of being reachable. All server-side content above is a proposal for the product owner's review, not a record of anything executed.*

---

## Addendum: Confirmed VPS Facts & Repository Implementation (2026-09-14)

Following product-owner VPS discovery, Phase 24 repository implementation was authorized (see `docs/DECISIONS.md` DEC-047) and completed in this same session. This addendum records what changed; the sections above are left exactly as originally written.

### Confirmed VPS facts (answers to §4's open questions)

- **Host:** Ubuntu 24.04.4 LTS, 2 vCPU, ~4 GB RAM, ~77 GB disk, Docker 29.8.0, Docker Compose 5.5.1. **A personal, multi-project staging VPS** — other, unrelated projects share this host. §5's "single VPS" recommendation is confirmed correct, but its topology assumption is narrowed: **no shared reverse proxy or platform layer** — every project, Company App included, must bind its own dedicated host ports.
- **The Phase 5 UAT DigitalOcean VPS question (§1/§4) is resolved by superseding fact, not by direct answer:** the product owner's VPS discovery describes a *personal multi-project* VPS with an already-running *preliminary* Company App deployment — whether or not this is literally the same DigitalOcean host used for Phase 5 UAT is no longer load-bearing, since that preliminary deployment is now fully identified and is being formally migrated (see below) regardless of its history.
- **A preliminary, ad-hoc Company App deployment already exists** at `/home/deploy/company-app/company-app-build/company-app/docker-compose.yml` — this is **the local-development-oriented Compose file** (bind-mounted source), not a Phase 24 staging deployment, exactly as §2's inventory anticipated. Containers: `company-app-api`, `company-app-nginx`, `company-app-mysql`; HTTP on host port `8012`; MySQL bound to `127.0.0.1:3347`. `GET http://127.0.0.1:8012/api/v1/health` already returns `200` — confirming the underlying application/database connectivity works, but not that the deployment mechanics (source baked into an image, real secrets, TLS-readiness) are staging-appropriate. This ad-hoc deployment's own MySQL volume (`company-app_mysql-data`, per Compose's default project-naming rule from its containing directory name) is real, pre-existing state that must be preserved — see `docs/DEPLOYMENT_STAGING.md` §10.
- **Host-port policy (new, recorded here and in DEC-047):** Company App reserves **8012 (HTTP)** and **8442 (HTTPS, reserved — not yet mapped)** and, only if retained, **127.0.0.1:3347 (MySQL, loopback-only)**. No other project on this VPS may use these ports; other projects receive their own separate ranges. Container-internal ports remain conventional (80/443/3306/9000) since each Compose project has its own isolated Docker network — this is what makes a per-project dedicated-port policy sufficient without a shared reverse proxy.
- **Firewall:** UFW currently permits only 22/80/443. Company App's reserved ports (8012, 8442) are **not** currently open — this deployment is reachable only from the VPS itself (or via SSH tunnel) until the operator deliberately opens them. Per the authorization, this document does not instruct changing UFW now.
- **TLS/domain:** still an open decision — no hostname has been chosen, so no certificate exists and port 8442 stays reserved-but-unmapped. Nothing about this addendum invents one.

### Repository changes implemented

| File | What it is |
|---|---|
| `docker/php/Dockerfile.staging` | Staging application image — bakes source + a `--no-dev`, `--optimize-autoloader` Composer install into the image (no bind mount); narrow `chown www-data` on `storage`/`bootstrap/cache` instead of the dev image's broad runtime `chmod`. Same minimal extension set as the dev image (`pdo_mysql`, `bcmath` — everything else this app needs already ships in `php:8.4-fpm`). No Redis/queue/scheduler tooling added, per the explicit exclusion — none is used anywhere in this codebase. |
| `docker/nginx/Dockerfile.staging` | Minimal staging Nginx image reusing the existing `docker/nginx/default.conf` vhost unchanged. **Revised after review — see Addendum 2 below.** Bakes in `apps/api/public/`'s actual small contents (`.htaccess`, `favicon.ico`, `index.php`, `robots.txt` — four inert static files) at build time, rather than an empty directory. `SCRIPT_FILENAME` for every real (`.php`-routed) request is still resolved on the `app` container's own filesystem by PHP-FPM, not by nginx — verified by line-by-line inspection of `default.conf`, not merely inferred from `@vite`'s absence — so this bakes in only what nginx's own two static `location =` blocks (`/favicon.ico`, `/robots.txt`) need to behave identically to local dev, not a functional requirement for PHP routing itself. |
| `docker-compose.staging.yml` | The dedicated staging Compose file: `app`/`nginx`/`mysql`, a dedicated `company-app-staging` bridge network, a persistent `mysql-data` volume (see below for how it reuses the pre-existing one) and a persistent `app-storage` volume (new — preserves Service/Incident Report attachments across image rebuilds, which the pre-existing ad-hoc deployment never needed since it bind-mounted host storage directly). `nginx` publishes `8012:80` only; `8442` is documented as reserved but deliberately unmapped (no TLS backend exists yet); `mysql` publishes no host port at all by default (Docker-network-only, the "preferably" option in the authorization), with a commented-out, loopback-only `127.0.0.1:3347` mapping available if the operator decides to keep host-tool access. MySQL's `MYSQL_ROOT_PASSWORD`/`MYSQL_USER`/`MYSQL_PASSWORD`/`MYSQL_DATABASE` are populated entirely via `${DB_*}` Compose-file substitution — no value is written into this committed file. The health check authenticates using the container's own `$MYSQL_ROOT_PASSWORD` environment variable (`$$`-escaped in the committed YAML), never a literal password. |
| `.env.staging.example` (repo root) | Placeholder-only template for the small Compose-substitution file (`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD`/`DB_ROOT_PASSWORD`) that feeds the `mysql` service above. |
| `apps/api/.env.staging.example` | Placeholder-only template for Laravel's real staging `.env` — `APP_ENV=staging`, `APP_DEBUG=false`, `LOG_LEVEL=info`, unique DB credential placeholders, Sanctum's already-decided 30-day expiration left as the default (DEC-045, not overridden), `SESSION_SECURE_COOKIE=false` with an explicit comment explaining why (no TLS yet — a `true` value here would silently break Admin Backoffice login on the current HTTP-only endpoint) and exactly when to flip it, staging URL/mail placeholders, no invented domain. |
| `.dockerignore` (repo root, new) | Keeps `.git`, secrets (`apps/api/.env*`, negating only the three committed `.example` files), `vendor/`, `node_modules/`, `apps/mobile/`, `docs/`, and the local-dev-only Docker assets (`docker-compose.yml`, `docker/php/entrypoint.sh`, `docker/php/conf.d`) out of every image built from this repository. |
| `docs/DEPLOYMENT_STAGING.md` (new) | The full deployment runbook — first deployment, migrating the ad-hoc deployment (with a mandatory backup step and explicit "do not run `down -v`" warnings), migrations/seeding (including why `AdminUserSeeder` must never run against staging and the Tinker-based alternative), cache commands, verification, logs, restart/stop/start, upgrade/redeploy, rollback, and mobile `--dart-define` staging configuration. Every command is labeled `[Repository — already done]` or `[Run on the VPS]`. |

**How `company-app_mysql-data` is protected:** `docker-compose.staging.yml`'s `mysql-data` volume declares no explicit `name:` override. Invoking Compose with `-p company-app` (documented at the top of the Compose file itself and throughout the runbook) makes Compose resolve the volume to `company-app_mysql-data` — verified directly in this session via `docker compose -p company-app -f docker-compose.staging.yml config`, whose output confirms `mysql-data: name: company-app_mysql-data`. This is the exact volume name the ad-hoc deployment already created (its own Compose project, run from a directory literally named `company-app`, resolves to the same project name by Compose's own default rule). The new stack therefore **attaches to and reuses the existing volume rather than creating a new one** — no data-copy step is needed for the database; `docs/DEPLOYMENT_STAGING.md` §10 still requires taking a `mysqldump` backup *before* the cutover as a safety net, and explicitly forbids `docker compose down -v` at every step where a volume-destroying command might otherwise be tempting.

### Quality gates run this session

- `docker compose -p company-app -f docker-compose.staging.yml --env-file <temporary placeholder, not committed> config` — succeeded; confirmed correct service/network/volume resolution, including the `company-app_mysql-data` volume-name match above. The temporary placeholder env files used for this check were deleted immediately after and were never committed.
- `docker build` could not be run — this sandbox has the `docker` CLI but no reachable Docker daemon (`/var/run/docker.sock` does not exist here). The two staging Dockerfiles were therefore reviewed by hand (correct COPY ordering — dependency files before `composer install --no-autoloader`, application source copied before the real `composer dump-autoload --optimize`, so the generated classmap is built against real application code) but **not build-tested** in this session.
- No PHP application code, `composer.json`, or any file under `apps/api/app|config|routes|tests` was touched — `composer validate --strict`/`vendor/bin/pint --test`/`vendor/bin/phpstan analyse`/`php artisan test` are unaffected by this session's changes and were not re-run (no relevant files changed).
- **No claim is made that the staging stack has been built, started, or verified on the real VPS** — §8 of `docs/DEPLOYMENT_STAGING.md` is a checklist for the VPS operator to execute, not a record of execution.

### What remains for the VPS operator (see `docs/DEPLOYMENT_STAGING.md` for exact commands)

1. Back up the ad-hoc deployment's database, then migrate to the new Compose file (§10 of the runbook) — preserving `company-app_mysql-data`.
2. Create the two real `.env`/`.env.staging` files from the committed `.example` templates, with generated secrets.
3. Build the staging images, generate `APP_KEY`, start the stack, run migrations, run only `RolePermissionSeeder`, create a real Administrator account via Tinker (never `AdminUserSeeder`).
4. Run the cache commands and the full verification checklist (§8).
5. Decide a staging domain, obtain a TLS certificate, open UFW for 8012 (and later 8442), add the HTTPS nginx configuration, then — and only then — flip `SESSION_SECURE_COOKIE=true`.

### Risks/follow-ups added by this addendum

- The health-endpoint gap (§1/§6/§10/§14 above) remains **not fixed**, per the explicit instruction not to make `/api/v1/health` DB-aware in this implementation — recorded as a standing follow-up, not a regression.
- This VPS is shared with other, unrelated projects. Nothing in the Phase 24 assets assumes exclusive host access beyond the two reserved ports — no shared network, volume, or reverse-proxy dependency was introduced that could couple Company App's staging stack to any other project's.
- The ad-hoc deployment's MySQL was reachable at `127.0.0.1:3347`; the new default is Docker-network-only (no host port at all). If any existing tooling/habit depends on connecting a host-side MySQL client to `3347`, re-enable the commented-out mapping in `docker-compose.staging.yml` deliberately, understanding it remains loopback-only either way.

---

## Addendum 2: Review Corrections & Real Build Verification (2026-09-14, same day)

Following product-owner review of the Addendum-1 implementation, two targeted corrections were requested and made, and — for the first time in this Phase 24 work — real Docker build/run verification was obtained (previously only static `docker compose config` validation had been possible; see Addendum 1's "Quality gates run this session").

### 1. Nginx design: verified by config inspection, not by `@vite`'s absence alone

`docker/nginx/default.conf` was read line-by-line to answer the actual question — does nginx need `public/index.php` (or anything else in `public/`) present on its own filesystem for routing to work — rather than relying on the earlier `@vite` grep as sufficient proof. Two genuinely different code paths exist:

- **Every `.php`-routed request** (100% of this application's real traffic: the JSON API and the Admin Backoffice) is matched by `location ~ \.php$`, which does **not** consult `try_files` or the filesystem at all. It builds `SCRIPT_FILENAME` as a plain string — `$document_root$fastcgi_script_name`, where `$document_root` is derived directly from the `root` directive (a config value, not a filesystem lookup) — and hands that string to PHP-FPM over `fastcgi_pass app:9000`. **PHP-FPM resolves that path on the `app` container's own filesystem**, where `docker/php/Dockerfile.staging` bakes in the real `public/index.php`. nginx itself never opens, reads, or needs `index.php` for this to work.
- **`location = /favicon.ico`/`= /robots.txt`**, however, bypass `try_files`/PHP entirely and read directly from nginx's own `root`. An empty `public/` (the original design) would make both **genuinely 404** — nginx correctly finding no file, not a bug, but a real, if minor, behavioral gap versus local dev's bind-mounted equivalent, and a case the earlier `@vite`-only reasoning didn't fully account for.

**Correction made:** `docker/nginx/Dockerfile.staging` now `COPY apps/api/public/ /var/www/html/public/` — the actual four small, static files (`.htaccess`, `favicon.ico`, `index.php`, `robots.txt`) baked in at build time. This is **not** a bind mount (a plain, immutable `COPY` at image-build time) and is not meaningfully "source code" — `index.php` does nothing on nginx's side, since nginx never executes PHP. **Why the final Nginx/PHP-FPM split works correctly across two containers:** nginx's `root`/`try_files` only ever need to know whether a *static* file matches a given request (now correctly answered using real files, matching local dev exactly); for everything else, nginx constructs a filesystem path as a string and delegates actual file resolution and execution entirely to PHP-FPM in the separate `app` container, which has the full, real application baked into its own image. Neither container needs the other's full filesystem — only this one small, now-real `public/` directory on nginx's side, and the complete application on `app`'s side.

### 2. `APP_KEY` generation with a read-only `.env` mount

`docs/DEPLOYMENT_STAGING.md` already used `php artisan key:generate --show` (which returns before any file write is attempted), so the *command* was already safe against `apps/api/.env`'s `:ro` bind mount. Its own explanatory text, however, incorrectly claimed "writing directly would also work" — false, since an ordinary `key:generate` would fail outright (`Failed to open stream: Read-only file system`) against a read-only mount. **Corrected:** the runbook now states this plainly and spells out the exact safe workflow — generate the key via `--show` (prints the value, touches no file), edit it into the real, host-side `apps/api/.env` directly (e.g. `sed -i "s|^APP_KEY=.*|APP_KEY=...|"`), then (re)start the application — with an explicit instruction never to commit or print the generated value anywhere version-controlled. No real secret was generated, printed, or committed in this session.

### 3. Real build verification (this session)

**The Docker daemon is actually usable in this sandbox** — a correction to Addendum 1's "no reachable Docker daemon" finding. `service docker start` fails (a `ulimit` call in `/etc/init.d/docker` errors with "Operation not permitted"), but running `dockerd` directly (`nohup dockerd &`, bypassing only that wrapper script) starts a fully functional daemon that persists across separate tool invocations. Base images were pulled via the same working Docker Hub mirror Phase 4A's own handoff already documented (`mirror.gcr.io`), then locally re-tagged to their expected names so the committed Dockerfiles' `FROM` lines resolve unmodified.

**What was actually built and verified:**
- `docker/nginx/Dockerfile.staging` — **built successfully**, both directly and via `docker compose -p company-app -f docker-compose.staging.yml build nginx`. `docker run --rm company-app-nginx:staging-verify ls -la /var/www/html/public` confirmed all four real files present inside the built image. `nginx -t` run against the built image in isolation (`docker run --rm ... nginx -t`) fails with `host not found in upstream "app"` — this is **expected and correct**, not a defect: `fastcgi_pass app:9000` can only resolve the hostname `app` when the container actually runs on the `company-app-staging` Docker network alongside a running `app` service (as it will under `docker compose up`), which an isolated single-container `docker run` doesn't provide.
- `docker/php/Dockerfile.staging` — **could not be built to completion in this sandbox.** The build reproduces this repository's own long-documented, pre-existing limitation at the very first step: `apt-get update` gets `403 Forbidden` reaching `deb.debian.org` — identical to the finding already recorded in `docs/handoffs/V1_PHASE_04A_HANDOFF.md`. A separate attempt to populate `vendor/` directly via Composer (run through the official `composer:2` image, which already bundles git/unzip, specifically to test whether the *later* `composer dump-autoload`/`php artisan package:discover` steps work against real dependencies, independent of the `apt-get` question) hit a harder wall than any prior phase session's documented Composer recovery: HTTPS requests to `api.github.com`/`repo.packagist.org` fail TLS verification from inside a plain container in this sandbox (`curl: (60) SSL certificate ... self-signed certificate in certificate chain` — this sandbox's outbound proxy is not trusted by a container's default CA store), and Composer's `--prefer-source` git fallback then hangs indefinitely: `docker exec`'s process list showed it stuck on `git clone --mirror -- git@github.com:doctrine/lexer.git`, actually attempting **SSH** (`ssh -o SendEnv=GIT_PROTOCOL git@github.com git-upload-pack ...`), a protocol this sandbox's network policy does not appear to permit outbound at all. The stuck container was killed and the partial, uncommitted `vendor/` directory removed — nothing from this attempt was ever staged or committed.
- `docker compose -p company-app -f docker-compose.staging.yml --env-file <temp, uncommitted placeholder> config` — **re-run after both fixes above and still succeeds**, with the same correct `mysql-data: name: company-app_mysql-data` resolution as before. Temporary placeholder files were deleted immediately after and were never committed.

**Honest conclusion, per the explicit instruction not to falsely claim a build passed:** the staging **Nginx** image is now genuinely build-verified, not merely reviewed by hand. The staging **application** image's `COPY`/Composer-flag ordering was reviewed by hand for correctness (unchanged by this round — the review found no defect in it, only in the separate `nginx`/runbook issues above) but remains **not build-tested**, because this sandbox's network policy blocks PHP dependency installation by every path attempted (`apt-get`, Composer dist-zip, and Composer git-source/SSH fallback alike) — a pre-existing, environment-level restriction this repository has documented since Phase 4A and reproduced by nearly every phase session since, not a defect introduced by Phase 24.

### What remains for the VPS operator (supersedes Addendum 1's list where it differs)

1. Back up the ad-hoc deployment's database, then migrate to the new Compose file (`docs/DEPLOYMENT_STAGING.md` §10) — preserving `company-app_mysql-data`.
2. Create the two real `.env`/`.env.staging` files from the committed `.example` templates, with generated secrets.
3. Build the staging images for real, on a host with normal network access (this will be the **first actual build** of `docker/php/Dockerfile.staging` anywhere, staging or otherwise — treat the first `docker compose ... build` as a real test, and check `docker compose ... logs app` immediately if it fails).
4. Generate `APP_KEY` via `php artisan key:generate --show` (never plain `key:generate` — see §2 above and `docs/DEPLOYMENT_STAGING.md` §4), place it into the real host-side `apps/api/.env`, then start/restart the stack.
5. Check container status (`docker compose ... ps` — all three `Up`/`healthy`), then issue a real HTTP request through nginx to confirm the Nginx → PHP-FPM → Laravel path actually answers (`curl http://<host>:8012/api/v1/health`) — this is the first environment where that full path can be exercised for real, since it could not be in this sandbox.
6. Run migrations, only `RolePermissionSeeder`, create a real Administrator account via Tinker (never `AdminUserSeeder`).
7. Run the cache commands and the full verification checklist (`docs/DEPLOYMENT_STAGING.md` §8).
8. Decide a staging domain, obtain a TLS certificate, open UFW for 8012 (and later 8442), add the HTTPS nginx configuration, then — and only then — flip `SESSION_SECURE_COOKIE=true`.

### Files changed in this correction round

`docker/nginx/Dockerfile.staging` (bakes in real `public/` contents), `docs/DEPLOYMENT_STAGING.md` (§4 rewritten for the read-only-mount-safe `APP_KEY` workflow; a new post-merge server verification sequence), `docs/DECISIONS.md` (DEC-047 Correction paragraph), this document (Addendum 2), and `docs/handoffs/V1_PHASE_24_HANDOFF.md` (updated to reflect both corrections and the real build verification results). No other file was touched — the Compose file, environment templates, `.dockerignore`, and the application Dockerfile's own command sequence are unchanged by this round.

---

## Addendum 3: Real VPS Deployment Verification (2026-09-14)

PR #28 (the repository implementation from Addenda 1–2) was reviewed, its CI passed, and it was **merged into `main`** at merge commit `eb227826aea776a303e05126743fa9c056ea2852`. The product owner then executed `docs/DEPLOYMENT_STAGING.md` against the real staging VPS and reported the results below. **This addendum is a documentation update only — no application code, migration, Docker asset, or security control was changed as part of recording it**, and no AI session performed or witnessed the deployment itself; the facts below are as reported by the product owner.

### Deployed commit and host

- Deployed from `main` at merge commit `eb227826aea776a303e05126743fa9c056ea2852` ("Merge pull request #28 — Phase 24: Staging Deployment").
- VPS: Ubuntu 24.04.4 LTS, Docker 29.8.0, Docker Compose v5.5.1, 2 vCPU, ~3.8 GiB RAM — matching the specs discovered in Addendum 1 (the earlier ~4 GB figure was an approximation; ~3.8 GiB is the confirmed real value).

### VERIFIED — the formal Phase 24 staging stack is running and correct

- **Containers running the new, dedicated staging images** (not the ad-hoc bind-mounted deployment): `company-app-api` (image `company-app-api:staging`), `company-app-nginx` (image `company-app-nginx:staging`), `company-app-mysql` (image `mysql:8.4`, running `8.4.11`). This closes the one thing this project's own sandbox could not itself prove (Addendum 2): the staging application image **does build successfully** in a real environment with normal network access — the sandbox's `apt-get`/Composer network-policy limitation was environment-specific, not a defect in `docker/php/Dockerfile.staging`.
- **Service topology exactly as designed:** `app` (PHP-FPM) listens on port 9000 internally only; `nginx` maps host port `8012` → container port `80`; `mysql` exposes **no host port at all** (Docker-network-only, the "preferably" option DEC-047 specified) — confirmed, not merely configured.
- **`company-app_mysql-data` was preserved and reused, not replaced** — exactly as DEC-047/the runbook's §10 designed: a pre-cutover `mysqldump` backup was taken first, no Docker volume was deleted, `docker compose down -v` was never used, and the existing volume was picked up by the new stack via the project-name/volume-key matching mechanism this session verified statically (`docker compose config`) and the real deployment now verifies dynamically. The existing database (`DB_DATABASE=company_app`, `DB_USERNAME=company_app` — the ad-hoc deployment's own original names, not the `_staging`-suffixed example names `apps/api/.env.staging.example` illustrated) survived the cutover intact.
- **Laravel runtime configuration confirmed correct via `php artisan about`:** Laravel 13.31.0, PHP 8.4.25, `environment: staging`, `debug: OFF`, MySQL database driver, database cache/session/queue drivers — matching every staging-specific value `apps/api/.env.staging.example` specifies (`APP_ENV=staging`, `APP_DEBUG=false`) and every value this project deliberately left unchanged from local dev (`CACHE_STORE`/`QUEUE_CONNECTION`/`SESSION_DRIVER=database`).
- **`php artisan migrate:status` confirmed all 45 migrations already `Ran`** — no migration execution was required during this cutover, exactly as `docs/DEPLOYMENT_STAGING.md` §6 anticipated for a database inherited from the ad-hoc deployment rather than a fresh one.
- **Runtime HTTP smoke tests passed, from the VPS itself:** `GET http://127.0.0.1:8012/api/v1/health` → `200`, `{"data":{"status":"ok",...}}`; `GET /` → `200`. Laravel's own `/up` route was not separately reported but `GET /` returning `200` through the full nginx→PHP-FPM→Laravel path confirms the same routing chain `docs/phases/V1_PHASE_24_STAGING_DEPLOYMENT_PLAN.md` Addendum 2 could only reason about, not execute.
- **Data/application-level verification:** `Users: 3`, `Administrators: 1`; the existing Administrator account (preserved from the ad-hoc deployment's database, not freshly created via the runbook's Tinker fallback — unnecessary here since a real account already existed) was tested and logs in successfully on staging.
- **Security posture confirmed as designed, not merely configured:** `APP_DEBUG=false` (confirmed via `php artisan about`'s `debug: OFF`, not just the `.env` file), MySQL has no host-exposed port, `SESSION_SECURE_COOKIE=false` remains intentional (correct, since TLS still doesn't exist — flipping it now would have been a regression, not a fix), the existing Laravel `APP_KEY` and database credentials were preserved rather than rotated mid-cutover (a reasonable, deliberate operator choice for migrating a live system's deployment mechanism without invalidating existing encrypted data/sessions — not a deviation this document treats as a defect).
- **Pickleverse (the VPS's other, unrelated project) was not touched**, consistent with DEC-047's host-port-reservation design never assuming or requiring exclusive host access.

### Deviations from the runbook's illustrative example values (expected, not defects)

`docs/DEPLOYMENT_STAGING.md`/`apps/api/.env.staging.example` illustrated generating **fresh**, uniquely-named credentials (`company_app_staging`) and a fresh `APP_KEY` for a green-field staging setup. The real operation performed was a **cutover of an already-running system** to the new deployment mechanism, for which preserving the existing database name/credentials/`APP_KEY`/data is the correct choice (rotating any of them mid-migration would have invalidated existing encrypted values or working access for no benefit). This is recorded here so a future reader doesn't mistake the deployed reality for a deviation from — rather than a sensible adaptation of — the documented plan.

### Explicitly deferred — NOT verified, NOT claimed complete

- **No TLS/HTTPS.** Port 8442 remains reserved but unmapped/unconfigured — exactly as designed, not a gap in this deployment.
- **No public internet exposure.** UFW on the VPS still does not expose port 8012 (or 8442) publicly — every smoke test reported above was run from `127.0.0.1` on the VPS itself. **Staging is not reachable from outside the VPS yet.**
- **No mobile-client verification.** No Flutter build was run against the staging `API_BASE_URL`, and doing so meaningfully would require public reachability first (above) — this remains fully open, not attempted.
- **`public/storage` is not symlinked** on the staging deployment. Per the product owner's explicit instruction, this is recorded as an observation only and **not silently changed** — nothing in Phase 24's own requirements depends on it: attachments (Service/Incident Reports) are served exclusively through authenticated controller routes against the private `attachments` disk (`storage/app/private`, `config/attachments.php`), never through the `public` disk or `public/storage`, so this has no functional effect on anything Phase 24 built. If a future phase ever needs the `public` disk (e.g. a genuinely public asset), linking it is that phase's own decision, not a Phase 24 follow-up.
- **Database/APP_KEY credential strength was not independently re-verified by this documentation session** — they were preserved from the pre-existing ad-hoc deployment (see above); auditing or rotating them, if ever warranted, is a separate, future, explicitly-scoped decision, not something this session should do silently while merely updating documentation.
- **A DB-aware `/api/v1/health`** remains unbuilt, per Phase 24's own original, explicit exclusion — unaffected by this addendum.

### Files changed in this documentation-closure round

This document (Addendum 3), `docs/handoffs/V1_PHASE_24_HANDOFF.md`, `docs/DECISIONS.md` (DEC-047's verification note), `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/testing/TEST_STATUS.md`, and `docs/testing/UAT_LOG.md`. **No application code, migration, Docker asset (`docker-compose.staging.yml`, either Dockerfile, `.dockerignore`), or environment template was changed** — nothing here needed to change now that the real deployment has confirmed they work as designed.
