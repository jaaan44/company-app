# Phase 34C — Staff Web Portal: HR & Operations — Handoff

**Status: implemented (2026-10-13), awaiting merge, staging deployment and UAT.** UAT-34C-01…07 are `NOT RUN`. 34D–34E are not authorized.

## 1. Phase Identification

- **Phase:** 34C — Staff Web Portal, HR & Operations (the third sub-phase of Phase 34, DEC-063).
- **Date:** 2026-10-13
- **Authorization:** "proceed with 34C" (product owner).
- **Specification:** `docs/phases/V1_PHASE_34_DEFINITION.md` §5 and §13.
- **Branch:** `claude/amazing-brahmagupta-dbsrjc`, from `main` at `5a552d2` (34B closed).

## 2. Objective

Bring the mobile app's Leave (Phase 31) and My status (Phase 30B) screens to the browser, including a manager's Team requests.

## 3. Scope Implemented

Everything listed for 34C in §5 and §13 of the specification. Nothing from 34D–34E.

## 4. Implementation Summary

**New Livewire components** in `App\Livewire\Portal`:
- `Leave`, `LeaveForm`, `LeaveRequestShow` (mine or a team request, by a locked `viewer`), `TeamLeave`;
- `MyStatus`, `StatusHistory` (status changes or check-ins, by a locked `kind`).

Each calls only `/api/v1`, through `InternalApi`, with the mobile app's page sizes (20) and parameters (`status` for team requests).

