# Phase 30 — Operations: Schedule & Check-in (Mobile) — Specification

**Status:** Revision 1 (2026-10-12), **approved: R-1…R-12 approved as written by the product owner (2026-10-12).** Implementation needs its own authorization, sub-phase by sub-phase and gate by gate (`CLAUDE.md` §1/§8); 30B also needs its own detailed revision first. **30A implemented (Gates 1–4, 2026-10-12); PR open, not merged** — see `docs/handoffs/V1_PHASE_30A_HANDOFF.md`.

*Previous status line (kept as written):* DRAFT, revision 1 (2026-10-12). Proposed decisions R-1…R-12 await the product owner's approval. **Not authorized for implementation**; each sub-phase's gates need explicit authorization (`CLAUDE.md` §1/§8). No application, test, migration, route, dependency or configuration change has been made.

**Depends on:**
- Phase 25: the shell, with a `Schedule` tab placeholder.
- Phase 27: the `ApiClient` and its 401/403 rule; Home's Today section (DEC-052: own Schedule Entries plus own open tasks due today, in company time); R-7's deferred `/schedule` day-boundary item.
- Phase 28/29: the More menu, paged-list and detail conventions, the 29A task detail (`/tasks/:publicId`), `PagedSearchController`/`PagedSearchList`.

It reads data owned by Phase 9 (Operational Status & Check-in, DEC-032), Phase 11 (Tasks), Phase 13 (Leave), Phase 17 (Scheduler, DEC-040) and Phase 10/17 (Projects, Milestones).

**Supersedes:** nothing. It turns `docs/ROADMAP.md`'s Phase 30 line ("the Schedule tab and Location/Check-in") into a concrete plan.

---

## 1. Objective

Let an employee see what's on their plate day by day — their own appointments, tasks due and approved leave — in the Schedule tab, and, in a second step, tell the company where they are and what they're doing (operational status and explicit check-ins), without ever becoming continuous tracking (DEC-005).

## 2. Starting Baseline

