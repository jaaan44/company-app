# 06 — UI/UX Guidelines

Status: **Foundation resolved and now implemented at the shell level, not screen designs.** Phase 4 built a functional (not visually refined) login screen for both the Admin Backoffice and the Flutter app, and a neutral authenticated placeholder, to make Authentication actually usable. Phase 23 (Mobile UI/UX Audit & Foundation) audited that existing UI and resolved the visual-design system, accessibility target, and mobile navigation architecture this document previously left open — see `docs/phases/V1_PHASE_23_MOBILE_UIUX_AUDIT.md` for the full audit and `docs/DECISIONS.md` DEC-046 for the decision record. Phase 25 (Mobile Application Foundation & Navigation Shell) then implemented the navigation architecture (`go_router`, the bottom-navigation shell) and closed the dark-theme (G-01) and typography-consistency (G-02) findings Phase 23 had documented but not fixed — see `docs/phases/V1_PHASE_25_DEFINITION.md` and `docs/handoffs/V1_PHASE_25_HANDOFF.md`. These guidelines otherwise steer future design/implementation phases and are not a substitute for actual UX design work when a phase builds a specific business-module screen (wireframes/detailed layouts remain that phase's own responsibility, not this document's).

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
- **Clear status indicators.** Status (task state, leave request state, incident state, staff current status) should be visually unambiguous at a glance — consistent color/iconography per status value, reused across modules rather than invented per screen. See "Status Presentation" below for the concrete convention.

### Navigation Architecture (implemented, Phase 25 — DEC-046)

**`go_router` is installed and is the app's sole navigation mechanism**, replacing the former `AuthGate` widget-switch/`MaterialApp.home`. `lib/app/router.dart` builds a `GoRouter` with `refreshListenable: authController` and a declarative `redirect` callback (`AuthStatus.unknown` → `/splash`, unauthenticated → `/login`, authenticated → the shell). A `StatefulShellRoute.indexedStack` (`lib/features/shell/presentation/app_shell.dart`) implements the bottom-navigation shell exactly as specified below — each of the five destinations preserves its own navigation stack across tab switches.

Both needs that motivated the choice are realized: **auth-gated redirects at the route level** (the `redirect` callback above, evaluated on every `AuthController` change) and the **structural readiness for deep linking** (`go_router`'s path-based routes make it possible; actual deep-link *consumption* from a Notification is still unbuilt, deferred to whichever later phase first needs it).

Four of the five destinations (`Tasks`/`Schedule`/`Messages`/`More`) are still honest placeholders — Phase 25 built the shell and routing only, per its own scope boundary; Phases 27–33 give them real content. See `docs/phases/V1_PHASE_25_DEFINITION.md` and `docs/handoffs/V1_PHASE_25_HANDOFF.md` for the full account.

### Employee Home (implemented, Phase 27 — pending PR/CI, staging and UAT)

The Home tab is the first real mobile screen, and the first real use of the loading/empty/error conventions below. In order: greeting ("Hello, {preferred ?? first name}", position · department, team — no time-of-day wording), **Today** (the employee's own schedule entries and tasks due today, in server order, times in the *company* timezone via the server's `company_day.utc_offset`, "+N more today"), **Needs attention** (task, message and notification counts as tiles, each one semantics label; overdue shown in the `error` role *with* text), and **Latest announcements** (≤ 3, title + date). Users with no employee profile see an explanation and only the notifications count. States: centered `CircularProgressIndicator` on first load; inline error in the `error` role (live region) with a "Try again" `FilledButton`; pull-to-refresh that keeps content and shows a `SnackBar` if it fails. **Home content is deliberately non-interactive** (no `InkWell`/`ListTile`/`GestureDetector`/chevrons) until the Tasks/Schedule/Messages screens exist (spec R-1). Verified by widget tests in light and dark mode and at 200% text scale; not yet verified on a device.

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

The visual-design foundation below (color, typography, spacing, components) is written for the Flutter mobile app specifically, since that's what Phase 23 audited. The Admin Backoffice (Blade + Livewire, server-rendered, no client-side design-system package) remains out of this document's resolved-foundation scope — its own visual conventions are a decision for whichever phase first builds real Admin Backoffice screens beyond the existing login form.

## Mobile Visual-Design Foundation (resolved, DEC-046)

Material 3, extending — not replacing — the `ColorScheme.fromSeed(seedColor: Colors.indigo)` already in use since Phase 4. This is a foundation for future screens to build on, not a component library — no shared widget code exists yet.

### Color — semantic roles, never raw colors in feature code

| Role | Source |
|---|---|
| Primary/brand | `Colors.indigo` seed (unchanged since Phase 4) → Material 3's generated `colorScheme.primary`/`onPrimary` |
| Secondary/accent | Material 3's own generated `colorScheme.secondary`/`tertiary` — no second hand-picked brand color |
| Surface/background | Material 3's own generated `colorScheme.surface`/`surfaceContainer*` roles |
| Success | `AppStatusColors.success`/`onSuccess` (new `ThemeExtension`, see below) |
| Warning | `AppStatusColors.warning`/`onWarning` |
| Error | Material 3's own `colorScheme.error`/`onError` (already in use in `LoginPage`) |
| Informational | `AppStatusColors.info`/`onInfo` |
| Disabled | Material 3's built-in disabled state layers — no separate custom color |
| Text/emphasis levels | `colorScheme.onSurface` (primary text) / `onSurfaceVariant` (secondary/metadata text) |
| Divider/border | `colorScheme.outline` (stronger, e.g. field borders) / `outlineVariant` (subtler, e.g. list dividers) |

Material 3 doesn't ship success/warning/info roles, so these three are added via a single `ThemeExtension<AppStatusColors>` (mirroring Material's own `color`/`onColor` pairing convention), registered once on `ThemeData.extensions` and looked up via `Theme.of(context).extension<AppStatusColors>()!` — this is the mechanism, so feature code never reaches for a raw `Color(0xFF...)` literal for a status meaning.

