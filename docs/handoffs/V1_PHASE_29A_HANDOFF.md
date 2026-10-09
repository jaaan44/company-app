# Phase 29A — Work: Tasks (Mobile) — Handoff

**Status: implementation complete — pending PR review/CI, merge, staging deployment and product-owner UAT. Phase 29A is NOT formally closed.** Phase 29B (Work logs) and 29C (Projects & Clients) are not started and not authorized.

## 1. Phase Identification

- **Phase:** 29A — Tasks, the first sub-phase of Phase 29 — Work: Tasks, Work Logs, Projects & Clients (Mobile) (R-1 split it into 29A/29B/29C).
- **Date:** 2026-10-09
- **Specification:** `docs/phases/V1_PHASE_29_DEFINITION.md` (revision 2: R-1…R-9 approved as written), merged via PR #60. That merge, `d8d550b`, is the **authoritative specification baseline**.
- **Branch:** `claude/amazing-brahmagupta-dbsrjc`, restarted from exactly `d8d550b` for implementation.
- **Gates (all on the one branch, no history rewritten):**
  - Gate 1, backend `GET /api/v1/me/tasks` and the `/tasks` tie-breaker: `3f5045e`
  - Gate 2, mobile write foundation and Tasks data and state: `d1e0fdf`
  - Gate 3, Tasks screens, Home navigation and router: `793f947`
  - Gate 4, final integration review, this handoff and the implementation PR: the final documentation commit
- **Pull request:** the Phase 29A implementation PR (`claude/amazing-brahmagupta-dbsrjc` → `main`) is opened in Gate 4. It is **not merged**.

## 2. Objective

Replace the Phase 25 `Tasks` placeholder with a real Tasks tab: the signed-in person's own assigned tasks, open or done, in due-date order with company-time due labels, and a detail screen where the assignee can change a task's status. This brings the first write the mobile app makes, so it also adds the shared write foundation (`POST`/`PATCH`/`DELETE`, `422` handling) to `ApiClient`.

## 3. Scope Implemented

- **Backend:**
  - `GET /api/v1/me/tasks`: self-scoped (R-2/R-3), `state=open|closed`, capped paging, server-computed `is_overdue`/`is_due_today`, `tasks: null` without a profile (spec §5.1).
  - A unique `id` tie-breaker on `GET /api/v1/tasks` (R-9).
- **Mobile:**
  - `ApiClient` writes, `422` → `ApiValidationException`, `204` (spec §5.2).
  - The Tasks tab (Open | Done) and task detail with the status control (spec §5.3).
  - Home: the My tasks tile and Today task rows navigate (R-6).
  - `/tasks` and `/tasks/:publicId` inside the Tasks branch; `TasksPlaceholderPage` removed.
- **Approved decisions honoured:**
  - R-1: 29A only; 29B and 29C untouched.
  - R-2: only tasks assigned to me, for every role.
  - R-3: the new `/me/tasks`.
  - R-4: no Cancelled in the app; a cancelled task is read-only.
  - R-5: status is the only edit.
  - R-6: Home task tile and Today task rows navigable; nothing else on Home.
  - R-7: confirmed, not optimistic, saves.
  - R-8: the work-log UTC "today" fix belongs to 29B and is **not** done here.
  - R-9: the `/tasks` tie-breaker.

**Excluded as specified:** creating, editing (other than status), assigning or deleting tasks; Cancelled; work logs (29B); projects and clients (29C); offline queues; push notifications; Admin Backoffice screens; Schedule and Messages (still placeholders).

## 4. Implementation Summary

### Backend (`apps/api`)

- **`MyTaskController::index`** (route `api.v1.me.tasks.index`, `auth:sanctum` + `account.active`, no `can:` middleware):
  - validates `state` (`open`/`closed`), `per_page` (1–50, default 25) and `page`;
  - no linked Staff → `{"data": {"company_day", "tasks": null}, "meta": null}`;
  - otherwise `Task::where('assignee_staff_id', own staff id)` with `TaskController`'s eager loads, filtered by the `OverdueTasks` terminal set;
  - order: open `due_date IS NULL, due_date, id`; closed `completed_at IS NULL, completed_at DESC, updated_at DESC, id`;
  - each item is `TaskResource::resolve()` plus `is_overdue`/`is_due_today` against `OverdueTasks::todayInCompanyTimezone()`.