- `main` at `2d8ae92` (PR #71, Phase 29 closure). Phases 1–29 are merged and closed.
- Staging runs `c79ed90` (Phase 29C).
- **Mobile:** the `Schedule` tab is `SchedulePlaceholderPage` ("Schedule — coming soon"); dependencies are `http`, `flutter_secure_storage`, `go_router` (unchanged since Phase 25); the Android manifest declares only `INTERNET`.

## 3. Current-State Findings (read-only repository discovery)

### 3.1 Schedule (Phase 17)

| Endpoint | What it does | Who sees what |
|---|---|---|
| `GET /schedule?from&to` (required) | One list aggregating four sources: Schedule Entries, Tasks with a due date, approved Leave, Project Milestones; optional `source`, `activity_type`, `project`; paginated in memory (`per_page` default 50, uncapped) | **Visibility-scoped, not self-scoped:** each item is included if the requester could see it through its own module — so a Manager (`tasks.view`, `projects.view`) gets **every** dated task and milestone in the company, an Administrator every entry and leave, a Staff member their own plus their projects' |
| `GET/POST/PATCH/DELETE /schedule-entries` | Schedule Entry CRUD | Private by default: creator, participants, and (if Project-linked) whoever can see the Project; someone else's entry is `404`; edit by creator, Project Lead or Administrator |

**Item shape** (`ScheduleItemResource`): `source_type` (`schedule_entry`, `task`, `leave`, `project_milestone`), `public_id`, `title`, `description`, `activity_type` (entries only: meeting, client_visit, service_appointment, company_event, training, internal_activity, other), `status`, `starts_at`/`ends_at` (UTC ISO-8601), `is_all_day`, `project {public_id, name}`. Tasks, leave and milestones are all-day items projected onto the company day (`CompanyTimezone::startOfDayUtc`).

**Gaps and defects found:**
1. **UTC day boundaries (Phase 27 R-7, confirmed).** `from`/`to` become `startOfDay()`/`endOfDay()` in the process timezone (UTC) and entries are filtered with `whereDate(starts_at/ends_at)` on UTC instants. With `Asia/Manila`, a meeting from 07:00 to 07:30 Manila (23:00–23:30 UTC the day before) is **missing** from its Manila day's query and **appears** in the previous day's; any item between 00:00 and 07:59 Manila is affected the same way. Home's Today already uses company-day boundaries, so the two would disagree.
2. **Not self-scoped:** a Schedule tab built on `/schedule` would show a Manager everyone's tasks and milestones (the same problem Phase 29 R-2/R-20 solved for Tasks and Projects).
3. **No unique order:** sorted by `starts_at` only; equal starts (every all-day item of a day) have no defined order, so pages can repeat or skip items.
4. **Unbounded work per request:** each source is loaded in full for the range, filtered row by row in PHP (one participant query per entry — no eager load), then sliced. A long range is slow; nothing caps it.
5. **No company day in the response:** a client can't label "Today" or lay out days without the device clock (DEC-052).
6. **No linked Staff record:** a Staff user without a profile simply sees nothing, without being told why; `/schedule-entries` answers `403`.

### 3.2 Operational status and check-in (Phase 9, DEC-032)

- `GET/POST /me/status`: append-only history of `available`, `busy`, `in_meeting`, `in_field`, `off_duty`; "current" is the latest row. `GET /staff/{id}/status` for colleagues (`staff-status.view`: all roles).
- `GET/POST /me/check-ins`: append-only; **`latitude` and `longitude` are required** (`accuracy_meters`, `location_label` ≤ 100, `note` ≤ 500 optional). Viewing someone else's check-ins needs `location.view` (Manager, direct reports only) or Administrator.
- Both history lists order by `created_at DESC` only (`latest()`): no tie-breaker; `per_page` default 20, uncapped.
- `StaffResource` already exposes `operational_status` (not shown by the Phase 28 directory, R-5).
- **Policy (DEC-005, `05_SECURITY_MODEL.md` "Location Data"):** explicit check-ins only, never background or continuous collection; a check-in is not attendance, and its absence is not absence.

### 3.3 Mobile

- No location capability: no geolocation package, no `ACCESS_FINE_LOCATION`/`ACCESS_COARSE_LOCATION` in the manifest. **A check-in from the app needs a new dependency and a runtime permission** — the first dependency since Phase 25.
- Home's Today rows for **tasks** link to the task (29A, R-6); Today rows for **schedule entries** are not interactive ("until their screens exist").

## 4. Product Decisions (approved as written, 2026-10-12)

| # | Decision | Why | Not chosen |
|---|---|---|---|
| **R-1** | **Split into two sub-phases**, each with its own gates, PR, staging deploy and UAT: **30A Schedule** (specified in full in §5) and **30B Status & Check-in** (outlined in §6; specified in its own revision before it starts). | Schedule is self-contained and needs no new dependency; check-in brings the first new dependency and a location permission, which deserve their own review. | One phase. |
| **R-2** | **A new self-scoped `GET /api/v1/me/schedule?from=YYYY-MM-DD&to=YYYY-MM-DD`** returning only **my** items: Schedule Entries I created or participate in, **my open assigned tasks** with a due date in the range, and **my approved leave**. Same for every role (no `tasks.view`/`projects.view`/Administrator widening). | One personal agenda, the DEC-052 "Today" definition extended to a range; the 29A/29C self-scope rule. | Reusing `/schedule` (visibility-scoped; a Manager would see the company). |
| **R-3** | **No milestones in My schedule.** | They're project-level, not personal; they're one tap away in Projects (29C). | Include milestones of my member projects. |
| **R-4** | **Company-day boundaries:** `from`/`to` are company dates; an item belongs to the range if it overlaps `[startOfDayUtc(from), endOfDayUtc(to)]` (as Home's Today does). The response adds `meta.company_day {date, timezone, utc_offset}`. | DEC-052; Home and Schedule agree at every hour. | Device-clock days. |
| **R-5** | **Also fix `GET /schedule`'s range** to the same company-day boundaries (Phase 27 R-7), with a regression test at 07:00 and 23:30 Manila. Its scope, sources and shape are otherwise unchanged. | Closes the recorded defect where it lives; the Administrator Backoffice and any future client get the same days. | Leave the legacy endpoint as is. |
| **R-6** | **Bounded, unpaginated:** `to − from` ≤ 31 days (`422` otherwise); all items returned in one response in a **total order**: `starts_at`, all-day first, then entries, tasks, leave, then title, then `public_id`. Entries are queried with participants eager-loaded (no per-row query). | A month of one person's agenda is small; no paging edge cases; constant query count. | Paging (the Phase 17 in-memory slicing). |
| **R-7** | **No linked Staff record:** `200` with `data: {company_day, items: null}` (the `/me/tasks` pattern); the app shows "No staff profile is linked to this account." | Consistent with `/me/home` and `/me/tasks`. | `403` (the `/me/work-logs` pattern). |
| **R-8** | **Schedule tab = a week agenda:** seven day sections starting from the company today, each headed "Today", "Tomorrow" or "Wed 14 Oct", listing its items in the R-6 order, with "Nothing scheduled." for an empty day; **Previous week / Next week** and a **Today** button; pull to refresh. Times in company time (Home's `utc_offset` rule); all-day items labelled "All day"; multi-day items appear on each day they cover. | Simple, readable, no calendar dependency; a week fits one 31-day-bounded request. | A month grid; an infinite list. |
| **R-9** | **Read-only in 30A:** no creating or editing schedule entries on mobile. | Scope control; Phase 17's entry editor has participants, projects and time zones — its own UX. | Create personal entries in 30A. |
| **R-10** | **Taps:** a **task** opens the 29A task detail (in the Tasks tab, as Home does); a **schedule entry** opens a new read-only **entry detail** (title, type, time, project, description, creator and participants) from the existing `GET /schedule-entries/{id}` (a `404` reads "This item is no longer available."); **leave** is not tappable (its screens are Phase 31). Home's Today **entry** rows become tappable too. | Uses what exists; Home finally links everything it lists. | Leave detail now. |
| **R-11** | **30B direction (to be specified in its revision):** More → **My status** to see and set my operational status (`/me/status`, no new dependency); and an explicit **Check in** that reads the device location **only when tapped**, with a clear prompt, no background location, and no check-in without the user's action (DEC-005). It needs a location package (for example `geolocator`) and `ACCESS_FINE_LOCATION`/`ACCESS_COARSE_LOCATION`, which the product owner approves at that revision. | Keeps the privacy stance explicit before any code. | Background or periodic location (rejected by DEC-005); dropping check-in from mobile. |
| **R-12** | **Tie-breakers** (`->orderByDesc('id')`) on `/me/status`, `/me/check-ins` and the staff history lists — landing in **30B** Gate 1, where they're used. | Stable paging, as in Phases 28/29. | Leave them. |

## 5. Sub-phase 30A — Schedule (specified in full)

### 5.1 Backend (Gate 1)

- **`GET /api/v1/me/schedule`** (`App\Http\Controllers\Api\V1\Scheduling\MyScheduleController`, route `me.schedule.index`, `auth:sanctum` + `account.active`, no permission):
  - Validates `from`, `to` as `Y-m-d`, `to ≥ from`, `to − from ≤ 30` days (31 days inclusive); unknown parameters are ignored and can't change the subject.
  - Range: `CompanyTimezone::startOfDayUtc(from)` to `endOfDayUtc(to)`; entries overlap it on their stored UTC instants; tasks and leave by their dates.
  - Sources (R-2): entries where I'm the creator or a participant (participants eager-loaded); my assigned tasks not completed or cancelled with `due_date` in `[from, to]`; my approved leave overlapping `[from, to]`.
  - Item shape: the `ScheduleItemResource` fields, plus `task_status` for tasks (as Home), so the client needs no second request.
  - Order (R-6); `meta.company_day {date, timezone, utc_offset}` (R-4); no Staff → `items: null` (R-7).
  - Tests: self-scope for Staff, Manager, Administrator and a Project Lead (none sees another person's items); every source in and out of range at 07:00/23:30 Manila; a multi-day entry; the total order with equal starts; the 31-day limit; no profile; query count constant in the number of items.
- **`GET /schedule` (R-5):** `from`/`to` mapped to company-day UTC bounds and entries compared on instants (not `whereDate`), with the 07:00/23:30 Manila regression test. No other change.
- No migration, permission or new dependency.

### 5.2 Mobile data and state (Gate 2) — `lib/features/schedule/`

- `domain/`: strict `ScheduleItem` (source, times, all-day, task status, activity type, project), `MySchedule` (company day + items or `null`), `ScheduleEntryDetail` (with creator and participants); day-grouping that places multi-day items on each covered company day, using the server's `utc_offset`.
- `data/ScheduleApiClient`: `fetchMySchedule(from, to)`, `fetchEntry(publicId)`.
- `state/`: `MyScheduleController` (week window from the company today; previous/next/today; refresh keeping content; stale responses dropped; no-profile; errors), `ScheduleEntryController` (detail; `404` message).

### 5.3 Mobile UI (Gate 3)

- `SchedulePage` replaces `SchedulePlaceholderPage` at `/schedule` (R-8); `ScheduleEntryPage` at `/schedule/entries/:publicId` (R-10).
- Each row: time (or "All day"), title, a small source label ("Meeting", "Task · To do", "Leave"), and the project name when present.
- Task rows `go` to `/tasks/:publicId` (with the company day, as Home does); Home's Today entry rows `go` to `/schedule/entries/:publicId`.

### 5.4 30A UAT scenarios (added to `UAT_LOG.md` at implementation; all `NOT RUN`)

| ID | Scenario |
|---|---|
| UAT-30A-01 | The Schedule tab shows this week from today: "Today", "Tomorrow", then dated days; my meetings at their Manila times, my open tasks and my approved leave as all-day items; empty days say "Nothing scheduled." |
| UAT-30A-02 | Next week, Previous week and Today move the window; pull to refresh works. |
| UAT-30A-03 | A meeting at 07:00 Manila appears on its Manila day (not the day before), and the same item appears on Home's Today when it's today (R-4/R-5). |
| UAT-30A-04 | A multi-day entry appears on each day it covers; all-day items come before timed ones. |
| UAT-30A-05 | Tapping a task opens its detail in the Tasks tab; tapping a meeting opens its detail (type, time, project, description, people); leave isn't tappable; Home's Today meeting row opens the same detail. |
| UAT-30A-06 | As a Manager and as an Administrator, Schedule shows only my own items, never a colleague's (R-2). A user without a profile sees the no-profile message. |
| UAT-30A-07 | Offline, loading fails clearly with a retry; after token revocation the app returns to Login. |
| UAT-30A-08 | Dark mode and large text on the Schedule tab and the entry detail. |

### 5.5 Definition of Done (30A)

- §5.1–§5.3 implemented; backend and Flutter tests and every `CLAUDE.md` §5 command pass locally and in CI; **no new dependency**.
- Docs (CURRENT_STATE, CHANGELOG, TEST_STATUS, UAT_LOG, `02_ARCHITECTURE`, `04_API_CONVENTIONS`, `05_SECURITY_MODEL`, `06_UI_UX_GUIDELINES`, a DEC entry) and `docs/handoffs/V1_PHASE_30A_HANDOFF.md`; UAT-30A-01…08 recorded `NOT RUN`.
- Merged only with product-owner approval; then a UAT30A runbook, staging and UAT.

### 5.6 Implementation sequence for 30A (once authorized; gated like Phase 29)

1. **Gate 1, backend:** `/me/schedule` and the `/schedule` day-boundary fix, with tests.
2. **Gate 2, mobile data and state.**
3. **Gate 3, mobile UI:** the Schedule tab, the entry detail, routes and Home's entry links.
4. **Gate 4:** integration review, contract parity, docs, DEC entry, UAT rows, handoff, PR. Do not merge without approval.

## 6. Sub-phase 30B — Status & Check-in (outline)

- **My status** (More → My status): the current status and when it was set, a choice of the five statuses (confirmed, not optimistic — the 29A rule), and my recent history. `/me/status` as is, plus R-12's tie-breaker.
- **Check in** (on My status, or its own row): explicit and foreground only — tap, see why the location is needed, grant the permission once, the app reads one position, the user may add a place label and a note, then sends it; a list of my recent check-ins. No background, periodic or silent location, ever (DEC-005). Denied or unavailable location → a clear message and no check-in.
- **Needs approval at its revision:** the location package and its version, the Android permissions and their wording, and whether colleagues' statuses appear anywhere in the app.
- UAT-30B-xx are defined at that revision.

## 7. Data / Authorization Rules (whole phase)

- **No new permission. No role widens a self-scoped surface:** `/me/schedule`, `/me/status` and `/me/check-ins` are always the person's own.
- Existing rules stay authoritative: Schedule Entry privacy (DEC-040; a Manager gets no view of a report's private entries), task and leave ownership, location visibility (DEC-032).
- The R-5 fix changes which **days** `/schedule` reports, not **who** sees what.

## 8. Out of Scope (whole phase)

- Creating or editing schedule entries on mobile (R-9); recurring entries; calendar sync or export.
- Viewing colleagues' schedules or locations on mobile; maps.
- Attendance, timekeeping or geofencing of any kind (`05_SECURITY_MODEL.md`).
- Leave screens (Phase 31); notifications or reminders (Phase 32).

## 9. Risks / Dependencies

- **Timezone correctness** is the core of 30A: every boundary test runs at 07:00 and 23:30 Manila, and the UAT data is dated relative to the company day.
- **R-5 changes a legacy endpoint's day boundaries:** an Administrator Backoffice view asking for a day now gets that Manila day. Recorded in the changelog and API conventions.
- **30B introduces the first new dependency and a sensitive permission** — deliberately isolated in its own sub-phase and revision.
- Carried forward, untouched: `per_page`/`q` hardening on legacy endpoints (Phase 36); the in-memory aggregation inside `/schedule` (unchanged apart from R-5; a Phase 36 candidate); debug signing (Phase 38).

## 10. Documentation / Handoff Requirements (per sub-phase)

`CURRENT_STATE`, `CHANGELOG`, `TEST_STATUS`, `UAT_LOG`; `02_ARCHITECTURE` (a Schedule section); `04_API_CONVENTIONS` (`/me/schedule`, the `/schedule` boundary change); `05_SECURITY_MODEL` (self-scope; for 30B, location capture on mobile); `06_UI_UX_GUIDELINES` (the agenda); a DEC entry for the approved R-x; a handoff per sub-phase; `ROADMAP` at the end of 30B.

## Notes

- **This document is a specification, not an authorization.**
- Revision 1 (2026-10-12): read-only discovery on `main` at `2d8ae92`; findings §3; proposed decisions R-1…R-12 (§4); 30A specified in full (§5); 30B outlined (§6). No code, test, route, dependency or configuration was changed.
- **Revision 1 approval (2026-10-12):** the product owner approved R-1…R-12 as written. Only the status line, the §4 heading and column label and this note changed.
- **30A Gate 1 implementation notes (2026-10-12):**
  - `App\Http\Controllers\Api\V1\Scheduling\MyScheduleController`, route `me.schedule.index` (`GET /api/v1/me/schedule`).
  - **Response shape (R-4 and R-7 reconciled):** `data: {company_day: {date, timezone, utc_offset}, range: {from, to}, items}` — the company day sits in `data`, as R-7's `/me/tasks` pattern says, rather than in `meta` (R-4's wording); there is no `meta` because nothing is paginated. `items` is `null` without a linked Staff record.
  - **Items:** the `ScheduleItemResource` fields (`source_type`, `public_id`, `title`, `description`, `activity_type`, `status`, `starts_at`, `ends_at`, `is_all_day`, `project`) plus `task_status` (Home's field). **Leave is titled with its leave type's name** (for example "Vacation leave") instead of `/schedule`'s generic "Leave": it is the person's own record.
  - **Range:** `from`/`to` must be `Y-m-d`; at most 31 days inclusive ("The range can be at most 31 days." on `to`). Entries overlap `[startOfDayUtc(from), endOfDayUtc(to)]` on their UTC instants; tasks and leave compare their dates with the company dates.
  - **`GET /schedule` (R-5):** `from`/`to` are reduced to their dates, entries compared on instants against those company days' UTC bounds, and the date-only sources (tasks, leave, milestones) compared with the company dates. Before the fix, an entry at 07:00 Manila was missing from its day (shown by the new regression test failing against the old controller); a datetime `from` still means its date.
  - Six data queries per request whatever the number of items (entries, tasks and leave, each with one eager load).
- **30A Gate 2 implementation notes (2026-10-12):**
  - `lib/features/schedule/` — `domain/schedule.dart` (`ScheduleSource`, `ScheduleItem`, `MySchedule`, `ScheduleCompanyDay` with `boundsOf`/`toCompanyTime`, `ScheduleDay` and `groupByDay`, `ScheduleEntryDetail`, `SchedulePerson`, `shiftDate`), `data/schedule_api_client.dart`, `state/my_schedule_controller.dart`, `state/schedule_entry_controller.dart`.
  - **Learning the company week without the device clock (DEC-052):** the first load asks for the device's yesterday to a week after its today (nine days), learns the company today from the response and lays the week out from it; only if that guess doesn't cover the company week (a clock days off) does it make one exact follow-up request. The device date never labels a day. Later weeks are requested exactly (seven days).
  - **Days:** an item belongs to a company day when it overlaps `[start, end)` of that day (end-exclusive, so a meeting until midnight doesn't spill into the next day; a zero-length item at a day's start belongs to it). Multi-day items appear on every day they cover; each day keeps the server's order. The offset at the start of today is used for the whole window (the company timezone has no DST, as on Home).
  - Activity types and task statuses stay strings and are labelled leniently with Home's helpers; the structure (sources, dates, instants, required fields) is parsed strictly.
  - Every load is a new generation; an overtaken response is ignored. A failed refresh keeps the week; a failed week change is the error state ("Try again" reloads the current week — changed at Gate 3: it reloads the week that failed).
- **30A Gate 3 implementation notes (2026-10-12):**
  - `lib/features/schedule/presentation/` — `schedule_page.dart` (`SchedulePage`), `schedule_entry_page.dart` (`ScheduleEntryPage`), `schedule_widgets.dart` (`scheduleEntryPath`, `ScheduleEntryArgs`, the day, week, time and label formatters). `/schedule` builds `SchedulePage`; its child route `entries/:publicId` builds `ScheduleEntryPage`. `SchedulePlaceholderPage` is removed (Messages keeps its placeholder). `CompanyApp` takes an injectable `ScheduleApiClient`.
  - **Navigation:** task rows `go` to `/tasks/:publicId` with the company day (as Home does); entry rows in the Schedule tab `push` the entry detail on top of the week (Back keeps the week and makes no request; `go` would behave the same, which the mutation run confirmed); Home's Today entry rows `go` to `/schedule/entries/:publicId` (the Schedule tab, index 2). Every route carries the company offset in `ScheduleEntryArgs`, so the detail shows company time; a restored deep link with no arguments shows device time and says "(device time)".
  - **Change to Gate 2:** "Try again" now reloads **the week that failed** — `MyScheduleController.retry()` repeats the last full load's week (null = the company today's week). Before, a failed Next week retried the week shown before it, which the new page test exposed. One state test added.
  - Leave rows are plain (no tap, no chevron). Home's Phase 27 non-interactive rule now covers only the greeting, the overflow line, the Messages/Notifications tiles and announcements.
- **30A Gate 4 notes (2026-10-12):**
  - **Integration review finding, fixed:** `SchedulePage` now listens to `TaskChanges`, as Home does, so completing a task opened from the Schedule tab refreshes the week quietly (before, it stayed listed until a pull). One page test; two mutation checks (listener and router wiring) both caught.
  - Contract parity: real Laravel responses replayed through the production app code (see `TEST_STATUS.md`, Phase 30A Gate 4). DEC-057; UAT-30A-01…08 added `NOT RUN`; handoff written.
