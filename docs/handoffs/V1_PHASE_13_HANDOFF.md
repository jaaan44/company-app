# Phase 13 Handoff — Leave Management

**Phase:** 13 — Leave Management
**Date:** 2026-09-12
**Branch:** `claude/vibrant-johnson-vk7bhr` (branched from `main`, not merged)
**Depends on:** Phase 7 (Staff), Phase 5 (Roles & Permissions)

## 1. Objective

Build the foundational Leave Management module: configurable Leave Types, a Leave Request lifecycle with a single-stage Manager/Administrator approval model, an append-only approval-history record, and simple per-staff/per-type/per-year Leave Balances derived from approved requests rather than a mutable cache column. Not payroll, attendance, scheduling, or a statutory leave-law engine.

## 2. Scope Implemented

- `leave_types` table and `App\Models\LeaveType` — configurable master data: `public_id` (ULID), `name` (unique), `code` (nullable, unique when present), `description`, `is_paid` (boolean, default `true`), `status` (`App\Enums\LeaveTypeStatus` — `active`/`inactive`), `sort_order`.
- `leave_requests` table and `App\Models\LeaveRequest` — `public_id` (ULID), `staff_id`/`leave_type_id` (both `restrictOnDelete()`), `start_date`/`end_date`, server-computed `total_days`, required `reason`, `status` (`App\Enums\LeaveRequestStatus` — `pending`/`approved`/`rejected`/`cancelled`), `created_by_user_id` (nullable, `nullOnDelete()`). No `submitted_at`/`approved_at`/`rejected_at`/`cancelled_at` columns.
- `leave_request_actions` table and `App\Models\LeaveRequestAction` — append-only approval history: `leave_request_id` (`cascadeOnDelete()`), `action` (`App\Enums\LeaveRequestActionType` — `submitted`/`approved`/`rejected`/`cancelled`), `acted_by_user_id` (nullable, `nullOnDelete()`), `note`.
- `leave_balances` table and `App\Models\LeaveBalance` — `staff_id`/`leave_type_id` (both `restrictOnDelete()`), `year`, `allocated_days`, `notes`, `created_by_user_id` (nullable, `nullOnDelete()`), unique on `(staff_id, leave_type_id, year)`. `used_days`/`pending_days`/`remaining_days` are always derived live from `leave_requests` via static helpers (`LeaveBalance::allocatedDaysFor()`/`usedDaysFor()`/`pendingDaysFor()`), never a stored column.
- Lifecycle: `pending` → `approved`/`rejected` (Administrator or the requester's current direct Manager only) → immutable; `pending`/`approved` → `cancelled` (requester, own `pending` any time or own `approved` only before `start_date`; Administrator, any time). No edit endpoint at all.
- Self-service creation eligibility (active Staff status; sufficient paid-leave balance, checked once at submission inside a `lockForUpdate()` transaction) — Administrator-created requests skip both, but Leave Type activity, date-range validity (no cross-year span), and overlap (`pending`/`approved`, any Leave Type) are enforced identically for both paths.
- `StaffController::destroy` (Phase 7) extended to block deletion while any Leave Request/Balance references the staff member; new `LeaveTypeController::destroy` blocks deletion while any Leave Request/Balance references the type.
- Four new permissions (`leave-types.view`/`leave-types.manage`/`leave-requests.view`/`leave-requests.manage`) extend `RolePermissionSeeder`.
- Two endpoint families: self-service `/api/v1/me/leave-requests`/`/me/leave-balances`, and supervisory `/api/v1/leave-requests` (scoped reads; `/approve`/`/reject`/`/cancel` action endpoints) plus `/api/v1/leave-types` (full CRUD) and `/api/v1/staff/{public_id}/leave-balances`.
- `LeaveTypeFactory`/`LeaveRequestFactory`/`LeaveBalanceFactory`, `LeaveTypeResource`/`LeaveRequestResource`/`LeaveRequestActionResource`/`LeaveBalanceResource`.
- New tests: `LeaveTypeTest`, `LeaveRequestTest`, `LeaveRequestLifecycleTest`, `LeaveBalanceTest`, `LeaveAuthorizationTest`, plus additions to `RolePermissionSeederTest`.

## 3. Implementation Summary

**Domain model.** `03_DATABASE_MODEL.md` already sketched `leave_types`/`leave_requests`/`leave_approvals`/`leave_balances`. Resolved for V1: the approval-history table is named `leave_request_actions` (not `leave_approvals`), since it records every lifecycle event (submission, approval, rejection, cancellation), not only approvals. `leave_requests` deliberately carries no `submitted_at`/`approved_at`/`rejected_at`/`cancelled_at` columns — submission is `created_at`, and every decision/cancellation timestamp lives only on `leave_request_actions`, extending DEC-032's "derive, don't cache" precedent to decision timestamps.

**Balances are fully derived.** `leave_balances.allocated_days` is the only stored number; `used_days` (sum of `approved` requests' `total_days`), `pending_days` (sum of `pending` requests'), and `remaining_days` (`allocated - used - pending`) are computed live on every read via three small static helpers on `LeaveBalance`. Cancelling a previously-approved request "restores" balance automatically — there is no explicit restoration code path, since the derived sum simply stops counting it. Insufficient balance is rejected only at submission time (inside a `DB::transaction()` with `lockForUpdate()` on the relevant `leave_balances` row to prevent a concurrent-submission race) and never re-checked at approval — the submission-time check already accounts for every other outstanding `pending`/`approved` request, so the `allocated_days` invariant holds automatically.

**Approval authority.** A `pending` request's current direct Manager (`Staff.manager_id`, evaluated at decision time) or Administrator may approve/reject it — enforced in `LeaveRequestController::authorizeDecisionAuthority()`, which explicitly denies the requester themselves (defense in depth; `Staff::wouldCreateCycleWith()`, Phase 7, already makes self-management structurally impossible) before checking the manager-of-record relationship. This mirrors Phase 12's `work-logs.view`-plus-row-level-scope shape rather than Phase 11's permission-less Project Lead shape, since `leave-requests.view` doubles as part of the write-authority check here.

**No edit endpoint.** Among the governing instructions' candidate rules ("requester may edit own request while pending" vs. "no edit; cancel and resubmit"), the simpler rule was chosen — there is no `PUT`/`PATCH` route on `leave_requests` at all, self-service or Administrator. Correcting a mistaken request means cancelling it and submitting a new one.

**Eligibility vs. structural validity.** Mirroring DEC-035's Work Log precedent, Administrator-created requests (`POST /api/v1/leave-requests`) skip self-service *eligibility* checks (active Staff status, sufficient balance) but not *structural consistency* checks (Leave Type must be `active`, dates must be valid and non-overlapping) — an Administrator is trusted to backfill/correct a record for someone who may since have become inactive, but an inactive Leave Type is closed to new business for everyone.

**Concurrency.** Both leave-request creation (balance check) and the approve action (pending-status check) run inside `DB::transaction()` blocks with `lockForUpdate()`, preventing a double-approval race and a concurrent-submission balance race.

## 4. Files Changed

**Added:**
- `apps/api/database/migrations/2026_09_12_130000_create_leave_types_table.php`, `..._130001_create_leave_requests_table.php`, `..._130002_create_leave_request_actions_table.php`, `..._130003_create_leave_balances_table.php`
- `apps/api/app/Enums/LeaveTypeStatus.php`, `LeaveRequestStatus.php`, `LeaveRequestActionType.php`
- `apps/api/app/Models/LeaveType.php`, `LeaveRequest.php`, `LeaveRequestAction.php`, `LeaveBalance.php`
- `apps/api/app/Http/Controllers/Api/V1/Leave/LeaveTypeController.php`, `MyLeaveRequestController.php`, `LeaveRequestController.php`, `LeaveBalanceController.php`
- `apps/api/app/Http/Controllers/Api/V1/Leave/Concerns/AuthorizesLeaveRequestVisibility.php`
- `apps/api/app/Http/Requests/Leave/StoreLeaveTypeRequest.php`, `UpdateLeaveTypeRequest.php`, `StoreMyLeaveRequestRequest.php`, `StoreLeaveRequestRequest.php`, `ApproveLeaveRequestRequest.php`, `RejectLeaveRequestRequest.php`, `CancelLeaveRequestRequest.php`, `StoreLeaveBalanceRequest.php`
- `apps/api/app/Http/Requests/Leave/Concerns/ResolvesLeaveRequestReferences.php`, `ValidatesLeaveDateRangeAndOverlap.php`, `ValidatesSelfServiceLeaveEligibility.php`
- `apps/api/app/Http/Resources/LeaveTypeResource.php`, `LeaveRequestResource.php`, `LeaveRequestActionResource.php`, `LeaveBalanceResource.php`
- `apps/api/database/factories/LeaveTypeFactory.php`, `LeaveRequestFactory.php`, `LeaveBalanceFactory.php`
- `apps/api/tests/Feature/Api/V1/Leave/LeaveTypeTest.php`, `LeaveRequestTest.php`, `LeaveRequestLifecycleTest.php`, `LeaveBalanceTest.php`
- `apps/api/tests/Feature/Authorization/LeaveAuthorizationTest.php`
- `docs/phases/V1_PHASE_13_DEFINITION.md`
- `docs/handoffs/V1_PHASE_13_HANDOFF.md` (this file)

**Modified:**
- `apps/api/app/Models/Staff.php` — added `leaveRequests()`/`leaveBalances()` relations.
- `apps/api/app/Http/Controllers/Api/V1/Staff/StaffController.php` — `destroy()` Leave Request/Balance-reference checks.
- `apps/api/database/seeders/RolePermissionSeeder.php` — `leave-types.view`/`leave-types.manage`/`leave-requests.view`/`leave-requests.manage`.
- `apps/api/routes/api/v1.php` — `/leave-types`, `/me/leave-requests`, `/me/leave-balances`, `/leave-requests`, `/staff/{public_id}/leave-balances` route groups.
- `apps/api/tests/Feature/Authorization/RolePermissionSeederTest.php` — updated permission count (18→22), added Phase 13 permission tests.
- `docs/02_ARCHITECTURE.md` (new §23), `docs/03_DATABASE_MODEL.md`, `04_API_CONVENTIONS.md`, `05_SECURITY_MODEL.md`, `CURRENT_STATE.md`, `CHANGELOG.md`, `DECISIONS.md`, `ROADMAP.md`, `testing/UAT_LOG.md`.

## 5. Database/Schema Changes

Four new migrations (see §4). Indexes: `leave_requests` — `(staff_id, status)`, `(staff_id, start_date, end_date)`, `(leave_type_id, status)`; `leave_request_actions` — `(leave_request_id, created_at)`; `leave_balances` — unique `(staff_id, leave_type_id, year)`. No DB-level `CHECK` constraints — application-enforced consistency, matching this codebase's existing precedent.

## 6. API Changes

```
GET    /api/v1/leave-types                      leave-types.view
GET    /api/v1/leave-types/{public_id}           leave-types.view
POST   /api/v1/leave-types                       leave-types.manage
PUT/PATCH /api/v1/leave-types/{public_id}        leave-types.manage
DELETE /api/v1/leave-types/{public_id}           leave-types.manage (409 if referenced)

GET    /api/v1/me/leave-requests                 self
POST   /api/v1/me/leave-requests                 self (creates 'pending')
GET    /api/v1/me/leave-requests/{public_id}     self (404 if not own)
POST   /api/v1/me/leave-requests/{public_id}/cancel   self

GET    /api/v1/me/leave-balances                 self

GET    /api/v1/leave-requests                    scoped (Administrator: all; Manager: direct reports)
GET    /api/v1/leave-requests/{public_id}        scoped
POST   /api/v1/leave-requests                    leave-requests.manage
POST   /api/v1/leave-requests/{public_id}/approve     Administrator OR direct Manager
POST   /api/v1/leave-requests/{public_id}/reject      same authority; reason required
POST   /api/v1/leave-requests/{public_id}/cancel      leave-requests.manage

GET    /api/v1/staff/{public_id}/leave-balances  scoped (Administrator: any; Manager: direct reports)
POST   /api/v1/staff/{public_id}/leave-balances  leave-requests.manage (upsert)
```

No `PUT`/`PATCH`/`DELETE` for `leave_requests`. `LeaveRequestResource` includes a full `history` array (`LeaveRequestActionResource`) — no internal numeric ID anywhere.

## 7. Authorization/Security Changes

- New permissions: `leave-types.view` (Administrator/Manager/Staff, company-wide), `leave-types.manage` (Administrator-only), `leave-requests.view` (Manager only, scoped to direct reports), `leave-requests.manage` (Administrator-only).
- `App\Http\Controllers\Api\V1\Leave\Concerns\AuthorizesLeaveRequestVisibility` — Administrator sees all; Manager scoped to direct reports; no other role has access to `/leave-requests` (must use `/me/leave-requests`). Also backs approve/reject authority (`isDirectManagerOf()`) and Leave Balance staff-scoped viewing (`authorizeManagerOf()`).
- Approve/reject carry no permission route middleware — authority resolved entirely in-controller.

## 8. Tests Added or Changed

- `LeaveTypeTest` (18 tests): CRUD, defaults, deactivation, Staff read-only visibility (active-only filter), delete-protection (Leave Requests/Balances), Staff deletion protection (Leave Requests/Balances), historical preservation after deactivation, internal-ID rejection.
- `LeaveRequestTest` (31 tests): self-service creation (eligibility, Leave Type validity, date validation, cross-year rejection, overlap in all four combinations, balance validation including the two-pending-requests-together case), own-only visibility, no-edit-endpoint (405), Administrator on-behalf creation (eligibility/balance skipped, structural checks retained, cross-staff rejection for non-Administrators), filters.
- `LeaveRequestLifecycleTest` (22 tests): approval/rejection (direct Manager, unrelated Manager, self-approval, Administrator, no-manager staff, duplicate-decision rejection, immutability), full history coverage (submission/approval/rejection/cancellation, actor preserved after a Manager change), cancellation (pending, approved-before-start, approved-after-start rejection, Administrator override, Manager cannot cancel, balance restoration), historical preservation after Staff status change.
- `LeaveBalanceTest` (13 tests): allocation set/update (upsert, not duplicate), self/Manager/unrelated-Manager visibility, every-active-type reporting (including no-allocation-row and unpaid-type cases), approved/pending/rejected consumption, correct calendar-year scoping, Manager write-denial.
- `LeaveAuthorizationTest` (12 tests): Administrator, Manager (direct-report-scoped, unrelated-denied), Manager cannot create-on-behalf or Administrator-cancel, Staff cannot access the top-level endpoint, coworker privacy despite no shared-Project concept in this domain, no-linked-staff/no-role/unlinked-user self-service boundaries, unauthenticated, suspended account.
- `RolePermissionSeederTest`: updated permission count (18→22), added `leave-types.view`/`leave-requests.view` Manager-only-vs-Staff tests.
- Full Phase 1–12 regression suite verified passing unmodified in behavior (all 463 tests pass together).

## 9. Commands/Checks Executed

```
composer install --no-interaction --prefer-dist --no-progress
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan migrate:fresh --force
php artisan db:seed --class=Database\Seeders\RolePermissionSeeder --force
php artisan test
```

Plus a real end-to-end HTTP smoke test: `php artisan serve`, seeded an Administrator, a Manager with a linked Staff record, and a Staff member reporting to that Manager (via Tinker), then exercised the actual running server with `curl` — Administrator creates a Leave Type and sets a Leave Balance allocation for the Staff member; the Staff member submits a Leave Request (`POST /api/v1/me/leave-requests`); the Manager approves it (`POST /api/v1/leave-requests/{id}/approve`); the Staff member's `GET /api/v1/me/leave-balances` correctly showed `used_days: 3`/`remaining_days: 7`; the Staff member was confirmed `403` on the top-level `/api/v1/leave-requests`; Staff/Leave-Type deletion were both confirmed `409` while referenced; the Staff member then cancelled the approved (not-yet-started) request (`POST .../cancel`), which succeeded and appended a `cancelled` history entry alongside the existing `submitted`/`approved` entries.

## 10. Results

- `composer validate --strict`: **valid**.
- `vendor/bin/pint --test`: **passed** (no style violations).
- `vendor/bin/phpstan analyse` (level 5): **0 errors**.
- `php artisan migrate:fresh --force`: all 25 migrations run cleanly, including the four new Leave tables.
- `php artisan db:seed --class=RolePermissionSeeder`: runs cleanly, produces the expected 22 permissions including the four new `leave-types.*`/`leave-requests.*` permissions.
- `php artisan test`: **463/463 passing, 1262 assertions** (full suite — Phases 1–13).
- Real HTTP smoke test: every request behaved as designed (see §9).

## 11. Deviations from Specification

- **A real correctness bug found and fixed during quality-gate testing.** The original overlap-detection queries (in `ValidatesLeaveDateRangeAndOverlap`, `MyLeaveRequestController::myStore`, and `LeaveRequestController::store`) compared `start_date`/`end_date` with plain `where('start_date', '<=', $endDate->toDateString())`-style calls. On SQLite (the test database), a `date`-cast column is persisted with a full datetime suffix (e.g. `2027-01-11T00:00:00.000000Z`), which breaks a plain lexicographic string comparison exactly on a same-day boundary (a value with a trailing time suffix sorts as lexicographically *greater than* a bare date string with the same date prefix) — a genuine overlap could be missed when an existing request's `start_date` equalled a new request's `end_date` (or vice versa). Caught by `LeaveRequestTest::test_an_overlapping_approved_request_is_rejected_regardless_of_leave_type` during the first full test run (an existing approved request `2027-01-11`–`2027-01-16` failed to block a new single-day request on `2027-01-11` itself). Fixed by switching all three call sites to `whereDate('start_date', '<=', ...)`/`whereDate('end_date', '>=', ...)`, which correctly extracts just the date portion regardless of the underlying storage format — this is the same reason `MyLeaveRequestController::myIndex`'s own `?from=`/`?to=` filters already used `whereDate()` rather than plain `where()`, a precedent this fix now applies consistently everywhere a `LeaveRequest` date column is range-compared.
- No other deviation from `docs/phases/V1_PHASE_13_DEFINITION.md`.

## 12. Known Issues/Limitations

- No partial-day/half-day leave (not required by any governing document for this phase).
- No cross-year leave requests (a deliberate, documented V1 limitation — an employee whose leave spans a year boundary submits two requests).
- As with every prior module, there is no Admin Backoffice (Blade/Livewire) CRUD UI and no Flutter mobile screens — API/backend only.

## 13. Manual/UAT Testing Instructions

1. Seed the database: `php artisan migrate:fresh --seed` (uses `RolePermissionSeeder`; add `AdminUserSeeder` separately for a local Administrator login).
2. As Administrator, create a Leave Type (`POST /api/v1/leave-types`), a Staff record linked to a second User, and a Leave Balance allocation for that Staff member (`POST /api/v1/staff/{public_id}/leave-balances`).
3. Log in as the linked Staff User and `POST /api/v1/me/leave-requests` — confirm `201`, `status: pending`, and a `submitted` history entry.
4. Create a Manager Staff/User, set the requester's `manager_id` to that Manager, log in as the Manager, and `POST /api/v1/leave-requests/{public_id}/approve` — confirm `200` and an `approved` history entry.
5. As the requester, confirm `GET /api/v1/me/leave-balances` now shows the days consumed; `POST .../cancel` the approved request (before its start date) — confirm the balance is restored.
6. As an unrelated Manager, confirm `GET`/`approve`/`reject` on the same request all return `403`.
7. Attempt to delete the Leave Type/Staff record referenced above — confirm each is blocked with `409`.

No UAT `PASS` is recorded here — per CLAUDE.md §7, only the product owner may record that in `docs/testing/UAT_LOG.md`.

## 14. Documentation Updated

`docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/ROADMAP.md`, `docs/02_ARCHITECTURE.md` (new §23), `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/DECISIONS.md` (new DEC-036), `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`, `docs/phases/V1_PHASE_13_DEFINITION.md`, this handoff.

## 15. Recommended Next Step

Per `docs/ROADMAP.md`, the next planned phase is **Phase 14 — Announcements** — not authorized to begin without explicit product-owner direction.
