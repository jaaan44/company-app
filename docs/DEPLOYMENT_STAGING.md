# Company App — Staging Deployment Runbook (Phase 24)

This document is a step-by-step runbook for deploying Company App to the staging VPS using the Phase 24 repository assets. It complements, and does not duplicate, `docs/phases/V1_PHASE_24_STAGING_DEPLOYMENT_PLAN.md` (the discovery/planning document — read it first for *why* each decision was made) and `docs/DECISIONS.md` DEC-047.

**Everything in this document is a proposal for the VPS operator to execute.** No command in this file has been run against the real staging VPS by any AI session — Claude Code has no access to that host. Every code block below is labeled either **[Repository — already done]** (an artifact this phase already committed, nothing to run) or **[Run on the VPS]** (a command the user must execute themselves, on the real staging host).

---

## 0. What Phase 24 already put in the repository

**[Repository — already done]** — nothing to run for this section.

| File | Purpose |
|---|---|
| `docker/php/Dockerfile.staging` | Builds the staging application image — bakes source + a `--no-dev` Composer install into the image (no bind mount). |
| `docker/nginx/Dockerfile.staging` | Builds a minimal staging Nginx image reusing `docker/nginx/default.conf` as-is. |
| `docker-compose.staging.yml` | The dedicated staging Compose file (`app`/`nginx`/`mysql`, no dev bind mounts). |
| `.env.staging.example` (repo root) | Template for `.env.staging` — Compose's own DB-credential substitution file. |
| `apps/api/.env.staging.example` | Template for `apps/api/.env` — Laravel's real staging runtime config. |
| `.dockerignore` (repo root) | Keeps secrets/VCS metadata/dev-only files out of every image built from this repository. |

None of the two real `.env`/`.env.staging` files exist in the repository — they are created by the VPS operator, from the two example files above, in §2 below.

**Always include `--env-file .env.staging` on every `docker compose -p company-app -f docker-compose.staging.yml ...` invocation** — `build`, `up`, `restart`, and, for consistency, read-only commands like `ps`/`logs`/`config` too. Compose only auto-loads a file literally named `.env` in the invocation directory; `.env.staging` (deliberately differently named, to avoid any ambiguity with the unrelated `apps/api/.env`) is never loaded automatically. Running `ps` without the flag produces a harmless "variable not set" warning while resolving the `${DB_*}` placeholders it displays — it does not affect already-running containers, whose environment was fixed at creation time by an earlier `up --env-file .env.staging` — but the habit of always including it avoids ever running `build`/`up`/`restart` without it by mistake, which would attempt to (re)create `mysql` with empty credential values.

---

## 1. First deployment vs. redeploying an existing one

If Company App has never run on this host via the Phase 24 assets before, follow §2 onward in order. If a Phase 24 deployment already exists and you are pushing a new release, skip to §9 (Upgrade / Redeploy). **If what currently exists is the pre-Phase-24 ad-hoc bind-mounted deployment** (`/home/deploy/company-app/company-app-build/company-app/docker-compose.yml`, containers `company-app-api`/`company-app-nginx`/`company-app-mysql` on port 8012), **read §10 (Migrating from the ad-hoc deployment) before doing anything else** — it must run before §2, not after.

---

## 2. Repository checkout / update

**[Run on the VPS]**

```sh
# First time:
cd /home/deploy
git clone https://github.com/jaaan44/company-app.git
cd company-app

# Subsequent deploys (see also §9):
cd /home/deploy/company-app
git fetch origin
git checkout <release commit or tag>   # pin a specific commit — never deploy a floating branch tip unverified
```

---

## 3. Create the real staging environment files

**[Run on the VPS]** — these two files are never committed; create them once, then edit in place for future changes (never re-copy the `.example` over an existing real file).

```sh
cd /home/deploy/company-app

cp .env.staging.example .env.staging
cp apps/api/.env.staging.example apps/api/.env
```

Now edit both files:

