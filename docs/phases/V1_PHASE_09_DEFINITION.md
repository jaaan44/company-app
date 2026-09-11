# Phase 9 — Staff Status & Location Check-in — Specification

**Status:** COMPLETE
**Depends on:** Phase 7 (Staff), Phase 5 (Roles & Permissions)

## Objective

Build the foundational Staff Operational Status & Location Check-in module: a lightweight, explicit, self-service way for a Staff member to expose a current operational/work status and perform location check-ins, with a small history of both, and appropriate (not blanket) visibility for company users. This is operational visibility, not surveillance, attendance enforcement, or continuous tracking.

## Critical Domain Separation

- `Staff.status` (Phase 7, `App\Enums\StaffStatus`: `active`/`inactive`/`separated`) is **employment lifecycle only** and is never read or written by this phase's code.
- This phase introduces a wholly separate concept, `App\Enums\OperationalStatus`, stored in its own history table, never on `staff` itself.
- A location check-in is **not** attendance, clock-in, present-for-payroll, timesheet start, or work-hours tracking. Absence of a check-in is **not** absence, lateness, or off-work. Neither concept feeds payroll, leave, or timekeeping in any way.

## Operational Status

`App\Enums\OperationalStatus`: `available` / `busy` / `in_meeting` / `in_field` / `off_duty` — five states covering ordinary day-to-day operational visibility for a ~100-person company with field staff, without building presence infrastructure (no heartbeat, no idle detection, no WebSocket presence, no online/offline tracking). Manually set by the staff member (or, for correction, an Administrator) — never inferred.

