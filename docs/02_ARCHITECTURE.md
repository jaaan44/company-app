# 02 — Architecture (Initial)

Status: **Mostly confirmed as of Phase 12** (repository layout, versions, database engine, identifier strategy, Admin Backoffice direction, API foundation, authentication, local Docker environment, authorization, organization structure, staff, clients & contacts, staff operational status & location check-in, projects & project membership, tasks, work logs — see §2, §3, §12, §13, §14, §15, §16, §17, §18, §19, §20, §21, §22). Remaining open items are listed in §9.

## 0. Resource-Efficiency Direction

Company App serves approximately 100 employees. Favor a lean, resource-efficient architecture with reasonable growth headroom — do not optimize for massive-scale workloads or introduce infrastructure without demonstrated need. Sound database design, indexing, pagination, maintainable module boundaries, and queues for genuinely expensive work are sufficient; premature infrastructure (microservices, Kubernetes, Kafka, Elasticsearch/OpenSearch, unnecessary always-on services, complex distributed systems) is out of scope for V1 and should not be introduced speculatively. This governs every subsequent phase's implementation choices, not just Phase 1's.

## 1. High-Level Components

```
┌────────────────────┐      ┌──────────────────────┐
│  Flutter Mobile App │◄────►│                       │
│  (staff)            │      │   Laravel API         │
└────────────────────┘      │   (JSON, versioned)   │
                              │                       │
┌────────────────────┐      │   + Admin Backoffice  │◄──► Relational DB
│  Admin Backoffice   │◄────►│     (server-rendered   │
│  (web, staff mgmt)  │      │      or SPA-on-API)   │
└────────────────────┘      └──────────┬────────────┘
                                        │
                              ┌─────────┴─────────┐
                              │ Redis (cache/queue/│
                              │ broadcast) + Queue │
                              │ workers            │
                              └────────────────────┘
```

- **Laravel backend/API**: single source of business logic. The Flutter app consumes the versioned API; the Admin Backoffice (Blade + Livewire — DEC-019) reads models/business logic directly within the same Laravel application rather than round-tripping through its own API — Livewire components call into the same underlying Models/Policies/Actions the API controllers use, so business rules still live in one place.
- **Flutter mobile app**: consumes the API only. No direct DB access, no duplicated business logic.
- **Relational database**: system of record. **MySQL is the production direction (DEC-016)**; SQLite is used for local development and automated tests where behavior is database-neutral.
- **Redis**: queueing (jobs like notification dispatch, report generation), caching, and — if adopted — broadcasting for real-time features (messaging, live status/notifications). Not adopted yet — see §6.

## 2. Repository Layout (confirmed, Phase 1 — DEC-011)

```
apps/api/     — Laravel app (API + future Admin Backoffice)
apps/mobile/  — Flutter app
docs/         — this documentation tree
```

Monorepo, no orchestration tooling (Nx/Turborepo/Melos) — the two apps operate independently. The Admin Backoffice renders as Laravel Blade + Livewire, inside `apps/api` (DEC-019) — both its location and its rendering approach are now confirmed.

**Versions (Phase 1 bootstrap):** Laravel 13.31.0, PHP 8.4.19 (composer.json requires `^8.3`), Flutter 3.47.2 (stable channel), Dart 3.13.2.

## 3. API Layer

- The API is the primary integration point for the Flutter app (the Admin Backoffice reads business logic directly — see §1).
- Versioned, rooted at `/api/v1` — **implemented as of Phase 3** (DEC-020): `routes/api.php` → `routes/api/v1.php`; a future breaking version adds `routes/api/v2.php` without touching or duplicating v1's controllers. A minimal `GET /api/v1/health` endpoint (tested) establishes the routing and response conventions in `04_API_CONVENTIONS.md` — success responses wrapped in `{"data": ...}`, standard Laravel JSON error/validation rendering, no custom envelope. Intentionally public — no authentication middleware yet.
- Authentication — **implemented as of Phase 4** (DEC-022): the API authenticates via Laravel Sanctum personal access tokens (`Authorization: Bearer <token>`), used by the Flutter mobile app. The Admin Backoffice does not call the API at all (see §1) — it authenticates separately via Laravel's session/cookie `web` guard. `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/me` are the first authenticated (and one unauthenticated: login) business endpoints. See `05_SECURITY_MODEL.md` for enforcement detail.

## 4. Authorization Layer

- Permission-oriented, not purely role-hardcoded (DEC-004). Roles are collections of permissions; authorization checks are expressed in terms of permissions (`staff.suspend`, `leave.approve`, etc.), typically via Laravel Policies/Gates backed by a permission store.
- **Implemented as of Phase 5** (DEC-028): `roles`/`permissions`/`role_permissions` tables, one role per user (`users.role_id`), a centralized `Gate::before` override for the Administrator role plus role-derived permission resolution for everyone else. See §15.
- Data isolation (e.g., a Manager only seeing their team, a Field Staff only seeing their own work logs) is a first-class design concern, not bolted on later — see `05_SECURITY_MODEL.md`.

## 5. Real-Time Capabilities

Needed for: messaging, live notifications, possibly live staff status. Direction: Laravel broadcasting (e.g. via Redis + a WebSocket layer such as Laravel Reverb, or a managed alternative) fed by queued events. Exact transport is an **open question**, to be settled no earlier than the Messaging/Notifications phases — do not adopt a specific vendor prematurely.

## 6. Background Processing

