# Phase 7 Handoff — Staff

## 1. Phase Identification / Objective

- **Phase:** 7 — Staff
- **Date:** 2026-09-11
- **Branch:** `claude/company-app-phase-7-staff` (from `main` @ `082de7d`, the merge commit for PR #8, which contains the approved Phase 1–6 content)
- **Objective:** build the foundational Staff/Employee module — the canonical company personnel directory and employment-profile foundation — connected to the Phase 6 Organization Structure (Department/Team/Position) and optionally to a Phase 4/5 `User` login account. Not later HR, payroll, attendance, leave, project, task, or messaging functionality.

## 2. Repository Recovery / Main Verification

Read, in order: `CLAUDE.md`, `docs/CURRENT_STATE.md`, `docs/ROADMAP.md`, `docs/handoffs/V1_PHASE_06_HANDOFF.md`, `docs/handoffs/V1_PHASE_05_HANDOFF.md`, `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/06_UI_UX_GUIDELINES.md`, `docs/DECISIONS.md`, `docs/testing/{TEST_PLAN,TEST_STATUS,UAT_LOG}.md`, and the existing codebase — `Department`/`Team`/`Position` models and their controllers/Form Requests/Resources/routes (Phase 6), `User`/`Role`/`Permission` (Phase 5), `RolePermissionSeeder`, `AppServiceProvider`'s `Gate::before`, and the full existing test suite. Fetched and checked out `origin/main`, confirmed clean, and confirmed Phase 6 present (`082de7d`, PR #8's merge).

**Key finding from inspection (unchanged since Phase 6):** no Admin Backoffice (Blade/Livewire) CRUD screen exists for any module yet. This phase again follows that precedent — API/backend only.

## 3. Final Staff Domain Model / Key Decisions (DEC-030)

- **Staff↔User separation:** `staff.user_id` is a nullable, **unique** FK to `users`. A Staff record may exist with no login access; a User may exist with no Staff record (e.g. the seeded local Administrator); one User links to at most one Staff record — enforced at the database level, not merely by convention. No authentication data (credentials, tokens, account state) lives on `staff`; `User` remains the sole authentication/account model. `User` gained an inverse `staff(): HasOne` relation.
- **Identity/employment fields:** `employee_number` (unique, admin-supplied — not system-generated), `first_name`/`last_name`/`preferred_name` (nullable), `company_email`/`company_phone` (nullable, professional contact info deliberately distinct from `User.email`), `hire_date`/`separation_date` (nullable dates). Deliberately excluded: government/tax IDs, bank details, salary, medical information, emergency contacts — all future-HR-module territory per the governing instructions.
- **Employment lifecycle:** `App\Enums\StaffStatus` (`active`/`inactive`/`separated`) — a three-state pattern mirroring `AccountStatus`'s established V1 approach rather than `OrganizationStatus`'s simpler two-state master-data lifecycle, because "temporarily inactive" and "no longer employed" are meaningfully different for a person record. Distinct from both `AccountStatus` (login/account state) and the future Phase 9 operational/current status (available, on leave, in the field, off duty) — Phase 7's `status` is purely "are they currently an employee."
- **Organization Structure integration:** nullable `department_id`/`team_id`/`position_id` FKs to the Phase 6 tables (`restrictOnDelete()`). A Staff record's Team and Department must be mutually consistent: if the assigned Team belongs to a Department, a supplied `department_id` must match it; when `department_id` is omitted, it's auto-derived from the Team. No Department/Team/Position name is duplicated onto `staff` — only the foreign keys.
- **Manager relationship:** a nullable, self-referencing `manager_id` (`restrictOnDelete()`). A staff member cannot be their own manager. Assigning a manager whose own chain would loop back to this staff member (a reporting cycle) is rejected via `Staff::wouldCreateCycleWith()` — a bounded walk (capped at 50 steps) up the proposed manager's chain, not general-purpose graph-cycle detection.
- **Relational integrity extends into Phase 6:** `DepartmentController`/`TeamController`/`PositionController::destroy` are extended (a small, necessary Phase 6 touch, explicitly anticipated by the governing instructions) to also reject deletion (`409`) when Staff still reference that Department/Team/Position. `StaffController::destroy` itself rejects deleting a staff member who still has direct reports.

