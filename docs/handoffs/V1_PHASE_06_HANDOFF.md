# Phase 6 Handoff — Organization Structure

## 1. Phase Identification / Objective

- **Phase:** 6 — Organization Structure
- **Date:** 2026-09-11
- **Branch:** `claude/company-app-phase-6-2s09wk` (from `main` @ `422cb75`, the merge commit for PR #7, which contains the approved Phase 1–5 content)
- **Objective:** build the foundational organization structure for Company App — Departments, Teams, and Positions, and the relationships between them — as reusable master data for later modules (starting with Staff, Phase 7), integrated with the Phase 5 authorization foundation. Not Staff/Employee management or any later business module.

## 2. Repository Recovery / Main Verification

Read, in order: `CLAUDE.md`, `docs/CURRENT_STATE.md`, `docs/ROADMAP.md`, `docs/handoffs/V1_PHASE_05_HANDOFF.md`, `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/06_UI_UX_GUIDELINES.md`, `docs/DECISIONS.md`, and the existing codebase (`User`/`Role`/`Permission` models, `AppServiceProvider`'s `Gate::before`, `AuthController`, `UserResource`, `ApiLoginRequest`, migrations, factories, seeders, `routes/api/v1.php`, `routes/web.php`, existing tests). Confirmed `git status` clean and the session's designated branch (`claude/company-app-phase-6-2s09wk`) already sat at the exact same commit as `origin/main` (`422cb75`, PR #7's merge — the approved Phase 5 content) before any work began.

**Key finding from inspection:** no Admin Backoffice (Blade/Livewire) CRUD screen exists for any module yet — Phase 4 built only a login screen and a placeholder `/home`; Phase 5 deliberately shipped no management UI. This phase follows that same precedent: it builds the backend/API foundation, not a speculative first CRUD UI pattern with no established convention to follow.

## 3. Domain Model / Key Decisions (DEC-029)

- **Department** — a major organizational unit. Flat: no sub-department hierarchy (resolves `02_ARCHITECTURE.md` §9's former open question — a hierarchy table was judged unnecessary infrastructure at ~100-employee scale).
- **Team** — belongs to **at most one** Department, or none yet (nullable `department_id`). Teams never span multiple departments (resolves `03_DATABASE_MODEL.md`'s former open question).
- **Position** — an organizational/job title, standalone master data in this phase (not yet linked to any staff record — Phase 7 introduces `staff`). Also optionally scoped to a Department (nullable `department_id`).
- **Lifecycle:** a shared `App\Enums\OrganizationStatus` (`active`/`inactive`) column on all three, rather than `SoftDeletes` — retiring a unit flips this instead of deleting the row (preserving future references without a second, overlapping lifecycle mechanism).
- **Ordering:** an admin-controlled `sort_order` integer on all three, used for default list ordering (`sort_order` then name/title) — no drag-and-drop UI exists yet to set it, but the column and ordering behavior are in place for when one does.
- **Identifiers:** numeric `BIGINT` internal PK + ULID `public_id` (DEC-017) on all three — these are admin-manageable business entities, unlike the fixed internal `roles`/`permissions` catalog, which deliberately has no `public_id`.
- **Deletion:** a Department cannot be deleted while any Team or Position still references it — enforced at the application layer (a clear `409`), backed by a DB-level `restrictOnDelete()` foreign key as a defense-in-depth backstop. Teams/Positions may be freely deleted (nothing yet depends on them in this phase).
- **Uniqueness:** Department `name` is globally unique. Team `name` and Position `title` are unique *within their department scope* (or the "no department" scope) — not globally, since the same position title (e.g. "Manager") legitimately recurs across departments.

## 4. Authorization

Two new permissions added to the existing `RolePermissionSeeder` (Phase 5 pattern — no new mechanism):
- `organization.view` — list/view Departments/Teams/Positions. Attached to **Manager and Staff** (viewing the company's org structure is low-sensitivity, broadly useful company metadata — unlike, e.g., staff personal data).
- `organization.manage` — create/update/delete them. **Administrator-only**, via the existing centralized `Gate::before` override (DEC-028) — not explicitly attached to any role, matching every other `*.manage` permission so far.

Enforced via route middleware (`can:organization.view` / `can:organization.manage`), on top of the existing `auth:sanctum` + `account.active` chain — identical enforcement shape to the Admin Backoffice's `admin.access` gate, just applied to the API surface for the first time.

## 5. Database / Schema Changes

Three new migrations, in order:
1. `2026_09_11_060000_create_departments_table.php` — `id`, `public_id` (ULID, unique), `name` (unique), `description` (nullable), `status` (string, default `active`), `sort_order` (unsigned int, default 0), timestamps.
2. `2026_09_11_060001_create_teams_table.php` — same shape, plus nullable `department_id` (`restrictOnDelete()`), composite unique `(department_id, name)`.
3. `2026_09_11_060002_create_positions_table.php` — same shape, plus nullable `department_id` (`restrictOnDelete()`), `title` instead of `name`, composite unique `(department_id, title)`.

Note: both MySQL and SQLite treat `NULL` as distinct in a unique index, so the composite unique index alone doesn't catch duplicate names/titles among rows with no department — that scope is validated at the application layer (Form Requests), documented in the migrations themselves.

## 6. Models

`App\Models\Department`, `App\Models\Team`, `App\Models\Position` — each:
- Casts `status` to `App\Enums\OrganizationStatus`.
- Assigns `public_id` in a `creating` hook (same pattern as `User`).
- Also defaults `status`/`sort_order` in that same hook — **a real bug found and fixed during testing** (see §12): the DB-level column defaults aren't reflected on the in-memory model returned by `Model::create()` unless refreshed, so a freshly created department/team/position with no explicit `status`/`sort_order` had a `null` `status` in memory, crashing `DepartmentResource`/`TeamResource`/`PositionResource` (`Attempt to read property "value" on null`) when serializing the `201 Created` response. Fixed by setting these explicitly in the `creating` hook, mirroring `public_id`'s existing pattern.
- `getRouteKeyName()` returns `public_id` — route model binding is always by public ULID, never the internal numeric id.

Relationships: `Department::teams()`/`positions()` (`hasMany`), `Team::department()`/`Position::department()` (`belongsTo`).

## 7. API / Admin Functionality

Full CRUD under `/api/v1`, following `04_API_CONVENTIONS.md` (plural top-level resources, not deep-nested):

| Method | Path | Permission |
|---|---|---|
| `GET` | `/departments`, `/teams`, `/positions` | `organization.view` |
| `GET` | `/departments/{public_id}`, `/teams/{public_id}`, `/positions/{public_id}` | `organization.view` |
| `POST` | `/departments`, `/teams`, `/positions` | `organization.manage` |
| `PUT`/`PATCH` | `/departments/{public_id}`, `/teams/{public_id}`, `/positions/{public_id}` | `organization.manage` |
| `DELETE` | `/departments/{public_id}`, `/teams/{public_id}`, `/positions/{public_id}` | `organization.manage` |

Filtering: `?status=active|inactive` (all three), `?department=<public_id>` (Teams/Positions) — a top-level filterable resource, matching `04_API_CONVENTIONS.md`'s guidance to prefer this over deep nesting for a resource that's more independent than owned. Pagination via Laravel's standard paginator (`?per_page=`, default 50).

`App\Http\Requests\Organization\{Store,Update}{Department,Team,Position}Request` validate all writes, including safe resolution of a client-supplied `department_id` (submitted as the department's public ULID — `App\Http\Requests\Organization\Concerns\ResolvesDepartmentId`) and department-scoped uniqueness. `App\Http\Resources\{Department,Team,Position}Resource` expose only `public_id` (never internal ids); Team/Position nest a minimal `department` object (`public_id` + `name`), `null` when unassigned.

**No Admin Backoffice (Blade/Livewire) UI** was built — see §2's key finding. This is API/backend functionality only, consistent with Phase 5's precedent.

## 8. Seeders / Factories

`RolePermissionSeeder` (extended, not replaced) now also creates `organization.view`/`organization.manage` and attaches `organization.view` to Manager and Staff via `syncWithoutDetaching` (idempotent). `DepartmentFactory`, `TeamFactory`, `PositionFactory` added for test data — standard Eloquent factories, no seeder for organization structure itself (this is admin-created business content, not fixed system catalog data like roles/permissions).

## 9. Files Changed

**Added (backend):** `app/Enums/OrganizationStatus.php`; `app/Models/{Department,Team,Position}.php`; `database/migrations/2026_09_11_06000{0,1,2}_create_{departments,teams,positions}_table.php`; `database/factories/{Department,Team,Position}Factory.php`; `app/Http/Controllers/Api/V1/Organization/{Department,Team,Position}Controller.php`; `app/Http/Requests/Organization/{Store,Update}{Department,Team,Position}Request.php`; `app/Http/Requests/Organization/Concerns/ResolvesDepartmentId.php`; `app/Http/Resources/{Department,Team,Position}Resource.php`; `tests/Feature/Api/V1/Organization/{Department,Team,Position}Test.php`; `tests/Feature/Authorization/OrganizationAuthorizationTest.php`.

**Modified (backend):** `database/seeders/RolePermissionSeeder.php` (new permissions + Manager/Staff attachment); `routes/api/v1.php` (new route group); `tests/Feature/Authorization/RolePermissionSeederTest.php` (2 new tests for the extended catalog).

**Added (docs):** `docs/phases/V1_PHASE_06_DEFINITION.md`, this handoff.

**Modified (docs):** `docs/02_ARCHITECTURE.md` (§9, new §16), `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/CHANGELOG.md`, `docs/CURRENT_STATE.md`, `docs/DECISIONS.md` (DEC-029), `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`.

No `apps/mobile` files changed — Flutter is entirely unaffected (no mobile UI currently needs organization-structure data; it's available via the API for whenever a future phase does).

## 10. Tests Added/Changed

**42 new PHPUnit tests:**
- `DepartmentTest` (14) — list/create/validate (required, unique name)/view (by `public_id`; internal numeric id `404`s)/update (incl. duplicate-name rejection, no-op rename allowed)/delete (success with no dependents; `409` when Teams or Positions still reference it, for each)/status filtering/`teams_count`+`positions_count` reporting.
- `TeamTest` (11) — create with/without a department; unknown `department_id` public_id rejected (`422`); name uniqueness scoped per department (rejects within the same department, allows the same name in a different department, allows/rejects appropriately among departmentless teams); reassigning/clearing a team's department; filtering by department (including an unknown department returning an empty list, not an error); deleting a team never affects its department.
- `PositionTest` (9) — create standalone and department-scoped; unknown `department_id` rejected; the same title recurring across departments is allowed; title uniqueness within one department is enforced; update; filter by department; delete (and confirm it doesn't affect the department).
- `OrganizationAuthorizationTest` (6) — Administrator can view and manage; Manager/Staff can view but not manage (`403` on write); a user with no role can do neither; unauthenticated requests are rejected (`401`); a suspended account loses access mid-session (`403`) — mirrors `AdminBackofficeAuthorizationTest`'s pattern for the API surface.
- `RolePermissionSeederTest` (+2) — the two new permissions exist; Manager/Staff hold `organization.view` but not `organization.manage`; re-running the seeder doesn't duplicate the pivot attachment.

**Unaffected in behavior:** all 51 pre-existing Phase 1–5 tests pass unmodified.

## 11. Commands Actually Executed

| Command | Result |
|---|---|
| `composer validate --strict` | `./composer.json is valid` |
| `vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` |
| `vendor/bin/phpstan analyse` | `{"tool":"phpstan","result":"passed","errors":0}` |
| `php artisan test` | `{"tool":"phpunit","result":"passed","tests":93,"passed":93,"assertions":249}` |
| `php artisan migrate:fresh --force` (SQLite) | All 12 migrations (9 pre-existing + 3 new) ran cleanly |
| `php artisan db:seed --class=...AdminUserSeeder` | `RolePermissionSeeder` ran (extended catalog created, Manager/Staff granted `organization.view`); `AdminUserSeeder` ran (Administrator assigned) |
| `php artisan serve` + curl — full CRUD smoke test | Administrator login → `POST /api/v1/departments` (`201`) → `GET /api/v1/departments` (`200`, with `teams_count`/`positions_count`) → `POST /api/v1/teams` scoped to that department by its `public_id` (`201`, response nests `department`) → `DELETE` that department while the team exists (`409`, correctly rejected) → `GET /api/v1/departments/1` (the internal numeric id — `404`, confirming `public_id`-only route binding) |

## 12. Exact Results

See §11's table. All commands passed on the first or (for the `Department`/`Team`/`Position` create-response bug, see below) second attempt.

## 13. Environment Deviation — `composer install`

**Environment, not specification** (the same class of issue Phase 5 documented, but far more severe this session): `vendor/` was not preinstalled. `composer install` (both `--prefer-dist` and `--prefer-source`) failed identically and repeatedly with `Could not authenticate against github.com` from Composer's `AuthHelper`, for the **entire** dependency tree (all 114 locked packages, not the 1–3 packages Phase 5 hit) — the shared sandbox's `api.github.com` reachability (already flagged as flaky in this session's own proxy status: `recentRelayFailures` showed `ws_closed_mid_exchange` against `api.github.com`) was apparently exhausted or blocked for both the zipball-dist path and the git-source fallback's own GitHub API calls.

Recovered without touching `composer.json`/`composer.lock`, extending Phase 5's exact technique to the full dependency set:
1. Confirmed every locked package's exact commit was already present in Composer's own local VCS mirror cache (`~/.cache/composer/vcs/`) from the failed install attempts' own (partially successful) git-fetch activity.
2. Extracted each package directly from its cached mirror at the exact locked commit (`git --git-dir=<mirror> archive <ref> | tar -x -C vendor/<name>`) — 113 of 114 packages recovered this way, purely from local disk, no network.
3. The one exception, `phpstan/phpstan`, has no `source` key in `composer.lock` at all (dist-only, same root cause Phase 5 hit for this exact package) — recovered via a direct shallow `git fetch --depth 1 origin <locked-sha>` against `https://github.com/phpstan/phpstan.git` (succeeded; this specific narrow request evidently didn't hit the same wall as the bulk API-driven installs).
4. Hand-built `vendor/composer/installed.json` from `composer.lock`'s package list (each entry plus an `install-path`) — a `composer dump-autoload` run against a from-scratch `vendor/` (no prior `installed.json`) did not pick up any vendor package's own autoload rules until this existed.
5. `composer dump-autoload -o` then correctly generated PSR-4/classmap autoloading for all 114 packages (7,334 classes) and created `vendor/bin/*` symlinks were **not** produced by this path, so those were created by hand for the three CLI tools the quality gates need (`pint`, `phpstan`/`phpstan.phar`, `phpunit`), matching each package's own `composer.json` `bin` declaration.
6. `vendor/composer/installed.php` (the plain-PHP runtime mirror of `installed.json`, read by `Composer\InstalledVersions`) doesn't get generated by a bare `dump-autoload` either — hand-built from the same `composer.lock` data (with a pragmatic, non-canonical-but-functionally-correct version-string normalization, since `composer/semver` isn't itself a project dependency and nothing in the app calls `InstalledVersions::satisfies()`).
7. `vendor/composer/InstalledVersions.php` (Composer's own runtime class, required by `bootstrap/app.php`'s autoloading chain) is not a project package at all — it ships bundled inside the `composer` binary itself. Extracted directly from the installed `/usr/local/bin/composer` Phar (a copy renamed to `.phar` so PHP's `Phar` class would open it, then `Phar::extractTo()`) and copied into `vendor/composer/`.
8. `cp .env.example .env && php artisan key:generate` — first-run setup, as documented in `CLAUDE.md` §5.

Verified this recovery was sound, not just "looks plausible," by: `composer validate --strict` passing; every quality-gate command in §11 passing cleanly; a genuine `php artisan migrate:fresh` and `php artisan db:seed` round-trip; and a real `php artisan serve` + curl session exercising the actual HTTP stack end-to-end (§11's last row) — not merely PHPUnit's in-process test client.

`composer.json`/`composer.lock` were untouched by any of this — both remain exactly as an unrestricted-network `composer install` would produce them. None of `vendor/`, `.env`, or the SQLite dev database file are committed (all correctly `.gitignore`d, confirmed via `git status` before staging).

**Docker was not re-verified this session** (see `docs/testing/TEST_STATUS.md`'s Phase 6 section) — this phase changed no Docker configuration, and the compounding network difficulty above made a Docker image build (which needs its own `apt-get`/Composer network access, already separately documented as blocked in this sandbox since Phase 4A) impractical to also pursue this session. A future session should re-verify Phase 6's migrations/tests inside Docker when network conditions allow.

## 14. Deviations from Specification

Beyond the environment recovery above (§13), no deviations from `docs/phases/V1_PHASE_06_DEFINITION.md`. The one implementation-time bug found and fixed (in-memory `status`/`sort_order` defaults missing after `create()` — §6) was caught by the automated test suite itself before this handoff was written, not left as a known issue.

## 15. Known Issues/Limitations

- No Admin Backoffice UI exists yet for managing Departments/Teams/Positions — this phase is API/backend only, consistent with Phase 5's precedent. Building that UI is future work (likely alongside Staff Directory or a dedicated Admin UI phase).
- No `?sort=` query parameter — list ordering is a fixed `sort_order`-then-name/title, sufficient at this data scale; a future phase can add real sort-field support if/when needed (`04_API_CONVENTIONS.md`).
- Docker-based re-verification was not performed this session (§13) — the last genuine Docker confirmation remains Phase 5's. This phase changed no Docker configuration, so this is a verification gap, not a known defect.
- Manager does not receive `organization.manage` — scoping management to "a manager's own department/team" would need row-level ownership logic and Staff/manager-relationship data (Phase 7) that doesn't exist yet; deferred until a real requirement emerges.

## 16. Explicit Phase 6 Scope Exclusions

Per the governing instructions: no Staff/Employee management, employment records, manager relationships, or staff↔organization assignment (Phase 7); no Clients, Projects, Leave, Tasks, Messaging, Attendance, Payroll, or any other later business module; no department hierarchy (flat only); no Team spanning multiple Departments; no Admin Backoffice CRUD UI; no soft-deletes (the `status` enum is the one lifecycle mechanism); no generic/polymorphic organization-hierarchy or ACL infrastructure.

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

**API smoke test (curl), substituting the Docker port `8012` or `8000` as appropriate:**
```sh
TOKEN=$(curl -s -X POST http://localhost:8012/api/v1/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.test","password":"password"}' | python3 -c "import sys,json;print(json.load(sys.stdin)['data']['token'])")

curl -s -X POST http://localhost:8012/api/v1/departments -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"name":"Engineering","description":"Builds the product."}'

curl -s http://localhost:8012/api/v1/departments -H "Authorization: Bearer $TOKEN"
```
Expect a `201` with the new department (including a `public_id`, never a numeric `id`), then a `200` list including `teams_count`/`positions_count`. To verify the deletion guard, create a Team with `"department_id"` set to that department's `public_id`, then attempt `DELETE /api/v1/departments/{public_id}` — expect `409`.

To verify Manager/Staff view-only access, create a Staff-role test account (as in Phase 5's UAT instructions) and confirm it can `GET` but not `POST`/`PUT`/`DELETE` any of `/departments`, `/teams`, `/positions` (expect `403` on writes).

**UAT:** logged as **NOT RUN** (`UAT-06-01` in `docs/testing/UAT_LOG.md`) — this phase built no Admin Backoffice UI, so there is nothing yet for the product owner to click through visually; the scenario is ready for the product owner to exercise via the API directly if desired, per CLAUDE.md §7 (only the product owner may record a `PASS`).

## 18. Recommended Next Phase

**Phase 7 — Staff**, per `docs/ROADMAP.md`: staff records, profiles, employment data, manager relationships, and the Staff Directory — the first module to actually consume Departments/Teams/Positions as foreign-key references.

## Business Functionality Statement

**No functionality outside Organization Structure was introduced in this phase.** No Staff, Clients, Projects, Leave, Tasks, Work Logs, Messaging, or any other business module; no department hierarchy; no many-to-many Team↔Department; no Admin Backoffice CRUD UI; no soft-deletes; no third-party package for any of this (plain Eloquent models/migrations/Form Requests, consistent with the existing architecture).

---

*Per CLAUDE.md §8 (Stop Discipline): this phase is complete. Not merged into `main`. Phase 7 is not authorized by this handoff and will not begin without explicit user instruction.*
