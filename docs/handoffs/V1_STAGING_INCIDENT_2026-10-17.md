# Staging Incident — 2026-10-17

**Status:** staging restored, and the seeded account locked. Remaining items are listed in §5.

## 1. What happened

- **The request:** the product owner asked how to review the Phase 37C Home redesign in a real browser.
- **The mistake:** the AI session gave instructions for a local machine (`README.md` "Backend (Docker)"), forgetting that the only running copy is the staging VPS.
- **What ran on the VPS,** as `deploy`, in `/home/deploy/company-app/company-app-build/company-app`:
  - `git checkout claude/mobile-home-redesign` (`f0e95fb`);
  - `cp .env.docker.example .env` in `apps/api`;
  - `npm ci && npm run build`;
  - `docker compose up -d --build`;
  - `key:generate`, `composer install`, `migrate`;
  - `db:seed --class=AdminUserSeeder`.

## 2. Effect

- **Containers:**
  - `docker-compose.yml` resolved to project `company-app` (the folder name), the same as staging's `-p company-app`, with the same container names;
  - `up` replaced `company-app-api`, `company-app-nginx` and `company-app-mysql` with the development ones (`nginx:1.27-alpine` on `0.0.0.0:8012`; MySQL `127.0.0.1:3347`).
- **Configuration:**
  - staging bind-mounts `apps/api/.env` as its runtime config, and the copy overwrote it (timestamp 06:13);
  - `key:generate` gave it a new key.
- **Database:**
  - the development MySQL container started on staging's existing `company-app_mysql-data` volume; the data was intact (init variables are ignored on an existing data directory);
  - the development login (`company_app`, 6-character password) is the same as staging's legacy one, and `APP_ENV=local` let `AdminUserSeeder` run;
  - `admin@example.test` was created, or reset, as an Administrator with the password `password`.
- **The site:** `https://company-staging.storm-ark.com/login` returned **500**.

## 3. Recovery (operator, output pasted)

1. `docker compose -p company-app -f docker-compose.yml down` (no `-v`) and `docker volume rm company-app_vendor`. The data volume was kept.
2. `apps/api/.env` was rebuilt from `apps/api/.env.staging.example`:
   - `DB_PASSWORD` copied from `.env.staging`, never printed;
   - `APP_URL=https://company-staging.storm-ark.com`, `SESSION_SECURE_COOKIE=true`, a new `APP_KEY`, `SCHEDULING_COMPANY_TIMEZONE=Asia/Manila`.

   The first attempt took `DB_DATABASE`/`DB_USERNAME` from the template (`company_app_staging`): access denied. Diagnosis showed staging's real login is the legacy `company_app`. Those two values were then copied from `.env.staging`.
3. The staging images were built and started on `f0e95fb` with `docker-compose.staging.yml`, and the caches rebuilt. Migrations: last `2026_10_16_000000…` Ran, nothing pending.
4. **Smoke tests:** `login 200`; `app` and `admin` `302 -> /login`; `api unread-count 401`.
5. `apps/api/.env` was backed up to `/home/deploy/backups/app-env-<date>.bak` (mode 600).

**Consequence:** the new `APP_KEY` signs everyone out, including "Keep me signed in". No model uses encrypted columns, so no data was lost.

## 4. Prevention (DEC-070)

- `docker-compose.yml` is project `company-app-dev`. Its app container refuses to start in a checkout with `.env.staging`, or when `apps/api/.env` says `APP_ENV=staging/production`.
- The README and `DEPLOYMENT_STAGING.md` carry explicit warnings.
- **For AI sessions:** this project's only running environment is the staging VPS. "Try it in a browser" means a staging deploy through the runbooks, never the local setup.

## 5. Pending

- **`admin@example.test`: done** (output pasted).
  - The account existed before today: created 2026-09-11 10:25:10, from the Phase 5 check against this database (UAT-05-01). Today's seed reset its password at 2026-10-17 06:13:20.
  - Now: `suspended`, with a random 48-character password; its sessions and API tokens are ended. `/login` returns 200.
  - It is kept rather than deleted, so its audit history stays intact.
- **Staging runs the unmerged branch `f0e95fb`** (37B and 37C) for review. After PR #141 is merged, deploy `main` with the runbook.
- **Rotate staging's legacy 6-character database password** in a planned change.
