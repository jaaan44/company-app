# Phase 34F — Staff Web Portal: Portal as an app — Handoff

**Status: implemented and tested (2026-10-13); awaiting merge, staging deployment and UAT.** UAT-34F-01…07 are `NOT RUN`.

## 1. Phase Identification

- **Phase:** 34F — Staff Web Portal, Portal as an app (a sixth sub-phase of Phase 34, added after 34E closed; DEC-064).
- **Date:** 2026-10-13
- **Request:** "can you improve the look of the web based frontend so that it looks and functions more like a mobile app?"
- **Authorization:** "approve 34f as recommended" (product owner), for F-1…F-6 as proposed, placed before Phase 35.
- **Specification:** `docs/phases/V1_PHASE_34_DEFINITION.md` §16.
- **Branch:** `claude/amazing-brahmagupta-dbsrjc`, from `main` at `201a980` (34E closed).

## 2. Objective

Make the Staff Web Portal look and move like the mobile app, without changing what any page can do.

## 3. Scope Implemented

F-1 to F-6 as listed in §16:
- the app's tabs and More page;
- the app bar;
- the app's look, with FABs;
- toasts and in-app dialogs;
- navigation without full reloads;
- an installable manifest.

**Not included, as proposed:** a service worker, offline mode, push notifications, and any new function. 34C's inline confirmation panels (Cancel request, Approve, Reject) keep their design, because they hold a text field.

## 4. Implementation Summary

- **Shell (`components/layouts/portal.blade.php`, rewritten):**
  - **Tabs:** Home, Tasks, Schedule and Messages, each with the route names it covers (a task under Tasks, a chat or New message under Messages). More covers every other portal route.
  - **Phones:** a fixed bottom bar, `grid-cols-5`, with safe-area padding.
  - **From `md`:** a sidebar with the brand, the tabs, Admin (`@can('admin.access')`) and Log out.
  - **App bar:** sticky, with the title. A back arrow comes from the page (`x-portal.back`, now `@push('appbar-back')`), or else goes to More for the ten pages More lists.
  - **Main column:** `max-w-3xl`, padded to clear the tab bar.
  - **Toast:** the session `status` flash.
- **More (`portal/more.blade.php`, route `portal.more`):** the app's More list, with icons, subtitles and chevrons, then Admin and Log out.
- **Components** (`components/portal/`):
  - `icon` (inline SVG, `aria-hidden`);
  - `fab` (a pill button from `md`, a 56 px "+" with an `aria-label` on phones);
  - `toast` (`role="status"`, Alpine auto-hide, keyed so a repeated message shows again);
  - `confirm` (a trigger button and a modal `<dialog>`; Cancel `autofocus`; the confirm button closes the dialog and calls the Livewire action);
  - `back` (rewritten for the app bar).
- **Look (`resources/css/app.css`):**
  - M3 indigo light and dark tokens (with `danger-soft`, `success` and `success-soft`);
  - larger radii;
  - a `.chevron` row marker (CSS mask);
  - `dialog.app-dialog` (a bottom sheet on phones, centred from `md`, with a dimmed backdrop).
- **Pages:**
  - the duplicate visible `h1`s became `sr-only` (the app bar shows the title);
  - list rows gained chevrons;
  - success paragraphs became toasts (`ReportShow` gained a `toastNonce` so "File added." twice shows twice);
  - `wire:confirm` became `x-portal.confirm` (work log Delete; report Submit, Delete draft, Return to draft, Remove file);
  - FABs: Log work, Request leave, New message, New service report / Report an incident;
  - Staff detail's "← Staff directory" link moved to the app bar.
- **Navigation (`resources/js/app.js`):** a click handler passes plain same-origin `/app` links to `Livewire.navigate()`. Every portal `redirect()` passes `navigate: true`. Livewire's progress bar is indigo (`config/livewire.php`).
- **Install:**
  - `public/manifest.json`;
  - `public/icons/` (192, 512, maskable 512, Apple touch; generated, no third-party artwork);
  - `partials/app-head.blade.php`, shared by the portal and the sign-in page, which also gains the brand mark.
- **CI:** `staging-images.yml` now also checks that the nginx image holds `manifest.json` and an icon.

## 5. Files Changed

**New:**
- `resources/views/portal/more.blade.php`;
- `resources/views/partials/app-head.blade.php`;
- `components/portal/{icon,fab,toast,confirm}.blade.php`;
- `public/manifest.json`, `public/icons/*.png`;
- `tests/Feature/Portal/AppShellTest.php`;
- this handoff.

