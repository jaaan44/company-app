# CURRENT STATE

*Read this first. Kept intentionally short — for depth, follow the pointers, don't expect this file to contain everything.*

**Product:** Company App — internal operations & communication platform
**Current phase:** Phase 10 — Projects & Project Membership
**Phase status:** COMPLETE (pending user review)
**Last completed phase:** Phase 10 (Phases 1–9 are merged into `main`)
**Next planned phase:** Phase 11 — Tasks (see `ROADMAP.md`) — **not authorized yet**

## Current Objective

Phase 10 is implemented, tested, and pushed for review. Awaiting authorization for Phase 11.

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
- **Phase 9:** Staff Status & Location Check-in. See `docs/handoffs/V1_PHASE_09_HANDOFF.md` for full detail.
  - `staff_statuses`/`staff_checkins` tables; `App\Models\StaffOperationalStatus`/`StaffCheckIn` — two append-only history tables, deliberately separate from `staff.status` (Phase 7 employment lifecycle) and from attendance/leave/payroll, which this phase never touches. `App\Enums\OperationalStatus` (`available`/`busy`/`in_meeting`/`in_field`/`off_duty`).
  - No denormalized "current" column on either table or on `staff` — `Staff::latestOperationalStatus()`/`latestCheckIn()` (`hasOne(...)->latestOfMany()`) derive it from the latest row; a staff member with no history has `null` current status/location, never an assumed default.
  - `staff_checkins` requires `latitude`/`longitude` (`decimal(10,7)`, validated), accepts optional `accuracy_meters`/`location_label`/`note`/an informational `status` snapshot; `public_id` (ULID) lets an Administrator address one directly for deletion. Both tables `cascadeOnDelete()` on their parent `staff` row (child data, unlike master-data `restrictOnDelete()` relationships elsewhere).
  - Four new permissions: `staff-status.view`/`staff-status.manage` (status; view is Manager/Staff company-wide, manage is Administrator-only) and `location.view`/`location.manage` (location; view is **Manager only**, further scoped in `CheckInController` to a Manager's own direct reports via `Staff.manager_id` — the first real row-level data isolation in the codebase; manage is Administrator-only).
  - New versioned REST endpoints under `/api/v1`: `GET`/`POST /me/status`, `GET`/`POST /me/check-ins` (self-service, requiring the authenticated User to have a linked Staff record — `403` otherwise, no new permission, mirroring `GET /auth/me`); `GET`/`POST /staff/{public_id}/status`; `GET /staff/{public_id}/check-ins`; `DELETE /check-ins/{public_id}`. "Updating" is modeled as appending to the same paginated, latest-first list the `GET` returns.
  - `App\Http\Resources\StaffResource` gains `operational_status` (nullable) — no location data of any kind was added to it.
  - Recorded DEC-032.
  - No attendance, clock-in/clock-out, timesheets, payroll, salary, overtime, leave management/balances/approvals, biometric integration, continuous/background GPS tracking, automatic location polling, geofencing, route/movement history, employee surveillance, GPS spoofing detection, Google Maps/Mapbox/geocoding integration, device tracking, project/task assignment, work logs, messaging, notifications, or performance/productivity monitoring; no Admin Backoffice CRUD UI or Flutter mobile screens (deferred to a future UI phase).

- **Phase 10:** Projects & Project Membership. See `docs/handoffs/V1_PHASE_10_HANDOFF.md` for full detail.
  - `projects`/`project_memberships` tables; `App\Models\Project`/`ProjectMembership` (`projects` carries a ULID `public_id`, DEC-017; `project_memberships` deliberately doesn't). `App\Enums\ProjectStatus` (`planned`/`active`/`on_hold`/`completed`/`cancelled`); `App\Enums\ProjectMembershipRole` (`project_lead`/`member`, project-scoped, distinct from the global application role).
  - No `project_manager_staff_id` — leadership lives entirely on Project Membership's `role`. `projects.client_id` is nullable (`restrictOnDelete()`) — a Project may be internal; an inactive Client neither loses existing Projects nor is blocked from new ones.
  - `project_memberships` represents the current roster only (no historical-period tracking, hard-delete on removal), unique on `(project_id, staff_id)`, both FKs `restrictOnDelete()`. Only `active` Staff may be newly assigned; existing memberships survive a later status change.
  - `ClientController`/`StaffController::destroy` (Phases 8/7) extended to also block deletion while a Project/Project Membership references them; `ProjectController::destroy` blocks deletion while memberships exist.
  - New permissions `projects.view` (**Manager only** — the first `*.view` not also granted to Staff) and `projects.manage` (Administrator-only, covers Project CRUD and all membership writes).
  - An ordinary Staff member sees only Projects where they hold a membership — enforced in-controller (`AuthorizesProjectVisibility`), applied directly to `GET /api/v1/projects`/`{public_id}` rather than a `can:` route middleware — the second real row-level authorization pattern after Phase 9.
  - New versioned REST endpoints (`/api/v1/projects` full CRUD; nested `/api/v1/projects/{public_id}/members` — the first genuinely nested resource in this API, addressed by the member's Staff `public_id`).
  - Recorded DEC-033.
  - No tasks, task assignment, work logs, time tracking, billing, quotations, contracts, CRM opportunity pipelines, file/document management, messaging, notifications, calendars, Gantt charts, budgeting/financials, utilization metrics, or approval workflows; no Admin Backoffice CRUD UI (consistent with Phase 6/7/8/9's precedent).

## Pending / Not Started

- Tasks (Phase 11) and everything after it on the roadmap.

## Known Blockers / Issues

- **This session's environment:** Docker's own image-pull path worked (via `mirror.gcr.io`, a legitimate Docker Hub mirror, when the default registry endpoint was blocked), but package installation *during* an image build (`apt-get`, reaching `deb.debian.org`) is blocked by this sandbox's network policy — confirmed as a real, deliberate block, not a transient failure. Worked around for validation purposes only (a temporary, uncommitted Dockerfile variant skipping just that one step) without weakening the real, committed Dockerfile, which still includes the `apt-get` step real developers and CI-less environments need. See `docs/handoffs/V1_PHASE_04A_HANDOFF.md` for the full account — this does not affect the correctness of what was committed.
- **Phase 6 session's environment:** `composer install` could not complete over the network — GitHub's zipball API (`api.github.com`) and even its git-source fallback consistently failed authentication/connectivity for the entire dependency tree (not just the 1–3 packages seen in Phase 5). Recovered without touching `composer.json`/`composer.lock` by extracting each locked package directly from Composer's own local VCS mirror cache (already present from a prior partial attempt) at its exact locked commit, hand-building `vendor/composer/installed.json`/`installed.php`, and copying Composer's own `InstalledVersions.php` runtime class from the installed `composer` phar. See `docs/handoffs/V1_PHASE_06_HANDOFF.md` for the full account — every quality gate then passed normally, and this does not affect the correctness of what was committed (no vendor files are committed either way).
- **Phase 7 session's environment:** `vendor/` from the Phase 6 session's manual recovery was already present and functional in this container, so no repeat of the Phase 6 `composer install` recovery was needed. Docker-based re-verification was again not attempted this session (no Docker configuration changed); see `docs/handoffs/V1_PHASE_07_HANDOFF.md`.
- **Phase 9 session's environment:** this container started with no `vendor/` at all (unlike Phase 7/8's sessions, which inherited one). `composer install` reproduced the same `api.github.com` zipball-scoping issue documented in Phase 6/8 for every third-party dependency, recovered the same way (`--prefer-source` git-clone fallback). `phpstan/phpstan` again hit its dist-only/no-`source`-entry exception (Phase 8 §13) — its own `git clone --mirror` this time additionally exceeded Composer's 300s process timeout (a large monorepo history) before completing. Recovered by shallow-cloning (`--depth 1 --branch <tag>`) the exact locked commit directly (seconds, not the timeout), pre-seeding Composer's local VCS mirror cache from that shallow clone so Composer's own retry found it immediately, then — when Composer's final reference-clone step still failed because a mirror sourced from a shallow clone is itself shallow — copying the four files `phpstan`/`phpstan.phar`/`bootstrap.php`/`composer.json` directly into `vendor/phpstan/phpstan/` (the phar is fully self-contained; nothing else in the repository is needed to run the tool), hand-writing `vendor/bin/phpstan`/`phpstan.phar` proxy scripts (mirroring Composer's own generated pattern, as Phase 8 did), adding the package's metadata to `vendor/composer/installed.json`, and running `composer dump-autoload` to regenerate the rest normally. `vendor/bin/phpstan --version`/`vendor/bin/phpstan analyse` both ran cleanly against this phase's real code (0 errors) — see `docs/handoffs/V1_PHASE_09_HANDOFF.md` for the full account. `vendor/` is never committed either way, so none of this recovery is part of the diff.
- **Phase 10 session's environment:** same pattern again — no `vendor/` at session start, `composer install` needed the git-mirror-cache fallback for every third-party dependency, and `phpstan/phpstan`'s own `git clone --mirror` again exceeded the 300s process timeout. Recovered identically to Phase 9 (shallow-clone the exact locked commit, pre-seed the mirror cache, manually copy the four essential files into `vendor/phpstan/phpstan/`, hand-write `vendor/bin/phpstan`/`phpstan.phar`, patch `vendor/composer/installed.json`, `composer dump-autoload`) — see `docs/handoffs/V1_PHASE_10_HANDOFF.md` §14a. Once installed, this phase's own automated test suite caught one real application bug (unrelated to the environment): Laravel's automatic nested-route-binding scoping guessed a nonexistent `Project::staff()` relation for the two-parameter member routes, fixed via `->withoutScopedBindings()` — see the handoff's Deviations section.
- Open design questions: real-time transport, object storage provider — see `docs/02_ARCHITECTURE.md` §9. (Departments/Teams hierarchy shape was resolved by Phase 6 — see DEC-029. Staff↔User relationship and manager/reporting structure were resolved by Phase 7 — see DEC-030.)

## Repository / Branch Information

- Repository: `jaaan44/company-app`
- Default branch: `main` (contains the approved Phase 0–9 baseline)
- Phase 10 branch: `claude/eager-archimedes-8ze14i` (branched from `main`, not merged)

## Latest Relevant Handoff

`docs/handoffs/V1_PHASE_10_HANDOFF.md`

## For the Next Session

Read `CLAUDE.md`, then this file, then `docs/ROADMAP.md`, then `docs/handoffs/V1_PHASE_10_HANDOFF.md` for Projects & Project Membership, `docs/handoffs/V1_PHASE_09_HANDOFF.md` for Staff Status & Location Check-in, `docs/handoffs/V1_PHASE_08_HANDOFF.md` for Clients & Contacts, `docs/handoffs/V1_PHASE_07_HANDOFF.md` for Staff, `docs/handoffs/V1_PHASE_06_HANDOFF.md` for Organization Structure, `docs/handoffs/V1_PHASE_05_HANDOFF.md` for Roles & Permissions, and `docs/handoffs/V1_PHASE_04A_HANDOFF.md`/`V1_PHASE_04_HANDOFF.md` for the Docker environment and Authentication. Phase 11 (Tasks) needs explicit user authorization before any implementation starts — do not begin it based on the roadmap alone.
