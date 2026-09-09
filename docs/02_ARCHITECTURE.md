# 02 — Architecture (Initial)

Status: **Directional.** No code exists yet. This document sets the intended shape of the system so Phase 1 (Project Bootstrap) has a target, without prescribing implementation details that should be decided when actually building.

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

## 2. Suggested Repository Layout

A monorepo is suggested for a project this size (single team, tightly coupled frontend/backend release cadence), containing:

```
backend/     — Laravel app (API + Admin Backoffice, or API only if Admin is split out)
mobile/      — Flutter app
docs/        — this documentation tree
```

Whether the Admin Backoffice lives inside `backend/` (as Laravel views/Livewire/Inertia) or as a separate frontend project is an open question for the Core Architecture phase — not decided here. A monorepo does not preclude splitting it out later.

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

Redis-backed queues for: notification dispatch, scheduled reminder jobs (leave, deadlines, appointments), report generation, and any bulk/administrative operation that shouldn't block a request.

## 7. File Storage

Attachments (service reports, incident reports, task comments, profile photos) need a storage abstraction from day one (Laravel's filesystem abstraction), even if the initial disk driver is local. Production target is expected to be S3-compatible object storage — confirmed at the relevant implementation phase, not assumed today.

## 8. Historical/Audit Data

Per DEC-009 and DEC-010: state-changing workflows (leave approvals, incident progress, staff status changes) preserve history as append-style records rather than overwriting a single mutable field. Audit logging (who did what, when) is expected to be a cross-cutting concern (e.g. a shared `audit_logs` table plus a lightweight logging convention/trait), introduced early enough that later modules don't have to retrofit it. See `03_DATABASE_MODEL.md`.

## 9. Explicitly Open Questions

These require a decision at the appropriate future phase, not now:

- Database engine: PostgreSQL vs MySQL
- Admin Backoffice implementation style: server-rendered Blade/Livewire vs Inertia vs separate SPA against the API
- Real-time transport for messaging/notifications
- Monorepo vs polyrepo (default assumption above is monorepo; confirm at Phase 1)
- Object storage provider for production
- Whether Departments/Teams need a dedicated hierarchy table or a simpler self-referencing structure

## 10. Non-Goals for V1

- Multi-tenancy
- Continuous GPS tracking (DEC-005)
- Full chat-platform feature parity (DEC-007)
- Microservices — this is a single Laravel application; no premature service decomposition
