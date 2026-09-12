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
- Self-service creation eligibility: active Staff status (Administrator-created requests skip only this). Sufficient paid-leave balance is checked once at submission inside a `lockForUpdate()` transaction, **identically for self-service and Administrator-created requests** — there is no Administrator override or negative-balance concept (see §11's Post-Review Correction). Leave Type activity, date-range validity (no cross-year span), and overlap (`pending`/`approved`, any Leave Type) are likewise enforced identically for both paths.
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

**Employment eligibility vs. balance/entitlement integrity.** Mirroring DEC-035's Work Log precedent, Administrator-created requests (`POST /api/v1/leave-requests`) skip self-service *employment eligibility* (active Staff status only) — an Administrator is trusted to backfill/correct a record for someone who may since have become inactive or separated. Administrator does **not** skip anything else: balance sufficiency, Leave Type activity, and date-range/overlap validity are all structural/entitlement rules enforced identically for both creation paths. (This line originally — incorrectly — grouped "sufficient balance" with the employment-eligibility checks Administrator may skip; see §11's Post-Review Correction for the bug this caused and how it was fixed.)

**Concurrency.** Both leave-request creation (balance check) and the approve action (pending-status check) run inside `DB::transaction()` blocks with `lockForUpdate()`, preventing a double-approval race and a concurrent-submission balance race.

## 4. Files Changed

**Added:**
- `apps/api/database/migrations/2026_09_12_130000_create_leave_types_table.php`, `..._130001_create_leave_requests_table.php`, `..._130002_create_leave_request_actions_table.php`, `..._130003_create_leave_balances_table.php`
- `apps/api/app/Enums/LeaveTypeStatus.php`, `LeaveRequestStatus.php`, `LeaveRequestActionType.php`
- `apps/api/app/Models/LeaveType.php`, `LeaveRequest.php`, `LeaveRequestAction.php`, `LeaveBalance.php`
- `apps/api/app/Http/Controllers/Api/V1/Leave/LeaveTypeController.php`, `MyLeaveRequestController.php`, `LeaveRequestController.php`, `LeaveBalanceController.php`
- `apps/api/app/Http/Controllers/Api/V1/Leave/Concerns/AuthorizesLeaveRequestVisibility.php`, `ChecksLeaveBalanceAvailability.php` (added post-review — see §11)
- `apps/api/app/Http/Requests/Leave/StoreLeaveTypeRequest.php`, `UpdateLeaveTypeRequest.php`, `StoreMyLeaveRequestRequest.php`, `StoreLeaveRequestRequest.php`, `ApproveLeaveRequestRequest.php`, `RejectLeaveRequestRequest.php`, `CancelLeaveRequestRequest.php`, `StoreLeaveBalanceRequest.php`
- `apps/api/app/Http/Requests/Leave/Concerns/ResolvesLeaveRequestReferences.php`, `ValidatesLeaveDateRangeAndOverlap.php`, `ValidatesSelfServiceLeaveEligibility.php`, `ValidatesLeaveBalanceAvailability.php` (added post-review — see §11)
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
- `App\Http\Controllers\Api\V1\Leave\Concerns\ChecksLeaveBalanceAvailability` (added post-review, §11) — the single, shared, transactional balance-sufficiency check used by both `MyLeaveRequestController` and `LeaveRequestController`; there is no Administrator authorization surface that bypasses it.

## 8. Tests Added or Changed

- `LeaveTypeTest` (18 tests): CRUD, defaults, deactivation, Staff read-only visibility (active-only filter), delete-protection (Leave Requests/Balances), Staff deletion protection (Leave Requests/Balances), historical preservation after deactivation, internal-ID rejection.
- `LeaveRequestTest` (35 tests): self-service creation (eligibility, Leave Type validity, date validation, cross-year rejection, overlap in all four combinations, balance validation including the two-pending-requests-together case), own-only visibility, no-edit-endpoint (405), Administrator on-behalf creation, filters. **Post-review correction (§11):** the Administrator-creation coverage was rewritten from a single "skips the balance check" test into six scenarios matching the corrected behavior — active-staff-eligibility bypass (now with a real allocation set up first), rejection with no allocation, rejection when exceeding remaining allocation, success with sufficient allocation, pending-requests-consume-capacity-like-self-service (the two-submission invariant, mirrored from the self-service test), and unpaid-type creation needing no allocation at all.
- `LeaveRequestLifecycleTest` (22 tests): approval/rejection (direct Manager, unrelated Manager, self-approval, Administrator, no-manager staff, duplicate-decision rejection, immutability), full history coverage (submission/approval/rejection/cancellation, actor preserved after a Manager change), cancellation (pending, approved-before-start, approved-after-start rejection, Administrator override, Manager cannot cancel, balance restoration), historical preservation after Staff status change.
- `LeaveBalanceTest` (13 tests): allocation set/update (upsert, not duplicate), self/Manager/unrelated-Manager visibility, every-active-type reporting (including no-allocation-row and unpaid-type cases), approved/pending/rejected consumption, correct calendar-year scoping, Manager write-denial.
- `LeaveAuthorizationTest` (12 tests): Administrator, Manager (direct-report-scoped, unrelated-denied), Manager cannot create-on-behalf or Administrator-cancel, Staff cannot access the top-level endpoint, coworker privacy despite no shared-Project concept in this domain, no-linked-staff/no-role/unlinked-user self-service boundaries, unauthenticated, suspended account.
- `RolePermissionSeederTest`: updated permission count (18→22), added `leave-types.view`/`leave-requests.view` Manager-only-vs-Staff tests.
- Full Phase 1–12 regression suite (including every pre-existing self-service balance test, e.g. `test_two_pending_requests_together_cannot_exceed_the_allocation`) verified passing unmodified in behavior.
- **Concurrent-submission protections:** both creation paths now go through the exact same `App\Http\Controllers\Api\V1\Leave\Concerns\ChecksLeaveBalanceAvailability::assertSufficientBalance()` — the same `lockForUpdate()`-guarded transactional re-check self-service already had. There is no separate, un-protected code path for Administrator creation to regress to. This is proven at the practical level PHPUnit's single-connection test process can exercise: the sequential two-submission invariant test (`test_administrator_created_pending_requests_consume_capacity_like_self_service`, mirroring the pre-existing self-service `test_two_pending_requests_together_cannot_exceed_the_allocation`) — genuine concurrent-request locking cannot be exercised from a single-process, single-connection PHPUnit feature test, so this is the same honest proxy this codebase's existing test suite already relies on for the self-service path.

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

