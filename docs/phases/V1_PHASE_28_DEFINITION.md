# Phase 28 — People: Staff Directory & Profile (Mobile) — Specification

**Status:** AUTHORIZED — IN PROGRESS. Approved (revision 2, merged via PR #55 at `0bb3a64fe5d4da44f4c79fce152047acabb049dc`); implementation authorized in gates. **Gate 1 (backend `GET /api/v1/me/profile` and the `/staff` tie-breaker) implemented**; **Gate 2 (Flutter People models, `PeopleApiClient` and controllers) implemented**; Gates 3–4 (UI and router, integration review) not started. The specification text below is unchanged from the approved revision.

**Depends on:**
- Phase 25 (Mobile Application Foundation & Navigation Shell): the `go_router` shell and the `More` tab
- Phase 26 (Staging Mobile Connectivity & TLS)
- Phase 27 (Employee Home): the authenticated `ApiClient`, its 401/403 session rule (DEC-052 §7) and the loading/empty/error/refresh conventions

It reads data owned by:
- Phase 6 (Departments, Teams, Positions)
- Phase 7 (Staff, DEC-030)

**Supersedes:** nothing. It turns `docs/ROADMAP.md`'s Phase 28 line ("Staff Directory browsing and the staff member's own profile") into a concrete scope.

---

## 1. Objective

Replace the Phase 25 `More` placeholder with the first two real secondary areas named in `06_UI_UX_GUIDELINES.md` (`More` → `Profile · Staff Directory · …`):

- **My profile:** the signed-in person sees their own company record. That means name, position, department, team, manager, company contact details and employment details, plus their account identity.
- **Staff Directory:** an employee can find a colleague by name, see where they sit in the organization, and get their company contact details.

Both are **read-only**. Phase 28 does not add self-service editing, photos, org charts, or management (create/update/delete) of any kind. Staff management stays Administrator-only and API-only until the Admin Backoffice phases (34–35).

## 2. Starting Baseline

- Repository `jaaan44/company-app`, `main` at `6be66aac1898a60440a5e63a23189a1bc685f72c` (PR #48, Phase 27 formal closure). Phases 1–27 are merged; Phase 27 was formally closed on 2026-09-24.
- **Backend:** Laravel 13 / PHP 8.4, Sanctum bearer tokens, versioned `/api/v1`.
- **Mobile:** Flutter 3.47.2, `go_router` ^18.0.1.
  - The Phase 27 `ApiClient` (GET only) and `AuthController` session lifecycle are in place.
  - The `More` tab is `MorePlaceholderPage`.
  - Dependencies are `http`, `flutter_secure_storage`, `go_router`, `cupertino_icons`.
- **Staging:** runs `be43663` (Phase 27). The Phase 27 UAT27 accounts and records remain on staging (intentional; `docs/testing/PHASE_27_UAT_PREPARATION.md` §8.4).

## 3. Current-State Findings (read-only repository discovery)

### 3.1 The Staff Directory API already exists (Phase 7)

- **Routes** (`routes/api/v1.php`):
  - `GET /api/v1/staff` and `GET /api/v1/staff/{public_id}`, behind `auth:sanctum` + `account.active` + `can:staff.view`;
  - the writes are behind `can:staff.manage`.
- **Permissions** (`RolePermissionSeeder`): `staff.view` is attached to Manager and Staff. The Administrator passes every gate via `Gate::before`. A user with **no role** gets `403`.
- **`StaffController::index` filters:** `status`, `department`, `team`, `position`, `manager` (each a `public_id`) and `q`. `q` is a `LIKE '%…%'` match on `first_name`, `last_name`, `preferred_name` and `employee_number`.
- **Ordering and pagination:** ordered by `last_name, first_name`. Laravel `LengthAwarePaginator` with `per_page` defaulting to 50 (`data`/`links`/`meta`).
- **Eager loads:** `department`, `team`, `position`, `manager`, `user` and `latestOperationalStatus`, so the query count is constant per page.
- **`StaffResource` fields:**
  - shown to every `staff.view` holder: `public_id`, `employee_number`, `first_name`, `last_name`, `preferred_name`, `display_name` (preferred name, else "first last"), `company_email`, `company_phone`, `status`, `hire_date`, `separation_date`, `department {public_id, name}`, `team {public_id, name}`, `position {public_id, title}`, `manager {public_id, display_name}`, `has_user_account`, `operational_status` (Phase 9 status word), `created_at` and `updated_at`;
  - shown only to a `staff.manage` holder (Administrator): `user {public_id, email, status}`.
- **No default status filter.** The list returns `active`, `inactive` **and** `separated` records unless `?status=` is given. Any `staff.view` holder can already see separated colleagues and their `separation_date`. This was a deliberate Phase 7 / DEC-030 decision ("the directory is company-wide"); Phase 28 does not reopen it at the API level (see R-1).
- **No personal data** beyond company-directory level exists on `staff` (`05_SECURITY_MODEL.md`, Phase 7): no home address, personal phone, government ID, salary or emergency contact.

### 3.2 There is no "my profile" endpoint

- `GET /api/v1/auth/me` returns only `UserResource` (`public_id`, `name`, `email`, `status`, `role`).
- `GET /api/v1/me/home` (Phase 27) returns a summary of the user's own staff record: `public_id`, names, position, department and team. It has no contact details, manager or employment dates.
- The full own record is reachable today only through `GET /staff/{own public_id}`. That needs `staff.view` and two calls (first `/me/home` for the id). The Administrator without a linked profile has no staff record at all.
- **Self-scope precedents:**
  - `/me/status`, `/me/check-ins` and `/me/announcements` require a linked Staff record and answer `403` without one (`RequiresLinkedStaff`).
  - `/me/notifications` and `/me/home` need no Staff record; `/me/home` returns `staff: null` (DEC-052).

  For the mobile client a `403` is "forbidden, keep the session" (Phase 27 §7.1). So the no-profile case must be a normal `200` response, not a `403`, or the Profile screen would show a generic error instead of a sensible "no staff profile" state.

### 3.3 Findings in existing code relevant to a paginated mobile list

1. **Unstable page order.** `ORDER BY last_name, first_name` has no unique tie-breaker. Two people with the same name can be skipped or duplicated across pages, which "load more" scrolling would expose. (R-6)
2. **Unbounded `per_page`.** `$request->integer('per_page', 50)` is not capped. This is the same in every collection controller (about 30), so it is a cross-cutting finding, not a Phase 28 one. The mobile client will send an explicit small `per_page`. A cap belongs to a cross-cutting hardening phase (36) and is recorded, not fixed here.
3. **`q` wildcards are not escaped.** `%` or `_` typed into the search box act as wildcards. This is harmless here (it only widens a match within data the user may already see). Recorded, not fixed.
4. **`operational_status` is in `StaffResource`.** Phase 27 R-4 kept operational status off Home, and Phase 30 (Operations) owns status and check-in UX. (R-4)

### 3.4 Mobile

- `MorePlaceholderPage` is the `/more` branch's only route.
- `ApiClient.getJson(path)` accepts a path plus query string. Callers must build the query with `Uri(queryParameters: …)` so that search text is encoded.
- There is no `url_launcher` (or any tap-to-call/email) dependency (R-3).
- `06_UI_UX_GUIDELINES.md` defers list density "to whichever module first demonstrates a real need (most likely a long Staff Directory)". The company is about 100 people (`Staff` model note), so standard comfortable Material density is sufficient.

## 4. Product Decisions (specification review)

The product owner approved every recommendation from revision 1 as written (2026-10-09). The rejected alternatives are listed so the reasoning is preserved.

| # | Decision | Treatment in this specification | Not chosen |
|---|---|---|---|
| **R-1** | The mobile directory shows **`active`** staff only. | The app always sends `status=active`. The API is unchanged: its company-wide visibility (DEC-030) stays as Phase 7 decided (§14). | Active + inactive; an API restriction for non-Administrators (a DEC-030 change). |
| **R-2** | **Yes**, a new self-scoped **`GET /api/v1/me/profile`**. | §6.1. No permission, no Staff required; `staff: null` when unlinked (the `/me/home` precedent, §3.2). | Chaining `/me/home` → `GET /staff/{public_id}`. |
| **R-3** | Email and phone are **displayed with a Copy button** (Flutter SDK `Clipboard`). | §7/§8. **No new `pubspec` dependency.** | Tap-to-call / tap-to-email via `url_launcher`. |
| **R-4** | **No** operational status in the directory. | Returned by the API but not parsed or rendered. Phase 30 owns status. | A passive label on the detail screen. |
| **R-5** | **List row:** display name; position · department. **Detail:** display name (full name if different), position, department, team, manager, company email, company phone. | Employee number, hire/separation dates, status and `has_user_account` are not shown for colleagues. My profile shows the person's **own** employee number and hire date (§8). | Showing a colleague's employee number. |
| **R-6** | **Yes**, a deterministic `/staff` page order. | §6.2: `->orderBy('id')` appended in `StaffController::index`, with a regression test. The visible order is unchanged. `StaffDirectoryReportController` (Phase 20) has the same `last_name, first_name` ordering; fixing it too is optional, since it is an admin report and not used by mobile. | Fetching everything in one large page. |
| **R-7** | **Yes**, tapping the manager on a detail screen opens the manager's entry. | §7/§8. A push within the `/more` branch stack. | The manager as plain text. |
| **R-8** | `More` contains only *My profile* and *Staff directory*. | §8. No "coming soon" rows; each later phase adds its own row. Logout stays in the Home app bar (unchanged). | Disabled future rows; Logout in `More`. |

## 5. In Scope

- **Backend:**
  - `GET /api/v1/me/profile` (R-2);
  - the `StaffController::index` order tie-breaker (R-6);
  - feature tests for both.

  No migration, no permission and no change to `StaffResource`'s fields.
- **Mobile:**
  - the `More` menu (R-8);
  - the My profile screen;
  - the Staff Directory list (search, paginated "load more", pull-to-refresh) and the Staff detail screen;
  - typed models and API clients going through the existing `ApiClient`;
  - widget, controller and router tests.

## 6. Backend / API

### 6.1 `GET /api/v1/me/profile` (R-2)

- **Route:** `Route::get('me/profile', [MyProfileController::class, 'show'])->name('me.profile')` in the `auth:sanctum` + `account.active` group, with no `can:` middleware. Controller in `App\Http\Controllers\Api\V1\Profile\` (or `…\Staff\`, matching existing layout at implementation).
- **Request:** takes no parameters; any query string is ignored. The subject comes only from the token, so it cannot be pointed at another person.
- **Response `200`:**

```json
{
  "data": {
    "user": { "public_id": "…", "name": "…", "email": "…", "role": "staff" },
    "staff": { "…": "StaffResource fields, exactly as GET /staff/{id} returns them" }
  }
}
```

- **`staff`:**
  - the person's own linked record through the existing `StaffResource`, with the same eager loads as `StaffController` (one shape for the client to parse);
  - `null` when no Staff record is linked (for example the seeded Administrator). Never zero-filled or fabricated.
- **The `user` block on `StaffResource`** (Administrator-only) appears exactly as it does today. No new exposure.
- **`user`:** the same safe fields as `UserResource`, minus `status`. An active session implies `active`. The email is the person's **own** login email, which is safe to show them.
- **Errors:** `401` without or with an invalid token; `403` for a suspended or inactive account (the `account.active` behaviour). There is no `404` and no `422`.
- **Constant query count** (user + role, staff + its eager loads).

### 6.2 `GET /api/v1/staff` (R-6)

- **Change:** append `->orderBy('id')` after `orderBy('last_name')->orderBy('first_name')`.
- **Unchanged:** the response shape, filters, authorization, defaults and visible order for distinct names.
- **Regression test:** with 3 identically named records across `per_page=1`, the 3 pages give 3 distinct `public_id`s.

### 6.3 Explicitly unchanged

- `StaffResource` fields and its manage-only `user` split.
- `staff.view` / `staff.manage` grants and DEC-030 directory visibility (R-1).
- All write endpoints, `/auth/me`, `/me/home`, and the Phase 9 status/check-in endpoints.
- The cross-cutting `per_page` cap and `q` wildcard escaping (§3.3-2/3), recorded for Phase 36.

## 7. Mobile Changes

**New folder `lib/features/people/`** with `domain/`, `data/`, `state/` and `presentation/`, the same layering as `features/home`:

- **`domain/`:**
  - `StaffMember` (directory shape) and `MyProfile` (`user` + nullable `staff`);
  - strict parsing as in Phase 27: required fields must be present, nullable stays null, and an unexpected shape becomes the error state.
- **`data/PeopleApiClient`:**
  - `fetchMyProfile()` → `/me/profile`;
  - `fetchDirectoryPage({String? query, int page})` → `/staff?status=active&per_page=25&page=N[&q=…]`, built with `Uri(queryParameters:)`;
  - `fetchStaff(publicId)` → `/staff/{publicId}`;
  - all through `ApiClient`, with no session logic of its own.
- **`state/`:**
  - `MyProfileController` and `StaffDetailController`: loading / loaded / error, and a refresh that keeps content (the Phase 27 `HomeController` pattern);
  - `StaffDirectoryController`: query, items, `hasMore` (from `meta.current_page < meta.last_page`), `loadingMore`, and one request in flight. A new query cancels or ignores stale responses: a response for an old query or page is dropped. Search is debounced by about 300 ms; an empty query lists everyone active.
- **`presentation/`:** `MorePage` (R-8), `MyProfilePage`, `StaffDirectoryPage`, `StaffDetailPage`.
- **Router:** `/more` → `MorePage`, with child routes `/more/profile`, `/more/directory` and `/more/directory/:publicId`, all inside the existing `/more` `StatefulShellBranch`, so the tab keeps its own stack (Phase 25).
- **API client injection:** `PeopleApiClient` is injected into `buildAppRouter` the same way as `HomeApiClient`, which is the test seam.
- **Session rule:** reused unchanged. A `401` anywhere ends the session and returns to Login with the notice. A `403` (for example a no-role user opening the directory) keeps the session and shows a "You don't have access to the staff directory" error state, not a sign-out.
- **No new dependency** (R-3). `MorePlaceholderPage` is removed, and its router test is updated.

## 8. UX Behavior

- **More:**
  - a list with two rows: *My profile* (subtitle: own display name, once known) and *Staff directory*;
  - standard Material `ListTile`s with chevrons. These **are** tappable; the Phase 27 non-interactivity rule applied to Home only.
- **My profile:**
  - a header with display name, then position · department and team;
  - a **Work** section: manager, company email, company phone;
  - an **Employment** section: employee number, hire date (the person's own data);
  - an **Account** section: login email, role (shown as "Staff", "Manager" or "Administrator");
  - a no-profile variant: "No staff profile is linked to this account." plus the Account section only;
  - missing values show "Not set", never blank;
  - the profile is read-only, with an "To change these details, contact an administrator." line (no edit control).
- **Staff directory:**
  - a search field at the top ("Search by name");
  - the list: display name (title); position · department (subtitle, omitted parts skipped);
  - "Load more" happens automatically near the end of the list, with a footer spinner;
  - pull-to-refresh reloads page 1 for the current query;
  - empty states: "No active staff yet." (no query) and "No one matches "{q}"." (with a query);
  - an initial-load error shows the error state with "Try again"; a load-more error shows an inline "Couldn't load more. Tap to retry." row.
- **Staff detail:**
  - a header with display name, plus the full name when the preferred name differs;
  - position, department, team, and manager (tappable when present, R-7);
  - company email and phone, each with a copy button; the snackbar says "Copied";
  - "Not set" for missing values.
- **Resilience:** light/dark theme, 200% text scale, and phone and tablet widths. No overflow, and every interactive element is at least 48 dp (DEC-046).

## 9. Data / Authorization Rules (summary)

| Surface | Who | What they see |
|---|---|---|
| `/me/profile` | Any active authenticated user | Only their own user and staff record. `staff: null` if unlinked. |
| Directory list/detail | `staff.view` holders (Admin/Manager/Staff) | Every Staff record the API already returns. The app requests `active` only (R-1). |
| Directory | No-role user | `403` → an access-denied state; the session is kept. |

No role widens or narrows any server-side visibility relative to today.

## 10. Performance

- `/staff` already eager-loads, so a 25-row page is a constant number of queries.
- The roughly 100-person company means about 4 pages in total.
- `/me/profile` runs a constant 2–3 queries.
- No caching, offline storage or local database in Phase 28.

## 11. Automated Tests

**Backend** (`tests/Feature/Api/V1/Profile/MyProfileTest.php`, plus additions to `StaffTest`):
- `/me/profile` for a linked Staff user, Manager and Administrator: the person's own data only;
- an unlinked Administrator gets `staff: null` with `200`;
- query parameters are ignored (cannot address another person);
- `401` unauthenticated; `403` suspended;
- shape parity with `GET /staff/{id}`;
- the Administrator-only `user` block appears exactly as on `/staff/{id}`;
- a constant query count;
- the R-6 order-determinism regression;
- the full suite plus Pint, PHPStan level 5 and `composer audit`.

**Flutter:**
- **Parsing:** the strict model (null vs missing, unexpected shapes).
- **`PeopleApiClient`:** paths and query encoding, including a search term with spaces, `&` and non-ASCII characters.
- **Controllers:** pagination (`hasMore`, load more, no duplicate fetch while in flight); stale-response dropping on a query change; debounce; refresh keeps content; error then retry.
- **Widgets:**
  - More rows navigate correctly;
  - every Profile state (linked, no-profile, "Not set", error/retry);
  - the directory list, both empty states, the load-more error row and search;
  - detail with copy-to-clipboard and manager navigation;
  - 401 → Login; 403 → the access-denied state with the session kept;
  - light/dark × 100%/200% × phone/tablet with no overflow (using the `scrollUntilVisible` pattern from PR #45, with fatal hit-test warnings).
- **Router:** the `/more` sub-routes keep the tab stack across tab switches.
- Plus the standing format, analyze and test gates.

## 12. UAT Scenarios

To be added to `docs/testing/UAT_LOG.md` at implementation. **All `NOT RUN`.** An AI session never marks PASS.

| ID | Scenario | Status |
|---|---|---|
| UAT-28-01 | As a Staff user with a linked profile, open More → My profile. Name, position, department, team, manager, company contact details, employee number, hire date, login email and role are correct, and there is no edit control. | NOT RUN |
| UAT-28-02 | As an Administrator **without** a linked profile, My profile shows the "no staff profile" message and the account details, with no error or crash. | NOT RUN |
| UAT-28-03 | Staff directory lists active colleagues alphabetically by last name. Searching by first, last or preferred name narrows the list. A query with no matches shows the no-results state. Separated and inactive staff do not appear (R-1). | NOT RUN |
| UAT-28-04 | With enough staff to need more than one page (seeded), scrolling loads further pages with no duplicates or gaps. Pull-to-refresh works. | NOT RUN |
| UAT-28-05 | Open a colleague: the details are correct, Copy puts the email or phone on the clipboard, and tapping the manager opens the manager's entry. Back navigation and switching tabs keep the More stack. | NOT RUN |
| UAT-28-06 | In airplane mode, the directory and profile show a clear error with "Try again" and recover after reconnecting. After server-side token revocation, the next load returns to Login with the session-ended notice. | NOT RUN |
| UAT-28-07 | In dark mode and with large system text, More, Profile, Directory and Detail stay legible with no clipped text. | NOT RUN |

UAT data will need its own small, dedicated, reversible seed (for example `UAT28` staff records: some active, one inactive, one separated, a duplicate name pair, enough for at least 2 pages at `per_page=25`). It follows the Phase 27 runbook discipline: dedicated accounts only, no password ever in chat or the repository, and order-sensitive steps called out. Its design is part of implementation, not this draft.

## 13. Acceptance Criteria

- `/me/profile` behaves as §6.1: self-scoped, no permission, no Staff required, no migration. The R-6 tie-breaker is in place. All §11 backend tests pass, with no regression in Phases 1–27.
- More, Profile, Directory and Detail render §8. The session rule is reused unchanged. There is no new `pubspec` dependency (R-3). Phase 25/27 behavior is intact.
- All `CLAUDE.md` §5 commands pass locally and in CI.
- UAT-28-01…07 are recorded as `NOT RUN`, and §16 documentation is complete.

## 14. Risks / Dependencies

- **R-1 trade-off (accepted):** with the client-side `status=active`, inactive and separated records remain reachable through the API by any `staff.view` holder (as today). This is an existing Phase 7 decision, not a Phase 28 regression. Narrowing it later would be a DEC-030 revision.
- **Search semantics** are the existing `LIKE` match. There is no fuzzy or accent-insensitive search; that depends on the MySQL collation and is acceptable at this scale.
- **Staging data:** directory UAT shows every active staging staff member, including the UAT27 records. That is acceptable (they are test data) but should be expected.
- **Phase 27 carry-forwards** are unchanged and none blocks Phase 28: the offline-launch sign-out, `ApiClient` charset, Android debug signing, and the `mobile` app label.

## 15. Rollback Considerations

- **Backend:** additive (one route, one controller) plus one `ORDER BY` column. Revert and redeploy; there is no data change.
- **Mobile:** reverting restores `MorePlaceholderPage`. There is no persisted data and no token format change.
- **Mixed versions:** an old app ignores `/me/profile`. A new app against an old API gets a `404` on profile, shown as a retryable error, while the directory still works. Deploy the API first.

## 16. Documentation / Handoff Requirements (at implementation)

- `docs/CURRENT_STATE.md` and `docs/CHANGELOG.md`.
- `docs/04_API_CONVENTIONS.md`: `GET /me/profile`, and the `/staff` deterministic order.
- `docs/05_SECURITY_MODEL.md`: profile self-scope (and the R-1 outcome).
- `docs/02_ARCHITECTURE.md`: a People module section.
- `docs/06_UI_UX_GUIDELINES.md`: More, Profile and Directory implemented, plus the list/pagination conventions this phase establishes.
- `docs/DECISIONS.md`: a new DEC entry for the R-1…R-8 outcomes (at minimum `/me/profile` and the directory population rule).
- `docs/ROADMAP.md` at closure.
- `docs/testing/TEST_STATUS.md` and `docs/testing/UAT_LOG.md` (UAT-28-01…07 `NOT RUN`).
- `docs/handoffs/V1_PHASE_28_HANDOFF.md`, separating Implemented / Tested automatically / Manually verified / Awaiting UAT.

## 17. Definition of Done

- All §13 criteria are met and verified by the standing commands.
- §16 documentation and the handoff are complete.
- Merged to `main` only with product-owner approval.
- The session then **stops** (`CLAUDE.md` §8). No Phase 29 work begins without authorization.

## Proposed Implementation Sequence (once authorized; gated like Phase 27)

1. **Gate 1, backend:** `/me/profile` plus tests; the R-6 tie-breaker plus a regression test; the full backend gates.
2. **Gate 2, mobile data/state:** models, `PeopleApiClient`, the three controllers, with tests.
3. **Gate 3, mobile UI:** `MorePage`, Profile, Directory, Detail and router sub-routes, with widget and router tests; the full Flutter gates.
4. **Gate 4:** final integration review, docs, the DEC entry, UAT rows, the handoff and the PR. Do not merge without approval.

## Notes

- **This document is a specification, not an authorization.**
- Revision 1 (2026-10-09) proposed R-1…R-8 as open recommendations; revision 2 records them as approved without change. In §5–§17, the only changes from revision 1 remove references to the alternatives that were not chosen.
- No database migration is expected.
