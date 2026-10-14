# Phase 35D — Add, Edit and Delete in the Staff Portal — Definition

**Status:** APPROVED (2026-10-14, "approve as recommended": D-1…D-8) and **implemented** (§9). Awaiting merge, deployment and UAT.
**Why:** during UAT-35BC the product owner gave Staff add, edit, delete and view on tasks, but Staff still couldn't create tasks. The permissions work in the API (35A), but neither the portal nor the app has add, edit or delete screens (DEC-065 P-6). The Roles & permissions screen now says so.

---

## 1. Objective

Anyone whose role holds an add, edit or delete permission (from 35C) can use it in the **staff web portal** (`/app`), on a computer or a phone browser.

## 2. Current State (read-only, `main` at `b311419`)

- **The API already has every endpoint, with the 35A permissions:**
  - **tasks:** create, update (all fields, or status only for the assignee), delete (only while To do, and with no work logged);
  - **projects:** create, update, delete (refused while linked records exist), members, milestones;
  - **clients and contacts:** create, update, delete;
  - **announcements:** draft, edit, publish, archive, delete; the audience is everyone, departments or teams.
- **The relationship rules** (unchanged):
  - a project lead adds and edits their project's tasks and milestones;
  - an assignee changes their task's status.
- **The portal (Phase 34) only reads,** apart from task status, work logs, leave, reports and messages:
  - Tasks lists **my** tasks (`/me/tasks`);
  - Projects lists **my** projects (`?member=me`);
  - Clients lists all active clients;
  - Announcements is **my** feed.
- **The mobile app is unchanged by 35D** (option A).

## 3. Scope

**In (D-2):**
- **Tasks:**
  - **New task:** title, description, project, assignee (searched by name), priority, status, due date;
  - **Edit task:** all fields;
  - **Delete task.**
- **Projects:**
  - **New project:** name, code, client, status, start and target dates, description, notes;
  - **Edit** and **Delete**;
  - **Members:** add, change role (member or lead), remove;
  - **Milestones:** add, edit, remove.
- **Clients:**
  - **New client:** name, code, status, email, phone, website, address, notes;
  - **Edit** and **Delete**;
  - **Contacts:** add, edit, remove.
- **Announcements (manage):**
  - a list of all announcements (draft, published, archived);
  - **New:** title, body, audience;
  - **Edit** a draft;
  - **Publish**, **Archive** and **Delete**.
- **"All" lists** (D-4) for people who may see everything.

**Out:**
- staff records (Phase 36, Admin Backoffice, because they include sign-in accounts);
- work-log corrections, leave administration, report reviews and incidents (Phase 36);
- the mobile app (option A);
- any API change.

## 4. Decisions Needed (D-1…D-8), each with a recommendation

| # | Question | Recommendation |
|---|---|---|
| **D-1** | Where | **The web portal only** (already approved, option A). |
| **D-2** | Which modules | **Tasks, Projects (with members and milestones), Clients (with contacts) and Announcements.** Staff records stay with Phase 36. |
| **D-3** | Who sees the buttons | **Anyone holding the permission, and a project lead** wherever the API lets a lead act (tasks and milestones of their project). The page only decides what to *show*; the API still decides every action, and a refusal shows its own message. |
| **D-4** | Lists | **Add "Mine / All" tabs:** Tasks and Projects for holders of View all tasks or View all projects. **Announcements** gets a **Manage** list for holders of View all announcements. Without these, something you add for someone else disappears from your view. |
| **D-5** | Deleting | **An in-app confirmation** (as in 34F). The API's own rules show as messages, for example "Only a task still To do can be deleted" or "This client still has projects". |
| **D-6** | Where the buttons go | Each list's **"+" button** (as in 34F) for New. **Edit** and **Delete** on the item's page. Forms are full pages, like Log work. |
| **D-7** | Placement in the portal | Tasks, Projects and Clients stay where they are. **Announcements → Manage** appears only for those who may. Nothing new appears for anyone without a permission, so today's Staff and Managers see the portal exactly as now. |
| **D-8** | UAT | **Finish UAT-35BC now**: the missing buttons are expected, and the screen says so. 35D then gets **its own UAT** after its deploy, which also ships the Roles screen note. |

## 5. Architecture

- **The same pattern as Phase 34:** Livewire components in `App\Livewire\Portal`, through `InternalApi` and `CallsInternalApi`, with no rule of their own.
- **Showing buttons (D-3)** uses the signed-in user's permissions (`$user->can()`), plus the lead flag the API already returns on project memberships.
- **New routes** under `/app`:
  - `tasks/new`, `tasks/{id}/edit`;
  - `projects/new`, `projects/{id}/edit`;
  - `clients/new`, `clients/{id}/edit`;
  - `announcements/manage`, `announcements/new`, `announcements/{id}/edit`.
