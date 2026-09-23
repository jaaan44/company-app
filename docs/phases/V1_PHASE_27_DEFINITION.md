# Phase 27 — Employee Home / Dashboard (Mobile) — Specification

**Status:** AUTHORIZED — IN PROGRESS. Approved (revision 2, merged via PR #43 at `b63599d4249d3aa621e29468378dea93d540abe4`); implementation authorized in gates. **Gate 1 (backend `GET /api/v1/me/home`) implemented and MySQL-verified (Gate 1A)**; **Gate 2 (Flutter authenticated API client and session lifecycle) implemented**; Gate 3 (Flutter Home screen) not started. The specification text below is unchanged from the approved revision.

**Depends on:**
- Phase 25 (Mobile Application Foundation & Navigation Shell)
- Phase 26 (Staging Mobile Connectivity & TLS)

It reads data owned by:
- Phases 6/7 (organization, Staff)
- Phase 11 (Tasks)
- Phase 14 (Announcements)
- Phase 15 (Notifications)
- Phase 16 (Messaging)
- Phase 17 (Schedule Entries)

It reuses Phase 17's `App\Support\CompanyTimezone` and Phase 20's canonical `App\Support\Reporting\OverdueTasks`.

**Supersedes:** nothing. It refines `docs/ROADMAP.md`'s Phase 27 line ("likely a self-scoped view of Phase 20's dashboard aggregation"). See §3.3.

---

## 1. Objective

Replace the Phase 25 Home placeholder (`lib/features/home/home_page.dart`, which only shows "Signed in as <name>" and a logout button) with a small, read-only employee Home. Immediately after login, an employee sees:

- who they are in the organization;
- what needs their attention (their own tasks, unread messages, unread notifications);
- their own schedule entries and tasks for today;
- the latest announcements addressed to them.

**Home is always the authenticated person's own employee Home.** No role (Administrator, Manager or Staff) turns it into a company-wide or team-wide dashboard. Phase 27 summarizes existing data. It does not build the Tasks, Schedule, HR, Messages, Announcements or Notifications modules owned by Phases 29–32.

## 2. Starting Baseline

- Repository `jaaan44/company-app`, `main` at `bbcaf144a68ec033e0bf3395625a1d34dc75bac4` (PR #42, "Phase 26: formally record Phase 26 as closed"). Phases 1–26 are merged and Phase 26 is formally closed (2026-09-23).
- Backend: Laravel 13 / PHP 8.4, Sanctum bearer tokens with a 30-day expiration (`SANCTUM_EXPIRATION`, DEC-045), versioned `/api/v1`.
- Mobile: Flutter 3.47.2 and `go_router` ^18.0.1.
  - The plain `ChangeNotifier` `AuthController` is exposed through `AuthScope` (DEC-025/DEC-049).
  - The Material 3 light/dark theme is built from an indigo seed with `ThemeMode.system` (DEC-046).

## 3. Current-State Findings (read-only repository discovery)

### 3.1 Backend data relevant to Home

Classification key: **A** = available through an existing API; **B** = data exists but has no suitable mobile API; **C** = belongs to a later phase and is not pulled into Phase 27; **D** = not implemented.

| Candidate | Existing source | Class | Phase 27 treatment |
|---|---|---|---|
| User identity | `GET /auth/me` → `UserResource` | A | Included, as a subset |
| Own Staff profile (names, position, department, team) | `staff` + relations. No `/me` endpoint returns it, and `/staff` cannot locate "my" record (`StaffResource.user` is visible only to `staff.manage`). | B | Included via `/me/home` |
| Company/organization profile | No entity exists | D | Excluded |
| Current operational status | `GET /me/status` | A | **Excluded (R-4, Phase 30)** |
| Tasks assigned to me | `tasks.assignee_staff_id`. `GET /tasks` has no client-usable "assigned to me" filter. | B | Counts and today's tasks via `/me/home` |
| My schedule entries today | `schedule_entries` + `schedule_entry_participants`. `GET /schedule` is visibility-scoped, not self-scoped. | B | Included via `/me/home` (§6.3) |
| Approved leave | `leave_requests` via `/schedule`, `/me/leave-requests` | C | **Excluded (R-3, Phase 31)** |
| Project milestones | `project_milestones` | C | Excluded, because they are project-level rather than personal |
| Leave balances/requests/approvals | `/me/leave-*`, `/leave-requests` | C | Excluded (Phase 31) |
| Latest announcements | `GET /me/announcements` (full payload, 50 per page) | A (heavy) | Lightweight preview, at most 3, via `/me/home` (§6.6) |
| Announcement acknowledgement state/counts | DEC-037 | C | Excluded (Phase 32) |
| Unread notifications count | `GET /me/notifications/unread-count` | A | Included (identical query) |
| Notification inbox | `GET /me/notifications` | C | Excluded (Phase 32) |
| Total unread messages | Per-conversation only (`ConversationResource.unread_count`: paginated, one query per conversation) | B | Included as one aggregate (§6.5) |
| Work Log hours, Service/Incident reports, approvals, attendance | various / none | C / D | Excluded |

### 3.2 Mobile foundation

- **Structure (DEC-021):** `lib/app/{app,router,auth_scope}.dart`, `lib/core/config/app_config.dart`, and `lib/features/{auth,home,shell}/`.
- **Routing:** one `GoRouter` with `/splash`, `/login` and a five-branch `StatefulShellRoute.indexedStack` (`/home`, `/tasks`, `/schedule`, `/messages`, `/more`). Redirects are driven by `AuthController` as the `refreshListenable`.
- **Session:**
  - `AuthController` validates the stored token only at `bootstrap()` (`GET /auth/me`). No request after that checks the session, so a session that expires while the app is open is never noticed.
  - The token lives in `TokenStorage`/`SecureTokenStorage` (DEC-026).
  - `logout()` reads the token, calls `POST /auth/logout` (ignoring failures), deletes the token, clears the user and sets `unauthenticated`.
- **API client:** only `AuthApiClient`, with private `_send`/`_extractMessage` helpers and a single `AuthApiException`. There is no shared authenticated client.
- **Home (the Phase 25 placeholder):** `StatelessWidget`, `AppBar('Company App')`, a logout `IconButton` (the app's only logout control) and a "Signed in as <name>" text.
- **Placeholder tabs:** Tasks/Schedule/Messages/More are `PlaceholderPage` "— coming soon" screens.
- **UI conventions:** loading/empty/error conventions exist only as guidance (`06_UI_UX_GUIDELINES.md`). `AppStatusColors` is not built.
- **Tests:** `test/app/router_test.dart`, `test/features/auth/*` (fake `TokenStorage` and fake HTTP), `test/widget_test.dart`.

### 3.3 Why `GET /api/v1/dashboard` (Phase 20) is not reused

`DashboardController` is **visibility-scoped** (DEC-043), not self-scoped:

- an Administrator's task counts are company-wide;
- a Manager's leave figures cover direct reports;
- plain Staff still receive company-wide `active_staff_count`/`active_clients_count`;
- its `schedule` section wraps `/schedule`, which is visibility-wide.

Reusing it, or adding a `?scope=me` mode, would either widen Home by role or modify an accepted DEC-043 surface. Phase 27 therefore adds a dedicated self-scoped endpoint. It reuses Phase 20's and Phase 17's canonical helpers (`OverdueTasks`, `CompanyTimezone`) rather than the endpoint itself.

### 3.4 Timezone discovery

| Question | Finding (repository evidence) |
|---|---|
| Does a company timezone setting exist? | **Yes.** It is `config('scheduling.company_timezone')` in `apps/api/config/scheduling.php` (Phase 17). |
| Where is it stored? | The environment variable `SCHEDULING_COMPANY_TIMEZONE`, read by that config file. It is not stored in the database. |
| Default/fallback | `'UTC'`, both in `config/scheduling.php` and in `CompanyTimezone::value()`'s `config(..., 'UTC')` fallback. `config/app.php` `timezone` is also `'UTC'` (the PHP/process timezone). |
| Authoritative accessor | `App\Support\CompanyTimezone` (`value()`, `startOfDayUtc()`, `endOfDayUtc()`). Its docblock says nothing else should read the config key directly. |
| Current users | `ScheduleEntryTiming` (all-day normalization); `ScheduleController` (Task/Leave/Milestone date → UTC day instants); `OverdueTasks::todayInCompanyTimezone()` (`Carbon::now(CompanyTimezone::value())->toDateString()`); `ReportPeriod` (current month and `utcFrom`/`utcTo` instants); `DashboardController::schedule()` (`now` in the company timezone). |
| Do task/schedule date boundaries use it? | **Task "today"/overdue: yes** (`OverdueTasks`). **Schedule Entry storage:** all instants are stored in UTC, and all-day entries are normalized to company-timezone day boundaries. **`GET /schedule`'s own `from`/`to` range filter: no.** It uses `$request->date('from')->startOfDay()` plus `whereDate(...)` in the process timezone (UTC). This is a pre-existing inconsistency that only matters when the company timezone is not UTC. It is recorded here and **not fixed in Phase 27** (it is the Schedule module's surface; see §14 R-7). |
| Staging value | **Cannot be established from evidence available to this session.** `apps/api/.env.staging.example` does not define `SCHEDULING_COMPANY_TIMEZONE`, and neither do `docker-compose*.yml`, `docker/` or `docs/DEPLOYMENT_STAGING.md`. The live staging `.env` is an operator-held, uncommitted secret file and was not inspected. If the operator has not added the variable, staging resolves to the `UTC` default. **This is unverified.** |

**No new timezone system is introduced.** Phase 27 does not hardcode any zone, change any environment file, or change staging configuration.

### 3.5 Authentication/authorization response semantics (for the session-expiry rule)

- **401:**
  - `auth:sanctum` rejects a missing, invalid, revoked or expired (`SANCTUM_EXPIRATION`, 30 days) token.
  - `bootstrap/app.php` renders JSON for `api/*`, giving `401 {"message":"Unauthenticated."}`.
  - A 401 on an authenticated route therefore always means "this token is no longer valid".
- **403 from `account.active` (`EnsureAccountIsActive`):**
  - for an inactive or suspended account, it **deletes the current access token** and then returns `403 {"message":"This account is not currently active."}`;
  - so any subsequent request with that token gets 401.
- **Other 403s are ordinary authorization failures that do not end the session.** Examples:
  - `abort(403, 'No staff record is linked to this account.')` in `RequiresLinkedStaff`/`AuthorizesConversationAccess` (every `/me/*` Staff-dependent endpoint and `/conversations`);
  - `can:` permission middleware;
  - in-controller checks (47 `abort(403|401)` sites under `app/Http`).
- There is no general API rate limiter. Only `throttle:login` applies, and only to `POST /auth/login`.

### 3.6 Announcement visibility (existing behavior)

`MyAnnouncementController::myIndex` (`GET /me/announcements`):

- requires a linked Staff record (`RequiresLinkedStaff`, otherwise 403);
- applies `ScopesAnnouncementVisibility::scopeVisibleToStaff($query, $staff)`, which matches `status = published` AND either:
  - `audience_type = company_wide`, OR
  - `audience_type = scoped` AND (the announcement targets the Staff member's **current** `department_id` OR **current** `team_id`), with union semantics and an always-false base when the Staff member has neither;
- orders by `published_at DESC` (no secondary tie-break).

Drafts and archived announcements never match. The Administrator role grants **no** extra visibility on this self-service surface. The management surface `GET /announcements` (`announcements.manage`) is a separate endpoint and is never used by Home.

## 4. Product Decisions (specification review)

| # | Decision | Treatment in this specification |
|---|---|---|
| R-1 | **No** Home card navigation into Tasks/Schedule/Messages while they are placeholders | Every Home tile, row and item is non-interactive (not a button, no `onTap`, no ink/ripple, no chevron). The only interactive controls are logout, "Try again" and pull-to-refresh. Later phases activate navigation when their destination exists. |
| R-2 | **Yes**, a lightweight read-only Latest Announcements preview, at most 3 | §6.6. Only `public_id`, `title` and `published_at`. No body, detail screen, acknowledgement, management or inbox. |
| R-3 | **Yes, narrowed**: today's own schedule entries and today's own tasks. **No approved leave.** | §6.3. Leave is absent from the endpoint, the queries, the UI and the tests (apart from a negative test proving leave never appears). |
| R-4 | **No** operational/current status | Status is not queried, returned or displayed. |

## 5. In Scope

1. **Backend:** one new read-only endpoint, `GET /api/v1/me/home` (§6). It is self-scoped and bounded, with no request parameters, no new permission and no migration.
2. **Mobile:** a real Home screen replacing the placeholder (§8). It keeps the logout action.
3. **Mobile:** a minimal authenticated-request foundation (§7):
   - `lib/core/network/api_client.dart`;
   - a shared `TokenStorage` instance;
   - a single-flight, idempotent session-ending routine in `AuthController`, shared by manual logout and automatic expiry;
   - a session-ended notice on the Login page.
4. **Mobile:** `HomeApiClient`, `HomeSummary` models and `HomeController` (a plain `ChangeNotifier`, DEC-025) under `lib/features/home/`.
5. **Test seam:** `CompanyApp`/`buildAppRouter` accept an optional injected Home data source, mirroring the existing `authController` injection.
6. **Automated tests (§11).**
7. **Documentation and handoff (§16).**

## 6. Backend / API

### 6.1 Route

```
GET /api/v1/me/home
middleware: auth:sanctum, account.active
route name: api.v1.me.home
controller: App\Http\Controllers\Api\V1\Home\MyHomeController
```

- **No query, path or body parameters are accepted.** Any query string is ignored. There is no `user`, `staff`, `employee`, `date` or `timezone` parameter, so Home can never be used to inspect another person or another day.
- Identity is derived exclusively from the authenticated token (`$request->user()`, then `$request->user()->staff`).
- **No new permission.** No `can:` middleware. **The `Gate::before` Administrator override is never consulted.** The controller performs no `can()`/`Gate` check whose result could widen data. The only role-derived output is the user's own role name in `user.role`.
- **A linked Staff record is not required** (the DEC-038 Notifications precedent). Every active authenticated user gets a `200`. Without Staff, the Staff-dependent sections are `null` (§6.8). The controller never calls `RequiresLinkedStaff::resolveAuthenticatedStaff()` (which aborts with 403).

### 6.2 Response contract (`200 OK`)

The response is a single object (the `/dashboard` precedent), not the paginated collection shape. All timestamps are ISO 8601 UTC (`04_API_CONVENTIONS.md`).

```json
{
  "data": {
    "user": { "public_id": "01J…", "name": "Jane Doe", "role": "staff" },
    "staff": {
      "public_id": "01J…",
      "display_name": "Jane Doe",
      "first_name": "Jane",
      "preferred_name": null,
      "position": { "public_id": "01J…", "title": "Field Technician" },
      "department": { "public_id": "01J…", "name": "Operations" },
      "team": null
    },
    "company_day": {
      "date": "2026-09-24",
      "timezone": "UTC",
      "starts_at": "2026-09-24T00:00:00+00:00",
      "ends_at": "2026-09-24T23:59:59+00:00",
      "utc_offset": "+00:00"
    },
    "today": {
      "total_count": 6,
      "items": [
        {
          "source_type": "schedule_entry",
          "public_id": "01J…",
          "title": "Site visit — Acme",
          "activity_type": "client_visit",
          "task_status": null,
          "starts_at": "2026-09-24T09:00:00+00:00",
          "ends_at": "2026-09-24T10:30:00+00:00",
          "is_all_day": false
        },
        {
          "source_type": "task",
          "public_id": "01J…",
          "title": "Replace filter unit",
          "activity_type": null,
          "task_status": "in_progress",
          "starts_at": "2026-09-24T00:00:00+00:00",
          "ends_at": "2026-09-24T23:59:59+00:00",
          "is_all_day": true
        }
      ]
    },
    "tasks": { "open_count": 4, "overdue_count": 1, "due_today_count": 2 },
    "messages": { "unread_count": 3 },
    "notifications": { "unread_count": 5 },
    "announcements": {
      "latest": [
        { "public_id": "01J…", "title": "Office closed Friday", "published_at": "2026-09-22T09:00:00+00:00" }
      ]
    }
  }
}
```

| Field | Nullability | Source | Notes |
|---|---|---|---|
| `user` | never null | `users`, `roles` | `public_id`, `name`, `role` (the role name string, or `null` when unassigned). |
| `staff` | **`null` if no linked Staff** | `staff` + eager `position`, `department`, `team` | `position`/`department`/`team` are each nullable objects. No `employee_number`, email, phone, manager, employment status, operational status or location. |
| `company_day` | never null | `CompanyTimezone` | The authoritative "today" (§6.3). `utc_offset` is the company timezone's offset **at `starts_at`**, for client display (§8). |
| `today` | **`null` if no linked Staff** | §6.3 | `items` ≤ **5**; `total_count` ≥ `items` length. |
| `today.items[].task_status` | null for schedule entries | `tasks.status` | The task's existing `TaskStatus` value. It is shown as a text label; no new status concept. |
| `today.items[].activity_type` | null for tasks | `schedule_entries.activity_type` | The existing `ScheduleEntryActivityType` value. |
| `tasks` | **`null` if no linked Staff** | `tasks` | §6.4 |
| `messages` | **`null` if no linked Staff** | `conversation_members`, `messages` | §6.5 |
| `notifications` | never null | `notifications` | §6.5 |
| `announcements` | **`null` if no linked Staff** | `announcements` | §6.6 |

Changes from revision 1 of this specification:

- removed the `leave` source/item type (R-3);
- replaced `today.date`/`today.timezone` with a top-level `company_day` object, which adds `starts_at`/`ends_at`/`utc_offset`;
- added `today.items[].task_status`;
- dropped the unused `project` item field;
- stated explicitly that there are no request parameters;
- fixed the tie-break order (§6.3);
- defined the announcements tie-break (§6.6);
- the greeting no longer depends on time of day (§8).

No internal numeric id, other person's personal data, message body, conversation name, announcement body or acknowledgement data appears anywhere in the response.

### 6.3 Today (employee-scoped; existing semantics only)

**1. What "today" means.** The current calendar date in the authoritative company timezone:

- `company_date = Carbon::now(CompanyTimezone::value())->toDateString()`. This is identical to `OverdueTasks::todayInCompanyTimezone()`, which should be reused.
- The day window is `[day_start, day_end]`, where `day_start = CompanyTimezone::startOfDayUtc(company_date)` and `day_end = CompanyTimezone::endOfDayUtc(company_date)`. These are UTC instants.
- Neither the device timezone nor the PHP process timezone is ever used to decide "today".

**2. A schedule entry belonging to the employee.** A `schedule_entries` row where the employee's Staff record is:

- the entry's creator (`creator_staff_id = staff.id`), **or**
- a participant (a row in `schedule_entry_participants` for `staff.id`).

These are exactly the first two, personal, clauses of the existing `AuthorizesScheduleEntryAccess::scopeVisibleScheduleEntries()`. The third clause, visibility via a Project the employee can see, is **excluded**: that grants visibility, not ownership. Schedule Entries have no status or cancellation concept, so no status filter applies.

**Overlap with today:** `starts_at <= day_end AND ends_at >= day_start`, using the stored UTC instants. This rule is inclusive, which matches `/schedule`'s inclusive overlap style. All-day entries are already stored as company-timezone day boundaries (`ScheduleEntryTiming`), so an all-day entry appears exactly on its company-timezone days. A multi-day entry appears on every day it overlaps.

**3. A task belonging to the employee.** A `tasks` row where:

- `assignee_staff_id = staff.id` (the Task's single assignee, DEC-034; not the creator, not project membership, not a Project Lead);
- `due_date = company_date`;
- `status NOT IN (completed, cancelled)`. This is the terminal-status exclusion set already canonical in `OverdueTasks`.

Tasks with a null `due_date` never appear in Today. Each task is represented exactly as `/schedule` represents a Task:
- `starts_at = CompanyTimezone::startOfDayUtc(due_date)`;
- `ends_at = CompanyTimezone::endOfDayUtc(due_date)`;
- `is_all_day = true`.

**Not included:** approved or any other leave (R-3, Phase 31), Project Milestones, tasks that are only visible via project membership or `tasks.view`, and schedule entries that are only visible via a project.

**4. Ordering when combined.** A single sort over the union:

1. `starts_at` ascending (UTC instant);
2. `is_all_day` descending (all-day first when starts coincide);
3. `source_type`: `schedule_entry` before `task`;
4. `title` ascending, by plain byte-wise comparison (deterministic across DB engines);
5. `public_id` ascending (a unique ULID, so this is a total order).

**5. Maximum.** `items` contains at most **5**. `total_count` is the full number of qualifying items: the entries count plus the tasks count, from two `COUNT` queries.

**6. Ties.** They are resolved by keys 2–5 above. Two items never compare equal because `public_id` is unique. The same data always yields the same `items`.

**Bounded fetch.** Each source is queried with the same ordering (for tasks, every row shares `starts_at`, so ordering effectively begins at `title`) and `LIMIT 5`. The ≤ 10 rows are merged, sorted by the keys above and truncated to 5. This is provably equal to "sort everything, take 5".

**7. No linked employee profile.** `today` is `null`. No schedule or task query runs. This is not an error.

### 6.4 Task counts (Needs attention)

The set is always `assignee_staff_id = staff.id`, whatever the role.

| Count | Exact derivation | Consistency with existing module |
|---|---|---|
| `open_count` | assigned to me AND `status NOT IN (completed, cancelled)` | Uses the same terminal-status set `OverdueTasks` treats as "not open". Tasks has no separate "open" definition. `blocked` counts as open, as it does for overdue. |
| `overdue_count` | assigned to me, passed through `OverdueTasks::scope()`: non-null `due_date` < company date AND not terminal | **Reuses the canonical class verbatim** (Phase 20, DEC-043). No second overdue definition. |
| `due_today_count` | assigned to me AND `due_date = company_date` (via `OverdueTasks::todayInCompanyTimezone()`) AND not terminal | Exactly the task set of §6.3 point 3, so it always equals the task component of `today.total_count`. |

Consistency is enforced by tests: `overdue_count` equals the `OverdueTasks::scope()` count for the same assignee filter, and `due_today_count` equals the number of task items qualifying for Today.

### 6.5 Message and notification counts

| Count | Exact derivation | Consistency with existing module |
|---|---|---|
| `messages.unread_count` | Σ over the employee's `conversation_members` rows (current memberships only; removal hard-deletes the row) of `COUNT(messages WHERE messages.conversation_id = member.conversation_id AND (member.last_read_message_id IS NULL OR messages.id > member.last_read_message_id))`. This is computed in **one** aggregate query (a join or correlated subquery). | **Identical predicate** to `ConversationMember::unreadCount()`, the per-conversation `unread_count` Messaging already exposes. Sending a message already advances the sender's own `last_read_message_id` (`MessageController`), so the employee's own messages are not unread. A parity test asserts that the Home value equals the Σ of `unreadCount()` over the same memberships. |
| `notifications.unread_count` | `notifications WHERE recipient_user_id = user.id AND read_at IS NULL` | **Identical** to `NotificationController::myUnreadCount()` (`GET /me/notifications/unread-count`). It is User-based and needs no Staff record (DEC-038). |

No count was removed for inconsistency. All five counts map one-to-one to an existing module definition.

### 6.6 Latest announcements (R-2)

- **Eligibility:** exactly `ScopesAnnouncementVisibility::scopeVisibleToStaff($query, $staff)`, reused through the existing trait and not re-implemented. It covers `status = published`, plus company-wide OR scoped to the employee's **current** department or team (union). Drafts and archived announcements are never eligible.
- **Role:** the Administrator/Manager role adds nothing. The management surface (`announcements.manage`, `GET /announcements`) is never used.
- **Ordering:** `published_at DESC`, then `id DESC` as a deterministic tie-break. The eligible set is unchanged; the tie-break only fixes order among identical `published_at` values, which the existing endpoint leaves unspecified.
- **Maximum:** `LIMIT 3`.
- **Fields:** `public_id`, `title`, `published_at` only. No body, audience, departments/teams, creator/publisher, acknowledgement data or counts. Relations are not eager-loaded, and the query selects only the needed columns.
- **No linked Staff:** `announcements` is `null` (the existing surface itself requires Staff).
- **Guarantee:** every announcement returned by Home is also returned by `GET /me/announcements` for the same user. This is enforced by a subset test.

### 6.7 Authorization and data isolation (elevated roles)

- There are no identifying request parameters (§6.1). The endpoint is self-scoped by construction.
- Role privilege **never widens** the profile, Today items, task counts, message counts, notification counts or announcements:
  - there is no `tasks.view`, `projects.view` or Project-Lead branch;
  - there is no Manager direct-report branch;
  - there is no Administrator `Gate::before` branch;
  - there is no management-surface query.
- Every returned datum is a subset of what the employee already sees through the owning module's self-service or membership surface.
- Tests assert that an Administrator with Staff, a Manager with direct reports and a Project Lead each receive **only their own** figures and items, in the same seeded company where `/dashboard` returns wider figures for them.

### 6.8 Employee-profile and no-profile behavior

- **Linked Staff:** all sections are present. Staff employment status (`inactive`/`separated`) does not alter the response. The account-level `account.active` check is the only gate, consistent with every other `/me` endpoint.
- **No linked Staff:** `200`, with `user`, `company_day` and `notifications` present, and `staff`, `today`, `tasks`, `messages` and `announcements` all `null`. The response is never a 403.

### 6.9 Error behavior

| Case | Response | Source |
|---|---|---|
| Missing, invalid, revoked or expired token | `401 {"message":"Unauthenticated."}` | `auth:sanctum` |
| Account inactive or suspended | `403 {"message":"This account is not currently active."}`, **and the token is deleted** | `account.active` |
| Unexpected failure | `500`, generic message (`APP_DEBUG=false` on staging) | framework |

The controller itself never returns 403 or 404. Empty data is never an error.

### 6.10 Explicitly unchanged

`/auth/*`, `/dashboard`, `/schedule`, `/schedule-entries`, `/tasks`, `/conversations`, `/me/announcements`, `/me/notifications*`, `/me/leave-*` and every authorization trait or support class stay byte-for-byte unchanged. They are reused via `use` or static calls only. If a needed predicate is private to a trait and cannot be reused cleanly, the Home controller reproduces the narrow query, and a parity test proves it equivalent (the Phase 20 precedent).

## 7. Mobile Changes

| Area | Change |
|---|---|
| `lib/core/network/api_client.dart` (new) | A minimal authenticated `GET` helper: base URL from `AppConfig`, `Accept: application/json`, `Authorization: Bearer <token>`, with the token read from the injected `TokenStorage` **per request**. It returns the decoded `data`. It throws typed exceptions that carry the token used for the request: `ApiUnauthorizedException` (401, or no stored token), `ApiForbiddenException` (403, carrying the server's sanitized `message`), `ApiNetworkException` (transport failure) and `ApiServerException` (other non-2xx or a malformed body). **It never ends a session itself.** GET only; other verbs are added by the later phases that need them. `AuthApiClient` is **not** refactored in Phase 27 (recorded as a known duplication). |
| `lib/features/home/data/home_api_client.dart` (new) | Calls `GET /me/home` and returns `HomeSummary`. |
| `lib/features/home/domain/home_summary.dart` (new) | Immutable models for §6.2. The null sections are explicit. |
| `lib/features/home/state/home_controller.dart` (new) | A `ChangeNotifier` with `status` (`loading`/`loaded`/`error`), `summary`, `errorMessage`, `isRefreshing`, `load()` and `refresh()`. Only one fetch is in flight at a time: a refresh while loading is ignored. Results arriving after `dispose()` are discarded. It applies the 401/403 rules in §7.1. |
| `lib/features/home/presentation/home_page.dart` (moved from `features/home/home_page.dart`) | A `StatefulWidget` that owns a `HomeController`, renders §8 and keeps logout. |
| `lib/features/auth/state/auth_controller.dart` | Adds the single-flight session-ending routine (§7.2) and `sessionEndedMessage`. The public behaviour of `login`/`bootstrap` is unchanged. |
| `lib/features/auth/presentation/login_page.dart` | Shows `AuthController.sessionEndedMessage` (if set) as an announced notice on arrival, and clears it on the next login attempt. Form and validation logic are unchanged. |
| `lib/app/app.dart`, `lib/app/router.dart` | One shared `TokenStorage`, plus an optional injected Home data source for tests. Routes, the redirect function and the shell are unchanged. |
| `pubspec.yaml` | **No new dependency.** No timezone package: display uses the server-provided `company_day.utc_offset` (§8). |

### 7.1 401 / 403 rule (grounded in §3.5)

- **401 on any authenticated request** means the token is invalid, so the session is ended. `HomeController` calls `authController.expireSession(tokenUsed: e.token)`. The router's existing redirect then shows `/login`, where the Login page displays "Your session has ended. Please sign in again."
- **403 is never, by itself, treated as session-invalidating.** The API uses 403 both for `account.active` (which also revokes the token) and for ordinary authorization failures, such as the missing-Staff-record 403 on other `/me/*` endpoints or permission denials. The client does not parse message strings to distinguish them. Instead, on a 403 the `HomeController` performs **one** session check, `GET /auth/me`, the same validation `bootstrap()` already uses:
  - `/auth/me` → **401**: the token is gone (the `account.active` case). Call `expireSession(tokenUsed: …)`. The generic "session ended" message is shown; account status is not disclosed, in keeping with DEC-045's non-disclosure intent.
  - `/auth/me` → **200**: this was an ordinary authorization failure. **The session is kept.** Home shows its error state with the server's message and "Try again".
  - `/auth/me` → a network or server error: the session is kept and the network/server error state is shown.
- For `/me/home` specifically, the backend contract (§6.9) means a 403 can only come from `account.active`, so in practice the check resolves to 401. The general rule still stands, so the new `ApiClient` can be reused safely by Phases 28–33, whose endpoints do return ordinary 403s.

### 7.2 Session-ending race safety and idempotency

- `AuthController` gains one private, single-flight `_endSession({required bool revokeServerSide, String? message})`. Both `logout()` (manual) and `expireSession(...)` (automatic) delegate to it.
  - If an end-session operation is already in flight, callers await the **same** `Future`. It is never run twice.
  - If the status is already `unauthenticated`, it is a no-op, apart from recording the message if none is set.
- `expireSession({required String? tokenUsed})` acts only if `tokenUsed` equals the **currently stored** token, or no token is stored. A late 401 from a request made with an old token therefore cannot sign out a newer session created by a fresh login. Concurrent 401s from several requests collapse into one end-session operation, one token deletion, one `notifyListeners()` transition to `unauthenticated` and one redirect.
- Token clearing:
  - `TokenStorage.deleteToken()` is always awaited, and a storage error is caught and does not block the state transition;
  - `_user` is cleared;
  - `status = unauthenticated`.
  - Manual logout still attempts `POST /auth/logout` first and ignores failures (existing behaviour). Automatic expiry does **not** call `/auth/logout`, because the token is already invalid.
- Stale UI: the router leaves the shell on `unauthenticated`. The `StatefulShellRoute` branches are disposed, so `HomeController` is disposed and no previous user's Home data survives into a later session.
- Redirect loops are impossible. The redirect function is unchanged and settles once `/login` is reached, and `/login` makes no authenticated request.

## 8. UX Behavior (Home screen)

**AppBar:** title "Home". The existing logout `IconButton` (tooltip "Log out", 48×48dp) is kept, because More is still a placeholder.

**Body:** a `RefreshIndicator` wrapping a vertically scrolling `ListView` with `AlwaysScrollableScrollPhysics`, 16px (`md`) gutters and constrained width on wide screens. Sections, in order:

1. **Greeting / identity.**
   - "Hello, {preferred_name ?? first_name}" (no time-of-day wording, so no device timezone is involved).
   - Subtitle "{position.title} · {department.name}", omitting missing parts and hidden when both are absent. Team is shown on a second line if present.
   - **No linked Staff:** "Hello, {user.name}", plus the informational text "Your account isn't linked to an employee profile, so some sections aren't available."
2. **Today** (a `Card`; hidden when `today` is null).
   - Heading: "Today · {company_day.date}", formatted as a weekday, day and month from the server's date string, not the device date.
   - Up to 5 `ListTile`-style rows that are **non-interactive**. Each row has a source icon **and** a text label: for a schedule entry, the activity type label ("Meeting", "Client visit", …); for a task, "Task due" plus its status label ("To do", "In progress", "Blocked").
   - Each row shows the title and the time: "All day", or "HH:mm–HH:mm" rendered **in the company timezone** by applying `company_day.utc_offset` to the UTC instants. Parts of a multi-day entry falling outside today are shown as "until {date}" or "from {date}".
   - If `total_count` > shown: plain text "+N more today".
   - Empty: "Nothing scheduled for you today."
3. **Needs attention.** Non-interactive summary tiles in a 2-column wrap that reflows to 1 column at large text scale:
   - "My tasks": `open_count` open; "{overdue_count} overdue" (text, in the `error` role when > 0); "{due_today_count} due today". Hidden when `tasks` is null.
   - "Unread messages": `messages.unread_count`. Hidden when null.
   - "Unread notifications": `notifications.unread_count`. Always shown.
   - Zero values stay visible with calm wording (for example "No unread messages") so the layout is stable.
4. **Latest announcements** (a `Card`; hidden when `announcements` is null).
   - Up to 3 **non-interactive** rows: the title (max 2 lines, ellipsised) and the published date, rendered in the company timezone via `utc_offset`.
   - Empty: "No announcements yet."

**R-1 guarantee:** no tile, row or item is tappable. No `InkWell`/`onTap`/`GestureDetector` is used, and there is no trailing chevron, so nothing implies navigation. Tests assert this (§11.2).

**States:**

- **Initial load:** a centered `CircularProgressIndicator`.
- **Error without data:** an inline, centered message in the `error` role, wrapped in `Semantics(liveRegion: true)`, with a "Try again" `FilledButton`. The messages are:
  - network: "Couldn't load your Home. Check your connection.";
  - server: "Something went wrong loading your Home.";
  - 403 with the session still valid: the server's sanitized message.
- **Refresh failure with data already shown:** keep the stale data and show a `SnackBar`: "Couldn't refresh. Showing earlier information."
- **401 or 403 that resolves as expired:** return to Login with the session-ended notice (§7.1).

**Pull-to-refresh:** included. It is justified because counts change during the day and there is no push or real-time transport (DEC-039). There is no polling, and no automatic refresh on tab re-selection or app resume.

**Navigation:** Home stays shell branch 0. Switching tabs and returning preserves state without re-fetching. Logout behaves as in Phase 25.

**Light/dark:** only `ColorScheme` roles and `TextTheme` roles (`headlineSmall` greeting, `titleMedium` section headings, `titleSmall` rows, `bodyMedium`, `labelLarge`). No raw colors, and no `AppStatusColors`.

**Accessibility:**
- 48×48dp minimum for the three interactive controls (logout, "Try again", and the scroll area for refresh).
- Each tile is a single semantics node with a full label, for example "3 unread messages".
- Status is always carried by text, never by color alone.
- No clipping at 200% text scale.
- Reading order follows the visual order.

## 9. Data / Authorization Rules (summary)

- The endpoint is self-scoped by construction, with no identifying parameters. Identity comes only from the token.
- Role never widens anything (§6.7). No new permission.
- Every datum is a subset of what the employee's own self-service or membership surfaces already expose (the DEC-043 principle).
- Without a linked Staff record, `user`, `company_day` and `notifications` are returned and everything else is `null`.
- Announcement audience follows the employee's current department and team (DEC-037).
- Messaging privacy (DEC-039): counts only.

## 10. Performance / Query Considerations

- About **12 queries per request, constant with data volume**:
  - user and role;
  - Staff plus position/department/team (eager);
  - task counts (one conditional-aggregate query, or three counts);
  - one message-unread aggregate;
  - one notification count;
  - one announcements query (`LIMIT 3`, selected columns);
  - Today: entries (`LIMIT 5`), entries count, tasks (`LIMIT 5`), tasks count.
- **No N+1:** there is no per-conversation `unreadCount()` loop, and no `/schedule`-style load-the-whole-range-then-filter-in-PHP.
- **Bounded output:** at most 5 Today items and at most 3 announcements.
- **Existing indexes suffice:**
  - `tasks(assignee_staff_id, status)`;
  - `messages(conversation_id, id)`;
  - `conversation_members(conversation_id, staff_id)` (unique);
  - `notifications(recipient_user_id, read_at)`;
  - `schedule_entries(creator_staff_id, starts_at)`;
  - the participant pivot's foreign-key indexes.
- **No migration or index is planned.** One would be added only if a real query demonstrably needs it, and recorded.
- No cache, queue or background job (the ~100-employee scale, DEC-043 precedent).
- The mobile app makes one request per load or refresh, plus at most one `/auth/me` check after a 403.

## 11. Automated Tests

### 11.1 Backend (`apps/api/tests/Feature/Api/V1/Home/`)

1. `401` with no token, an invalid token, a revoked token, and a token past `SANCTUM_EXPIRATION`.
2. `403` for an inactive or suspended account, and the token is deleted (a follow-up request gets `401`).
3. No linked Staff: `200`; `staff`/`today`/`tasks`/`messages`/`announcements` are `null`; `notifications` and `company_day` are correct.
4. Profile fields: position, department and team present and null-safe. A recursive assertion that no internal numeric `id` and no email/phone/manager/status field appears anywhere.
5. **Parameters are ignored:** `?staff=<other public_id>`, `?user=…` and `?date=…` do not change the response.
6. **Elevated-role isolation:** an Administrator with Staff, a Manager (with direct reports who have tasks, entries and messages) and a Project Lead (of a project with other members' tasks and entries) each receive only their own profile, counts, Today items and audience-eligible announcements.
7. Tasks: `open_count` excludes completed and cancelled and includes blocked. `overdue_count` equals the `OverdueTasks::scope()` result for the same assignee. `due_today_count` is correct. Tasks visible only via project membership are excluded. Null `due_date` is handled.
8. Timezone: with `scheduling.company_timezone` set to a non-UTC zone (for example UTC+8) and time frozen near midnight, "today", overdue, due-today and the entry-overlap boundaries follow the company date, not the UTC date. `company_day.utc_offset`/`starts_at`/`ends_at` are correct.
9. Today entries: creator included; participant included; project-only-visible entry excluded; another person's private entry excluded; overlap boundaries (entry ending exactly at `day_start`, starting exactly at `day_end`); a multi-day entry appears; an all-day entry appears only on its company-timezone day.
10. Today tasks: the assignee's non-terminal task due today is included; completed, cancelled, tomorrow's, yesterday's and others' tasks are excluded.
11. **Leave never appears:** an approved leave covering today produces no item and does not change `total_count`.
12. **Milestones never appear.**
13. Ordering and limit: combined sort keys 1–5 (ties across `starts_at`, `is_all_day`, `source_type`, `title` and `public_id`); at most 5 items; `total_count` is correct when more than 5 qualify; the bounded fetch equals a full sort for randomized data.
14. Messages: the sum across several conversations, with `last_read_message_id` null and set; non-member conversations excluded; the sender's own messages are not counted after sending; **parity** with `Σ ConversationMember::unreadCount()`.
15. Notifications: equals `GET /me/notifications/unread-count` for the same user; others' notifications are excluded.
16. Announcements: only published and audience-eligible (company-wide, own department, own team; other department or team excluded); draft and archived excluded; at most 3; order `published_at DESC, id DESC`; fields limited to `public_id`/`title`/`published_at`; **subset** of `GET /me/announcements`; an Administrator gets no extra announcements.
17. Empty employee: zeros, empty arrays, `total_count` 0.
18. **Query-count guard:** the same query count for 1 versus 20 conversations, tasks, entries and announcements.
19. The full existing suite, `pint --test`, `phpstan` (level 5), `composer validate --strict` and `composer audit --locked` all pass.

### 11.2 Flutter

- `test/core/network/api_client_test.dart`: the bearer header; 2xx decode; 401 and a missing token → unauthorized (carrying the token used); 403 → forbidden with the message; socket error → network; 500 and malformed JSON → server. The client never touches `AuthController`.
- `test/features/auth/auth_controller_test.dart` (extended):
  - `expireSession` clears the token and user, sets `unauthenticated` and sets the message;
  - concurrent `expireSession` calls cause one deletion and one transition;
  - `logout` racing `expireSession` causes a single end-session;
  - a stale `tokenUsed` does not end a newer session;
  - a `deleteToken` failure still ends the session;
  - calling either method when already unauthenticated is a no-op.
- `test/features/home/home_api_client_test.dart`: full, empty and no-Staff payload parsing.
- `test/features/home/home_controller_test.dart`:
  - load success and error;
  - single in-flight fetch;
  - refresh failure keeps data;
  - 401 → `expireSession`;
  - 403 → `/auth/me` 401 → `expireSession`;
  - 403 → `/auth/me` 200 → **session kept** and error state shown;
  - results after dispose are ignored.
- `test/features/home/home_page_test.dart`:
  - loading; populated (all sections, "N overdue" text, status labels, "+N more today");
  - empty (every empty text); no-Staff variant;
  - error with retry recovering; refresh-failure `SnackBar` with stale data;
  - pull-to-refresh triggers a second fetch;
  - 401 → `/login` with the session-ended notice;
  - logout still works;
  - **no tile/row/item is tappable** (no `InkWell`/`onTap` ancestors; tapping changes nothing);
  - times rendered in the company offset regardless of the test device timezone;
  - dark theme renders;
  - no overflow at `textScaler` 2.0;
  - semantics labels on tiles.
- `test/app/router_test.dart` (updated): every Phase 25 behavior still passes with an injected fake Home source; switching tabs and back does not re-fetch.
- `test/features/auth/login_page_test.dart` (extended): the session-ended notice appears and clears on the next submit.
- Standing gates: `dart format --set-exit-if-changed .`, `flutter analyze`, `flutter test`.

## 12. UAT Scenarios

These are to be added to `docs/testing/UAT_LOG.md` during implementation. **All are `NOT RUN`.** An AI session never marks PASS.

| ID | Scenario | Status |
|---|---|---|
| UAT-27-01 | On a real device, sign in as a Staff user with a linked profile. Home shows "Hello, {name}" with the correct position and department, and loads without error. | NOT RUN |
| UAT-27-02 | With seeded data (a schedule entry today created by the user, one where the user is a participant, a task assigned to the user due today, one overdue task, an unread direct message, an unread notification, a published company-wide announcement), each appears correctly on Home. Another employee's entries and tasks do not appear. | NOT RUN |
| UAT-27-03 | As a user with no tasks, entries, messages or announcements, the empty states read clearly and nothing looks broken. | NOT RUN |
| UAT-27-04 | Another account sends the user a message; pulling down to refresh updates the unread count. | NOT RUN |
| UAT-27-05 | In airplane mode, opening or refreshing Home shows a clear error with "Try again"; retrying after reconnecting recovers. | NOT RUN |
| UAT-27-06 | After the session is revoked server-side (token deleted, or the account suspended by an Administrator), refreshing Home returns to Login with a session-ended notice, and signing in again works normally. | NOT RUN |
| UAT-27-07 | Toggling system dark mode and increasing the system font size keeps Home legible with no clipped text. No Home card or item is tappable. | NOT RUN |
| UAT-27-08 | Signed in as an Administrator (with and without a linked Staff profile) and as a Manager, Home shows only that person's own data, never company- or team-wide figures. The no-profile variant renders sensibly. | NOT RUN |

## 13. Acceptance Criteria

- `GET /api/v1/me/home` behaves exactly as §6 says: no parameters, self-scoped, no new permission, no migration, bounded, and all §11.1 tests pass.
- Home renders §8 with non-interactive cards (R-1), an announcements preview capped at 3 (R-2), Today limited to the employee's own schedule entries and tasks with no leave (R-3), and no operational status (R-4).
- The 401/403 and session-expiry behavior follows §7.1–7.2, and all §11.2 tests pass. Phase 25 redirect, tab and logout behaviour is intact.
- No placeholder tab changed. No new `pubspec` dependency. No change to `/dashboard`, `/schedule` or any existing authorization trait or support class. No environment or timezone configuration change.
- All `CLAUDE.md` §5 commands pass locally and in CI.
- UAT-27-01…08 are recorded as `NOT RUN`. §16 documentation is complete.

## 14. Risks / Dependencies

- **R-5 (session expiry):** this is the app's first mid-session auth handling. It is mitigated by the single-flight, token-matched `_endSession` (§7.2) and dedicated race tests.
- **R-6 (`ApiClient` reuse):** keep it minimal (GET plus typed errors). It never ends a session itself; callers apply §7.1.
- **R-7 (timezone):**
  - staging's `SCHEDULING_COMPANY_TIMEZONE` is **unverified**; it is `UTC` unless the operator has set it;
  - the product owner or operator should confirm or set the intended company timezone on staging **outside Phase 27** before UAT-27-02, since "today" follows it;
  - pre-existing: `GET /schedule`'s own range filter uses UTC day boundaries rather than `CompanyTimezone` (§3.4), so a future Schedule tab (Phase 30) may disagree with Home near midnight when the company timezone is not UTC. This is recorded for Phase 30 and not fixed here;
  - display uses the single `utc_offset` in effect at the start of the company day, so on a daylight-saving transition day, times after the switch may show one hour off. This is documented and accepted at this scale; a no-DST zone or UTC is unaffected.
- **R-8 (UAT data):** there is no Admin UI, so UAT-27-02 data must be created via the API or Tinker. The handoff must include a seeding recipe.
- **Phase 26 carried-forward items — none blocks Phase 27:**
  - the historical UAT-24-04 `:8012` wording (documentation only);
  - the inert DigitalOcean firewall 8012 rule (the port is loopback-bound);
  - client-IP accuracy behind `trustProxies(at: '*')` (Home neither uses nor logs client IP);
  - the Android label "mobile" (cosmetic);
  - Android release debug signing (distribution, not function).

  All remain out of scope.

## 15. Rollback Considerations

- **Backend:** additive only (one route, one controller, tests). There is no schema or data change. Revert and redeploy; no other client depends on `/me/home`.
- **Mobile:** reverting restores the Phase 25 placeholder Home. There are no persisted-data changes, and the token storage format is unchanged.
- **Mixed versions:** an old app ignores the endpoint. A new app against an old API gets a `404`, which it shows as a retryable server error, not a crash. Deploy the API before distributing the app.

## 16. Documentation / Handoff Requirements (at implementation)

- `docs/CURRENT_STATE.md` and `docs/CHANGELOG.md`.
- `docs/04_API_CONVENTIONS.md`: `GET /me/home` (self-scoped aggregate, no Staff required, no parameters).
- `docs/05_SECURITY_MODEL.md`: Home isolation, and the client 401/403 rule.
- `docs/02_ARCHITECTURE.md`: the Home module, `core/network`, and session ending.
- `docs/06_UI_UX_GUIDELINES.md`: Home implemented, and the first real loading/empty/error/refresh usage.
- `docs/ROADMAP.md`: Phase 27 complete, and the refinement of "self-scoped view of Phase 20's dashboard".
- `docs/DECISIONS.md`: **DEC-052**, added at implementation per established practice (DEC-049 was recorded with Phase 25's implementation). It records the dedicated self-scoped `/me/home` rather than `/dashboard`; the Today, count and announcement definitions; the 401/403 client rule; and the `ApiClient`/session-ending foundation.
- `docs/testing/TEST_STATUS.md` and `docs/testing/UAT_LOG.md` (UAT-27-01…08 `NOT RUN`).
- `docs/handoffs/V1_PHASE_27_HANDOFF.md` (per `docs/handoffs/README.md`), separating Implemented / Tested automatically / Manually verified / Awaiting UAT, with the UAT seeding recipe and the staging-timezone confirmation note.

## 17. Definition of Done

- All §13 criteria are met and verified by the standing commands.
- The §11 tests are implemented and passing, with no regression in the Phase 1–26 suites.
- §16 documentation and the handoff are complete, and the UAT rows are `NOT RUN`.
- Merged to `main` only with product-owner approval.
- The session then **stops** (`CLAUDE.md` §8). No Phase 28 work begins without authorization.

## Proposed Implementation Sequence (once authorized)

1. Backend: route plus `MyHomeController` with `user`/`staff`/`company_day`/`notifications`, and tests (401/403, no-Staff, ignored parameters).
2. Backend: tasks, messages and announcements, with parity, subset, isolation and query-count tests.
3. Backend: Today (entries plus tasks), with timezone, overlap, ordering, limit and no-leave tests. Run the full backend gates.
4. Mobile: `ApiClient`, the shared `TokenStorage`, `AuthController` single-flight session ending and the Login notice, with race tests.
5. Mobile: Home models, API client and controller, with the 401/403 rule tests.
6. Mobile: Home UI, states, pull-to-refresh and the router injection seam, with widget and router tests (including non-interactivity). Run the Flutter gates.
7. Docs, DEC-052, UAT rows and handoff. Update the PR; do not merge without approval.

## Notes

- **This document is a specification, not an authorization.** Implementation requires explicit product-owner approval.
- The R-1…R-4 decisions are recorded in §4.
- No database migration is expected.
