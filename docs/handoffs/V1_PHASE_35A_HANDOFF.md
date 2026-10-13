# Phase 35A — Granular Permissions — Handoff

**Status: implemented and tested (2026-10-13); awaiting merge, staging deployment and UAT.** UAT-35A-01…04 are `NOT RUN`.

## 1. Phase Identification

- **Phase:** 35A, Granular permissions. It is the first of 35A–35C (DEC-065, P-1).
- **Date:** 2026-10-13
- **Authorization:** "approve as recommended" (product owner), for `docs/phases/V1_PERMISSIONS_PROPOSAL.md` with P-1…P-7 as recommended. **Only 35A** is authorized; 35B and 35C need their own.
- **Specification:** `docs/phases/V1_PERMISSIONS_PROPOSAL.md` §4 and §11.
- **Branch:** `claude/amazing-brahmagupta-dbsrjc`, from `main` at `fead2c0`.

## 2. Objective

Split the five Administrator-only `.manage` permissions into separate add / edit / delete permissions, so that a role can later be given one without the others. Nobody's access changes.

## 3. Scope Implemented

**The split** (P-2):
- `staff.*`;
- `clients.*` (contacts follow `clients.update`);
- `projects.*` plus `projects.members` (milestones follow `projects.update`);
- `tasks.*`;
- `announcements.view-all` / `create` / `publish` / `delete`.

The endpoint mapping is in §11 of the specification. Also included: the data migration and tests.

**Not included:** the screen (35C), the Admin Backoffice shell (35B), the eight `hasRole(Administrator)` checks (P-4), and any app or portal screen (P-6).

## 4. Implementation Summary

- **`RolePermissionSeeder`:** the five old entries are replaced by 17 new ones, with plain labels (23 → 35 permissions). The Manager and Staff grants are unchanged.
- **Migration `2026_10_13_000000_split_manage_permissions`** is a data migration in one transaction:
  - each role holding an old permission gets all of its parts, then the old one is deleted;
  - it does nothing on a fresh database;
  - `down()` restores an old permission only to roles holding every part.
- **`routes/api/v1.php`:** a `can:` check per route, as mapped.
- **`AuthorizesTaskAccess`:** `canManageAllTasks` became `canCreateAnyTask` (`tasks.create`) and `canUpdateAnyTask` (`tasks.update`).
- **`AuthorizesMilestoneAccess`:** checks `projects.update`.
- **Resources:**
  - `StaffResource`'s linked `user` block follows `staff.update`;
  - `AnnouncementResource`'s `acknowledgements_count` follows `announcements.view-all`.
- **Comments** naming the old permissions in controllers, requests and routes were updated.

## 5. Files Changed

**New:**
- the migration;
- `tests/Feature/Authorization/GranularPermissionsTest.php`;
- this handoff.

**Changed:**
- `RolePermissionSeeder`, `routes/api/v1.php`;
- `AuthorizesTaskAccess`, `AuthorizesMilestoneAccess`;
- `StaffResource`, `AnnouncementResource`;
- doc comments in 8 controller and 6 request files;
- `tests/Feature/Authorization/RolePermissionSeederTest.php`.

**Docs:**
- DECISIONS (DEC-065);
- the proposal (status and §11);
- 02, 05;
- ROADMAP, CURRENT_STATE, CHANGELOG, TEST_STATUS, UAT_LOG.

## 6. Database/Schema Changes

**No schema change.** One data migration changes rows in `permissions` and `role_permissions`. Migrations: 45 → 46.

## 7. API Changes

**None** to endpoints, requests or responses. Only the permission names behind them changed. The mobile app needs no update.

## 8. Authorization/Security Changes

- The five write permissions are split. The defaults are unchanged, so only Administrator holds them (through `Gate::before`).
- No role gains or loses access: the migration copies any existing grant onto every part.
- See `05_SECURITY_MODEL.md` and DEC-065.

## 9. Tests Added or Changed

- **New `GranularPermissionsTest`** (9 tests). Each test gives a role exactly one new permission and checks it opens only its own endpoints:
  - **tasks:** add, edit and delete;
  - **projects:** add, edit (with milestones), members and delete;
  - **clients:** add, edit (with contacts) and delete;
  - **staff:** add, edit (and the linked account shown) and delete;
  - **announcements:** view-all, draft, publish and archive, and delete.
- **Old names:** the old names are gone.
- **The migration:**
  - moves a grant onto every part and rolls back exactly;
  - on rollback, restores the old permission only to a role holding every part;
  - does nothing on a fresh catalog.
- **Changed:** `RolePermissionSeederTest` now checks each part instead of the old name, and the count is 23 → 35.
- **Unchanged and passing:** every module's `*AuthorizationTest` and every feature test, which together prove the default behaviour is the same.

## 10. Commands/Checks Executed

- In `apps/api`: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test`.
- **Mutation checks:** 10 run.
- **An upgrade rehearsal on SQLite:**
  1. a database built with `main`'s migrations and seeder (23 permissions, 45 migrations), with `tasks.manage` granted to Manager as a probe;
  2. then the new code's `migrate`, a re-seed, `migrate:rollback --step=1`, and `migrate` again.

## 11. Results

- **Backend:** 1,390/1,390 tests pass. Pint and PHPStan are clean.
- **Mutations: 10/10 caught:**
  - task delete checked as update;
  - announcement edit as publish;
  - contacts as client create;
  - members as project update;
  - staff delete as update;
  - task edit as create;
  - milestones as project create;
  - the staff account shown on create;
  - rollback to "any part";
  - grants not copied.
- **Rehearsal:**
  - the migration ran;
  - 35 permissions, none of the five old ones;
  - the Manager probe now holds `tasks.create`, `.update` and `.delete`;
  - Administrator has no rows (as before; `Gate::before`);
  - the re-seed changed nothing;
  - rollback returned to 23 permissions with the Manager probe back on `tasks.manage`;
  - re-migrating gave the same result.
- **Not run here:** MySQL (the queries are portable: `insertOrIgnore`, `groupBy`/`having count(*)`), the staging deployment, and UAT.

## 12. Deviations from Specification

None.

## 13. Known Issues/Limitations

- **The "write includes view" rule (P-3)** is a granting rule. The defaults follow it, and the 35C screen will enforce it. Until then, a grant can only be made by a developer.
- **A granted write permission shows no new button** in the app or portal (P-6, as stated in the proposal).

## 14. Manual/UAT Testing Instructions

A runbook will be written after merge: `docs/testing/PHASE_35A_UAT_PREPARATION.md`. It covers the deploy (with the migration), a read-only check script, and UAT-35A-01…04.

## 15. Documentation Updated

See §5.

## 16. Recommended Next Step

1. Merge on green CI.
2. Deploy and run UAT-35A.
3. Close 35A.
4. 35B (Admin Backoffice Foundation) needs its own authorization.