- **`.env.staging`** (repo root): replace `DB_PASSWORD`/`DB_ROOT_PASSWORD` with two different, high-entropy generated values (e.g. `openssl rand -base64 32` each). Leave `DB_DATABASE`/`DB_USERNAME` as-is or choose your own — just make sure step 4 below uses the *same* values in both files.
- **`apps/api/.env`**: set `DB_PASSWORD` to the **exact same value** you just put in `.env.staging`'s `DB_PASSWORD` (not the root password — the application connects as the application user, not root). Replace `APP_URL` with the real reachable URL (`http://<VPS-IP-or-domain>:8012` until TLS exists — see §12). Leave `APP_KEY` blank; §4 generates it. Review every other `REPLACE_WITH_*` placeholder.

```sh
chmod 600 .env.staging apps/api/.env   # readable only by the deploying user — these are real secrets now
```

---

## 4. Generate `APP_KEY`

**[Run on the VPS]** — `apps/api/.env` is bind-mounted **read-only** into the `app` container (`docker-compose.staging.yml`, `:ro`), by design (§0/§7 of the planning document — nothing running inside a container should be able to rewrite the host's real secrets file). This makes the ordinary `php artisan key:generate` **unsafe to run as-is here**: with no `--show`, it tries to open `.env` for writing and fails (`file_put_contents(): Failed to open stream: Read-only file system`) — a broken deploy step, not a working one. Always use `--show`, which prints the generated key and returns **before** attempting any file write, and place the value into the real file yourself, on the host side:

```sh
docker compose -p company-app -f docker-compose.staging.yml build app
docker compose -p company-app -f docker-compose.staging.yml run --rm app php artisan key:generate --show
```

This prints a line like `base64:XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX=`. Copy that exact value — never a value from this document, never a value used in any other environment — into `apps/api/.env` on the VPS host filesystem (not inside any container):

```sh
sed -i "s|^APP_KEY=.*|APP_KEY=base64:REPLACE_WITH_THE_VALUE_JUST_PRINTED|" apps/api/.env
```

(Edit the line by hand instead of `sed` if you prefer — either way, the edit happens directly on the host file, never through a write attempt inside the read-only-mounted container.) The application container picks up the new value the next time it's (re)started (§5/§9) — `key:generate --show` itself does not need, and does not perform, a container restart.

**Never commit the generated value, print it in a commit message or PR description, or paste it into any file under version control** — it is a real secret from the moment it's generated.

---

## 5. Build and start the staging stack

**[Run on the VPS]**

```sh
cd /home/deploy/company-app
docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging build
docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging up -d
docker compose -p company-app -f docker-compose.staging.yml ps
```

All three services (`company-app-api`, `company-app-nginx`, `company-app-mysql`) should show `Up`/`healthy`. If `mysql` doesn't reach `healthy` within ~50 seconds (10 retries × 5s), check `docker compose -p company-app -f docker-compose.staging.yml logs mysql` before proceeding — do not run migrations against a database that isn't actually up yet.

`-p company-app` is not optional — it's what makes the `mysql-data`/`app-storage` volumes resolve to the `company-app_*` names (see §10 if migrating from the ad-hoc deployment, and `docker-compose.staging.yml`'s own header comment).

**Smoke-test the Nginx → PHP-FPM → Laravel path immediately** — this exact path could not be verified in the sandbox this repository's own Docker images were developed in (no outbound network access there for PHP dependency installation; see `docs/phases/V1_PHASE_24_STAGING_DEPLOYMENT_PLAN.md`'s Addendum 2), so this VPS is the first place it can actually be exercised end-to-end:

```sh
curl -i http://127.0.0.1:8012/api/v1/health
```

Expect `HTTP/1.1 200 OK` with `{"data":{"status":"ok","timestamp":"..."}}`. **If this fails** (connection refused, 502/504, or anything other than a clean 200):

