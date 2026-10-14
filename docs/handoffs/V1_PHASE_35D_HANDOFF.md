# Phase 35D — Add, Edit and Delete in the Staff Portal — Handoff

**Status: implemented and tested (2026-10-14); awaiting merge, staging deployment and UAT.** UAT-35D-01…08 are `NOT RUN`. The deploy also ships the Roles screen note (PR #121).

## 1. Phase Identification

- **Phase:** 35D (DEC-065 addendum).
- **Dates:** 2026-10-14
- **Authorization:** the UAT-35BC finding, then:
  - "i go with your recommendation" (option A, web portal);
  - "approve as recommended" (D-1…D-8).
- **Specification:** `docs/phases/V1_PHASE_35D_DEFINITION.md` (§9 as implemented).
- **Branch:** `claude/amazing-brahmagupta-dbsrjc`, from `main` at `6a4635e`.

## 2. Objective

Let anyone whose role holds an add, edit or delete permission (from 35C) use it in the staff web portal.

## 3. Scope Implemented

All of §3 of the specification:
- tasks;
- projects, with members and milestones;
- clients, with contacts;
- announcements;
- Mine/All lists.

**Not included:** staff records (Phase 36), the mobile app (option A), and any API change.

## 4. Implementation Summary

See the specification §9 and `02_ARCHITECTURE.md` §46.
- **Seven new Livewire components** and seven changed ones.
- **`Concerns\WritesThroughApi`:** `may()` decides what is shown; the API decides what is done.
- **A shared field partial.**
- **Ten routes,** with ids limited to letters and digits.

## 5. Files Changed

**New:**
- `app/Livewire/Portal/{TaskForm,ProjectForm,ClientForm,ContactForm,AnnouncementsManage,AnnouncementManage,AnnouncementForm}.php` and their 7 views;
- `app/Livewire/Portal/Concerns/WritesThroughApi.php`;
- `resources/views/livewire/portal/partials/field.blade.php`;
- `tests/Feature/Portal/ManagePagesTest.php`;
- this handoff.

**Changed:**
- `Tasks`, `TaskShow`, `Projects`, `ProjectShow`, `Clients`, `ClientShow` and `Announcements`, with their views;
- the portal layout (the Tasks tab pattern);
- `routes/web.php`.

**Docs:**
- DECISIONS (addendum);
- the 35D specification;
- 02 (§46), 05, 06;
- ROADMAP, CURRENT_STATE, CHANGELOG, TEST_STATUS, UAT_LOG.

## 6. Database/Schema Changes

None.

## 7. API Changes

None.

## 8. Authorization/Security Changes

None to the rules. The buttons follow permissions, and the API decides (see `05_SECURITY_MODEL.md`).

## 9. Tests Added or Changed

**`ManagePagesTest`** (12 tests):
- **Defaults:** with them, Staff see no new buttons on 7 pages.
- **Tasks:**
  - the buttons and the All tab with permissions;
  - New task with a project and an assignee (the API's "assignee must be a member" rule), then edit (assignee cleared) and delete;
  - a refused delete shows the API's message;
  - a lead adds and edits only in their own project, with no Delete and no Add a member.
- **Projects:**
  - new (completed needs a date), edit, delete, and the All tab;
  - Edit without members management;
  - members and milestones.
- **Clients:** new, edit, contacts (add, edit, inactive, remove), delete; another client's contact is not found.
- **Announcements:** a scoped draft (an audience is required), edit, publish, archive, delete; with publish only, no Edit or Delete.
- **Refusals:** a refused create shows the API's message.

## 10. Commands/Checks Executed

- In `apps/api`: Pint, PHPStan, `php artisan test`, `npm run build` and `php artisan view:cache`.
- **Mutation checks:** 12 run.
- **Playwright** (Chromium, axe-core 4.10.2) on scratch SQLite, with Staff given the permissions:
  - 390 px, light;
  - 1280 px, dark;
  - a Manager with the defaults.

## 11. Results

- **Backend:** 1,431/1,431 tests pass. Pint and PHPStan are clean.
- **Browser: 57/57 checks pass.** For each of the two runs:
  - **Tasks:** New and the tabs; All shows a colleague's task; a form message; New task with a project, an assignee and a priority; Edit (with the fixed project); Delete through the dialog.
  - **Projects:**
    - New; a member added as lead, then made a member;
    - a milestone added and completed; a member removed;
    - a refused delete shows "This project still has milestones and cannot be deleted.".
  - **Clients:** form messages; New; a primary contact added and removed; delete.
  - **Announcements:** an audience is required; a draft for Operations; publish; archive.
  - **Layout:** no horizontal scroll on phone forms.
  - **Overall:** no JS errors and no browser dialogs.
  - **The Manager:** with the defaults, no new buttons.
  - **axe AA: 0 violations** on 18 scans (9 states × 2).
- **Screenshot reviewed:** the project page on a phone.
- **Mutations:** 12 run.
  - 11 were caught at first.
  - The survivor ("Add a member" shown to View-only holders) led to a new assertion; it is now caught.
- **Found and fixed by the tests:**
  - **New task crashed** because of a route parameter clash (`project` → `forProject`).
  - **Editing sent `project_id`,** which the API prohibits. The project is now fixed on edit.
- **Not run here:** MySQL, staging, and UAT.

## 12. Deviations from Specification

None.

## 13. Known Issues/Limitations

- Task lists by project or assignee (beyond the search) and bulk actions are not included.
- The mobile app has none of these screens (option A).
- Long lists of projects or clients in the pickers are capped at 500 (10 pages × 50).

## 14. Manual/UAT Testing Instructions

A runbook will be written after merge: `docs/testing/PHASE_35D_UAT_PREPARATION.md`, covering the deploy and UAT-35D-01…08.

## 15. Documentation Updated

See §5.

## 16. Recommended Next Step

1. Merge on green CI.
2. Deploy and run UAT-35D, and finish UAT-35BC.
3. Close Phase 35.
