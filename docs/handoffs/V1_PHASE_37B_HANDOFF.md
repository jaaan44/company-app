# Phase 37B — Web UI/UX Improvement — Handoff

**Status: implemented; CI green on PR #139 (`0b8895f`, 1,493 tests); not yet checked in a browser.** Branch `claude/stoic-wright-paan67`. **No migration and no API change.**

## 1. Phase Identification

- **Phase:** 37B (DEC-068), Web UI/UX Improvement.
- **Date:** 2026-10-17.
- **Authorization:** a UI/UX audit, then "I approve all your recommendations" (UX-1…UX-4).
- **Specification:** `docs/phases/V1_PHASE_37B_DEFINITION.md`.
- **Branch:** `claude/stoic-wright-paan67`, from `main` at `fe93d4c`.

## 2. Objective

Fix the audit's findings in the existing web app (the staff portal and the Admin Backoffice) without changing its workflows, backend, API or dependencies:
- contrast;
- consistency;
- feedback;
- focus;
- Backoffice efficiency.

## 3. Scope Implemented

| Item | Done |
|---|---|
| A1 field contrast | New `--color-field` token (light `#767680`, 4.27:1 on surface; dark `#90909a`, 5.87:1). Every input, select and textarea uses it via the `field` style. |
| V1–V6 consistency | One button style (`btn` plus a variant) on 202 class lists in 54 views, and on the dynamic confirm buttons. One label style. Pill segmented controls and choice buttons. `x-portal.notice` and `x-portal.no-profile` replace the 68 hand-written notice cards. The error card uses `rounded-xl`. |
| V7 warning | `--color-warning`/`-soft` (light `#7a5900` on `#ffdea6`, 4.99:1; dark `#f5bf48` on `#5c4300`, 5.50:1). A `warning` chip tone, used for suspended accounts and "Must choose a password". |
| V5 status labels | `Format::staffStatus`, `accountStatus` and `announcementStatus`. `Format::tone` covers `suspended`, `separated`, `published` and `archived`, so no view uses `ucfirst()` for a status. |
| U1 saving feedback | `.btn:disabled` and `.btn[data-loading]` dim the button and show a small spinner. Livewire sets both on a submitting or clicked button, so every form gets it without per-view code. |
| U6 loading and clearing | A live search or filter (`wire:model.live`, which Livewire marks `data-loading`) shows a spinner at the field's end; under reduced motion, three dots instead. **Clear filters** on Staff records, Leave requests, Service reports and Incidents, every report, and Schedule. |
| U8/A2 focus | `app.js`: after a page change without a reload (not Back or Forward), focus goes to the page's `h1`. After a failed save, focus goes to the first `aria-invalid` field. |
| U5 Chat | Opens at the latest message, and follows new ones only while you're near the end. The message box is sticky above the tab bar and 2 rows high. |
| U3 dashboard | **Needs attention** first: Leave waiting, open incidents and overdue tasks, as of now. Each links to its list (the tasks report with Overdue on), for those who may open that section. Then **Overview** with the period form. Work logged, Service reports and Coming up link too. Card headings are `h3`. The Leave card shows approvals in the period (pending moved to Needs attention). |
| U4 hubs | People drops "Staff status" and "Location check-ins"; Staff records' description now covers them. |
| U2 portal links | A section item that opens a staff-portal screen says "Opens in the staff portal" and loads in full (`data-no-navigate`), like "Staff portal". |
| Audit log | Actions read as words ("Role: permissions updated"), with the raw key after them. The filter lists the readable names. |
| U9 | Show/Hide on the sign-in password and all three Change password fields. Every field is hidden again on submit, to match what the server renders. |
| Tables | `tabular-nums` on the report table and the dashboard's figures. |
| A4–A6 polish | Stronger `hover` (light `#e3e1ec`, dark `#34343b`; all text on it ≥5:1). Livewire's progress bar follows `--color-accent`. A reduced-motion rule. Home and Schedule rows are padded and rounded, and Today rows get the chevron. Backoffice list rows that open a record get the chevron. |

**Not done (see §13):**
- the Messages unread badge (U7);
- sortable report columns (U10);
- a denser Backoffice table layout;
- the render pass.

## 4. Implementation Summary

- **`resources/css/app.css`:**
  - tokens;
  - `@utility field`, `btn`, `btn-compact`, `btn-primary`, `btn-danger`, `btn-secondary`, `btn-danger-outline`, `btn-text`, `btn-danger-text`, `btn-plain`;
  - the spinner keyframes, the progress-bar colour, reduced motion, and no focus ring on a programmatically focused `h1`.
- **Class migration:** a one-off script replaced each hand-written button or control class list with the shared style. It kept layout classes (margins, widths, `ml-auto`, `text-sm`) and skipped any list containing Blade expressions. The 10 dynamic lists it skipped were edited by hand.
- **Blade:**
  - new `components/portal/notice` and `no-profile`;
  - `chip` gains `warning`;
  - `admin/section` labels portal screens;
  - the dashboard is reordered;
  - Chat gets an Alpine `x-data` (scroll and follow);
  - sign-in and Change password get show/hide.
- **PHP (UI layer only):**
  - `Format`: three label helpers, `auditAction` and the tone additions;
  - `Admin\Dashboard` passes the visible section keys;
  - `clearFilters()` on five Backoffice list components;
  - `Sections` loses two items;
  - the staff report's Status column uses `Format`.

## 5. Files Changed

- **New:**
  - `resources/views/components/portal/notice.blade.php` and `no-profile.blade.php`;
  - `tests/Feature/UiImprovementTest.php`;
  - this handoff and the phase definition.
