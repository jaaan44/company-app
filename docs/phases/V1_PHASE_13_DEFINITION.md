# Phase 13 — Leave Management — Specification

**Status:** IN PROGRESS
**Depends on:** Phase 7 (Staff), Phase 5 (Roles & Permissions)

## Objective

Build the foundational Leave Management module: configurable Leave Types, a Leave Request lifecycle with a single-stage Manager/Administrator approval model, an append-only approval-history record, and simple per-staff/per-type/per-year Leave Balances derived from approved requests rather than a mutable cache column. This is explicitly not payroll, attendance, scheduling, or a statutory leave-law engine — see Critical Domain Boundary below.

## Critical Domain Boundary

Leave Management represents an employee's requested/approved absence entitlement and workflow only. It is never coupled to: payroll, salary deduction, statutory payroll computation, attendance/clock-in-clock-out, time tracking, shift scheduling, roster planning, overtime, holiday pay, Work Logs, an accrual engine based on tenure, carry-forward, encashment, or medical-document attachments. No hidden coupling is introduced to Work Logs (Phase 12), Staff Operational Status (Phase 9), or any other module.

## Leave Types — Configurable Master Data

`leave_types`: `public_id` (ULID, DEC-017), `name` (required, unique), `code` (nullable, unique when present, admin-supplied — mirrors Client's `client_code` pattern, DEC-031), `description` (nullable), `is_paid` (boolean, default `true` — the only "policy" flag this phase adds, used solely to decide whether balance/allocation checks apply, never a payroll computation), `status` (`App\Enums\LeaveTypeStatus` — `active`/`inactive`, a dedicated two-state enum rather than reusing `OrganizationStatus`, mirroring `ClientStatus`/`ContactStatus`'s reasoning, DEC-031: Leave Type is HR master data, a distinct domain from organization structure), `sort_order` (unsigned int, default 0, mirrors Department/Team/Position). No default annual allocation column on the type itself — allocation is per Staff member (see Leave Balances), since a flat per-type default was not demonstrated as required and per-staff allocation is the more accurate real-world shape (e.g. tenure-based entitlement set individually by HR). No country-specific statutory rules.

