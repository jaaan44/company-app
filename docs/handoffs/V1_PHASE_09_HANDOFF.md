# Phase 9 Handoff — Staff Status & Location Check-in

## 1. Phase Identification / Objective

- **Phase:** 9 — Staff Status & Location Check-in
- **Date:** 2026-09-11
- **Branch:** `claude/company-app-v1-phase-9-status-checkin` (from `main` @ `6a90d59`, the merge commit for PR #10, which contains the approved Phase 1–8 content)
- **Objective:** build the foundational Staff Operational Status & Location Check-in module — a lightweight, explicit, self-service way for a Staff member to expose a current operational/work status and perform location check-ins, with a small history of both, and appropriate (not blanket) visibility for company users. Operational visibility, not surveillance, attendance enforcement, or continuous tracking.

## 2. Repository Recovery / Main Verification

Checked out and pulled `origin/main`, confirmed the working tree clean, and confirmed the Phase 8 merge (`8541dd5`, PR #10's merge commit `6a90d59`) present. Read, in order: `CLAUDE.md`, `docs/CURRENT_STATE.md`, `docs/ROADMAP.md`, `docs/DECISIONS.md`, `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/06_UI_UX_GUIDELINES.md`, `docs/testing/{TEST_PLAN,TEST_STATUS,UAT_LOG}.md`, `docs/handoffs/README.md`, `docs/handoffs/V1_PHASE_07_HANDOFF.md`, `docs/handoffs/V1_PHASE_08_HANDOFF.md`, and the existing codebase — `Staff`/`User`/`Role`/`Permission` models, `StaffController`/`ClientController` and their Form Requests/Resources/routes/factories, `RolePermissionSeeder`, `AppServiceProvider`'s `Gate::before`, `EnsureAccountIsActive`, and the full existing test suite (176 tests, all passing before this phase's changes).

**Key finding from inspection:** no Admin Backoffice (Blade/Livewire) CRUD screen and no Flutter mobile screen exists for any module yet — this phase again follows that precedent (API/backend only). No prior location-related code existed anywhere in the repository.

## 3. Final Domain Model / Key Decisions (DEC-032)

- **Critical separation, verified in code, not just documentation:** `Staff.status` (`App\Enums\StaffStatus`, Phase 7 employment lifecycle) is never read or written by any Phase 9 code path — confirmed by a dedicated test (`test_setting_operational_status_never_changes_employment_status`). This phase's operational status lives entirely in its own table and enum (`App\Enums\OperationalStatus`).
- **Operational status:** five values — `available`/`busy`/`in_meeting`/`in_field`/`off_duty` — manually set, never inferred; no presence infrastructure (no heartbeat, idle detection, WebSocket presence, or online/offline tracking).
- **Storage — append-only history, no denormalized cache:** `staff_statuses` (`App\Models\StaffOperationalStatus`) and `staff_checkins` (`App\Models\StaffCheckIn`) are both append-only. Neither table, nor `staff` itself, carries a mutable "current value" column. `Staff::latestOperationalStatus()`/`latestCheckIn()` (`hasOne(...)->latestOfMany()`, indexed via `(staff_id, created_at)`) derive "current" from the latest row. A staff member with no history has a `null` current status/location — never an assumed default (e.g. never defaulted to "available"), symmetric with "absence of a check-in is not absence."
- **Location check-in:** an explicit, user-triggered action (`POST /api/v1/me/check-ins`) — no continuous/background GPS, polling, or geofencing exists anywhere in the codebase (DEC-005 unchanged). Required `latitude`/`longitude` (`decimal(10,7)`, validated -90..90/-180..180); optional `accuracy_meters`, `location_label` (free text — no Location Master/catalog module), `note` (≤500 chars, operational annotation only), and an informational `status` snapshot that does **not** also write a `staff_statuses` row (verified by `test_check_in_accepts_an_optional_operational_status_snapshot`). Server timestamps (`created_at`) are the sole authoritative check-in time — no client-supplied historical timestamp is accepted anywhere.
- **Immutability:** check-ins are never edited or deleted by ordinary users. The only mutation is Administrator deletion (`DELETE /api/v1/check-ins/{public_id}`, `location.manage`) — no general audit subsystem was built.
- **`cascadeOnDelete()`, not `restrictOnDelete()`:** both new tables cascade-delete when their parent `staff` row is deleted — a deliberate departure from every prior phase's `restrictOnDelete()` pattern, because this is Staff-owned child data with no independent business meaning (unlike Department/Team/Position/Client, which other rows depend on).
- **Staff↔User requirement unchanged:** self-service actions require the authenticated `User` to have a linked `Staff` record (Phase 7's optional `staff.user_id`, untouched). A `User` with no linked `Staff` gets `403` (`RequiresLinkedStaff` trait, shared by both controllers) — a domain check, not a new authentication mechanism.

## 4. Authorization / Privacy (the phase's central design question)

Four new permissions added to the existing `RolePermissionSeeder` (Phase 5–8 pattern, no new mechanism):

| Permission | Grantees | Purpose |
|---|---|---|
| `staff-status.view` | Administrator (override) / Manager / Staff | View any staff member's operational status — company-wide, low sensitivity |
| `staff-status.manage` | Administrator only | Set/correct another staff member's status on their behalf |
| `location.view` | Administrator (override) / **Manager only** | View another staff member's check-in history/current location — **necessary but not sufficient**: `CheckInController::authorizeViewingLocationOf()` further restricts a non-Administrator holder to their own direct reports (`Staff.manager_id`), never company-wide |
| `location.manage` | Administrator only | Delete/correct a specific historical check-in |

This is the **first real implementation** of `05_SECURITY_MODEL.md`'s previously "not yet applicable" data-isolation principle — a permission grants *whether* a Manager may view *someone's* check-ins; a second, explicit query/controller-level check decides *whose*. Self-service (`/me/status`, `/me/check-ins`) requires none of the four permissions — only a linked Staff record, mirroring `GET /api/v1/auth/me`'s precedent.

## 5. Database / Schema Changes

Two new migrations:
- `2026_09_11_090000_create_staff_statuses_table.php` — `id`, `staff_id` (`constrained('staff')->cascadeOnDelete()`), `status` (string, not nullable), `changed_by_user_id` (nullable, `constrained('users')->nullOnDelete()`), timestamps, index `(staff_id, created_at)`. No `public_id` (never independently addressed by URL).
- `2026_09_11_090001_create_staff_checkins_table.php` — `id`, `public_id` (ULID, unique), `staff_id` (`constrained('staff')->cascadeOnDelete()`), `latitude`/`longitude` (`decimal(10,7)`, not nullable), `accuracy_meters` (unsigned integer, nullable), `location_label` (string, nullable), `note` (string(500), nullable), `status` (string, nullable), timestamps, index `(staff_id, created_at)`.

## 6. Models

`App\Models\StaffOperationalStatus` — table `staff_statuses`; casts `status` to `OperationalStatus`; relations `staff()`, `changedBy()`.

`App\Models\StaffCheckIn` — table `staff_checkins`; casts `latitude`/`longitude` to `decimal:7`, `status` to `OperationalStatus`; assigns `public_id` in a `creating` hook (same pattern as every other ULID-bearing model); `getRouteKeyName()` returns `public_id`; relation `staff()`.

`App\Models\Staff` gained four relations: `operationalStatuses()`/`checkIns()` (`HasMany`, full history) and `latestOperationalStatus()`/`latestCheckIn()` (`HasOne`, `latestOfMany()`, the derived-current pattern).

## 7. API / Endpoints

| Method | Path | Access |
|---|---|---|
| `GET` | `/me/status` | self (linked Staff required) |
| `POST` | `/me/status` | self (linked Staff required) |
| `GET` | `/me/check-ins` | self (linked Staff required) |
| `POST` | `/me/check-ins` | self (linked Staff required) |
| `GET` | `/staff/{public_id}/status` | `staff-status.view` |
| `POST` | `/staff/{public_id}/status` | `staff-status.manage` |
| `GET` | `/staff/{public_id}/check-ins` | `location.view` + Manager scoped to direct reports |
| `DELETE` | `/check-ins/{public_id}` | `location.manage` |

"Updating" status or creating a check-in is modeled as **appending** to the same paginated, latest-first collection the corresponding `GET` returns — the first page item is always "current." This is the first `/me/...` self-scoped resource pattern beyond `/auth/me`, and the second demonstration (after Contact→Client) of a flat, scoped-by-another-resource's-`public_id` endpoint over deep nesting.

`App\Http\Controllers\Api\V1\StaffOperations\{OperationalStatus,CheckIn}Controller`; `App\Http\Requests\StaffOperations\{StoreOperationalStatusRequest,StoreCheckInRequest}`; `App\Http\Resources\{OperationalStatus,CheckIn}Resource`; shared `App\Http\Controllers\Api\V1\StaffOperations\Concerns\RequiresLinkedStaff` trait.

**Directory integration:** `StaffResource` gains `operational_status` (nullable) — visible to any `staff.view` holder (identical grantees to `staff-status.view`). No location data of any kind was added to `StaffResource`.

## 8. Validation / Integrity

- **Status:** `status` required, must be a valid `OperationalStatus` value (`Illuminate\Validation\Rules\Enum`).
- **Check-in:** `latitude`/`longitude` required, `numeric`, `between:-90,90`/`between:-180,180`; `accuracy_meters` optional integer `0`–`100000`; `location_label` optional, max 100; `note` optional, max 500; `status` optional, valid `OperationalStatus` when present.
- **Self-service target resolution:** the acting staff member is always derived server-side (`$request->user()->staff`) — never accepted from the request body, so a Staff user cannot self-service on behalf of another staff member.
- **Manager scoping:** enforced in `CheckInController::authorizeViewingLocationOf()` via `$target->manager_id === $requesterStaff->id` — never merely hidden client-side.

## 9. Factories / Seeders

`StaffOperationalStatusFactory`, `StaffCheckInFactory` — standard Eloquent factories for test data. No production/demo seeder — consistent with every prior business-module phase's reasoning (this is business-owned operational data, not fixed system catalog data). `RolePermissionSeeder` extended with the four new permissions (§4).

## 10. Files Changed

**Added (backend):** `app/Enums/OperationalStatus.php`; `app/Models/{StaffOperationalStatus,StaffCheckIn}.php`; `database/migrations/2026_09_11_090000_create_staff_statuses_table.php`; `database/migrations/2026_09_11_090001_create_staff_checkins_table.php`; `database/factories/{StaffOperationalStatus,StaffCheckIn}Factory.php`; `app/Http/Controllers/Api/V1/StaffOperations/{OperationalStatus,CheckIn}Controller.php`; `app/Http/Controllers/Api/V1/StaffOperations/Concerns/RequiresLinkedStaff.php`; `app/Http/Requests/StaffOperations/{StoreOperationalStatusRequest,StoreCheckInRequest}.php`; `app/Http/Resources/{OperationalStatus,CheckIn}Resource.php`; `tests/Feature/Api/V1/StaffOperations/{OperationalStatusTest,CheckInTest}.php`; `tests/Feature/Authorization/StaffOperationsAuthorizationTest.php`.

**Modified (backend):** `app/Models/Staff.php` (four new relations); `app/Http/Resources/StaffResource.php` (`operational_status` field); `app/Http/Controllers/Api/V1/Staff/StaffController.php` (`WITH_RELATIONS` gains `latestOperationalStatus`); `database/seeders/RolePermissionSeeder.php` (four new permissions + Manager/Staff attachment); `routes/api/v1.php` (new route group); `tests/Feature/Api/V1/Staff/StaffTest.php` (1 new test — directory reflects operational status); `tests/Feature/Authorization/RolePermissionSeederTest.php` (2 new tests for the extended catalog).

**Added (docs):** `docs/phases/V1_PHASE_09_DEFINITION.md`, this handoff.

**Modified (docs):** `docs/02_ARCHITECTURE.md` (new §19), `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/ROADMAP.md` (Phase 9 marked complete), `docs/CHANGELOG.md`, `docs/CURRENT_STATE.md`, `docs/DECISIONS.md` (DEC-032), `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`.

No `apps/mobile` files changed — Flutter is entirely unaffected.

## 11. Tests Added/Changed

**40 new PHPUnit tests:**
- `OperationalStatusTest` (11) — self default/no-history, self-update, ordering (latest first), invalid/missing value rejected, employment status unaffected, no-linked-staff rejected; viewing another staff member (allowed, broad), managing another's status (staff forbidden, Administrator allowed as correction, Manager view-only).
- `CheckInTest` (17) — successful check-in, optional status snapshot decoupled from status history, coordinate range validation, required fields, invalid status snapshot, note length limit, multiple check-ins/ordering, no-linked-staff rejected; Administrator/Manager/Staff privileged-viewing rules including direct-report scoping and its negative case; deletion (Administrator allowed, non-Administrator forbidden, never addressable by internal id).
- `StaffOperationsAuthorizationTest` (9) — Administrator full access; Manager view+scoped-location/no-manage; Staff self-service+broad-status-view/no-location/no-manage; no-role denied; no-linked-staff denied on all self-service routes; unauthenticated `401`; suspended mid-session `403`; two dedicated precise-location-visibility tests (ordinary Staff never obtains another's location; Manager limited to direct reports, not company-wide).
- 2 new tests in `RolePermissionSeederTest` for the extended permission catalog (including the asymmetric Manager-gets-`location.view`/Staff-doesn't assertion).
- 1 new test in `StaffTest` confirming the Staff Directory reflects current operational status.

**Unaffected in behavior:** all 176 Phase 1–8 tests pass unmodified.

## 12. Commands/Checks Executed

| Command | Result |
|---|---|
| `composer validate --strict` | `./composer.json is valid` |
| `vendor/bin/pint --test` | Initially flagged one test file (trailing-comma style in an array destructuring expression); `vendor/bin/pint` fixed it — the fix mechanically produced an invalid empty `[]` list-assignment in two tests, caught immediately by the next `php artisan test` run and corrected by removing the unused destructuring; re-run: `{"tool":"pint","result":"passed"}` |
| `vendor/bin/phpstan analyse` | `{"tool":"phpstan","result":"passed","errors":0}` |
| `php artisan test` | `{"tool":"phpunit","result":"passed","tests":216,"passed":216,"assertions":623}` |
| `php artisan migrate:fresh --force` (SQLite) | All 17 migrations (15 pre-existing + 2 new) ran cleanly |
| `php artisan db:seed --class=...AdminUserSeeder` | `RolePermissionSeeder` ran (extended catalog created — `staff-status.view`/`location.view` attached to Manager, `staff-status.view` also to Staff); `AdminUserSeeder` ran |
| `php artisan serve` + curl — self-service, manager-scoping, admin-correction, deletion smoke test | See §14/Manual Testing Instructions below — all steps passed as designed |

## 13. Results

See §12's table; the same results are recorded per-check in `docs/testing/TEST_STATUS.md`'s new Phase 9 section.

## 14. Deviations from Specification

None from `docs/phases/V1_PHASE_09_DEFINITION.md` (written at the start of this same session, so it already reflects the as-built design — see that document for the full reasoning behind every choice summarized in §3–§8 above).

## 15. Known Issues/Limitations

- No Admin Backoffice UI or Flutter mobile screens exist yet for this feature — this phase is API/backend only, consistent with every prior business-module phase's precedent. Mobile location-permission UX and check-in screens are deferred to a future, dedicated UI phase.
- `staff_checkins`/`staff_statuses` history is not yet bounded by any retention policy — acceptable at ~100 employees with explicit, user-triggered events (not periodic telemetry), per the governing instructions, but a future phase should revisit retention if volume ever grows materially.
- No anti-spoofing verification of submitted coordinates is attempted (explicitly out of scope).
- `location.view`'s Manager scoping depends on the Manager's own linked Staff record and Phase 7's `manager_id` — a Manager-role user with no linked Staff record can view no one's location (including their own team's), which is a deliberate consequence of Phase 7's optional Staff↔User relationship, not a Phase 9 defect; a real Manager should always have a linked Staff record.
- This session's `composer install` again required the manual `phpstan/phpstan` recovery documented in Phase 6/8's handoffs, complicated further this session by a 300-second git-mirror timeout on the package's own monorepo history — see `docs/CURRENT_STATE.md`'s Known Blockers/Issues for the full account. This is an environment-recovery detail only; `vendor/bin/phpstan analyse` genuinely ran and passed against this phase's real code.
- Docker-based re-verification and GitHub Actions CI were not run this session — verification gaps, not known defects; no Docker configuration changed, and no PR was opened/pushed to `main` this session, so CI's path-filtered trigger (DEC-015) never fired. All CLAUDE.md §5 quality-gate commands were run directly and locally (§12/§13).

## 16. Explicit Phase 9 Scope Exclusions

Per the governing instructions: attendance, clock-in/clock-out, timesheets, payroll, salary, overtime, leave management/balances/approvals, biometric integration, continuous/background GPS tracking, automatic location polling, geofencing, route/movement history, employee surveillance, GPS spoofing detection, Google Maps/Mapbox integration, reverse geocoding, device tracking, project assignment, tasks, work logs, messaging, notifications, performance monitoring, productivity scoring. No Admin Backoffice CRUD UI or Flutter mobile screens.

## 17. Manual/UAT Testing Instructions

**Backend setup — Docker (standard as of Phase 4A/DEC-027; port `8012` by default):**
```sh
cd apps/api
cp .env.docker.example .env
cd ..
docker compose up -d --build
docker compose exec app php artisan key:generate
docker compose exec app composer install
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed --class="Database\Seeders\AdminUserSeeder"
```

**Backend setup — direct install (alternative; port `8000`):**
```sh
cd apps/api
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan db:seed --class="Database\Seeders\AdminUserSeeder"
php artisan serve
```

**API smoke test (curl), substituting the Docker port `8012` or `8000` as appropriate.** First create a Staff-linked test account (as in Phase 7's UAT instructions — create a Staff record and link `user_id` to a Staff- or Manager-role test user's `public_id` via `PUT /api/v1/staff/{public_id}`), then:

```sh
TOKEN=$(curl -s -X POST http://localhost:8012/api/v1/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"<staff-linked-user-email>","password":"password"}' | python3 -c "import sys,json;print(json.load(sys.stdin)['data']['token'])")

curl -s -X POST http://localhost:8012/api/v1/me/status -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"status":"in_field"}'

curl -s http://localhost:8012/api/v1/me/status -H "Authorization: Bearer $TOKEN"

curl -s -X POST http://localhost:8012/api/v1/me/check-ins -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"latitude":51.5072,"longitude":-0.1276,"location_label":"Client Site"}'

curl -s http://localhost:8012/api/v1/me/check-ins -H "Authorization: Bearer $TOKEN"
```
Expect a `201` for each `POST`, then a `200` list with the newest entry first. To verify the Staff Directory integration, `GET /api/v1/staff/{public_id}` and confirm `operational_status` reflects the value just set. To verify Manager scoping, create a Manager-role Staff-linked account whose Staff record is another Staff member's `manager_id`, and confirm `GET /api/v1/staff/{report}/check-ins` succeeds while `GET /api/v1/staff/{non-report}/check-ins` returns `403`. To verify Administrator correction/deletion, `POST /api/v1/staff/{public_id}/status` and `DELETE /api/v1/check-ins/{public_id}` as the Administrator account, and confirm a non-Administrator gets `403` on the same `DELETE`.

**UAT:** logged as **NOT RUN** (`UAT-09-01`, `UAT-09-02` in `docs/testing/UAT_LOG.md`) — this phase built no Admin Backoffice UI or Flutter screens, so there is nothing yet for the product owner to click through visually; both scenarios are ready for the product owner to exercise via the API directly if desired, per CLAUDE.md §7 (only the product owner may record a `PASS`).

## Recommended Next Phase

**Phase 10 — Projects & Project Membership**, per `docs/ROADMAP.md` (its stated dependencies — Phase 7 Staff, Phase 8 Clients — are both satisfied).

## Business Functionality Statement

**No functionality outside Staff Operational Status & Location Check-in was introduced in this phase.** No attendance, clock-in/clock-out, timesheets, payroll, salary, overtime, leave management/balances/approvals, biometric integration, continuous/background GPS tracking, automatic location polling, geofencing, route/movement history, employee surveillance, GPS spoofing detection, Google Maps/Mapbox/geocoding integration, device tracking, project/task assignment, work logs, messaging, notifications, or performance/productivity monitoring; no Admin Backoffice CRUD UI or Flutter mobile screens; no third-party package for any of this (plain Eloquent models/migrations/Form Requests, consistent with the existing architecture); `Staff.status` (employment) was never read or written by any code this phase added.

---

*Per CLAUDE.md §8 (Stop Discipline): this phase is complete. Not merged into `main`. Phase 10 is not authorized by this handoff and will not begin without explicit user instruction.*
