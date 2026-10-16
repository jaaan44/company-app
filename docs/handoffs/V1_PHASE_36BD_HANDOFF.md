# Phases 36B–36D — HR, Work & Operations, Reports (Admin Backoffice) — Handoff

**Status: complete and formally closed (2026-10-16).** Merged (PR #131, `0797e0c`), deployed to staging, UAT-36BD-01…08 **PASS**. UAT-36BD-01…08 are `NOT RUN`. There is **no migration** and **no API change**.

## 1. Phase Identification

- **Phases:** 36B (HR), 36C (Work & Operations) and 36D (Reports). DEC-066 and its 36B–36D addendum apply.
- **Date:** 2026-10-16.
- **Authorization:**
  - M-2 (approved with M-1…M-9): "36B–36D in one go, with one UAT";
  - then "go".
- **Specification:** `docs/phases/V1_PHASE_36_DEFINITION.md` (§10 as implemented).
- **Branch:** `claude/amazing-brahmagupta-dbsrjc`, from `main` at `b9886dc`.

## 2. Objective

Make every remaining "Coming in Phase 36" Backoffice item a working screen.

## 3. Scope Implemented

All of 36B, 36C and 36D in §3 of the specification:
- **HR:** leave requests, leave types and leave balances;
- **Work & Operations:** work logs, schedule, service reports and incidents, plus links to the portal for projects, tasks, clients and announcements;
- **Reports:** the seven reports with CSV, and the Audit log filters.

**Not included** (M-9): the mobile app, email, bulk import and the Phase 37 hardening.

## 4. Implementation Summary

See §10 of the specification and `02_ARCHITECTURE.md` §48.
- **Screens:** 12 new Livewire screens, plus Audit log filters.
- **Configuration:** one report configuration (`App\Support\Admin\Reports`).
- **Download:** one CSV download controller, which streams the API's own export.
- **Shared pieces:** a staff picker and a files-and-history partial.
- **Section links:** `Sections` items can carry route parameters.

## 5. Files Changed

**New:**
- `app/Livewire/Admin/{LeaveRequests,LeaveRequestRecord,LeaveRequestForm,LeaveTypes,LeaveBalances,WorkLogs,ScheduleEntries,ScheduleEntryForm,OperationsReports,ServiceReportRecord,IncidentRecord,ReportView}.php` and their views;
- `app/Livewire/Admin/Concerns/PicksStaff.php`;
- `resources/views/livewire/admin/partials/{staff-picker,report-files-history}.blade.php`;
- `app/Support/Admin/Reports.php`;
- `app/Http/Controllers/Admin/ReportExportController.php`;
- `tests/Feature/Admin/BusinessAdminPagesTest.php`;
- this handoff.

**Changed:**
- `app/Livewire/Admin/AuditLog.php` and its view (filters);
- `app/Support/Admin/Sections.php` and `resources/views/admin/section.blade.php` (every item live; route parameters);
- `routes/web.php`;
- `tests/Feature/Admin/AdminBackofficePagesTest.php` (no section says "Coming").

**Docs:**
- DECISIONS (a DEC-066 addendum);
- the Phase 36 definition (§10);
- 02 (§48), 04, 05 and 06;
- ROADMAP, CURRENT_STATE, CHANGELOG, TEST_STATUS and UAT_LOG.

## 6. Database/Schema Changes

None.

## 7. API Changes

None. Report CSVs use the existing exports.

## 8. Authorization/Security Changes

None to the rules (see `05_SECURITY_MODEL.md`).
- The Backoffice stays behind `admin.access`.
- Each action is the API's to allow.
- The CSV route accepts only the seven report keys and known filter values.

## 9. Tests Added or Changed

**`BusinessAdminPagesTest` (13 tests):**
- **Access:** 16 pages for an Administrator only; an unknown report gives 404.
- **Leave requests:**
  - the list and its filters;
  - approve with a note; cancel, including a refused cancel; a reject needs a reason;
  - leave for someone.
- **Leave types and balances:**
  - leave types: add, edit, a refused remove, a remove;
  - leave balances: from `?staff=`, a non-number refused, then saved.
- **Work logs:** filter; correct; the API's future-date message; remove.
- **Schedule:** refused without a staff profile; then create in company time with a person; list; edit; delete.
- **Operations:**
  - service reports: list, reject (needs a reason), mark reviewed;
  - incidents: the "Open" list (reported plus under investigation, not closed), then assign (needs a person), investigate, resolve (the API's rule, then success), close and reopen.
- **Reports:** all seven render; filters narrow the rows.
- **CSV:** the export respects the filters and is audited; a Manager gets 403.
- **Audit log:** filters by record, person and date.

**`AdminBackofficePagesTest`:** every section page is live, and the Work section links the portal's All tabs.

## 10. Commands/Checks Executed

- In `apps/api`: Pint, PHPStan, `composer validate`, `composer audit`, `php artisan test` (twice) and `npm run build`.
- **Mutation checks:** 12 run.
- **Playwright** (Chromium, axe-core 4.10.2) on scratch SQLite, with two of each record:
  - 390 px, light;
  - 1280 px, dark;
  - 320 px;
  - a Manager.

## 11. Results

- **Backend:** 1,468/1,468 tests pass, twice. Pint, PHPStan and Composer are clean.
- **Browser: 86/86 checks pass**, with **axe AA: 0 violations**.
- **Screenshot reviewed:** a report table at 320 px.
- **Mutations:** 12/12 caught. One needed an added assertion: "Open" incidents must include those under investigation.
- **Found and fixed:**
  - **Resolving an incident** needs a resolution, and a corrective action unless an immediate action is recorded. The API reports one at a time, so the form now states both.
  - **Filter selects** widened a 320 px page.
  - **The audit log's inline links** were under 24 px tall.
- **Not run here:** MySQL, staging and UAT.

## 12. Deviations from Specification

- **Schedule entries:** "add" works only for an account with a staff profile, because the API makes the creator a staff record. An Administrator without one sees the API's refusal. Editing and deleting work.
- **Audit log:** filters by date, person and record, but not by record type, because its stored values are inconsistent.

## 13. Known Issues/Limitations

- **Incident messages from the API** name fields in their technical form (for example, "corrective_action or immediate_action_taken").
- **"Open" incidents** are two API calls merged. Pages beyond the first combine both lists page by page, which is approximate but complete.
- **The audit log's date filter** uses UTC days, as the API does.
- **Schedule** lists start from a date; there is no calendar view in the Backoffice (the portal has one).
- **The CSV** has the API's columns, which are more than the screen shows.

## 14. Manual/UAT Testing Instructions

A runbook will be written after merge: `docs/testing/PHASE_36BD_UAT_PREPARATION.md`. It will cover the deploy (no migration) and UAT-36BD-01…08.

## 15. Documentation Updated

See §5.

## 16. Recommended Next Step

1. Merge on green CI.
2. Write the runbook.
3. Deploy and run UAT-36BD.
4. Close Phase 36.
5. Then Phase 37 (integration and UX hardening) needs its own authorization.

## Addendum — Merge and UAT runbook (2026-10-16)

- **PR #131 merged as `0797e0c`.** CI was green.
- **Runbook `docs/testing/PHASE_36BD_UAT_PREPARATION.md`:**
  - the deploy `ea77505` → `0797e0c`: no migration; backup, build, caches, smoke tests;
  - a setup step in which Lia creates the `[UAT36BD]` leave requests, work log, service reports and incident in the portal;
  - UAT-36BD-01…08.
- **Not run:** the deployment and UAT.

## Addendum — Deployment, UAT and Formal Closure (2026-10-16)

- **Deployed:** staging runs `0797e0c`. The output was pasted:
  - the fast-forward started from `ea77505`;
  - the backup was complete (`-- Dump completed`);
  - `BUILD OK`, with no migration pending;
  - the caches were built and the containers recreated;
  - the smoke tests were as expected.
- **UAT-36BD-01…08: PASS**, reported by the product owner ("all PASS"). Recorded by the AI on that report.
- **36B–36D are formally closed, and with 36A, Phase 36 is complete.** The `[UAT36BD]` records stay on staging; cleaning them up needs its own authorization.
- **Next:** Phase 37 (Mobile/Web/Admin Integration & UX Hardening) needs its own authorization.