```sh
docker compose -p company-app -f docker-compose.staging.yml ps                 # which container is actually unhealthy/exited?
docker compose -p company-app -f docker-compose.staging.yml logs app           # PHP-FPM/Laravel-side errors (e.g. a missing APP_KEY, a DB connection failure)
docker compose -p company-app -f docker-compose.staging.yml logs nginx         # e.g. "host not found in upstream" (app not yet up/on the network), permission errors
docker compose -p company-app -f docker-compose.staging.yml logs mysql        # if app's own logs point at a DB connectivity failure
```

Do not proceed to §6 (migrations) until this smoke test passes — a database migration against a stack that isn't actually serving requests correctly yet risks masking the real problem.

---

## 6. Migrations and seeding

**[Run on the VPS]**

```sh
docker compose -p company-app -f docker-compose.staging.yml exec app php artisan migrate --force
```

`--force` is required because `APP_ENV=staging` is not `local` — Laravel prompts for confirmation on a non-local environment otherwise.

**Seeding — only `RolePermissionSeeder`, per repository evidence:**

```sh
docker compose -p company-app -f docker-compose.staging.yml exec app php artisan db:seed --class="Database\Seeders\RolePermissionSeeder" --force
```

`RolePermissionSeeder` is documented (`CLAUDE.md` §5, every phase handoff since Phase 5) as idempotent and required in every environment — safe to run here.

**Do NOT run `AdminUserSeeder` against staging.** It creates a hardcoded, publicly-known account (`admin@example.test` / `password`) — appropriate only for an isolated local dev database, never for a reachable host. Instead, create the first real Administrator account directly:

```sh
docker compose -p company-app -f docker-compose.staging.yml exec app php artisan tinker
```

Inside Tinker:

```php
$user = \App\Models\User::create([
    'name' => 'Staging Admin',
    'email' => 'REPLACE_WITH_A_REAL_STAGING_EMAIL',
    'password' => \Illuminate\Support\Facades\Hash::make('REPLACE_WITH_A_REAL_GENERATED_PASSWORD'),
    'status' => \App\Enums\AccountStatus::Active,
    'role_id' => \App\Models\Role::where('name', \App\Models\Role::ADMINISTRATOR)->value('id'),
]);
```