- **Shared form pieces:** a staff picker (search `GET /staff`), a client picker (as in the 34E service report form), a project picker, and the 34F dialog.

## 6. Testing and UAT

- **Feature tests per module:**
  - buttons hidden without the permission, and shown with it, or to a lead;
  - each form's messages and the API's 422 on the fields;
  - create, edit and delete persisted;
  - the API's 403 and 409 shown;
  - the "All" tabs.
- **Browser checks** (Playwright and axe AA): desktop and phone, light and dark, 320 px.
- **UAT scenarios** (draft, all `NOT RUN`):
  1. With the defaults, Staff and Managers see no new buttons.
  2. Give Staff the task permissions. As Staff: New task, assigned to a colleague, appears under **All**; edit it; delete it.
  3. A project lead (without the permissions) adds and edits a task in their project, but not in another.
  4. Projects: new, edit, members and milestones, and a refused delete.
  5. Clients and contacts: new, edit, delete.
  6. Announcements: draft, edit, publish (seen in the phone app), archive, delete.
  7. Removing a permission hides its buttons again.
  8. Phone browser, dark mode, 200% zoom, keyboard only.

## 7. Risks

| Risk | Mitigation |
|---|---|
| A button shows but the API refuses | The page shows the API's message. Tests cover each refusal. |
| Large scope | One module at a time inside the PR, each fully tested before the next. |
| Staff create data they then can't find | The "All" tabs (D-4). |

## 8. On Approval

1. Record the decisions in DEC-065 (a 35D addendum).
2. Implement §3.
3. Test as in §6.
4. Update the docs, then stop for merge, deployment and UAT.

## 9. As implemented (2026-10-14)

**New portal screens** (`App\Livewire\Portal`), each through `InternalApi` with no rule of its own:
- **`TaskForm`** (`/app/tasks/new`, `/app/tasks/{id}/edit`):
  - title, project (new tasks only), assignee search, priority, status, due date, description;
  - holders of `tasks.create` / `tasks.update` choose any project or none; a lead chooses only projects they lead.
- **`ProjectForm`** (`/app/projects/new`, `…/{id}/edit`): name, code, client, status, start, target and completed dates (completed only when needed), description and notes.
- **`ClientForm`** (`/app/clients/new`, `…/{id}/edit`): name, code, status, contact details, address and notes.
- **`ContactForm`** (`/app/clients/{id}/contacts/new`, `…/contacts/{id}/edit`): name, job title, email, phone, primary, status and notes. A contact of another client is "no longer exists".
- **`AnnouncementsManage`** (`/app/announcements/manage`): Drafts, Published and Archived.
- **`AnnouncementManage`** (`…/manage/{id}`): Publish, Edit draft, Delete draft and Archive, each confirmed.
- **`AnnouncementForm`** (`…/manage/new`, `…/{id}/edit`): title, body, and audience (everyone, or chosen departments and teams). It saves a draft.

**Existing pages gained:**
- **Tasks:** Mine/All tabs (with `tasks.view`) and New task. New task shows with `tasks.create` or as a lead of an open project.
- **Task:** Edit task (`tasks.update` or the project's lead) and Delete task (`tasks.delete`).
- **Projects:** Mine/All tabs (with `projects.view`) and New project.
- **Project:**
  - **New task**, for `tasks.create` or the lead;
  - **Edit project** and **Delete project**;
  - **members**, with `projects.members`: add (search plus role), Make lead / Make member, Remove;
  - **milestones**, with `projects.update` or the lead: add, change status, Remove.
- **Clients:** New client.
- **Client:**
  - Edit client and Add contact (`clients.update`), and Delete client (`clients.delete`);
  - per contact, Edit and Remove;
  - inactive contacts are shown to editors.
- **Announcements:** a **Manage announcements** link, with `announcements.view-all`.

**Shared pieces:**
- `Concerns\WritesThroughApi`: `may()` decides what is shown; the API's 422 messages map onto fields, and its 403, 404 and 409 messages show above the form.
- `livewire.portal.partials.field`.
- The Tasks tab also covers `portal.tasks.*`.

**What testing found:**
- **New task crashed (500).** The route passed `project`, which Livewire copied into the `project` property. The parameter is now `forProject`.
- **The API refuses to move a task between projects.** Edit now shows the project as fixed and doesn't send it.

**UAT scenarios** (all `NOT RUN`): UAT-35D-01…08 in `docs/testing/UAT_LOG.md`.
