# Phase 10 Handoff — Projects & Project Membership

## 1. Phase Identification / Objective

- **Phase:** 10 — Projects & Project Membership
- **Date:** 2026-09-11
- **Branch:** `claude/eager-archimedes-8ze14i` (from `main` @ `a3347ea`, the merge commit for PR #11, which contains the approved Phase 1–9 content)
- **Objective:** build the foundational Projects & Project Membership module — Projects as a first-class business entity, optionally associated with a Client, with a small practical lifecycle; Staff membership/assignment to Projects carrying a project-level role. The foundation later modules (Tasks, Work Logs, project activity, reporting, messaging) will reference — not those modules themselves.

## 2. Repository Recovery / Main Verification

Checked out and confirmed `claude/eager-archimedes-8ze14i` (this session's designated branch) was already identical to `origin/main` at the Phase 9 merge commit (`a3347ea`), working tree clean. Read, in order: `CLAUDE.md`, `docs/CURRENT_STATE.md`, `docs/ROADMAP.md`, `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/06_UI_UX_GUIDELINES.md`, `docs/DECISIONS.md`, `docs/testing/{TEST_PLAN,TEST_STATUS,UAT_LOG}.md`, `docs/handoffs/README.md`, `docs/handoffs/V1_PHASE_09_HANDOFF.md`, `docs/handoffs/V1_PHASE_08_HANDOFF.md`, `docs/handoffs/V1_PHASE_07_HANDOFF.md`, `docs/phases/V1_PHASE_09_DEFINITION.md`, and the existing codebase in depth — `Staff`/`Client`/`Contact`/`Role`/`Permission`/`User` models, `StaffController`/`ClientController`/`ContactController`/`CheckInController`/`OperationalStatusController` and their Form Requests/Resources/routes/factories/tests, `RolePermissionSeeder`, `AppServiceProvider`'s `Gate::before`, `EnsureAccountIsActive`.

**Key finding from inspection (unchanged since Phase 6):** no Admin Backoffice (Blade/Livewire) CRUD screen exists for any module yet. This phase again follows that precedent — API/backend only.

## 3. Final Project Model / Final Project Membership Model / Key Decisions (DEC-033)

- **Project** — `public_id` (ULID, DEC-017), `project_code` (nullable, unique when present, admin-supplied — mirrors `clients.client_code`, no auto-numbering), `name` (required), `description` (nullable), `client_id` (nullable, `restrictOnDelete()` against `clients` — a Project may be internal), `status` (`App\Enums\ProjectStatus`: `planned`/`active`/`on_hold`/`completed`/`cancelled`), `start_date`/`target_end_date`/`completed_date` (nullable dates), `notes` (nullable), timestamps.
- **No `project_manager_staff_id` column.** Leadership is expressed entirely through Project Membership's `role` (`project_lead`), avoiding two competing sources of truth — the governing instructions explicitly favored this once Membership already needs roles.
- **Project ↔ Client:** optional, one Client per Project, no many-to-many. An inactive Client retains its existing Projects and may still receive new ones (no status cascade or restriction).
- **Lifecycle:** `App\Enums\ProjectStatus`, one mechanism only (no soft deletes/archival flag). `completed_date` is required when the effective status is `completed` (mirrors `StaffStatus::Separated`/`separation_date`, Phase 7); reopening a completed project is permitted and does not clear `completed_date`.
- **Project Membership** — `project_id`/`staff_id` (both `restrictOnDelete()`), `role` (`App\Enums\ProjectMembershipRole`: `project_lead`/`member`, default `member`), unique on `(project_id, staff_id)`, timestamps. No `public_id` — addressed via its Project's and Staff's `public_id` (nested route), consistent with DEC-017's "pivot/history tables generally don't need one."
- **Membership represents the current roster only** — no historical-period tracking, no status/ended-date column; removal is a hard delete. Future Tasks/Work Logs are expected to reference `staff_id`/`project_id` directly, so historical work data is not lost when a membership row is later removed.
- **No single-project-lead enforcement** — unlike Contact's `is_primary`, `project_lead` is a responsibility tag; multiple co-leads are permitted, no transactional clear-then-set.
- **Staff eligibility:** only `active` Staff (Phase 7's `StaffStatus`) may be **newly** assigned to a Project (validated server-side); an existing membership is left untouched if the staff member later becomes inactive/separated.

## 4. Authorization

Two new permissions added to `RolePermissionSeeder` (Phase 5–9 pattern — no new mechanism):
- `projects.view` — Administrator (via `Gate::before`) and **Manager only** — the first `*.view` permission in this codebase not also granted to Staff, since Projects may carry sensitive client-engagement information, unlike the company-wide Staff/Client/Organization directories.
- `projects.manage` — Administrator-only. Covers Project CRUD **and** all Project Membership writes (add/change-role/remove) — no separate `project-membership.*` pair, and no project-lead self-management carve-out.

**Visibility (the phase's central design question):** `GET /api/v1/projects`/`{public_id}` (and the nested members listing) are **not** gated by a bare `can:projects.view` route middleware, because that would blanket-deny every ordinary Staff member. Instead, `App\Http\Controllers\Api\V1\Projects\Concerns\AuthorizesProjectVisibility` (shared by `ProjectController`/`ProjectMembershipController`):
1. A requester holding `projects.view` (Administrator/Manager) sees every Project.
2. Otherwise, a requester with a linked Staff record sees only Projects where that Staff record holds a Project Membership.
3. A requester with neither sees nothing (`403`).

This is the second real row-level authorization pattern in this codebase (after Phase 9's Manager→direct-reports scoping in `CheckInController`), and the first applied to a primary resource's own `GET` endpoints rather than a `/me/...` or secondary endpoint.

## 5. Database / Schema Changes

Two new migrations:
- `2026_09_11_100000_create_projects_table.php` — `id`, `public_id` (ULID, unique), `project_code` (nullable, unique), `name`, `description` (nullable text), `client_id` (nullable, `constrained('clients')->restrictOnDelete()`), `status` (string, default `planned`), `start_date`/`target_end_date`/`completed_date` (nullable dates), `notes` (nullable text), timestamps.
- `2026_09_11_100001_create_project_memberships_table.php` — `id`, `project_id` (`constrained('projects')->restrictOnDelete()`), `staff_id` (`constrained('staff')->restrictOnDelete()`), `role` (string, default `member`), timestamps, unique `(project_id, staff_id)`.

## 6. Models

`App\Models\Project` — table `projects`; casts `status`/`start_date`/`target_end_date`/`completed_date`; assigns `public_id` and defaults `status` to `Planned` in a `creating` hook (same pattern as every prior ULID-bearing model). `getRouteKeyName()` returns `public_id`. Relations: `client()` (`BelongsTo`), `memberships()` (`HasMany`).

`App\Models\ProjectMembership` — table `project_memberships`; casts `role`; defaults `role` to `Member` in a `creating` hook. Relations: `project()`, `staff()` (both `BelongsTo`).

`App\Models\Client` gained `projects(): HasMany`. `App\Models\Staff` gained `projectMemberships(): HasMany`.

## 7. API / Endpoints

| Method | Path | Access |
|---|---|---|
| `GET` | `/projects` | scoped (see §4) |
| `GET` | `/projects/{public_id}` | scoped (see §4) |
| `POST` | `/projects` | `projects.manage` |
| `PUT`/`PATCH` | `/projects/{public_id}` | `projects.manage` |
| `DELETE` | `/projects/{public_id}` | `projects.manage` |
| `GET` | `/projects/{public_id}/members` | scoped, same as viewing the project |
| `POST` | `/projects/{public_id}/members` | `projects.manage` |
| `PUT`/`PATCH` | `/projects/{public_id}/members/{staff_public_id}` | `projects.manage` |
| `DELETE` | `/projects/{public_id}/members/{staff_public_id}` | `projects.manage` |

Nested member routes — the first genuinely nested resource with real endpoints in this API (Contact→Client, Phase 8, was deliberately kept flat instead). Addressed by the member's Staff `public_id` within the nested collection (unique per `(project_id, staff_id)`), not an independent membership `public_id`.

`App\Http\Controllers\Api\V1\Projects\{Project,ProjectMembership}Controller`; `App\Http\Requests\Projects\{Store,Update}ProjectRequest`/`{Store,Update}ProjectMembershipRequest`; `App\Http\Resources\{Project,ProjectMembership}Resource`; shared `App\Http\Controllers\Api\V1\Projects\Concerns\AuthorizesProjectVisibility` trait.

## 8. Validation / Integrity

- **Project:** `name` required; `project_code` nullable, unique when present; `client_id` resolved server-side from `public_id` (`Rule::exists`); `status` a valid `ProjectStatus`; `target_end_date`/`completed_date` each validated not to precede `start_date` (effective-date comparison on update, mirroring `UpdateStaffRequest`'s pattern); `completed_date` required when the effective status is `completed`.
- **Membership:** `staff_id` required, resolved from `public_id`, must exist; `role` a valid `ProjectMembershipRole`; the target Staff member's employment status must be `active` for a **new** membership (not re-checked on role update); duplicate `(project_id, staff_id)` rejected with a clean `422` (backed by the DB unique constraint).
- Public IDs are resolved server-side to internal keys throughout — no internal numeric ID is ever exposed or accepted.

## 9. Factories / Seeders

`ProjectFactory` (states: `active()`, `onHold()`, `completed()`, `cancelled()`) and `ProjectMembershipFactory` (state: `projectLead()`) — standard Eloquent factories for test data. No production/demo seeder — Project/Project Membership are business-owned data, same reasoning as every prior business-module phase. `RolePermissionSeeder` extended with the two new permissions.

## 10. Files Changed

**Added (backend):** `app/Enums/{ProjectStatus,ProjectMembershipRole}.php`; `app/Models/{Project,ProjectMembership}.php`; `database/migrations/2026_09_11_100000_create_projects_table.php`; `database/migrations/2026_09_11_100001_create_project_memberships_table.php`; `database/factories/{Project,ProjectMembership}Factory.php`; `app/Http/Controllers/Api/V1/Projects/{Project,ProjectMembership}Controller.php`; `app/Http/Controllers/Api/V1/Projects/Concerns/AuthorizesProjectVisibility.php`; `app/Http/Requests/Projects/{Store,Update}ProjectRequest.php`; `app/Http/Requests/Projects/{Store,Update}ProjectMembershipRequest.php`; `app/Http/Resources/{Project,ProjectMembership}Resource.php`; `tests/Feature/Api/V1/Projects/{ProjectTest,ProjectMembershipTest}.php`; `tests/Feature/Authorization/ProjectsAuthorizationTest.php`.

**Modified (backend):** `app/Models/Client.php` (`projects()` relation); `app/Models/Staff.php` (`projectMemberships()` relation); `app/Http/Controllers/Api/V1/Clients/ClientController.php` (`destroy` extended); `app/Http/Controllers/Api/V1/Staff/StaffController.php` (`destroy` extended); `database/seeders/RolePermissionSeeder.php` (two new permissions + Manager attachment); `routes/api/v1.php` (new route group); `tests/Feature/Api/V1/Clients/ClientTest.php` (1 new delete-protection test); `tests/Feature/Api/V1/Staff/StaffTest.php` (1 new delete-protection test); `tests/Feature/Authorization/RolePermissionSeederTest.php` (2 new tests for the extended catalog).

**Added (docs):** `docs/phases/V1_PHASE_10_DEFINITION.md`, this handoff.

**Modified (docs):** `docs/02_ARCHITECTURE.md` (new §20), `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/ROADMAP.md` (Phase 10 marked complete), `docs/CHANGELOG.md`, `docs/CURRENT_STATE.md`, `docs/DECISIONS.md` (DEC-033), `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`.

No `apps/mobile` files changed — Flutter is entirely unaffected.

## 11. Tests Added/Changed

**49 new PHPUnit tests:**
- `ProjectTest` (23) — CRUD (list/create/validation/unique-project_code/view-by-public_id/internal-id-404/update/delete/delete-with-members-rejected); Client relationship (with/without client, invalid client public_id, project may be created for an inactive client); lifecycle (status change, completed requires completed_date, reopening a completed project, date-order validation); filters/search (status, client, member, name/project_code); member count reporting; `my_role` convenience field.
- `ProjectMembershipTest` (13) — list, add (default role, explicit role), validation (missing/unknown staff_id, unknown/wrong-public_id project), duplicate membership rejected, Staff eligibility (inactive/separated rejected on new assignment, existing membership survives a later status change), role change, updating a non-member's role fails (`404`), removal, role filter.
- `ProjectsAuthorizationTest` (9) — Administrator full access; Manager view-all/no-manage; a Staff project member scoped to only their own project (list/show/members) and denied on any other project; a Staff project member cannot manage projects or membership; a Staff member with no memberships sees an empty list; a no-role user with no linked Staff is denied entirely; a no-role user *with* a linked Staff record still sees their own memberships (mirroring Phase 9's self-service-needs-no-permission precedent); unauthenticated `401`; suspended mid-session `403`.
- 2 new tests in `RolePermissionSeederTest` for the extended permission catalog (including the `projects.view` Manager-only-not-Staff assertion).
- 1 new test in `ClientTest` (delete blocked while Projects reference the Client).
- 1 new test in `StaffTest` (delete blocked while Project Memberships reference the Staff member).

**Unaffected in behavior:** all 216 Phase 1–9 tests pass unmodified.

## 12. Commands/Checks Executed

| Command | Result |
|---|---|
| `composer validate --strict` | `./composer.json is valid` |
| `vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` — clean on the first run |
| `vendor/bin/phpstan analyse` | `{"tool":"phpstan","result":"passed","errors":0}` at level 5 |
| `php artisan test` | `{"tool":"phpunit","result":"passed","tests":265,"passed":265,"assertions":766}` (3 tests failed on the first run — a real routing bug, fixed and re-verified; see §14) |
| `php artisan migrate:fresh --force` (SQLite) | All 19 migrations (17 pre-existing + 2 new) ran cleanly |
| `php artisan db:seed --class=...AdminUserSeeder` | `RolePermissionSeeder` ran (extended catalog created — 14 permissions total; `projects.view` attached to Manager only, confirmed via `tinker`); `AdminUserSeeder` ran |
| `php artisan serve` + curl — full CRUD + membership + visibility-scoping smoke test | See §17 — all steps passed as designed |

## 13. Results

See §12's table; the same results are recorded per-check in `docs/testing/TEST_STATUS.md`'s new Phase 10 section.

## 14. Deviations from Specification — One Real Bug Found and Fixed

Caught by the automated test suite itself before this handoff was written, not left as a known issue:

**Nested route model binding auto-scoping.** `PUT`/`PATCH`/`DELETE /api/v1/projects/{project:public_id}/members/{staff:public_id}` initially returned `500` for every request (`BadMethodCallException: Call to undefined method App\Models\Project::staff()`). Root cause: Laravel's implicit route-model-binding resolver automatically enables "scoped bindings" for a route whenever a later Eloquent parameter has an explicit `:field` binding suffix following an earlier one (`array_key_exists($parameterName, $route->bindingFields())`) — it then tries to resolve the second model via a *guessed relationship name on the first model* (`Project::staff()`/`staffs()`, pluralized from the parameter name), which doesn't exist and was never intended to (membership existence is verified explicitly inside `ProjectMembershipController::update()`/`destroy()` via `$project->memberships()->where('staff_id', $staff->id)->firstOrFail()`). Fixed by calling `->withoutScopedBindings()` on both routes (`routes/api/v1.php`), which disables Laravel's automatic parent-scoping guess and lets each parameter resolve independently by its own `public_id`, exactly as the controller already expects. Covered by the existing tests (`test_administrator_can_change_a_members_role`, `test_updating_a_non_members_role_fails`, `test_administrator_can_remove_a_member`) — no new test was needed, they simply went from failing to passing once the routing fix landed.

No other deviations from `docs/phases/V1_PHASE_10_DEFINITION.md`.

## 14a. Environment Notes

This session's container started with no `vendor/` at all. `composer install` reproduced the same `api.github.com` zipball-scoping issue documented in Phase 6/8/9's handoffs for every third-party dependency (recovered via `--prefer-source`-style git-mirror caching, which composer falls back to automatically), and `phpstan/phpstan` again hit its dist-only/no-usable-`source`-entry exception — its own `git clone --mirror` exceeded Composer's 300s process timeout (large repository history). Recovered exactly as Phase 9 documented: shallow-cloned (`--depth 1 --branch 2.2.13`) the exact locked commit (`9ba9ac76ee9c5cf5b56d58eb5deec6315b7a0260`) directly over plain `https://github.com/...` (seconds, not the timeout), pre-seeded Composer's local VCS mirror cache from that shallow clone, then — when Composer's reference-clone step still failed because a mirror sourced from a shallow clone is itself shallow — copied `phpstan`/`phpstan.phar`/`bootstrap.php`/`composer.json` directly into `vendor/phpstan/phpstan/`, hand-wrote `vendor/bin/phpstan`/`phpstan.phar` proxy scripts (mirroring Composer's own generated pattern), added the package's metadata to `vendor/composer/installed.json`, and ran `composer dump-autoload` to regenerate the rest normally. `vendor/bin/phpstan --version`/`vendor/bin/phpstan analyse` both ran cleanly against this phase's real code (0 errors). `vendor/` is never committed either way, so none of this recovery is part of the diff — `composer.lock` already carried a `source` entry for `phpstan/phpstan` from Phase 8, so no lock-file change was needed this time.

GitHub Actions CI did not run this session — no PR was opened (this session's operating instructions direct not to open one unless the user explicitly asks), so the path-filtered trigger (DEC-015) never fired. All CLAUDE.md §5 quality-gate commands were run directly and locally (§12/§13). Docker-based re-verification was not attempted this session — no Docker configuration changed; the last genuine Docker confirmation remains Phase 5's.

## 15. Known Issues/Limitations

- No Admin Backoffice UI or Flutter mobile screens exist yet for this feature — this phase is API/backend only, consistent with every prior business-module phase's precedent.
- No project milestones — the governing instructions for this phase did not request them, and `03_DATABASE_MODEL.md`'s original `project_milestones` entity remains explicitly unimplemented.
- No project-lead self-management of membership — `projects.manage` is strictly Administrator-only in V1; a future phase could reconsider this if the product owner wants project leads to manage their own project's roster.
- Project Membership has no historical-period tracking — removing a membership is a hard delete with no record of past assignment. This is a deliberate V1 simplification (see DEC-033); a future phase should revisit this only if a genuine reporting requirement demonstrates the need.
- `projects.view`'s absence from Staff means an ordinary Staff member cannot browse a company-wide project list even for non-sensitive internal projects — a deliberate least-privilege default per the governing instructions, not an oversight.

## 16. Explicit Phase 10 Scope Exclusions

Per the governing instructions: tasks, task assignment, subtasks, Kanban, task comments, task dependencies, task status/due dates/notifications, work logs, time tracking, timesheets, payroll, billing, project invoicing, quotations, contracts, CRM opportunity pipelines, file/document management, messaging, notifications, calendars, Gantt charts, resource forecasting, project budgeting/financials, utilization metrics, project profitability, performance scoring, approval workflows. No Admin Backoffice CRUD UI or Flutter mobile screens.

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

curl -s -X POST http://localhost:8012/api/v1/projects -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"project_code":"PRJ-0001","name":"Website Rebuild"}'

curl -s http://localhost:8012/api/v1/projects -H "Authorization: Bearer $TOKEN"
```
Expect a `201` with the new project (a `public_id`, never a numeric `id`), then a `200` list. To verify membership, first create a Staff record (Phase 7 endpoints), then `POST /api/v1/projects/{public_id}/members` with `{"staff_id": "<staff public_id>", "role": "project_lead"}` — expect `201`. To verify Staff-scoped visibility, link that Staff record's `user_id` to a Staff-role test account (as in Phase 7's UAT instructions), sign in as that account, and confirm `GET /api/v1/projects` returns only the one project they are a member of. To verify delete protection, attempt to `DELETE` the Project while the membership still exists — expect `409`.

**This exact sequence was run in this session** via `php artisan serve` (port `8123`) against the real HTTP server, not just PHPUnit's in-process test client: Administrator login → create a Client (`Acme Corp`) → create a Project (`PRJ-0001`, linked to that Client — response correctly nested a minimal `{public_id, name}` Client object, never a numeric id) → create a Staff record → add that Staff as `project_lead` (`201`) → list members (`200`, one entry) → change the member's role to `member` via `PATCH .../members/{staff}` (`200`) — this specific request is what surfaced and confirmed the fix for the nested-route-binding bug in §14 — → attempt to delete the Project while the membership still existed (`409`, correctly rejected) → remove the member (`204`) → delete the now-empty Project (`204`). Separately: created a second Staff-linked Staff-role account, added her to one of two Projects, confirmed `GET /api/v1/projects` returned only her own Project (with the correct `my_role`), confirmed `GET /api/v1/projects/{the-other-project}` returned `403`, and confirmed an unauthenticated request returned `401`.

**UAT:** logged as **NOT RUN** (`UAT-10-01`, `UAT-10-02`, `UAT-10-03` in `docs/testing/UAT_LOG.md`) — this phase built no Admin Backoffice UI or Flutter screens, so there is nothing yet for the product owner to click through visually; all three scenarios are ready for the product owner to exercise via the API directly if desired, per CLAUDE.md §7 (only the product owner may record a `PASS`).

## Recommended Next Phase

**Phase 11 — Tasks**, per `docs/ROADMAP.md` (its stated dependency, Phase 10, is now satisfied).

## Business Functionality Statement

**No functionality outside Projects & Project Membership was introduced in this phase.** No tasks, task assignment, subtasks, Kanban, task comments/dependencies, work logs, time tracking, timesheets, payroll, billing, project invoicing, quotations, contracts, CRM opportunity pipelines, file/document management, messaging, notifications, calendars, Gantt charts, resource forecasting, project budgeting/financials, utilization metrics, project profitability, performance scoring, or approval workflows; no Admin Backoffice CRUD UI; no third-party package for any of this (plain Eloquent models/migrations/Form Requests, consistent with the existing architecture).

---

*Per CLAUDE.md §8 (Stop Discipline): this phase is complete. Not merged into `main`. Phase 11 is not authorized by this handoff and will not begin without explicit user instruction.*
