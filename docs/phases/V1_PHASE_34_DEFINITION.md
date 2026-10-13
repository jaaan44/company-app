# Phase 34 — Staff Web Portal (Laravel) — Specification

**Status:** Revision 1 (2026-10-13). **Decisions W-1…W-10 approved "as recommended" by the product owner on 2026-10-13 (DEC-063).** **34A and 34B authorized, implemented and closed** (§11–§12); **34C authorized, implemented and closed** (§13); **34D authorized and implemented** (§14; handoff `docs/handoffs/V1_PHASE_34D_HANDOFF.md`). 34E is **not authorized**: each needs its own authorization (W-10). Revision 0 was the draft reviewed in PR #89.

**Requested by the product owner (2026-10-12):** "a laravel based frontend (basically similar function with the flutter frontend)". This draft reads that as: **the same staff functions as the Flutter app, in a web browser, built with Laravel**, for the same accounts and the same rules.

**Depends on:** Phases 5–21 (the API), Phases 25–33 (the mobile app whose behavior is mirrored), DEC-019 (Admin Backoffice: Blade + Livewire), DEC-022 (session/cookie authentication for the browser), DEC-044 (audit log), DEC-052 (the company day).

## 1. Objective

Let every staff member do in a browser what they do in the mobile app:
- see their home summary;
- work with tasks, work logs, projects and clients;
- view their schedule and set their status;
- request and review leave;
- read and send messages, notifications and announcements;
- write service and incident reports with photos.

**Behavior must match the mobile app.** The same permissions, the same company-day rules, the same list orders and the same messages apply, because the same API rules do.

## 2. Current-State Findings (read-only discovery on `main` at `e324114`)

1. **Web surface today** (`apps/api/routes/web.php`):
   - a Livewire login (`App\Livewire\Auth\LoginForm`), with rate limiting, generic failure messages and an audit entry with `source: admin`;
   - one placeholder page, `GET /home`, behind `auth`, `account.active` and `can:admin.access`;
   - logout.

   **The login refuses any account without `admin.access`**, so staff cannot sign in on the web today.
2. **Styling:** one inline-CSS layout (`resources/views/components/layouts/app.blade.php`). Laravel's default Vite and Tailwind v4 scaffolding exists in `package.json` and `vite.config.js`, but nothing uses it. **The staging image has no Node build step.**
3. **Web visual design is an open decision.** `06_UI_UX_GUIDELINES.md` leaves the web visual-design system to "whichever phase first builds real Admin Backoffice screens". The roadmap's Phase 34 (Admin Backoffice Foundation) was going to make that decision.
4. **The API already serves the browser's session user.** `config/sanctum.php` has `guard => ['web']`, so `auth:sanctum` resolves a signed-in web session user.
   - **A spike on 2026-10-13** (a temporary test, deleted afterwards and never committed) dispatched `/api/v1` requests **in-process** from a session-authenticated web request:
     - `GET /me/profile` and `GET /me/leave-requests` returned `200`;
     - an invalid `POST /me/leave-requests` returned `422`, from the real FormRequest;
     - a **suspended** account got `403` everywhere, from `account.active`;
     - **no token was created**, and the outer request was intact afterwards.
   - The same rules, policies, company-day handling and JSON contract that the mobile app uses can therefore serve web screens without being copied.
5. **The audit log knows two front doors** (`App\Enums\AuditSource`: `api`, `admin`). Actions made through the API in-process would be recorded as `api` unless this changes (W-6).
6. **Mobile scope to mirror** (Phases 27–33): about 40 endpoints, all under `/api/v1` — the `/me/*` family, `/tasks`, `/projects`, `/clients`, `/staff`, `/schedule*`, `/conversations`, `/messages`, `/service-reports`, `/incident-reports` and attachments.

## 3. Goals and Non-Goals