**Storage:** an append-only history table, `staff_statuses` (`App\Models\StaffOperationalStatus`) — `staff_id`, `status`, `changed_by_user_id` (nullable, who performed the change — self or an Administrator correction), timestamps. No `public_id` (DEC-017 — a history row is never independently addressed by URL; only ever read via its parent Staff's collection). "Current status" is *derived*, not a separate mutable column: `Staff::latestOperationalStatus()` (`hasOne(...)->latestOfMany()`), indexed via `(staff_id, created_at)`. A Staff member with no history yet has no current status (`null`), never an assumed default like "available" — absence of a report should not imply anything, symmetric with the check-in rule above. This satisfies DEC-010 (history over mutation) without dual-write risk between a cache column and the history table, and without event-sourcing complexity — a plain indexed query is sufficient at this scale.

## Location Check-in

An explicit, user-triggered event: "I am currently working/checking in from this location." No continuous/background GPS tracking, periodic polling, geofencing, or route history — DEC-005 continues to apply.

**Storage:** `staff_checkins` (`App\Models\StaffCheckIn`) — `public_id` (ULID, DEC-017 — an Administrator can address a single check-in for correction, e.g. `DELETE /api/v1/check-ins/{public_id}`), `staff_id`, `latitude`/`longitude` (`decimal(10,7)` — ~1cm precision, required together), `accuracy_meters` (nullable), `location_label` (nullable free-text, e.g. "Office"/"Client Site"/"Remote" — no Location Master/catalog module), `note` (nullable, ≤500 chars, operational note only — never a work log/task update/timesheet entry), `status` (nullable `OperationalStatus` snapshot captured at check-in time — informational only, does **not** also write a `staff_statuses` row; changing ongoing status is a separate, deliberate `POST /me/status`), timestamps. No altitude/heading/velocity/device identifiers/IP-derived location. Server timestamps (`created_at`) are the authoritative check-in time — no client-supplied historical timestamp is accepted. "Current location" is the latest check-in (`Staff::latestCheckIn()`, `latestOfMany()`), same derivation pattern as operational status — no separate current-location field.

**Immutability:** check-ins are never updated by ordinary users. The only mutation is Administrator deletion/correction (`location.manage`) — no general audit subsystem is built for this.

## Staff ↔ User Requirement

Self-service actions (`/api/v1/me/status`, `/api/v1/me/check-ins`) require the authenticated `User` to have a linked `Staff` record (Phase 7's optional `staff.user_id`). A `User` with no linked `Staff` record receives `403` with a clear domain message — this is a business-rule check performed in the controller, not a new authentication/authorization mechanism, and does not alter Phase 7's deliberately optional Staff↔User relationship in either direction.

## Authorization

Four new permissions, added to the existing `RolePermissionSeeder` (Phase 5–8 pattern, no new mechanism):

- `staff-status.view` — view any staff member's current operational status/history via `GET /api/v1/staff/{public_id}/status`. Attached to **Manager and Staff** (company-wide, like `staff.view`/`organization.view`/`clients.view`) — an operational status word ("busy", "in the field") is materially less sensitive than precise coordinates and is treated as ordinary low-sensitivity directory metadata, consistent with the governing instructions' framing.
- `staff-status.manage` — set/correct another staff member's operational status on their behalf (`POST /api/v1/staff/{public_id}/status`). **Administrator-only**, via the existing centralized `Gate::before` override.
- `location.view` — view another staff member's check-in history/current location (`GET /api/v1/staff/{public_id}/check-ins`). Attached to **Manager only** (not Staff — ordinary staff never see another employee's precise location) — and additionally scoped inside the controller: a non-Administrator holder may only view staff whose `manager_id` is their own linked Staff record (direct reports only, using Phase 7's existing `manager_id`, not a new ACL engine). Administrator sees everyone via the centralized override, unscoped.
- `location.manage` — delete/correct a specific historical check-in (`DELETE /api/v1/check-ins/{public_id}`). **Administrator-only.**

Self-access (`/me/status`, `/me/check-ins`) never requires any of the four permissions above — it is inherent self-service, gated only by having a linked Staff record, mirroring the existing `GET /api/v1/auth/me` precedent (no permission check beyond authentication + active account).

This phase is the first real exercise of `05_SECURITY_MODEL.md`'s previously "not yet applicable" data-isolation principle (Manager scoped to their own team, not company-wide) — see DEC-032.

## API

```
GET   /api/v1/me/status                 self (requires linked Staff) — paginated status history, latest first
POST  /api/v1/me/status                 self (requires linked Staff) — append a new status entry
GET   /api/v1/me/check-ins              self (requires linked Staff) — paginated check-in history, latest first
POST  /api/v1/me/check-ins              self (requires linked Staff) — create a check-in

GET   /api/v1/staff/{public_id}/status      staff-status.view — that staff member's status history
POST  /api/v1/staff/{public_id}/status      staff-status.manage (Administrator) — set/correct on their behalf
GET   /api/v1/staff/{public_id}/check-ins   location.view (Manager scoped to direct reports; Administrator unscoped)

DELETE /api/v1/check-ins/{public_id}        location.manage (Administrator) — delete/correct a check-in
```

"Update own status" is modeled as **appending** a new history entry (`POST`), not a mutable `PUT` — the append-only list itself is both the current-value source (first page) and the history, avoiding a separate `/history` endpoint while staying inside `04_API_CONVENTIONS.md`'s "avoid deep nesting" guidance. This is the first use of a `/me/...` self-scoped resource pattern beyond `/auth/me`.

## Directory Integration

`StaffResource` (Phase 7) gains one additional field, `operational_status` (the current status value, nullable) — visible to any `staff.view` holder, since `staff-status.view`'s grantees are identical (Manager/Staff broad + Administrator). No location data (not even a label) is added to `StaffResource` — location stays entirely behind the `location.view`-gated endpoints, per the governing instructions' explicit warning against mixing sensitive precise location into the general directory resource.

## Explicitly Out of Scope

Per the governing instructions: attendance, clock-in/clock-out, timesheets, payroll, salary, overtime, leave management/balances/approvals, biometric integration, continuous/background GPS tracking, automatic location polling, geofencing, route/movement history, employee surveillance, GPS spoofing detection, Google Maps/Mapbox/geocoding integration, device tracking, project/task assignment, work logs, messaging, notifications, performance/productivity monitoring. No Admin Backoffice (Blade/Livewire) CRUD UI and no Flutter mobile screens — this phase is API/backend only, consistent with every prior business-module phase's precedent; mobile check-in/location UX is deferred to its own future UI phase.

## Relevant Documentation

- `docs/02_ARCHITECTURE.md` §4/§15 (Authorization), §17 (Staff precedent)
- `docs/03_DATABASE_MODEL.md` §1 (Staff Operations entity group)
- `docs/04_API_CONVENTIONS.md` (Resource Naming, Filtering)
- `docs/05_SECURITY_MODEL.md` (Authorization data isolation, Location Data)
- `docs/DECISIONS.md` DEC-005 (explicit check-ins, not continuous tracking), DEC-010 (historical operational records), DEC-017 (identifiers), DEC-028 (authorization pattern), DEC-030 (Staff precedent, `manager_id`)

## Acceptance Criteria

- A Staff-linked user can view and update their own operational status and create location check-ins through the API; a `User` with no linked Staff record cannot.
- Operational status and location check-in history are preserved (append-only), with "current" correctly derived as the latest entry.
- `Staff.status` (employment) is never modified by any Phase 9 action.
- Manager visibility into check-ins is limited to direct reports; ordinary Staff cannot view another staff member's precise location; Administrator sees everything.
- No internal numeric ID is ever exposed by an API response or accepted as client input — only `public_id`.
- All `CLAUDE.md` §5 quality gates pass, including the full Phase 1–8 regression suite.

## Testing Expectations

- Operational status: default/no-history behavior, self-update, valid/invalid values, another staff member cannot improperly change it, Administrator correction, `Staff.status` unaffected.
- Check-in: successful check-in, coordinate/label/note validation, latest-check-in-is-current behavior, multiple historical check-ins, `public_id` behavior, invalid values rejected, no-linked-Staff rejection.
- Privacy: ordinary Staff cannot obtain another staff member's precise location; Manager visibility exactly matches the direct-reports scoping; Administrator visibility works as designed.
- Authorization: Administrator, Manager, Staff self-service, Staff attempting to alter another Staff member, User with no Staff link, unauthenticated, suspended account.
- Full Phase 1–8 regression suite continues passing unmodified in behavior.
