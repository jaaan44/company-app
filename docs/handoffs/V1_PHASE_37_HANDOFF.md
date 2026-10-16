# Phase 37 — Web Integration & Mobile-First UX Hardening — Handoff

**Status: implemented (2026-10-16), awaiting merge, deployment and UAT.** UAT-37-01…08 are `NOT RUN`. There is **no migration**. The deploy rebuilds both images: nginx gains compression, caching and headers.

## 1. Phase Identification

- **Phase:** 37 (DEC-067).
- **Date:** 2026-10-16.
- **Authorization:**
  - "go ahead but i would like you to skip the mobile flutter part. i want to prioritize mvp for web based frontend but make sure mobile first responsiveness is prioritized for staff who will use it with mobile phones";
  - then "approve as recommended" (H-1…H-9).
- **Specification:** `docs/phases/V1_PHASE_37_DEFINITION.md` (§9 as implemented).
- **Branch:** `claude/amazing-brahmagupta-dbsrjc`, from `main` at `30a458b`.

## 2. Objective

Make the web app a dependable MVP for staff on their phones:
- they stay signed in;
- they meet no dead end and no browser pop-up;
- it copes with a weak connection;
- the everyday tasks are quick with one thumb.

The Flutter app is paused.

## 3. Scope Implemented

All of §3 of the specification:
- Keep me signed in;
- no dead ends;
- the offline banner;
- nginx compression, caching and headers;
- the `per_page` cap and search escaping;
- the phone walk-through fixes;
- the small 34–36 items.

**Not included** (§3 "Out"):
- the Flutter app;
- new features;
- a CSP;
- push notifications;
- full offline use.

## 4. Implementation Summary

See §9 of the specification and `02_ARCHITECTURE.md` §49.

| Area | What |
|---|---|
| Sign-in | `remember` checkbox (30 days); `redirectIntended`; phone keyboard hints. The password change rotates the remember token. |
| JS | `Livewire.interceptRequest`: 419/401 reloads; 5xx and network failures show the banner. `online`/`offline` events. |
| Views | `errors/{layout,403,404,419,429,500,503}`; `partials/connection-banner` in the three layouts; `x-portal.empty`. |
| nginx | gzip, four headers, `/build/` immutable cache; CI checks them on the built image. |
| API | `PerPage` (1…100) in 35 controllers; `Like` in 6 searches; Audit log company days; plain resolve messages. |
| Portal | Chat pages 100 at a time; report upload on choose; the incident form's long fields; severity pills. |
| Backoffice | Link and unlink an existing sign-in account on the staff record; staff and organisation lists ask for 100. |

**Walk-through findings (H-6)**, at 360 px on the Pixel (light) and 390 px on the iPhone (dark), over the eight tasks:
1. **The incident form's four long fields rendered as one-line inputs.** The shared partial's `$type` was overridden by IncidentForm's own `$type` property. It was renamed `$input`; no other component passes a clashing name (checked by grep).
2. **Severity radios were 20 px.** They are now 44 px pills.
3. **A report photo needed a second tap ("Upload")**, easy to miss on a phone. It now uploads on choose.
4. **The sign-in email field** capitalised and spell-checked on phones. It now has `inputmode="email"`, `autocapitalize="none"`, `spellcheck="false"` and `enterkeyhint`.

Everything else in the eight tasks (targets, zoom on focus, the keyboard over buttons, sideways scroll) was already fine.

## 5. Files Changed

**New:**
- `app/Support/Http/PerPage.php` and `app/Support/Database/Like.php`;
- `resources/views/errors/` (7 files);
- `resources/views/partials/connection-banner.blade.php`;
- `resources/views/components/portal/empty.blade.php`;
- tests `tests/Feature/Auth/StaySignedInTest.php` and `tests/Feature/Api/V1/ListHardeningTest.php`;
- this handoff.

**Changed:**
- **Sign-in and account:** `Livewire/Auth/LoginForm` and its view; `MyPasswordController`.
- **The browser side:** `resources/js/app.js`; the three layouts.
- **API (35 list controllers):** `PerPage`; `Like` in Staff, UserRole, Client, Contact, Project and Task; `AuditLogController`; `ResolveIncidentReportRequest`.
- **Portal:** `Chat`, `ReportShow` and their views; `incident-form`, `report-field` and `service-report-form`; 11 views now use `x-portal.empty`.
- **Admin:** `StaffRecord` and its view; `Organization`; `StaffForm`; `AuditLog` (labels).
- **Infrastructure:** `docker/nginx/default.conf` and `.github/workflows/staging-images.yml`.
- **Tests:** `AppShellTest`, `ReportPagesTest`, `CommunicationPagesTest`, `PeopleAdminPagesTest` and `IncidentReportLifecycleTest`.