**Goals:**
- Feature parity with the mobile app for staff, managers and administrators acting as staff (§5).
- **One source of business rules:** the existing API. No validation, permission or company-day logic is re-implemented for the web.
- Server-rendered pages (DEC-019), usable on desktop and on a phone's browser, in light and dark mode, meeting the WCAG 2.2 AA baseline already adopted for mobile (DEC-046).
- Deployed in the existing staging stack: the same container, the same domain, no new service.

**Non-goals for this track:**
- Admin management and CRUD screens. Those stay Phases 34–35 (Admin Backoffice), which can later reuse this track's layout and design system.
- Push notifications, websockets or offline mode. Messages refresh by polling, as on mobile.
- Location check-in from the browser (W-7 recommends excluding it).
- Any new business feature not already in the mobile app.
- Replacing the mobile app.

## 4. Decisions Needed (W-1…W-10) — each with a recommendation

| # | Question | Options | **Recommendation** |
|---|---|---|---|
| **W-1** | How do web screens get their data and rules? | **(a)** Livewire components call the existing `/api/v1` controllers **in-process** through one small internal client, as the signed-in session user. **(b)** Extract per-module service classes from the API controllers, used by both API and web (a backend refactor). **(c)** A JavaScript client in the browser calling `/api/v1` with Sanctum cookie auth (supersedes DEC-019; adds a JS app). | **(a).** The spike proved it works. Rules stay in one place by construction, and behavior matches mobile without a refactor of tested code. (b) can still happen later, one module at a time, if a module needs it. (c) contradicts DEC-019 for no gain. |
| **W-2** | Where does the portal live? | A route area in the same Laravel app (for example `/app/...`) / a subdomain / a separate Laravel app | **Same app, same domain, under `/app`.** It reuses the session, CSRF and deployment. The Admin Backoffice keeps its own area later. |
| **W-3** | Who can sign in on the web? | Admins only (today) / every active account | **Every active account.** The portal is for staff. `account.active` and every API permission still apply per action. Admin-only pages stay behind `admin.access`. |
| **W-4** | One login page or two? | One login that routes by role / a separate staff login | **One login.** After sign-in everyone lands on the portal home; accounts with `admin.access` also see an "Admin" link. This changes today's login, which refuses non-admins. The generic failure messages, rate limit and audit stay. |
| **W-5** | Visual design system for the web | Tailwind v4 via Vite (scaffolding present; needs a Node build stage in the staging image) / hand-written CSS with no build | **Tailwind v4 via Vite**, with a multi-stage Docker build: Node builds assets, and PHP serves them, with no Node at runtime. This becomes **the web design decision** that `06_UI_UX_GUIDELINES.md` left open, so the Admin Backoffice reuses it instead of deciding again. |
| **W-6** | How are web actions audited? | Keep `source: api` for in-process calls / add `source: web` | **Add `AuditSource::Web`**, set for in-process portal calls and portal logins. Audit stays truthful about the front door. There is no migration: the column is a string. |
| **W-7** | Location check-in on the web | Include, via the browser Geolocation API (a JS permission prompt) / exclude | **Exclude for now.** The web shows My status (view and change) and the check-in history, but not "Check in". Check-in is a field action on the phone, and browser location on desktops is IP-based and imprecise. It can be revisited after UAT. |
| **W-8** | Photos and files | Browser file picker with upload / view only | **Upload JPEG/PNG/PDF from the file picker** (Livewire file upload, forwarded to the existing attachment endpoints; the same 10 MB limit). Photos open in a viewer; **PDFs download.** The web can open PDFs, unlike the phone. |
| **W-9** | Roadmap position and numbering | Insert as Phase 34 and renumber 34–38 to 35–39 / give it letters ("33W") / place it after the Admin Backoffice | **Insert as Phase 34 — Staff Web Portal, sub-phases 34A–34E**, and renumber the later phases (as DEC-048 did). It comes first because its foundation (W-2…W-5) is exactly what the Admin Backoffice phase needed anyway. |
| **W-10** | Authorization style | Gate by gate with a stop after each (default) / one blanket authorization as DEC-059 | **The product owner's choice.** A DEC-059-style blanket authorization worked for 31–33; the foundation sub-phase (34A) is the riskiest and benefits from its own review. |

