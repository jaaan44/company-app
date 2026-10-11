# Phase 29 — Work: Tasks, Work Logs, Projects & Clients (Mobile) — Specification

**Status:** **Revision 4 (2026-10-11): §7 specifies 29C — Projects & Clients in full. Decisions R-19…R-27 approved as written by the product owner (2026-10-11).** Implementation needs its own authorization, gate by gate.

*Previous status line (kept as written):* **29B: COMPLETE — FORMALLY CLOSED 2026-10-10** (PR #65, `7e29ffe`; deployed to staging; UAT-29B-01…08 PASS). **29A: COMPLETE — FORMALLY CLOSED 2026-10-10.** 29C (Projects & Clients) is not started; it needs its detailed revision and explicit authorization (`CLAUDE.md` §1/§8).

*Previous status line (kept as written):* Revision 3 (2026-10-10): §6 specifies 29B — Work logs in full. **Decisions R-10…R-18 approved as written by the product owner (2026-10-10).** **29B implementation complete and merged (PR #65, `7e29ffe`, 2026-10-10); UAT preparation runbook `docs/testing/PHASE_29B_UAT_PREPARATION.md` written — pending staging deployment and UAT; not formally closed.** See `docs/handoffs/V1_PHASE_29B_HANDOFF.md`. Everything outside §6 (the approved revision 2, R-1…R-9, and the 29A sections) is unchanged. **29A: COMPLETE — FORMALLY CLOSED 2026-10-10** (PR #61, `3632ce1`; UAT-29A-01…07 PASS). 29C is not started.

*Previous status line (kept as written):* Revision 2, approved (R-1…R-9 as written, 2026-10-09; merged via PR #60). **29A: COMPLETE — FORMALLY CLOSED 2026-10-10.** Implementation merged (PR #61, `3632ce1e821ccaca205f19abf5196e3df54fd35b`) and deployed to staging; physical-device UAT-29A-01…07 all **PASS** (2026-10-10; `docs/testing/UAT_LOG.md`). The §5.4 table keeps its wording as specified; it is not the results record. **29B and 29C are not started**; each needs its own explicit authorization, and 29B its detailed revision first (`CLAUDE.md` §1/§8).

*Status history (kept as written):* Revision 2, approved (R-1…R-9 as written, 2026-10-09; merged via PR #60). **29A implementation complete (Gates 1–4, 2026-10-09) — pending PR review/CI, merge, staging deployment and UAT; not formally closed.** See `docs/handoffs/V1_PHASE_29A_HANDOFF.md`. 29B and 29C each need their own explicit authorization (`CLAUDE.md` §1/§8).

*Previous status (revision 2 as merged):* DRAFT, revision 2. It incorporates the product owner's specification-review decisions: all nine recommendations R-1…R-9 were approved as written (2026-10-09). It is proposed for approval and **is not authorized for implementation**; each sub-phase's gates need explicit authorization (`CLAUDE.md` §1/§8). No application, test, migration, route, dependency or configuration change has been made.

**Depends on:**
- Phase 25: the shell, with a `Tasks` tab placeholder.
- Phase 27: the `ApiClient` and its 401/403 rule, the Home tiles (R-1: they become navigable once their screens exist), and the canonical "open"/"overdue" task definitions (DEC-052).
- Phase 28: the More menu (R-8: each phase adds its own rows), `/me/profile`, and the paged-list conventions in `06_UI_UX_GUIDELINES.md`.

It reads and writes data owned by:
- Phase 8 (Clients and Contacts);
- Phase 10 (Projects and Membership);
- Phase 11 (Tasks);
- Phase 12 (Work Logs);
- Phase 17 (Project Milestones);
- Phase 20 (`OverdueTasks`).

**Supersedes:** nothing. It turns `docs/ROADMAP.md`'s Phase 29 line ("the Tasks tab, Projects/Client browsing, and Work Log entry; may be split into sub-phases") into a concrete plan.

---

## 1. Objective

Let an employee do their day-to-day work from the phone:
- see the tasks assigned to them and move them along (status);
- record the work they did (work logs);
- look up the projects they belong to and the clients behind them.

This is the app's **first write surface**. Everything before it (Phases 25–28) was read-only.

## 2. Starting Baseline

- `main` at `9fb62be974af8a403c82102b4bcb9efdc3903eb4` (PR #59, Phase 28 closure). Phases 1–28 are merged; Phase 28 was formally closed on 2026-10-09.
- Staging runs `b6e85c5` (Phase 28).
- **Mobile:**
  - the `Tasks` tab is `TasksPlaceholderPage`;
  - `More` lists My profile and Staff directory;
  - `ApiClient` supports **GET only**;
  - dependencies are unchanged since Phase 25.

## 3. Current-State Findings (read-only repository discovery)

### 3.1 The backend already supports everything this phase needs, with the gaps listed in §3.5

| Area | Endpoints | Who sees / may do what (existing rules) |
|---|---|---|
| Tasks (Phase 11) | `GET/POST /tasks`, `GET/PATCH /tasks/{id}`, `DELETE` (Admin) | **See:** `tasks.view` (Administrator, Manager) sees all; otherwise tasks in projects you're a member of, plus tasks assigned to you. **Update:** an Administrator or the Project Lead may change any field; the **assignee may change only `status`** (`AuthorizesTaskAccess`). **Create:** an Administrator or the Project Lead. |
| Work logs (Phase 12) | `GET/POST /me/work-logs`, `PATCH/DELETE /me/work-logs/{id}` | Self-service with a linked Staff record. A log needs a **task or a project** (never both; the project is derived from the task). Eligibility: active staff; a project member, or the assignee of an independent task. `work_date` ≤ today; 1–1440 minutes; a description of at most 2000 characters. An update may change only date, duration and description. |
| Projects (Phase 10/17) | `GET /projects`, `/projects/{id}`, `/projects/{id}/members`, `/projects/{id}/milestones` | `projects.view` (Manager, Administrator) sees all; Staff see **only projects they're a member of**. `ProjectResource` includes `my_role`. |
| Clients (Phase 8) | `GET /clients`, `/clients/{id}`, `GET /contacts?client=` | `clients.view` = Administrator, Manager and Staff: **company-wide**. |

### 3.2 Task details

- `TaskStatus` is `todo`, `in_progress`, `blocked`, `completed` or `cancelled`. There are **no transition rules**: an assignee may set any of the five, including `cancelled`, and may reopen a completed task.
- `completed_at` is set and cleared by the server.
- `GET /tasks` filters are `project`, `status`, `assignee`, `priority` and `q`. It orders by `created_at DESC` **with no tie-breaker** (the Phase 28 R-6 paging problem), and there is **no "assigned to me" shortcut** and no due-date ordering.
- `TaskResource` exposes `title`, `description`, `status`, `priority`, `due_date`, `completed_at`, `project {public_id, name}`, `assignee` and `created_by`. It has no overdue flag, so a client would have to compute "overdue" itself in the company timezone.

### 3.3 Work logs: "today" is checked in UTC (a real defect for mobile entry)

- `StoreMyWorkLogRequest`/`UpdateMyWorkLogRequest` use `before_or_equal:today`, and `config/app.php` has `timezone = UTC`.
- With the company timezone `Asia/Manila` (UTC+8), from **00:00 to 07:59 Manila time every day**, logging work for the Manila "today" is rejected as a future date.
- Phase 27 (DEC-052) already made "today" mean `CompanyTimezone`'s date for Home. This rule predates that.

### 3.4 Mobile

- `ApiClient` has `getJson` only. There is no POST, PATCH or DELETE, no handling of `422` validation errors (Laravel's `{"message", "errors": {field: [..]}}`), and no `204` handling.
- `TasksPlaceholderPage` is the `/tasks` branch's only route.
- Home's "My tasks" tile and Today task rows are deliberately not tappable (Phase 27 R-1, "until their screens exist").

### 3.5 Gaps this phase would close (backend)

1. Work-log "today" in UTC (§3.3).
2. There's no self-scoped, due-date-ordered task list with server-computed overdue and due-today flags (§3.2). A client otherwise needs its own staff id plus its own timezone logic.
3. The task list has an unstable page order (§3.2).

## 4. Product Decisions (specification review)

The product owner approved every recommendation from revision 1 as written (2026-10-09). The alternatives that were not chosen are listed so the reasoning is preserved.

| # | Decision | Treatment in this specification | Not chosen |
|---|---|---|---|
| **R-1** | **Split into three sub-phases**, each with its own gates, PR, staging deploy and UAT: **29A Tasks** (plus the mobile write foundation), **29B Work logs**, **29C Projects & Clients** (read-only). | §5 specifies 29A in full. §6 and §7 outline 29B and 29C, which each get a short revision before they start. | One phase with everything; a different order. |
| **R-2** | The Tasks tab lists **only tasks assigned to me**, for every role. | §5.1: an Administrator's or Manager's `tasks.view` doesn't widen it (the DEC-052 self-scope). | Including unassigned project tasks; everything visible. |
| **R-3** | **Yes**, a new self-scoped **`GET /api/v1/me/tasks`**. | §5.1: `state=open|closed`; due-date ordering; server-computed `is_overdue` and `is_due_today` in company time; `tasks: null` without a profile. | Reusing `GET /tasks?assignee=` with client-side sorting and timezone logic. |
| **R-4** | The app offers **To do, In progress, Blocked, Completed** (and reopening), **not Cancelled**. | §5.3. App-side only; the API's assignee rule is unchanged (§10). | All five; enforcing it in the API. |
| **R-5** | **Status only** in the app, for everyone. No task creation. | §5.3, §9. | Project Leads creating and editing tasks on mobile. |
| **R-6** | **Yes**, the Home "My tasks" tile and Today **task** rows become navigable. | §5.3. Schedule, message, notification and announcement items stay non-interactive. | Keep Home non-interactive. |
| **R-7** | Status changes are **confirmed, not optimistic.** | §5.3. | Optimistic update with rollback. |
| **R-8** | The work-log "today" defect (§3.3) is fixed **in 29B**, against `CompanyTimezone`, with a regression test at 00:30 Manila time. | §6. | A standalone backend PR now. |
| **R-9** | **Yes**, a `GET /tasks` tie-breaker (`->orderBy('id')`), landing in 29A Gate 1. | §5.1. | Leave it. |

## 5. Sub-phase 29A — Tasks (specified in full)

### 5.1 Backend

**`GET /api/v1/me/tasks`** (R-3):
- `auth:sanctum` + `account.active`, no permission, under `App\Http\Controllers\Api\V1\Tasks\` (or alongside `MyHomeController`, decided at implementation).
- **Parameters:**
  - `state=open|closed` (default `open`; anything else gives `422`);
  - `page` and `per_page` (default 25, max 50; this endpoint caps it, unlike the legacy ones).
- **Subject:** the token's own linked Staff record, as `assignee_staff_id` only (R-2).
- **Open** means not `completed`/`cancelled`; **closed** means `completed` or `cancelled`. These are the exact `OverdueTasks` terminal set used by `/me/home`.
- **Response:**
  ```json
  {"data": {"company_day": {"date", "timezone"},
            "tasks": [TaskResource + "is_overdue", "is_due_today"] | null},
   "meta": {"current_page", "last_page", "per_page", "total"}}
  ```
  `tasks: null` (with `200`) when no Staff record is linked.
- **Order:** open tasks by `due_date ASC` (nulls last), then `id`; closed tasks by `completed_at DESC` (nulls last), then `id`.
- **Consistency:** the counts match `/me/home` (open = `tasks.open_count`, the overdue subset = `overdue_count`).
- **Status changes use the existing `PATCH /api/v1/tasks/{public_id}` `{"status": …}`.** It is unchanged: the assignee rule, `completed_at` handling and `403` for anyone else all stay.
- **`GET /tasks`:** add `->orderBy('id')` (R-9).
- **Tests:** self-scope (an Administrator or Manager still sees only their own assigned tasks); both states; ordering and stable pagination; the `per_page` cap; flags in the company timezone, including around Manila midnight; parity with `/me/home` counts; the no-profile `200`; `401`/`403`; constant query count; and the tie-breaker regression.

### 5.2 Mobile write foundation (`lib/core/network/`)

- `ApiClient` gains `postJson`, `patchJson` and `delete`, with the same token and 401/403 session rule as `getJson`.
- **`422`** becomes `ApiValidationException(message, fieldErrors: Map<String, List<String>>)`.
- **`204`** is treated as success with no body.
- Bodies are JSON; requests are never retried automatically.
- **Tests** mirror the Phase 27 `api_client_test` set: a 401 mid-write expires the session, 403 is re-checked, 422 field errors are parsed, network failure, no duplicate session-ending.

### 5.3 Mobile Tasks tab (`lib/features/tasks/`)

- **List (`/tasks`):**
  - Segmented **Open | Done**, mapping to `state=open|closed`.
  - Each row shows title, project name (if any), a due label ("Due today", "Overdue · 3 Sep", "Due 12 Oct", or none) and a status chip.
  - The status chip always pairs colour with text (the accessibility baseline).
  - Paged lists, pull-to-refresh, and the Phase 28 load-more and retry-row conventions.
  - Empty states: "No open tasks assigned to you." / "No completed tasks yet."
  - No-profile state: "No staff profile is linked to this account."
- **Detail (`/tasks/:publicId`):**
  - Title; status and priority; due date (with the overdue/due-today flag); project (plain text in 29A, a link once 29C exists); description; created by; completed at.
  - A **status control** offering To do, In progress, Blocked and Completed (R-4). A cancelled task shows its status read-only, with no control.
  - It saves through `PATCH`, confirmed rather than optimistic (R-7). A `403` ("not your task any more") reloads the task and shows the server's message; a `422` shows the message.
- **After a change:**
  - the list and Home are refreshed the next time they're shown;
  - if the task moved between Open and Done, it leaves the current list segment.
- **Home (R-6):**
  - the "My tasks" tile opens `/tasks`;
  - a Today **task** row opens `/tasks/:publicId`;
  - schedule rows, message, notification and announcement items stay non-interactive.
- **Router:** `/tasks` and `/tasks/:publicId` stay inside the `Tasks` branch. Home → task detail switches to the Tasks branch. `TasksPlaceholderPage` is removed.

### 5.4 29A UAT scenarios (to be added to `UAT_LOG.md` at implementation; all `NOT RUN`)

| ID | Scenario |
|---|---|
| UAT-29A-01 | The Tasks tab lists only my assigned open tasks, overdue first by due date, with correct due labels in company time; Done shows completed and cancelled ones. |
| UAT-29A-02 | Open a task: the details are correct. Change its status To do → In progress → Completed; it moves to Done, and Home's counts match after refresh. Reopen it. |
| UAT-29A-03 | Cancelled is not offered. A cancelled task shows its status with no control. |
| UAT-29A-04 | As an Administrator and as a Manager, the Tasks tab still shows only tasks assigned to me. An account without a profile shows the no-profile state. |
| UAT-29A-05 | Home → My tasks tile opens the Tasks tab; a Today task row opens that task. |
| UAT-29A-06 | Offline: a status change fails clearly, the old status stays, and it works after reconnecting. After the task is reassigned to someone else server-side, saving shows the server's message. After token revocation, the app returns to Login. |
| UAT-29A-07 | Dark mode and large text on the list and detail. |

## 6. Sub-phase 29B — Work logs (revision 3: specified in full; R-10…R-18 approved 2026-10-10)

*Revision 3 (2026-10-10) replaces revision 2's outline with a full specification, after read-only discovery on `main` at `c6c6812` (29A closed). The product owner approved R-10…R-18 as written (2026-10-10). Implementation needs its own authorization, gate by gate (`CLAUDE.md` §1/§8).*

### 6.1 Current-state findings (read-only discovery)

**The API already supports self-service work logs (Phase 12), with these details:**
- `GET /api/v1/me/work-logs` (filters `project`, `task`, `from`, `to`; `per_page`, uncapped, default 50), `POST /me/work-logs`, `PATCH /me/work-logs/{public_id}`, `DELETE /me/work-logs/{public_id}` (`204`). No permission; `RequiresLinkedStaff`.
- **A log references a task or a project, never both and never neither.** With a task, the project is derived from the task by the server.
- **Eligibility (create only, never re-checked later):** the performer's Staff record is `active`; for a project or a project task, the performer is a **member of that project**; for an independent task, the performer is its **assignee**. Errors are `422` on `task_id`, `project_id` or `staff_id`.
- **Fields:** `work_date` (required, a date, `before_or_equal:today`), `duration_minutes` (1–1440), `description` (required, ≤ 2000). **An update may change only those three;** `task_id`/`project_id`/`staff_id` are `prohibited`.
- **Someone else's log:** `404`, not `403` (its existence is itself sensitive).
- `WorkLogResource`: `public_id`, `staff`, `task {public_id, title}`, `project {public_id, name}`, `work_date`, `duration_minutes`, `description`, `created_by`, `created_at`, `updated_at`.
- Work logs are not audit-logged and appear nowhere on `/me/home`.

**Gaps and defects found:**
1. **"Today" is UTC (R-8, confirmed).** All four work-log form requests — the self-service `StoreMyWorkLogRequest`/`UpdateMyWorkLogRequest` **and** the Administrator `StoreWorkLogRequest`/`UpdateWorkLogRequest` — use `before_or_equal:today` with `app.timezone = UTC`. From 00:00 to 07:59 Manila time, the Manila "today" is rejected as a future date.
   - The existing test `test_work_date_cannot_be_in_the_future` uses `now()->addDay()` in UTC. Once the rule is in company time, that date can equal the Manila today (from 16:00 UTC), so the test must be rewritten against the company date or it becomes time-of-day dependent.
2. **`GET /me/work-logs` has no unique tie-breaker:** it orders by `work_date DESC, created_at DESC`. Two logs created in the same second on the same date can be skipped or repeated across pages (the Phase 28 R-6 / 29A R-9 problem).
3. **No company day in the list response.** The form's default and maximum date must be the company "today", which the device clock can't provide (the DEC-052 rule).
4. **No linked Staff record → `403`** ("No staff record is linked to this account.") on every `/me/work-logs` call, unlike the newer `/me/*` endpoints, which return `200` with `null`.
5. **Picker sources exist:**
   - my assigned open tasks: `GET /me/tasks?state=open` (29A);
   - my projects: `GET /projects?member=<own staff public_id>` (Phase 10 filter, tested). Staff are already limited to member projects; Managers and Administrators hold `projects.view`, so without `member` they would see every project, most of which they couldn't log against. `GET /projects` orders by `name` only (no tie-breaker) and does not filter by project status.

**Mobile:** the write foundation from 29A (`ApiClient` POST/PATCH/DELETE, `ApiValidationException` with field errors, `204`) is ready. No form screen exists yet; the 06 guidelines already set the form conventions (`TextFormField`, inline per-field errors, submit disabled in flight, `AlertDialog` for destructive actions).

### 6.2 Product decisions for 29B (approved as written, 2026-10-10)

| # | Decision | Why | Not chosen |
|---|---|---|---|
| **R-10** | **Fix "today" in all four work-log requests**, self-service and Administrator, using the company date (`CompanyTimezone`). Error message: "The work date cannot be later than today." | One rule everywhere; the Administrator Backoffice has the same defect. | Self-service only (leaves the Admin bug). |
| **R-11** | **Tie-breaker on `GET /me/work-logs`:** `work_date DESC, created_at DESC, id DESC`. | Stable paging; visible order unchanged. | Leave it. |
| **R-12** | **Add `meta.company_day` `{date, timezone}` to `GET /me/work-logs`** (additive; the paginator's `meta` keeps its keys). | The form's default and maximum date come from the server, never the device clock (DEC-052). | Reuse Home's company day across features. |
| **R-13** | **No-profile state:** keep the API's `403` unchanged. The app treats a `403` on `GET /me/work-logs` **that survived the `/auth/me` re-check** as "No staff profile is linked to this account." (that is the endpoint's only remaining `403`; no message text is parsed). | No contract change to a Phase 12 endpoint. | Change the list to `200` + `null` like `/me/tasks`. |
| **R-14** | **"What was this for?" picker** offers (a) **my open assigned tasks** (`/me/tasks?state=open`), and (b) **my projects** (`/projects?member=<my staff public_id>`), hiding completed and cancelled projects in the app. **No new endpoint.** A task chosen shows its project automatically. | Covers every eligible case for normal use without new API; server eligibility stays authoritative (a `422` is shown as-is). | A new `GET /me/projects`; all tasks in my projects. |
| **R-15** | **Entry points:** More → **"My work logs"** (list with an "Add" button), and **"Log work"** on a task's detail (task pre-filled; offered for any task that isn't cancelled). | Matches the outline; logging against a just-completed task is common. | List only; detail only. |
| **R-16** | **No day totals in 29B.** Rows show each log's duration; dates are group headers only. | A day can be split across pages, so a client-side total could briefly be wrong; a correct total needs a server aggregate (a later phase). | Client-side totals per loaded day. |
| **R-17** | **Date picker:** from 365 days ago up to the company today (an existing older log keeps its own date as the lower bound when edited). Duration as **hours + minutes** (0–24 h, 0–59 min; total 1 min–24 h). | Prevents impossible input up front; the API remains authoritative. | Any past date; minutes only. |
| **R-18** | **Unsaved changes:** leaving a form with edits asks "Discard changes?"; **delete** asks "Delete this work log?" (the 06 destructive-action rule). No undo. | First real forms; avoids silent data loss. | No discard prompt. |

**Kept from revision 2 and 29A:** saves are confirmed, never optimistic (R-7 carried to forms); nothing is retried automatically; a log's task/project can't be changed after creation (API rule; shown read-only when editing).

### 6.3 Backend (Gate 1)

- **R-10:** replace `before_or_equal:today` with the company date in `StoreMyWorkLogRequest`, `UpdateMyWorkLogRequest`, `StoreWorkLogRequest` and `UpdateWorkLogRequest`, with one shared message. `CompanyTimezone`/`OverdueTasks::todayInCompanyTimezone()` is the single definition of "today".
- **R-11:** `->orderByDesc('id')` after `orderByDesc('created_at')` in `MyWorkLogController::myIndex`.
- **R-12:** `meta.company_day: {date, timezone}` on `GET /me/work-logs` (via the resource collection's `additional`; the existing `data`, `links` and `meta` keys are unchanged).
- **No other change:** eligibility, ownership (`404`), field rules, `per_page` (the app always sends 25), and the `403` without a profile all stay as they are.
- **Tests:**
  - **the R-8 regression:** at 00:30 Manila (16:30 UTC the previous day), logging for the Manila today succeeds and for the Manila tomorrow fails, on create and update, self-service and Administrator;
  - the existing future-date test rewritten against the company date (no time-of-day dependence);
  - the ORDER BY tie-breaker (SQL assertion, as in 29A, because SQLite hides it) and stable paging across identical `work_date`/`created_at`;
  - `meta.company_day` present and correct around Manila midnight; the rest of the response shape unchanged;
  - mutation checks as in 29A.

### 6.4 Mobile data and state (Gate 2) — `lib/features/work_logs/`

- **Models:** `WorkLog` (strict `WorkLogResource`), `MyWorkLogsPage` (with `companyDay`), the picker's target (task or project).
- **`WorkLogsApiClient`:** `fetchMyWorkLogs(page)` (`per_page=25`), `create`, `update` (only date, duration, description), `delete`.
- **Controllers:**
  - a list controller (the 29A/28 paging, refresh and stale-response rules; no-profile per R-13; grouping by `work_date` across pages);
  - a form controller (create/edit; confirmed single-flight save; `422` field errors mapped to the form's fields, with `task_id`/`project_id`/`staff_id` errors shown at the top of the form; offline keeps the entered values);
  - a picker source (open assigned tasks + my non-closed projects, per R-14);
  - a session-wide change record (as `TaskChanges`), so the list refreshes after a save or delete made elsewhere, e.g. from a task's detail.

### 6.5 Mobile UI (Gate 3)

- **My work logs** (`/more/work-logs`):
  - date group headers ("Today", "Yesterday", then "Wed 7 Oct"; judged against `meta.company_day`), newest first;
  - each row: the task title, or the project name when there's no task; the project under a task; the duration ("1 h 30 min", "45 min"); the description (2 lines);
  - an **Add** button; paging, pull-to-refresh, the retry row;
  - empty state "No work logged yet."; no-profile state "No staff profile is linked to this account.";
  - tapping a row opens it for editing.
- **Form** (`/more/work-logs/new`, `/more/work-logs/:publicId`, and from a task `/tasks/:publicId/log-work`):
  - **What for** (picker, R-14; read-only when editing or when opened from a task);
  - **Date** (date picker, R-17; default the company today);
  - **Duration** (hours + minutes);
  - **Description** (multi-line, with a 2000-character counter);
  - **Save** (disabled while saving; a progress indicator); on success a `SnackBar` ("Work logged." / "Changes saved.") and back;
  - **Delete** (edit only, with confirmation, R-18).
- **Task detail:** a **"Log work"** button (not for cancelled tasks, R-15).
- **More:** a third row, **"My work logs"** (R-8 of Phase 28: each phase adds its own rows).

### 6.6 Data / authorization rules (29B)

- Unchanged API authority: eligibility at creation, ownership (`404` for others' logs), field rules and immutability of task/project.
- The app's restrictions (no closed projects in the picker, no logging against cancelled tasks, the 365-day picker range) are **presentation choices**, recorded as such, like Phase 28's R-1 and 29A's R-4.
- **Known risk, accepted:** if the connection drops after the server saved a log but before the app received the reply, the app reports a failure and a retry creates a second log. There's no idempotency key in V1; the duplicate can be deleted. Recorded in the handoff.

### 6.7 29B UAT scenarios (to be added to `UAT_LOG.md` at implementation; all `NOT RUN`)

| ID | Scenario |
|---|---|
| UAT-29B-01 | More → My work logs lists my logs grouped by date, newest first, with durations and the task or project; paging and pull-to-refresh work. A user without a profile sees the no-profile state. |
| UAT-29B-02 | Add a log from the list against one of my projects: the picker shows my open tasks and my non-closed projects only; the date defaults to the company today; the log appears under "Today". |
| UAT-29B-03 | From a task's detail, "Log work" opens the form with that task fixed; the saved log shows the task and its project. A cancelled task offers no "Log work". |
| UAT-29B-04 | Validation: a future date can't be picked; 0 minutes or more than 24 hours is refused; an empty description is refused; a server `422` (for example, no longer a project member) is shown on the form. |
| UAT-29B-05 | Edit my log's date, duration and description (what-for is read-only); leaving with unsaved changes asks to discard; delete asks for confirmation and removes it. |
| UAT-29B-06 | Company-time "today" (R-8): logging for today succeeds between 00:00 and 07:59 Manila. If UAT can't be run in that window, the runbook's verification stage checks it on staging by running the real validation with the clock set to 00:30 Manila (no record saved). |
| UAT-29B-07 | Offline: saving fails clearly and keeps what was typed; it saves after reconnecting. After token revocation the app returns to Login. |
| UAT-29B-08 | Dark mode and large text on the list and the form. |

### 6.8 Definition of Done (29B)

- §6.3–§6.5 implemented; backend tests, Flutter tests and all `CLAUDE.md` §5 commands pass locally and in CI.
- No new dependency (the date picker is Flutter's own `showDatePicker`).
- Docs: DEC-055 (the approved R-10…R-18), `02_ARCHITECTURE` (a work-logs part of §35), `04`/`05` (the `meta.company_day` addition and the "today" rule), `06` (form conventions as built), the handoff `V1_PHASE_29B_HANDOFF.md`; UAT-29B-01…08 recorded `NOT RUN`.
- Merged only with product-owner approval; then a UAT29B runbook, staging deployment and UAT; then formal closure. The session **stops** after each step (`CLAUDE.md` §8).

### 6.9 Implementation sequence for 29B (once authorized; gated like 29A)

1. **Gate 1, backend:** R-10 (all four requests) with the 00:30 Manila regression, R-11 tie-breaker, R-12 `meta.company_day`, and their tests.
2. **Gate 2, mobile data and state:** models, `WorkLogsApiClient`, list/form/picker controllers, the change record.
3. **Gate 3, mobile UI:** the list, the form, "Log work" on task detail, the More row, routes.
4. **Gate 4:** integration review (including real-API contract parity), docs, DEC entry, UAT rows, handoff, PR. Not merged without approval.

## 7. Sub-phase 29C — Projects & Clients (revision 4: specified in full; R-19…R-27 approved 2026-10-11)

*Revision 4 (2026-10-11) replaces the outline with a full specification, after read-only discovery on `main` at `5c7b40c` (29A and 29B closed). The product owner approved R-19…R-27 as written (2026-10-11). Implementation needs its own authorization, gate by gate (`CLAUDE.md` §1/§8). 29C stays **read-only**: no create, edit or membership change from the app.*

*Revision 2's outline, kept for reference:* My projects under More → "Projects" (member projects for Staff; Managers/Administrators to be decided), with code, name, status, client, dates and `my_role`, and a detail with members (linking to the Staff directory) and milestones; Clients under More → "Clients" (company-wide, with search; detail with contact details, Copy as in Phase 28 R-3, and contacts); a task's project becomes a link; no backend change beyond optional tie-breakers.

### 7.1 Current-state findings (read-only discovery)

**The API already serves every read 29C needs:**

| Endpoint | Visibility (existing) | Filters | Order | Notes |
|---|---|---|---|---|
| `GET /projects` (Phase 10) | `projects.view` (Manager; Administrator via `Gate::before`) sees **all**; otherwise only **member** projects; no linked Staff and no `projects.view` → `403` | `status` (one value), `client`, `member`, `q` (name, code) | `name` | `ProjectResource`: code, name, description, status, `start_date`, `target_end_date`, `completed_date`, `client {public_id, name}`, `members_count`, `my_role`, `notes` |
| `GET /projects/{id}` | same rule; not visible → `403` "You do not have access to view this project." | — | — | same resource |
| `GET /projects/{id}/members` | same as the project | `role` | `created_at` | `staff {public_id, employee_number, display_name}`, `role` (`project_lead`, `member`) |
| `GET /projects/{id}/milestones` (Phase 17) | same as the project | `status` | `due_date` | `title`, `due_date`, `status` (`pending`, `completed`, `cancelled`) |
| `GET /clients`, `/clients/{id}` (Phase 8) | `clients.view`: Administrator, Manager **and Staff**, company-wide; **no linked Staff needed** | `status` (`active`, `inactive`), `q` (name, code) | `name` | code, name, status, email, phone, website, address fields, `notes`, `contacts_count` |
| `GET /contacts?client=` | `clients.view` | `client`, `status`, `is_primary`, `q` | `last_name, first_name` | name, job title, email, phone, `is_primary`, status, `notes` |
| `GET /staff/{id}` (Phase 7/28) | `staff.view` (all three roles); **any** status | — | — | the Staff directory detail screen already shows it |

**Gaps and details found:**
1. **No unique tie-breaker on any of the five lists** the app would page through: projects and clients by `name` (names aren't unique), contacts by `last_name, first_name`, members by `created_at`, milestones by `due_date`. The Phase 28 R-6 / 29A R-9 / 29B R-11 paging problem.
2. **Project scope for Managers and Administrators:** without `member=`, `GET /projects` returns **every** project to them. The R-2-style choice is open (R-20).
3. **`notes` fields** on projects, clients and contacts are exposed by the API to every reader. They read as internal remarks for the Administrator Backoffice; the app doesn't have to show them (R-24).
4. **A task's project can be invisible to its assignee:** an assignee needn't be a member of the task's project (Phase 11). Opening that project from the task then gets a `403` (R-25).
5. **Inactive clients and contacts:** the lists include them unless `status=active` is passed. A project can still point at an inactive client.
6. **No company day** on the project endpoints, so the app can't label milestones "overdue" without the device clock (DEC-052). R-23 avoids it.
7. **Carried forward, not touched:** `per_page` is uncapped and `q` wildcards are unescaped on these legacy endpoints (Phase 36); `ProjectResource.my_role` runs one query per row (bounded by the page size).

**Mobile:** the Phase 28 paging, Copy (R-3) and detail patterns, and the Staff directory detail route `/more/directory/:publicId`, are reusable. The task detail shows the project name as plain text. More has three rows (My profile, Staff directory, My work logs).

### 7.2 Product decisions for 29C (approved as written, 2026-10-11)

| # | Decision | Why | Not chosen |
|---|---|---|---|
| **R-19** | **Tie-breakers** (`->orderBy('id')` appended; visible order unchanged) on `GET /projects`, `/clients`, `/contacts`, `/projects/{id}/members` and `/projects/{id}/milestones`, each with a regression test. | Stable paging, as everywhere else in Phase 28/29. | Leave them. |
| **R-20** | **Projects shows only projects I'm a member of, for every role** (`member=<my staff public_id>`), like R-2 for tasks. No "all projects" view in the app. Without a linked Staff record: "No staff profile is linked to this account." | Self-scoped and the same for everyone; company-wide project browsing stays in the Administrator Backoffice. | Managers/Administrators see all projects (the API default for them). |
| **R-21** | **Projects list:** one list in name order, each row with name, code, client, a **status chip** and "Project lead" when `my_role` is lead; **search** (`q`, name or code); Phase 28 paging (25 per page). No status filter. | Small lists; the chip shows closed projects without hiding anything. | Open/closed segments (needs several queries or a new API parameter). |
| **R-22** | **Project detail:** name, code, status, client (a link to the client), start / target end / completed dates, my role, description, **members** (lead(s) first, then members, in server order; each a link to the Staff directory detail) and **milestones** (date, title, status chip). Members and milestones each load **one page of 50**; beyond that, "Showing 50 of N". | Projects are small; one page keeps the detail simple and bounded. | Paging inside the detail. |
| **R-23** | **Milestones show their date and status only — no "overdue" label.** | The project endpoints have no company day; the device clock isn't used for business dates (DEC-052). | Adding `company_day` to the milestone endpoint (a contract change for a label). |
| **R-24** | **The app doesn't show `notes`** on projects, clients or contacts; it shows a project's `description`. Recorded as a presentation choice, **not** a security boundary (the API still returns notes to these readers). | Notes read as internal Backoffice remarks. | Showing them. |
| **R-25** | **Links:** a task's project (task detail) and a project's client open their detail screens; member names open the Staff directory detail. A `403` on a project opened from a task shows "You don't have access to this project." (no sign-out, the 401/403 rule unchanged). | Navigation where the data already is; the `403` case is real (finding 4). | Showing the link only when membership is known (needs an extra call per task). |
| **R-26** | **Clients:** More → "Clients", **active clients only** (`status=active`), with search and paging; the detail opens for **any** client (a project's client may be inactive, shown with an "Inactive" chip). Detail: code, name, email, phone, website and address, each with **Copy** (Phase 28 R-3; no `url_launcher`), then the client's **active contacts** (name, job title, "Primary" badge, email and phone with Copy), one page of 50. Clients need no Staff record (company-wide `clients.view`). | Mirrors the active-only Staff directory; no new dependency. | All clients in the list; tap-to-call/email. |
| **R-27** | **More** gains two rows, in order: My profile, Staff directory, My work logs, **Projects**, **Clients**. No Home change. | Each phase adds its own rows (R-8). | A separate tab. |

**Kept from 29A/29B:** no new permission; no automatic retries; the Phase 27 401/403 session rule; `per_page` 25 for lists.

### 7.3 Backend (Gate 1)

- R-19: append `->orderBy('id')` in `ProjectController::index`, `ClientController::index`, `ContactController::index`, `ProjectMembershipController::index` and `ProjectMilestoneController::index`. One regression test each (equal sort keys, two pages, no repeats or gaps).
- Nothing else changes: no new endpoint, parameter, field, permission or migration.

### 7.4 Mobile data and state (Gate 2) — `lib/features/projects/`, `lib/features/clients/`

- Strict models: `ProjectSummary`/`ProjectDetail` (status enum, dates as `YYYY-MM-DD` strings, nullable client), `ProjectMember`, `ProjectMilestone`, `ClientSummary`/`ClientDetail`, `ClientContact`. Unknown enum values fail parsing (as in 29A).
- `ProjectsApiClient`: my projects (`/me/profile` for the own staff public id, then `/projects?member=…&q=…`, 25 per page), project, members (50), milestones (50). `ClientsApiClient`: active clients (`status=active`, `q`, 25 per page), client, active contacts (`client=…&status=active`, 50).
- Controllers in the Phase 28 style: paged list controllers with search (debounced, stale responses ignored), detail controllers loading the record and its sub-lists; no-profile, `403` and `404` states.

### 7.5 Mobile UI (Gate 3)

- `ProjectsPage` (`/more/projects`), `ProjectDetailPage` (`/more/projects/:publicId`), `ClientsPage` (`/more/clients`), `ClientDetailPage` (`/more/clients/:publicId`); routes from a task (`/tasks/:publicId/project/:projectPublicId` or a push of the More route — settled at Gate 3, keeping back navigation inside the current tab).
- More rows (R-27); task detail project link (R-25).
- Phase 28 widgets reused (section headers, info rows with Copy, error view, padding); status chips styled like the Tasks status chips.

### 7.6 Data / authorization rules (29C)

- No new permission. **Projects are self-scoped in the app for every role (R-20)**; the API's visibility rule stays authoritative (a non-member's `403`).
- Clients and contacts are company-wide for every role, as the API already allows.
- Hiding notes (R-24) and inactive clients/contacts in lists (R-26) are presentation choices, recorded as such.

### 7.7 29C UAT scenarios (to be added to `UAT_LOG.md` at implementation; all `NOT RUN`)

| ID | Scenario |
|---|---|
| UAT-29C-01 | More → Projects lists only projects I'm a member of, in name order, with status chips, client and "Project lead" where it applies; search and paging work. A user without a profile sees the no-profile state. |
| UAT-29C-02 | A project's detail shows code, status, client, dates, my role, description, members (leads first) and milestones with their status; no notes. |
| UAT-29C-03 | From a project: a member opens their Staff directory entry; the client opens the client detail. From a task: its project opens; a project I'm not a member of shows "You don't have access to this project." |
| UAT-29C-04 | More → Clients lists active clients only, with search and paging. |
| UAT-29C-05 | A client's detail shows its contact details with Copy, and its active contacts with "Primary" marked; an inactive client opened from a project shows "Inactive". |
| UAT-29C-06 | As a Manager and as an Administrator, Projects still lists only my member projects (R-20). |
| UAT-29C-07 | Offline, lists and details fail clearly with a retry; after token revocation the app returns to Login. |
| UAT-29C-08 | Dark mode and large text on the four screens. |

### 7.8 Definition of Done (29C)

- §7.3–§7.5 implemented; backend and Flutter tests and every `CLAUDE.md` §5 command pass locally and in CI; no new dependency.
- Docs (CURRENT_STATE, CHANGELOG, TEST_STATUS, UAT_LOG, `02_ARCHITECTURE`, `06_UI_UX_GUIDELINES`, a DEC entry for the approved R-x, `ROADMAP` for the end of Phase 29) and `docs/handoffs/V1_PHASE_29C_HANDOFF.md`; UAT-29C-01…08 recorded `NOT RUN`.
- Merged only with product-owner approval; then staging, a UAT29C runbook, UAT and formal closure — which also closes **Phase 29** as a whole.

### 7.9 Implementation sequence for 29C (once authorized; gated like 29A/29B)

1. **Gate 1, backend:** the five tie-breakers and their tests.
2. **Gate 2, mobile data and state:** models, API clients, controllers and their tests.
3. **Gate 3, mobile UI:** the four screens, routes, More rows and the task-detail link.
4. **Gate 4:** integration review, docs, DEC entry, UAT rows, handoff, PR. Do not merge without approval.

## 8. Data / Authorization Rules (summary, all sub-phases)

- **No new permission. No role widens self-scoped surfaces:** `/me/tasks` and `/me/work-logs` are always the person's own.
- **Existing API rules stay authoritative:** the assignee status-only update, work-log eligibility, project membership visibility, and company-wide client visibility. The app's restrictions (R-4, R-5) are presentation choices, recorded as such (as with Phase 28's R-1).

## 9. Out of Scope (whole phase)

- Creating or editing tasks other than status.
- Task comments and attachments (Phase 33 owns attachments).
- Time tracking with timers.
- Editing projects or clients, and managing project membership or milestones.
- Admin Backoffice screens; offline storage or sync; push notifications.

## 10. Risks / Dependencies

- **First writes from the app:** the 401 and session rule must hold for writes as it does for reads (§5.2 tests). There are no automatic retries, so nothing is applied twice.
- **R-4 is app-side only:** the API still accepts `cancelled` from an assignee. That is acceptable (any API user could do it today), and is recorded.
- **Staging data:** UAT will need a dedicated UAT29 seed (assigned tasks across states and due dates, a project, an independent task), in the Phase 28 runbook style (a `0600` credentials file, shredded, never pasted).
- **Carry-forwards, not touched:** uncapped `per_page` and `q` wildcards on legacy endpoints (Phase 36); offline-launch sign-out (Phase 36); Android debug signing and the label (Phase 38).

## 11. Documentation / Handoff Requirements (per sub-phase)

- `CURRENT_STATE`, `CHANGELOG`, `TEST_STATUS`, `UAT_LOG`.
- `02_ARCHITECTURE` (a Work module section, and `ApiClient` writes).
- `04_API_CONVENTIONS` (`/me/tasks`).
- `05_SECURITY_MODEL` (self-scope; app-side restrictions).
- `06_UI_UX_GUIDELINES`: the first form and write conventions, i.e. confirmed saves, field errors and destructive confirmations.
- A DEC entry for the approved R-x outcomes.
- A handoff per sub-phase (`V1_PHASE_29A_HANDOFF.md`, …).
- `ROADMAP` at the end of 29C.

## 12. Definition of Done (29A)

- §5 is implemented: backend tests, Flutter tests and all `CLAUDE.md` §5 commands pass locally and in CI.
- No new dependency.
- Documentation and the handoff are complete; UAT-29A-01…07 are recorded `NOT RUN`.
- Merged only with product-owner approval; then staging deployment and UAT.
- The session then **stops** (`CLAUDE.md` §8). 29B does not begin without authorization.

## Proposed Implementation Sequence for 29A (once authorized; gated like Phases 27/28)

1. **Gate 1, backend:** `/me/tasks` plus tests; the `/tasks` tie-breaker plus a regression test.
2. **Gate 2, mobile write foundation and Tasks data and state:** `ApiClient` writes and `422`; the Tasks models, API client and controllers.
3. **Gate 3, mobile UI:** the Tasks list and detail, the status control, Home navigation (R-6) and the router.
4. **Gate 4:** integration review, docs, DEC entry, UAT rows, handoff, PR. Do not merge without approval.

## Notes

- **This document is a specification, not an authorization.**
- Revision 1 (2026-10-09) proposed R-1…R-9 as open recommendations; revision 2 records them as approved without change. Elsewhere, only this status line and this note changed.
- **29A Gate 1 implementation notes (2026-10-09):**
  - The controller is `App\Http\Controllers\Api\V1\Tasks\MyTaskController` (the §5.1 placement choice), route `me.tasks.index`.
  - **Refinement of the closed order:** `completed_at DESC` (nulls last), then **`updated_at DESC`**, then `id`. A cancelled task has no `completed_at`, so without `updated_at` all cancelled tasks would sit after the completed ones in creation order. With it they are most recently changed first. Still total and stable; recorded in DEC-054.
  - Unknown query parameters (for example `assignee`) are ignored and can't change the subject; `page` must be a positive integer.
- **29A Gate 2 implementation notes (2026-10-09):**
  - `ApiClient` writes: `postJson`/`patchJson` return the decoded body, or `null` for a `204`; `delete` accepts any 2xx; `getJson` still requires a JSON body. `ApiValidationException` is a subtype of `ApiRequestException` (status 422), so existing generic handlers keep working.
  - **Detail due state without the device clock:** `GET`/`PATCH /tasks/{id}` carry no `is_overdue`/`is_due_today`. The detail keeps the list item's server flags while they still hold, and otherwise compares the due date with a **server-reported company date** handed over by the screen that opened it (the list's or Home's `company_day.date`), by the server's exact rule. With neither, the due state is shown as unknown rather than guessed.
  - **After a change:** a session-wide `TaskChanges` record of confirmed saves. Each list applies a change at once (a task moving between Open and Done leaves its segment) and marks itself stale so it refreshes when next shown; Home is wired to it in Gate 3.
  - **`403` on save:** the server's message is shown, the task reloaded, the status control locked for that screen, and the task recorded as no longer mine (removed from the lists).
- **29A Gate 3 implementation notes (2026-10-09):**
  - **Status control:** a wrapping row of four choice chips (To do, In progress, Blocked, Completed), rather than a segmented button, so it never clips at large text. While a save runs the chips are disabled under a progress bar; the selected chip moves only on the server's confirmation, then a "Status changed to …" notice appears. Failures show under the control.
  - **Refresh after a change:** rather than detecting "shown again", the Tasks lists and Home refresh quietly in the background as soon as a confirmed change is recorded, so they are already current when shown. Done loads on first selection.
  - **Home → task:** the My tasks tile goes to `/tasks`; a Today task row goes to `/tasks/:publicId` with Home's `company_day.date`, switching to the Tasks branch (back returns to the Tasks list).
  - **Known limitation:** "Completed" on the detail is shown in the device's timezone, because `/me/tasks` and `/tasks/{id}` give the company timezone's name but no offset. Due labels are unaffected (they use the server's company date).
  - The People screens' shared widgets (error view, info rows, section headers, padding) are reused rather than copied.
- **Revision 3 (2026-10-10):** §6 (29B) rewritten from an outline into a full specification after read-only discovery on `main` at `c6c6812`: findings (§6.1, including the R-8 defect confirmed in all four work-log requests, a missing `/me/work-logs` tie-breaker, no company day in the list, and the `403` without a profile), proposed decisions R-10…R-18 (§6.2), backend, mobile, rules, UAT-29B-01…08, Definition of Done and gate sequence. No code, test, route or configuration was changed. Elsewhere only this note and the status line changed.
- **Revision 3 approval (2026-10-10):** the product owner approved R-10…R-18 as written. Only the status line, the §6 heading and intro, the §6.2 and §6.9 headings, the §6.2 column labels and this note changed.
- **29B Gate 2 implementation notes (2026-10-10):**
  - `lib/features/work_logs/`: `domain/work_log.dart` (`WorkLog`, `MyWorkLogsPage` with `companyDay`, `WorkTarget` = `TaskTarget` | `ProjectTarget`, `MemberProject`, `WorkLogLimits`, `formatDuration`), `data/work_logs_api_client.dart`, and `state/` (`MyWorkLogsController`, `WorkLogFormController`, `WorkLogChanges`).
  - **The picker source lives in the form controller** rather than a separate class: it reads my open tasks through the 29A `TasksApiClient` (all pages) and my projects through `GET /me/profile` (own staff public id) then `GET /projects?member=…` (all pages), hiding completed and cancelled projects. At most 10 pages of each are read.
  - **A form opened without a company day** reads it from `GET /me/work-logs?per_page=1` (R-12), which also detects the no-profile `403` (R-13).
  - **Form checks before sending** mirror the API (R-17): target, date between the 365-day floor (or an edited log's own earlier date) and the company today, 1 minute–24 hours with minutes 0–59, and a trimmed description of 1–2000 characters. Server `422`s for `work_date`, `duration_minutes` and `description` land on those fields; `task_id`/`project_id`/`staff_id` at the top.
  - A `404` on update or delete means the log is gone: delete counts it as done, update says "This work log is no longer available."; both record the removal so the list drops it.
- **29B Gate 3 implementation notes (2026-10-10):**
  - **Routes:** `/more/work-logs` (list), `/more/work-logs/new` (registered before `/more/work-logs/:publicId`, the edit form), and `/tasks/:publicId/log-work` (the same form, in the Tasks branch above the task). The route `extra` (`WorkLogFormArgs`) carries the log, the fixed task and the company day; without it the form loads the company day itself.
  - **The form** uses a tappable "What was this for?" field that opens a bottom sheet (My open tasks, then My projects), a date field opening Flutter's `showDatePicker` limited to the R-17 range, two number fields (Hours, Minutes; digits only, two characters), and a multi-line description with a 2000-character counter. The SDK's `FilteringTextInputFormatter` is used; no dependency was added.
  - **Leaving:** `PopScope` asks "Discard changes?" (Keep editing / Discard) only when something changed; saving or deleting leaves without asking. Delete asks "Delete this work log?" (Cancel / Delete, in the error colour).
  - **More:** a third row, "My work logs". The Phase 28 test that More has exactly two rows was updated to three, as Phase 28's R-8 anticipated.
- **Revision 4 (2026-10-11):** §7 (29C) rewritten from an outline into a full specification after read-only discovery on `main` at `5c7b40c`: findings (§7.1: no tie-breakers on the five lists, project scope for Managers/Administrators, exposed notes, a task's project possibly invisible to its assignee, inactive clients, no company day on milestones), proposed decisions R-19…R-27 (§7.2), backend, mobile, rules, UAT-29C-01…08, Definition of Done and gate sequence. No code, test, route or configuration was changed. Elsewhere only this note and the status line changed.
- **Revision 4 approval (2026-10-11):** the product owner approved R-19…R-27 as written. Only the status line, the §7 heading and intro, the §7.2 heading and column label and this note changed.