Queues for: notification dispatch, scheduled reminder jobs (leave, deadlines, appointments), report generation, and any bulk/administrative operation that shouldn't block a request. Per the resource-efficiency direction (§0), the Phase 1 bootstrap uses Laravel's framework-default **database-backed** queue/cache/session drivers — no Redis service is required to run the application. Redis remains the likely upgrade path if/when a genuine load or real-time need demonstrates it (see §5), not a default to install speculatively.

## 7. File Storage

Attachments (service reports, incident reports, task comments, profile photos) need a storage abstraction from day one (Laravel's filesystem abstraction), even if the initial disk driver is local. Production target is expected to be S3-compatible object storage — confirmed at the relevant implementation phase, not assumed today.

## 8. Historical/Audit Data

Per DEC-009 and DEC-010: state-changing workflows (leave approvals, incident progress, staff status changes) preserve history as append-style records rather than overwriting a single mutable field. Audit logging (who did what, when) is expected to be a cross-cutting concern (e.g. a shared `audit_logs` table plus a lightweight logging convention/trait), introduced early enough that later modules don't have to retrofit it. See `03_DATABASE_MODEL.md`.

## 9. Explicitly Open Questions

These require a decision at the appropriate future phase, not now:

- Real-time transport for messaging/notifications
- Object storage provider for production

*(Resolved: monorepo vs polyrepo — §2, DEC-011. Database engine — §1, DEC-016. Admin Backoffice implementation style — §1/§2, DEC-019. Primary key/public ID strategy — §12, DEC-017. Departments/Teams hierarchy shape — §16, DEC-029.)*

## 10. Non-Goals for V1

- Multi-tenancy
- Continuous GPS tracking (DEC-005)
- Full chat-platform feature parity (DEC-007)
- Microservices, Kubernetes, Kafka, Elasticsearch/OpenSearch, unnecessary always-on services, or other complex distributed-systems infrastructure — this is a single Laravel application sized for ~100 users; no premature infrastructure (§0)

## 11. Development Environment & CI (confirmed, Phase 2)

**Local development:** Docker Compose is the standard local backend environment as of Phase 4A (DEC-027, superseding DEC-013) — see §14. Direct install of PHP/Composer/Node.js remains possible for a developer who prefers it; the Flutter SDK is always installed directly regardless (Flutter is never Dockerized).

**CI:** Two path-filtered GitHub Actions workflows (DEC-015) — `.github/workflows/backend-ci.yml` and `.github/workflows/mobile-ci.yml` — each triggered on pull requests targeting `main` and pushes to `main`, scoped via `paths:` so a backend-only change doesn't run the mobile suite and vice versa. Single PHP version (8.4), single Flutter version (3.47.2) — no build matrix, per the resource-efficiency direction (§0). No Android/iOS artifact builds in CI (release packaging is a later concern, not fast quality validation).

**Backend static analysis:** Larastan (PHPStan for Laravel) v3, level 5, configured at `apps/api/phpstan.neon` (DEC-014). The exact commands CI runs are documented once, authoritatively, in `CLAUDE.md` §5 — this section intentionally doesn't repeat them.

**Test database safety:** Laravel's own `phpunit.xml` (from the Phase 1 bootstrap) already isolates tests to an in-memory SQLite database (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`) distinct from the local dev database file — no risk of tests touching real data, in CI or locally. Production database engine is now MySQL (DEC-016, Phase 3); CI continues to use SQLite for the current database-neutral test suite — no MySQL service has been added to CI, and none should be until a feature genuinely needs MySQL-specific behavior tested (see §12).

## 12. Core Architecture Conventions (confirmed, Phase 3)

**Identifiers (DEC-017):** numeric `BIGINT` internal primary keys (`$table->id()`); `ULID public_id` on externally addressable entities, added per-entity as each is actually built — not retrofitted everywhere speculatively. See `03_DATABASE_MODEL.md` §3 for which entities likely need one.

**Laravel application organization (DEC-018):** modular monolith, one Laravel app, conventional structure — thin Controllers, Form Requests, Policies, Eloquent (no repository pattern), Action/Service classes only where warranted, Jobs for genuinely expensive async work, Events only for meaningful decoupling. `app/Http/Controllers/Api/V1/` holds versioned API controllers; this pattern (an app-organized-by-Laravel-convention, versioned API namespace) is what future business modules should follow — no new architectural pattern needed per module.

**Flutter application organization (DEC-021):** `lib/app/` (root widget + theme), `lib/core/` (cross-cutting concerns — currently just `core/config/` for build-time configuration), `lib/features/<name>/` (one folder per feature area, `home/` today as a placeholder). No routing package, no state-management framework — Flutter's built-in navigation is sufficient until Authentication (Phase 4) needs more.

**Mobile configuration (§14 of the governing Phase 3 instruction):** the API base URL is supplied at build/run time via `--dart-define=API_BASE_URL=...` (`lib/core/config/app_config.dart`), defaulting to the local dev server. No production URLs or secrets are hard-coded.

**MySQL-specific behavior (§16 of the governing Phase 3 instruction):** future features must remain compatible with MySQL. If a feature's behavior genuinely can't be faithfully tested against SQLite (e.g. a MySQL-specific function or locking behavior), that feature's phase should add MySQL-backed integration testing for that feature specifically — CI does not gain a general-purpose MySQL service preemptively.

## 13. Authentication (confirmed, Phase 4)

**Mechanisms (DEC-022):** Admin Backoffice uses Laravel's session/secure-cookie authentication (`web` guard). The Flutter mobile app uses Laravel Sanctum personal access tokens (bearer-token only — no cookie-based SPA/stateful authentication). No OAuth server, JWT infrastructure, or Passport.

**Account state:** `users.status` (`active`/`suspended`/`inactive`, `App\Enums\AccountStatus`) is enforced both at login (both surfaces) and on already-authenticated access via a single `App\Http\Middleware\EnsureAccountIsActive` middleware (alias `account.active`) — not scattered per-controller checks. A suspended/inactive account cannot start a new session/token and loses access mid-session the next time it's checked.

**Admin Backoffice access (DEC-024):** a transitional `users.is_admin` boolean, checked once at login by `App\Livewire\Auth\LoginForm`. Deliberately temporary — Phase 5 replaces it with permission-based authorization (DEC-004).

**Registration (DEC-023):** none. Accounts are company-provisioned (a local-only seeder for now; a future Staff Management phase may add an Admin-driven flow).

**Flutter (DEC-025, DEC-026):** `lib/features/auth/` — `AuthApiClient` (wraps `package:http`), `TokenStorage`/`SecureTokenStorage` (`flutter_secure_storage`), `AuthController` (`ChangeNotifier` — the first state-management choice made under DEC-021's deferral), `AuthGate`/`LoginPage` presentation. The existing `HomePage` placeholder (Phase 3) is reused as the authenticated destination, extended with a logout action — not replaced with a real dashboard.

## 14. Docker Development Environment (confirmed, Phase 4A)

**Standard, not exclusive (DEC-027, superseding DEC-013):** `docker-compose.yml` at the repository root defines exactly three services:

```
Flutter (host/device)
        │
        ▼
     nginx  ──►  app (PHP-FPM 8.4)  ──►  mysql
```

- `nginx` (`nginx:1.27-alpine`) — conventional Laravel vhost (`docker/nginx/default.conf`), document root `apps/api/public`, PHP requests proxied to `app:9000`, dotfiles/non-`public` paths denied. Published on host port `8012` by default (`${APP_PORT:-8012}:80` — the container's own internal port, 80, is unaffected by this and never changes) — a project-standard default chosen to avoid clashing with common locally-run services; override via the `APP_PORT` environment variable if it's already taken. Flutter's `API_BASE_URL` must be pointed at whichever port is actually in use (`http://localhost:8012/api/v1` by default) via the existing `--dart-define` mechanism — see §12/DEC-021; nothing in Flutter's own code is hard-coded to either backend path.
- `app` (`docker/php/Dockerfile`, built from `php:8.4-fpm`) — only `pdo_mysql`/`bcmath` added via `docker-php-ext-install` (`mbstring` etc. already ship in the base image), `git`/`unzip` for Composer, and Composer itself copied from the official `composer:2` image. A `local-dev.ini` raises `memory_limit` to 512M (Larastan/PHPStan's parallel workers otherwise crash on the base image's 128M default). No application code is baked into the image — it's bind-mounted (see below).
- `mysql` (`mysql:8.4`) — matches the production direction (DEC-016). Data persists in a named volume (`mysql-data`); a healthcheck (`mysqladmin ping`) gates the `app` container's startup so migrations never race an unready database. Host-mapped to `127.0.0.1:3347` by default (`MYSQL_PORT`, overridable — 3306 is commonly already taken by another local MySQL install) purely for host tools such as MySQL Workbench; Laravel's own connection is entirely internal to the Docker network (`DB_HOST=mysql`, `DB_PORT=3306` — the container's actual port, never affected by the host mapping).

**No Redis, queue worker, scheduler, WebSocket server, Mailpit, phpMyAdmin, Elasticsearch, Node container, or Kubernetes** — none is demonstrably needed yet, per the resource-efficiency direction (§0). A future phase adds a service only when it has a real requirement to satisfy.

**Source mounting / Composer strategy:** `apps/api` is bind-mounted into `app` (and read-only into `nginx`) so the host's editor sees every change instantly. `vendor/` is a *separate* named volume mounted over the bind mount's `vendor/` path — this is deliberate: it stops whatever is (or isn't) in the host's `apps/api/vendor` from shadowing or conflicting with what the container installs via `composer install`, avoiding the classic "works on my host, breaks in the container" dependency mismatch. First-time setup therefore includes one `docker compose exec app composer install`.

**Environment configuration:** Laravel reads `apps/api/.env` directly (it's part of the bind mount) — the `app` service deliberately does **not** use Compose's `env_file`/`environment` to inject the same values as container-level OS environment variables. Testing this the other way during the phase revealed why: PHPUnit's `<env>` overrides in `phpunit.xml` don't force-replace a variable that already exists, so OS-level env vars from `env_file` silently defeated the testing-environment overrides, pointing `php artisan test` inside the container at the real MySQL dev database instead of the isolated in-memory SQLite it uses everywhere else. `apps/api/.env.docker.example` provides the MySQL-pointing example (`DB_HOST=mysql`, matching the `mysql` service's hardcoded local-only dev credentials) to copy to `.env`.

**Permissions:** the container's `php-fpm` runs as `www-data`, which essentially never matches whatever UID owns the bind-mounted files across Windows/macOS/Linux. Rather than attempt UID-mapping across three host platforms, the image's `entrypoint.sh` grants `a+rwX` (not a blanket `777`, and scoped only to `storage/` and `bootstrap/cache/`, never the whole application) on container start.

**CI unaffected:** GitHub Actions continues to run directly on the runner (no Docker), still SQLite-based per DEC-015 — Docker is a local development environment concern, not a CI concern; see `docs/handoffs/V1_PHASE_04A_HANDOFF.md` for the full rationale and verification detail.

## 15. Roles & Permissions (confirmed, Phase 5)

**Schema (DEC-028):** `roles` (`id`, `name` unique — `administrator`/`manager`/`staff`, `label`), `permissions` (`id`, `name` unique — dot-notation, e.g. `admin.access`, `label`), `role_permissions` (composite PK `role_id`+`permission_id`, a plain pivot with no surrogate key). `users.role_id` is a nullable FK to `roles` (`nullOnDelete`) — **one role per user**, not a many-to-many `users`↔`roles` table; a user with no role has no permissions. The Phase 4 transitional `users.is_admin` boolean (DEC-024) is retired — the column is dropped; a data-migration step backfills any pre-existing `is_admin = true` row onto the Administrator role before dropping it.

**Enforcement pattern:** a single `Gate::before` callback, registered in `App\Providers\AppServiceProvider::boot()`:
1. A user holding the Administrator role (`App\Models\User::hasRole(Role::ADMINISTRATOR)`) passes every ability check, unconditionally. This is the one deliberate, centralized override — not `if ($user->is_admin)`/`if ($user->role === 'admin')` checks scattered through controllers or Livewire components.
2. Otherwise, the ability name is resolved against the user's role-derived permission set (`App\Models\User::hasPermission()`) — `true` if granted, `null` (defer, not deny) if not, so an unrelated Policy/Gate ability continues to resolve normally rather than being silently intercepted.
3. An ability matching neither ends up denied by Laravel's own default-deny Gate behavior.

Because this runs through Laravel's real Gate resolution, every existing authorization surface already works against permission names with no per-permission boilerplate: route middleware (`Route::middleware('can:admin.access')`, Laravel's built-in `can` alias), `Gate::allows()`/`authorize()`, Blade's `@can`, and `$user->can()`/`$user->cannot()` inside a future Policy. Future modules protect an action by picking whichever of these fits the surface, checking a permission name — not by inventing a new mechanism.

**Administrator behavior:** does not hold explicit `role_permissions` rows — see the `Gate::before` override above. This avoids the maintenance risk of a new permission being added to the catalog and someone forgetting to attach it to Administrator (silently locking out the highest-privilege role); Administrator's "does everything" behavior is expressed in exactly one place.

**Manager/Staff:** exist as of Phase 5 with **no attached permissions** — acceptable and expected (CLAUDE.md §10); later phases attach real permissions as their modules are built.

**Admin Backoffice access chain:** `auth` → `account.active` → `can:admin.access` → `/home` (`routes/web.php`). `App\Livewire\Auth\LoginForm` performs the same `admin.access` check at login time (mirroring the account-status check's login-time + mid-session double enforcement established in Phase 4).

**API impact:** `UserResource` gains a stable `role` field — the role's *name* (e.g. `"administrator"`), never the internal numeric `role_id`. No new API endpoints were added; no endpoint is currently permission-gated beyond what Phase 4 already authenticates.

**Not introduced:** third-party RBAC packages, external IAM/OAuth authorization server, a policy engine service, Redis-backed permission caching, multi-tenant/organization-level ACL infrastructure, or many-to-many user↔role assignment — none demonstrably needed at ~100-user V1 scale (CLAUDE.md §4/§6).

## 16. Organization Structure (confirmed, Phase 6)

**Schema (DEC-029):** `departments` (`id`, `public_id` ULID, `name` unique, `description`, `status`, `sort_order`), `teams` and `positions` (same shape plus a nullable `department_id` FK to `departments`, `restrictOnDelete()`). Flat — no sub-department hierarchy; a Team/Position belongs to **at most one** Department, never a many-to-many span (resolves §9's former open question). `App\Enums\OrganizationStatus` (`active`/`inactive`) is the shared lifecycle column for all three — no `SoftDeletes`, avoiding two overlapping "is this still around" mechanisms on the same row.

**Relational integrity:** a Department cannot be deleted while any Team or Position still references it — enforced at the application layer (a clear `409`), backed by a DB-level `restrictOnDelete()` foreign key as a defense-in-depth backstop. Teams/Positions may be freely deleted in this phase (nothing yet depends on them — Phase 7 introduces Staff, the first thing that will).

**API:** versioned REST endpoints (`/api/v1/departments`, `/teams`, `/positions`), full CRUD, route-model-bound by `public_id` (DEC-017 — never the internal numeric id, including for the `department_id` a client submits when creating/updating a Team or Position). Filterable by `?status=` (all three) and `?department=<public_id>` (Teams/Positions) — a top-level filterable resource rather than deep nesting, per `04_API_CONVENTIONS.md`.

**Authorization:** two new permissions (`organization.view`, `organization.manage`) added to the existing `RolePermissionSeeder` (Phase 5 pattern, no new mechanism). `organization.view` is attached to Manager and Staff; `organization.manage` remains Administrator-only via the existing centralized `Gate::before` override (§15/DEC-028).

**Not introduced:** Staff/Employee management or any staff↔organization assignment (Phase 7), Admin Backoffice CRUD UI (no such UI pattern exists yet for any module), department hierarchy, or generic/polymorphic organization infrastructure.

## 17. Staff (confirmed, Phase 7)

**Schema (DEC-030):** `staff` (`id`, `public_id` ULID, `employee_number` unique, `first_name`, `last_name`, `preferred_name`, `company_email`/`company_phone`, `status`, `hire_date`, `separation_date`, nullable `department_id`/`team_id`/`position_id` FKs to the Phase 6 tables, `restrictOnDelete()`, a nullable self-referencing `manager_id` `restrictOnDelete()`, and a nullable **unique** `user_id` FK to `users`, `nullOnDelete()`). `App\Enums\StaffStatus` (`active`/`inactive`/`separated`) is Staff's own employment lifecycle — a three-state pattern (mirroring `AccountStatus`, not `OrganizationStatus`'s simpler two states), distinct from both `AccountStatus` (login/account state) and the future Phase 9 operational/current status (available, on leave, in the field, off duty).

**Staff↔User separation:** `Staff` is the personnel record; `User` remains the sole authentication/account model — no credentials or account-state data live on `staff`. `user_id` is optional in both directions (a Staff record may have no login access; a User may have no Staff record, e.g. the seeded local Administrator) and unique (one User links to at most one Staff record, DB-enforced).

**Organization consistency:** a Staff record's `team_id`/`department_id` must be mutually consistent when the Team itself belongs to a Department (matching, or auto-derived from the Team when `department_id` is omitted). The **Manager** relationship is a self-referencing `manager_id`; a staff member cannot be their own manager, and an assignment that would create a reporting cycle is rejected via a bounded manager-chain walk (`Staff::wouldCreateCycleWith()`, capped at 50 steps) rather than general-purpose cycle detection.

**Relational integrity extends Phase 6:** `DepartmentController`/`TeamController`/`PositionController::destroy` now also reject deletion (`409`) when Staff still reference the record; `StaffController::destroy` itself rejects deleting a staff member who still has direct reports — the same philosophy as §16's Department protection, backed by `restrictOnDelete()` foreign keys.

**API:** versioned REST endpoints (`/api/v1/staff`), full CRUD, route-model-bound by `public_id`. Filterable by `?status=`, `?department=`/`?team=`/`?position=`/`?manager=` (all by `public_id`), and a directory `?q=` search (name/employee number). Status changes (including offboarding) go through the same `update` endpoint as Organization Structure's own `status` field — no separate action route.

**Authorization:** two new permissions (`staff.view`, `staff.manage`) added to `RolePermissionSeeder` (same pattern as §16, no new mechanism). `staff.view` is attached to Manager and Staff — the Staff Directory is company-wide; `staff.manage` remains Administrator-only via the centralized `Gate::before` override.

**API resource shape:** `StaffResource` is a single Staff Directory shape (name, contact, department/team/position, manager, status) visible to anyone holding `staff.view`; the linked User's own identity (beyond a plain `has_user_account` boolean) is only included for a requester who also holds `staff.manage` — keeping directory data and administrative data distinct without a second, near-duplicate resource class.

**Not introduced:** payroll, salary/compensation, government/tax IDs, attendance/timekeeping, biometrics, leave balances/requests, employee documents, medical data, emergency contacts, performance reviews, recruitment, onboarding workflow, benefits, expense claims, work logs, project/task assignment, messaging, or client management; no Admin Backoffice CRUD UI; no operational/current-status tracking (Phase 9); no auto-generated employee numbers.

## 18. Clients & Contacts (confirmed, Phase 8)

**Schema (DEC-031):** `clients` (`id`, `public_id` ULID, `client_code` nullable/unique, `name`, `status`, `email`/`phone`/`website`, a small structured inline address — `address_line1`/`address_line2`/`city`/`state_province`/`postal_code`/`country` — `notes`) and `contacts` (`id`, `public_id` ULID, **required** `client_id` `restrictOnDelete()`, `first_name`/`last_name`, `job_title`, `email`/`phone`, `is_primary` boolean, `status`, `notes`). `App\Enums\ClientStatus` and `App\Enums\ContactStatus` (both `active`/`inactive`) are two-state lifecycles — Client mirrors `OrganizationStatus`'s master-data pattern (§16); Contact is a lightweight preserve-don't-delete lifecycle, not a richer employment-style state machine like `StaffStatus`.

**Client↔Contact relationship:** a Contact always belongs to exactly one Client — `client_id` is required, never nullable, and there is no many-to-many Contact↔Client relationship or client-less contact.

**Primary contact:** at most one Contact per Client may have `is_primary = true`, enforced in `ContactController` inside a DB transaction (clear any other primary contact for the same client, then save) — not a DB partial-unique-index (MySQL/SQLite portability).

**Relational integrity:** a Client cannot be deleted while any Contact still references it (`409`, application-enforced, backed by a `restrictOnDelete()` foreign key) — the same philosophy as Department (§16) and Staff (§17). A Contact may be freely deleted (nothing yet depends on it).

**API:** versioned REST endpoints (`/api/v1/clients`, `/api/v1/contacts`), full CRUD, route-model-bound by `public_id`. Both are flat top-level resources — Contact is filtered by `?client=<public_id>`, not nested under `/clients/{client}/contacts` — matching §16/§17's established "prefer a top-level filterable resource over nesting" precedent. Clients filterable by `?status=`/`?q=` (name/client_code search); Contacts filterable by `?client=`/`?status=`/`?is_primary=`/`?q=` (name/email search). Status changes go through the same update endpoint as every other field.

**Authorization:** two new permissions (`clients.view`, `clients.manage`) added to `RolePermissionSeeder` (same pattern as §16/§17, no new mechanism). `clients.view` is attached to Manager and Staff — the Client/Contact directory is company-wide; `clients.manage` remains Administrator-only via the centralized `Gate::before` override. Contacts share these same permissions — no separate `contacts.*` pair, since a Contact has no independent meaning apart from its Client.

**API resource shape:** `ClientResource` exposes a `contacts_count` (via `withCount`), never a nested Contacts array, keeping list responses lightweight; the full contact list is fetched via `/api/v1/contacts?client=<public_id>`. `ContactResource` nests only a minimal `client` reference (`public_id` + `name`).

**Not introduced:** projects, opportunities, sales pipeline, leads, quotations, contracts, invoices, billing, payments, tasks, work logs, client portals, support tickets, service desk, email campaigns, marketing automation, messaging, notifications, file/document management, account-manager ownership rules, complex tagging, custom fields framework, activity timeline, contact interaction history, multiple addresses, branch/location hierarchy, or advanced CRM segmentation; no Admin Backoffice CRUD UI; no auto-generated client codes.

## 19. Staff Operational Status & Location Check-in (confirmed, Phase 9)

**Schema (DEC-032):** two append-only history tables, deliberately separate from `staff.status` (Phase 7 employment lifecycle) and from attendance/leave/payroll. `staff_statuses` (`App\Models\StaffOperationalStatus`) — `staff_id`, `status` (`App\Enums\OperationalStatus`: `available`/`busy`/`in_meeting`/`in_field`/`off_duty`), `changed_by_user_id` (nullable — self or an Administrator correction). `staff_checkins` (`App\Models\StaffCheckIn`) — `public_id` (ULID, DEC-017), `staff_id`, required `latitude`/`longitude` (`decimal(10,7)`), optional `accuracy_meters`/`location_label`/`note` (≤500 chars)/`status` (an informational `OperationalStatus` snapshot — never also written to `staff_statuses`). Both `cascadeOnDelete()` on their parent Staff (child data with no independent meaning, unlike master data other tables `restrictOnDelete()` against). Neither table has a denormalized "current" column — `Staff::latestOperationalStatus()`/`latestCheckIn()` (`hasOne(...)->latestOfMany()`, indexed via `(staff_id, created_at)`) derive it from the latest row; a Staff member with no history has `null` current status/location, never an assumed default (DEC-010: history over mutation, without dual-write risk).

**Explicit check-in, not tracking (DEC-005):** a check-in is a deliberate, user-triggered action — no continuous/background GPS, polling, or geofencing exists anywhere in this phase. Check-ins are otherwise immutable; the only mutation is Administrator deletion (`location.manage`), not general editing.

**Authorization/privacy — first real row-level data isolation (§15's previously "not yet applicable" note):** `staff-status.view` (Manager and Staff — company-wide, a status word is low-sensitivity) / `staff-status.manage` (Administrator-only). `location.view` is granted to **Manager only** (never Staff) and is further scoped inside `CheckInController` to a Manager's own direct reports (`Staff.manager_id`) — Administrator is unscoped via the centralized `Gate::before` override. `location.manage` (deleting/correcting a check-in) is Administrator-only. Self-service (`/api/v1/me/status`, `/api/v1/me/check-ins`) needs no permission — only that the authenticated `User` has a linked `Staff` record (a domain check, `403` otherwise), mirroring `GET /api/v1/auth/me`.

**API:** `GET`/`POST /api/v1/me/status`, `GET`/`POST /api/v1/me/check-ins` (self); `GET /api/v1/staff/{public_id}/status` (`staff-status.view`), `POST /api/v1/staff/{public_id}/status` (`staff-status.manage`, Administrator correction), `GET /api/v1/staff/{public_id}/check-ins` (`location.view`, Manager-scoped); `DELETE /api/v1/check-ins/{public_id}` (`location.manage`). "Updating" status or check-in is modeled as appending to the same paginated, latest-first list the corresponding `GET` returns — the first item is always "current" — avoiding a separate `/history` endpoint; this is the first `/me/...` self-scoped resource pattern beyond `/auth/me`.

**Directory integration:** `StaffResource` gains `operational_status` (nullable) — safe to expose to any `staff.view` holder since its grantees match `staff-status.view`'s. No location data of any kind was added to `StaffResource`; location stays entirely behind the `location.view`-gated endpoints.

**Not introduced:** attendance, clock-in/clock-out, timesheets, payroll, leave management/balances/approvals, biometrics, continuous/background GPS tracking, automatic location polling, geofencing, route/movement history, employee surveillance, GPS spoofing detection, third-party mapping/geocoding integration, device tracking, project/task assignment, work logs, messaging, notifications, performance/productivity monitoring; no Admin Backoffice CRUD UI; no Flutter mobile screens (deferred to a future UI phase).

## 20. Projects & Project Membership (confirmed, Phase 10)

**Schema (DEC-033):** `projects` (`App\Models\Project`) — `public_id` (ULID, DEC-017), `project_code` (nullable, unique when present, admin-supplied — mirrors `clients.client_code`), `name`, `description`, `client_id` (nullable, `restrictOnDelete()` against `clients`), `status` (`App\Enums\ProjectStatus`: `planned`/`active`/`on_hold`/`completed`/`cancelled`), `start_date`/`target_end_date`/`completed_date` (nullable dates), `notes`. No `project_manager_staff_id` column — leadership is expressed entirely through Project Membership's `role`. `project_memberships` (`App\Models\ProjectMembership`) — `project_id`/`staff_id` (both `restrictOnDelete()`), `role` (`App\Enums\ProjectMembershipRole`: `project_lead`/`member`), unique on `(project_id, staff_id)`. No `public_id` on Project Membership — addressed via its Project's and Staff's `public_id` in a nested route (DEC-017: "pivot/history tables generally don't need one"). Represents the current roster only, not a history table (contrast Phase 9's genuinely historical `staff_statuses`/`staff_checkins`).

**Authorization/visibility — second real row-level data isolation (after §19's):** `projects.view` (Administrator via `Gate::before`, and **Manager only** — not Staff, the first `*.view` permission not attached to Staff) / `projects.manage` (Administrator-only, covering Project CRUD and all Project Membership writes). An ordinary Staff member holds neither, and instead sees only Projects where their linked Staff record holds a Project Membership — enforced in-controller (`AuthorizesProjectVisibility`), applied to the primary `GET /api/v1/projects`/`{public_id}` endpoints themselves (not a separate `/me/projects` route), since route middleware alone can't express instance-level membership scoping.

**API:** `GET`/`POST /api/v1/projects`, `GET`/`PUT`/`PATCH`/`DELETE /api/v1/projects/{public_id}`; `GET`/`POST /api/v1/projects/{public_id}/members`, `PUT`/`PATCH`/`DELETE /api/v1/projects/{public_id}/members/{staff_public_id}`. Nested member routes (contrast Contact→Client's deliberately flat routing, Phase 8) — Membership is inherently contextual to a Project.

**Relational integrity extends Phases 7/8:** `ClientController::destroy`/`StaffController::destroy` are extended to also reject deletion (`409`) when a Project/Project Membership still references the Client/Staff member, respectively; `ProjectController::destroy` itself rejects deletion while any Project Membership exists — preserving the anchor future Tasks/Work Logs will reference.

**Not introduced:** tasks, task assignment, subtasks, Kanban, work logs, time tracking, timesheets, payroll, billing, project invoicing, quotations, contracts, CRM opportunity pipelines, file/document management, messaging, notifications, calendars, Gantt charts, resource forecasting, project budgeting/financials, utilization metrics, performance scoring, approval workflows; no Admin Backoffice CRUD UI; no Flutter mobile screens.

## 21. Tasks (confirmed, Phase 11)

**Schema (DEC-034):** `tasks` (`App\Models\Task`) — `public_id` (ULID, DEC-017), `project_id` (**nullable**, `restrictOnDelete()` against `projects` — DEC-006 requires independent-Task support), `title`, `description`, `status` (`App\Enums\TaskStatus`: `todo`/`in_progress`/`blocked`/`completed`/`cancelled`), `priority` (`App\Enums\TaskPriority`: `low`/`normal`/`high`/`urgent`, default `normal`), `assignee_staff_id` (nullable, `restrictOnDelete()` against `staff` — a single assignee, not a `task_assignees` pivot), `created_by_user_id` (nullable, `nullOnDelete()` against `users` — accountability only), `due_date` (nullable date), `completed_at` (nullable timestamp, server-controlled: set on transition to `completed`, cleared on reopening).

**Assignment references Staff directly, not through Project Membership.** New-assignment eligibility (active Staff; a current Project member when the Task has a Project) is checked only at assignment time — exactly Project Membership's own "new assignment only" pattern (§20). Because the foreign key targets `staff_id` directly, removing a Project Membership never disturbs an existing Task assignment — assignment history survives, the design §20 itself anticipated for this phase.

**Authorization/visibility — row-level scoping extended to writes for the first time:** `tasks.view` (Administrator via `Gate::before`, and **Manager only**, mirroring §20's `projects.view`) / `tasks.manage` (Administrator-only, unrestricted Task CRUD). Beyond that, `App\Http\Controllers\Api\V1\Tasks\Concerns\AuthorizesTaskAccess` grants a Project Lead (`ProjectMembershipRole::ProjectLead` on the Task's own Project) create/update authority scoped strictly to that Project, and a Task's assignee update authority over its `status` field only — neither via a new permission, both enforced in-controller and applied to `POST`/`PUT`/`PATCH /api/v1/tasks` themselves, not just the `GET` routes §20 first demonstrated this pattern on.

**API:** `GET`/`POST /api/v1/tasks`, `GET`/`PUT`/`PATCH`/`DELETE /api/v1/tasks/{public_id}` — a **flat, top-level resource** (filterable by `?project=`), not nested under `/projects/{project}/tasks`, since a Task is not inherently owned by a Project (contrast §20's genuinely-nested Project Membership). `DELETE` remains Administrator-only, further narrowed to Tasks still in their initial `todo` status.

**Relational integrity extends §20/§18:** `ProjectController::destroy`/`StaffController::destroy` are extended to also reject deletion (`409`) when a Task still references the Project/Staff member, respectively — Staff deletion is blocked regardless of the referencing Task's status, since assignment history is preserved even after Project Membership removal.

**Not introduced:** work logs, time tracking, timers, timesheets, billable hours, payroll, attendance, task comments, task attachments, task reactions, messaging, notifications, mentions, Kanban boards, configurable workflows, subtasks, task dependencies, recurring tasks, calendars, reminders, Gantt charts, milestones, resource forecasting, project budgeting/financials, utilization metrics, task activity timeline, audit/event stream, approval workflows, a `task_assignees` pivot; no Admin Backoffice CRUD UI; no Flutter mobile screens.

## 22. Work Logs (confirmed, Phase 12)

**Schema (DEC-035):** `work_logs` (`App\Models\WorkLog`) — `public_id` (ULID, DEC-017), `staff_id` (required, `restrictOnDelete()` against `staff` — the performer), `task_id`/`project_id` (both nullable, `restrictOnDelete()` — at least one required, application-enforced), `created_by_user_id` (nullable, `nullOnDelete()` against `users` — accountability only, mirroring `tasks.created_by_user_id`), `work_date` (date, not future), `duration_minutes` (unsigned int, `1`–`1440`), `description` (required, ≤2000 chars). Duration model, not start/end timestamps — no timer of any kind exists.

**Task↔Project single source of truth:** when `task_id` is supplied, `project_id` is always server-derived from that Task's own `project_id` (itself possibly `null` for an independent Task) and a client-supplied `project_id` alongside a `task_id` is rejected outright (a custom `after()` validation check — this Laravel version has no declarative "prohibited if another field is present" rule) — `Work Log.project = A` while `Work Log.task` belongs to Project B is structurally impossible, not merely validated. `project_id` remains a real, persisted, indexed column (not purely derived at read time) because a Work Log may also reference a Project directly with no specific Task.

**Eligibility is a creation-time-only gate — self-service only:** the performer's own Staff record must be `active`; for a Project-linked Task or a direct Project reference, the performer must currently be a Project member (**any** role — not only the Task's assignee, since any active Project member may legitimately contribute); for an independent Task, the performer must be that Task's current assignee (the only linked-Staff visibility route Phase 11 grants for an independent Task). Never re-validated afterward — existing Work Logs survive a later Project Membership removal, Task reassignment, Staff status change, or Task status change unchanged (historical preservation). Administrator-entered Work Logs (naming another Staff member via `staff_id`) deliberately skip this eligibility check entirely — an Administrator is trusted to backfill/correct a record for a Staff member who may since have left a Project or become inactive.

**Authorization/visibility — narrower than Task/Project's company-wide Manager grant:** `work-logs.view` is **Manager only**, scoped in-controller (`App\Http\Controllers\Api\V1\WorkLogs\Concerns\AuthorizesWorkLogVisibility`) to the Manager's own direct reports (`Staff.manager_id`) — mirroring Phase 9's `location.view` precedent (logged work duration/timing is treated as materially more sensitive than the company-wide Staff/Task/Project directories), not Phase 10/11's company-wide Manager grant. `work-logs.manage` is Administrator-only (create-for-others/edit-any/delete-any). A Project Lead (no permission) has **read-only** visibility into Work Logs referencing a Project they lead or one of its Tasks — a deliberately stricter boundary than Phase 11's Task authority: no Project Lead or Manager edit/delete/create-for-others authority exists anywhere in this module. An ordinary Staff member never sees another Staff member's Work Logs merely via shared Project membership — Project visibility and Work Log visibility are not equivalent.

**Editing/deletion:** the performer may edit (`work_date`/`duration_minutes`/`description` only) or hard-delete their **own** Work Log at any time — no arbitrary time-window restriction. An Administrator may correct or delete **any** Work Log, same field restriction. `staff_id`/`task_id`/`project_id` are immutable for everyone after creation, including Administrator — a wrongly-attributed Work Log is deleted and recreated rather than reassigned. No status/approval workflow was introduced.

**API:** self-service `GET`/`POST /api/v1/me/work-logs`, `PUT`/`PATCH`/`DELETE /api/v1/me/work-logs/{public_id}` (performer identity always server-derived, mirroring Phase 9's `RequiresLinkedStaff`; a `public_id` belonging to another Staff member returns `404`, not `403` — its existence is itself sensitive); supervisory/administrative `GET /api/v1/work-logs`, `GET /api/v1/work-logs/{public_id}` (scoped in-controller, no bare `can:` middleware — same pattern as Phase 10/11's read endpoints), `POST`/`PUT`/`PATCH`/`DELETE /api/v1/work-logs...` (`can:work-logs.manage` route middleware, Administrator-only). Filters: `?staff=`/`?project=`/`?task=`/`?from=`/`?to=` (privileged); `?project=`/`?task=`/`?from=`/`?to=` (self, always own).

**Relational integrity extends §20/§21/§17:** `TaskController::destroy`/`ProjectController::destroy`/`StaffController::destroy` are extended to also reject deletion (`409`) when a Work Log still references them — checked before Task's existing `todo`-status check, since real work logged against a Task is an even stronger signal than status that history would be lost.

**Not introduced:** payroll, salary/overtime calculation, billable rates, invoicing/client billing, attendance, clock-in/clock-out, geolocation/background tracking, timers (start/pause/resume/heartbeat/presence), timesheet approval workflow, weekly timesheet submission, payroll-period locking, utilization analytics, productivity/performance scoring, automatic overtime detection, leave/holidays/shift scheduling/work schedules, comments/messaging/notifications/reminders/attachments/screenshots, Project financials, approval chains; no Admin Backoffice CRUD UI; no Flutter mobile screens.
