# Phase 37 — Web Integration & Mobile-First UX Hardening — Definition

**Status:** APPROVED (2026-10-16) and implemented (§9). The product owner authorized Phase 37: "go ahead but i would like you to skip the mobile flutter part. i want to prioritize mvp for web based frontend but make sure mobile first responsiveness is prioritized for staff who will use it with mobile phones", then approved H-1…H-9 with "approve as recommended" (recorded as DEC-067).
**Roadmap line (before this change):** "Mobile/Web/Admin Integration & UX Hardening — cross-cutting polish and real-device/cross-browser verification across both surfaces…; revisit a shared loading/empty/error-state library."

---

## 1. Objective

Make the **web app** (the staff portal at `/app`, and the Admin Backoffice) a dependable MVP for staff who use it **on their phones**:
- they stay signed in;
- they never meet a dead end or a browser pop-up;
- it works on a weak connection;
- the everyday tasks are quick with one thumb.

**The Flutter app is paused.**

## 2. What a phone audit found (2026-10-16, `main` at `612dc67`)

Every staff portal page (21 pages) was checked at **360 × 740** in Chromium with Pixel touch emulation:
- **Already good:**
  - no page scrolls sideways;
  - no input is under 16 px, so iPhones won't zoom in on focus;
  - axe AA found 0 violations;
  - every control is at least 44 px, except the incident form's **severity radios (20 px)**.
- **Gaps:**

