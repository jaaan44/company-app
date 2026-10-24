# Phase 37E — Employee Cards on Home and the Staff Directory

**Status: APPROVED (2026-10-24) and implemented; see §6 and `docs/handoffs/V1_PHASE_37E_HANDOFF.md`.**
**Authorization:** the product owner, 2026-10-24.
- **Brief:** "looking to this design, the dashboard i want to focus on putting item cards for each staff. the goal of this dashboard is to show the user the employees. show first 4. try to follow the functionalities of the screens on this reference image", with the same reference as 37D (`docs/design/portal-style-reference.webp`).
- **Answers, same day:** Phase 37E; the first 4 **company-wide, A–Z**; for the reference's data the app lacked, "create the functions for these"; a card's action is **View profile**.

**Branch:** `claude/admiring-planck-hictyu`, restarted from `main` at `77aab0f` (#145 and #146 merged).

## 1. Goal

Home shows the user the employees: the first four, as cards like the reference's "All Employees" list. The full list (the Staff directory) works like that screen: cards, status filter chips and a department filter.

## 2. What the reference's screen does, and how 37E covers it

| Reference | 37E |
|---|---|
| A card per person: photo, name, role, status dot and word | Initials avatar (no photos), name, role line, status dot and word (37D) |
| "On Leave" with dates (Sarah Kim, Oct 24 – 26) | **New:** `current_leave` (approved leave covering today: dates only) |
| "Working on" a project, with its task | **New:** `working_on` (the project of their most recent in-progress task, only if the viewer may see that project; the task isn't shown) |
| Location (Main Office, Remote, Client Site) | Not built: location is check-in data behind `location.view` (Phase 9), not directory information |
| Filter chips: All, Working, WFH, On Leave | **New:** chips for All, each operational status and On leave (`/staff?operational_status=`, `?on_leave=1`). "WFH" isn't a status the app has |
| "By Department" | A department select (`/staff?department=`, existing) |
| Search | The directory's name search (existing) |
| ⋯ menu per card | The whole card opens the profile (the only action chosen); no menu with a single item |

## 3. API (no migration)

- `GET /api/v1/staff`: `operational_status=<status>` (the person's latest status) and `on_leave=1|0`. Both need `staff-status.view` (403 otherwise), because filtering reveals the status.
- `StaffResource` on `GET /staff`, `GET /staff/{public_id}` and `GET /me/profile`, which must stay identical to the second:
  - `current_leave`: `{start_date, end_date}` or null, only for `staff-status.view` holders; never the type or reason;
  - `working_on`: `{public_id, name}` or null, only when the requester may view that project (`projects.view` or a membership, Phase 10).
- Two eager-loaded relations (`Staff::currentLeave`, `Staff::currentTask`). The list stays at a constant number of queries.

## 4. Portal

- `x-portal.staff-card`, used on Home and the directory.
- **Home:** Employees replaces 37D's My team: four **compact cards side by side**, like the reference's dashboard "My Team" (a sideways-scrolling row on phones, four columns from `md`; follow-up instruction), with "View all (N)" opening the directory, right after the quick actions.
- **Directory:** cards, status chips (with `staff-status.view`), a department select (with `organization.view`), all kept in the URL.
- **Colleague's page:** On leave with dates, and a Working on row.

## 5. Not in scope

Photos, locations, WFH, a per-card menu, and the Flutter app (paused).

## 6. As implemented

As above. See the handoff for tests and checks.