## 5. Scope — mobile feature → web screen

| Mobile (phase) | Web screen | API used (unchanged) | Notes |
|---|---|---|---|
| Login, logout, session expiry (25–27) | Login, logout | — (web guard) | One login (W-4). A suspended or deactivated account is signed out on its next request, as on mobile. |
| Home (27) | Home | `GET /me/home` | Today, Needs-attention tiles, latest announcements; tiles link to their screens. |
| My profile, Staff directory (28) | Profile, Directory | `/me/profile`, `/staff` | Read-only; copy-to-clipboard for email and phone. |
| Tasks, task detail, status changes, log work (29A–B) | Tasks, Task | `/me/tasks`, `/tasks`, `/tasks/{id}/log-work` | |
| Work logs (29B) | Work logs | `/me/work-logs` | Create, edit, delete as on mobile. |
| Projects, Clients (29C) | Projects, Clients | `/projects`, `/clients` | Read-only. |
| Schedule (30A) | Schedule (week view) | `/me/schedule`, `/schedule/entries/{id}` | Previous / next week / Today; a wider layout on desktop. |
| My status, history (30B) | My status | `/me/status`, `/me/check-ins` | **No Check in** (W-7). |
| Leave, request, cancel, team review (31) | Leave, Request leave, Team requests | `/me/leave-*`, `/me/team-leave-requests`, `/leave-requests/{id}` | |
| Messages, chat, new message (32) | Messages, Chat | `/conversations`, `/messages` | 15 s polling only while a chat is open (`wire:poll`), as on mobile. |
| Notifications, Announcements (32) | Notifications, Announcements | `/me/notifications`, `/me/announcements` | |
| Service reports, incidents, photos (33) | Reports | `/service-reports`, `/incident-reports`, attachments | File-picker upload (W-8). |

## 6. Architecture (if W-1…W-6 are accepted as recommended)

- **Internal API client (one class).**
  - It dispatches `Request::create('/api/v1/...')` through the HTTP kernel with the outer session, as the spike did. It restores the outer request afterwards, marks the call as coming from the web (for W-6), and returns the status and decoded JSON.
  - It maps errors exactly as the mobile `ApiClient` does: `401` signs out, `403` shows the no-profile or forbidden state, `422` gives field errors, `409` gives the server's message, and `413` says the file is too large.
  - It holds no business rules.
- **Livewire components**, one per screen, under `App\Livewire\Portal\...`. Each renders the API's JSON, using the same fields and orders as the mobile screens. Pagination uses the API's `meta`.
- **Routes:** `/app/*` behind `web`, `auth` and `account.active`.
  - Admin-only routes keep `can:admin.access`.
  - `/login` routes everyone (W-4); `/home` becomes the Admin entry.
- **Security:**
  - **CSRF and session:** Laravel's CSRF on every form. The session cookie is already `Secure` on staging, and `HttpOnly` and `SameSite=Lax` by default (`config/session.php`).
  - **Uploads:** Livewire's temporary uploads stay on the private disk and are deleted after they are forwarded.
  - **Logging:** no message bodies, reasons or file names are logged, which matches the audit redaction rules (DEC-044).
  - **Rate limits:** API rate limits apply to in-process calls per user, as for mobile.