- **`TaskController::index`:** `->orderBy('id')` after `orderByDesc('created_at')`.

### Mobile

- **`lib/core/network/`:**
  - `ApiClient` sends every verb through one `_send`, so reads and writes share the token, the 401 expiry and the 403 → one `/auth/me` re-check.
  - `postJson`/`patchJson` return the body or `null` for `204`; `delete` accepts any 2xx; `getJson` still requires a JSON object.
  - `422` → `ApiValidationException(message, fieldErrors)`, a subtype of `ApiRequestException`.
  - Nothing is retried: a write is sent at most once.
- **`lib/features/tasks/domain/task_item.dart`:**
  - `TaskItem` (strict `TaskResource`, plus the optional flags), `TaskStatus` (`selectable` excludes Cancelled), `TaskPriority`, `TaskRef`, `TaskCompanyDay`, `MyTasksPage`;
  - `dueState()` uses the server flags, or a server-reported company date by the server's exact rule — never the device clock;
  - `withServerUpdate()` keeps flags only while they still hold.
- **`data/TasksApiClient`:** `fetchMyTasks` (always `per_page=25`), `fetchTask`, `updateStatus` (`PATCH {"status"}`).
- **`state/`:**
  - `MyTasksController`: one per segment; the Phase 28 directory rules (joined first-page requests, generation-based stale-response dropping, de-duplication, load-more retry), plus the no-profile state.
  - `TaskDetailController`: the resource lifecycle (opens loaded when given the row's item); `saveStatus` is confirmed, single-flight and guarded by a save epoch so an older refresh can't overwrite a save; 403 → message, reload, lock, and "no longer mine"; 422 → the `status` error.
  - `TaskChanges`: a session-wide record of confirmed saves. The lists apply each change at once (a task leaving its segment disappears) and refresh; Home refreshes.
- **`presentation/`:**
  - `TasksPage`: a `SegmentedButton` (Open | Done), rows with project, a status chip that always carries text, and the due label; Phase 28 paging and retry row; empty and no-profile states.
  - `TaskDetailPage`: details through the People info rows; the status control as a wrapping row of `ChoiceChip`s with a progress bar while saving and the failure message beneath.
  - `task_widgets.dart`: `TaskStatusChip`, `TaskDueText`, `taskDueLabel`, `TaskDetailArgs`.
- **Wiring:**
  - `CompanyApp` owns a `TasksApiClient` (injectable) and the `TaskChanges`; `buildAppRouter` takes both.
  - `HomePage` takes `taskChanges`; its tasks tile goes to `/tasks` and a Today task row to `/tasks/:publicId` with Home's company date.
  - `home_formatting.dart` gains `formatShortDate`.

## 5. Files Changed

40 files against `d8d550b` (including this handoff): 18 added, 22 modified, 0 deleted.

- **Backend implementation (3):**
  - `apps/api/app/Http/Controllers/Api/V1/Tasks/MyTaskController.php` (new)
  - `apps/api/app/Http/Controllers/Api/V1/Tasks/TaskController.php`
  - `apps/api/routes/api/v1.php`
- **Backend tests (2):** `apps/api/tests/Feature/Api/V1/Tasks/MyTaskTest.php` (new), `apps/api/tests/Feature/Api/V1/Tasks/TaskTest.php`
- **Mobile implementation (15):**
  - `lib/app/{app,router}.dart`
  - `lib/core/network/{api_client,api_exception}.dart`
  - `lib/features/home/presentation/{home_page,home_formatting}.dart`
  - `lib/features/shell/presentation/placeholder_page.dart` (`TasksPlaceholderPage` removed)
  - `lib/features/tasks/data/tasks_api_client.dart`
  - `lib/features/tasks/domain/task_item.dart`
  - `lib/features/tasks/state/{my_tasks_controller,task_detail_controller,task_changes}.dart`
  - `lib/features/tasks/presentation/{tasks_page,task_detail_page,task_widgets}.dart`
- **Mobile tests (9):**
  - `test/app/router_test.dart` (Tasks expectation)
  - `test/features/home/home_page_test.dart` (interaction group rewritten for R-6)
  - `test/core/network/api_client_write_test.dart`
  - `test/features/tasks/{task_models_test,tasks_api_client_test,my_tasks_controller_test,task_detail_controller_test,tasks_pages_test}.dart`
  - `test/support/task_fixtures.dart`
- **Documentation (11, including this handoff):**
  - `docs/{02_ARCHITECTURE,04_API_CONVENTIONS,05_SECURITY_MODEL,06_UI_UX_GUIDELINES,CHANGELOG,CURRENT_STATE,DECISIONS}.md`
  - `docs/phases/V1_PHASE_29_DEFINITION.md`
  - `docs/testing/{TEST_STATUS,UAT_LOG}.md`
  - `docs/handoffs/V1_PHASE_29A_HANDOFF.md`

Unchanged: migrations, environment files, Docker/infrastructure, CI workflows, `composer.json`/`composer.lock`, `pubspec.yaml`/`pubspec.lock`.

## 6. Database/Schema Changes

None. Both orderings use existing columns; the tie-breaker is the primary key.

## 7. API Changes

- **Added `GET /api/v1/me/tasks`:** `{"data": {"company_day": {date, timezone}, "tasks": [TaskResource + is_overdue, is_due_today] | null}, "meta": {current_page, last_page, per_page, total} | null}`.
  - **Errors:** `401` for a missing, invalid, revoked or expired token; `403` for an inactive account; `422` for an unknown `state`, `per_page` outside 1–50, or a non-positive `page`.
- **Changed `GET /api/v1/tasks`:** deterministic order `created_at DESC, id`. The response shape, filters, authorization and the visible order of distinct timestamps are unchanged.
- **Unchanged and now used by the app:** `GET /api/v1/tasks/{public_id}` and `PATCH /api/v1/tasks/{public_id}` `{"status"}` with the Phase 11 rules.

## 8. Authorization/Security Changes

- **No new permission.** `/me/tasks` is token-scoped only; foreign `assignee`/`staff` parameters are ignored (tested).
- **No widening by role:** Staff, a Manager with a direct report's task, and an Administrator each see only their own assigned tasks, not tasks they created for others or unassigned ones (tested).
- **No new exposure:** each item is the existing `TaskResource` plus two booleans; the assignee could already read these tasks via `GET /tasks/{id}`.
- **Writes unchanged:** status changes go through the existing `PATCH` and `AuthorizesTaskAccess`. The app's R-4/R-5 restrictions are presentation choices; the API stays authoritative (an Administrator could still cancel via the API or Admin Backoffice).
- **Session rules on writes:** identical to reads; a 403 on a write is never resent.
- Recorded in DEC-054 and `05_SECURITY_MODEL.md` (My Tasks self-scope).

## 9. Tests Added or Changed

- **Backend:** 22 new.
  - `MyTaskTest` (21; 19 methods, one run for three roles): auth, shape, no internal ids, no-profile, empty, self-scope for every role, parameters can't change the subject, states and `422`, both orders, stable paging, `per_page` default and cap, flags, the Manila-midnight flip, parity with `/me/home` counts, constant query count, and the ORDER BY tie-breakers.
  - `TaskTest` (+1): same-`created_at` paging plus the SQL `order by "created_at" desc, "id" asc`.
- **Mobile:** 115 new, 2 files changed.

  | File | Tests |
  |---|---|
  | `api_client_write_test` | 20 |
  | `task_models_test` | 21 |
  | `tasks_api_client_test` | 11 |
  | `my_tasks_controller_test` | 19 |
  | `task_detail_controller_test` | 22 |
  | `tasks_pages_test` | 22 (widget, through the real app, including light/dark at 200% text) |

  `home_page_test`: the Phase 27 R-1 "nothing is tappable" group (3 tests) is rewritten for R-6 (5 tests). `router_test` now expects `TasksPage`.
- **Regression proof (mutation checks):** every key rule was temporarily broken, the tests run, and the file restored; see `TEST_STATUS.md` for each gate. Backend 5 (the `/tasks` tie-breaker, both `/me/tasks` tie-breakers, nulls-last, the assignee filter), all caught; mobile data/state 9, all caught; mobile UI 7 caught directly and 2 survivors resolved — one was an equivalent mutant, replaced by a sharper one that is caught, and one exposed a real gap (the detail opening with content), closed with a new test that now catches it.

## 10. Commands/Checks Executed

All `CLAUDE.md` §5 commands, at the final tree.

- **Backend (`apps/api`):** `composer validate --strict`, `composer audit --locked`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test` (`composer install` and `.env` were already in place in this sandbox).
- **Mobile (`apps/mobile`):** `flutter pub get`, `dart format --output=none --set-exit-if-changed .`, `flutter analyze`, `flutter test`.
- **Integration check (temporary, not committed):** a scratch backend test captured real `/me/tasks` (open and closed), `GET /tasks/{id}`, `PATCH` success and `422` for Staff, Manager and Administrator, both kinds of `403` (task not mine; a non-status field from the assignee), and the no-profile response, with non-ASCII titles, project and creator names and null fields. A scratch Flutter test parsed every response with the production models and mapped the real `422`/`403` bodies through the real `ApiClient`: 9/9 passed. Both files were deleted.

## 11. Results (Gate 4 final run)

| Check | Result |
|---|---|
| `composer validate --strict` | valid |
| `composer audit --locked` | no advisories |
| Pint | pass |
| PHPStan level 5 | 0 errors |
| `php artisan test` | **1,169/1,169** (3,310 assertions; 1,147 baseline + 22) |
| `flutter pub get` | ok; `pubspec` unchanged |
| `dart format` | 0 changed |
| `flutter analyze` | no issues |
| `flutter test` | **330/330** (213 baseline + 117: 115 new and a net 2 in the Home group) |
| Contract parity | real Laravel output parsed and mapped by the app for every case above (9/9) |

## 12. Deviations from Specification

- **Closed order (§5.1):** `completed_at DESC` (nulls last), then **`updated_at DESC`**, then `id`. Without `updated_at` all cancelled tasks (no `completed_at`) would sit in creation order. Recorded in DEC-054 and the spec's Gate 1 notes.
- **"Refreshed the next time they're shown" (§5.3):** implemented as a quiet background refresh as soon as a confirmed change is recorded, so the lists and Home are already current when shown, rather than by detecting visibility. The list also applies the change locally at once.
- **`403` on save (§5.3):** besides reloading and showing the server's message, the status control is locked for that screen and the task is removed from my lists (the refusal means it is no longer mine to change).
- **Detail due flag (§5.3):** single-task responses carry no flags. The detail uses the row's server flags while they still hold, otherwise the company date handed over by the list or Home, and shows no flag rather than guessing when neither is known.
- **Status control form:** a wrapping row of `ChoiceChip`s rather than a segmented button, so it can't clip at large text.
- **UAT data (§10):** a UAT29 seed is still needed. It follows the Phase 28 runbook pattern after merge (see §14), as in Phases 27/28.

## 13. Known Issues/Limitations

- **Completed date on the detail uses the device timezone:** `/me/tasks` and `/tasks/{id}` give the company timezone's name but no offset. Due labels and flags are unaffected. A small backend addition (`company_day.utc_offset` on `/me/tasks`, as `/me/home` has) would fix it; recorded for a later phase.
- **No MySQL run of the new ordering in this sandbox:** `due_date IS NULL` / `completed_at IS NULL` are standard SQL valid on MySQL 8; the tests ran on SQLite. Staging deployment exercises it.
- **Cross-feature reuse:** the Tasks screens reuse `people_widgets.dart` (error view, info rows, headers, padding) and `home_formatting.dart`. Moving shared widgets and formatting to `core/` remains a Phase 36 candidate (already noted in Phase 28).
- **`TaskChanges` is session-wide and in memory:** it does not survive an app restart (the lists load fresh then).
- **A task reassigned to me while the list is open** appears on the next refresh, not live (no push).
- **Carried forward, unchanged:** `per_page`/`q` hardening on legacy endpoints (Phase 36); the Phase 27/26/28 items; the work-log UTC "today" defect (29B, R-8).
- **No device testing:** no device or emulator was available. Nothing in 29A is manually verified on a device.

## 14. Manual/UAT Testing Instructions

**Prerequisites:**
- **Merge** this PR.
- **Deploy to staging:** an app image rebuild is required (new route and controller), followed by `route:cache`; no migration.
- **Smoke test:** an unauthenticated `GET /api/v1/me/tasks` → `401`.
- **Build the UAT APK** from the merge commit with `--dart-define=API_BASE_URL=https://company-staging.storm-ark.com/api/v1`, and record its provenance (SHA, toolchain, size, SHA-256).

**UAT data** (a reviewed operator script in the Phase 28 runbook style; dedicated records only; passwords in a `0600` file, shredded, never pasted anywhere):
- **A Staff user** with assigned tasks covering: overdue, due today, due later, no due date; To do, In progress and Blocked; one completed and one cancelled; one in a project and one independent; enough open tasks for a second page (at least 26) (UAT-29A-01/02/03).
- **An Administrator and a Manager**, each with one task of their own, while other people's tasks (including a direct report's for the Manager) exist (UAT-29A-04).
- **An account without a staff profile** (UAT-29A-04).
- **For UAT-29A-06:** a way for the operator to reassign one of the Staff user's tasks server-side, and to revoke the user's tokens, during the session.
- Today's company date is `Asia/Manila` on staging, so "due today" must be set in Manila time.