## 4. Authorization

Two new permissions added to the existing `RolePermissionSeeder` (Phase 5/6 pattern — no new mechanism):
- `staff.view` — list/view the Staff Directory. Attached to **Manager and Staff** — the Directory is a company-wide feature, not admin-only.
- `staff.manage` — create/update/delete staff records (including status/lifecycle changes). **Administrator-only**, via the existing centralized `Gate::before` override.

A finer distinction than a bare permission check: within `StaffResource`, the linked `User`'s own identity (`public_id`/email/account status) is only included when the requester *also* holds `staff.manage` — everyone with `staff.view` sees a plain `has_user_account` boolean instead. This keeps "Staff Directory data" and "sensitive staff-management data" genuinely separate in one shared resource, as the governing instructions asked, rather than two near-duplicate resource classes.

## 5. Database / Schema Changes

One new migration: `2026_09_11_070000_create_staff_table.php` — `id`, `public_id` (ULID, unique), `employee_number` (unique), `first_name`, `last_name`, `preferred_name` (nullable), `company_email` (nullable, unique), `company_phone` (nullable), `status` (string, default `active`), `hire_date`/`separation_date` (nullable dates), `department_id`/`team_id`/`position_id` (nullable, `restrictOnDelete()`), `manager_id` (nullable, self-referencing, `restrictOnDelete()`), `user_id` (nullable, **unique**, `nullOnDelete()`), timestamps.

## 6. Models

