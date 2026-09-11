# CURRENT STATE

*Read this first. Kept intentionally short — for depth, follow the pointers, don't expect this file to contain everything.*

**Product:** Company App — internal operations & communication platform
**Current phase:** Phase 8 — Clients & Contacts
**Phase status:** COMPLETE (pending user review)
**Last completed phase:** Phase 8 (Phases 1–7 are merged into `main`)
**Next planned phase:** Phase 9 — Staff Status & Location Check-in (see `ROADMAP.md`) — **not authorized yet**

## Current Objective

Phase 8 is implemented, tested, and pushed for review. Awaiting authorization for Phase 9.

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
- **Phase 6:** Organization Structure. See `docs/handoffs/V1_PHASE_06_HANDOFF.md` for full detail.
  - `departments`, `teams`, `positions` tables; `App\Models\Department`/`Team`/`Position`, each with a ULID `public_id` (DEC-017). Shared `App\Enums\OrganizationStatus` (`active`/`inactive`) lifecycle column.
  - Flat Departments (no sub-department hierarchy); Teams/Positions belong to at most one Department (nullable FK) — never a many-to-many span. Team names / Position titles are unique within their department scope, not globally.
  - A Department with any Team or Position still referencing it cannot be deleted (`409`, backed by a `restrictOnDelete()` FK).
  - New permissions `organization.view` (Manager/Staff) and `organization.manage` (Administrator-only, via the existing `Gate::before` override) — no new authorization mechanism.
  - New versioned REST endpoints (`/api/v1/departments`, `/teams`, `/positions`) — full CRUD, route-model-bound by `public_id`, permission-gated, filterable by `status`/`department`.
  - Recorded DEC-029 (organization structure architecture).
  - No Staff/Employee management, Clients, Projects, Leave, Tasks, Messaging, or other later business module; no department hierarchy; no Admin Backoffice CRUD UI (consistent with Phase 5's precedent).
- **Phase 7:** Staff. See `docs/handoffs/V1_PHASE_07_HANDOFF.md` for full detail.
  - `staff` table; `App\Models\Staff`, with a ULID `public_id` (DEC-017) and its own three-state `App\Enums\StaffStatus` (`active`/`inactive`/`separated`) employment lifecycle — distinct from `AccountStatus` and the future Phase 9 operational status.
  - Staff↔User separation (DEC-030): `staff.user_id` nullable and unique — Staff may exist without login access, a User may exist without a Staff record, one User links to at most one Staff. No authentication data duplicated onto `staff`.
  - Staff references Department/Team/Position (nullable FKs, `restrictOnDelete`) with enforced Team/Department consistency (auto-derived when omitted), and a self-referencing `manager_id` with self-reference and bounded-cycle rejection (`Staff::wouldCreateCycleWith()`).
  - `DepartmentController`/`TeamController`/`PositionController::destroy` (Phase 6) extended to also block deletion when Staff reference the record; `StaffController::destroy` blocks deleting a staff member with direct reports.
  - New permissions `staff.view` (Manager/Staff) and `staff.manage` (Administrator-only, via the existing `Gate::before` override).
  - New versioned REST endpoints (`/api/v1/staff`) — full CRUD, route-model-bound by `public_id`, permission-gated, filterable by `status`/`department`/`team`/`position`/`manager` plus a directory `q` search.
  - `StaffResource` is a single Staff Directory shape; the linked User's own identity is only visible to a `staff.manage` holder.
  - Recorded DEC-030.
  - No payroll, attendance, leave, HR documents, performance reviews, project/task assignment, messaging, or client management; no Admin Backoffice CRUD UI (consistent with Phase 6's precedent); no operational/current-status tracking (Phase 9).
- **Phase 8:** Clients & Contacts. See `docs/handoffs/V1_PHASE_08_HANDOFF.md` for full detail.
  - `clients`/`contacts` tables; `App\Models\Client`/`Contact`, each with a ULID `public_id` (DEC-017) and its own two-state lifecycle (`App\Enums\ClientStatus`/`ContactStatus`, both `active`/`inactive`).
  - Client↔Contact relationship (DEC-031): `contacts.client_id` is **required** (never nullable), `restrictOnDelete()` — a Contact always belongs to exactly one Client; no many-to-many Contact↔Client relationship, no client-less contacts.
  - `client_code` is nullable, unique when present, admin-supplied — deliberately different from Staff's required `employee_number`. A small structured inline address on `clients` (no polymorphic/multi-address subsystem).
  - At most one primary Contact per Client (`is_primary`), enforced by `ContactController` inside a DB transaction (clear-then-set), not a DB constraint.
  - `ClientController::destroy` blocks deleting a Client while Contacts still reference it (`409`); `ContactController::destroy` allows free deletion (nothing yet depends on Contacts).
  - New permissions `clients.view` (Manager/Staff) and `clients.manage` (Administrator-only) — Contacts share these permissions, no separate `contacts.*` pair.
  - New versioned REST endpoints (`/api/v1/clients`, `/api/v1/contacts`) — full CRUD, route-model-bound by `public_id`, permission-gated, both flat top-level resources (Contact filterable by `?client=<public_id>`, not nested).
  - Recorded DEC-031.
  - No projects, opportunities, sales pipeline, leads, quotations, contracts, invoices, billing, payments, tasks, work logs, client portals, support tickets, service desk, email campaigns, marketing automation, messaging, notifications, file/document management, account-manager ownership rules, complex tagging, custom fields framework, activity timeline, contact interaction history, multiple addresses, branch/location hierarchy, or advanced CRM segmentation; no Admin Backoffice CRUD UI (consistent with Phase 6/7's precedent).

## Pending / Not Started

- Staff Status & Location Check-in (Phase 9) and everything after it on the roadmap.

## Known Blockers / Issues

- **This session's environment:** Docker's own image-pull path worked (via `mirror.gcr.io`, a legitimate Docker Hub mirror, when the default registry endpoint was blocked), but package installation *during* an image build (`apt-get`, reaching `deb.debian.org`) is blocked by this sandbox's network policy — confirmed as a real, deliberate block, not a transient failure. Worked around for validation purposes only (a temporary, uncommitted Dockerfile variant skipping just that one step) without weakening the real, committed Dockerfile, which still includes the `apt-get` step real developers and CI-less environments need. See `docs/handoffs/V1_PHASE_04A_HANDOFF.md` for the full account — this does not affect the correctness of what was committed.
- **Phase 6 session's environment:** `composer install` could not complete over the network — GitHub's zipball API (`api.github.com`) and even its git-source fallback consistently failed authentication/connectivity for the entire dependency tree (not just the 1–3 packages seen in Phase 5). Recovered without touching `composer.json`/`composer.lock` by extracting each locked package directly from Composer's own local VCS mirror cache (already present from a prior partial attempt) at its exact locked commit, hand-building `vendor/composer/installed.json`/`installed.php`, and copying Composer's own `InstalledVersions.php` runtime class from the installed `composer` phar. See `docs/handoffs/V1_PHASE_06_HANDOFF.md` for the full account — every quality gate then passed normally, and this does not affect the correctness of what was committed (no vendor files are committed either way).
- **Phase 7 session's environment:** `vendor/` from the Phase 6 session's manual recovery was already present and functional in this container, so no repeat of the Phase 6 `composer install` recovery was needed. Docker-based re-verification was again not attempted this session (no Docker configuration changed); see `docs/handoffs/V1_PHASE_07_HANDOFF.md`.
- Open design questions: real-time transport, object storage provider — see `docs/02_ARCHITECTURE.md` §9. (Departments/Teams hierarchy shape was resolved by Phase 6 — see DEC-029. Staff↔User relationship and manager/reporting structure were resolved by Phase 7 — see DEC-030.)

## Repository / Branch Information

- Repository: `jaaan44/company-app`
- Default branch: `main` (contains the approved Phase 0–7 baseline)
- Phase 8 branch: `claude/company-app-v1-phase-8-bo1282` (branched from `main`, not merged)

## Latest Relevant Handoff

`docs/handoffs/V1_PHASE_08_HANDOFF.md`

## For the Next Session

Read `CLAUDE.md`, then this file, then `docs/ROADMAP.md`, then `docs/handoffs/V1_PHASE_08_HANDOFF.md` for Clients & Contacts, `docs/handoffs/V1_PHASE_07_HANDOFF.md` for Staff, `docs/handoffs/V1_PHASE_06_HANDOFF.md` for Organization Structure, `docs/handoffs/V1_PHASE_05_HANDOFF.md` for Roles & Permissions, and `docs/handoffs/V1_PHASE_04A_HANDOFF.md`/`V1_PHASE_04_HANDOFF.md` for the Docker environment and Authentication. Phase 9 (Staff Status & Location Check-in) needs explicit user authorization before any implementation starts — do not begin it based on the roadmap alone.
