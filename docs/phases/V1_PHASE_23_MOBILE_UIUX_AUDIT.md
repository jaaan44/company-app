# Phase 23 — Mobile UI/UX Audit & Foundation

**Status:** COMPLETE (audit, design-system/accessibility/navigation decisions, and documentation only — no Flutter code was changed)
**Depends on:** Phase 4 (Authentication — the only existing Flutter UI), `docs/06_UI_UX_GUIDELINES.md` (Phase 0)
**Roadmap name:** `docs/ROADMAP.md` names this phase "Mobile UI/UX Audit — review against `06_UI_UX_GUIDELINES.md`." The working title above is this phase's own expanded description, authorized explicitly for this session: the roadmap line is left unchanged (per CLAUDE.md's "don't rewrite historical decisions unnecessarily"), and this phase resolves the fact that "audit" alone under-describes the work, since the current Flutter application contains almost no built UI to audit.
**This is not a `_DEFINITION.md` document.** Following the precedent `docs/phases/V1_PHASE_22_SECURITY_AUDIT.md` set: this phase was authorized directly (repository scope-discovery, then explicit product-owner "proceed" instructions delivered as chat messages), not via a separate pre-authorized `V1_PHASE_23_DEFINITION.md`. This single document serves as both the audit report and the record of what was authorized and delivered.

