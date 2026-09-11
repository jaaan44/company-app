# 02 — Architecture (Initial)

Status: **Mostly confirmed as of Phase 5** (repository layout, versions, database engine, identifier strategy, Admin Backoffice direction, API foundation, authentication, local Docker environment, authorization — see §2, §3, §12, §13, §14, §15). Remaining open items are listed in §9.

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
- Whether Departments/Teams need a dedicated hierarchy table or a simpler self-referencing structure

*(Resolved: monorepo vs polyrepo — §2, DEC-011. Database engine — §1, DEC-016. Admin Backoffice implementation style — §1/§2, DEC-019. Primary key/public ID strategy — §12, DEC-017.)*

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
