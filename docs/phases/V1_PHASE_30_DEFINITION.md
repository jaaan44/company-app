# Phase 30 — Operations: Schedule & Check-in (Mobile) — Specification

**Status:** Revision 2 (2026-10-12), **approved: R-13…R-22 approved as written by the product owner (2026-10-12)** (R-1…R-12 approved with revision 1). 30B implementation needs its own authorization, gate by gate (`CLAUDE.md` §1/§8). **30A complete — formally closed 2026-10-12** (PR #73, `0e7fce6`; UAT-30A-01…08 `PASS`). 30B not started.

*Previous status line (kept as written):* **DRAFT, revision 2 (2026-10-12): §6 specifies 30B — Status & Check-in in full. Proposed decisions R-13…R-22 await the product owner's approval.** 30B is **not authorized for implementation**; each gate needs explicit authorization (`CLAUDE.md` §1/§8). Everything outside §6 (revision 1: R-1…R-12 approved, and the 30A sections) is unchanged, apart from §8's location line and the notes. **30A complete — formally closed 2026-10-12** (PR #73, `0e7fce6`; UAT-30A-01…08 `PASS`).

*Previous status line (kept as written):* Revision 1 (2026-10-12), **approved: R-1…R-12 approved as written by the product owner (2026-10-12).** Implementation needs its own authorization, sub-phase by sub-phase and gate by gate (`CLAUDE.md` §1/§8); 30B also needs its own detailed revision first. **30A complete — formally closed 2026-10-12** (merged PR #73, `0e7fce6`; staging deployed; UAT-30A-01…08 `PASS`) — see `docs/handoffs/V1_PHASE_30A_HANDOFF.md`. 30B not started.

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

## 6. Sub-phase 30B — Status & Check-in (revision 2: specified in full; R-13…R-22 approved 2026-10-12)

*Revision 2 replaces the outline that stood here. That outline's direction (approved as R-11 and R-12) is kept. This section adds what 30B needs before it can be built: what the repository holds today (§6.1), proposed decisions R-13…R-22 (§6.2), and the gates (§6.3–§6.8).*

### 6.1 Current-state findings (read-only discovery on `main` at `b57f2ef`)

**Backend (Phase 9, DEC-032), unchanged since Phase 9:**

| Endpoint | Behaviour today |
|---|---|
| `GET /me/status` | My status history, newest first (`latest()` only: **no tie-breaker**), `per_page` default 20, uncapped. Items are `{status, recorded_at}` (no id; status rows have no `public_id`, DEC-017). The current status is the first item. |
| `POST /me/status` | `{status}` with one of `available`, `busy`, `in_meeting`, `in_field`, `off_duty`; appends a row and returns it (`201`). |
| `GET /me/check-ins` | My check-ins, newest first (`latest()` only: **no tie-breaker**), `per_page` default 20. Items: `public_id`, `latitude`, `longitude`, `accuracy_meters`, `location_label`, `note`, `status`, `checked_in_at`. |
| `POST /me/check-ins` | `latitude` (−90…90) and `longitude` (−180…180) **required**; `accuracy_meters` (0…100,000), `location_label` (≤ 100), `note` (≤ 500) and a `status` snapshot optional. Stored as `decimal(10,7)`. A snapshot does **not** change my operational status. `201`. |
| `GET /staff/{id}/status`, `GET /staff/{id}/check-ins` | Another person's history: status for every role (`staff-status.view`); check-ins for a Manager's **direct reports only** (`location.view` plus a `manager_id` check) and for Administrators. Same missing tie-breaker. |

- **No linked Staff record:** every `/me/status` and `/me/check-ins` call answers `403` "No staff record is linked to this account." This is the `/me/work-logs` pattern (Phase 29B R-13), not the newer `200` with `null`.
- **No company day** in either response, so the app can't show the times in company time without the device clock (DEC-052).
- **Immutable:** nobody can edit or delete their own check-in; only an Administrator can delete one (`location.manage`). **No rate limit** on `POST /me/check-ins` (`05_SECURITY_MODEL.md` "Rate Limiting": none until a need is shown).
- **Policy:** explicit check-ins only, never background or continuous (DEC-005). A check-in is **not** attendance, and its absence is not absence. `off_duty` is not leave (`05_SECURITY_MODEL.md` "Location Data").
- `StaffResource.operational_status` exists, but the Phase 28 directory doesn't show it (Phase 28 R-5). Home doesn't show my status.

**Mobile:**
- No location code. `AndroidManifest.xml` declares only `INTERNET`. Dependencies are `http`, `flutter_secure_storage`, `go_router` and `cupertino_icons`; 30B would add the **first new runtime dependency since Phase 25**.
- An `ios/` folder exists (the Flutter template), but no iOS build has ever been made or tested; every UAT so far ran on Android.
- More has five rows: My profile, Staff directory, My work logs, Projects, Clients.

**The candidate location package, `geolocator` (pub.dev, checked 2026-10-12):**
- Latest is 14.1.1 (2026-09-27), with `geolocator_android` 5.1.1+1 (2026-10-02). Both are **less than three weeks old**. The previous line, 14.0.3 with `geolocator_android` 5.0.3, was published 2026-06-12.
- `geolocator` 14.0.3 only requires `geolocator_android` `^5.0.3`, so pinning `geolocator` alone would still resolve the newest 5.x Android implementation.
- `geolocator_android` (5.0.3 and 5.1.1+1 alike) declares **no permissions**. It adds an inert `GeolocatorLocationService` (`foregroundServiceType="location"`, used only for background streams) to the merged manifest. It depends on `com.google.android.gms:play-services-location`, using the fused provider and falling back to Android's `LocationManager` without Play Services.
- The umbrella package also brings the web, Windows and Linux implementations and their Dart dependencies into `pubspec.lock`. The Android build only links the Android one.

### 6.2 Product decisions (R-13…R-22, approved as written 2026-10-12)

| # | Decision | Why | Not chosen |
|---|---|---|---|
| **R-13** | **One "My status" area in More** (a sixth row, "My status", whose subtitle is my current status). The screen shows: my current status and when I set it; **Change status**; **Check in**; my 5 newest check-ins with **All check-ins**; my 5 newest status changes with **All status changes**. Both "All" pages are paged lists (20 a page, the Phase 28/29 paging rules). | Status and check-in are one "where am I and what am I doing" area. More already holds my personal records, and a tab or Home tile would cost more space than these occasional actions need. | A Home tile; a tab; two separate More rows. |
| **R-14** | **Backend, additive only** (R-12 made concrete): `->orderByDesc('id')` after `latest()` on all four history lists (`/me/status`, `/me/check-ins`, `/staff/{id}/status`, `/staff/{id}/check-ins`). Add `meta.company_day {date, timezone, utc_offset}` to `GET /me/status` and `GET /me/check-ins` (the `/me/work-logs` `meta` pattern of Phase 29B, plus `utc_offset` as `/me/home` and `/me/schedule` give it, since these lists show times, not just dates). No new endpoint, field on items, permission, migration or rate limit. | Stable paging when two rows share a second; company-time display (DEC-052). | Cap `per_page` (Phase 36, with the other legacy lists); a rate limit (no demonstrated need). |
| **R-15** | **No profile:** keep the `403`. As in Phase 29B R-13, a `403` on `GET /me/status` that survives `ApiClient`'s `/auth/me` re-check means "No staff profile is linked to this account." The My status screen then shows only that line: no status, no Check in. | No contract change; the pattern is already built and tested. | Change the API to `200` + `null`. |
| **R-16** | **Status changes are confirmed, never optimistic** (the Phase 29A rule). Change status opens a sheet with the five statuses, each with an icon **and** its label (Available, Busy, In a meeting, In the field, Off duty), the current one marked. Choosing one sends `POST /me/status` with a progress indicator; the screen updates only on `201`. Off duty carries the hint "Not working right now. This isn't leave." | A status others rely on must never show a value the server didn't accept. | Optimistic update; a free-text status. |
| **R-17** | **Location package:** `geolocator` **14.0.3** and `geolocator_android` **5.0.3**, both as **exact** direct dependencies (the Android implementation pinned too, §6.1). Only `getCurrentPosition` is used, plus the permission and service checks and `openAppSettings`/`openLocationSettings`. Never a position stream or the background service. `pubspec.lock` changes are reviewed in the Gate 2 PR. | The most widely used Flutter location package. A version line in use for four months, not one published days ago. Both permission handling and one-shot reads are built in. | 14.1.1 / 5.1.1+1 (under three weeks old); the `location` package; a hand-written platform channel (no dependency, but native permission and position code to maintain). |
| **R-18** | **Android permissions: `ACCESS_COARSE_LOCATION` and `ACCESS_FINE_LOCATION` only**, so Android 12+ users can choose **Approximate**. **Never** `ACCESS_BACKGROUND_LOCATION` or any `FOREGROUND_SERVICE*` permission. The plugin's `GeolocatorLocationService` is removed from the merged manifest (`tools:node="remove"`). The UAT APK step checks the **exact** permission list. **iOS:** not built or supported in 30B; the `NSLocationWhenInUseUsageDescription` text is added only if an iOS build is ever authorized. | Foreground only, provably so in the shipped APK (DEC-005). Android's own permission screen then offers "While using the app" and "Only this time", never "All the time". | Requesting fine only; leaving the inert service in. |
| **R-19** | **Check-in flow, explicit and foreground only:** 1. Tap **Check in**. 2. Unless permission is already granted, an in-app explanation comes first: *"Company App reads your location once, only when you tap Check in. It never tracks you in the background. Your manager and administrators can see your check-ins. A check-in isn't a clock-in."* Then **Continue** opens Android's prompt. 3. The app reads **one** position (timeout 30 s; Approximate accepted). 4. A form shows "Location found (±N m)" or "Approximate location (±N m)", an optional **Place** (≤ 100) and **Note** (≤ 500). 5. **Send check-in** posts `latitude`, `longitude`, `accuracy_meters` (rounded), `location_label`, `note`, and **no status snapshot**. A position older than 2 minutes is read again first. 6. On `201`: back to My status, "Checked in." and the new row on top. **Cancel** sends nothing. **No offline queue:** a failed send keeps the form and says so; a later send would carry the wrong time. | The minimum the API needs, gathered only at the user's tap, with who can see it stated plainly before Android asks. | A status snapshot (a second, silent meaning); queued offline check-ins; auto check-in. |
| **R-20** | **Location errors never send anything:** location turned off → "Turn on location to check in." with **Open location settings**; permission denied → "Location permission is needed to check in." with **Try again**; denied permanently → the same with **Open app settings**; no position within 30 s → "Couldn't get your location. Try again, ideally outdoors or near a window." | Clear, actionable and honest; the API requires a position anyway. | Sending a check-in without coordinates. |
| **R-21** | **What a check-in row shows** (my own data only): the time in **company time** (Today / Yesterday / date, per Home's rules), the Place or "No place name", the note, "±N m", and the coordinates as text to 5 decimals. **No map and no maps link** (§8; no `url_launcher`). Rows are read-only (immutable). Status rows show the label with its icon and the time. | Lets me confirm what was recorded without adding a map dependency. | A map preview; opening an external maps app. |
| **R-22** | **Unchanged in 30B:** colleagues' statuses stay out of the app (Phase 28 R-5); a Manager's view of reports' check-ins stays in the Administrator Backoffice; Home shows no status; a check-in never changes my status, and vice versa. | Smallest change that delivers the feature; each of these is its own product question. | Status chips in the directory; a manager's "my team" screen. |

### 6.3 Backend (Gate 1)

- `OperationalStatusController::historyFor` and `CheckInController::historyFor`: add `->orderByDesc('id')` after `->latest()` (R-14). This covers all four lists.
- `myIndex` in both controllers adds `meta.company_day` (`date` from `OverdueTasks::todayInCompanyTimezone()`, `timezone`, and `utc_offset` computed as `MyScheduleController` does), through `->additional()` as `/me/work-logs` does, so the paginator's own `meta` keys are unchanged. The `/staff/{id}/…` responses are unchanged apart from the order.
- **Tests:**
  - equal `created_at` ordered by id, newest first, across pages, for all four lists;
  - `meta.company_day` at 07:00 and 23:30 Manila;
  - item shapes unchanged;
  - the no-profile `403` unchanged;
  - `POST` validation unchanged (a regression set: missing coordinates `422`, out-of-range values, label and note limits);
  - the manager and direct-report scoping unchanged.
- `04_API_CONVENTIONS.md` records the additive `meta`.

### 6.4 Mobile data, state and location (Gate 2) — `lib/features/my_status/`

- **`pubspec.yaml`:** `geolocator: 14.0.3` and `geolocator_android: 5.0.3` (exact). **`AndroidManifest.xml`:** the two location permissions and the `tools:node="remove"` service entry (R-18). The Gate 2 report lists every package added to `pubspec.lock`.
- **`domain/`:** strict `OperationalStatusEntry`, `CheckIn`, `OperationalStatus` (enum with labels; an unknown status from the server is shown as "Unknown status", never a crash), and `CheckInDraft` (with the `CheckInRequest` validation limits mirrored).
- **`data/`:** `MyStatusApiClient`: `fetchStatusHistory(page)`, `setStatus`, `fetchCheckIns(page)` and `checkIn(draft)`, each reading `meta.company_day`. A `403` that survives the session re-check maps to `noProfile`.
- **`location/`:** a `LocationService` interface (`permission()`, `requestPermission()`, `serviceEnabled()`, `currentPosition(timeout)`, `openAppSettings()`, `openLocationSettings()`) and its one `geolocator` implementation. Tests use a fake. **No other file imports `geolocator`.**
- **`state/`:**
  - `MyStatusController`: current status, the 5 newest of each list, `noProfile`, errors, and a confirmed `setStatus`.
  - Paged controllers for the two "All" pages.
  - `CheckInController`: the R-19/R-20 state machine, from explaining and requesting through locating, ready and sending to sent, plus the four error states.

### 6.5 Mobile UI (Gate 3)

- More gains **My status** (`/more/status`), with **All check-ins** (`/more/status/check-ins`), **All status changes** (`/more/status/history`), and the check-in flow (`/more/status/check-in`: explanation, locating, form).
- **States:** loading, error with Try again, no profile, pull to refresh. Light and dark mode and 200% text on a small phone. Every control at least 48 dp with a label, and the status icons always paired with text.

### 6.6 30B UAT scenarios (added to `UAT_LOG.md` at implementation; all `NOT RUN`)

| ID | Scenario |
|---|---|
| UAT-30B-01 | More → My status shows my current status and when I set it, my recent check-ins and status changes. Change status to each of the five: the screen updates only after the server accepts it; "Off duty" says it isn't leave. |
| UAT-30B-02 | First check-in: the explanation screen appears before Android's prompt and says who can see check-ins. Allow **While using the app** with precise location; the form shows "Location found (±N m)"; add a place and a note; send. "Checked in." and the row shows the time in Manila time, the place, the note, ±N m and the coordinates. The runbook's verify stage shows the same check-in on staging. |
| UAT-30B-03 | Choose **Approximate**: the check-in is sent and shown as approximate. Deny: "Location permission is needed…", nothing sent. Deny again until Android stops asking: **Open app settings** appears and opens the app's settings. Android's permission screen for the app offers no "Allow all the time". |
| UAT-30B-04 | Location turned off: "Turn on location…" with **Open location settings**; nothing sent. Offline at Send: the form stays with an error; reconnect and send succeeds; exactly one check-in is recorded. |
| UAT-30B-05 | As the staff member's Manager, the runbook's verify stage (or the Administrator Backoffice) sees the check-in; as a colleague who is not their manager, it is refused. As a user without a profile, My status shows only "No staff profile is linked to this account." |
| UAT-30B-06 | The app reads location only while checking in: with the app in the background or closed, Android's location indicator never appears for it, and Android's Privacy dashboard shows the app's location use only at the check-in moments. |
| UAT-30B-07 | All check-ins and All status changes page past 20 without repeats. After token revocation the app returns to Login. |
| UAT-30B-08 | Dark mode and large text on My status, the check-in flow and both lists. |

### 6.7 Definition of Done (30B)

- §6.3–§6.5 implemented; backend and Flutter tests and every `CLAUDE.md` §5 command pass locally and in CI.
- **Exactly** the two approved packages added at the approved versions (plus their transitive packages, listed). The merged manifest holds exactly `INTERNET`, `ACCESS_COARSE_LOCATION`, `ACCESS_FINE_LOCATION` (plus the AndroidX-internal `DYNAMIC_RECEIVER_NOT_EXPORTED_PERMISSION`) and no location service.
- Docs: `CURRENT_STATE`, `CHANGELOG`, `TEST_STATUS`, `UAT_LOG`, `02_ARCHITECTURE`, `04_API_CONVENTIONS`, `05_SECURITY_MODEL` (location capture on mobile), `06_UI_UX_GUIDELINES`, `ROADMAP`, a DEC entry, and `docs/handoffs/V1_PHASE_30B_HANDOFF.md`. UAT-30B-01…08 recorded `NOT RUN`.
- Merged only with product-owner approval; then a UAT30B runbook, staging and UAT on a physical Android device (location can't be tested in this sandbox).

### 6.8 Implementation sequence for 30B (once authorized; gated like 30A)

1. **Gate 1, backend:** tie-breakers and `meta.company_day`, with tests.
2. **Gate 2, mobile data, state and location:** the dependency, the manifest, `LocationService`, API client, controllers, with tests (fakes only).
3. **Gate 3, mobile UI:** My status, the two lists, the check-in flow, routes and the More row.
4. **Gate 4, integration review and PR:** full gates, a real-output contract parity check, the merged-manifest check from a release build, docs, DEC entry, UAT rows, handoff.

## 7. Data / Authorization Rules (whole phase)

- **No new permission. No role widens a self-scoped surface:** `/me/schedule`, `/me/status` and `/me/check-ins` are always the person's own.
- Existing rules stay authoritative: Schedule Entry privacy (DEC-040; a Manager gets no view of a report's private entries), task and leave ownership, location visibility (DEC-032).
- The R-5 fix changes which **days** `/schedule` reports, not **who** sees what.

## 8. Out of Scope (whole phase)

- Creating or editing schedule entries on mobile (R-9); recurring entries; calendar sync or export.
- Viewing colleagues' schedules, statuses or locations on mobile; maps or a maps link (R-21, R-22).
- Background, periodic or geofenced location of any kind; an iOS build (R-18).
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
- **Revision 2 (2026-10-12):** read-only discovery on `main` at `b57f2ef` (after 30A closed). §6 (30B) rewritten from an outline into a full specification: findings §6.1 (the Phase 9 contract, the missing tie-breakers and company day, the `403` no-profile, no location code on mobile, and the `geolocator` package's versions, permissions and inert background service); proposed decisions **R-13…R-22**; backend, mobile, UI, UAT-30B-01…08, Definition of Done and gate sequence. §8 gains the background-location and iOS exclusions. No code, test, route, dependency or configuration was changed.
- **Revision 2 approval (2026-10-12):** the product owner approved R-13…R-22 as written. Only the status line, the §6 and §6.2 headings and this note changed.
- **30B Gate 1 implementation notes (2026-10-12):**
  - `OperationalStatusController` and `CheckInController`: `->orderByDesc('id')` after `->latest()` in the shared `historyFor`, covering all four lists. `myIndex` adds `meta.company_day` through `->additional()`; the `/staff/…` lists don't.
  - `utc_offset` is computed as `MyScheduleController` does (the offset at the start of the company day).
  - New `tests/Feature/Api/V1/StaffOperations/StatusCheckInHistoryTest.php` (15 tests).
  - **On SQLite, equal-second rows already come back newest-first** (the `(staff_id, created_at)` index is walked backwards), so the paging tests alone couldn't fail without the tie-breaker. A test pins the SQL `ORDER BY` (`"created_at" desc, "id" desc`) on all four lists, as Phase 29C did.
