# Phase 34B — Staff Web Portal: Work — Handoff

**Status: implemented and merged (PR #92, `2a7bca9`); awaiting staging deployment and UAT.** UAT-34B-01…07 are `NOT RUN`. 34C–34E are not authorized.

## 1. Phase Identification

- **Phase:** 34B — Staff Web Portal, Work (the second sub-phase of Phase 34, DEC-063).
- **Date:** 2026-10-13
- **Authorization:** "proceed with 34B" (product owner).
- **Specification:** `docs/phases/V1_PHASE_34_DEFINITION.md` §5 and §12.
- **Branch:** `claude/amazing-brahmagupta-dbsrjc`, from `main` at `ee4896c` (34A closed).

## 2. Objective

Bring the mobile app's Work features to the browser: Tasks, Work logs, Projects, Clients and the Schedule.

## 3. Scope Implemented

Everything listed for 34B in §5 and §12 of the specification. Also: Home's My tasks tile and Today rows now link to their pages, as on mobile. Nothing from 34C–34E.

## 4. Implementation Summary

**New Livewire components** in `App\Livewire\Portal`:
- `Tasks`, `TaskShow`;
- `WorkLogs`, `WorkLogForm`;
- `Projects`, `ProjectShow`;
- `Clients`, `ClientShow`;
- `Schedule`, `ScheduleEntryShow`.

Each calls only `/api/v1`, through `InternalApi`, with the same query parameters as the mobile app (page sizes, `state`, `member`, `status=active`, `q`).

**Key behavior:**
- **Task status changes are confirmed**, not optimistic:
  - the message "Status changed to X." appears only after the API accepts the change;
  - Cancelled is never offered, and a cancelled task is read-only;
  - a `403` shows the API's reason and locks the control; `404` and `422` are handled.
- **The task's due state** is judged against `company_day` from `/me/tasks`, never the server clock. The completed date is shown in the company timezone. This is better than mobile, which used device time.
- **Work-log form:**
  - the client-side checks and messages are those of the mobile form; the API's `422` errors map onto the same fields;
  - create targets a task (`?task=` fixes it) or one of my projects that isn't closed;
  - edit finds the log in my own `/me/work-logs` pages, because the owner can't read `/work-logs/{id}`; it changes only the date, time and description;
  - Delete asks for confirmation;
  - after a save or delete, a message shows on the list.
- **Projects** are always mine (`member=<my staff id>`), for every role (mobile R-20).
- **Clients** are active only. Contacts are active only, and Primary is marked.
- **Schedule:**
  - seven days from the company today (learnt from the API);
  - items placed by the mobile `overlaps` rule;
  - per-day times with "From … / until …" for multi-day items;
  - **the address carries `?from=` only for a week other than the current one**, and "Today" returns to the plain address.
- **`Format`** gains the mobile helpers: due labels, durations, day labels, week ranges, item times, entry "When", priority, project, milestone and role labels, status tones, and address lines.
- **Views:**
  - shared Blade components `chip`, `pager`, `search`, `back`;
  - `portal.page` wraps every 34B page;
  - the navigation always has its own row;
  - new tokens: `danger-soft`, `success`, `success-soft`.
- **Routes:** eleven under `/app`. `Route::pattern('publicId', '[0-9A-Za-z]{1,64}')` covers every portal id.

## 5. Files Changed

**New:**
- the 10 components;
- 10 Livewire views;
- `portal/page.blade.php`;
- `components/portal/{chip,pager,search,back}.blade.php`;
- `tests/Feature/Portal/WorkPagesTest.php`;
- this handoff.

**Changed:**
- `app/Support/Portal/Format.php`;
- `routes/web.php`;
- `components/layouts/portal.blade.php`, `livewire/portal/home.blade.php`;
- `resources/css/app.css`;
- `tests/Unit/Support/Portal/FormatTest.php`;
- docs: 02, 05, 06; the Phase 34 definition; ROADMAP; CURRENT_STATE; CHANGELOG; TEST_STATUS; UAT_LOG.

## 6. Database/Schema Changes

None.

## 7. API Changes

None.

## 8. Authorization/Security Changes

None to the rules. The portal now writes task status and work logs, through the same endpoints as the mobile app (see `05_SECURITY_MODEL.md`). Writes the API audits record `source: web`.

## 9. Tests Added or Changed

- **`WorkPagesTest`** (20 tests):
  - guests are sent to login, and all four roles open every page; the navigation order;
  - Tasks Open and Done with the three due labels, the empty states and no profile;
  - the task detail with a confirmed change persisted, and a request for Cancelled ignored;
  - the cancelled and independent task; a refused change (reassigned meanwhile) is locked; a task not mine, and an unknown task;
  - Work logs grouped by day;
  - logging work on a task, then editing and deleting it;
  - logging work on a project from the picker, with closed and foreign projects excluded;
  - the form's checks, and server `422` errors on the fields; an unknown or foreign log, and no profile;
  - Projects (mine only, search, a manager sees only their own) and a project page (links, members, milestones, `403`, `404`);
  - Clients (active only, search, details, contacts, `404`);
  - the Schedule week (Today, Tomorrow, times in Manila, links, leave, Previous / Next / Today);
  - an entry page, and no profile;
  - Home's links.
- **`FormatTest`**: 4 more unit tests, 8 in all.

## 10. Commands/Checks Executed

- In `apps/api`: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test`, and `npm run build`.
- Mutation checks.
- Playwright with Chromium and axe-core 4.10.2, against `php artisan serve` on a scratch SQLite database seeded with work data.

## 11. Results

- **Backend:** 1,308/1,308 tests pass; Pint and PHPStan are clean.
- **Browser:** 72/72 checks pass, in light and dark:
  - Home tile → Tasks → Done tab → task;
  - a status change confirmed (`aria-pressed`);
  - Log work from the task, with client validation shown, then saved;
  - edit, and delete (confirm dialog);
  - project → client;
  - clients paged, then searched (the `q` in the address);
  - schedule Next week (`from=`), and Today (none);
  - an entry;
  - no horizontal scroll at 320 px on all six list and form pages;
  - no JavaScript errors;
  - **axe WCAG 2.x AA: 0 violations** on 10 page states × 2 schemes.
- **Mutations:** 11 run.
  - 10 were caught at first.
  - The survivor (the Home tile's link) exposed a weak assertion that also matched the navigation. It was tightened and now catches the mutation.
- **Found and fixed during testing:**
  - **The Schedule wrote the current week's date into the address on first load**, which made "Today" and the Back button confusing. The current week is now the plain address.
  - **Two Blade views mixed inline `@php(...)` before a multi-line `@php` block**, which Blade compiles wrongly. They now use block form only.
  - **The page wrapper's variable `$component` clashed** with Blade's own component variable. It was renamed.
- **Not run here:** MySQL, the staging deployment, and UAT. The staging image build runs in CI on the PR.

## 12. Deviations from Specification

None. Two small improvements over mobile, both using the API's company day and timezone:
- the task's completed date is shown in the company timezone (mobile uses device time);
- the schedule entry's times use the company offset even when opened directly.

## 13. Known Issues/Limitations

- **The edit page finds a log** by scanning my own work-log pages (at most 2,000 logs), because the API gives the owner no single-log read. Older logs can still be edited from the phone.
- **Leave rows in the Schedule aren't links yet**: Leave arrives in 34C.
- **Home's Messages and Notifications tiles aren't links yet**: they arrive in 34D.

## 14. Manual/UAT Testing Instructions

A runbook will be written after merge: `docs/testing/PHASE_34B_UAT_PREPARATION.md`, covering the deploy and UAT-34B-01…07.

## 15. Documentation Updated

See §5.

## 16. Recommended Next Step

1. Merge on green CI.
2. Deploy to staging and run UAT-34B.
3. Then 34C (HR & Operations) needs its own authorization.

## Addendum — Merge and UAT runbook (2026-10-13)

- **PR #92 merged as `2a7bca9`.** CI was green, including the staging image build.
- **Runbook `docs/testing/PHASE_34B_UAT_PREPARATION.md`:**
  - the deploy `d3cf84d` → `2a7bca9`, with no migration;
  - the data script `uat34b_data.php` (SHA-256 `cef6db23…`), which reuses the UAT30B accounts and makes no password. It seeds a client with contacts, a project with members and milestones, seven tasks for Lia, four work logs and three schedule entries.
  - UAT-34B-01…07.
- **The script was rehearsed on scratch SQLite:** `plan`, `seed` and `verify` gave the expected output; a re-seed is idempotent; a work log made in the app is kept; and task statuses reset.
- **Not run:** the deployment and UAT.