**Light/dark:** both supported, following the device's system setting by default (`ThemeMode.system`), with the dark scheme generated from the same seed color (`ColorScheme.fromSeed(seedColor: Colors.indigo, brightness: Brightness.dark)`) rather than a separately hand-tuned dark palette. **Implemented (Phase 25) — G-01 closed:** `CompanyApp`'s `MaterialApp.router` now supplies both `theme`/`darkTheme` plus `themeMode: ThemeMode.system`, exactly as specified here.

### Typography — Flutter's stock Material 3 `TextTheme`, no custom font

| Guideline role | `TextTheme` |
|---|---|
| Display/page heading | `headlineSmall` |
| Section heading | `titleMedium` |
| Title (list/card item) | `titleSmall` |
| Body text | `bodyMedium` |
| Label (field label, chip text) | `labelLarge` |
| Metadata/supporting text | `bodySmall` + `onSurfaceVariant` color |
| Button/action | `labelLarge` (Material's own default) |

No custom font family — the platform default is retained; a custom font is a future decision only if a real product/brand reason emerges.

### Spacing — one 4px-based scale

| Token | Value | Typical use |
|---|---|---|
| `xs` | 4px | Icon-to-label gaps |
| `sm` | 8px | Label-to-field, chip padding |
| `md` | 16px | Screen padding, form-field spacing, card padding |
| `lg` | 24px | Section spacing, spacing before a primary action |
| `xl` | 32px | Spacing between major page regions |
| `xxl` | 48px | Large empty-state/hero spacing |

`md`/`lg` are already the de facto values `LoginPage` uses informally — this scale formalizes, rather than changes, that existing pattern. List density defaults to Material's standard comfortable sizing; a denser variant is a decision for whichever module first demonstrates a real need (most likely a long Staff Directory), not decided speculatively here.

### Components — baseline conventions, not a library

| Element | Convention |
|---|---|
| Primary button | `FilledButton` |
| Secondary button | `OutlinedButton` |
| Tertiary/inline action | `TextButton` |
| Destructive action | Same button types, styled with the `error` role — never a hardcoded red |
| Text field | `TextFormField` with `OutlineInputBorder` |
| Card/container | Material `Card`, default M3 shape/elevation, `md` (16px) padding |
| Dialog | `AlertDialog` — used for destructive-action confirmation |
| Sheet | `showModalBottomSheet` for lightweight contextual actions |
| Chip/status indicator | One status-color role **plus a text label, always** — never a color-only dot |
| App bar | Standard `AppBar`, one consistent title style, at most 1–2 action icons |
| List row | `ListTile`-based rows |
| Empty state | Icon + short message + optional primary action, centered |
| Loading state | `CircularProgressIndicator` — no shimmer/skeleton library |
| Error state | Inline text/banner in the `error` role, with a retry action when retryable |
| Success/confirmation | `SnackBar` for transient confirmations — no custom toast library |

## Accessibility Baseline (resolved, DEC-046)

**Target: WCAG 2.2 AA principles, applied where they translate to Flutter/mobile, plus native Flutter/platform accessibility practice.** This is an engineering/design baseline for future work, not a claim of formal certification or a compliance audit process.

- **Color contrast:** WCAG AA ratios (4.5:1 normal text, 3:1 large text/UI). Material 3's seed-generated role pairs meet this by construction; any new semantic color (the status-color extension above) must be spot-checked against both light and dark surfaces when defined.
- **Minimum touch targets:** 48×48dp (Material's own standard).
- **Text scaling:** support the OS text-scale factor up to at least 200% without clipped/overlapping text.
- **Semantic labels:** every icon-only control gets a `tooltip`/`semanticLabel` (the existing logout button's `tooltip` is the pattern to continue).
- **Screen-reader-friendly controls:** real Flutter/Material widgets, not a bespoke `GestureDetector`-on-`Container` that loses built-in semantics.
- **Meaningful focus/traversal order:** matches the visual top-to-bottom, natural-reading-order layout.
- **No color-only status communication:** every status indicator pairs color with a text label or icon.
- **Visible selected/disabled/error states:** Material's built-in state layers, never suppressed for a custom look.
- **Accessibility-aware loading/feedback:** stock `CircularProgressIndicator`/`SnackBar`, which already participate correctly in Flutter's semantics tree.
- **Avoid unnecessary motion:** no gratuitous custom animation; respect the OS-level reduce-motion signal where Flutter surfaces it.
- **Keyboard/focus support where relevant:** the existing `TextInputAction.next`/`.done` keyboard-chaining pattern in `LoginPage` should be preserved and extended to future forms.
- **Announce dynamic content:** an inline error/status message that appears after an action (as `LoginPage`'s already does) should be wrapped so assistive technology announces it, not merely rendered silently on-screen.

## Shared Interaction/State Conventions (Both Surfaces)

- **Loading / empty / error states are mandatory, not optional polish.** Every list/detail view needs a defined loading state, a defined "nothing here yet" empty state, and a defined error state before it's considered done — not just a happy-path implementation. Phase 4's login screens follow this: initial, submitting/loading, invalid-credential, and network-failure states are all handled — see `docs/handoffs/V1_PHASE_04_HANDOFF.md`.
- **Loading:** `CircularProgressIndicator`, centered for full-screen loads, inline (e.g. inside a button) for action-scoped loads.
- **Error:** inline text/banner in the semantic `error` role, with a retry affordance when retryable (network failures) and a plain message when not (validation errors).
- **Success/confirmation feedback:** `SnackBar` for transient, non-blocking confirmations.
- **Form behavior:** real `Form`/validated fields, inline per-field error text, submit disabled while in flight, keyboard chaining between fields (`LoginPage`'s existing pattern, generalized).
- **Confirmation for destructive actions.** Suspending staff, deleting a record, rejecting a leave request, resolving/closing an incident — anything hard to reverse gets an explicit `AlertDialog` confirmation step *before* the action, not a way to undo it after. Mirrors the engineering-side "check before destructive action" discipline in `CLAUDE.md`.
- **Status presentation.** A given status concept (e.g. "Pending," "Approved," "In Progress," "Resolved") should look and read the same way everywhere it appears across both the mobile app and the Admin Backoffice — one shared color-role+label(+icon) chip convention, reused for every status concept across every module, rather than each module inventing its own visual language.
- **Responsive/mobile behavior:** constrain form/content width on wider viewports (`LoginPage`'s existing `ConstrainedBox(maxWidth: 360)` pattern) rather than stretching every element edge-to-edge; scroll rather than clip when the keyboard reduces available height.
- **Accessibility.** Sufficient color contrast, readable type sizes, tappable/clickable target sizes, and semantic structure (labels on form fields, alt text where relevant) are baseline requirements for any shipped screen, not a later audit-only concern — see the resolved Accessibility Baseline above.

## Explicitly Not Covered Here

- Specific screen layouts/wireframes for any business module (Tasks, Schedule, Messages, Clients, Projects, Staff, Work Logs, Leave, Service Reports, Incident Reports, Announcements, Location/Check-in, Settings, or any other) — produced when each module's own UI phase is actually designed/built.
- A shared/reusable Flutter widget component library (e.g. an actual `StatusChip` class) — the Components table above is a set of conventions to follow, not code that exists yet.
- The Admin Backoffice's own visual-design system — this document's resolved foundation (color/typography/spacing/components) covers the Flutter mobile app only; a future phase should make the equivalent decision for Blade/Livewire screens when one first needs it.
- Detailed accessibility compliance certification (e.g. a formal WCAG audit/certification process) — the Accessibility Baseline above is a practical engineering/design target, deliberately not framed as certification.