**Changed:**
- the portal and guest layouts and `components/portal/back`, `leave-row`;
- 30 Livewire portal views;
- 7 portal components (redirects, plus `ReportShow`'s toast nonce);
- `routes/web.php` (one route);
- `resources/css/app.css`, `resources/js/app.js`, `config/livewire.php`;
- `.github/workflows/staging-images.yml`;
- 5 portal test files;
- docs: 02, 05, 06; DECISIONS (DEC-064); the Phase 34 definition (§16); ROADMAP; CURRENT_STATE; CHANGELOG; TEST_STATUS; UAT_LOG.

## 6. Database/Schema Changes

None.

## 7. API Changes

None.

## 8. Authorization/Security Changes

None to the rules. `/app/more` sits behind the same `auth` and `account.active`. In-app navigation uses the same session and middleware. The manifest and icons hold no user data. There is no service worker, so nothing is cached for offline use. See `05_SECURITY_MODEL.md`.

## 9. Tests Added or Changed

- **New `AppShellTest`** (10 tests):
  - the five tabs in order;
  - the current tab on 8 pages, checking that exactly one tab is current in each bar;
  - More's order and subtitles; Admin only for administrators;
  - the back arrow to More on its ten pages and on no tab;
  - a page's own back arrow (a work log form, a colleague);
  - the app bar title;
  - the flash as a toast, and no toast without one;
  - the manifest link, the Apple icon and `viewport-fit` on the portal and the sign-in page;
  - the manifest's start, display and maskable icon, with every icon present as a PNG of the right size.
- **Changed:** the four "navigation lists…" tests now check each area's tab or More entry and its current tab. The Admin link assertion follows the new markup. Report Submit checks the dialog, its wording and its action. `/app/more` joins `PortalPagesTest`'s pages for guests and every role.

## 10. Commands/Checks Executed

- In `apps/api`:
  - `composer validate --strict`, `composer audit --locked`;
  - `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`;
  - `php artisan test`, `npm run build`;
  - `php artisan view:cache`.
- Mutation checks on the layout.
- **Playwright** with Chromium and axe-core 4.10.2, against `php artisan serve` on a scratch SQLite database:
  - a 390 × 844 touch phone, then 320 × 640;
  - a 1280 × 900 desktop;
  - light and dark.

## 11. Results

- **Backend:** 1,381/1,381 tests pass. Pint, PHPStan, Composer validate and audit are clean.
- **Browser: 94/94 checks pass.**
  - **Phone:**
    - the bottom tabs without the sidebar; Home current;
    - Tasks, Schedule, Messages and More **without a page reload** (a `window` marker survives);
    - More's order;
    - the app bar title, and the back arrow to More;
    - the Log work FAB, not the pill; the form's back arrow;
    - a work log saved with a "Work logged." toast above the tab bar that hides after 4.5 s, with no reload.
  - **The Delete dialog:**
    - a bottom sheet, with Cancel focused;
    - Escape and Cancel keep the log;
    - Delete removes it, with a toast;
    - **no browser dialog at any point**.
  - **A service report** from the FAB: a draft saved, then submitted through the dialog with no reload.
  - **Layout:**
    - the last content clears the tab bar;
    - no horizontal scroll at 320 px on 8 pages;
    - the manifest and all four icons served as JSON and PNG;
    - no JavaScript errors.
  - **Desktop:**
    - the sidebar without the tab bar;
    - More current on Reports, with no reload;
    - the pill, not the FAB;
    - the Return to draft dialog centred.
  - **axe WCAG 2.x AA: 0 violations** on 6 states (sign-in, Home, More, work logs with a toast, an open dialog, desktop Reports) × 2 schemes.
- **Screenshots reviewed:** Home, More, work logs, the dialog (phone and desktop) and desktop Reports, light and dark.
- **Mutations:** 6 run on the layout, all caught:
  - New message not under Messages;
  - My profile without its back arrow;
  - More always current;
  - a page's own back arrow ignored;
  - no toast;
  - a fixed app bar title.
- **Not run here:** MySQL, the staging deployment, a real phone's "Add to Home screen", and UAT.

## 12. Deviations from Specification

None. Two notes:
- The phone FAB and the wider-screen pill are two elements; only one is shown at a time, so screen readers meet one.
- Toasts announce through `role="status"`. A toast that comes with a redirect is rendered by the next page, as the flash was before.

## 13. Known Issues/Limitations

- **Back arrows are fixed parents,** not browser history (as the app's own back for these pages). The browser's back button still works, including after in-app navigation.
- **Installing** depends on the browser. Chrome and Edge offer "Install app"; iOS Safari uses Share → Add to Home Screen. The installed app is the website in its own window, online only.
- **The Admin Backoffice keeps its own look.** Phase 35 reuses these tokens (DEC-063 W-5).

## 14. Manual/UAT Testing Instructions

A runbook will be written after merge: `docs/testing/PHASE_34F_UAT_PREPARATION.md`, covering the deploy and UAT-34F-01…07. No new data is needed; the scenarios reuse the existing UAT accounts and data.

## 15. Documentation Updated

See §5.

## 16. Recommended Next Step

1. Merge on green CI.
2. Deploy to staging and run UAT-34F.
3. Close 34F. Phase 35 (Admin Backoffice) needs its own authorization.
