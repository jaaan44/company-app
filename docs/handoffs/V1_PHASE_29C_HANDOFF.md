# Phase 29C — Work: Projects & Clients (Mobile) — Handoff

**Status: implementation complete and merged (PR #69, `c79ed90`); UAT preparation runbook written — pending staging deployment and product-owner UAT. Phase 29C is NOT formally closed.** See the addendum at the end. Its closure also closes Phase 29 as a whole. No later phase is started or authorized.

## 1. Phase Identification

- **Phase:** 29C — Projects & Clients, the third and last sub-phase of Phase 29 — Work: Tasks, Work Logs, Projects & Clients (Mobile) (R-1).
- **Date:** 2026-10-11
- **Specification:** `docs/phases/V1_PHASE_29_DEFINITION.md` §7 (revision 4: R-19…R-27 approved as written), merged via PR #68. That merge, `e93b7f0`, is the **authoritative specification baseline**.
- **Branch:** `claude/amazing-brahmagupta-dbsrjc`, restarted from exactly `e93b7f0` for implementation.
- **Gates (all on the one branch, no history rewritten):**
  - Gate 1, backend list tie-breakers: `22de74d`
  - Gate 2, mobile projects and clients data and state: `77ef0de`
  - Gate 3, screens, routes, More rows and the task link: `75a4af8`
  - Gate 4, final integration review, this handoff and the implementation PR: the final documentation commit
- **Pull request:** the Phase 29C implementation PR (`claude/amazing-brahmagupta-dbsrjc` → `main`) is opened in Gate 4. It is **not merged**.

## 2. Objective

Let staff look up, from the phone, the projects they belong to (who is on them, their milestones, their client) and the company's clients and contacts, read-only, and reach a task's project from the task.

## 3. Scope Implemented

- **Backend:** an `id` tie-breaker on five list endpoints (R-19). Nothing else.
- **Mobile:**
  - More → **Projects** (member projects, search, paging) and a **project detail** (client, my role, dates, description, members, milestones).
  - More → **Clients** (active clients, search, paging) and a **client detail** (contact details with Copy, active contacts).
  - A task's **Project** row opens the project.
- **Approved decisions honoured:**
  - R-19: tie-breakers on projects, clients, contacts, members and milestones.
  - R-20: member-only projects for every role; the no-profile state.
  - R-21: one name-ordered list with status chips, "Project lead" and search; no status filter.
  - R-22: detail with members (leads first) and milestones, one page of 50 each, "Showing 50 of N".
  - R-23: no "overdue" on milestones.
  - R-24: notes never shown.
  - R-25: links (task → project, project → client, member → Staff directory); a `403` is "You don't have access to this project."
  - R-26: active clients and contacts; any client opens ("Inactive"); Copy; no profile needed.
  - R-27: More gains Projects and Clients.

## 4. Implementation Summary

- **Gate 1:** `->orderBy('id')` appended in five `index` methods, each with a regression test that pins the ORDER BY (SQLite returns ties in rowid order anyway).
- **Gate 2:** strict models; two API clients; `MyProjectsController`/`ClientsController` on a new generic `PagedSearchController<T>` (the Phase 28 directory behaviour plus `noProfile`); detail controllers loading their parts together with `Future.wait`, so no request's error is ever left unhandled.
- **Gate 3:** four screens on a shared `PagedSearchList<T>` body and Phase 28 widgets; status chips styled like the Tasks chip; routes in the More branch; More rows; the task detail's Project row.
- **Gate 4:** full gates, a contract-parity replay of real Laravel responses through the production app code, docs, DEC-056, UAT rows and this handoff.

## 5. Files Changed

- **Backend (`apps/api`):**
  - `app/Http/Controllers/Api/V1/Projects/ProjectController.php`, `ProjectMembershipController.php`, `ProjectMilestoneController.php`, `Clients/ClientController.php`, `Clients/ContactController.php` — one tie-breaker each.
  - Tests: one new test in each of `tests/Feature/Api/V1/Projects/ProjectTest.php`, `ProjectMembershipTest.php`, `ProjectMilestoneTest.php`, `Clients/ClientTest.php`, `Clients/ContactTest.php`.
- **Mobile (`apps/mobile`), new:**
  - `lib/core/network/paged_result.dart`, `lib/core/state/paged_search_controller.dart`, `lib/core/presentation/paged_search_list.dart`
  - `lib/features/projects/{domain/project.dart, data/projects_api_client.dart, state/my_projects_controller.dart, state/project_detail_controller.dart, presentation/projects_page.dart, presentation/project_detail_page.dart, presentation/project_widgets.dart}`
  - `lib/features/clients/{domain/client.dart, data/clients_api_client.dart, state/clients_controller.dart, state/client_detail_controller.dart, presentation/clients_page.dart, presentation/client_detail_page.dart}`
  - Tests: `test/features/projects/{project_models_test, projects_api_clients_test, projects_controllers_test, projects_pages_test}.dart`, `test/support/project_fixtures.dart`
- **Mobile, changed:** `lib/app/app.dart` (two injectable clients), `lib/app/router.dart` (four routes), `lib/features/people/presentation/more_page.dart` (two rows), `lib/features/tasks/presentation/task_detail_page.dart` (the Project row links), `test/features/people/people_pages_test.dart` (More expects five rows).
- **Docs:** see §15.

## 6. Database/Schema Changes

None. No migration (still 45).

## 7. API Changes

- Order only: `GET /api/v1/projects`, `/clients`, `/contacts`, `/projects/{public_id}/members` and `/projects/{public_id}/milestones` end their ORDER BY in `id`. Visible order is unchanged; paging is stable when sort keys repeat.
- No new endpoint, parameter, field or status code.

## 8. Authorization/Security Changes

None in the API. The app's narrower views (member-only projects for every role; active-only lists; no notes) are presentation choices, recorded in DEC-056 and `05_SECURITY_MODEL.md` as **not** a security boundary. The Phase 27 401/403 rule is unchanged: a `403` on a project never ends the session.

## 9. Tests Added or Changed

- **Backend (+5):** one paging/ORDER BY regression per endpoint.
- **Flutter (+52):**
  - models 13 (fields, nulls, enums, dates, `leadsFirst`, `addressLines`, `PagedResult`);
  - API clients 8 (paths, query parameters, page sizes, `403`/`404`, bad shapes);
  - controllers 14 (paging, de-duplication, debounced search, stale responses, refresh, errors, no profile, detail loads and messages);
  - pages 17 (through the whole app: every screen state, links, Copy, the task link and tab behaviour, dark mode and 200% text on a small phone).
- **Changed (1):** the Phase 28 More test now expects five rows.
- **Mutation checks:** Gate 1 5/5, Gate 2 12/12 (after strengthening the debounce test), Gate 3 14/14 — all caught.

## 10. Commands/Checks Executed

- `apps/api`: `composer validate --strict`, `composer audit --locked`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test`.
- `apps/mobile` (Flutter 3.47.2 / Dart 3.13.2): `flutter pub get`, `dart format --output=none --set-exit-if-changed .`, `flutter analyze`, `flutter test` (and per area).
- **Contract parity (Gate 4, temporary, not committed):** a scratch Laravel test (with `RolePermissionSeeder`) recorded 19 real responses — a Staff member's `/me/profile`, `/projects?member=` with and without `q`, two projects, members, milestones, a hidden project's `403`, `/clients?status=active`, an active and an inactive client, active contacts, a missing client's `404`; a Manager's profile, member projects and all projects; and a no-profile account. Non-ASCII names, duplicate client names, equal milestone dates and null fields included. A scratch Flutter test replayed them through the production API clients and controllers: **6/6 groups passed**. Both files were deleted.

## 11. Results (Gate 4 final run)

| Check | Result |
|---|---|
| `composer validate --strict` / `composer audit --locked` | Valid; no advisories |
| Pint / PHPStan level 5 | Pass; 0 errors |
| `php artisan test` | **1,183/1,183** (3,399 assertions) |
| `dart format` / `flutter analyze` | 0 files changed; no issues |
| `flutter test` | **473/473** (projects 52, work_logs 91, tasks 95, home 66, people 84, core/network 29) |
| `pubspec.yaml` / `pubspec.lock` | Unchanged |
| Contract parity | 6/6; a Manager with `member=` sees 1 of 4 projects (R-20 confirmed); members come lead first then by join order |

## 12. Deviations from Specification

- **One `Project` model**, not `ProjectSummary`/`ProjectDetail` (§7.4): the API returns the same shape for both.
- **Shared list code in `lib/core/`** (`PagedResult`, `PagedSearchController`, `PagedSearchList`) instead of copying the directory twice. The Phase 28 directory is left on its own controller (no unrelated refactor).
- **Task → project** (§7.5 left it open): `context.go` to the More route, switching tabs as Home's task links do; no route under `/tasks`.

## 13. Known Issues/Limitations

- Members, milestones and contacts show their first 50 only ("Showing 50 of N"), by design (R-22/R-26).
- A Manager's or Administrator's non-member projects aren't browsable on mobile (R-20, by design).
- A task's project link can open a project the assignee can't see; the screen says so (R-25). Knowing in advance would cost a request per task.
- `ProjectsApiClient.fetchMyStaffPublicId` duplicates `WorkLogsApiClient`'s; moving the directory onto `PagedSearchController` and shared widgets to `core/` are Phase 36 candidates.
- Carried forward, untouched: uncapped `per_page` and unescaped `q` on legacy endpoints (Phase 36); `ProjectResource.my_role` is one query per row (bounded by page size); debug signing (Phase 38); the deferred UAT password rotation from 29B; the UAT27/28/29A/29B staging data.
- Not verified here: MySQL (plain-column ORDER BY; staging's `verify` will exercise it) and a real device.

## 14. Manual/UAT Testing Instructions

A separately authorized UAT29C runbook will give the exact steps. Needed for UAT-29C-01…08:
- **Staging:** redeploy to the 29C merge (no migration; caches rebuilt); smoke `/projects`, `/clients`, `/contacts` → 401.
- **APK:** built from the merge; expected Flutter 473 (projects 52).
- **Data (dedicated `uat29c.*` accounts):** a Staff member who leads one project and is a member of others (active, on hold, completed), one with no client, a project with more than 50 members or a long milestone list if "Showing 50 of N" is to be seen; a project they're **not** a member of with a task assigned to them (UAT-29C-03's `403`); active and inactive clients with active and inactive contacts, one primary; a Manager and an Administrator who are members of one project each (UAT-29C-06); a no-profile account.
- UAT-29C-07 needs the usual offline toggle and a `revoke` stage.

## 15. Documentation Updated

- `docs/phases/V1_PHASE_29_DEFINITION.md`: status; Gate 1–3 implementation notes.
- `docs/02_ARCHITECTURE.md` §37 (and §36 marked closed); `docs/04_API_CONVENTIONS.md` (Gate 1); `docs/05_SECURITY_MODEL.md` (Projects & Clients); `docs/06_UI_UX_GUIDELINES.md` (Projects and Clients).
- `docs/DECISIONS.md`: DEC-056.
- `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/ROADMAP.md`.
- `docs/testing/TEST_STATUS.md` (Gates 1–4), `docs/testing/UAT_LOG.md` (UAT-29C-01…08 `NOT RUN`).
- This handoff.

## 16. Recommended Next Step

1. Review the Phase 29C implementation PR and its CI (Backend CI and Mobile CI both run).
2. On approval, merge.
3. Prepare a UAT29C runbook (staging redeploy, APK provenance, data script) — a separately authorized documentation step.
4. Deploy to staging and seed the UAT data (operator).
5. The product owner runs UAT-29C-01…08.
6. Formally close 29C, and with it Phase 29.

The next roadmap phase must not begin until 29C is closed and that phase is explicitly authorized (`CLAUDE.md` §8).

**Status summary:**
- **Implemented:** yes.
- **Tested automatically:** yes, backend and mobile, plus a real-output contract parity check.
- **Manually verified on a device:** no.
- **Awaiting UAT:** yes (UAT-29C-01…08 `NOT RUN`).
- **Deployed:** no.
- **Formally closed:** no.

## Addendum — Merge and UAT Preparation Runbook (2026-10-11)

- **Merged:** PR #69 merged into `main` as `c79ed90cac0263a7f7e452c0f7d0861d4adac380`, a standard merge commit with parents `e93b7f0` and `75c1b3f`. Backend CI and Mobile CI passed on the PR head before the merge, and the merged tree is identical to the reviewed head. This is the source for both the staging deployment and the final UAT APK.
- **Runbook:** `docs/testing/PHASE_29C_UAT_PREPARATION.md`. Each step states where to run it, the exact commands, what it does, the expected output and what to paste back (never a password):
  - §2: the staging redeploy from `7e29ffe` (no migration, no route; caches rebuilt), with the corrected backup listing (`ls -lt … | head -3`) and smoke tests for `/projects`, `/clients`, `/contacts`;
  - §3: the APK build (always run) and provenance, expected counts 473 / projects 52 / people 84 / tasks 95, plus `adb install`;
  - §4–§5: the UAT29C data plan and steps (extract and SHA, `plan`, `seed`, `verify`, `exposure`, a same-day re-`seed`);
  - §6: per-scenario UAT notes, including the `revoke` stage for UAT-29C-07;
  - §7: the script `uat29c_data.php`, SHA-256 `1c151c4d4c9fcc7b94918da4a4ed526ac5b9accaa2f415b570702b93a52a4a64`.
- **Data:** four `uat29c.*` accounts (staff, manager, administrator, no profile), 29 projects (28 with Mia as a member over two pages; one, "UAT29C Hidden Site", holding her task but not her), 52 milestones on one project for "Showing 50 of 52", an active and an inactive client, and a primary, a plain and an inactive contact. Every record carries an internal `notes` value the app must never show.
- **New lesson applied:** passwords are pasted only into the app's sign-in field, never into a terminal (the 29B stray-file incident).
- **Rehearsal:** on scratch SQLite only, including the refusals, the all-or-nothing `rotate`, a drift-then-reseed check, a non-UAT data snapshot and the runbook's own extraction path. Results are in `TEST_STATUS.md`. Its `verify` stage is the first MySQL check of the R-19 order.
- **Status:** nothing is deployed or seeded on staging. UAT-29C-01…08 remain `NOT RUN`. Phase 29C is not closed.
