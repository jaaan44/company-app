# Phase 29 — Work: Tasks, Work Logs, Projects & Clients (Mobile) — Specification

**Status:** Revision 2, approved (R-1…R-9 as written, 2026-10-09; merged via PR #60). **29A AUTHORIZED — IN PROGRESS: Gate 1 (backend) and Gate 2 (mobile write foundation, Tasks data and state) implemented** (2026-10-09). Gates 3–4 of 29A, and 29B/29C, each need their own explicit authorization (`CLAUDE.md` §1/§8).

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

## 6. Sub-phase 29B — Work logs (outline; detailed in a revision before it starts)

- **Screens:**
  - **My work logs:** More → "My work logs", grouped by date, newest first, paged.
  - **Add:** from a task's detail ("Log work", task pre-filled) or from the list (choose one of my projects, or one of my independent assigned tasks).
  - **Edit and delete:** for my own logs; date, duration and description only, matching the API. Deleting asks for confirmation.
- **Form:** date (default company today, never later), duration in hours and minutes (1 minute to 24 hours), description (≤ 2000 characters). Server `422`s appear per field.
- **Backend:** the R-8 company-timezone "today" fix, and a project/task picker source (likely `GET /me/tasks` plus `GET /projects`, membership-scoped).
- UAT-29B-xx defined at that revision.

## 7. Sub-phase 29C — Projects & Clients (outline; read-only)

- **My projects:** More → "Projects". It shows member projects for Staff; the R-2-style choice of whether Managers and Administrators also see all is to be decided at its revision. Each has code, name, status, client, dates and `my_role`. The detail page has members (names linking to the Staff directory entry) and milestones.
- **Clients:** More → "Clients". A company-wide list with search, and a detail page with contact details (Copy, as in Phase 28 R-3) and contacts.
- **Links:** a task's project becomes a link.
- No backend change is expected beyond optional tie-breakers. UAT-29C-xx is defined at that revision.

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