**Date:** 2026-09-14.
**Session type:** Repository inspection, UI audit, design-system/accessibility/navigation architecture decisions, and documentation. **No Flutter (or any) application code was changed.** No business-module mobile screens, no bottom-navigation shell, and no routing package were implemented — all three are explicit exclusions the product owner set.
**Repository state:** `main` at `d97636d` (merge commit for PR #24, `claude/eloquent-dijkstra-r1a09v` → `main` — Phase 22 Security Audit remediation). Working tree confirmed clean before and after this session.

---

## 1. Executive Summary

Company App's Flutter mobile client was audited against `docs/06_UI_UX_GUIDELINES.md`. The client currently implements exactly one real user flow — Authentication (Phase 4: login, an auth-gated redirect, and a neutral placeholder home screen) — plus the app shell and theme that wrap it. Every one of the fifteen business modules built since Phase 6 (Staff, Clients, Projects, Tasks, Work Logs, Leave, Announcements, Notifications, Messaging, Scheduler, Service Reports, Incident Reports, Dashboard/Reports) has **zero** Flutter UI, by explicit, repeated design decision recorded in every one of those phases' own handoffs ("no Flutter mobile UI" appears as an explicit exclusion in Phases 6 through 21 without exception).

This audit therefore had a narrow, concrete surface to actually inspect (two screens, one app-level theme, one auth-state controller), and a much larger, more consequential gap to address: `06_UI_UX_GUIDELINES.md` itself left the visual design system, the accessibility target, and the navigation architecture all **explicitly undecided** ("a decision for whichever phase first needs to render real UI," "should be explicitly decided rather than defaulted"). Every future mobile-module UI phase depends on those three decisions existing first — building Tasks, Schedule, or Messages screens without them would mean either blocking on the same open questions again or having each module phase invent its own inconsistent answer.

**What this phase found on audit:** the existing two screens are a genuinely solid, if minimal, foundation — they already follow several principles `06_UI_UX_GUIDELINES.md` asks for (loading/error/success states, semantic error coloring, real `Form` validation, keyboard ergonomics) before this phase ever wrote those principles down formally. Two small, real inconsistencies were found (no dark theme is wired up despite `MaterialApp` defaulting to light-only regardless of device setting; `HomePage`'s placeholder text doesn't use the app's own established text-style convention that `LoginPage` already follows) — both are documented below as recommended fixes, **neither was fixed in this session**, per explicit instruction to document first and decide separately whether remediation belongs in Phase 23 or a later phase.

**What this phase resolved:** a Material 3-based visual design foundation (semantic color roles via a `ThemeExtension`, a mapped typography scale, a spacing scale, and baseline component conventions), an accessibility baseline aligned to WCAG 2.2 AA principles plus native Flutter/platform practices (not formal certification), and a navigation architecture decision (`go_router`, not implemented yet) — recorded as DEC-046, and folded into a substantially expanded `docs/06_UI_UX_GUIDELINES.md` so the first real mobile-module UI phase has concrete, non-speculative direction instead of open questions.

---

## 2. Current Flutter UI Inventory

Full inventory of `apps/mobile/lib/` (every file, no omissions):

| File | Role | Notes |
|---|---|---|
| `app/app.dart` | Root `CompanyApp` widget — owns the single `AuthController`, builds `MaterialApp` | One `ThemeData(colorScheme: ColorScheme.fromSeed(seedColor: Colors.indigo))`. No `darkTheme`, no `themeMode`. No routing package — `home:` is a single fixed widget (`AuthGate`). |
| `core/config/app_config.dart` | Build-time config (`API_BASE_URL` via `--dart-define`) | Not a UI file; included for completeness. |
| `features/auth/domain/auth_user.dart` | `AuthUser` model | No UI. |
| `features/auth/data/auth_api_client.dart` | HTTP client for `/auth/*` | No UI; produces the error message strings `LoginPage` displays. |
| `features/auth/data/token_storage.dart` | `TokenStorage`/`SecureTokenStorage` | No UI. |
| `features/auth/state/auth_controller.dart` | `AuthController extends ChangeNotifier` | Drives `AuthGate`'s screen selection via `AuthStatus` (`unknown`/`authenticating`/`authenticated`/`unauthenticated`). |
| `features/auth/presentation/auth_gate.dart` | `AuthGate` — the app's only "routing" decision point | A `switch` over `AuthStatus` inside a `ListenableBuilder`, not a routing package. Three branches: a bare `Scaffold` + `CircularProgressIndicator` (unknown), `LoginPage` (unauthenticated/authenticating), `HomePage` (authenticated). |
| `features/auth/presentation/login_page.dart` | `LoginPage` — the one fully-built screen | See §4 for detailed audit. |
| `features/home/home_page.dart` | `HomePage` — explicitly-labeled "neutral placeholder home screen" | A `Scaffold` with an `AppBar` (title + optional logout `IconButton`) and a single centered `Text` showing `'Signed in as $userName'` or a bootstrap message. Its own doc comment states it is "replaced by real navigation/screens once business modules are authorized." |
| `main.dart` | Entry point | `runApp(const CompanyApp())`. No UI of its own. |

**Total: 2 screens** (`LoginPage`, `HomePage`), **1 transitional loading screen** (the bare spinner in `AuthGate`), **1 app-level theme**, **0 business-module screens**, **0 navigation shells**, **0 shared/reusable UI widgets** (no button/card/chip/status-indicator components exist anywhere — nothing has yet needed one, since only one screen pair exists).

Dependencies (`pubspec.yaml`): `http`, `flutter_secure_storage`, `cupertino_icons` (unused beyond the default asset), `flutter_lints` (dev). No routing package, no state-management package, no design-system/component package, no custom fonts.

Tests (`apps/mobile/test/`): `auth_controller_test.dart`, `auth_gate_test.dart`, `login_page_test.dart`, `fake_token_storage.dart`, `widget_test.dart` — all auth-flow coverage; nothing UI-system-level to test yet (no shared theme/widget code existed before this phase, and none was added).

---

## 3. Audit Methodology

1. **Documentation-first scoping** — re-read `CLAUDE.md`, `docs/CURRENT_STATE.md`, `docs/ROADMAP.md`, `docs/06_UI_UX_GUIDELINES.md` in full, `docs/02_ARCHITECTURE.md` §12/§13 (Flutter organization, DEC-021/025/026), and the relevant passages of `docs/DECISIONS.md` (DEC-021 through DEC-026) and every Phase 6–21 handoff's own "no Flutter mobile UI" exclusion line, to confirm — rather than assume — how much mobile UI actually exists.
2. **Direct, full-file code review** — every file in `apps/mobile/lib/` was read in full (not sampled), listed in §2 above. No file was skipped.
3. **Guideline-by-guideline comparison** — each existing screen (`LoginPage`, `HomePage`, `AuthGate`'s loading branch) was checked against every principle in `06_UI_UX_GUIDELINES.md` and against the specific inspection checklist the product owner specified for this audit (theme setup, loading/error states, auth transition behavior, form validation, feedback, typography, spacing, touch targets, contrast, hierarchy, semantics/accessibility, responsiveness, consistency, platform conventions).
4. **Non-visual, static review only** — no device/simulator was launched (this session has no Flutter tooling attached to a running emulator); every finding below was verified by reading the actual widget code and its resolved Material 3 defaults, not by taking a screenshot. Where a finding depends on runtime rendering behavior neither confirmable nor deniable from source alone, it is described as such rather than asserted.
5. **Distinguishing three categories explicitly** (per the product owner's explicit instruction) throughout §5/§6: an actual defect in existing code; a foundation piece a future phase will need but that isn't a defect in what exists today; and something that simply doesn't exist yet and therefore cannot be meaningfully audited at all (every business-module screen).

---

## 4. Findings Against `06_UI_UX_GUIDELINES.md`

Walking the product owner's inspection checklist against the two real screens:

| Area | Finding |
|---|---|
| **App/root theme setup** | One `ColorScheme.fromSeed(seedColor: Colors.indigo)`, light only. Real gap: no `darkTheme`/`themeMode` — see §6. |
| **Login screen** | Fully functional: email/password `TextFormField`s, per-field validation, a submit `FilledButton` that shows a spinner while submitting, an inline error message region. Matches Material 3 conventions throughout (no custom-painted widgets). |
| **Auth loading/error behavior** | `AuthController.login()` sets `AuthStatus.authenticating` before the call and resolves to `authenticated`/`unauthenticated` with `errorMessage` populated on failure — a clean, explicit state machine, not ad hoc booleans. `AuthApiClient` distinguishes a network failure ("Could not reach the server…") from a server-returned validation/auth error, each with a distinct, user-appropriate message — genuinely two different error states, not one generic catch-all. |
| **Auth gate / transition behavior** | `AuthGate.initState()` calls `bootstrap()` once; the `unknown` status shows a spinner specifically to avoid "a flash of the wrong UI" (the code's own comment) while a stored token is validated — correct, deliberate handling of the classic cold-start auth flash. |
| **Signed-in Home placeholder** | Functions correctly as a placeholder (shows name, offers logout) but its `Text` uses no `TextTheme` styling at all — see §6 (inconsistency, not a functional bug). |
| **Loading states** | Present in both places that need one (`AuthGate`'s `unknown` branch; `LoginPage`'s submitting button) using the same `CircularProgressIndicator` widget in both — consistent. |
| **Error states** | Present (`LoginPage`'s inline error `Text`), styled with `Theme.of(context).colorScheme.error` — a semantic role, not a hardcoded color. No error/retry pattern exists for `HomePage` because it performs no data fetch to fail. |
| **Form validation** | Real `Form`/`GlobalKey<FormState>`/`TextFormField.validator`, not manual string-checking — required-field messages shown inline, submit blocked (`if (!validate()) return`) until valid. |
| **Feedback after actions** | Login success is implicit (the screen changes) rather than an explicit confirmation — appropriate for a navigation-causing action; logout has no confirmation step. No `SnackBar`/toast convention exists anywhere yet since no non-navigating action exists yet that would need one (see §6, §10). |
| **Typography** | `LoginPage` uses `Theme.of(context).textTheme.headlineSmall` for its one heading — the only place in the whole app any `TextTheme` role is used. `HomePage` uses no styling at all. No formal hierarchy existed before this phase (see §7). |
| **Spacing** | `LoginPage` is internally consistent (`24` around the form, `16` between fields, `24` before the submit button) — a real, if implicit and undocumented, spacing pattern already in informal use. `HomePage` has no internal spacing decisions to make (a single centered `Text`). |
| **Touch-target sizing** | `FilledButton`/`IconButton`/`TextFormField` are all stock Material 3 widgets at their default sizes, which meet Material's 48×48dp minimum by construction — no custom-sized tap targets exist anywhere to shrink one below the safe minimum. |
| **Contrast** | `ColorScheme.fromSeed` generates Material 3 tonal palettes designed to meet WCAG AA contrast between paired roles (e.g. `onSurface` on `surface`, `error` on `surface`) by construction; the app introduces no custom colors that bypass this. Not independently measured pixel-by-pixel in this static review — flagged as verified by construction, not by direct contrast-ratio measurement (no rendering tool was available in this session). |
| **Visual hierarchy** | `LoginPage` has a clear single hierarchy (heading → fields → action). `HomePage` has none to speak of — appropriate for a one-line placeholder, not evidence of a real hierarchy problem. |
| **Semantic/accessibility behavior** | The logout `IconButton` already carries a `tooltip: 'Log out'` (Flutter's tooltip doubles as its accessibility label) — a real, existing good practice. One real gap: the login error `Text` is not marked as a live region, so a screen reader may not proactively announce it appearing — see §6. |
| **Responsiveness** | `LoginPage` wraps its form in `ConstrainedBox(maxWidth: 360)` + `SingleChildScrollView` — deliberately not full-width-stretched on a wide/tablet screen, and scrollable if the keyboard shrinks available height. A real, if small, existing responsiveness decision, not an oversight. |
| **Consistency** | Both screens share the same `AppBar` title ("Company App") and the same `CircularProgressIndicator` loading pattern. The one inconsistency found is typographic (`LoginPage` uses `textTheme.headlineSmall`; `HomePage` uses none) — see §6. |
| **Platform conventions** | Entirely stock Material widgets (`Scaffold`/`AppBar`/`Form`/`FilledButton`) — no custom-painted chrome, no platform-conditional (Cupertino) branching. Consistent with a single Material design language across Android/iOS, which is a reasonable, common Flutter default for an internal business tool and is not flagged as a gap. |

---

## 5. Existing Strengths

Recorded so this audit does not read as purely a list of gaps — several `06_UI_UX_GUIDELINES.md` principles were already being followed, in the one place they could be, before this phase existed to write them down:

- **Loading / empty / error states are mandatory, not optional polish"** (the guidelines' own words) — already true for the one flow that exists: initial/loading, submitting, invalid-credential, and network-failure states are all distinctly handled, exactly as `06_UI_UX_GUIDELINES.md` §"Shared Principles" already credited to Phase 4.
- **Semantic color use, not raw colors** — `LoginPage`'s error text already uses `Theme.of(context).colorScheme.error` rather than a hardcoded red, which is exactly the "prefer semantic color roles rather than encouraging feature code to use arbitrary raw colors" principle this phase formalizes in §7 — the codebase already defaulted to the right instinct.
- **Real form validation infrastructure**, not a hand-rolled substitute.
- **Deliberate cold-start handling** (`AuthStatus.unknown` → spinner, not a flash of the login screen or a flash of the home screen before the stored token is checked).
- **An existing accessible-name precedent** (the logout button's `tooltip`) to extend, not invent from nothing.
- **A working Material 3 foundation already in place** (`ColorScheme.fromSeed`) — this phase extends and formalizes it rather than replacing it or introducing a competing design system.
- **Deliberate, documented scope discipline** — every file's own doc comments explicitly say what phase built it and what's deliberately deferred (`AuthGate`'s comment citing the Phase 4 flow, `HomePage`'s comment citing the roadmap), which is exactly the kind of "why," not "what," commenting this project's own conventions ask for.

---

## 6. Existing UI/UX Gaps (Actual Defects Only)

Two small, real inconsistencies were found in the *existing* code — not manufactured to fill space, and not counted as gaps merely because more screens don't exist yet (see §11 for what's explicitly excluded from this list):

**G-01 — No dark theme is wired up; the app is light-mode-only regardless of device setting.**
- **File:** `apps/mobile/lib/app/app.dart:46-52`.
- **Detail:** `MaterialApp` supplies only `theme:`, no `darkTheme:` and no `themeMode:`. Flutter's default `themeMode` is `ThemeMode.system`, but with no `darkTheme` supplied, a device set to dark mode still renders the app in its one light `ColorScheme.fromSeed` scheme — silently ignoring the platform setting rather than deliberately opting out of it.
- **Why it matters:** most staff carrying a modern phone will have a device-level dark-mode preference (day/night, battery, personal preference); an app that silently ignores it reads as unfinished rather than deliberately light-only, and this phase's own foundation work (§7) explicitly adopts a "support both, follow system by default" strategy — so this gap is now in direct, documented tension with the newly-recorded decision.
- **Recommended fix (not applied this session):** add a `darkTheme: ThemeData(colorScheme: ColorScheme.fromSeed(seedColor: Colors.indigo, brightness: Brightness.dark))` and `themeMode: ThemeMode.system` to `CompanyApp`'s `MaterialApp`. This is a small, self-contained, low-risk change (touches one file, adds no dependency, changes no business logic) — a reasonable candidate for either a quick Phase 23 follow-up commit or the first line of the next mobile implementation phase, per the product owner's choice (see the Recommendations in the closing report).

**G-02 — `HomePage`'s placeholder text doesn't use the app's own established typography convention.**
- **File:** `apps/mobile/lib/features/home/home_page.dart:27-33`.
- **Detail:** `LoginPage` already establishes the pattern of using `Theme.of(context).textTheme.headlineSmall` for its heading; `HomePage`'s only text (`'Signed in as $userName'` / the bootstrap message) uses a bare, unstyled `Text` widget instead — the two existing screens are inconsistent with each other on the one typographic decision either of them makes.
- **Why it matters:** minor on its own (it's an explicitly-labeled placeholder, not a shipped feature screen), but it's the kind of small drift that compounds once more screens copy whichever pattern they see first — worth fixing before it's imitated, not after.
- **Recommended fix (not applied this session):** style the `Text` with `Theme.of(context).textTheme.bodyLarge` (or `titleMedium`, per the mapped hierarchy in §7) instead of leaving it unstyled.

**No other defects were found.** Contrast, touch targets, keyboard ergonomics, semantic labeling (aside from G-01/G-02 and the one live-region note folded into §7's accessibility baseline as a forward-looking convention rather than a fix to existing code, since no dynamically-announced error currently fails silently in a way a static review can prove), spacing, and responsiveness were all checked and found consistent with `06_UI_UX_GUIDELINES.md`'s principles for the one flow that exists.

---

## 7. Visual-Design Foundation (resolves `06_UI_UX_GUIDELINES.md`'s open design-system question)

Full decision recorded as **DEC-046** in `docs/DECISIONS.md`; summarized here.

**Base system:** Material 3 (`useMaterial3` is Flutter's default from the SDK version this project already targets), continuing — not replacing — the seed-color approach already in `app.dart`. No competing design-system package (Cupertino-first, a third-party UI kit) is introduced.

**Color — semantic roles, not raw colors:**
- **Brand/primary:** `Colors.indigo` remains the seed color (already in use since Phase 4 — no rebrand without a real product reason to change it). `ColorScheme.fromSeed(seedColor: Colors.indigo)` derives the full Material 3 tonal palette (primary/secondary/tertiary/surface/error and their `on*` counterparts) from that one seed — this is itself the "secondary/accent usage" and "surface/background roles" answer: **reuse Material 3's own generated roles** (`colorScheme.secondary`, `colorScheme.surface`, `colorScheme.surfaceContainerHighest`, etc.) rather than hand-picking a second brand color, which would fight the tonal system's own consistency guarantees.
- **Status colors Material 3 doesn't provide out of the box** (success/warning/info — Material only ships `error`): defined via a single `ThemeExtension<AppStatusColors>` (`success`/`onSuccess`, `warning`/`onWarning`, `info`/`onInfo`, mirroring Material's own `color`/`onColor` pairing convention exactly), registered once on `ThemeData.extensions` and looked up via `Theme.of(context).extension<AppStatusColors>()!` from feature code — the Flutter-idiomatic way to add app-specific semantic colors without inventing a parallel, competing color system or leaking raw `Color(0xFF...)` literals into feature widgets.
- **Error:** Material 3's own `colorScheme.error`/`onError` — already in use in `LoginPage`, now formalized as the mandatory pattern rather than an accidentally-correct choice.
- **Disabled states:** Material 3's built-in disabled treatment (`ColorScheme` + widget-level `disabledColor`/opacity state layers) — no separate custom "disabled color" is defined; feature code sets `onPressed: null` (as `LoginPage` already does via `_submitting ? null : _submit`) and lets Material render the correct disabled visual.
- **Text/emphasis levels:** Material 3's own `onSurface` (primary text) vs. `onSurfaceVariant` (secondary/de-emphasized text, e.g. metadata/timestamps) — no bespoke "emphasis" color scale is introduced.
- **Divider/border roles:** Material 3's `colorScheme.outline` (stronger, e.g. text-field borders) and `outlineVariant` (subtler, e.g. list-row dividers) — reused as-is.
- **Light/dark strategy:** support both; default to the device's system setting (`ThemeMode.system`), generating the dark scheme from the same seed color (`ColorScheme.fromSeed(seedColor: Colors.indigo, brightness: Brightness.dark)`) rather than hand-tuning a separate dark palette — proportionate for this app's scale, and Material 3's seed algorithm is specifically designed to produce a coherent, accessible dark variant from one seed. (Note: this decision directly identifies gap **G-01** above — the app does not yet implement this strategy in code.)

**Typography — mapped onto Flutter's stock Material 3 `TextTheme`, no custom font:**

| Guideline role | `TextTheme` mapping |
|---|---|
| Display/page headings (top-level screen title) | `headlineSmall` |
| Section headings (grouping within a screen) | `titleMedium` |
| Titles (list-item/card title) | `titleSmall` |
| Body text | `bodyMedium` |
| Labels (form field labels, chip text) | `labelLarge` |
| Metadata/supporting text (timestamps, secondary lines) | `bodySmall`, paired with `onSurfaceVariant` for de-emphasis |
| Buttons/actions | `labelLarge` (Material's own default for `FilledButton`/`TextButton`/`OutlinedButton` — no override needed) |

No custom font family is introduced — the platform default (Roboto/San Francisco via Flutter's system-font fallback) is retained; a custom font remains a future decision if a real brand requirement emerges, not a default for an internal ~100-employee tool.

**Spacing/layout — one 4px-based scale, reused everywhere:**

| Token | Value | Used for |
|---|---|---|
| `xs` | 4px | Tight inline gaps (icon-to-label) |
| `sm` | 8px | Component spacing (label-to-field, chip padding) |
| `md` | 16px | Screen padding, form-field spacing (already the de facto value in `LoginPage`), card/container internal padding |
| `lg` | 24px | Section spacing, spacing before a primary action (already the de facto value in `LoginPage`) |
| `xl` | 32px | Spacing between major page regions |
| `xxl` | 48px | Rare, large empty-state/hero spacing |

List density defaults to Material's standard comfortable `ListTile` sizing; a compact density variant is deferred until a specific module (e.g. a long Staff Directory) demonstrates a real need — not decided speculatively now.

**Shape/elevation/components — baseline conventions, not a component library:**

| Element | Convention |
|---|---|
| Primary button | `FilledButton` (already used in `LoginPage`) |
| Secondary/less-emphasis button | `OutlinedButton` |
| Tertiary/inline action | `TextButton` |
| Destructive action | The same button types above, styled with the `error` color role — never a hardcoded red |
| Text field | `TextFormField` with `OutlineInputBorder` (outlined style, matching `LoginPage`'s existing default) |
| Card/container | Material `Card`, default M3 shape/elevation, `md` (16px) internal padding |
| Dialog | `AlertDialog` via `showDialog` — used for destructive-action confirmation (per the guidelines' existing "confirmation for destructive actions" principle) |
| Sheet | `showModalBottomSheet` for lightweight contextual/secondary actions (e.g. a future "More" menu) |
| Chip/status indicator | A single status-color role (success/warning/error/info/neutral) **plus a text label always** — never a color-only dot (ties directly into the accessibility baseline's "no color-only status" rule, §8) |
| App bar | Standard `AppBar`, consistent title styling, at most 1–2 action icons (matches existing precedent) |
| List row | `ListTile`-based rows for directory/list screens |
| Empty state | Icon + short message + optional primary action, centered |
| Loading state | `CircularProgressIndicator` (already the app's only pattern) — no shimmer/skeleton library is introduced, proportionate to this app's scale |
| Error state | Inline error text/banner in the `error` role, with a retry action where the error is retryable (mirrors `LoginPage`'s existing pattern) |
| Success/confirmation feedback | `SnackBar` for transient confirmations (e.g. "Saved," "Checked in") — no custom toast library |

This is explicitly a **foundation**, not a component library: no shared widget code (e.g. a reusable `StatusChip` class) was written in this phase — that belongs to the first phase that actually needs one, reusing these conventions rather than each module phase reinventing its own answer.

---

## 8. Accessibility Baseline (resolves `06_UI_UX_GUIDELINES.md`'s open accessibility-target question)

Recorded as part of **DEC-046**. **This is an engineering/design baseline, not a claim of formal WCAG 2.2 AA certification or a compliance audit process** — no accessibility auditing tooling or certification body was engaged.

**Target:** WCAG 2.2 AA principles, applied where they translate to the Flutter/mobile context, combined with native Flutter/platform accessibility practices (Flutter's `Semantics` tree, `TalkBack`/`VoiceOver` compatibility) where WCAG's own web-authored criteria don't map directly.

Practical requirements for future mobile UI work:

- **Color contrast:** text/icon-vs-background pairs must meet WCAG AA ratios (4.5:1 normal text, 3:1 large text/UI components). Material 3's `ColorScheme.fromSeed`-generated role pairs (e.g. `onSurface`/`surface`, `error`/`surface`) meet this by construction; any new semantic color (the `AppStatusColors` extension, §7) must be spot-checked against both the light and dark surface colors when defined, not assumed.
- **Minimum touch targets:** 48×48dp (Material's own standard — stricter and simpler to apply uniformly than WCAG 2.2's 24×24 CSS-px minimum). Every interactive element uses a stock Material widget at its default size unless a specific, documented reason shrinks it.
- **Text scaling:** support the OS-level text-scale factor (Flutter's `MediaQuery.textScaler`) up to at least 200% without clipped or overlapping text — avoid fixed-height containers wrapped tightly around text where a layout can instead grow.
- **Semantic labels:** every icon-only interactive element gets a `tooltip`/`semanticLabel` (the existing logout `IconButton`'s `tooltip: 'Log out'` is the pattern to continue, not a one-off).
- **Screen-reader-friendly controls:** build with real Flutter/Material interactive widgets (`TextButton`, `Checkbox`, `TextFormField`) rather than a bespoke `GestureDetector`-wrapped `Container`, which loses built-in semantics and must be manually reconstructed.
- **Meaningful focus/traversal order:** logical top-to-bottom, natural reading-order traversal matching the visual layout; verify with TalkBack/VoiceOver once real screens exist to test (not verifiable from source alone).
- **No color-only communication of status:** every status indicator pairs its color with a text label or icon — never a bare colored dot (a direct rule feeding into §7's chip/status-indicator convention).
- **Visible selected/disabled/error states:** rely on Material's built-in state layers rather than suppressing default focus/disabled/selected visuals for a custom look.
- **Accessibility-aware loading/feedback:** stock `CircularProgressIndicator`/`SnackBar` already participate correctly in Flutter's semantics tree; avoid custom overlays that opt out of it.
- **Avoid unnecessary motion:** no gratuitous custom animation; respect the OS-level reduce-motion signal where Flutter surfaces it (`MediaQuery.disableAnimations`).
- **Keyboard/focus support where relevant:** primarily matters for the existing keyboard-driven form flow (already present via `TextInputAction.next`/`.done` in `LoginPage`) — this pattern should be preserved and extended to future forms, since Company App targets mobile (Android/iOS), not desktop/web, per `00_PROJECT_CHARTER.md`.
- **Dynamically-appearing content should be announced:** a specific, concrete forward-looking convention this audit surfaced (§4/§6) — an inline error message that appears after a failed action (as `LoginPage`'s already does) should be wrapped so assistive technology announces it (e.g. Flutter's `Semantics(liveRegion: true)` or an equivalent mechanism current at implementation time) rather than silently appearing on-screen only. Not filed as a defect against the existing `LoginPage` (its current behavior cannot be proven to fail this from static review alone), but recorded as a requirement for this exact pattern going forward.

---

## 9. Navigation Architecture Decision (resolves DEC-021's deferred routing-package question)

Recorded as part of **DEC-046**, superseding the routing-package half of DEC-021 specifically (DEC-021's state-management deferral is untouched — DEC-025's plain `ChangeNotifier` choice stands; `go_router` is a routing package, not a state-management framework, and this decision recommends no Provider/Riverpod/Bloc addition).

**Decision: standardize on `go_router` for the first mobile-module UI implementation phase onward. Not added as a dependency in this phase.**

**Reasoning:**
- The guidelines already call for a five-destination bottom navigation shell (`Home | Tasks | Schedule | Messages | More`) with a `More` menu exposing ten further secondary areas, plus per-module list→detail (and in places detail→sub-detail, e.g. Project → Task → Work Log) drill-down — a materially more complex navigation graph than the single linear stack (`AuthGate` → `LoginPage`/`HomePage`) that exists today, where plain `Navigator` push/pop has been entirely sufficient because there has been nothing to navigate to.
- `go_router` is a Flutter-team-maintained package (part of the official `flutter.dev` navigation guidance, not a fragile or unmaintained third-party dependency) that directly supports the two capabilities this app's near-term roadmap will need and hand-rolled `Navigator` logic handles poorly at scale: **declarative redirects** (the existing `AuthGate` pattern — swapping the whole widget tree on auth state — works for one global gate today, but doesn't extend cleanly to per-route guards once there are dozens of routes) and **`StatefulShellRoute`** (the standard mechanism for a bottom-nav shell that preserves each tab's own navigation stack — exactly the `Home | Tasks | Schedule | Messages | More` shape the guidelines already specify).
- **Deep linking** is a concrete, already-partially-built need: Phase 15 (Notifications) already models a typed `source_type`/`source_public_id` reference server-side with no mobile UI yet to consume it — a Notification tapping through to "the Incident Report it's about" is a real, named future requirement that a declarative router with path-based routes serves naturally and a manual `Navigator.push` call chain does not.
- **Testability:** `go_router`'s routes are declarable and testable in isolation from widget trees, which matters more as the number of screens grows from 2 to dozens.
- **Proportionality:** `go_router` is a single, small, stable, first-party-endorsed dependency — not a heavy framework, not a from-scratch custom router, and not overengineering for this application's modest ~100-employee/internal-tool scale. The alternative (continuing with bare `Navigator`) would work today but would very likely need to be replaced mid-way through the module-UI build-out anyway, once the second or third tab's own multi-screen stack is added — better to decide once, now, than to migrate under pressure later.

**Explicitly not done in this phase:** the `go_router` dependency was **not** added to `pubspec.yaml`; no route table, no shell, no code of any kind was written. This is a recorded architectural decision for the first future mobile-UI implementation phase to apply consistently from its first commit — not a pre-installed dependency with nothing yet using it.

---

## 10. Reusable Interaction/State Conventions

Distilled from §7/§8 and folded into `06_UI_UX_GUIDELINES.md` (§6 below) as durable, cross-module conventions rather than one-off decisions:

- **Loading:** `CircularProgressIndicator`, centered for full-screen loads, inline (e.g. inside a button) for action-scoped loads — exactly `AuthGate`/`LoginPage`'s existing two patterns, generalized.
- **Empty state:** icon + short message + optional primary action, centered — not yet instantiated anywhere (no list screen exists yet), but specified now so the first one is consistent.
- **Error state:** inline text/banner in the semantic `error` role, with a retry affordance when the failure is retryable (network errors) and a plain message when it isn't (validation errors) — `LoginPage`'s existing distinction, generalized.
- **Success/confirmation feedback:** `SnackBar` for transient, non-blocking confirmations; `AlertDialog` confirmation *before* a destructive action, not after.
- **Form behavior:** real `Form`/`TextFormField` validation, inline per-field error text, submit disabled while in flight, keyboard `TextInputAction.next`/`.done` chaining between fields — `LoginPage`'s existing pattern, generalized to every future form.
- **Destructive actions:** always an explicit `AlertDialog` confirmation step (mirrors the pre-existing engineering-side "check before destructive action" discipline in `CLAUDE.md`, now made explicit on the UI side too).
- **Status presentation:** one shared color+label(+icon) chip convention (§7) reused for every status concept across every module (Task status, Leave status, Incident severity, etc.) rather than each module inventing its own visual language — directly extends the guidelines' pre-existing "status consistency" principle from a stated intention into a concrete shape.
- **Responsive/mobile behavior:** constrain form/content width on wider viewports (`LoginPage`'s existing `ConstrainedBox(maxWidth: 360)` pattern) rather than naively stretching every element edge-to-edge; scroll rather than clip when the keyboard reduces available height.

---

## 11. Explicit Exclusions

The following were **not** done in this phase, per explicit product-owner instruction:

- No business-module mobile screens (Tasks, Schedule, Messages, Clients, Projects, Staff, Work Logs, Leave, Service Reports, Incident Reports, Announcements, Location/Check-in, Settings, or any other module).
- No bottom-navigation shell implementation.
- No routing package added to `pubspec.yaml`, and no routing code written.
- No detailed wireframes for any business module.
- No Laravel API changes, no Admin Backoffice UI changes, no new backend permissions, no backend feature development, no new business functionality of any kind.
- No fix applied for G-01 or G-02 (§6) — documented only, per instruction not to "silently begin fixing UI issues in this planning/audit pass."
- **The absence of business-module mobile UI is not classified as a defect anywhere in this document.** Phases 6 through 21 each explicitly excluded Flutter implementation from their own scope, by repeated, deliberate, documented decision — not by oversight. `docs/ROADMAP.md`/`docs/CURRENT_STATE.md` do not claim that UI should already exist; therefore, per the product owner's own instruction, its absence is recorded in §2 as inventory and in this section as scope, never as a finding in §6.

---

## 12. Recommendations for Future Mobile UI Phases

1. **Fix G-01 (dark theme) and G-02 (`HomePage` typography)** — small, low-risk, single-file changes. Recommend doing them as a short, explicit follow-up (either a narrow Phase 23 remediation commit, if the product owner wants Phase 23 itself to close them out, or as the very first commit of the next mobile implementation phase) rather than folding them silently into a larger future phase's diff.
2. **The first module-UI implementation phase should add `go_router` and build the bottom-navigation shell together**, as one coherent unit of work — the shell has no purpose without at least one real destination beyond Home, and building the shell in isolation first would risk the same "build now, nothing uses it yet" speculative-infrastructure problem `CLAUDE.md` §3 warns against. Whichever module is picked first (Tasks and Schedule are the two most immediately useful, per the guidelines' own primary-navigation ordering) should be the one that proves the shell.
3. **Extract the `AppStatusColors` `ThemeExtension` and the spacing-token constants (§7) into `lib/app/` or a new `lib/core/theme/` location** as the very first code change of that same future phase — before any feature screen is written, so every module built afterward consumes the same shared foundation from day one rather than each phase defining its own local constants.
4. **Do not revisit the visual-design/accessibility/navigation decisions themselves** without a real, demonstrated reason (e.g., a genuine product need for a second brand color, a demonstrated performance problem with `go_router`) — treat DEC-046 as settled the same way DEC-017 (identifiers)/DEC-018 (Laravel organization) are treated: a foundation other phases build on, not something to re-litigate per module.
5. **When the first real list screen is built** (most likely Tasks or the Staff Directory), that is the natural point to validate the "comfortable" list-density default (§7) against real data volume — revisit only if a genuine density problem is demonstrated, not preemptively.

---

## 13. Definition of Done

- [x] Repository state re-confirmed (branch, clean working tree, Phase 22/PR #24 merge independently verified).
- [x] Every file in `apps/mobile/lib/` read in full and inventoried (§2) — no file skipped, no assumption made about content not directly read.
- [x] Every item on the product owner's audit checklist addressed against the actual existing screens (§4).
- [x] Existing strengths recorded, not just gaps (§5).
- [x] Existing UI/UX gaps limited to genuine, evidenced defects (two: G-01, G-02) — no finding manufactured merely because the UI is intentionally minimal (§6).
- [x] Visual-design foundation defined at the principles/system level: color (semantic roles + light/dark strategy), typography (mapped hierarchy, no new font dependency), spacing (one token scale), and baseline component conventions — without designing any individual business-module screen (§7).
- [x] Accessibility baseline adopted (WCAG 2.2 AA principles + native Flutter/platform practices), framed explicitly as an engineering/design baseline, not certification (§8).
- [x] Navigation architecture decision made (`go_router`) and its reasoning tied to this application's actual near-term roadmap (bottom-nav shell, deep linking from Notifications, growing screen count) — with no code/dependency added (§9).
- [x] Reusable interaction/state conventions distilled for reuse by every future module phase (§10).
- [x] Explicit exclusions stated, including the express instruction that "screen doesn't exist yet" is never itself a finding (§11).
- [x] Recommendations for future mobile phases recorded, without assuming authorization to start any of them (§12).
- [x] `docs/DECISIONS.md` DEC-046 recorded; DEC-021 annotated with a forward pointer (not rewritten).
- [x] `docs/06_UI_UX_GUIDELINES.md` substantially expanded with the resolved foundation, kept at the principles/system level (no wireframes, no per-module layouts).
- [x] `docs/02_ARCHITECTURE.md` §12 updated to point to DEC-046 for the routing decision.
- [x] `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/testing/TEST_STATUS.md` updated per CLAUDE.md §6.
- [x] `docs/handoffs/V1_PHASE_23_HANDOFF.md` written.
- [ ] **Not done, and explicitly out of this phase's scope:** any Flutter code change (including the two recommended small fixes, G-01/G-02), the bottom-navigation shell, the `go_router` dependency/routing code, and any business-module screen. These await separate authorization.