- **Changed:**
  - `resources/css/app.css` and `resources/js/app.js`;
  - 76 Blade views;
  - `app/Support/Portal/Format.php`, `app/Support/Admin/Sections.php` and `app/Support/Admin/Reports.php`;
  - `app/Livewire/Admin/{Dashboard,StaffList,LeaveRequests,OperationsReports,ReportView,ScheduleEntries}.php`;
  - `tests/Unit/Support/Portal/FormatTest.php`.
- **Docs:**
  - DECISIONS (DEC-068) and the phase definition;
  - 02 (§50) and 06;
  - ROADMAP, CURRENT_STATE, CHANGELOG, TEST_STATUS and UAT_LOG.

## 6. Database/Schema Changes

None.

## 7. API Changes

None in the first delivery. See the addendum for `GET /conversations/unread-count` and `?sort=` on the reports.

## 8. Authorization/Security Changes

None:
- Dashboard links appear only for sections the person may see (`Sections::visibleTo`), the same rule as the navigation. Each target page keeps its own checks.
- Show password changes only the input's type in the browser.

## 9. Tests Added or Changed

- **`UiImprovementTest` (8):**
  - the shared button and field styles on a real page;
  - the FAB's two forms;
  - the no-profile notice;
  - the dashboard's order and links;
  - no links for someone without the section;
  - "Opens in the staff portal" with `data-no-navigate`;
  - People without the duplicate items;
  - Clear filters on the five lists;
  - the password toggle markup.
- **`FormatTest` (+2):** the new labels and tones; readable audit actions.
- **Existing tests kept passing by design:** the Home tile class prefix, severity pills, the app bar title, the Audit log's raw keys, Work's portal links and the dashboard's figure order. This is unverified: see §11.

## 10. Commands/Checks Executed

- `npm ci && npm run build` (Vite 8, Tailwind 4): builds. The compiled CSS was checked for every new class, the variant order (`max-md:hidden` after `.btn`; `px-2` after `.field`) and the tokens.
- `node --check resources/js/app.js`.
- `php -l` on every changed PHP file.
- A script checked that `@if`/`@endif`, `@foreach`, `@error` and notice tags balance in all 76 changed views.

## 11. Results

- **Not run:** `composer install`, so also Pint, PHPStan, `php artisan test`, the render pass and axe.
  - Composer's GitHub zip downloads are refused by this environment's network policy (403). Git clones work, but filled the disk.
  - A workaround (fetching each package at its locked commit) was refused by the session's permission policy, so it wasn't pursued.
  - **CI on PR #139 is the run of record:**
    - first run (`807601a`): 2 of the new tests failed. Schedule's From defaults to today, so Clear filters now returns to today. The Operations section shows to everyone in the Backoffice, so the dashboard test now expects its link for that role.
    - second run (`0b8895f`): **Pint, PHPStan and 1,493/1,493 tests pass**, and the staging images build.
- **CSS build and the static checks above:** pass.

## 12. Deviations from Specification

- **Shared styles are utility classes, not an `x-portal.button` component** (§5 of the definition).
- **Error summary:** focus on the first invalid field, not a separate summary box. Each field's message is already tied to it, and long forms keep their inline errors.
- **One-item hubs (Clients, Communication) stay.** Their only item opens the staff portal, and the hub is where that is now explained.

## 13. Known Issues/Limitations

- **The unread badge (U7)** needs a cheap unread-count source:
  - a small read-only endpoint, such as `GET /me/messages/unread-count` (like the notifications one);
  - or calling Home's aggregate on every page, which is too heavy.

  Either is backend work. It needs the product owner's decision.
- **Sortable report columns (U10)** need an API `sort` parameter (04: not yet implemented).
- **A denser Backoffice list or table layout at desktop widths** and **the filter row's `ml-auto` button wrapping at 768–1024 px** need the render pass before deciding.
- **The spinner and "Saving…" text** both show on buttons that already had the text. That is harmless, but redundant.
- **Chat's follow-the-end behaviour** is untested in a browser.

## 14. Manual/UAT Testing Instructions

UAT-37B-01…06 are in `docs/testing/UAT_LOG.md` (NOT RUN): phones (light and dark) and a computer, after deployment.

## 15. Documentation Updated

See §5.

## Addendum — the deferred items (2026-10-17)

- **Approval:** "i approve you recommendations", after CI went green. The two deferred items are now built on the same branch.
- **Unread badge:**
  - `GET /api/v1/conversations/unread-count` (a new read-only endpoint; needs a linked staff record, `403` otherwise);
  - its count logic is shared with Home (`UnreadMessages`);
  - the portal layout reads it through `InternalApi` (`Badges`) and shows it on the Messages tab, in the sidebar and the tab bar.
- **Sortable reports:**
  - `?sort=` on the seven report endpoints and their exports (`ReportSort`), for names, dates and numbers;
  - the Backoffice report headers sort and set `aria-sort`;
  - the sort is kept in the URL and in the CSV link.
- **Tests:**
  - `ReportSortTest` (4);
  - `UnreadCountTest` (2);
  - `UiImprovementTest` +2: the badge, and sortable headers.
- **Schema and authorization:** no migration and no new permission. The badge costs one in-process API call and one COUNT query on each full portal page load.
- **CI:** green on `f46a52e` (1,501 tests, Pint, PHPStan, staging images), after two fix-ups described in TEST_STATUS.
- **Not yet run:** the render pass, UAT-37B-07 and 08.

## 16. Recommended Next Step

1. Authorize a pull request so CI runs Pint, PHPStan and the suite.
2. Fix anything CI finds.
3. Do a render pass (screenshots at 360, 768 and 1280 px, light and dark, plus axe) where Composer can install.
4. Merge, deploy and run UAT-37B.
5. Decide on the unread badge and report sorting (backend).
6. Then Phase 38 or 39, each needing its own authorization.