`App\Models\Staff` — casts `status` to `StaffStatus`, `hire_date`/`separation_date` to `date`; assigns `public_id` and defaults `status` to `Active` in a `creating` hook (same pattern as `Department`/`Team`/`Position`, and for the same reason — DB-level defaults aren't reflected on the in-memory model after `create()` unless refreshed). `getRouteKeyName()` returns `public_id`. Relationships: `department()`/`team()`/`position()`/`manager()` (`BelongsTo`), `directReports()` (`HasMany`, `manager_id`), `user()` (`BelongsTo`). Helper methods: `displayName()` (preferred name, falling back to full name), `fullName()`, `wouldCreateCycleWith(int $proposedManagerId): bool` (the bounded manager-chain walk).

`Department`/`Team`/`Position` each gained a `staff(): HasMany` relation, used by their controllers' extended delete-protection checks. `User` gained `staff(): HasOne`.

## 7. API / Endpoints

Full CRUD under `/api/v1/staff`, following `04_API_CONVENTIONS.md` (this document's own long-standing `/staff` example is now real):

| Method | Path | Permission |
|---|---|---|
| `GET` | `/staff` | `staff.view` |
| `GET` | `/staff/{public_id}` | `staff.view` |
| `POST` | `/staff` | `staff.manage` |
| `PUT`/`PATCH` | `/staff/{public_id}` | `staff.manage` |
| `DELETE` | `/staff/{public_id}` | `staff.manage` |

Status changes (including offboarding) go through the same `update` endpoint as every other field — no separate action route, matching the Phase 6 precedent for Department/Team/Position's own `status` field.

**Filters:** `?status=`, `?department=<public_id>`, `?team=<public_id>`, `?position=<public_id>`, `?manager=<public_id>` (all resolved server-side from the submitted `public_id`, never an internal id), and a directory `?q=` search matched against `first_name`/`last_name`/`preferred_name`/`employee_number`. Pagination via Laravel's standard paginator (`?per_page=`, default 50), same as Phase 6.

`App\Http\Requests\Staff\{Store,Update}StaffRequest` validate all writes, sharing `App\Http\Requests\Staff\Concerns\ResolvesStaffReferences` for public_id→internal-id resolution (Department/Team/Position/Manager/User). `App\Http\Resources\StaffResource` is the single Staff Directory shape described in §4.

## 8. Validation / Integrity

- `employee_number`: required, unique.
- `first_name`/`last_name`: required.
- `company_email`: optional, unique when present.
- `department_id`/`team_id`/`position_id`/`manager_id`/`user_id`: each validated with `Rule::exists(<table>, 'public_id')` against the submitted public ULID.
- Team/Department consistency (§3).
- Manager: existence (via `exists`), non-self, non-cycle (§3) — both checked in a `Validator::after()` closure since they need the resolved internal id and (for cycle detection) the current record.
- `separation_date`: required when the effective `status` is `separated`; must not precede the effective `hire_date` — compared via a custom `Validator::after()` check against whichever of `hire_date`/`separation_date` the request supplies, falling back to the existing record's value on a partial update (see §12 for why a plain Laravel `after_or_equal:hire_date` rule doesn't work here).
- User linkage uniqueness ("this user is already linked to another staff member") is checked against the *resolved* internal id in `Validator::after()`, not a `Rule::unique()` on the raw submitted field (see §12 — the naive version compares the wrong values entirely).

## 9. Factories / Seeders

`StaffFactory` — standard Eloquent factory (`inactive()`/`separated()` states) for test data. No production/demo seeder — Staff is business-owned personnel data, not fixed system catalog data, the same reasoning Phase 6 applied to Department/Team/Position.

## 10. Files Changed

**Added (backend):** `app/Enums/StaffStatus.php`; `app/Models/Staff.php`; `database/migrations/2026_09_11_070000_create_staff_table.php`; `database/factories/StaffFactory.php`; `app/Http/Controllers/Api/V1/Staff/StaffController.php`; `app/Http/Requests/Staff/{Store,Update}StaffRequest.php`; `app/Http/Requests/Staff/Concerns/ResolvesStaffReferences.php`; `app/Http/Resources/StaffResource.php`; `tests/Feature/Api/V1/Staff/StaffTest.php`; `tests/Feature/Authorization/StaffAuthorizationTest.php`.

**Modified (backend):** `app/Models/{Department,Team,Position}.php` (new `staff()` relation), `app/Models/User.php` (new `staff()` relation), `app/Http/Controllers/Api/V1/Organization/{Department,Team,Position}Controller.php` (extended `destroy()` delete-protection), `database/seeders/RolePermissionSeeder.php` (new permissions + Manager/Staff attachment), `routes/api/v1.php` (new route group), `tests/Feature/Api/V1/Organization/{Department,Team,Position}Test.php` (new delete-protection-with-staff tests), `tests/Feature/Authorization/RolePermissionSeederTest.php` (2 new tests for the extended catalog).

**Added (docs):** `docs/phases/V1_PHASE_07_DEFINITION.md`, this handoff.

**Modified (docs):** `docs/02_ARCHITECTURE.md` (new §17), `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/ROADMAP.md` (Phase 6/7 marked complete), `docs/CHANGELOG.md`, `docs/CURRENT_STATE.md`, `docs/DECISIONS.md` (DEC-030), `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`.

No `apps/mobile` files changed — Flutter is entirely unaffected.

## 11. Tests Added/Changed

**42 new PHPUnit tests:**
- `StaffTest` (26) — CRUD (list/create/validation/view-by-public_id/internal-id-404/update/status-transition-with-separation-date-validation/delete/delete-with-reports-rejected); filters and search (status, department/team/position, manager, `q`); organization relationships (assign department+team+position, unknown public_id rejected, department auto-derived from team, mismatched department rejected, team-with-no-department places no constraint); manager relationship (assign, unknown manager rejected, self-manager rejected, two-node cycle rejected); User relationship (staff without a user, linking a user, duplicate user linkage rejected, linked-user identity visible only to a `staff.manage` holder).
- `StaffAuthorizationTest` (6) — Administrator view+manage; Manager/Staff view-only (`403` on write); no-role denied both; unauthenticated `401`; suspended account `403` mid-session — mirrors `OrganizationAuthorizationTest`.
- 3 new tests (one each in `DepartmentTest`/`TeamTest`/`PositionTest`) confirming deletion is now also rejected when Staff reference the record.
- 2 new tests in `RolePermissionSeederTest` for the extended permission catalog.

**Unaffected in behavior:** all 51 Phase 1–5 tests and all 42 Phase 6 tests (93 total) pass unmodified beyond the 3 new delete-protection assertions added to existing files.

## 12. Deviations from Specification — Two Real Bugs Found and Fixed

Both were caught by the automated test suite itself before this handoff was written, not left as known issues:

1. **`after_or_equal:hire_date` doesn't work for a partial update.** Laravel's built-in date-comparison rules compare against *another field in the same request payload* — not the database record. `UpdateStaffRequest` supports partial updates (`sometimes`), so a request that sets only `separation_date` (without resending `hire_date`) would have the rule compare against a missing field and silently pass, regardless of the actual persisted `hire_date`. Fixed by replacing the inline rule with a custom `Validator::after()` check (`validateDateOrder()`) that resolves the *effective* hire/separation dates — from the request if supplied, otherwise from the existing record — before comparing.
2. **The `user_id` uniqueness check validated the wrong value.** `Rule::unique('staff', 'user_id')` was applied directly to the submitted field, but that field's value is the user's `public_id` (a ULID string) while `staff.user_id` stores the internal numeric id — the two can never collide, so the rule always passed even when a user really was already linked to another staff member, and the request fell through to a real `UNIQUE constraint failed` database exception (`500`) instead of a clean `422`. Fixed by moving the check into `Validator::after()` (`validateUserNotAlreadyLinked()`), comparing against the *resolved* internal id instead.

Both fixes are reflected in the final `StoreStaffRequest`/`UpdateStaffRequest` (§8) and covered by tests (`test_separation_date_must_not_precede_hire_date`, `test_a_user_cannot_be_linked_to_more_than_one_staff_member`).

No other deviations from `docs/phases/V1_PHASE_07_DEFINITION.md`.

## 13. Environment Notes

`vendor/` from the Phase 6 session's manual recovery (see that handoff's §13) was already present and fully functional in this container — no repeat of that recovery process was needed this session. `composer validate --strict`, `pint`, `phpstan`, and the full test suite all ran normally against it.

