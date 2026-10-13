# Proposal — Granular Permissions and a Roles & Permissions Screen

**Status:** PROPOSED (2026-10-13). **Not authorized.** No code has been written.
**Requested by the product owner:** "write a proposal for options 1 and 2". This came after asking "is it part of the roadmap to allow roles for each user type? like roles for allowing an employee to add, edit, view and delete tasks etc?"
**Options proposed:**
- **Option 1:** finer permissions, so that add, edit and delete are separate.
- **Option 2:** a screen where an Administrator chooses which permissions each role has, without a code change or a deploy.
- **Option 3 (not proposed here):** custom roles.

---

## 1. Objective

An Administrator can decide, per role, who may **view, add, edit and delete** in each module. For example: "Managers may add and edit tasks but not delete them." The change happens from a screen and takes effect immediately, with every change audited.

## 2. Current-State Findings (read-only, `main` at `3d0b166`)

**Roles** (Phase 5, DEC-028):
- the fixed roles are Administrator, Manager and Staff;
- each user has **one role** (`users.role_id`);
- roles are bundles of permissions in `role_permissions`;
- one `Gate::before` gives Administrator everything.

Checks are by permission name (DEC-004), through `can:` route middleware, `$user->can()` and `@can`.

**There are 23 permissions, all set in code** (`RolePermissionSeeder`):

| Module | Permissions | Manager | Staff |
|---|---|---|---|
| Organization | `organization.view` / `.manage` | view | view |
| Staff | `staff.view` / `.manage` | view | view |
| Clients and contacts | `clients.view` / `.manage` | view | view |
| Staff status | `staff-status.view` / `.manage` | view | view |
| Location check-ins | `location.view` / `.manage` | view (own reports only) | — |
| Projects | `projects.view` / `.manage` | view | — |
| Tasks | `tasks.view` / `.manage` | view | — |
| Work logs | `work-logs.view` / `.manage` | view (own reports only) | — |
| Leave types | `leave-types.view` / `.manage` | view | view |
| Leave requests | `leave-requests.view` / `.manage` | view | — |
| Announcements | `announcements.manage` | — | — |
| Access | `admin.access`, `authorization.manage` | — | — |

Notes on the table:
- **Every `.manage` covers add, edit and delete together** and is Administrator-only.
- `authorization.manage` exists but is **not used anywhere**.

**Rules that are not permissions** (and stay as they are):
- **Relationship rules:** a project lead manages their project's tasks; an assignee changes their task's status; a manager approves their direct reports' leave; a report's creator edits their draft.
- **Eight "Administrator sees or does everything" checks written as `hasRole(Administrator)`:**
  - the schedule (two places);
  - check-ins;
  - service reports;
  - incident reports;
  - work logs;
  - leave requests;
  - audit logs.

  These cannot be granted to another role today.

**Gaps that matter for this proposal:**
- **There is no screen and no API to change permissions, or a user's role.** Both need a developer: a code change, or a command on the server.
- **Permissions are read fresh on every request** (`User::hasPermission()`, no cache). A change therefore takes effect on the user's next request, in the apps and on the web, with no sign-out needed.
- **The mobile app and the staff portal show no "add task" or "edit task" screens at all.** They mirror what staff can do today. **Granting a new permission does not, by itself, make a button appear for an employee.** See P-6.

## 3. Goals and Non-Goals

**Goals:**
1. Split `.manage` into separate **add**, **edit** and **delete** permissions where the difference matters (P-2).
2. Keep **today's behaviour exactly** after the split. Every role gets the permissions it effectively has now, and the existing tests pass. The only edits allowed are where a test names a `.manage` permission that was split.
3. Add an **Administration → Roles & permissions** screen in the Admin Backoffice, where an Administrator ticks permissions per role.
4. Audit every change: who changed it, when, which role, and what was added or removed.

**Non-goals:**
- custom roles, or more than one role per user (option 3);
- permissions given to one person instead of to a role;
- changing what the Administrator role can do (it keeps everything);
- the relationship rules in §2, which stay as they are;
- new add/edit screens for staff in the mobile app or portal (P-6 says where those would go).

## 4. Option 1 — Granular permissions

**Proposed catalog changes** (recommended set; P-2 decides the final list):

| Module | Today | Proposed |
|---|---|---|
| Tasks | `tasks.manage` | `tasks.create`, `tasks.update` (fields and assignee), `tasks.delete` |
| Projects | `projects.manage` | `projects.create`, `projects.update`, `projects.delete`, `projects.members` (add or remove members and leads) |
| Clients and contacts | `clients.manage` | `clients.create`, `clients.update`, `clients.delete` (contacts follow their client) |
| Staff | `staff.manage` | `staff.create`, `staff.update`, `staff.delete` |
| Announcements | `announcements.manage` | `announcements.view-all`, `announcements.create` (create and edit drafts), `announcements.publish` (publish and archive), `announcements.delete` |
| Everything else | `.view` / `.manage` | unchanged; each `.manage` can still be ticked per role on the screen |

**Rules:**
- **A write permission includes view.** Ticking "add tasks" also ticks "view all tasks", and unticking view clears the writes. A role should never be able to change what it can't see.
- **Permissions are company-wide.** "Add tasks" means in any project. The relationship rules still apply on top: a project lead keeps their project's tasks, and an assignee keeps status changes. P-3 asks you to confirm this.
- **Same endpoints, same API.** Only the `can:` check on each route changes, for example `POST /tasks` checks `tasks.create`. No endpoint, field or response changes. The mobile app needs no update.
- **The migration maps today's grants onto the new names.** Administrator needs nothing (it has everything). Manager and Staff hold no `.manage` today, so they get no new write permissions. **The day it ships, nothing changes for anyone.**

## 5. Option 2 — Roles & Permissions screen