- **Assets and deployment (W-5):**
  - `Dockerfile.staging` gains a Node stage that runs `npm ci && npm run build`. Its output, `public/build`, is copied into both the `app` and `nginx` images.
  - A `.dockerignore` and image-build check is part of the gate (lesson from PR #83). **A CI job that builds the staging images** becomes part of 34A.
- **Time:** everything shown uses the API's `company_day` / `utc_offset`, exactly as mobile (DEC-052).

## 7. Sub-Phases and Gates (proposed)

| Sub-phase | Content | Gate output |
|---|---|---|
| **34A — Foundation** | W-1 client, W-4 login change, W-5 assets and Docker build stage, layout and navigation (light and dark, responsive), W-6 audit source, **Home, Profile, Directory**; CI builds the staging images | Tests: feature and Livewire, plus the internal client's error mapping. A **Playwright** run against a local server in the AI sandbox (Chromium is available) for login, navigation and dark mode. UAT on staging. |
| **34B — Work** | Tasks, Work logs, Projects, Clients, Schedule | As above |
| **34C — HR & Operations** | Leave (incl. Team requests), My status | As above |
| **34D — Communication** | Messages, Notifications, Announcements | As above |
| **34E — Reports** | Service and incident reports with uploads and downloads | As above, plus a real upload through the host nginx (12 MB limit already in place) |

**Each sub-phase:**
- runs the backend checks CI runs (Pint, PHPStan, `php artisan test`, `composer audit`);
- **runs no mobile change;**
- adds UAT scenarios to `UAT_LOG.md`, which are `NOT RUN` until the product owner runs them;
- produces a handoff.

## 8. Testing and UAT

- **Automated:**
  - Livewire component tests per screen against the real API (no mocks of business rules);
  - the internal client's mapping of `401/403/409/413/422`;
  - an access matrix: staff, manager, admin, no profile and suspended accounts on every route;
  - CSRF and session expiry.
- **Contract parity:** the web screens consume the same JSON the mobile app's tests already pin, so a parity check compares the rendered fields with the mobile models' fields.
- **Browser checks** (AI sandbox, Playwright with Chromium): login and logout, navigation, keyboard-only use, dark mode, 200% zoom, and a 320 px-wide viewport.
- **UAT** (product owner, staging): one scenario set per sub-phase, run in a desktop browser **and** a phone browser.

## 9. Risks and Mitigations

- **The in-process dispatch is an unusual pattern.**
  - **Mitigations:** one class owns it; its tests pin request restoration, the session, the absence of tokens and error mapping. If a Laravel upgrade breaks it, W-1(b) is the fallback, one module at a time.
- **Two front ends double the UAT surface.**
  - **Mitigation:** per-sub-phase UAT, with scenarios mirroring the mobile ones already passed.
- **The Node build stage changes the staging image.**
  - **Mitigations:** a CI image build (34A), a `BUILD OK` / STOP rule in every runbook, and rollback by redeploying the previous commit.
- **Changing the login affects administrators.**
  - **Mitigations:** the same generic failure messages, rate limit and audit; an explicit UAT scenario for the admin path.

## 10. On Approval

On the product owner's approval of W-1…W-10 (as recommended or amended) and authorization of **34A**, the AI session will:
- record a DEC (and DEC-019 / DEC-022 / DEC-048 cross-references);
- update ROADMAP numbering (W-9), `02_ARCHITECTURE.md`, `05_SECURITY_MODEL.md` and `06_UI_UX_GUIDELINES.md` (the web design system);
- turn this draft into `docs/phases/V1_PHASE_34_DEFINITION.md` with the 34A UAT scenarios;
- implement 34A only, and stop for review.

## 11. Sub-phase 34A as implemented (2026-10-13)

**Built:**
- `App\Services\Portal\InternalApi` and `PortalApiException` (W-1);
- `AuditSource::Web`, with the request-derived default in `AuditLogger::recordForRequest` (W-6);
- the shared login, landing on `/app`, with the "no Admin Backoffice access" refusal removed (W-3/W-4);
- `/` redirecting to `/app`, and the unused `welcome` page deleted;
- `/app` (Home), `/app/profile` (My profile), `/app/staff` (Staff directory) and `/app/staff/{publicId}` (a colleague), behind `auth` and `account.active`;
- the portal and guest layouts: skip link, main navigation with `aria-current`, an "Admin" link for `admin.access`, and Log out;
- Tailwind tokens and Vite without web fonts (W-5);
- the Node stage in both staging Dockerfiles, and `.github/workflows/staging-images.yml`.

**Mirrors mobile:**
- **Home:** the greeting, Today with company times and "+N more today", the Needs-attention counts, and the latest announcements. As on mobile before the target screens existed (Phase 27 R-1), Home's tiles and rows don't link anywhere yet; they will link once 34B and 34D build those screens.
- **My profile:** Work, Employment and Account sections with copy buttons, read-only.
- **Directory:** active staff only, 25 per page, name search kept in the URL, and a colleague's page.
- **Wording and states:** the same text and the same no-profile states as mobile.

**UAT scenarios (all `NOT RUN`):**
- **UAT-34A-01:** sign-in for staff, managers, administrators and an account without a profile; landing; the Admin link only for administrators.
- **UAT-34A-02:** Home.
- **UAT-34A-03:** My profile, including copy.
- **UAT-34A-04:** Staff directory, search, pages and a colleague.
- **UAT-34A-05:**
  - a suspended account is signed out on its next page;
  - a session that ends returns to the login page with the message;
  - Log out.
- **UAT-34A-06:**
  - dark mode, 200% zoom and a phone browser;
  - keyboard only, including the skip link.

Run these with `docs/testing/PHASE_34A_UAT_PREPARATION.md`.

## 12. Sub-phase 34B as implemented (2026-10-13)

**Authorized** by the product owner ("proceed with 34B"). No API, migration or permission change.

**Screens** (each a Livewire component calling `/api/v1` in-process, mirroring the mobile screen named):
- **Tasks** `/app/tasks` (Phase 29A):
  - Open and Done, 25 per page;
  - the status chip, and the due label ("Due today", "Overdue · 3 Sep", "Due 12 Oct") judged against the API's company day.
- **Task** `/app/tasks/{id}`:
  - status, priority, due date, project (a link), created by, completed date (company timezone), description;
  - a **confirmed** status change among To do / In progress / Blocked / Completed, never Cancelled. A cancelled task is read-only. A `403` shows the API's reason and locks the control;
  - **Log work**.
- **My work logs** `/app/work-logs` (Phase 29B): grouped by day (Today, Yesterday, "Wed 7 Oct"), with durations.
- **Log work / Edit work log** `/app/work-logs/new[?task=]`, `/app/work-logs/{id}/edit`:
  - the target is one of my open tasks or one of my projects that isn't closed;
  - the date is no later than the company today and within the last year;
  - hours and minutes (up to 24 h), and a description of up to 2,000 characters;
  - the same checks and messages as the mobile form, and the API's `422` errors land on the same fields;
  - edit changes only the date, time and description; Delete asks for confirmation.
- **Projects** `/app/projects` (Phase 29C, R-20):
  - the projects I'm a member of, for every role, with search;
  - **Project** `/app/projects/{id}`: client (a link), my role, dates, description, members (links to Staff), and milestones.
- **Clients** `/app/clients`: active clients, with search. **Client** `/app/clients/{id}`: contact details with Copy, and active contacts with Primary.
- **Schedule** `/app/schedule` (Phase 30A):
  - seven days from the company today, with Previous week / Next week / Today;
  - meetings, open tasks due and approved leave per day, in company time;
  - tasks and meetings link to their pages; leave doesn't link, as on mobile (§13).
  - **Entry** `/app/schedule/entries/{id}`: when, project, organizer, people, description.
- **Home:** the My tasks tile and the Today rows now link to their pages, as on mobile. Messages and Notifications follow in 34D.
- **Navigation:** Home, Tasks, Schedule, Work logs, Projects, Clients, Staff directory, My profile, on its own row.

**Status chips:** one shared component (`x-portal.chip`). Every status shows its text, and its colour comes from one tone per status concept (06 "Status presentation").

**UAT scenarios (all `NOT RUN`):**
- **UAT-34B-01:** Tasks Open and Done, with due labels matching the phone.
- **UAT-34B-02:** a task: change status, and see the phone show the same; a cancelled task is read-only.
- **UAT-34B-03:** log work from a task and from the form's picker (a project), and check the form's messages.
- **UAT-34B-04:** edit and delete a work log; the phone shows the same.
- **UAT-34B-05:** Projects (mine only, search), a project, its client and members.
- **UAT-34B-06:** Clients (search) and a client with contacts.
- **UAT-34B-07:** Schedule week, Next / Previous / Today, an entry; Home's links; dark mode, zoom and a phone browser on the new pages.

## 13. Sub-phase 34C as implemented (2026-10-13)

**Authorized** by the product owner ("proceed with 34C"). No API, migration or permission change.

**Screens** (each a Livewire component calling `/api/v1` in-process, mirroring the mobile screen named):
- **Leave** `/app/leave` (Phase 31):
  - **Balances YYYY**: per type, "N days left of M" with "X used · Y pending", or "Unpaid — no balance";
  - **My requests**, newest first, 20 per page: the type, the dates and days, and the status chip;
  - **Request leave**;
  - a **Team requests** entry ("N pending" or "Nothing waiting for you"), only for a reviewer who manages someone (R-1).
- **Request leave** `/app/leave/new`:
  - the types are my balance rows ("15 days left in 2026" or "Unpaid");
  - the first and last day (the last follows the first), "N calendar days (weekends included)", and a reason of 3–1,000 characters;
  - the mobile form's checks and messages, including "A request must stay within one calendar year…";
  - the API's `422` errors land on the same fields, and its `403`/`409` show at the top in its own words;
  - "Leave requested." on the list.
- **Leave request** `/app/leave/requests/{id}` (mine):
  - the type, Paid or Unpaid, the status, the dates and days, the reason and the **History** ("Approved by Ben Cruz · Today 09:12", with the note);
  - **Cancel request**, offered while pending, or approved and not yet started by the company day. It's confirmed first ("Cancel this request?", with the right body, an optional reason, and Keep it).
- **Team requests** `/app/leave/team` (R-3): my direct reports' requests, with Pending (N) / Approved / Rejected / All, 20 per page.
- **Team request** `/app/leave/team/{id}`:
  - "Requested by", and **Approve** (optional note) or **Reject** (a reason of at least 3 characters), each confirmed first and offered only while pending;
  - "Request approved." / "Request rejected."; a stale decision shows the API's refusal;
  - someone else's request: "You don't have access to this request."
- **My status** `/app/status` (Phase 30B):
  - the current status and when it was set ("Set Today 09:12", or "No status set yet");
  - **Change status** among the five statuses. The change is confirmed by the API before "Status set to X." shows; Off duty is explained as "not working right now. This isn't leave.";
  - the 5 newest status changes and check-ins, with **All status changes** and **All check-ins** (`/app/status/changes`, `/app/status/check-ins`, 20 per page);
  - check-ins are read-only: the place (or "No place name"), the time and "±N m", the note, and the coordinates to 5 decimals. **There is no check-in from the browser** (W-7); the page says to check in from the phone.
- **Navigation:** Home, Tasks, Schedule, Work logs, Projects, Clients, **Leave**, **My status**, Staff directory, My profile.

**As on mobile, the Schedule's leave rows stay unlinked** (mobile's Schedule doesn't open leave either). That corrects the 34B note, which expected 34C to link them.

**UAT scenarios (all `NOT RUN`):**
- **UAT-34C-01:** Leave: balances and My requests matching the phone; the Team requests entry only for the manager.
- **UAT-34C-02:** Request leave: the form's messages, then a request that the phone shows as Pending.
- **UAT-34C-03:** Cancel: a pending request, and an approved one not yet started; neither is offered for leave that has started.
- **UAT-34C-04:** Team requests as the manager: the filters, then Approve with a note; the staff member sees "Approved by …" on the web and the phone.
- **UAT-34C-05:** Reject with a reason (a too-short reason is refused); a colleague can't open a team request.
- **UAT-34C-06:** My status: change it and see the phone show the same; the histories and check-ins match the phone; no check-in from the browser.
- **UAT-34C-07:** dark mode, 200% zoom, a phone browser and keyboard on the new pages.

## 14. Sub-phase 34D as implemented (2026-10-13)

**Authorized** by the product owner ("proceed with 34D"). No API, migration or permission change.

**Screens** (each a Livewire component calling `/api/v1` in-process, mirroring the mobile Phase 32 screen named):
- **Messages** `/app/messages` (R-3):
  - my conversations, newest activity first, 20 per page;
  - each shows the name (the group, the project, or the other person), the last message ("You: …", "Grace: …" in a group, or "No messages yet"), its time in company time ("09:12", "Yesterday", "Mon 12 Oct") and an unread count;
  - **New message**.
- **New message** `/app/messages/new`: the active staff directory, searchable, without myself. Choosing a colleague find-or-creates our direct chat and opens it.
- **Conversation** `/app/messages/{id}` (R-4, R-5):
  - messages oldest to newest with day separators (Today, Yesterday, "Mon 12 Oct"); mine on the right; sender names in group and project chats; 30 per page, with **Load earlier messages**;
  - **Send** is confirmed by the API (1–4,000 characters, trimmed; blank text is ignored). On failure the text stays, with "Couldn't send. Try again.";
  - the page checks for new messages **every 15 s while it is open** (`wire:poll.15s`, slowed down by the browser in a background tab). It marks the chat read when it opens and whenever a newer message appears.
  - Someone else's or an unknown conversation: "This conversation is no longer available." (the API answers `404` to a non-member).
- **Notifications** `/app/notifications` (R-6):
  - newest first, 20 per page; unread ones bold, with a dot and "Unread" for screen readers;
  - opening one marks it read (a failure is quiet) and then opens its source: an announcement, or the conversation;
  - **Mark all read** ("All marked read.");
  - works without a staff profile (R-9).
- **Announcements** `/app/announcements` (R-7):
  - newest first, 20 per page, with the published date and **New** until acknowledged.
  - **Announcement** `/app/announcements/{id}`: the title, "Published by · 12 Oct 2026 09:30", the full body as plain text, and **Acknowledge**, then "Acknowledged on …" from the API.
- **Home** (R-8): the Messages and Notifications tiles and the latest announcements now link to their pages, plus **All announcements**.
- **Navigation:** … Leave, My status, **Messages**, **Notifications**, **Announcements**, Staff directory, My profile.

**Not in 34D** (as R-10): attachments in messages, editing or deleting messages, groups and members, project chats started from the web, announcement management, read receipts, typing indicators, push, sound, and a navigation badge.

**UAT scenarios (all `NOT RUN`):**
- **UAT-34D-01:** Messages inbox matching the phone (names, previews, times, unread counts); Home's Messages tile opens it.
- **UAT-34D-02:** a chat: day separators, Send (the phone shows it), a reply sent from the phone appears on the web within about 15 s, and the unread count clears.
- **UAT-34D-03:** New message to a colleague opens the direct chat; doing it again opens the same chat.
- **UAT-34D-04:** Notifications: unread ones bold; opening one marks it read and opens its announcement or chat; Mark all read; an account without a profile still sees its notifications.
- **UAT-34D-05:** Announcements: New until acknowledged; the detail; Acknowledge, then the phone shows it acknowledged; Home's links.
- **UAT-34D-06:** dark mode, 200% zoom, a phone browser and keyboard on the new pages.