**Docker was not re-verified this session** — no Docker configuration changed in this phase, and the Phase 6 session's documented sandbox network difficulties made a fresh Docker image build impractical to also pursue here. The last genuine Docker confirmation remains Phase 5's.

**GitHub Actions CI did not run this session** — consistent with the Phase 6 session, no PR was opened and no push to `main` was made (this session's operating instructions direct not to open a PR unless the user explicitly asks, and this phase's instructions explicitly say not to open one "unless instructed by the product owner or governing repository instructions"). All CLAUDE.md §5 quality-gate commands were run directly and locally (§14).

## 14. Commands Actually Executed

| Command | Result |
|---|---|
| `composer validate --strict` | `./composer.json is valid` |
| `vendor/bin/pint --test` | Initially flagged 2 files for import ordering (`Staff.php`, `StaffTest.php`); `vendor/bin/pint` fixed them; re-run: `{"tool":"pint","result":"passed"}` |
| `vendor/bin/phpstan analyse` | `{"tool":"phpstan","result":"passed","errors":0}` |
| `php artisan test` | `{"tool":"phpunit","result":"passed","tests":135,"passed":135,"assertions":370}` |
| `php artisan migrate:fresh --force` (SQLite) | All 13 migrations (12 pre-existing + 1 new) ran cleanly |
| `php artisan db:seed --class=...AdminUserSeeder` | `RolePermissionSeeder` ran (extended catalog created, Manager/Staff granted `staff.view`); `AdminUserSeeder` ran (Administrator assigned) |
| `php artisan serve` + curl — full CRUD + relationship smoke test | Administrator login → create Department/Team (team scoped to department)/Position → create Staff assigned only to the Team (`201`; response and DB both show `department_id` correctly auto-derived from the team) → list staff (`200`) → attempt to delete the Department while Staff reference it (`409`) → create a second Staff reporting to the first (`manager` field correctly nested in the response) → attempt to delete the manager while they have a direct report (`409`) → attempt to set a staff member as their own manager (`422`, with the expected message) |

## 15. Exact Results

See §14's table in full; the same results are recorded per-check in `docs/testing/TEST_STATUS.md`'s new Phase 7 section.

## 16. Known Issues/Limitations

