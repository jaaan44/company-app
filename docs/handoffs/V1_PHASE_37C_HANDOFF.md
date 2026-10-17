# Phase 37C — Home Redesign for Phones — Handoff

**Status: implemented; CI green** on PR #141 (`12a1319`); awaiting review, merge and UAT. **No migration, no API change, no new dependency.**

## 1. Phase Identification

- **Phase:** 37C (DEC-069), Home redesign for phones.
- **Date:** 2026-10-17.
- **Authorization:** the product owner's "Mobile Home Dashboard UI/UX Redesign" brief and the reference image (now `docs/design/mobile-home-reference.png`).
- **Branch:** `claude/mobile-home-redesign`, from `main` at `c72cfef`.

## 2. Objective

Make the staff portal's Home look and feel like a polished native mobile screen, closely following the reference, while keeping its data, links, permissions and states.

## 3. Scope Implemented

- **Hero:**
  - a navy-to-blue gradient under the status bar on phones (in place of the app bar);
  - the app's mark, the initials (a link to My profile), "Hello, *name*", and the role line and team (or "Good to see you again!");
  - from `md` up, a rounded panel below the app bar, without the duplicate mark.
- **Today:** a blue-tinted card with a calendar icon and "Today · *date*". Each item has its title, kind and time, and a round chevron, and opens its task or entry. It shows the empty message and "+N more today" as before.
- **Needs attention:** pastel cards with round icons, each opening its screen:
  - tasks (rose: open, overdue, due today);
  - messages (blue: unread or "No unread messages");
  - notifications (green, across the row on phones).

  Two columns on phones; three from `lg`.
- **Latest announcements:** a list card with a violet megaphone, title, date and chevron, and a "View all" heading link (heard as "View all announcements"). The empty state uses `x-portal.empty`.
- **States:** the no-profile, error and no-staff-record states keep their messages, under a hero titled "Home" (or the greeting).
- **Bottom tab bar** (every portal page): raised, with rounded top corners and a soft shadow; the current tab is a filled accent-soft pill around its icon and label. The tabs, badge, `aria-current` and safe-area padding are unchanged.

## 4. Implementation Summary

- **`resources/css/app.css`:**
  - Home tokens: `hero-from/to/sub`; `card-*`, `tint-*` and `icon-*` for rose, blue, green and violet; `shadow-card`, `shadow-bar`; `radius-3xl`;
  - a dark value for each.
- **New `x-portal` components:** `home-hero`, `summary-card`, `section-header` and `announcement-row`. Icons: `flag`, `chevron`, `today` and `company`.
- **`livewire/portal/home.blade.php`:** rebuilt from these. The `Home` component is unchanged.
- **Layout:**
  - `components/layouts/portal.blade.php` gains `phoneAppBar` (default true); `portal/home.blade.php` sets it false;
  - the bottom bar's classes change.

## 5. Files Changed

- **New:**
  - `resources/views/components/portal/{home-hero,summary-card,section-header,announcement-row}.blade.php`;
  - `docs/design/` (the reference, three static previews and a README);
  - this handoff.
- **Changed:**
  - `resources/css/app.css`;
  - `resources/views/components/portal/icon.blade.php`;
  - `resources/views/components/layouts/portal.blade.php`;
  - `resources/views/portal/home.blade.php`;
  - `resources/views/livewire/portal/home.blade.php`;
  - `tests/Feature/Portal/PortalPagesTest.php` and `CommunicationPagesTest.php`.
- **Docs:** DECISIONS (DEC-069), 06, ROADMAP, CURRENT_STATE, CHANGELOG, TEST_STATUS and UAT_LOG.

## 6. Database/Schema Changes

None.

## 7. API Changes

None. Home reads the same `GET /me/home` fields.

## 8. Authorization/Security Changes

None. Every link goes where it did; the profile link is the existing My profile page.

## 9. Tests Added or Changed

- **`PortalPagesTest` (Home):** the hero's profile link and initial, and the app bar hidden on phones. The existing greeting, Today, counts and announcements assertions are kept.
- **`CommunicationPagesTest`:** "All announcements" becomes "View all" (with " announcements" for screen readers).
- **Kept by design:** the `class="block rounded-xl` prefix on the tasks, messages and notifications cards, "No unread notifications", and the greeting's order.

## 10. Commands/Checks Executed

- `npm run build`.
- The Blade structure check script.
- A static layout preview with Playwright and Chromium at 360, 390 (light and dark) and 1280 px, including a horizontal-overflow check.
- `php -l` on the changed tests.

## 11. Results

- **Build and structure:** pass.
- **Preview:**
  - close to the reference;
  - no horizontal overflow at any width;
  - refined after a first pass: softer card borders, Notifications stacking in the three-column row, no duplicate app mark on desktop.
- **CI on PR #141 (`12a1319`):** Pint, PHPStan and 1,501/1,501 tests, and the staging images build. The first run failed only the new "View all" assertion (Livewire's block markers), fixed in the test.
- **Not run here:** the real page in a browser.

## 12. Deviations from the Brief

- **Brand colours:** the suggested `#2563EB`/`#163B70` palette is applied to Home's hero and cards, **not app-wide** (DEC-069 §1). Switching the whole app is a separate decision.
- **Needs attention has no "View all"**, because there's no single destination for three different cards.
- **Announcement icons** are one megaphone, since the API has no category.
- **"Good to see you again!"** shows only when there's no role or team, which otherwise make a more useful line.
- **The initials** are one letter when the person has a preferred name (the API's display name), as in the reference ("K").

## 13. Known Issues/Limitations

- **No real-browser check yet:** the previews are static copies of the markup (`docs/design/README.md`).
- **The hero sits under the iOS status bar** only when the app is installed to the Home Screen. In Safari, the browser's own bar stays above it.
- **The 37B items** (deploy, UAT-37B) are still pending, independent of this phase.

## 14. Manual/UAT Testing Instructions

UAT-37C-01…03 in `docs/testing/UAT_LOG.md` (NOT RUN), after deployment.

## 15. Documentation Updated

See §5.

## 16. Recommended Next Step

1. Review the PR (and the previews in `docs/design/`).
2. Merge on green CI with your approval.
3. Deploy together with 37B, and run UAT-37B and UAT-37C.
