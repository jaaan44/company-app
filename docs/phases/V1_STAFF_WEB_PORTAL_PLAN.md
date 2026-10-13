# Staff Web Portal (Laravel) — Draft Specification

**Status:** **Draft, revision 0 (2026-10-13). Not authorized.** A planning document only: no code, no configuration and no roadmap change is made by it. It needs the product owner's decisions on §4 (W-1…W-10) and an explicit authorization naming the first sub-phase before any work starts (CLAUDE.md §1, §3, §8).

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
