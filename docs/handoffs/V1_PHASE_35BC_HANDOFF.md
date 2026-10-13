# Phases 35B and 35C — Admin Backoffice Foundation and Roles & Permissions — Handoff

**Status: merged (PR #119, `680444e`, 2026-10-13); awaiting staging deployment and UAT.** One UAT covers both, UAT-35BC-01…08, all `NOT RUN`. With them, Phase 35 (35A–35C) is implemented.

## 1. Phase Identification

- **Phases:** 35B, the Admin Backoffice Foundation, and 35C, the Roles & permissions screen (DEC-065).
- **Date:** 2026-10-13
- **Authorization:**
  - "proceed with 35B";
  - then "approve as recommended, and also can you just finish phase 35 in one go and just do that UAT at the end".

  B-1…B-6 are as recommended. 35C follows P-1…P-7 (approved earlier) and B-5.
- **Specifications:**
  - `docs/phases/V1_PHASE_35B_DEFINITION.md` (§9 as implemented);
  - `docs/phases/V1_PERMISSIONS_PROPOSAL.md` §5 and §12.
- **Branch:** `claude/amazing-brahmagupta-dbsrjc`, from `main` at `205b70a`.

## 2. Objective

1. Turn the Admin Backoffice placeholder into a real shell with a working Dashboard.
2. Let an Administrator choose what Managers and Staff can view, add, edit and delete.
3. Let an Administrator change a person's role.
4. Let an Administrator see the audit log.

## 3. Scope Implemented

Everything in 35B §3 and 35C (proposal §5, §12).

**Not included:**
- management screens (Phase 36);
- custom roles;
- per-person permissions;
- the eight Administrator-only rules (P-4);
- new app or portal screens (P-6).

## 4. Implementation Summary

- **35B, the shell:**
  - `/admin` (`/home` redirects to it);
  - `components/layouts/admin.blade.php`: a sidebar, and a drawer on phones;
  - `App\Support\Admin\Sections`, with a permission-aware list of sections and items;
  - `admin.section` pages;
  - the Dashboard (`App\Livewire\Admin\Dashboard`, from `GET /api/v1/dashboard`, with a period);
  - the portal's Admin link points to `/admin`, and the Backoffice has a Staff portal link;
  - Livewire navigation now also covers `/admin`;
  - new icons: dashboard, chart, menu, close, portal.
- **35C, the API:**
  - `GET /roles`, `PUT /roles/{role}/permissions`, `GET /users` and `PUT /users/{user}/role`;
  - `App\Support\Authorization\PermissionCatalog`;
  - audit actions `role.permissions_updated` and `user.role_changed`.
- **35C, the screens:**
  - **Roles & permissions:** the ticking rule, a Save dialog listing every change, Undo, and Reset to defaults;
  - **Users and roles:** search, and a confirmed role change;
  - **Audit log:** filter and details.
- **Removed:** `resources/views/home.blade.php` and `components/layouts/app.blade.php` (the placeholder and its layout, no longer used).

## 5. Files Changed

**New:**
- `app/Livewire/Admin/{Dashboard,Roles,Users,AuditLog}.php`;
- `app/Http/Controllers/Api/V1/Authorization/{RoleController,UserRoleController}.php`;
- `app/Support/Admin/Sections.php`, `app/Support/Authorization/PermissionCatalog.php`;
- `resources/views/components/layouts/admin.blade.php`;
- `resources/views/admin/{page,section}.blade.php`, `admin/partials/{nav,account}.blade.php`;
- `resources/views/livewire/admin/*` (4 views);
- `tests/Feature/Admin/AdminBackofficePagesTest.php`, `tests/Feature/Api/V1/Authorization/RolesAndUsersApiTest.php`;
- this handoff.

**Changed:**
- `routes/web.php`, `routes/api/v1.php`;
- `app/Support/Audit/AuditActions.php`;
- the portal layout and More (the Admin link), and `components/portal/icon`;
- `resources/css/app.css`, `resources/js/app.js`;
- `tests/Feature/Auth/AdminLoginTest.php`, `tests/Feature/Authorization/AdminBackofficeAuthorizationTest.php` (`/home` → `/admin`).

**Docs:**
- DECISIONS (DEC-065 addendum);
- both specifications;
- 02 (§45), 04, 05, 06;
- ROADMAP, CURRENT_STATE, CHANGELOG, TEST_STATUS, UAT_LOG.

## 6. Database/Schema Changes

None. No migration (still 46).

## 7. API Changes

**Four new endpoints** behind `can:authorization.manage` (see 04). Nothing existing changed.

## 8. Authorization/Security Changes

- **`authorization.manage`** is now used, by the four endpoints and the Roles and Users pages. It stays locked to Administrator.
- **The server refuses:**
  - editing Administrator;
  - locked or unknown names;
  - a write without its view;
  - changing your own role;
  - removing the last Administrator.
- **Every change is audited.** See `05_SECURITY_MODEL.md`.

## 9. Tests Added or Changed

- **`RolesAndUsersApiTest`** (11 tests):
  - the catalog covers every permission once, and its defaults equal the seeder and follow the view rule;
  - `normalize()`;
  - only Administrators may use the API;
  - locked permissions can't be granted;
  - listing roles and the catalog;
  - saving, with the change taking effect and audited once, and no entry for no change;
  - a write needs its view;
  - Administrator, unknown names and bad bodies are refused;
  - the users list and search (`%` literal);
  - changing a role, audited;
  - own role, and the last-Administrator guard.
- **`AdminBackofficePagesTest`** (17 tests):
  - access for guests, Manager, Staff and Administrator, and an unknown section `404`;
  - the `/home` redirect, and the links both ways;
  - navigation order and the current section;
  - permission-aware sections;
  - section pages;
  - the Dashboard's figures and period, and an invalid period;
  - Roles: modules, notes, locked permissions not tickable, the ticking rule both ways, Save with its listed changes (audited `web`, takes effect), Undo, Reset to defaults, a refused save;
  - Users: list, search, change, your own role;
  - Audit log: entries and the filter, and Administrator-only;
  - Roles and Users need `authorization.manage` as well as `admin.access`.
- **Changed:** 2 tests now use `/admin` instead of `/home`, and 1 new test covers the redirect.

## 10. Commands/Checks Executed

- In `apps/api`:
  - `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`;
  - `php artisan test`;
  - `composer validate --strict`, `composer audit --locked`;
  - `npm run build`, `php artisan view:cache`.
- **Mutation checks:** 11 run.
- **Playwright** with Chromium and axe-core 4.10.2, against `php artisan serve` on a scratch SQLite database:
  - an Administrator at 1280 px and 390 px (then 320 px), light and dark;
  - a Manager.

## 11. Results

- **Backend:** 1,419/1,419 tests pass. Pint, PHPStan, Composer and the build are clean.
- **Browser: 79/79 checks pass.**
  - **Dashboard:** from the portal's Admin link; its figures; the period, an invalid period, This month.
  - **Navigation:** a section without a reload; the current section.
  - **Roles:**
    - ticking Add ticks View;
    - the Save dialog lists both, and Save works;
    - unticking View clears Add; Undo;
    - Reset to defaults lists removals; Escape keeps them unsaved; the reset is saved.
  - **Users:** your own row locked; search; the confirmation names the new role; the role changes.
  - **Audit log:** shows both changes; filters; details.
  - **Links:** Staff portal back.
  - **Phone:** the drawer, with Close focused; a drawer link; no horizontal scroll at 320 px on 6 pages.
  - **Manager:** no Admin link, and `/admin` and Roles are `403`.
  - **Overall:** no JavaScript errors and no browser dialogs.
  - **axe AA: 0 violations** on 18 scans (10 light, 8 dark): Dashboard, the period error, a section, Roles with changes and the Save dialog (light), Roles, the role dialog, Users, the Audit log, the phone drawer.
- **Screenshots reviewed:** the Dashboard, the Save dialog, the phone drawer, and Roles on a phone (dark).
- **Mutations:** 11 run.
  - 10 were caught at first.
  - One survivor exposed a missing test: Roles and Users pages without `authorization.manage` were still refused by the API. The route check now has its own test and is caught.
- **Found and fixed during testing:**
  - **The role-change dialog named the old role** ("Make Lia Ramos Manager?" after choosing Staff). It read the menu's text once instead of the chosen value. It now uses the chosen role.
  - **A flaky test:** a random factory email could match the search, so both test files now use fixed emails.
- **Not run here:** MySQL, the staging deployment, and UAT.

## 12. Deviations from Specification

- **The Dashboard shows every card.** The API returns every section to every caller (zeros where not visible), so "only if the API returns it" always holds. No API change was made.
- **Administration's three screens are live** (built together), not "Coming in 35C".

## 13. Known Issues/Limitations

- Section pages list Phase 36 screens that don't exist yet.
- A permission granted to Staff or Manager shows no new button in the app or portal (P-6).
- The Audit log filters by action only. The API also supports dates, actor and entity, which could be added in Phase 36.

## 14. Manual/UAT Testing Instructions

A runbook will be written after merge: `docs/testing/PHASE_35BC_UAT_PREPARATION.md`. It covers the deploy, with no migration and no data script, and UAT-35BC-01…08.

## 15. Documentation Updated

See §5.

## 16. Recommended Next Step

1. Merge on green CI.
2. Deploy and run UAT-35BC.
3. Close Phase 35.
4. Phase 36 (Business Management Screens) needs its own authorization.

## Addendum — Merge and UAT runbook (2026-10-13)

- **PR #119 merged as `680444e`.** CI was green, including the staging image build.
- **Runbook `docs/testing/PHASE_35BC_UAT_PREPARATION.md`:**
  - the deploy `999380e` → `680444e`, with no migration and no data script;
  - `--force-recreate` for both containers;
  - smoke tests for `/admin`, the `/home` redirect and the new API;
  - UAT-35BC-01…08, including a real permission change (Staff's "View clients and contacts", undone by Reset to defaults) and a role change that is set back.
- **Not run:** the deployment and UAT.