This is the smallest safe option today because no User-management mutation API/CLI exists yet (a documented, pre-existing gap — DEC-044's Known Limitation, unaffected by Phase 24). Record the credentials in whatever secrets store the product owner uses — never in the repository, never in this document, never in a commit message.

---

## 7. Laravel cache/config commands

**[Run on the VPS]** — run after every deploy that changes config/routes/views (i.e., every deploy):

```sh
docker compose -p company-app -f docker-compose.staging.yml exec app php artisan config:cache
docker compose -p company-app -f docker-compose.staging.yml exec app php artisan route:cache
docker compose -p company-app -f docker-compose.staging.yml exec app php artisan view:cache
```

These are not used in local development (Phase 4A) but are standard, low-risk practice for a non-local environment. If you ever change `apps/api/.env` after caching config, run `php artisan config:cache` again — `config:cache` freezes the `.env` values it read at cache time.

---

## 8. Verification

**[Run on the VPS, or from any machine that can reach the VPS on port 8012]**

```sh
curl -i http://<VPS-IP-or-domain>:8012/api/v1/health
# Expect: HTTP/1.1 200 OK, {"data":{"status":"ok","timestamp":"..."}}

curl -i http://<VPS-IP-or-domain>:8012/up
# Expect: HTTP/1.1 200 OK (Laravel's own framework-level health route)

docker compose -p company-app -f docker-compose.staging.yml exec app php artisan migrate:status
# Expect: all 45 migrations listed as "Ran"

docker compose -p company-app -f docker-compose.staging.yml exec app composer audit --locked
# Expect: no vulnerabilities found (Phase 22/F-10's standing check, re-run against the exact deployed lockfile)
```

**Admin Backoffice:** visit `http://<VPS-IP-or-domain>:8012/login`, sign in with the account created in §6, confirm redirect to `/home`.

**API (mobile-equivalent) login:**

```sh
curl -i -X POST http://<VPS-IP-or-domain>:8012/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"REPLACE_WITH_STAGING_ADMIN_EMAIL","password":"REPLACE_WITH_STAGING_ADMIN_PASSWORD"}'
# Expect: 200 with a Sanctum token; use it as `Authorization: Bearer <token>` against e.g. GET /api/v1/staff
```

**Confirm `APP_DEBUG=false` is actually in effect** (do this once, deliberately):

```sh
curl -i http://<VPS-IP-or-domain>:8012/api/v1/this-route-does-not-exist
# Expect: a plain 404 JSON error, NOT a Laravel debug/stack-trace page
```

**Logs:**

```sh
docker compose -p company-app -f docker-compose.staging.yml logs -f app nginx mysql   # container stdout/stderr
docker compose -p company-app -f docker-compose.staging.yml exec app tail -f storage/logs/laravel.log   # Laravel's own log file
```

**Queues/scheduler:** intentionally not part of this checklist — no queue worker or scheduler exists anywhere in this application (`02_ARCHITECTURE.md` §5/§6, `routes/console.php` defines no scheduled commands). This is a correct "N/A," not an unverified item.

---

## 9. Restart / stop / start / upgrade / redeploy

**[Run on the VPS]**

```sh
# Restart everything (e.g. after an .env change that config:cache alone doesn't cover):
docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging restart

# Stop (containers removed, volumes untouched):
docker compose -p company-app -f docker-compose.staging.yml down

# Start again:
docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging up -d
```

**Upgrade / redeploy a new release:**

```sh
cd /home/deploy/company-app
git fetch origin
git checkout <new release commit or tag>

# Back up the database first — see §11 (Rollback):
docker compose -p company-app -f docker-compose.staging.yml exec mysql \
  sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' > /home/deploy/backups/company-app-$(date +%Y%m%d-%H%M%S).sql

docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging build app nginx
docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging up -d
docker compose -p company-app -f docker-compose.staging.yml exec app php artisan migrate --force
docker compose -p company-app -f docker-compose.staging.yml exec app php artisan config:cache
docker compose -p company-app -f docker-compose.staging.yml exec app php artisan route:cache
docker compose -p company-app -f docker-compose.staging.yml exec app php artisan view:cache
```

Then repeat the §8 verification checklist. **Never run `docker compose down -v`** as part of a routine redeploy — `-v` removes named volumes, including `company-app_mysql-data` and `company-app_app-storage`.

---

## 10. Migrating from the ad-hoc deployment (do this once, before first use of the Phase 24 assets)

The preliminary deployment at `/home/deploy/company-app/company-app-build/company-app/docker-compose.yml` bind-mounts live source — the development-oriented Compose file, running here as an ad-hoc/preliminary deployment, not the intended Phase 24 staging architecture. It must be replaced, not left running alongside the new stack (both would fight over the same container names and port 8012).

**Step 1 — back up the database, before touching anything:**

```sh
cd /home/deploy/company-app/company-app-build/company-app
docker compose exec mysql sh -c 'mysqldump -uroot -psecret company_app' > /home/deploy/backups/pre-phase24-migration-$(date +%Y%m%d-%H%M%S).sql
ls -la /home/deploy/backups/   # confirm the dump is non-empty before proceeding
```

(The dev Compose file's MySQL root password is the documented, self-acknowledged dev-only value `secret` — Phase 22/F-09 — used here one last time, only to take this backup.)

**Step 2 — identify the existing volume (confirm before assuming):**

```sh
docker volume ls | grep mysql-data
# Expect a line naming `company-app_mysql-data` (the ad-hoc deployment's own
# Compose project is named `company-app` because that is the directory
# containing its docker-compose.yml — Compose's default project-naming rule).
docker volume inspect company-app_mysql-data
```

**Step 3 — stop the ad-hoc deployment WITHOUT removing volumes:**

```sh
cd /home/deploy/company-app/company-app-build/company-app
docker compose down
# Do NOT add -v. Confirm the volume still exists:
docker volume ls | grep mysql-data
```

**Step 4 — check for any real uploaded attachments in the ad-hoc deployment's bind-mounted storage** (the ad-hoc deployment has no Docker volume for attachments at all — they'd be sitting directly on the host filesystem, unlike the new stack's `app-storage` volume):

```sh
find /home/deploy/company-app/company-app-build/company-app/apps/api/storage/app/private -type f
```

If this lists any real files (Service/Incident Report attachments), copy them into the new `app-storage` volume *after* §5's first `up` has created it:

```sh
docker run --rm \
  -v company-app_app-storage:/dest \
  -v /home/deploy/company-app/company-app-build/company-app/apps/api/storage/app/private:/src:ro \
  alpine sh -c 'cp -a /src/. /dest/'
```

If the `find` command lists nothing (likely — this was a health-check-only preliminary deployment), skip this step entirely.

**Step 5 — deploy the new stack from the "real" repository checkout** (per §2 — this should be a separate directory from the ad-hoc deployment's `company-app-build` path, or the same one with the ad-hoc `docker-compose.yml` no longer used; either is fine as long as §5's `up` runs with `-p company-app` from a directory containing the new `docker-compose.staging.yml`):

Follow §3 through §8 above. Because `docker-compose.staging.yml`'s `mysql-data` volume has no explicit `name:` override and you invoke Compose with `-p company-app` (as instructed throughout this document), Docker Compose will resolve it to `company-app_mysql-data` — the exact volume identified in Step 2 — and **reuse it automatically**, with the existing schema/data intact. **Do not manually create, rename, or copy that volume** — the migration is "use the same project name and volume key," not a data-copy operation.

**Step 6 — verify data survived** before considering the migration complete:

```sh
docker compose -p company-app -f docker-compose.staging.yml exec mysql \
  sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SHOW TABLES; SELECT COUNT(*) FROM users;"' "$MYSQL_DATABASE"
```

Compare row counts against what you expect from the ad-hoc deployment's own prior testing. If anything looks wrong, restore from the Step 1 backup into a fresh volume rather than debugging in place.

---

## 11. Rollback

See `docs/phases/V1_PHASE_24_STAGING_DEPLOYMENT_PLAN.md` §12 for the full policy; the concrete commands:

**Application code:**

```sh
cd /home/deploy/company-app
git checkout <previous-known-good-commit>
docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging build app nginx
docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging up -d
docker compose -p company-app -f docker-compose.staging.yml exec app php artisan config:cache
```

**Database:** restore the most recent pre-deploy backup (§9's redeploy step, or §10 Step 1) rather than relying on `migrate:rollback`'s `down()` methods, which are not guaranteed lossless for every migration:

```sh
cat /home/deploy/backups/<the-relevant-backup>.sql | \
  docker compose -p company-app -f docker-compose.staging.yml exec -T mysql \
  sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
```

**`.env` preservation:** `apps/api/.env` and `.env.staging` are never touched by `git checkout`/redeploys (they are git-ignored, host-only files) — no action needed unless a bad deploy specifically corrupted one, in which case restore your own separately-kept copy (e.g. a copy stored outside the deployment directory).

**Service restart only** (no code/data change needed): `docker compose -p company-app -f docker-compose.staging.yml restart`.

---

## 12. Mobile staging configuration

**[Run on a developer machine — not the VPS]** — no Flutter code change is needed (`apps/mobile/lib/core/config/app_config.dart` already reads `API_BASE_URL` via `--dart-define`, DEC-021):

```sh
cd apps/mobile
flutter run --dart-define=API_BASE_URL=http://<VPS-IP-or-domain>:8012/api/v1
# or, for a build artifact:
flutter build apk --dart-define=API_BASE_URL=http://<VPS-IP-or-domain>:8012/api/v1
```

**Once Phase 26 Gate 2 has verified HTTPS working end-to-end** (§13 below), switch to the approved staging hostname — still no code change, just a different `--dart-define` value:

```sh
flutter run --dart-define=API_BASE_URL=https://company-staging.storm-ark.com/api/v1
flutter build apk --dart-define=API_BASE_URL=https://company-staging.storm-ark.com/api/v1
```

Do not use this HTTPS value until Gate 2's own real-device validation step confirms it actually serves traffic — neither Android nor iOS's default network security policy permits a cleartext (`http://`) fallback on a real device, so this value only ever works once TLS is genuinely live.

---

## 13. TLS / domain / firewall

**Status as of Phase 26 Gate 1: PLANNED / CONFIGURED IN REPOSITORY ONLY — NOT YET DEPLOYED, NOT YET REACHABLE.** The architecture and exact procedure below are approved (DEC-050) and documented in full, gated detail in `docs/phases/V1_PHASE_26_STAGING_MOBILE_CONNECTIVITY_TLS_PLAN.md` §5–§8. **No DNS record, host-Nginx vhost, certificate, or UFW rule exists yet** — this section is a preview of Gate 2's work, not a record of anything executed. Do not describe `company-staging.storm-ark.com` as reachable until Gate 2 is actually run and its own verification steps pass.

**Approved architecture:** TLS terminates at the VPS's existing host-level Nginx (already installed, already terminating TLS for another project on this shared host via Certbot) — not inside this repository's Docker stack. A new `server_name company-staging.storm-ark.com` vhost, proxying over loopback to `127.0.0.1:8012` (this stack's own `nginx` service, loopback-bound as of Gate 1 — §0 above), mirroring the existing sibling site's own `proxy_set_header Host`/`X-Real-IP`/`X-Forwarded-For`/`X-Forwarded-Proto` pattern exactly. Certbot's `--nginx` plugin (already installed on the VPS, already managing the sibling certificate) issues and renews the new certificate via the same, already-active `certbot.timer` — no new renewal mechanism.

**Company App's `docker-compose.staging.yml` change already made (Gate 1):** the `nginx` service now binds `127.0.0.1:8012:80` (was `8012:80`) — defense-in-depth; UFW already blocked public access to 8012 either way. **The former `8442` (HTTPS-inside-Docker) reservation is retired** — it will never be mapped, never receive a firewall rule, and is superseded by the host-Nginx architecture above, not merely still pending.

**Sequenced env-value changes (Gate 2 only, never during Gate 1):**
1. `APP_URL` → `https://company-staging.storm-ark.com`, only after HTTPS is independently verified reachable and valid from an external network.
2. `SESSION_SECURE_COOKIE` → `true`, only after (1) *and* a live confirmation that Laravel correctly detects the original HTTPS scheme through the host-Nginx → loopback → Docker-nginx → PHP-FPM chain (`apps/api/bootstrap/app.php`'s `trustProxies(at: '*')`, added in Gate 1 — see the comment there for why `at: '*'` is safe in this specific, loopback-only topology). Enabling this before both are confirmed would silently break Admin Backoffice login.

**Firewall (Gate 2, not yet done):** UFW opens 443 (and 80, for the ACME challenge/HTTP→HTTPS redirect) — already open on this host for the sibling project, so likely just a new host-Nginx `server_name` block, no new UFW rule. Company App's own port 8012 is never opened to the public internet under this architecture — the host Nginx is the only path in.

The full, step-by-step gated implementation sequence (prerequisite/action/verification/rollback per step) lives in `docs/phases/V1_PHASE_26_STAGING_MOBILE_CONNECTIVITY_TLS_PLAN.md` §8 — this section is a summary, not a replacement for it.