Then run UAT-29A-01…07 as listed in `docs/testing/UAT_LOG.md`.

## 15. Documentation Updated

- `CURRENT_STATE.md`, `CHANGELOG.md`
- `02_ARCHITECTURE.md` (§35 Work — Tasks, and the `ApiClient` write rules)
- `04_API_CONVENTIONS.md` (`/me/tasks`, the `/tasks` order)
- `05_SECURITY_MODEL.md` (My Tasks self-scope)
- `06_UI_UX_GUIDELINES.md` (Tasks; the first write conventions: confirmed saves, failure messages, status chips; the R-6 refinement of Home)
- `DECISIONS.md` (DEC-054)
- `phases/V1_PHASE_29_DEFINITION.md` (status and per-gate implementation notes; the approved text is unchanged)
- `testing/TEST_STATUS.md` (Gates 1–4)
- `testing/UAT_LOG.md` (UAT-29A-01…07, **all `NOT RUN`**)
- this handoff

`ROADMAP.md` is deliberately left for the end of 29C (spec §11).

## 16. Recommended Next Step

1. Review the Phase 29A implementation PR and its CI. Backend CI and Mobile CI should both run, because it touches `apps/api/**` and `apps/mobile/**`.
2. On approval, merge.
3. Prepare a UAT29A runbook (staging redeploy, APK provenance, data script) — a separately authorized documentation step, as in Phase 28.
4. Deploy to staging and seed the UAT data (operator).
5. The product owner runs UAT-29A-01…07.
6. Formally close 29A.

29B (Work logs) must not begin until 29A is closed and 29B (including its detailed spec revision) is explicitly authorized (`CLAUDE.md` §8).

**Status summary:**
- **Implemented:** yes.
- **Tested automatically:** yes, backend and mobile, plus a real-output contract parity check.
- **Manually verified on a device:** no.
- **Awaiting UAT:** yes (UAT-29A-01…07 `NOT RUN`).
- **Deployed:** no.
- **Formally closed:** no.
