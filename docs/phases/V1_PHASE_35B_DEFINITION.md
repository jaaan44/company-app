# Phase 35B — Admin Backoffice Foundation — Definition

**Status:** PROPOSED (2026-10-13). Authorized to start by the product owner ("proceed with 35B"). Decisions B-1…B-6 await approval; **no code has been written.**
**Place:** the second of 35A–35C (DEC-065). 35A is closed. 35C (the Roles & permissions screen) builds on this shell.

---

## 1. Objective

Turn the Admin Backoffice from a placeholder into a real web app shell that administrators use. It needs:
- **navigation for the sections** in `06_UI_UX_GUIDELINES.md`:
  - Dashboard;
  - People;
  - Clients;
  - Work;
  - HR;
  - Operations;
  - Communication;
  - Reports;
  - Administration;
- **a working Dashboard;**
- **the same look and behaviour as the staff portal** after 34F.

Phase 36 then fills the sections with management screens, and 35C adds Administration → Roles & permissions.

## 2. Current State (read-only, `main` at `6f34536`)

- **`/home`** (`can:admin.access`) is a plain placeholder: "This is a neutral authenticated placeholder…" plus Log out. It uses the old `x-layouts.app` layout, unstyled.
- **Who can open it:** only Administrators hold `admin.access`. The portal's **Admin** link (sidebar and More) points to `/home`.
- **APIs that already exist** for the Backoffice:
  - **Dashboard:** `GET /api/v1/dashboard` (Phase 20). It returns:
    - people, clients and projects counts;
    - tasks by status, plus overdue;
    - work-log hours;
    - leave pending and approved;
    - service reports by status;
    - open incidents, by severity;
    - upcoming schedule items;
    - by default for the current month, or for a chosen period.
  - **Reports:** seven report APIs with CSV export (staff, projects, tasks, work logs, leave, service reports, incidents).
  - **Audit log:** `GET /api/v1/audit-logs` with CSV export.
- **The portal's building blocks are ready to reuse:**
  - the 34F shell (tabs or sidebar, app bar, toasts, dialogs, icons, Livewire navigation);
  - the indigo tokens;
  - `InternalApi` (in-process API calls as the signed-in user).

## 3. Scope

**In:**
1. **The shell:**
   - a sidebar with the nine sections;
   - an app bar with the page title;
   - the same tokens, light and dark, toasts, dialogs, icons and navigation without reloads as the portal;
   - a **Staff portal** link back to `/app`, and Log out.
2. **Dashboard** (the Backoffice home), from `GET /api/v1/dashboard`:
   - cards for people, clients, projects, tasks (by status, overdue), work hours, leave (pending), service reports, open incidents (by severity), and the next schedule items;
   - a period picker (this month by default);
   - each card **only if the API returns it** for the signed-in user.
3. **Section pages:**
   - each section has a landing page that lists what it will hold (for example Work: Projects, Tasks, Work logs, Schedule), each marked **"Coming in Phase 36"**;
   - Administration lists **Roles & permissions** and **Users and roles** as "Coming in 35C", and **Audit log** as "Coming in Phase 36" (B-5).
4. **Permission-aware navigation** (06): a section shows only to someone who holds a permission it will need. Today only Administrators enter the Backoffice, so they see all nine. This is the rule 35C and Phase 36 build on.

**Out:**
- any management screen (Phase 36);
- the Roles & permissions screen (35C);
- report screens (Phase 36);
- any API, migration or permission change;
- giving anyone other than Administrators `admin.access`.

## 4. Decisions Needed (B-1…B-6), each with a recommendation

| # | Question | Recommendation |
|---|---|---|
| **B-1** | The Backoffice's address | **`/admin`**, with `/home` redirecting there so old links still work. The portal's Admin link points to `/admin`. |
| **B-2** | Layout on phones | **Desktop-first** (06): a sidebar from `md` up. On phones, a **menu button** in the app bar opens the same list as a drawer. There's no bottom tab bar: nine sections don't fit in five tabs, and the Backoffice is mainly used on a computer. |
| **B-3** | The Dashboard | **Build it now** from the existing API, read-only, with a period picker. Cards link to their section pages. |
| **B-4** | Sections with no screens yet | **Show them,** each with a landing page listing what's coming and when. That makes it clear where things will be. |
| **B-5** | A read-only Audit log page (the API exists) | **Not in 35B.** It goes in **35C**, where role and permission changes first need to be seen. |
| **B-6** | Moving between the Backoffice and the portal | A **Staff portal** link in the Backoffice sidebar, and the portal's **Admin** link (already there for Administrators). Both are full page loads, because the two have different layouts. |

## 5. Architecture

- **Routes:** `/admin`, plus `/admin/{section}` for the eight section pages. They sit behind `auth`, `account.active` and `can:admin.access`, as `/home` does today. `/home` becomes a redirect.
- **Layout:**
  - `components/layouts/admin.blade.php`, using the same `partials.app-head`, tokens and components as the portal;
  - a drawer for phones, made with a `<dialog>` like the 34F confirmations.
- **Dashboard:**
  - a Livewire component, `App\Livewire\Admin\Dashboard`, that calls `/dashboard` through `InternalApi`. It uses the same pattern as the portal (`CallsInternalApi`), with no rule of its own;
  - display helpers come from the existing `Format` where possible.
- **The section list** is in one place, a small config array of each section's title, icon, the permissions that show it, and its planned items. Phase 36 and 35C replace the "Coming" items with links.

## 6. Testing and UAT

- **Feature tests:**
  - only `admin.access` opens `/admin` and each section; Manager and Staff are refused (`403`); guests go to sign-in;
  - `/home` redirects;
  - the portal's Admin link points to `/admin`;
  - the Dashboard shows each card from the API's data; a narrowed API answer hides cards;
  - the period is passed through, and an invalid period shows the API's message;
  - the navigation order and the current section.
- **Browser checks** (Playwright + axe AA): desktop and 390 px, light and dark, the phone drawer, navigation without reloads, no horizontal scroll at 320 px.
- **UAT scenarios** (draft, all `NOT RUN`):
  1. An Administrator opens Admin from the portal, lands on the Dashboard, and the figures match the data on staging.
  2. The period picker changes the period-based figures.
  3. Each of the eight sections opens and lists what's coming.
  4. The phone drawer, light and dark, and keyboard only.
  5. Manager and Staff can't open `/admin` (the portal shows no Admin link, and typing the address is refused).
  6. The old `/home` address still leads to the Dashboard.

## 7. Risks

| Risk | Mitigation |
|---|---|
| Section pages with nothing in them feel unfinished | Each page states what's coming and in which phase (B-4). |
| The Backoffice and the portal drift apart in look | Same head partial, tokens and components. Only the layout differs. |
| Dashboard figures differ from what an administrator expects | They come from the same API the mobile reports use. UAT 1 compares them with staging data. |

## 8. On Approval

1. Record the decisions in DEC-065 (35B addendum).
2. Implement exactly §3.
3. Test as in §6.
4. Update the docs, then stop for merge, deployment and UAT, as with every sub-phase.
5. 35C needs its own authorization.