**Where:**
- the Admin Backoffice (`/home` area), **Administration → Roles & permissions**;
- behind `can:authorization.manage`, the existing permission, finally put to use;
- the Admin Backoffice uses the same look as the 34F portal (DEC-063 W-5, DEC-064).

**What it shows:**
- **One grid per module.** Rows are permissions with plain labels ("View all tasks", "Add tasks", "Edit tasks", "Delete tasks"); columns are **Manager** and **Staff**.
- **An Administrator column**, shown as all ticked and locked ("Administrators can always do everything").
- **Rules that are not permissions**, listed per module as a note, so an Administrator isn't misled. For example: "Project leads can always manage their project's tasks." "Only Administrators can see all check-ins."

**How it works:**
- **Save** opens a confirmation listing exactly what changes ("Manager: + Add tasks, + Edit tasks"). Confirm applies it and shows a toast.
- **Reset to defaults** restores the shipped grants, with the same confirmation.
- Each save writes one audit-log entry per role changed: the role, the permissions added and removed, by whom, `source: web`. It appears in the existing audit log.
- **The effect is immediate,** because permissions are read on every request (§2).

**Guardrails (security):**
- **Locked, never tickable:** `admin.access` (entering the Admin Backoffice) and `authorization.manage` (this screen). Without this lock, a Manager could be given the power to give themselves more.
- **Administrator** can't be edited and can't lose anything (the `Gate::before` stays).
- **Only known permission names** are accepted. The server rejects anything else, whatever the page sends.
- The screen changes **role grants only**, never a user's role (P-5).

## 6. Placement and Sub-phases

The screen needs the Admin Backoffice shell, which is Phase 35 (not yet built). Recommended placement, within Phase 35 and with no renumbering:

| Sub-phase | Content | Visible change |
|---|---|---|
| **35A — Granular permissions** | Option 1: the new catalog, route checks, migration of grants, tests. | **None** (proves behaviour is unchanged). |
| **35B — Admin Backoffice Foundation** | Phase 35 as already planned: the navigation shell and sections. | Admin Backoffice shell. |
| **35C — Roles & permissions screen** | Option 2, plus P-5 if approved. | The screen. |

Each sub-phase is authorized and UAT-tested on its own, as with 34A–34F. Phase 36 (Business Management Screens) then builds add/edit/delete screens that **show each button only to those who hold its permission** (06: "permission-aware UI").

## 7. Testing and UAT

**35A:**
- the full existing suite passes, with edits only where a test names a split `.manage` permission (no behaviour change);
- each new permission has a test for allowed and refused;
- the migration is tested both ways on MySQL and SQLite;
- mutation checks on the route-to-permission mapping.

**35C:**
- feature tests:
  - only `authorization.manage` opens the page;
  - locked permissions are refused even if posted;
  - unknown names are refused;
  - audit entries are written;
  - Reset to defaults works;
  - a grant takes effect on the next request;
- browser and axe checks as in Phase 34.

**UAT scenarios (draft, all `NOT RUN`):**
1. Today's behaviour is unchanged after 35A (spot checks as Staff, Manager and Administrator).
2. The screen shows the grid; Administrator is locked; the notes are visible.
3. Give Manager "Add tasks", sign in as a Manager, and add a task through the API or a test screen. Remove it and see it refused.
4. A write includes view; locked permissions can't be ticked.
5. The audit log shows each change. Reset to defaults works.
6. A Manager or Staff account can't open the screen.

## 8. Risks and Mitigations

| Risk | Mitigation |
|---|---|
| Granting too much by mistake (for example "Delete staff" to Staff) | Clear labels; a confirmation listing every change; the audit log; Reset to defaults; company-wide scope stated on the screen. |
| A role gains power over the permission system itself | `admin.access` and `authorization.manage` locked to Administrator. |
| The split changes behaviour by accident | Default mapping equals today; the existing suite must pass (permission names aside); mutation checks. |
| An Administrator expects a ticked permission to show buttons in the app or portal | §2 gap stated on the screen; P-6. |
| The relationship rules look like a contradiction ("I removed Edit tasks but a lead can still edit") | Notes per module on the screen. |

## 9. Decisions Needed (P-1…P-7), each with a recommendation

| # | Question | Recommendation |
|---|---|---|
| **P-1** | Placement | **As sub-phases 35A–35C** (§6), no renumbering. |
| **P-2** | Which modules get add/edit/delete split | **Tasks, Projects, Clients, Staff, Announcements** (§4). The rest keep `.view`/`.manage`, still tickable per role. |
| **P-3** | Scope of a write permission | **Company-wide**, and every write includes view. The relationship rules stay on top. |
| **P-4** | The eight hard-coded "Administrator sees everything" checks | **Leave them Administrator-only** in this work; the screen lists them as notes. Converting them (for example "see all check-ins") is a separate, later decision, because several cover private data. |
| **P-5** | Changing a **user's role** (today only by a developer) | **Include in 35C** a minimal "Users and roles" list: change an account's role, never your own, and never remove the last Administrator. All changes audited. |
| **P-6** | Add/edit screens for staff in the **mobile app and portal**, for roles given new write permissions | **Not in 35A–35C.** Phase 36 builds the management screens in the Admin Backoffice, which anyone with `admin.access` uses. If employees must add tasks **from the app or portal**, that is a separate sub-phase, proposed after 35C and needing its own authorization. |
| **P-7** | Labels and defaults | **Plain labels** as in §5. **Shipped defaults = today's grants.** |

## 10. On Approval

On authorization of 35A (and the decisions above):
1. Record the decision as **DEC-065**.
2. Update the ROADMAP and this document's status.
3. Implement 35A only, then stop for review and UAT, as with every phase.
4. 35B and 35C each need their own authorization.
