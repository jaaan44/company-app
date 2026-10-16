# Phase 36A — People & Sign-in Accounts — Handoff

**Status: complete and formally closed (2026-10-16).** Merged (PR #127, `ea77505`), deployed to staging, UAT-36A-01…08 **PASS**. UAT-36A-01…08 are `NOT RUN`. The deploy **includes one migration**.

## 1. Phase Identification

- **Phase:** 36A (DEC-066).
- **Date:** 2026-10-16.
- **Authorization:**
  - "Go ahead i authorize" (Phase 36);
  - "approve as recommended" (M-1…M-9).
- **Specification:** `docs/phases/V1_PHASE_36_DEFINITION.md` (§9 as implemented).
- **Branch:** `claude/amazing-brahmagupta-dbsrjc`, from `main` at `aff74a5`.

## 2. Objective

Let an Administrator run People from the web:
- staff records and the company structure;
- status corrections and check-ins;
- sign-in accounts: create, reset password, suspend.

Every person chooses their own password.

## 3. Scope Implemented

All of 36A in §3 of the specification:
- Staff records;
- departments, teams and positions;
- status correction;
- check-in review and removal;
- sign-in accounts (create, reset password, suspend and reactivate);
- Choose your password;
- Change password.

**Not included** (M-9): the mobile app (it only shows the API's message), email, bulk import, the Phase 37 hardening, and 36B–36D.

## 4. Implementation Summary

See the specification §9 and `02_ARCHITECTURE.md` §47.
- **API:** four routes, one migration, `Passwords`, and must-change enforced in `EnsureAccountIsActive` and both sign-ins.
- **Admin:** five new Livewire screens, plus Users gaining reset and suspend.
- **Account:** one component used for both Choose your password and Change password.
- **The temporary password** never enters the session, a URL, the logs, the audit log or Livewire's snapshot.

## 5. Files Changed

**New:**
- `database/migrations/2026_10_16_000000_add_must_change_password_to_users_table.php`;
- `app/Support/Auth/Passwords.php`;
- `app/Http/Controllers/Api/V1/Authorization/AccountController.php`, `.../Authorization/Concerns/PresentsAccounts.php`;
- `app/Http/Controllers/Api/V1/Account/MyPasswordController.php`;
- `app/Livewire/Admin/{StaffList,StaffForm,StaffRecord,Organization,AccountForm}.php` and their views;
- `resources/views/livewire/admin/partials/temporary-password.blade.php`;
- `app/Livewire/Account/ChangePassword.php` with its view, and `resources/views/auth/choose-password.blade.php`;
- tests `tests/Feature/Api/V1/Authorization/AccountsApiTest.php` and `tests/Feature/Admin/PeopleAdminPagesTest.php`;
- this handoff.

**Changed:**
- `User` (the cast) and `AuditActions` (4 actions);
- `UserRoleController` (the shared account shape: `must_change_password`, `staff`);
- `AuthController` and `LoginForm` (must-change), and `EnsureAccountIsActive`;
- `Livewire/Admin/Users` and its view;
- `Support/Admin/Sections` (live links);
- `routes/api/v1.php` and `routes/web.php`;
- the portal profile view (Change password), with `PortalPagesTest`'s wording updated.

**Docs:**
- DECISIONS (DEC-066) and the Phase 36 definition;
- 02 (§47), 04, 05 and 06;
- ROADMAP, CURRENT_STATE, CHANGELOG, TEST_STATUS and UAT_LOG;
- also, two leftover "NOT RUN" sentences in the 35BC and 35D handoff status lines were fixed.

## 6. Database/Schema Changes

`users.must_change_password` is a `boolean`, default `false`, after `password`. It is additive. Existing accounts are unaffected. `down()` drops it.

## 7. API Changes

Additive (see `04_API_CONVENTIONS.md`):
- `POST /users`;
- `POST /users/{id}/reset-password`;
- `PUT /users/{id}/status`;
- `PUT /me/password`;
- `GET /users` gains `must_change_password` and `staff`;
- `POST /auth/login` refuses must-change accounts with a 422 message.

## 8. Authorization/Security Changes

See `05_SECURITY_MODEL.md` "Passwords".
- **Account management** sits behind the locked `authorization.manage`. It refuses your own account.
- **A must-change account** reaches only "Choose your password".
- **Reset and suspend** end every sign-in.
- **Changing your own password** needs the current one and is throttled.
- **Audit:** four new actions, never with a password.

## 9. Tests Added or Changed

- **`AccountsApiTest`** (11 tests):
  - access;
  - create (the temporary password's format, hash only, absent from the audit log and later lists);
  - refusals;
  - the phone sign-in while must-change;
  - every other route refused or redirected;
  - choosing a password, then signing in on the phone;
  - the six password rules;
  - keeping the phone token in use;
  - reset (tokens and database sessions);
  - your own account;
  - suspend and reactivate.
- **`PeopleAdminPagesTest`** (13 tests):
  - access and the section's links;
  - the staff list;
  - new staff (messages, manager search) and edit (with the API's field message);
  - the record page (account, status, check-ins);
  - delete refused, then done;
  - organisation;
  - a new account, with the password absent from the snapshot and gone on the next request;
  - the account form's messages;
  - reset (password absent from the snapshot) and suspend and reactivate;
  - a refused action;
  - the first sign-in flow;
  - Change password, with its refusals and the 429 message.
- **`PortalPagesTest`:** the profile's wording.

## 10. Commands/Checks Executed

- In `apps/api`: Pint, PHPStan, `php artisan test` and `npm run build`.
- **Mutation checks:** 12 run.
- **Playwright** (Chromium, axe-core 4.10.2) on scratch SQLite:
  - 390 px, light;
  - 1280 px, dark;
  - 320 px;
  - a Manager.

## 11. Results

- **Backend:** 1,455/1,455 tests pass. Pint and PHPStan are clean.
- **Browser: 71/71 checks pass.** For each run:
  - the People section and the staff list;
  - form messages; a new record; edit;
  - creating its account, with the password shown and absent from every `wire:snapshot`;
  - suspend and reactivate;
  - the new person's first sign-in (portal locked, a short password refused, then the portal);
  - Change password;
  - correcting a status; removing a check-in; a refused delete;
  - organisation;
  - no horizontal scroll at 390 and 320 px;
  - no JS errors or browser dialogs;
  - a Manager refused.
  - **axe AA: 0 violations.**
- **Screenshot reviewed:** organisation on a phone.
- **Mutations:** 12/12 caught.
- **Found and fixed:**
  - the staff status and check-in lists have no company day, so the record page uses the company timezone;
  - an unreachable "last active Administrator" check was removed;
  - `wire:navigate` was dropped, because `app.js` already handles navigation.
- **Not run here:** MySQL, staging and UAT.

## 12. Deviations from Specification

None. M-8's maximum (72 bytes, bcrypt's limit) is stated in DEC-066.

## 13. Known Issues/Limitations

- **Changing your password on the web signs out the phone app** (it ends the other sign-ins, by design). The UAT says so.
- **Linking an *existing* account to a staff record** has no screen. A new account can be created for the record, and the API's `user_id` on PATCH /staff remains.
- **A department's teams and positions** are listed on their own tabs, not under the department.
- **Inactive** (as opposed to suspended) accounts can only be reactivated, not set from the screen.

## 14. Manual/UAT Testing Instructions

A runbook will be written after merge: `docs/testing/PHASE_36A_UAT_PREPARATION.md`. It will cover the deploy, **with `migrate --force`**, and UAT-36A-01…08.

## 15. Documentation Updated

See §5.

## 16. Recommended Next Step

1. Merge on green CI.
2. Write the runbook.
3. Deploy and run UAT-36A.
4. Then 36B–36D in one go (M-2).

## Addendum — Merge and UAT runbook (2026-10-16)

- **PR #127 merged as `ea77505`.** CI was green.
- **Runbook `docs/testing/PHASE_36A_UAT_PREPARATION.md`:**
  - the deploy `2ca6092` → `ea77505`: backup, build, **`migrate --force`**, caches, and smoke tests including "accounts that must change: 0";
  - UAT-36A-01…08 on screens, with a test person `[UAT36A] Nina Novak` (`uat36a.nina@company-app.test`) created during UAT;
  - her temporary and chosen passwords go only into the password manager and the sign-in fields.
- **Not run:** the deployment and UAT.

## Addendum — Deployment, UAT and Formal Closure (2026-10-16)

- **Deployed:** staging now runs `ea77505`. The output was pasted:
  - the fast-forward started from `2ca6092`, which also confirms the 35D deploy;
  - `BUILD OK`;
  - the migration showed `Pending`, then `DONE` on MySQL;
  - the smoke tests were as expected, including `accounts that must change: 0`.
- **Confirmed later by a re-run of the runbook (13:08 UTC):** a pre-migration backup (`company-app-20261016-113122.sql`) exists. The re-run's own backup has `-- Dump completed`. The migration shows `Ran` (`Nothing to migrate`), the three caches succeeded, the containers are `Up`, and the smoke tests passed, with `accounts that must change: 0` after UAT.
- **UAT-36A-01…08: PASS**, reported by the product owner ("all passed"). Recorded by the AI on that report.
- **36A is formally closed.** The `[UAT36A]` records and Nina's account stay on staging; cleaning them up needs its own authorization.
- **Next (M-2):** 36B HR, 36C Work & Operations and 36D Reports, in one go with one UAT.