Plus two rounds of real end-to-end HTTP smoke testing via `php artisan serve` + `curl` (Tinker-seeded fixtures each time):

- **Initial round:** Administrator creates a Leave Type and sets a Leave Balance allocation for a Staff member; the Staff member submits a Leave Request (`POST /api/v1/me/leave-requests`); the Manager approves it (`POST /api/v1/leave-requests/{id}/approve`); the Staff member's `GET /api/v1/me/leave-balances` correctly showed `used_days: 3`/`remaining_days: 7`; the Staff member was confirmed `403` on the top-level `/api/v1/leave-requests`; Staff/Leave-Type deletion were both confirmed `409` while referenced; the Staff member then cancelled the approved (not-yet-started) request, which succeeded and appended a `cancelled` history entry.
- **Post-review correction round (§11):** four Administrator on-behalf scenarios against a real running server — (1) an **inactive** Staff member **with** a sufficient allocation → `201` (eligibility bypass still works); (2) a Staff member with **no** allocation row at all → `422` (`"Insufficient leave balance: 0 day(s) remaining..."`); (3) a Staff member with a 2-day allocation, requesting 5 days → `422` (`"Insufficient leave balance: 2 day(s) remaining..."`); (4) the same Staff member requesting exactly the 2 remaining days → `201`, after which `GET .../leave-balances` correctly showed `used_days: 0, pending_days: 2, remaining_days: 0` — never negative.

## 10. Results

- `composer validate --strict`: **valid**.
- `vendor/bin/pint --test`: **passed** (no style violations).
- `vendor/bin/phpstan analyse` (level 5): **0 errors**.
- `php artisan migrate:fresh --force`: all 25 migrations run cleanly, including the four new Leave tables.
- `php artisan db:seed --class=RolePermissionSeeder`: runs cleanly, produces the expected 22 permissions including the four new `leave-types.*`/`leave-requests.*` permissions.
- `php artisan test`: **467/467 passing, 1273 assertions** (full suite — Phases 1–13, post-correction; 4 tests / 11 assertions added net versus the pre-correction 463/1262).
- Real HTTP smoke tests (both rounds): every request behaved as designed (see §9).

## 11. Deviations from Specification

- **A real correctness bug found and fixed during quality-gate testing (pre-review).** The original overlap-detection queries (in `ValidatesLeaveDateRangeAndOverlap`, `MyLeaveRequestController::myStore`, and `LeaveRequestController::store`) compared `start_date`/`end_date` with plain `where('start_date', '<=', $endDate->toDateString())`-style calls. On SQLite (the test database), a `date`-cast column is persisted with a full datetime suffix (e.g. `2027-01-11T00:00:00.000000Z`), which breaks a plain lexicographic string comparison exactly on a same-day boundary (a value with a trailing time suffix sorts as lexicographically *greater than* a bare date string with the same date prefix) — a genuine overlap could be missed when an existing request's `start_date` equalled a new request's `end_date` (or vice versa). Caught by `LeaveRequestTest::test_an_overlapping_approved_request_is_rejected_regardless_of_leave_type` during the first full test run (an existing approved request `2027-01-11`–`2027-01-16` failed to block a new single-day request on `2027-01-11` itself). Fixed by switching all three call sites to `whereDate('start_date', '<=', ...)`/`whereDate('end_date', '>=', ...)`, which correctly extracts just the date portion regardless of the underlying storage format — this is the same reason `MyLeaveRequestController::myIndex`'s own `?from=`/`?to=` filters already used `whereDate()` rather than plain `where()`, a precedent this fix now applies consistently everywhere a `LeaveRequest` date column is range-compared.

