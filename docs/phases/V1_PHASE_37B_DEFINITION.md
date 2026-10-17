# Phase 37B — Web UI/UX Improvement (DEC-068)

**Status:** approved and implemented (2026-10-17). See `docs/handoffs/V1_PHASE_37B_HANDOFF.md`.

## 1. How this phase came about

- **Audit (2026-10-17):** the product owner asked for a UI/UX audit of the existing web app (staff portal and Admin Backoffice), made with the UI/UX Pro Max and Frontend Design skills. They also asked for a priority-ranked list of recommendations and a phased roadmap. The audit had no code changes and no dependency installs.
- **Approval:** "I approve all your recommendations". That covers the whole roadmap (UX-1…UX-4), the browser render pass, labelling Backoffice links into the staff portal, and the unread badge, which needed a decision.
- **Placement:** the work comes before Phase 38 (UAT), so UAT covers it. It is numbered **37B** so the renumbered roadmap (DEC-063) is unchanged.

## 2. Audit method and evidence

- **Read:** `CLAUDE.md`, `CURRENT_STATE.md`, `06_UI_UX_GUIDELINES.md`, the Phase 34F/37 handoffs and every Blade view (103 files).
- **Counted:** radius, button and field class strings across all views.
- **Computed:** WCAG contrast ratios from the token values in `resources/css/app.css`.
- **No screen was rendered during the audit.** Findings were marked as confirmed from source, computed, or needing visual verification.

## 3. Findings (summary)

| ID | Finding | Evidence |
|---|---|---|
| A1 | Form-field boundaries were 1.71:1 (light) and 1.78:1 (dark) against their surface, below WCAG 1.4.11's 3:1 (`--color-line` used as the input border on about 97 controls). axe does not test this. | Computed |
| U1 | Twelve Backoffice forms showed no visible change while saving. Livewire disables the submit button, but the buttons had no disabled style or "Saving…". | Source |
| U2 | Clients, Projects, Tasks and Announcements in the Backoffice open the staff portal (a different layout) with no warning. | Source |
| U3 | The Admin dashboard linked only People and Clients. Pending leave, open incidents and overdue tasks were plain text. | Source |
| U4 | People listed "Staff status" and "Location check-ins", both just links to Staff records. | Source |
| U5 | Chat opened at the top of up to 100 messages, with the message box below them. | Source |
| U6 | Live searches and filters gave no sign that results were loading, and most lists had no Clear filters. | Source |
| U7 | No unread badge on the Messages tab. | Source |
| U8/A2 | Focus didn't move after a page change without a reload, or to the first invalid field after a failed save. | Source |
| U9 | No show/hide toggle for passwords. | Source |
| U10 | The report table can't be sorted; numbers aren't aligned. | Source |
| V1–V6 | Button shapes: 42 pills and 12 `rounded-md`. Four radii with no rule. 68 hand-written notice cards, plus `x-portal.empty` (11). "No staff profile is linked…" was repeated 24 times. Two label styles; two field backgrounds. `ucfirst()` status labels and tones defined case by case. Square segmented controls. | Source (grep counts) |
| V7 | No warning tone on the web (the mobile app has `AppStatusColors.warning`). | Source |
| A4–A6 | Hover colour 1.17–1.19:1 (weak feedback); no reduced-motion handling; Livewire's progress bar fixed to the light accent. | Computed / Source |

## 4. Scope (approved)

- **UX-1, Foundation:**
  - `field` and `warning` tokens; a stronger `hover`;
  - one shape rule: controls `rounded-md`, containers `rounded-xl`, actions `rounded-full`;
  - shared `field` and `btn` styles;
  - `x-portal.notice` and `x-portal.no-profile`;
  - one label style; pill segmented controls and choice buttons.
- **UX-2, Feedback and focus:**
  - visible saving and loading on every button and live filter;
  - focus on the page heading after a page change, and on the first invalid field after a failed save;
  - Chat opens at the latest message, with a sticky message box.
- **UX-3, Backoffice efficiency:**
  - the dashboard leads with what needs a decision, and its figures link to their lists;
  - section pages lose the duplicate items and label staff-portal screens;
  - status labels go through `Format`;
  - readable Audit log actions;
  - Clear filters;
  - aligned numbers in tables.
- **UX-4, Polish:**
  - password show/hide;
  - a dark-mode-aware progress bar;
  - reduced motion;
  - list-row padding and chevrons.

**Out:**
- backend or API changes;
- new dependencies;
- the Flutter app (paused, DEC-067 H-1);
- a new visual direction (the indigo branding and system font stay).

## 5. Decisions taken during implementation

- **Shared styles are Tailwind `@utility` classes in `app.css`** (`field`, `btn` with `btn-primary` and its siblings), not a Blade `x-portal.button` component as the audit first suggested.
  - Buttons carry Livewire and Alpine attributes and serve as the trigger of `x-portal.confirm`.
  - A class keeps that markup as it is, while a component would have to wrap every button.
- **The unread badge (U7) is deferred.** The portal reads only through `/api/v1`, and no endpoint returns the unread message count cheaply; Home's aggregate is too heavy to call on every page. Adding an endpoint is backend work, outside this phase. It needs the product owner's decision.
- **Sortable report columns (U10) are deferred.** The API has no `sort` parameter (04 "Filtering & Sorting": not yet implemented), and sorting only one page would mislead. The tables get aligned numbers instead.

## 6. As implemented

See the handoff.
