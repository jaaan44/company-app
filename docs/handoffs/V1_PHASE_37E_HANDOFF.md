# Phase 37E — Employee Cards on Home and in the Staff Directory — Handoff

**Status: merged as `6c7e2cd` (PR #147, 2026-10-24) with its backend CI red on one PHPStan finding (`Directory.php:108`, `identical.alwaysTrue`); fixed in PR #148 (merged as `6f924f2`, CI green, PHPStan included). Awaiting deployment and UAT-37E-01…03 (`NOT RUN`).** **No migration, no new permission, no new dependency.**

## 1. Phase Identification

- **Phase:** 37E (DEC-072).
- **Date:** 2026-10-24.
- **Authorization:** the product owner's brief ("the dashboard i want to focus on putting item cards for each staff… show first 4. try to follow the functionalities of the screens on this reference image"), and their answers:
  - Phase 37E;
  - the first 4 company-wide, A–Z;
  - "create the functions for these" (the missing data);
  - View profile as the card's action.
- **Branch:** `claude/admiring-planck-hictyu`, restarted from `main` at `77aab0f` after PRs #145 and #146 merged.

## 2. Objective

Make Home show the employees as cards (the first four), and make the Staff directory work like the reference's "All Employees", with the data the cards need.

## 3. Scope Implemented

- **API** (`GET /api/v1/staff`):
  - filters `operational_status=` (the latest status) and `on_leave=1|0`, both needing `staff-status.view`;
  - `StaffResource` adds `current_leave` (`{start_date, end_date}`, `staff-status.view` only) and `working_on` (`{public_id, name}` of a project the requester may view);
  - `GET /staff/{id}` and `GET /me/profile` carry the same fields.
- **`x-portal.staff-card`** (a `compact` shape for Home; the row shape for the directory):
  - initials avatar with a status dot, name, role, the status in words or **On leave** with dates;
  - a **Working on** block;
  - the whole card links to the person's page.
- **Home:** **Employees**, right after the quick actions: the first four active staff A–Z as **compact cards side by side**, like the reference's dashboard (product owner, follow-up: "make it scroll sideways like the reference"). On phones the row scrolls sideways inside itself with the next card peeking in; four columns from `md`. "View all (N)". It replaces 37D's My team.
- **Staff directory:**
  - cards;
  - status chips (All, Available, In a meeting, Busy, In the field, Off duty, On leave), with `staff-status.view`;
  - an "All departments" select, with `organization.view`;
  - the filters are in the URL.
- **Colleague's page:** On leave with dates, and a Working on row.

## 4. Implementation Summary

- `Staff::currentLeave()` and `Staff::currentTask()`: `hasOne()->ofMany()` with constraints. They're eager-loaded by `StaffController` (index and show) and `MyProfileController`.
- `StaffResource::mayViewProject()`: Phase 10's rule, with the requester's membership ids looked up once per request.
- `Livewire\Portal\Home::employees()`; `Directory`: `$show`, `$department`, `FILTERS`, `showOnly()` and `departments()`.

## 5. Files Changed

- **New:**
  - `tests/Feature/Api/V1/Staff/StaffDirectoryStatusTest.php`;
  - `resources/views/components/portal/staff-card.blade.php`;
  - `docs/phases/V1_PHASE_37E_DEFINITION.md`;
  - `docs/design/portal-preview-37e-*.webp`;
  - this handoff.
- **Changed:**
  - `app/Models/Staff.php`;
  - `app/Http/Controllers/Api/V1/Staff/StaffController.php`;
  - `app/Http/Controllers/Api/V1/Profile/MyProfileController.php`;
  - `app/Http/Resources/StaffResource.php`;
  - `app/Livewire/Portal/{Home,Directory}.php`;
  - `livewire/portal/{home,directory,staff-show}.blade.php`;
  - `tests/Feature/Portal/PortalPagesTest.php`.
- **Docs:** DECISIONS (DEC-072), 02, 04, 05, 06, ROADMAP, CURRENT_STATE, CHANGELOG, TEST_STATUS, UAT_LOG, `docs/design/README.md`, and the 37D handoff's status.

## 6. Database/Schema Changes

None. The relations read existing `leave_requests` and `tasks` columns.

## 7. API Changes

Additive, as in §3; existing clients ignore the new fields. See `04_API_CONVENTIONS.md` (Phase 37E).

## 8. Authorization/Security Changes

No new permission. Each new value reuses an existing audience (`05_SECURITY_MODEL.md`, "Directory Leave and Project Fields"):
- leave dates and the status filters follow `staff-status.view` (a `403` on a filter without it);
- the leave type and reason are never exposed;
- `working_on` follows Phase 10's project visibility;
- no location.

## 9. Tests Added or Changed

- **`StaffDirectoryStatusTest`:**
  - the status filter uses the latest status;
  - the leave filter and dates cover today only: not pending, not ended, and no reason in the response;
  - a role with only `staff.view` gets `403` on the filters and no `current_leave`;
  - `working_on` only for a visible project (a Manager sees all);
  - the most recently updated in-progress task wins;
  - the list's query count doesn't grow with the number of people.
- **`PortalPagesTest`:**
  - Home's first four (A–Z, View all (7), not the fifth, not separated staff);
  - no Employees without `staff.view`;
  - directory chips, On leave dates and the department filter;
  - Working on on the list and the colleague's page.

  37D's two My team tests are replaced.

## 10. Commands/Checks Executed

- `npm run build`.
- In the scratch copy (lock without PHPStan/Larastan, as in 37D): `php artisan test` and `vendor/bin/pint --test`.
- The running app on SQLite with sample leave and in-progress project work, captured with Playwright at 320, 390 (light and dark) and 1280 px, with an overflow check.

## 11. Results

- **1,514/1,514 tests; Pint passes.**
- The first full run failed one existing test: `MyProfileTest`'s "the staff block is identical to `GET /staff/{id}`". It's fixed by loading the same relations in `/me/profile`, which keeps the invariant rather than weakening it.
- No horizontal overflow at any width.
- **PHPStan:** in CI.

## 12. Deviations

- **No ⋯ menu:** with View profile as the only action, the card itself is the link.
- **No location** on cards (DEC-032), **no "WFH"** (not a status the app has), **no photos**.

## 13. Known Issues/Limitations

- **"Working on"** is derived from the latest in-progress task with a project. Someone working without a task in progress shows nothing.
- **On leave** reflects approved leave only. Pending requests don't show.
- **Home's Employees** may include the person themselves (it's the company list, A–Z, as chosen).

## 14. Manual/UAT Testing Instructions

UAT-37E-01…03 in `docs/testing/UAT_LOG.md` (`NOT RUN`).

## 15. Documentation Updated

See §5.

## 16. Recommended Next Step

1. Review the PR and the previews.
2. Merge on green CI.
3. Deploy, then run UAT-37E (with 37B, 37C and 37D if they're still pending).