- No Admin Backoffice UI exists yet for managing Staff — this phase is API/backend only, consistent with Phase 6's precedent. Building that UI is future work.
- No auto-generated employee numbers — admin-supplied and validated unique; a future phase could add auto-numbering if the product owner wants it.
- Docker-based re-verification and GitHub Actions CI were not run this session (§13) — verification gaps, not known defects; the last genuine Docker confirmation remains Phase 5's, and a future PR against this branch will produce a real CI run.
- Manager-chain cycle detection is intentionally bounded (50 steps), not exhaustive graph analysis — sufficient at ~100-employee scale per the governing instructions, but not a formal guarantee for an arbitrarily large org.
- Staff's `status` is purely an employment lifecycle; day-to-day operational/current status (available, on leave, in the field, off duty) and location check-ins remain entirely unaddressed, deferred to Phase 9 as intended.

## 17. Explicit Phase 7 Scope Exclusions

Per the governing instructions: no payroll, salary/compensation, government/tax information, attendance/timekeeping, biometric integration, leave balances/leave requests, employee documents, medical data, emergency contacts, performance reviews, recruitment, onboarding workflow, benefits, expense claims, work logs, project assignment, task management, messaging, notifications unrelated to Staff, or client management. No Admin Backoffice CRUD UI. No operational/current-status tracking (Phase 9). No department hierarchy or many-to-many staff↔department/team structure. No auto-generated employee numbers. No forced Staff↔User 1:1 requirement in either direction.

## 18. Manual/UAT Testing Instructions

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

**API smoke test (curl), substituting the Docker port `8012` or `8000` as appropriate:**
```sh
TOKEN=$(curl -s -X POST http://localhost:8012/api/v1/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.test","password":"password"}' | python3 -c "import sys,json;print(json.load(sys.stdin)['data']['token'])")

curl -s -X POST http://localhost:8012/api/v1/staff -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"employee_number":"EMP-0001","first_name":"Ada","last_name":"Lovelace","company_email":"ada@example.test"}'

curl -s http://localhost:8012/api/v1/staff -H "Authorization: Bearer $TOKEN"
```
Expect a `201` with the new staff member (a `public_id`, never a numeric `id`), then a `200` list. To verify org integration, first create a Department/Team/Position (Phase 6 endpoints), then create a Staff record with `"team_id"` set to that team's `public_id` — the response's `department` should be auto-populated from the team even if `department_id` wasn't supplied. To verify the manager guard, attempt to `PUT` a staff member's own `public_id` as their own `manager_id` — expect `422`.

To verify Manager/Staff view-only access, create a Staff-role test account (as in Phase 5's UAT instructions) and confirm it can `GET` but not `POST`/`PUT`/`DELETE` `/staff` (expect `403` on writes), and that the response for `GET /staff/{public_id}` omits the `user` field even for a staff member with a linked account (only `has_user_account` is visible).

**UAT:** logged as **NOT RUN** (`UAT-07-01` in `docs/testing/UAT_LOG.md`) — this phase built no Admin Backoffice UI, so there is nothing yet for the product owner to click through visually; the scenario is ready for the product owner to exercise via the API directly if desired, per CLAUDE.md §7 (only the product owner may record a `PASS`).

## 19. Recommended Next Phase

**Phase 8 — Clients & Contacts**, per `docs/ROADMAP.md`. (Phase 9 — Staff Status & Location Check-in — is also unblocked by this phase and could reasonably come next instead; either is a defensible choice per the roadmap's stated dependencies. Awaiting product-owner direction.)

## Business Functionality Statement

**No functionality outside Staff was introduced in this phase.** No payroll, salary/compensation, government/tax IDs, attendance, biometrics, leave balances/requests, employee documents, medical data, emergency contacts, performance reviews, recruitment, onboarding workflow, benefits, expense claims, work logs, project/task assignment, messaging, or client management; no Admin Backoffice CRUD UI; no operational/current-status tracking; no third-party package for any of this (plain Eloquent models/migrations/Form Requests, consistent with the existing architecture).

---

*Per CLAUDE.md §8 (Stop Discipline): this phase is complete. Not merged into `main`. Phase 8 is not authorized by this handoff and will not begin without explicit user instruction.*