**Key behavior:**
- **The Team requests entry** shows only when `/me/team-leave-requests` says `can_review` and `direct_reports_count > 0` (R-1), with its pending count.
- **Request leave:**
  - the types are my balance rows, labelled "N days left in YYYY" or "Unpaid";
  - the last day follows the first when it's empty or earlier;
  - the mobile form's checks and messages; the API's `422` errors land on the same fields; a `403`/`409` shows in the API's words at the top;
  - the day count ("N calendar days (weekends included)") and the balance hint (only when the first day is in the balance's year, as on mobile).
- **Every leave decision is confirmed first and done by the API:**
  - Cancel is offered for a pending request, or an approved one whose first day is after the company today (`company_day` from `/me/leave-requests`);
  - Approve and Reject only while pending; Reject needs a reason of at least 3 characters; notes and reasons are capped at 1,000;
  - the confirm panel replaces the buttons inline, focuses its field, and offers Keep it / Back;
  - after a decision the page re-reads the request, so the status, the buttons and the History are the API's.
- **History** reads "Approved by Ben Cruz · Today 09:12" with the note, in company time (`Format::companyTime`).
- **My status:**
  - the change is confirmed by the API before "Status set to X." shows; the current status's button is pressed and disabled;
  - check-ins are read-only (W-7): the place or "No place name", the time and "±N m", the note, and the coordinates to 5 decimals. The page says to check in from the phone.
- **`Format`** gains `companyTime`, `days`, `leaveDates`, `calendarDays`, `balanceHeadline`, `leaveStatus`, `leaveAction` and `operationalStatus`, the same as the mobile helpers.
- **Views:** a shared `x-portal.leave-row`, and two partials for status and check-in rows used by My status and the All pages.
- **Routes:** eight under `/app`: `leave`, `leave/new`, `leave/requests/{id}`, `leave/team`, `leave/team/{id}`, `status`, `status/changes`, `status/check-ins`.
- **Navigation:** Leave and My status, after Clients.

## 5. Files Changed

**New:**
- the 6 components;
- 6 Livewire views, `components/portal/leave-row.blade.php`, and `livewire/portal/partials/{status-rows,check-in-rows}.blade.php`;
- `tests/Feature/Portal/LeaveStatusPagesTest.php`;
- this handoff.

**Changed:**
- `app/Support/Portal/Format.php`;
- `routes/web.php`;
- `components/layouts/portal.blade.php`;
- `tests/Unit/Support/Portal/FormatTest.php`;
- docs: 02, 05, 06; DECISIONS (DEC-063 status line only); the Phase 34 definition; ROADMAP; CURRENT_STATE; CHANGELOG; TEST_STATUS; UAT_LOG; the 34B handoff (a stale status sentence and the leave-link note).

## 6. Database/Schema Changes

None.

## 7. API Changes

None.

## 8. Authorization/Security Changes

None to the rules. The portal now also writes leave requests, cancellations, a reviewer's decisions and my status, through the same endpoints as the mobile app; the API's policies decide who may do what (see `05_SECURITY_MODEL.md`). The portal never calls `POST /me/check-ins` and never asks for the browser's location.

## 9. Tests Added or Changed

- **`LeaveStatusPagesTest`** (23 tests):
  - guests are sent to login, and all four roles open every page; the navigation order;
  - Leave: balances (paid and unpaid), my requests newest first with dates, days and status, and someone else's excluded; empty and no profile; the Team entry for a manager with reports only;
  - the form: every client-side message; the day count and balance hint; a request sent with the right dates and trimmed reason, and the flash; a `409` in the API's words; server `422` errors on the fields;
  - my request: details and Cancel with Keep it, then a reason, persisted; Cancel offered for approved leave only before it starts; someone else's request;
  - Team requests: the filters and direct reports only; nobody to review; Approve with a note and the history; Reject needs a reason; a stale decision refused; a colleague can't open or decide;
  - My status: current status, histories and check-ins in Manila time; empty and no profile; a change persisted and an unknown status ignored; the All pages page by 20;
  - an ended session goes to login.
- **`FormatTest`**: 3 more unit tests, 11 in all.

## 10. Commands/Checks Executed

- In `apps/api`: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test`, and `npm run build`.
- Mutation checks.
- Playwright with Chromium and axe-core 4.10.2, against `php artisan serve` on a scratch SQLite database seeded with leave and status data (a staff member, her manager and a colleague).

## 11. Results

- **Backend:** 1,334/1,334 tests pass; Pint and PHPStan are clean.
- **Browser:** 67/67 checks pass, in light and dark:
  - Leave overview, with no Team entry for the staff member;
  - the form's validation, the last day following the first, the balance hint, and a request sent;
  - Cancel: Keep it, then a reason; focus moves into the field;
  - My status: a change confirmed (`aria-pressed`), both All pages;
  - as the manager: the Team entry and list, Approve with a note ("Approved by …" in the history), and Reject with a too-short reason refused, then accepted;
  - no horizontal scroll at 320 px on all six list and form pages;
  - no JavaScript errors;
  - **axe WCAG 2.x AA: 0 violations** on 13 page states × 2 schemes.
- **Mutations:** 5 run, 4 caught. The survivor removed the web's allow-list of statuses; the API's validation still refused the unknown status, so the web check is for usability only, as intended.
- **Found during testing:**
  - Livewire's test harness rethrows the API's own `422` validation exception instead of returning it, as in 34B, so the form's `422` and `409` paths are tested with a substitute `InternalApi`. On a real server the API answers `422` with field errors (seen in the browser run as well).
  - The browser script first raced the confirm panel (its own "Cancel request" button matched too early). This was a script fix, not a page fix.
- **Not run here:** MySQL, the staging deployment, and UAT. The staging image build runs in CI on the PR.

## 12. Deviations from Specification

None. One correction of an earlier note: the 34B handoff expected 34C to link the Schedule's leave rows. Mobile's Schedule doesn't open leave, so the web keeps them unlinked.

## 13. Known Issues/Limitations

- **Team request pages read the company day** from `/me/team-leave-requests` (only for the history's "Today"/"Yesterday"), as the single request has no `company_day` meta.
- **The history** shows what the API returns. Requests created outside the API, such as seeded test data, may have no "Requested" step.
- **Home's Messages and Notifications tiles aren't links yet**: they arrive in 34D.

## 14. Manual/UAT Testing Instructions

A runbook will be written after merge: `docs/testing/PHASE_34C_UAT_PREPARATION.md`, covering the deploy, any data the scenarios need (reusing the UAT30B accounts, no passwords) and UAT-34C-01…07.

## 15. Documentation Updated

See §5.

## 16. Recommended Next Step

1. Merge on green CI.
2. Deploy to staging and run UAT-34C.
3. Then 34D (Communication) needs its own authorization.
