# 02 — Architecture (Initial)

Status: **Directional**, now partially confirmed as of Phase 1 (repository layout, versions — see §2). This document sets the intended shape of the system, without prescribing implementation details that should be decided when actually building.

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

- **Laravel backend/API**: single source of business logic. The Admin Backoffice and the Flutter app are both clients of this logic — the Admin Backoffice may consume the same versioned API rather than duplicating business rules in controllers, unless a specific phase finds good reason to do otherwise (e.g. server-rendered admin views reading models directly within the same Laravel app). This choice should be confirmed, not assumed, at the Core Architecture phase.
- **Flutter mobile app**: consumes the API only. No direct DB access, no duplicated business logic.
- **Relational database**: system of record. PostgreSQL or MySQL — exact choice deferred to Phase 1/3 (either is compatible with the conceptual model in `03_DATABASE_MODEL.md`).
- **Redis**: queueing (jobs like notification dispatch, report generation), caching, and — if adopted — broadcasting for real-time features (messaging, live status/notifications).

## 2. Repository Layout (confirmed, Phase 1 — DEC-011)

```
apps/api/     — Laravel app (API + future Admin Backoffice)
apps/mobile/  — Flutter app
docs/         — this documentation tree
```

Monorepo, no orchestration tooling (Nx/Turborepo/Melos) — the two apps operate independently. Whether the Admin Backoffice renders as Laravel views/Livewire/Inertia or a separate SPA against the API remains an open question for the Core Architecture phase — only its *location* (inside `apps/api`) is confirmed.

**Versions (Phase 1 bootstrap):** Laravel 13.31.0, PHP 8.4.19 (composer.json requires `^8.3`), Flutter 3.47.2 (stable channel), Dart 3.13.2.

## 3. API Layer

- The API is the only integration point for both frontend surfaces (mobile, and admin — if admin is API-driven).
- Versioned from day one (see `04_API_CONVENTIONS.md`).
- Authentication via token-based auth suited to both SPA/admin and mobile clients (e.g. Laravel Sanctum) — exact mechanism confirmed at the Authentication phase.

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

- Database engine: PostgreSQL vs MySQL (local bootstrap uses SQLite — DEC-012 — production engine still open)
- Admin Backoffice implementation style: server-rendered Blade/Livewire vs Inertia vs separate SPA against the API
- Real-time transport for messaging/notifications
- Object storage provider for production
- Whether Departments/Teams need a dedicated hierarchy table or a simpler self-referencing structure

*(Monorepo vs polyrepo is resolved — see §2, DEC-011.)*

## 10. Non-Goals for V1

- Multi-tenancy
- Continuous GPS tracking (DEC-005)
- Full chat-platform feature parity (DEC-007)
- Microservices, Kubernetes, Kafka, Elasticsearch/OpenSearch, unnecessary always-on services, or other complex distributed-systems infrastructure — this is a single Laravel application sized for ~100 users; no premature infrastructure (§0)

## 11. Development Environment & CI (confirmed, Phase 2)

**Local development:** No Docker by default (DEC-013). PHP, Composer, Node.js, and the Flutter SDK installed directly are sufficient at this project's scale — both apps have already been built and validated this way in Phases 1–2. Docker remains an option to introduce later for a specific, demonstrated need (e.g. standardizing a non-SQLite database across contributors once one is chosen), not a default.

**CI:** Two path-filtered GitHub Actions workflows (DEC-015) — `.github/workflows/backend-ci.yml` and `.github/workflows/mobile-ci.yml` — each triggered on pull requests targeting `main` and pushes to `main`, scoped via `paths:` so a backend-only change doesn't run the mobile suite and vice versa. Single PHP version (8.4), single Flutter version (3.47.2) — no build matrix, per the resource-efficiency direction (§0). No Android/iOS artifact builds in CI (release packaging is a later concern, not fast quality validation).

**Backend static analysis:** Larastan (PHPStan for Laravel) v3, level 5, configured at `apps/api/phpstan.neon` (DEC-014). The exact commands CI runs are documented once, authoritatively, in `CLAUDE.md` §5 — this section intentionally doesn't repeat them.

**Test database safety:** Laravel's own `phpunit.xml` (from the Phase 1 bootstrap) already isolates tests to an in-memory SQLite database (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`) distinct from the local dev database file — no risk of tests touching real data, in CI or locally. Production database engine (PostgreSQL vs. MySQL) remains open (DEC-012) — this phase does not resolve it.
