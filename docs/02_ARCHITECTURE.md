# 02 — Architecture (Initial)

Status: **Mostly confirmed as of Phase 4** (repository layout, versions, database engine, identifier strategy, Admin Backoffice direction, API foundation, authentication — see §2, §3, §12, §13). Remaining open items are listed in §9.

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

**Local development:** No Docker by default (DEC-013). PHP, Composer, Node.js, and the Flutter SDK installed directly are sufficient at this project's scale — both apps have already been built and validated this way in Phases 1–2. Docker remains an option to introduce later for a specific, demonstrated need (e.g. standardizing a non-SQLite database across contributors once one is chosen), not a default.

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
