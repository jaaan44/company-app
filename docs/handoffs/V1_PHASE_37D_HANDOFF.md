# Phase 37D — Staff Portal Visual Refresh — Handoff

**Status: merged as `f9d7092` (PR #145, 2026-10-24); awaiting deployment and UAT-37D-01…04 (`NOT RUN`).** PR #145 merged while its CI was red on a new Livewire advisory at the audit step; the full CI (Pint, PHPStan, tests) first ran on PR #146 (Livewire 4.4.8, merged as `77aab0f`), green. Home's My team was replaced by Employees in Phase 37E (DEC-072). **No migration, no API change, no new dependency.**

## 1. Phase Identification

- **Phase:** 37D (DEC-071), staff portal visual refresh.
- **Date:** 2026-10-24.
- **Authorization:**
  - "Authorize Phase 37D, web portal only, all screens should be moved to this style", with the reference (now `docs/design/portal-style-reference.webp`);
  - then "Approved, go with your recommendations on all four" on `docs/phases/V1_PHASE_37D_DEFINITION.md`.
- **Branch:** `claude/admiring-planck-hictyu`, from `main` at `ab24aac`.

## 2. Objective

Move every staff-portal screen to the reference's style, keeping every route, permission, API call, wording and behaviour.

## 3. Scope Implemented

- **Palette (Q1):**
  - royal blue `#2563EB` on a grey-blue page `#F4F6FA`, white cards, in light and dark;
  - kind colours (blue, green, amber, rose, violet, slate) app-wide;
  - the browser theme colour and the web manifest updated.
- **Shell:**
  - an app bar on the page colour with a bold title and no rule;
  - the sidebar and bottom bar recoloured (37C's shapes kept).
- **Shared styles:**
  - a `card` utility for every container;
  - fields `rounded-lg`;
  - chips as pills;
  - list filters as `filter-chip` buttons (the chosen one filled blue);
  - alerts `rounded-xl`.
- **New components:**
  - `x-portal.icon-tile`;
  - `x-portal.avatar` (initials, a tint from the name, an optional status dot);
  - `x-portal.status-dot` (dot plus its word);
  - `x-portal.stat-chip`.
- **Updated components:** `section-header` (every screen; "View all" in accent), `announcement-row`, `leave-row` and `chip`. `summary-card` is removed (no longer used).
- **Home (Q2):**
  - hero (Q4: 37C's gradient kept);
  - **quick actions:** New task (with `tasks.create`, filled blue), Message, Request leave, My status, More;
  - Today as rows with icon tiles;
  - **My work** chips (due today, open, overdue) opening Tasks;
  - **Needs attention:** Messages and Notifications rows with a red count;
  - **My team:** up to six active teammates with their status, from `GET /staff?team=…`;
  - Latest announcements.
- **Lists:**
  - Tasks: status tile, due-date pill (red for today or overdue), a flag for High and Urgent;
  - Messages: initials for a direct chat, a round tile for a group or project chat, a bold unread title, a red bubble;
  - Notifications: a tile by kind, the time on the right, the unread dot;
  - Schedule: a coloured dot per kind and bold titles;
  - Work logs, Projects, Clients, Reports, Announcements (and Manage), Leave and Team requests: icon tiles;
  - Staff directory and New message: avatars, and status for those who may see it;
  - More: coloured tiles, in the same order.
- **Pages:**
  - a large icon tile above the title on Task, Project, Client, Report, Schedule entry, Leave request and Announcement;
  - a header card with a large avatar on a colleague's page and My profile;
  - My status shows the current status's colour, and its history shows status dots;
  - Leave balances are cards with a tile;
  - chat bubbles are rounded with a tail corner.
- **Guest and error pages:** the new card and palette.

## 4. Implementation Summary

- `resources/css/app.css`: new token values (with `edge`, amber and slate kind colours), the `card` and `filter-chip` utilities, and `field` at `radius-lg`.
- `App\Support\Portal\Format`: `operationalTone()`, `tileTone()` and `initials()`.
- `App\Livewire\Portal\Home`: `team()` (the My team call), plus `mayAddTasks` and `mayViewStatus`.
- `Directory` and `StaffShow`: pass `mayViewStatus`.
- The views were edited to the new components and classes. A scripted pass swapped `rounded-xl border border-line bg-raised` for `card`, and the segmented controls for filter chips.

## 5. Files Changed

- **New:**
  - `components/portal/{avatar,icon-tile,status-dot,stat-chip}.blade.php`;
  - `docs/design/portal-style-reference.webp` and five `portal-preview-*.webp`;
  - `docs/phases/V1_PHASE_37D_DEFINITION.md`;
  - this handoff.
- **Removed:** `components/portal/summary-card.blade.php`.
- **Changed:**
  - `resources/css/app.css`, `public/manifest.json`, `partials/app-head.blade.php`;
  - the portal and guest layouts, the error layout;
  - every `livewire/portal/*` view and partial, `livewire/account/change-password`, `portal/more`;
  - `Format.php`, `Home.php`, `Directory.php`, `StaffShow.php`;
  - tests: `PortalPagesTest`, `AppShellTest`, `CommunicationPagesTest`, `WorkPagesTest`, `UiImprovementTest`.
- **Docs:** DECISIONS (DEC-071), 06, ROADMAP, CURRENT_STATE, CHANGELOG, TEST_STATUS, UAT_LOG and `docs/design/README.md`.

## 6. Database/Schema Changes

None.

## 7. API Changes

None. Home makes one more read, `GET /staff` (existing, `staff.view`), for My team.

## 8. Authorization/Security Changes

None to permissions:
- quick actions only link to pages the person can already open (New task needs `tasks.create`);
- statuses show only with `staff-status.view`;
- My team needs `staff.view` and a team.

## 9. Tests Added or Changed

- **New (`PortalPagesTest`):**
  - quick actions by permission (staff: no New task; an administrator with a profile: New task first);
  - My team (a teammate with status shown; not herself, other teams or separated staff);
  - no My team without teammates;
  - directory and colleague-page status for `staff-status.view` holders, and none for a role with only `staff.view`.
- **Changed to the new markup (same behaviour):**
  - the More Admin row's weight;
  - the app bar title's classes;
  - Home's Messages, Notifications and Tasks links (now rows and the My work card);
  - Home's order (My work before Needs attention);
  - the shared notice's class.

## 10. Commands/Checks Executed

- `npm run build`.
- `composer install --no-dev --prefer-source`. A full install can't finish here, because PHPStan is dist-only and GitHub downloads are refused.
- In a scratch copy whose lock omits only PHPStan and Larastan: `php artisan test` and `vendor/bin/pint --test`.
- The real app served from that copy on SQLite with sample data, signed in, and captured with Playwright at 320, 390 (light and dark) and 1280 px on 11 pages, with a horizontal-overflow check.
- WCAG contrast computed for every new colour pair.

## 11. Results

- **Tests:**
  - **1,505/1,505 pass** (6,042 assertions);
  - Pint passes;
  - the first run found two real view errors, both fixed: a `@php(...)` before a `@php` block in Notifications (the 37B gotcha), and a missing `Format` import in Clients.
- **Render:**
  - no horizontal overflow at any width;
  - Home, the directory and Tasks match the reference's structure;
  - previews in `docs/design/`.
- **Contrast:**
  - text ≥ 4.5:1 everywhere (for example ink on surface 16.5:1, muted on surface 7.0:1, white on accent 5.2:1, accent-ink on surface 6.2:1, each kind colour on its tint 4.5–8.4:1, light and dark);
  - control boundaries ≥ 3:1 (`field` 3.5:1 on the page, 5.5:1 dark).
- **PHPStan: not run here.** CI runs it.

## 12. Deviations from the Definition

See the definition's §10:
- More keeps one list in the app's order;
- no scroll shadow on the app bar;
- statuses only with `staff-status.view`;
- New task on Home only with `tasks.create`;
- the Backoffice takes the new colours.

## 13. Known Issues/Limitations

- **Directory status filters** (Working / In a meeting…) need an API filter: a follow-up (DEC-071 §8).
- **No photos:** avatars are initials.
- **My team** shows the first six teammates by name, with "View all" opening the whole directory (it doesn't filter by team).
- **37B and 37C UAT** are still `NOT RUN`. Their links and behaviour are unchanged; on a 37D deploy, Home looks as in 37D.
- **No real-device check yet.** The screenshots are of the real pages in Chromium, not on phones.

## 14. Manual/UAT Testing Instructions

UAT-37D-01…04 in `docs/testing/UAT_LOG.md` (`NOT RUN`), after deployment.

## 15. Documentation Updated

See §5.

## 16. Recommended Next Step

1. Review the PR (and the previews in `docs/design/`).
2. Merge on green CI with your approval.
3. Deploy, then run UAT-37D (with UAT-37B and UAT-37C if they're still pending).