**Docs:**
- DECISIONS (DEC-067) and the Phase 37 definition (approved, §9);
- 02 (§49), 04, 05 and 06;
- ROADMAP, CURRENT_STATE, CHANGELOG, TEST_STATUS and UAT_LOG.

## 6. Database/Schema Changes

None. `users.remember_token` already existed.

## 7. API Changes

No new endpoint and no shape change (see `04_API_CONVENTIONS.md`):
- `per_page` is clamped to 1…100;
- `q` wildcards are literal;
- the Audit log's `from`/`to` are company days;
- two resolve messages are reworded.

## 8. Authorization/Security Changes

See `05_SECURITY_MODEL.md` "Web Sessions, Error Pages and Headers".
- **Remember cookie:** 30 days, opt-in, `HttpOnly`/`Lax`/`Secure`. It ends on sign-out, a password change, a reset or a suspension.
- **Error pages** never show details.
- **Four security headers.** No CSP (Phase 39).
- **Linking an account** goes through `PATCH /staff/{id}`, whose rules are unchanged (Administrators; one staff record per account).

## 9. Tests Added or Changed

- **`StaySignedInTest` (11):** the checkbox; no cookie without it; the 30-day cookie and a returning browser; a wrong cookie; ended by sign-out, password change, reset and suspension; the intended page; must-change first; all six error pages and a real 404; the banner in all layouts.
- **`ListHardeningTest` (3):** the `per_page` bounds; literal `%`, `_` and `!`; Audit log company days.
- **`PeopleAdminPagesTest` (+1):** link and unlink.
- **`CommunicationPagesTest` (+1):** Chat past 100 messages.
- **Extended:**
  - `ReportPagesTest`: textareas, pills, upload on choose;
  - `IncidentReportLifecycleTest`: the resolve messages;
  - `AppShellTest`: exactly one `role="status"`, the hidden banner.

## 10. Commands/Checks Executed

- **In `apps/api`:**
  - `composer validate --strict` and `composer audit --locked`;
  - Pint and PHPStan;
  - `php artisan test`;
  - `npm run build`.
- **Mutation checks:** 16 run.
- **Playwright** (Chromium, axe-core 4.10.2) on scratch SQLite:
  - phones: Pixel 360 light and iPhone 13 dark, with touch and `setOffline`;
  - the Backoffice at 1280 dark and 360 light.

## 11. Results

- **Backend:** 1,484/1,484. Pint, PHPStan and Composer are clean.
- **Browser:** 46/46 on phones and 20/20 on the Backoffice. **axe AA: 0 violations.** No JS errors or browser dialogs.
- **Screenshots reviewed:** the 404 page (Pixel, light). The incident form was checked by its assertions (four `textarea`s), not by eye.
- **Mutations:** 15/16 caught; the survivor is equivalent (see TEST_STATUS).
- **Found and fixed during the build:**
  - **Chat** would have lost "Load earlier" beyond 100 messages under the cap, so it now pages;
  - **the nginx `/build/` block** would have sent two `Cache-Control` headers with `expires`, so it has one.
- **Not run here:**
  - MySQL;
  - **the nginx header check** (no Docker; CI's image job runs it);
  - staging;
  - UAT.

## 12. Deviations from Specification

- **H-3:** the expired session reaches `/login` by a reload, not `/login?expired=1`. The message is "Please sign in to continue." rather than "Your session ended. Please sign in again." The page can't tell an expired session from no session, and the outcome is the same.
- **Literal searches** cover six `q` searches (the specification estimated five), plus `!`, the escape character.

## 13. Known Issues/Limitations

- **The remember cookie and the 2-hour session:** without the box ticked, staff are still signed out after 2 idle hours (`SESSION_LIFETIME=120`, unchanged).
- **The offline banner** says a change wasn't saved; it doesn't keep the change. A form keeps its typed values, so Save works once back online. Full offline use stays out of scope.
- **A 5xx in a Livewire request** shows the banner, not the error page. A full page load shows the 500 page.
- **No CSP** (Phase 39). HSTS is the host nginx's (Phase 26).
- **The Backoffice** has the error pages and banner but no other phone polish, by design.
- **The Flutter app is paused**, with its UATs (30B, 31–33) waiting.

## 14. Manual/UAT Testing Instructions

A runbook will be written after merge: `docs/testing/PHASE_37_UAT_PREPARATION.md`. It will cover:
- the deploy (no migration; both images rebuilt; a `curl` header check);
- UAT-37-01…08 on the product owner's own iPhone and Android phone and a computer.

## 15. Documentation Updated

See §5.

## 16. Recommended Next Step

1. Merge on green CI.
2. Write the runbook.
3. Deploy, and run UAT-37 on real phones.
4. Then Phase 38 (UAT) or 39 (release readiness), each needing its own authorization.
