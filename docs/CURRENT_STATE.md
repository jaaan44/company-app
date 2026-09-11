# CURRENT STATE

*Read this first. Kept intentionally short — for depth, follow the pointers, don't expect this file to contain everything.*

**Product:** Company App — internal operations & communication platform
**Current phase:** Phase 5 — Roles & Permissions
**Phase status:** COMPLETE (pending user review)
**Last completed phase:** Phase 5 (Phases 1–4A are merged into `main`)
**Next planned phase:** Phase 6 — Organization Structure (see `ROADMAP.md`) — **not authorized yet**

## Current Objective

Phase 5 is implemented, tested, and pushed for review. Awaiting authorization for Phase 6.

## Completed

- **Phase 0:** Full documentation/governance foundation.
- **Phase 1:** Monorepo bootstrap — `apps/api` (Laravel) and `apps/mobile` (Flutter). Merged into `main`.
- **Phase 2:** Development environment & CI (Larastan, GitHub Actions). Merged into `main`.
- **Phase 3:** Core architecture — `/api/v1` routing, health endpoint, MySQL production direction (DEC-016), numeric+ULID identifier strategy (DEC-017), Blade+Livewire Admin direction (DEC-019). Merged into `main`.
- **Phase 4:** Authentication — Admin session/cookie login (Blade+Livewire), Sanctum bearer tokens for the mobile API, account states enforced centrally, full Flutter auth flow. Merged into `main`. See `docs/handoffs/V1_PHASE_04_HANDOFF.md`.
- **Phase 4A:** Docker Development Environment. See `docs/handoffs/V1_PHASE_04A_HANDOFF.md` for full detail.
  - `docker-compose.yml` (repo root) — three services: `nginx`, `app` (PHP-FPM 8.4), `mysql` (8.4). No Redis, queue worker, scheduler, WebSocket server, or other always-on infrastructure.
  - MySQL data persists in a named volume; `vendor/` is a separate named volume isolating the container's Composer install from the host bind mount.
  - `apps/api/.env.docker.example` added for Docker-specific config (`DB_HOST=mysql`).
  - Verified in this session via a genuinely running Docker stack: build, MySQL healthcheck, Laravel↔MySQL connectivity, all 5 migrations, the Admin seeder, the full 31-test Authentication suite, `vendor/bin/pint`, `vendor/bin/phpstan analyse`, `/api/v1/health` and `/login` through Nginx — all passing. Two real bugs were found and fixed during this validation (an entrypoint permissions bug and an `env_file` misconfiguration that silently defeated test-database isolation) — see the handoff.
  - DEC-027 records Docker Compose as the new standard local backend environment, formally superseding DEC-013 (not deleted — marked superseded per `DECISIONS.md`'s own rules).
  - Flutter remains entirely outside Docker, unaffected.
  - No RBAC or other business functionality was introduced.
  - **Windows UAT (product owner, post-handoff):** an initial `.gitattributes`-related CRLF failure was found and fixed (`docker/php/entrypoint.sh` checked out as CRLF, breaking its shebang) — see handoff §26. A full retest then passed: Docker stack, first-time setup (`composer install`, `key:generate`, migrations, `AdminUserSeeder`), `/api/v1/health`, and the full Admin + Flutter authentication flow against the Dockerized API. Recorded in `docs/testing/UAT_LOG.md`.
  - **Port reconfiguration (product owner request, post-handoff):** both host ports made configurable — application `APP_PORT` (default `8012`, was fixed `8000`), MySQL `MYSQL_PORT` (default `3347`, was fixed `3306` — host-tool access only, never affects Laravel's internal `DB_HOST=mysql`/`DB_PORT=3306` connection). See handoff §27.
- **Phase 5:** Roles & Permissions. See `docs/handoffs/V1_PHASE_05_HANDOFF.md` for full detail.
  - `roles`, `permissions`, `role_permissions` tables; `App\Models\Role`/`App\Models\Permission`. **One role per user** (`users.role_id`, nullable FK) — Administrator, Manager, Staff.
  - The Phase 4 transitional `users.is_admin` boolean (DEC-024) is retired — dropped, with pre-existing `is_admin = true` rows migrated onto the Administrator role.
  - Centralized authorization: a single `Gate::before` override (`App\Providers\AppServiceProvider::boot()`) grants Administrator every ability; everyone else resolves against their role's attached permissions, defaulting to deny. Works through Laravel's real Gate, so `can` middleware/`Gate::allows()`/`@can`/future Policies all work against permission names with no per-permission boilerplate.
  - Admin Backoffice access chain: `auth` → `account.active` → `can:admin.access` → `/home`.
  - Foundational permission catalog only (`admin.access`, `authorization.manage`) — Manager/Staff intentionally hold no permissions yet.
  - `Database\Seeders\RolePermissionSeeder` (new, idempotent, run in every environment); `AdminUserSeeder` updated to assign the Administrator role.
  - Recorded DEC-028, superseding DEC-024.
  - No Staff/Clients/Projects/Leave/Tasks/Work Logs/Messaging or other business module was introduced; no third-party RBAC package; no many-to-many user↔role infrastructure.

## Pending / Not Started

- Organization Structure (Phase 6) and everything after it on the roadmap.

## Known Blockers / Issues

- **This session's environment:** Docker's own image-pull path worked (via `mirror.gcr.io`, a legitimate Docker Hub mirror, when the default registry endpoint was blocked), but package installation *during* an image build (`apt-get`, reaching `deb.debian.org`) is blocked by this sandbox's network policy — confirmed as a real, deliberate block, not a transient failure. Worked around for validation purposes only (a temporary, uncommitted Dockerfile variant skipping just that one step) without weakening the real, committed Dockerfile, which still includes the `apt-get` step real developers and CI-less environments need. See `docs/handoffs/V1_PHASE_04A_HANDOFF.md` for the full account — this does not affect the correctness of what was committed.
- Open design questions: real-time transport, object storage provider, Departments/Teams hierarchy shape — see `docs/02_ARCHITECTURE.md` §9.

## Repository / Branch Information

- Repository: `jaaan44/company-app`
- Default branch: `main` (contains the approved Phase 0–4A baseline)
- Phase 5 branch: `claude/wonderful-darwin-whxeyg` (branched from `main`, not merged)

## Latest Relevant Handoff

`docs/handoffs/V1_PHASE_05_HANDOFF.md`

## For the Next Session

Read `CLAUDE.md`, then this file, then `docs/ROADMAP.md`, then `docs/handoffs/V1_PHASE_05_HANDOFF.md` for Roles & Permissions, `docs/handoffs/V1_PHASE_04A_HANDOFF.md` for the Docker environment, and `docs/handoffs/V1_PHASE_04_HANDOFF.md` for Authentication. Phase 6 (Organization Structure) needs explicit user authorization before any implementation starts — do not begin it based on the roadmap alone.