**Lifecycle.** `active`/`inactive`, never hard-deleted while referenced. Deactivating a Leave Type blocks it from being used in **new** Leave Requests (self-service and Administrator-created alike — see Leave Type Validity below) while every existing Leave Request/Balance referencing it remains fully intact and readable (DEC-010, historical preservation). A Leave Type may be hard-deleted only while it has no referencing Leave Request or Leave Balance (`409` otherwise, backed by `restrictOnDelete()` — mirrors Department/Client's delete-protection pattern).

## Leave Requests

`leave_requests`: `public_id` (ULID, DEC-017), `staff_id` (required, `restrictOnDelete()` — the requester), `leave_type_id` (required, `restrictOnDelete()`), `start_date`/`end_date` (dates, `end_date >= start_date`, both within the same calendar year — see Date Model), `total_days` (unsigned int, **server-computed only**, never client-supplied — inclusive calendar-day count between `start_date`/`end_date`; see Leave Quantity), `reason` (required, string ≤1000 chars — the requester's stated reason, material to a Manager's decision), `status` (`App\Enums\LeaveRequestStatus` — `pending`/`approved`/`rejected`/`cancelled`), `created_by_user_id` (nullable, `nullOnDelete()` — accountability: distinguishes a self-service request from one an Administrator entered on the staff member's behalf, mirroring `work_logs.created_by_user_id`/`tasks.created_by_user_id`), timestamps.

**Deliberately no `submitted_at`/`approved_at`/`rejected_at`/`cancelled_at` columns.** Submission time is `created_at` (submission and creation are the same event — a separate column would be a redundant second source of truth for the same fact). Decision/cancellation timestamps live **only** on `leave_request_actions` (see Approval History) — storing them a second time on `leave_requests` itself would create exactly the dual-write risk DEC-010/DEC-032 already steer this codebase away from (`Staff::latestOperationalStatus()`'s "derive, don't cache" precedent, applied here to decision timestamps instead of operational status).

## Leave Request Lifecycle

`App\Enums\LeaveRequestStatus`: `pending` / `approved` / `rejected` / `cancelled` — four states, no `draft` (a self-service or Administrator-created request is always immediately `pending`; nothing in the governing product requirements calls for a saved-but-unsubmitted state). Allowed transitions, enforced in the controller layer (not a generic `PATCH status`):

- `pending` → `approved` (Manager of the requester, or Administrator)
- `pending` → `rejected` (same authority — reason required)
- `pending` → `cancelled` (the requester themselves, or Administrator)
- `approved` → `cancelled` (the requester themselves, only while `start_date` is still in the future; or Administrator, at any time)
- `rejected` → immutable (no further transition)
- `cancelled` → immutable (no further transition)

No arbitrary status `PATCH` exists at all — every transition is its own explicit action endpoint (`/approve`, `/reject`, `/cancel`), per `04_API_CONVENTIONS.md`'s own illustrative example and this phase's governing instructions.

## Editing — No Edit; Cancel and Resubmit

A pending Leave Request cannot be edited (no `PUT`/`PATCH` exists on `leave_requests` at all, self-service or Administrator). The smallest coherent rule from the governing instructions' own alternatives was chosen: correcting a mistaken request means cancelling it and submitting a new one. This keeps the API surface small and avoids any question of what an "edit to a request already partway through approval" should mean.

## Approval Model — Single-Stage, Manager-of-Record or Administrator

- A Staff member submits their own Leave Request.
- The requester's **direct Manager** (`Staff.manager_id`, evaluated **at decision time**, not at submission time — see Manager Changes below) may approve/reject it.
- **Administrator** may approve/reject **any** pending request (via the centralized `Gate::before` override, DEC-028).
- No multi-level approval chain, no delegated/substitute approvers, no SLA/escalation.
- A Manager can never approve/reject their own request: enforced explicitly (`request.staff_id !== actor's own staff id`) even though `Staff::wouldCreateCycleWith()` (Phase 7) already makes self-management structurally impossible — defense in depth, and directly tested.
- A Staff member with no Manager (`manager_id` is `null`) can only have their request decided by an Administrator — there is no fallback to a company-wide Manager grant.
- Approval/rejection authority requires the actor to hold `leave-requests.view` **and** be the requester's current direct Manager — mirroring Phase 12's `work-logs.view`-plus-row-level-scope shape (DEC-035) rather than Phase 11's permission-less Project Lead shape, since both "may this Manager see this record" and "may this Manager decide it" are the same underlying relationship here.

## Manager Changes — Current Manager Decides, History Preserves the Actual Actor

If `Staff.manager_id` changes after a request is submitted but before a decision, the **current** Manager at decision time may act on it — not the Manager at submission time. This is the simpler of the two candidate rules and was chosen because nothing in the governing instructions or requirements demands otherwise. Whichever Manager actually approves/rejects is recorded on the `leave_request_actions` row (`acted_by_user_id`) permanently — a later Manager reassignment never rewrites who actually made a past decision (DEC-010).

## Approval History — `leave_request_actions`

An append-only history table, not a generic audit framework: `leave_request_id` (`cascadeOnDelete()` — child data with no independent meaning apart from its Leave Request; in practice a Leave Request is never itself deleted, so this never actually fires, mirroring `staff_statuses.staff_id`'s identical reasoning, DEC-032), `action` (`App\Enums\LeaveRequestActionType` — `submitted`/`approved`/`rejected`/`cancelled`), `acted_by_user_id` (nullable, `nullOnDelete()` — the acting User; nullable only so the history row itself is never deleted merely because the acting account later is), `note` (nullable, ≤1000 chars — the rejection/cancellation reason, or an optional note on submission/approval), timestamps. No `public_id` — never independently addressed by URL, only ever read as part of its parent Leave Request's own resource shape (mirrors `project_memberships`/`staff_statuses`' identical reasoning).

Every Leave Request gets a `submitted` action row at creation (self-service or Administrator-entered) — this is the mechanism that makes "submission history" real rather than merely inferable from `created_at`, and gives Administrator-entered requests an explicit, visible record of who submitted on the staff member's behalf.

## Leave Balances

Per-staff, per-Leave-Type, per-calendar-year **allocation**, with usage **fully derived** (never a stored/mutable "remaining" column) from the Leave Request table itself — the simplest of the candidate models that is still correct, and the one that avoids any dual-write risk between a cached balance and the requests that actually consume it (the same "derive, don't cache" precedent as `Staff::latestOperationalStatus()`/`latestCheckIn()`, DEC-032).

`leave_balances`: `staff_id` (`restrictOnDelete()`), `leave_type_id` (`restrictOnDelete()`), `year` (unsigned smallint — calendar year), `allocated_days` (unsigned int — the entitlement an Administrator has set for that Staff member/Leave Type/year), `notes` (nullable, ≤500 chars), `created_by_user_id` (nullable, `nullOnDelete()` — accountability for who set/last updated the allocation), timestamps. Unique on `(staff_id, leave_type_id, year)`. No `public_id` — addressed only via its Staff member's `public_id` plus `leave_type_id`/`year` in the request body (an upsert-style management endpoint, not an independently addressable resource — nothing here is a standalone business entity with its own detail page).

**Period: calendar year.** The narrowest, simplest choice with no fiscal-year complexity, consistent with the governing instructions' explicit preference absent a documented requirement otherwise. A Leave Request is **not permitted to span two different calendar years** (`start_date.year === end_date.year` is enforced at validation) — a deliberate, documented V1 limitation that avoids splitting a single request's consumption across two balance rows; an employee whose leave genuinely spans a year boundary submits two requests.

**Usage is derived**, not stored: for a given Staff/Leave Type/year, `used_days` = sum of `total_days` across that staff member's **approved** requests; `pending_days` = the same sum across **pending** requests; `remaining_days` = `allocated_days - used_days - pending_days`. A cancelled or rejected request contributes to neither sum — cancelling a previously-approved request therefore "restores" balance automatically, with no explicit restoration code path required (there is nothing to restore; the derived sum simply excludes it going forward).

**Unpaid Leave Types are not balance-tracked at all** — `is_paid = false` means no allocation/consumption concept applies; `allocated_days`/`remaining_days` are reported as `null` (not zero — zero would incorrectly imply "no leave allowed") wherever a balance is shown for an unpaid type, and no balance check ever blocks a request against one.

## Negative Balances / Insufficient Balance

For a **paid** Leave Type, a new submission is **rejected** if `total_days` would exceed the requester's `remaining_days` for that Leave Type/year (computed as above) — no override concept, no complex exception handling, matching the governing instructions' "no override concept exists → reject insufficient paid-leave balance" default. A Staff member with **no** `leave_balances` row for that Leave Type/year is treated as having `allocated_days = 0` (no entitlement configured yet), so any positive-day paid-leave request is rejected until an Administrator sets an allocation.

**The balance check occurs once, at submission time only** (self-service and Administrator-created requests alike — see Leave Type Validity for what Administrator creation does and does not skip), computed against `allocated_days - already-approved - already-pending` (excluding the request being created). It is **never re-checked at approval time.** This is deliberate, not an oversight: because every submission's check already accounts for every other outstanding `pending`/`approved` request for that Staff/Leave Type/year, the invariant `sum(approved) + sum(pending) <= allocated_days` holds automatically after every successful submission, without needing a second check when a pending request is later approved — and a rejected/cancelled request immediately frees capacity for the *next* submission simply by being excluded from the derived sums. The submission-time check runs inside a database transaction with a row-level lock on the relevant `leave_balances` row (`lockForUpdate()`) so two concurrent submissions cannot both pass the same check and jointly overdraw the balance.

## Date Model & Leave Quantity

Date-only semantics — `start_date`/`end_date`, no partial-day/half-day support (not required by any governing document for this phase; if a future phase needs it, `App\Enums\LeavePartialDayType` or similar can be added without breaking this shape). `total_days` is a plain **inclusive calendar-day count** (`end_date - start_date + 1`), stored as an unsigned integer — no business-day/holiday-aware calculation, and **weekends are not excluded**, per the governing instructions' explicit "do not assume Saturdays/Sundays should be excluded unless requirements say so." Backdated requests are permitted for both self-service and Administrator creation — nothing in the governing documents restricts this, and an Administrator in particular may need to record historical leave.

## Overlapping Requests

A new Leave Request (self-service or Administrator-created) is rejected if the same Staff member already has a `pending` or `approved` request whose date range intersects the new one (inclusive on both ends), **regardless of Leave Type** — an employee cannot be simultaneously "on vacation" and "on sick leave." `rejected`/`cancelled` requests never block. Enforced server-side in the Form Request's `withValidator()` hook, the same mechanism `ResolvesWorkLogReferences` (Phase 12) uses for its own cross-field checks.

## Leave Type Validity vs. Self-Service Staff Eligibility — What Administrator Creation Skips

Mirroring DEC-035's Work Log precedent exactly, but drawing the eligibility/validity line in the corresponding place for this domain:

- **Self-service eligibility** (the requester's own Staff record must be `active`; sufficient balance for a paid Leave Type) is checked **only** for self-service creation. An **Administrator creating a request on a Staff member's behalf** (`POST /api/v1/leave-requests`) deliberately **skips both of these** — an Administrator is trusted to record/correct a request for a Staff member who may since have become inactive, or to backfill a historical record without an allocation yet on file. This mirrors "Administrator-entered Work Logs skip this eligibility check entirely."
- **Leave Type validity** (the type must be `active`) and the **overlap**/**date-range** checks are structural consistency rules, not staff eligibility, and are enforced identically for **both** creation paths — mirrors Work Log's Task/Project "existence and consistency are still enforced identically" for Administrator entries. An inactive Leave Type is closed to new business for everyone, Administrator included; only Administrator's own management endpoint may still reference an inactive type when correcting/deleting something that already exists (not creating a new Leave Request against it).

Only `active` Staff may submit a **new** self-service Leave Request; an inactive/separated Staff member's existing Leave Requests/history/balances remain fully intact and readable.

## Cancellation

- **Requester, pending:** may cancel their own pending request at any time — no reason required (optional `reason`, ≤1000 chars).
- **Requester, approved:** may cancel their own approved request **only while `start_date` is still in the future** (i.e., the leave has not started) — the smallest coherent time-window rule available, directly grounded in "cancel before it starts," not an arbitrary N-day window.
- **Administrator:** may cancel any `pending` or `approved` request at any time (correction authority) — no time-window restriction.
- `rejected`/`cancelled` requests are immutable — any further cancellation attempt is `409`.
- No cancellation-approval workflow of any kind — cancellation is a unilateral action by whichever of the two authorities above is allowed to perform it at that moment, never something a Manager approves.

## Rejection Reason

`reject` requires a `reason` (string, 3–1000 chars) — the same accountability rationale as the governing instructions call out, at negligible added complexity. No threaded comments, no free-form back-and-forth.

## Permissions

Four permissions, extending `RolePermissionSeeder`'s established pattern:

- `leave-types.view` — Administrator (via `Gate::before`)/Manager/Staff, company-wide — mirrors `organization.view`/`staff.view`/`clients.view`/`staff-status.view`'s reasoning: a Staff member must be able to browse active Leave Types to submit a request, and the type catalog itself carries no sensitive information.
- `leave-types.manage` — Administrator-only (create/update/delete Leave Types).
- `leave-requests.view` — **Manager only** (not Staff — mirrors `work-logs.view`'s Phase 12 reasoning: leave reasons/dates are materially more sensitive than a company directory), further scoped in-controller to the Manager's own direct reports (`Staff.manager_id`). Also the permission checked (alongside the manager-of-record relationship) for approve/reject authority — see Approval Model.
- `leave-requests.manage` — Administrator-only: create a request on a Staff member's behalf, and Administrator-cancel.

Self-service (`/me/leave-requests`, `/me/leave-balances`) requires **no permission at all** beyond a linked Staff record — mirroring Phase 9/12's domain-check pattern (`RequiresLinkedStaff`, reused verbatim), not a new authorization mechanism.

## Visibility

- **Administrator:** every Leave Request/Balance (via `Gate::before`).
- **Manager** (`leave-requests.view`): only Leave Requests/Balances belonging to their own direct reports — scoped in-controller (`AuthorizesLeaveRequestVisibility`, mirroring `AuthorizesWorkLogVisibility`'s shape exactly, minus the Project-Lead tier, which has no equivalent in this domain).
- **Staff:** own Leave Requests/Balances only, via `/me/...` — never a coworker's, regardless of shared Project/Team/Department membership (Leave is an HR domain, not a Project domain, per the governing instructions).
- `GET /api/v1/leave-requests`/`{public_id}` and `GET /api/v1/staff/{public_id}/leave-balances` carry no bare `can:<permission>` route middleware (scoped in-controller, same shape as Phase 10–12's precedent); a requester holding neither Administrator nor a qualifying Manager relationship gets `403`.

## API

```
GET    /api/v1/leave-types                      leave-types.view
GET    /api/v1/leave-types/{public_id}           leave-types.view
POST   /api/v1/leave-types                       leave-types.manage
PUT/PATCH /api/v1/leave-types/{public_id}        leave-types.manage
DELETE /api/v1/leave-types/{public_id}           leave-types.manage (409 if referenced)

GET    /api/v1/me/leave-requests                 self (?status=&type=&from=&to=)
POST   /api/v1/me/leave-requests                 self (creates 'pending', logs 'submitted')
GET    /api/v1/me/leave-requests/{public_id}     self (404 if not own — existence is sensitive)
POST   /api/v1/me/leave-requests/{public_id}/cancel   self (pending any time; approved only before start_date)

GET    /api/v1/me/leave-balances                 self (?year=, defaults to current year)

GET    /api/v1/leave-requests                    scoped (Administrator: all; Manager: direct reports) (?staff=&status=&type=&from=&to=)
GET    /api/v1/leave-requests/{public_id}        scoped, same rule
POST   /api/v1/leave-requests                    leave-requests.manage (Administrator on behalf of a Staff member; always 'pending')
POST   /api/v1/leave-requests/{public_id}/approve     Administrator OR direct Manager (leave-requests.view + manager-of-record)
POST   /api/v1/leave-requests/{public_id}/reject      same authority; reason required
POST   /api/v1/leave-requests/{public_id}/cancel      leave-requests.manage (Administrator-only cancel path)

GET    /api/v1/staff/{public_id}/leave-balances  scoped (Administrator: any; Manager: direct reports)
POST   /api/v1/staff/{public_id}/leave-balances  leave-requests.manage (Administrator upserts an allocation for a Leave Type/year)
```

No `PUT`/`PATCH`/`DELETE` exists for `leave_requests` at all (see Editing above).

## Resource Shapes

**LeaveTypeResource:** `public_id`, `name`, `code`, `description`, `is_paid`, `status`, `sort_order`, timestamps.

**LeaveRequestResource:** `public_id`, `staff` (minimal `{public_id, employee_number, display_name}`), `leave_type` (minimal `{public_id, name, code, is_paid}`), `start_date`, `end_date`, `total_days`, `reason`, `status`, `history` (ordered array of `LeaveRequestActionResource`), `created_by` (minimal Staff shape or `null`, mirrors Task/WorkLog's `created_by` pattern), timestamps. No internal numeric ID anywhere.

**LeaveRequestActionResource:** `action`, `acted_by` (minimal Staff shape or `null` — the acting User's linked Staff record, mirroring `WorkLogResource.created_by`; `null` if the actor has no linked Staff, e.g. the seeded local Administrator), `note`, `created_at`.

**LeaveBalanceResource:** `leave_type` (minimal), `year`, `is_paid`, `allocated_days` (nullable — `null` for an unpaid type), `used_days`, `pending_days`, `remaining_days` (nullable, mirrors `allocated_days`), `notes`, timestamps.

## Filters

`GET /api/v1/leave-requests`: `?staff=<public_id>` (Administrator/Manager, further narrows an already-scoped result set), `?status=`, `?type=<public_id>`, `?from=`, `?to=`. `GET /api/v1/me/leave-requests`: `?status=`, `?type=`, `?from=`, `?to=` (never `?staff=` — always own). No advanced reporting.

## Database Indexes

`leave_requests`: `(staff_id, status)`, `(staff_id, start_date, end_date)` (overlap-check support), `(leave_type_id, status)`. `leave_request_actions`: `(leave_request_id, created_at)`. `leave_balances`: unique `(staff_id, leave_type_id, year)`.

## Staff Deletion Protection

`StaffController::destroy` (Phase 7) is extended: a Staff member with any Leave Request or Leave Balance still referencing them cannot be deleted (`409`, backed by `restrictOnDelete()`), regardless of Leave Request status — both are HR history, checked the same way Task assignment/Work Log references already are.

## Leave Type Deletion Protection

`LeaveTypeController::destroy` rejects deletion (`409`) while any Leave Request or Leave Balance references the type — preferring deactivation (`status: inactive`) instead, exactly as Department/Client/Project's own delete-protection precedent already establishes.

## Concurrency / Transaction Safety

Leave Request creation (the balance check + insert) and the approve action (the pending-status check + status update + history insert) each run inside a `DB::transaction()` with `lockForUpdate()` on the relevant row(s) — preventing a double-approval race and a concurrent-submission balance race, without introducing any distributed-locking infrastructure.

## Explicitly Out of Scope

Payroll, salary deduction, statutory payroll computation, attendance, clock-in/clock-out, time tracking, Work Log integration, shift scheduling, roster planning, overtime, holiday pay, an automatic statutory leave-law engine, a tenure-based accrual engine, monthly accrual, carry-forward, encashment/leave-to-cash conversion, medical-document uploads/attachments, multi-level approval chains, delegated/substitute approvers, an HR workflow engine, calendar sync, notifications/messaging/reminders, leave forecasting, staffing-capacity forecasting, payroll-period locking, approval SLA/automatic escalation, partial-day/half-day leave, and business-day/holiday-aware date calculation. No Admin Backoffice (Blade/Livewire) CRUD UI and no Flutter mobile screens — API/backend only, consistent with every prior business-module phase's precedent.

## Relevant Documentation

- `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md` §1 (HR entity group — `leave_types`/`leave_requests`/`leave_approvals`/`leave_balances`, resolved here as `leave_request_actions` for the approval-history table)
- `docs/04_API_CONVENTIONS.md` (explicit action endpoints over generic `PATCH status`)
- `docs/05_SECURITY_MODEL.md` (data isolation, least privilege)
- `docs/DECISIONS.md` DEC-010 (history over mutation), DEC-017 (identifiers), DEC-028 (authorization pattern), DEC-030 (Staff/manager precedent), DEC-032 (derive-don't-cache precedent), DEC-035 (Work Log eligibility/visibility precedent this phase mirrors closely)

## Acceptance Criteria

- A Staff member can submit, view, and cancel (subject to the rules above) their own Leave Requests and view their own Leave Balances; they can never see or act on another Staff member's.
- A Staff member's direct Manager can view, approve, and reject that Staff member's pending requests; an unrelated Manager cannot; a Manager cannot approve their own request.
- Administrator can view/approve/reject/cancel any request, create one on behalf of a Staff member, manage Leave Types, and set/update Leave Balance allocations.
- Overlapping `pending`/`approved` requests for the same Staff member are rejected; insufficient paid-leave balance is rejected at submission; unpaid Leave Types are never balance-checked.
- Approval/rejection/cancellation/submission are all recorded in `leave_request_actions`, preserved regardless of later Staff status, Manager, or Leave Type changes.
- Staff/Leave Type deletion is blocked while referenced by any Leave Request or Leave Balance.
- No internal numeric ID is ever exposed by an API response or accepted as client input — only `public_id`.
- All `CLAUDE.md` §5 quality gates pass, including the full Phase 1–12 regression suite.

## Testing Expectations

See `docs/handoffs/V1_PHASE_13_HANDOFF.md` for what was actually implemented and tested; broad categories: Leave Type CRUD/lifecycle/delete-protection; self-service creation (eligibility, date/overlap/balance validation, Leave Type validity); approval (Manager-of-record, unrelated Manager rejection, self-approval rejection, Administrator, duplicate-approval rejection); history (every action type, actor preserved after Manager change); cancellation (pending, approved-before-start, approved-after-start rejection, Administrator override, immutability of rejected/cancelled); balances (allocation, consumption, restoration on cancellation, insufficient-balance rejection, unpaid-type bypass); historical preservation (Staff status/Manager/Leave Type changes never alter existing records); full authorization matrix; integrity (Staff/Leave Type delete-protection, no internal IDs); full Phase 1–12 regression.
