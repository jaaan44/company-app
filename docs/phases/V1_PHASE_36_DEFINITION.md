# Phase 36 — Admin Backoffice Business Management Screens — Definition

**Status:** PROPOSED (2026-10-16). The product owner authorized Phase 36 ("Go ahead i authorize"). This definition splits the phase into sub-phases and lists the decisions (M-1…M-9) that need approval before any code is written.
**Roadmap line:** "CRUD/management UI for the business modules, grouped by the same section layout; may be split into sub-phases at its own planning time."

---

## 1. Objective

Every "Coming in Phase 36" item in the Admin Backoffice (`/admin`) becomes a working screen, so an Administrator can run the company from the web without calling the API directly.

## 2. Current State (read-only, `main` at `c2a64d1`)

- **The Admin Backoffice (35B/35C) has:**
  - the Dashboard (live counts from `GET /dashboard`);
  - eight sections;
  - Administration → Roles & permissions, Users and roles, and the Audit log.
  - Every other item says "Coming in Phase 36" (`App\Support\Admin\Sections`).
- **Who can enter:** only holders of `admin.access`, which is locked to Administrators (DEC-065).
- **The staff portal (35D) already has add, edit and delete screens** for tasks, projects (with members and milestones), clients (with contacts) and announcements. They work for an Administrator too, under the "All" tabs.
- **The API already has every business endpoint** these screens need, with one exception: there is **no way to create a sign-in account**, reset a password, or suspend an account, other than seed scripts on the server.
  - Staff records can link to an *existing* account (`user_id`).
  - Users and roles can change an account's role, but nothing else.
  - Accounts already have a status (`active`, `suspended` or `inactive`), and the `account.active` middleware already signs out anyone who isn't active.
  - Nobody can change their own password: there is no "change password" screen or endpoint.

## 3. Proposed Sub-phases

| Sub-phase | Section(s) | Screens | API change |
|---|---|---|---|
| **36A — People & sign-in accounts** | People, Administration | Staff records (list, search, add, edit, remove, link an account); **sign-in accounts** (create, reset password, suspend, reactivate); departments, teams and positions; correct a staff member's status; review and remove check-ins; **change my own password** (portal profile) | **Yes:** account endpoints and a "must change password" flag (M-3, M-4) |
| **36B — HR** | HR | All leave requests (filter, approve, reject, cancel, request on someone's behalf); leave types (add, edit, remove); leave balances (set a person's allowance per year) | No |
| **36C — Work & Operations** | Work, Operations, Clients, Communication | Work logs (review all, correct, remove); schedule entries (add, edit, remove); service reports (review, approve, reject); incidents (assign, reassign, investigate, resolve, close, reopen). Projects, tasks, clients and announcements **link to the 35D screens** (M-6). | No |
| **36D — Reports** | Reports, Administration | The seven reports (staff, projects, tasks, work logs, leave, service reports, incidents) with filters and **CSV download**; Audit log filters by date, person and record (35B/35C known issue) | No |

## 4. Decisions Needed (M-1…M-9), each with a recommendation

| # | Question | Recommendation |
|---|---|---|
| **M-1** | Split as in §3? | **Yes: 36A, 36B, 36C and 36D.** 36A is the only one that changes the API and touches passwords, so it goes first and on its own. |
| **M-2** | Delivery and UAT | **36A alone, with its own UAT.** Then **36B–36D in one go with one UAT at the end**, as you chose for 35B–35D. |
| **M-3** | How a new sign-in account gets its first password | **The system generates a strong temporary password and shows it once,** on screen, to the Administrator who created the account, with a Copy button. It is never stored in readable form, never logged and never put in the audit log (which records only "account created"). The Administrator gives it to the person directly. **No email invites:** no mail service is set up, and that would be a separate decision. |
| **M-4** | First sign-in with a temporary password | **The person must choose their own password at their first web sign-in** before anything else opens. The phone app refuses to sign in until they have, with the message "Set your password on the web first." **Reset password** works the same way: a new temporary password, shown once, and the person's existing sign-ins (phone and web) are ended. |
| **M-5** | Who may manage sign-in accounts | **Administrators only,** through the existing locked `authorization.manage` permission. No new permission. Anyone can change their own password. |
| **M-6** | Projects, tasks, clients and announcements | **Reuse the 35D portal screens.** The Backoffice items link to them, under the "All" tab, rather than duplicating them. |
| **M-7** | Removing things that have history | **Follow the API's existing rules** and show its message. For example, a staff record with history can't be deleted, and the screen suggests setting its status to Separated instead. Suspending an account, rather than deleting it, is how access is removed. |
| **M-8** | Password rules | **At least 12 characters,** not the temporary password, and not the same as the email address. The same rules apply on the "change my password" screen. |
| **M-9** | Out of scope | **Out:** the mobile app (except M-4's refusal message); email; bulk import; the `per_page` cap and `q` escaping on older endpoints (Phase 37, hardening); and the 29B UAT password rotation, which stays with the operator. |

## 5. Architecture

- **The same pattern as 35B–35D:**
  - Livewire components in `App\Livewire\Admin`, calling `/api/v1` in-process through `InternalApi`, with no business rule of their own;
  - the `admin.page` layout;
  - buttons shown by permission (`WritesThroughApi::may()`);
  - the API's messages shown on fields or above the form;
  - in-app confirmation dialogs.
- **36A, new API (additive):**
  - `POST /users` (create an account, optionally linked to a staff record in the same step);
  - `POST /users/{id}/reset-password`;
  - `PUT /users/{id}/status` (suspend or reactivate);
  - `PUT /me/password`.
  - Each is audited, without passwords.
  - New column `users.must_change_password` (migration; existing accounts `false`).
  - The token login (`POST /login`) refuses an account that must change its password, and the web sends it to "Choose your password".
- **Sections:** each "Coming in Phase 36" item gains its route as its screen ships. The pills disappear when the last one lands.

## 6. Testing and UAT

- **Feature tests per screen:**
  - shown or hidden by permission;
  - each form's messages and the API's 422s;
  - create, edit and delete persisted;
  - the API's 403, 404 and 409 shown.
- **36A security tests:**
  - the temporary password appears once, and never in the audit log, logs or a later page;
  - its hash is stored;
  - must-change is enforced on the web and the API;
  - a reset ends existing tokens and sessions;
  - a suspended account is refused on both;
  - only Administrators reach any of it.
- **Browser checks** (Playwright and axe AA): desktop and phone, light and dark, 320 px.
- **UAT:** scenarios drafted with each sub-phase, `NOT RUN` until you run them. 36A's UAT includes creating a real staging account and signing in with it on the web and the phone.

## 7. Risks

| Risk | Mitigation |
|---|---|
| A temporary password is seen by someone else | Shown once to the creating Administrator only; it must be changed at first sign-in; reset is available. |
| Large phase | Four sub-phases, each fully tested before the next. |
| Duplicated screens drift apart | M-6 reuses the portal screens. |

## 8. On Approval

1. Record the decisions as DEC-066.
2. Implement 36A.
3. Test as in §6.
4. Update the docs and the handoff.
5. Stop for merge, deployment and UAT.
6. Then 36B–36D, as M-2 says.