### Post-Review Correction (2026-09-12)

Product-owner review of the completed phase identified a balance-model inconsistency: `StoreLeaveRequestRequest`/`LeaveRequestController::store()` (the Administrator on-behalf creation path) performed **no balance check at all**, an unintended, undocumented Administrator negative-balance override — capable of driving `used_days + pending_days` above `allocated_days` (a negative derived `remaining_days`), directly contradicting this phase's own "no override/negative-balance concept" design (DEC-036).

**Root cause.** The original design (`docs/phases/V1_PHASE_13_DEFINITION.md`'s "Leave Type Validity vs. Self-Service Staff Eligibility" section, and the matching prose in this handoff and DEC-036) incorrectly grouped "sufficient balance" together with "active Staff status" as things Administrator creation "skips both of" — treating balance sufficiency as if it were staff-eligibility-shaped, when it is actually a structural entitlement-integrity rule (like Leave Type activity or overlap, both of which were already correctly enforced for Administrator creation). The implementation faithfully matched that incorrect design.

**Correction required and implemented, exactly as specified:**
- Administrator **still** bypasses only the Staff active-employment-status requirement (`ValidatesSelfServiceLeaveEligibility`, narrowed to cover only this one check).
- Administrator **no longer** bypasses paid-leave balance sufficiency, Leave Type activity, date-range/cross-year validity, or overlap — all four are now enforced identically to self-service, with no override flag, no allow-negative-balance configuration, no new permission, no new workflow, and no balance-adjustment ledger introduced.
- **No balance logic was duplicated.** Two new shared, single-implementation helpers replace what would otherwise have been two divergent copies: `App\Http\Requests\Leave\Concerns\ValidatesLeaveBalanceAvailability` (the Form Request validation layer, used by both `StoreMyLeaveRequestRequest` and `StoreLeaveRequestRequest`) and `App\Http\Controllers\Api\V1\Leave\Concerns\ChecksLeaveBalanceAvailability::assertSufficientBalance()` (the transactional, `lockForUpdate()`-guarded layer, used by both `MyLeaveRequestController::myStore()` and `LeaveRequestController::store()` — the latter previously had no balance re-check of any kind, and now computes and passes `$year`/`$totalDays` into the same shared assertion self-service already relied on).
- `ValidatesSelfServiceLeaveEligibility` was narrowed from a combined "active status + balance" check down to only the active-status check; the balance portion moved verbatim into the new `ValidatesLeaveBalanceAvailability` trait, called by *both* Store requests (self-service unconditionally; Administrator only when `staff_id` resolved to a real Staff member).
- All governing documentation (`docs/phases/V1_PHASE_13_DEFINITION.md`, `docs/DECISIONS.md` DEC-036, `docs/02_ARCHITECTURE.md` §23, `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`) was corrected to state the single, consistent rule: **Administrator bypasses employment eligibility, never balance/entitlement integrity.**

**Tests corrected/added** (see §8): the single, now-incorrect `test_administrator_creation_skips_the_balance_check` (which asserted `201` with no allocation at all) was replaced with six tests covering the corrected behavior — rejection with no allocation, rejection when exceeding remaining allocation, success with sufficient allocation, the eligibility-bypass test now setting up a real allocation first, the two-submission capacity-consumption invariant mirrored from self-service, and unpaid-type creation needing no allocation. Two further pre-existing Administrator-creation tests (`test_administrator_can_create_a_leave_request_on_behalf_of_a_staff_member`, `test_administrator_creation_skips_the_active_staff_eligibility_check`) needed a `LeaveBalance` fixture added since they now correctly require one. All Phase 1–12 regression and every pre-existing self-service balance test remained green throughout — see §10 for final counts.

No other deviation from `docs/phases/V1_PHASE_13_DEFINITION.md`.

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
8. **Balance-integrity check (post-review correction):** as Administrator, create a request on behalf of a *different* Staff member who has **no** Leave Balance allocation for that Leave Type/year — confirm `422` (`"Insufficient leave balance: 0 day(s) remaining..."`); set a small allocation (e.g. 2 days) for them and request more days than that — confirm `422` naming the actual remaining days; request exactly the remaining days — confirm `201`; confirm `GET .../leave-balances` afterward shows `remaining_days: 0`, never negative. Separately, confirm Administrator can still create a request for an **inactive/separated** Staff member once an allocation exists for them.

No UAT `PASS` is recorded here — per CLAUDE.md §7, only the product owner may record that in `docs/testing/UAT_LOG.md`.

## 14. Documentation Updated

`docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/ROADMAP.md`, `docs/02_ARCHITECTURE.md` (§23), `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/DECISIONS.md` (DEC-036, plus its Correction), `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`, `docs/phases/V1_PHASE_13_DEFINITION.md`, this handoff — all corrected in the post-review pass (§11) to state the single, consistent rule: Administrator bypasses employment eligibility, never balance/entitlement integrity.

## 15. Recommended Next Step

Per `docs/ROADMAP.md`, the next planned phase is **Phase 14 — Announcements** — not authorized to begin without explicit product-owner direction.
