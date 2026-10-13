# Phase 34A — Staff Web Portal: Foundation — Handoff

**Status: implemented; awaiting merge, staging deployment and UAT.** UAT-34A-01…06 are `NOT RUN`. 34B–34E are **not authorized** (DEC-063 W-10).

## 1. Phase Identification

- **Phase:** 34A — Staff Web Portal, Foundation (the first sub-phase of Phase 34).
- **Date:** 2026-10-13
- **Specification:** `docs/phases/V1_PHASE_34_DEFINITION.md` revision 1. Decisions W-1…W-10 were approved "as recommended" by the product owner, who also authorized 34A (DEC-063).
- **Flagged sensitive:**
  - the web login now admits every active account;
  - the staging images gain a Node build stage.
- **Branch:** `claude/amazing-brahmagupta-dbsrjc`, from `main` at `e324114` plus the draft specification (PR #89).

## 2. Objective

The mobile app's staff functions in a browser, built with Laravel. 34A lays the foundation:
- one login for everyone;
- the layout and the web design system;
- Home, My profile and the Staff directory;
- a CI build of the staging images.

## 3. Scope Implemented

The whole of 34A as specified in §7 and §11 of the specification. Nothing from 34B–34E.

## 4. Implementation Summary

- **`App\Services\Portal\InternalApi`** (W-1) dispatches `/api/v1` requests in-process through the HTTP kernel, as the web session user. It:
  - copies the client identity (IP, user agent, forwarded headers) and asks for JSON;
  - marks the request with an attribute (`portal.internal`) for the audit source;
  - always runs the API's middleware, even when the calling request has it disabled, as Livewire's test harness does;
  - restores the outer request, the router's current route and the default auth guard;
  - throws `PortalApiException` (status, message, field errors) for any non-2xx answer.
- **`CallsInternalApi`** (component trait) maps failures:
  - 401: sign out, then the login page with "Your session has ended. Please sign in again.";
  - 403: the no-profile state on `/me/*` pages, or the page's own forbidden message;
  - anything else: an error with "Try again".
- **Components:** `App\Livewire\Portal\{Home, Profile, Directory, StaffShow}`.
  - API data is fetched in `render()` and never stored in public properties, so it is never in the client-side snapshot (pinned by a test).
  - The directory keeps `q` and `page` in the URL.
- **`App\Support\Portal\Format`** ports the mobile formatting: company-time ranges, multi-day "From … / until …", activity and task-status labels, the role line, dates.
- **Login (W-3/W-4):** the `admin.access` refusal is removed, and the landing page is `/app`.
  - `redirectUsersTo` sends a signed-in user away from `/login` to `/app`.
  - `/` redirects to `/app`, and the unused `welcome` view is deleted.
  - The login page is restyled and shows the session-ended notice.
- **Audit (W-6):** `AuditSource::Web`.
  - `recordForRequest()` defaults to `Web` for marked requests and `Api` otherwise.
  - Login and logout use `Web`.
- **Design (W-5):**
  - Tailwind v4 tokens (light and dark), the system font stack, scanning only `resources/views`;
  - the `fonts.bunny.net` fetch is removed from `vite.config.js`, since the build failed offline and pages should load no third-party resource;
  - layouts `components/layouts/portal` and `guest`; components `portal/error` and `portal/info-row` (Copy through Alpine).
- **Docker:** both staging Dockerfiles gain a `node:22-alpine` `assets` stage (`npm ci`, `npm run build`), and copy only `public/build`. An isolated build of the stage's inputs produces the same manifest as a full local build.
- **CI:** `.github/workflows/staging-images.yml` builds both images and checks them:
  - the manifest is present;
  - `12M`/`13M` upload limits;
  - no `.env` and no `node_modules`;
  - nginx `client_max_body_size 12m`.
- **Tests:** `TestCase` calls `withoutVite()`.

## 5. Files Changed

**New:**
- `app/Services/Portal/{InternalApi,PortalApiException}.php`;
- `app/Livewire/Portal/{Home,Profile,Directory,StaffShow}.php`;
- `app/Livewire/Portal/Concerns/CallsInternalApi.php`;
- `app/Support/Portal/Format.php`;
- views:
  - `components/layouts/{portal,guest}`;
  - `components/portal/{error,info-row}`;
  - `portal/{home,profile,directory,staff}`;
  - `livewire/portal/{home,profile,directory,staff-show}`;
- tests: `tests/Feature/Portal/{InternalApiTest,PortalPagesTest}.php`, `tests/Unit/Support/Portal/FormatTest.php`;
- `.github/workflows/staging-images.yml`;
- this handoff.

**Changed:**
- `app/Enums/AuditSource.php`, `app/Services/Audit/AuditLogger.php`, `app/Livewire/Auth/LoginForm.php`;
- `routes/web.php`, `bootstrap/app.php`;
- views: `auth/login`, `livewire/auth/login-form`;
- `resources/css/app.css`, `vite.config.js`;
- tests: `tests/TestCase.php`, `tests/Feature/Auth/AdminLoginTest.php` (3 tests updated to the approved behavior), `tests/Feature/ExampleTest.php`;
- `docker/php/Dockerfile.staging`, `docker/nginx/Dockerfile.staging`.

**Deleted:** `resources/views/welcome.blade.php`.

**Docs:**
- `CLAUDE.md` §5 and `README.md`;
- 02 §43, 04, 05, 06;
- DECISIONS (DEC-063), ROADMAP (Phase 34 inserted and renumbering), the Phase 34 definition (the draft renamed);
- DEPLOYMENT_STAGING;
- CURRENT_STATE, CHANGELOG, TEST_STATUS, UAT_LOG.

## 6. Database/Schema Changes

None. `audit_logs.source` is a string column; the new `web` value needs no migration.

## 7. API Changes

None. The API is consumed in-process with the same contract.

## 8. Authorization/Security Changes

See `05_SECURITY_MODEL.md` "Staff Web Portal". In short:
- **The web login admits every active account.** The Admin Backoffice keeps `can:admin.access` (a staff account gets `403` on `/home`, tested).
- **Portal pages** require `auth` and `account.active`.
- **All data goes through the API's own checks.** No token is created.
- **Audit:** login, logout and portal writes record `web`.
- **No third-party resource** is loaded by any page.

## 9. Tests Added or Changed

- **`InternalApiTest`** (11 tests):
  - acts as the session user without a token;
  - request, route and default guard are restored;
  - permissions decide (`403`); `account.active` applies in-process (`403`);
  - field-by-field `422`; `404`; `401` without a user;
  - a portal write is audited `web`, while the same write through the mobile API stays `api`;
  - API middleware runs even when the caller skips middleware;
  - a client cannot mark a request as internal.
- **`PortalPagesTest`** (16 tests):
  - guests are sent to login; all four roles open every page; a suspended account is signed out;
  - the Admin link only for administrators;
  - Home (greeting, role line, team, Today with Manila times, counts, announcements) and Home without a profile;
  - Profile and Profile without a profile; API data is absent from the snapshot;
  - Directory list, search, empty result, clear, pages of 25, no permission;
  - the colleague page, an unknown id, an invalid id;
  - an ended session (`401`); `403` shows the no-profile state on Home and Profile; a `500` offers Try again.
- **`FormatTest`** (4 tests).
- **Updated:** 3 `AdminLoginTest` tests and the `ExampleTest`, to the approved behavior.

## 10. Commands/Checks Executed

In `apps/api`:
- `composer validate --strict` and `composer audit --locked` (no Composer change);
- `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test`;
- `npm ci`, `npm run build`, and an isolated build of the Docker stage's inputs (identical manifest).

Browser checks: Playwright with Chromium against `php artisan serve` on a scratch SQLite database, using axe-core 4.10.2. Mutation checks against the portal tests.

## 11. Results

- **Backend:** 1,284/1,284 tests pass; Pint and PHPStan are clean.
- **Browser:** 50/50 checks pass, in light and dark:
  - login, landing, greeting, Today, announcements, no Admin link for staff;
  - directory pages, live search in the URL, colleague page, Back keeps the search;
  - profile, Copy email (clipboard verified);
  - skip link first in the Tab order;
  - no horizontal scroll at 320 px on all three pages;
  - logout, and no JavaScript errors;
  - **axe WCAG 2.0, 2.1 and 2.2 AA: no violations** on login, Home, directory and profile in both schemes;
  - the administrator's Admin link opens the backoffice; a signed-in user on `/login` goes to `/app`;
  - for an account without a profile: the no-profile messages, and `/home` forbidden.
- **Mutations:** 10 run.
  - 8 were caught.
  - Two survived and led to fixes:
    - Handing the outer session to the inner request had no effect, so it was removed.
    - A `403` on Home or Profile rendered a blank page, so the views now show the no-profile state, and a test pins it.
- **Not run here:** a real Docker build (no daemon in this environment; CI now does it), MySQL, the staging deployment, and UAT.

## 12. Deviations from Specification

- **W-6:** login and logout also record `web`, not only portal actions. The login is the shared web door, so `admin` is kept for future Admin Backoffice actions.
- **The draft said "Home's tiles link to their screens".** In 34A they don't, because those screens don't exist yet; this follows the mobile app's own Phase 27 rule. They become links in 34B and 34D.

## 13. Known Issues/Limitations

- **Local Docker development** (`docker-compose.yml`) needs `npm ci && npm run build` on the host for the browser pages (README).
- **Livewire test harness:** it rethrows exceptions instead of rendering them, so in `Livewire::test` an API `401` or `422` reaches the test as an exception. The client's own tests cover those through a real HTTP request; component tests use a test double for failures.
- **The first staging build** after this change downloads the Node image and npm packages, so it takes a few minutes longer.

## 14. Manual/UAT Testing Instructions

A runbook will be written after merge, naming the merge SHA: `docs/testing/PHASE_34A_UAT_PREPARATION.md`. It covers:
- the staging deploy (`BUILD OK` rule);
- smoke tests, including that the built CSS is served;
- UAT-34A-01…06 with the UAT30B accounts and your administrator account, in a desktop and a phone browser.

## 15. Documentation Updated

See §5.

## 16. Recommended Next Step

1. Merge on green CI. This is the first run of the new staging-images workflow.
2. Write the runbook, deploy to staging and run UAT-34A.
3. After that, 34B (Work) needs its own authorization.