| # | Gap | Effect on a phone |
|---|---|---|
| G-1 | **The sign-in lasts 2 hours idle** (`SESSION_LIFETIME=120`), with no "Keep me signed in" | Staff are signed out several times a day and must type their password again on a phone keyboard |
| G-2 | **An expired page** (a tab left open past the session) brings up Livewire's own *browser* "This page has expired" dialog | It's a browser pop-up, the kind 34F removed everywhere else, and it gives no path back |
| G-3 | **No custom error pages** (`resources/views/errors` doesn't exist) | 403, 404, 419, 429, 500 and 503 show Laravel's plain pages, with no way back to the app |
| G-4 | **No offline handling** | On a lost connection, a tap silently does nothing |
| G-5 | **The app's nginx sends no compression**, no long-lived caching for the hashed `/build` files, and no security headers | Slower loads on mobile data; weaker browser protections |
| G-6 | **`per_page` is uncapped** on about 35 API list endpoints, and **search wildcards** (`%`, `_`) aren't escaped in 5 searches | Deferred since Phase 28 to this phase |
| G-7 | **Small items from 34–36:** the severity radios; no way to link an *existing* sign-in account to a staff record (36A); the Audit log's date filter in UTC (36D); the incident "resolve" messages in field-name wording (36C) | Minor friction |

## 3. Scope

**In:**
1. **Stay signed in (G-1).** A "Keep me signed in on this device" checkbox at sign-in (H-2).
2. **No dead ends (G-2, G-3):**
   - an expired session or page goes to the sign-in page with a plain message, then back to where the person was;
   - branded, phone-first error pages for 403, 404, 419, 429, 500 and 503, each with **Go to Home** (or **Sign in**).
3. **Offline (G-4).** A banner, "You're offline. Changes won't be saved until you reconnect.", which goes when the connection returns. A tap that fails because of the network says so; it doesn't fail silently.
4. **Speed and protection (G-5)**, in `docker/nginx/default.conf`:
   - gzip for HTML, CSS, JS, JSON and SVG;
   - `Cache-Control: public, max-age=31536000, immutable` for `/build/*`;
   - `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options` and `Permissions-Policy` (camera and geolocation kept for `self`).
5. **API hardening (G-6):**
   - `per_page` capped at 100 everywhere;
   - `%`, `_` and `\` escaped in every `q` search.
6. **Phone-first polish (G-7 and the walk-through, H-6):**
   - a walk-through of the eight everyday staff tasks at 360 px: sign in; Home; update a task's status; log work; request leave; a service report with a photo; report an incident; messages;
   - fix what slows a thumb (target sizes, the keyboard covering a button, input types, `autocomplete`, `enterkeyhint`);
   - plus the 34–36 items above.
7. **Verification:**
   - Chromium with Android and iPhone viewports and touch;
   - **real-phone UAT** by the product owner on an **iPhone (Safari)** and an **Android phone (Chrome)**.

**Out:**
- the Flutter app (H-1);
- new features;
- a Content-Security-Policy (H-5);
- push notifications;
- full offline use, meaning saving while offline and syncing later.

## 4. Decisions Needed (H-1…H-9), each with a recommendation

| # | Question | Recommendation |
|---|---|---|
| **H-1** | The Flutter app | **Paused.** No code, no APK, no app UAT, until you decide otherwise. The web app is the product for staff. The roadmap says so. The phone-app UATs for 30B and 31–33 (moved to Phase 38) wait with it. |
| **H-2** | Staying signed in | **"Keep me signed in on this device"**, unticked by default, lasting **30 days**. Signing out, a password reset or change, or a suspension ends it; the 36A code already clears the remember token. Without it ticked, sign-in lasts as now. Shared or borrowed phones: leave it unticked. |
| **H-3** | An expired page or session | **No browser pop-up.** Go to the sign-in page with "Your session ended. Please sign in again.", then return to the page they were on. |
| **H-4** | Error pages | **Our own look**, phone-first: a short title, one plain sentence, and **Go to Home** (or **Sign in**). 500 and 503 say "Something went wrong on our side" and never show technical details. |
| **H-5** | Security headers | **The four above now. No CSP yet:** Livewire and Alpine need careful CSP work, and a mistake would break the app on phones. It is recorded for Phase 39 (release readiness). HSTS stays with the host's TLS nginx (Phase 26). |
| **H-6** | Phone polish | **Fix what the walk-through finds** on the eight everyday tasks, list each fix in the handoff, and change nothing else. |
| **H-7** | API hardening | **Cap `per_page` at 100** (a larger request gets 100, not an error, so no client breaks) and **escape search wildcards**. |
| **H-8** | A shared loading/empty/error library | **Not needed.** The portal already shares `x-portal.error`, toasts, the confirm dialog and the progress bar. Phase 37 adds only an **empty-state** component where the same markup repeats, plus the offline banner. |
| **H-9** | Delivery and UAT | **One go, one UAT** on staging, run on **your own iPhone and Android phone**, plus a desktop browser for the Backoffice. |

## 5. Architecture Notes

- **H-2:** Laravel's `Auth::attempt($credentials, $remember)` plus `remember_token`.
  - The remember cookie is `HttpOnly`, `Secure` on staging and `SameSite=Lax`.
  - `EnsureAccountIsActive` already ends access for a suspended account.
- **H-3:**
  - A Livewire `request` hook in `resources/js/app.js` handles a 419 response, or a 401 from an expired session, by sending the browser to `/login?expired=1`.
  - `LoginForm` redirects to the intended URL after sign-in.
- **H-4:** `resources/views/errors/{403,404,419,429,500,503}.blade.php` on a small self-contained layout (no database, no Livewire), so they work even when the app is failing.
- **Offline:** a few lines in `app.js` (`online` and `offline` events) toggle a banner in both layouts. Livewire request failures show the same banner.
- **nginx:** container config only. The host's TLS nginx is unchanged.
- **The API changes are internal:** a shared `per_page` helper and a shared `like`-escaping helper. No response shape changes.

## 6. Testing and UAT

- **Feature tests:**
  - "keep me signed in" (the cookie is set, and cleared by sign-out, reset and suspension);
  - the redirect after sign-in;
  - each error page renders and links Home;
  - `per_page` is capped;
  - `%` and `_` searched literally.
- **Browser checks** (Playwright + axe AA):
  - the eight tasks at 360 px, with Pixel and iPhone emulation;
  - offline on and off (Playwright's `setOffline`);
  - an expired session mid-task;
  - error pages in light and dark.
- **Header check:** run against the built nginx image in CI's image job.
- **UAT** (drafted with the build, all `NOT RUN`):
  - on a real iPhone and Android phone: sign in with "Keep me signed in", close the browser, come back the next day;
  - the eight everyday tasks one-handed;
  - airplane mode mid-task;
  - an expired page;
  - an error page;
  - plus the Backoffice on desktop.

## 7. Risks

| Risk | Mitigation |
|---|---|
| A remember cookie on a lost phone | Unticked by default; ended by a password reset or a suspension in the Backoffice. |
| Caching serves a stale CSS file | Only the hashed `/build/*` files are cached long. HTML is never cached. |
| A header breaks something | No CSP. The four headers are tested in the browser checks. |

## 8. On Approval

1. Record the decisions as DEC-067. Update the roadmap: the Flutter app is paused, and Phase 37 is renamed.
2. Implement §3.
3. Test as in §6.
4. Update the docs and the handoff.
5. Stop for merge, deployment and UAT.

## 9. As Implemented (2026-10-16)

**All of §3 is implemented.** Handoff: `docs/handoffs/V1_PHASE_37_HANDOFF.md`.

1. **Stay signed in (H-2):**
   - `LoginForm` has a "Keep me signed in on this device" checkbox, unticked, with "For 30 days. Leave it off on a shared phone or computer.";
   - it sets the `web` guard's remember duration to 30 days before `Auth::attempt(…, $remember)`;
   - signing out cycles the remember token (Laravel's own logout), and a reset or suspension clears it (`Passwords::endSignIns`, 36A);
   - **changing your own password now rotates it** (`MyPasswordController`), so a remembered browser elsewhere is signed out.
2. **No dead ends (H-3, H-4):**
   - `resources/js/app.js` intercepts Livewire requests. A 419 or 401 reloads the page instead of Livewire's browser dialog; with the session gone, the reload lands on the sign-in page.
   - **Sign-in returns you to the page you asked for** (`redirectIntended`), with "Please sign in to continue." above the form.
   - **Error pages:** `resources/views/errors/{403,404,419,429,500,503}.blade.php` on a self-contained `errors/layout` (no database, session or Livewire).
3. **Offline:**
   - `partials/connection-banner` sits in the portal, Admin and sign-in layouts, hidden until needed;
   - it shows "You're offline. Changes won't be saved until you reconnect." on the `offline` event or when a Livewire request never reaches the server;
   - it shows "Something went wrong on our side. Please try again." on a 5xx, in place of Livewire's error modal;
   - it hides when the connection returns or the next request succeeds.
4. **nginx (H-5):**
   - `docker/nginx/default.conf` adds gzip (HTML, CSS, JS, JSON, the manifest, SVG, text) and the four headers on every response;
   - `/build/` adds `Cache-Control: public, max-age=31536000, immutable`;
   - the staging image job checks all of this on the built image.
5. **API (H-7):**
   - `App\Support\Http\PerPage::from()` keeps `per_page` between 1 and 100, used by 35 list endpoints. `/me/tasks` keeps its own cap of 50, and `/users` its fixed 25.
   - `App\Support\Database\Like` escapes `%`, `_` and `!` with `ESCAPE '!'` (the same on SQLite and MySQL), in the six `q` searches: staff, users, clients, contacts, projects and tasks.
   - The Audit log's `from`/`to` are company days.
   - **The incident resolve messages** are now plain: "Describe the resolution before resolving this incident." and "Give a corrective action (or record the immediate action taken) before resolving this incident."
   - The portal's Chat reads its messages 100 at a time, so "Load earlier messages" still reaches 600. The Admin's staff and organisation lists ask for 100 instead of 200.
6. **The phone walk-through (H-6)** of the eight tasks at 360 px (Pixel, light) and 390 px (iPhone, dark) found and fixed:
   - **the incident form's long fields were one-line inputs.** The shared field partial's `$type` parameter was overridden by the form's own `$type` property, so it was renamed `$input`;
   - **the severity choices** were 20 px radios; they are now 44 px pills;
   - **a report photo needed a second tap** on Upload; it now uploads as soon as it is chosen ("Uploading…" is announced);
   - **the sign-in fields** gained `inputmode="email"`, `autocapitalize="none"`, `spellcheck="false"` and `enterkeyhint` (next, go).
7. **The small items from 34–36 (G-7):**
   - an Administrator can **link an existing sign-in account** to a staff record (search accounts without a staff record) and **unlink** it, after a confirmation;
   - the severity pills, the Audit log's company days and the resolve messages, as above.
8. **H-8:** `x-portal.empty` replaces 11 copies of the empty-list markup.

**Deviations:**
- **H-3:** the expired session goes to `/login` through the reload, not `/login?expired=1`, and the sign-in page says "Please sign in to continue." rather than "Your session ended. Please sign in again." The page doesn't know whether the session expired or was never there, and the effect is the same.
- **The Backoffice** got the same error pages and banner, but no other phone polish (it stays a desktop tool, H-9).
