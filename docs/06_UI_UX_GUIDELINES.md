# 06 — UI/UX Guidelines (Initial Principles)

Status: **Principles, not screen designs — with one deliberate, minimal exception.** Phase 4 built a functional (not visually refined) login screen for both the Admin Backoffice and the Flutter app, and a neutral authenticated placeholder, to make Authentication actually usable. These guidelines otherwise steer future design/implementation phases and are not a substitute for actual UX design work when a phase reaches real UI (a dedicated Mobile UI/UX Audit phase exists in the roadmap).

## Staff Mobile App (Flutter) — Navigation

Primary bottom navigation, kept shallow and task-oriented:

```
Home | Tasks | Schedule | Messages | More
```

`More` may expose secondary areas without cluttering primary navigation:

```
Profile · Staff Directory · Clients · Projects · Work Logs · Leave ·
Service Reports · Incident Reports · Announcements · Location/Check-in · Settings
```

Principles:
- **Mobile-first staff workflows.** Design for a staff member using this on a phone, often in the field, possibly one-handed, possibly with poor connectivity — not a scaled-down desktop layout.
- **Minimal navigation depth.** Prefer surfacing the next action (e.g. "check in," "log work," "approve leave" if permitted) over deep menu trees. Two taps to any primary action is a reasonable target.
- **Clear status indicators.** Status (task state, leave request state, incident state, staff current status) should be visually unambiguous at a glance — consistent color/iconography per status value, reused across modules rather than invented per screen.

## Admin Backoffice (Web) — Navigation

Primary sections:

```
Dashboard
People | Clients | Work | HR | Operations | Communication | Reports | Administration
```

Principles:
- **Responsive**, but optimized for desktop/tablet use by administrative and management roles — not required to be as thumb-friendly as the mobile app.
- **Permission-aware UI.** Navigation items, actions, and buttons for capabilities the current user lacks should not appear (not just be disabled) unless a disabled state with explanation is genuinely useful (e.g. "requires HR Administrator role" tooltip) — decided per case, but hiding is the default.
- **Consistent forms.** Shared form conventions (validation display, required-field marking, save/cancel placement) across modules — a leave-approval form and a client-edit form should feel like the same product.

## Shared Principles (Both Surfaces)

- **Loading / empty / error states are mandatory, not optional polish.** Every list/detail view needs a defined loading state, a defined "nothing here yet" empty state, and a defined error state before it's considered done — not just a happy-path implementation. Phase 4's login screens follow this: initial, submitting/loading, invalid-credential, and network-failure states are all handled — see `docs/handoffs/V1_PHASE_04_HANDOFF.md`.
- **Confirmation for destructive actions.** Suspending staff, deleting a record, rejecting a leave request, resolving/closing an incident — anything hard to reverse gets an explicit confirmation step. Mirrors the engineering-side "check before destructive action" discipline in `CLAUDE.md`.
- **Accessibility.** Sufficient color contrast, readable type sizes, tappable/clickable target sizes, and semantic structure (labels on form fields, alt text where relevant) are baseline requirements for any shipped screen, not a later audit-only concern — though a dedicated Mobile UI/UX Audit phase exists in the roadmap to catch what was missed.
- **Status consistency.** A given status concept (e.g. "Pending," "Approved," "In Progress," "Resolved") should look and read the same way everywhere it appears across both the mobile app and the Admin Backoffice.

## Explicitly Not Covered Here

- Specific screen layouts/wireframes — produced when each module's phase is actually designed/built.
- Visual design system (color palette, typography, component library) — a decision for whichever phase first needs to render real UI (likely Core Architecture or the first module UI phase); should be recorded as a decision (`DECISIONS.md`) when chosen, not assumed here.
- Detailed accessibility compliance target (e.g. WCAG level) — should be explicitly decided rather than defaulted.
