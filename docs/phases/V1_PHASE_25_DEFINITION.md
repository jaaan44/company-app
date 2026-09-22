# Phase 25 — Mobile Application Foundation & Navigation Shell — Specification

**Status:** DRAFT — not yet authorized for implementation
**Depends on:** Phase 4 (Authentication), Phase 23 (Mobile UI/UX Audit & Foundation — DEC-046)
**Supersedes (sequencing only):** the original `docs/ROADMAP.md` Phase 25 ("UAT") — see DEC-048. No prior implementation decision (DEC-021/DEC-025/DEC-026/DEC-046) is reopened; this phase implements decisions those already made.

## Objective

Establish the production-quality Flutter navigation and theming foundation — a `go_router`-based bottom-navigation shell wired to the existing authentication state — into which every subsequent mobile business-module phase (Phases 27–33) can add a real screen without ever again having to touch routing, theming, or app-shell architecture. This phase makes the Flutter app *structurally* ready for the business modules that follow; it does not build any of those modules itself.

## Rationale

Phase 23 (DEC-046) deliberately resolved the mobile visual-design, accessibility, and navigation-architecture *decisions* without implementing any of them, specifically to avoid inventing those foundations under the pressure of also delivering a first real feature. The Phase 25 discovery/readiness assessment (2026-09-22) confirmed, by direct inspection of `apps/mobile/lib`, that nothing has changed since: the app is still exactly `LoginPage` + a placeholder `HomePage`, switched by a single `StatefulWidget` (`AuthGate`) with no routing package. The product owner has now directed that the employee mobile application be built out before the Admin Backoffice (DEC-048) — this phase is the first step of that direction, and is scoped narrowly to the foundation itself so that Phase 27 onward can start immediately on real features rather than re-deriving architecture.

## In Scope

1. **Add `go_router` as a dependency** (`apps/mobile/pubspec.yaml`) — the single package DEC-046 already named, at whatever stable version is current when this phase is actually implemented.
2. **Replace `MaterialApp(home: AuthGate(...))` with `MaterialApp.router`**, using a `GoRouter` configured with:
   - An unauthenticated route: `/login`.
   - An authenticated shell route (`StatefulShellRoute`) with five branches matching `06_UI_UX_GUIDELINES.md`'s already-specified bottom navigation exactly: `/home`, `/tasks`, `/schedule`, `/messages`, `/more` — each branch preserves its own navigation stack, per `StatefulShellRoute`'s standard purpose.
   - A `redirect` callback (or per-route `redirect`) that maps `AuthController.status` to the correct location — unauthenticated → `/login`, authenticated → the shell (default `/home`) — replacing `AuthGate`'s current widget-switch with the declarative, route-level equivalent DEC-046 specifically named as the reason for adopting `go_router` at all.
   - `GoRouter(refreshListenable: authController, ...)` so route redirection re-evaluates automatically on every `AuthController` state change — `AuthController` is already a `ChangeNotifier` (DEC-025), so this requires no new state-management package, exactly as DEC-046 anticipated.
   - The existing `AuthStatus.unknown` bootstrap-loading behavior (`AuthGate`'s current `CircularProgressIndicator` Scaffold, shown while `AuthController.bootstrap()` resolves the stored token) is preserved as-is — relocated into the router's initial-location/redirect handling rather than a widget switch, but not redesigned. No new loading-state pattern is introduced; this is `06_UI_UX_GUIDELINES.md`'s own existing Loading convention (`CircularProgressIndicator`, no shimmer/skeleton library), already correctly followed here since Phase 4.
3. **Build the bottom-navigation shell widget itself** (the persistent `Scaffold` + `NavigationBar`/`BottomNavigationBar` wrapping the five branches), per `06_UI_UX_GUIDELINES.md`'s navigation principles (minimal depth, clear status/active-tab indication).
4. **Four new placeholder destination screens** (Tasks, Schedule, Messages, More) — honestly labeled structural scaffolding only (e.g. a centered "Tasks — coming soon" message, matching the existing `HomePage` placeholder's own honesty about its non-final state), with **no API calls, no fetched data, and no business logic of any kind**. Each is replaced by its owning phase (27–33) with zero further router/shell change required. `Home` keeps its existing placeholder content unchanged in substance (see item 6 below for its one styling fix) — Phase 27 is what gives it real content.
5. **Fix G-01** (`docs/phases/V1_PHASE_23_MOBILE_UIUX_AUDIT.md` finding, DEC-046): add `darkTheme`/`themeMode: ThemeMode.system` to the app's theme configuration, using `ColorScheme.fromSeed(seedColor: Colors.indigo, brightness: Brightness.dark)` exactly as DEC-046 specified — the dark scheme is generated from the same seed, not a hand-tuned separate palette.
6. **Fix G-02** (same audit): `HomePage`'s placeholder text is updated to use the `TextTheme` styling convention `LoginPage` already established (`06_UI_UX_GUIDELINES.md`'s Typography table), closing the one documented inconsistency between the app's two existing screens.
7. **Adapt `HomePage`'s current `userName`/`onLogout` constructor-parameter coupling to `AuthGate`**, since `AuthGate` itself is removed by this phase — `HomePage` (and the shell generally) reads authentication state from the shared `AuthController` instance by whatever idiomatic mechanism this phase's implementation chooses (e.g. an `InheritedNotifier`/`InheritedWidget` exposing the existing single `AuthController`, or `go_router`'s own `context.read`-style access to a value provided at the router/app root) — **not** a new state-management package (DEC-025 is not reopened; this is wiring, not a new architecture).
8. **A mobile testing foundation for the shell and redirect behavior**: automated tests (Flutter's existing `flutter_test`/`integration_test` tooling, no new test framework) covering — unauthenticated app launch redirects to `/login`; a successful login redirects into the shell; an already-authenticated relaunch (stored valid token) restores directly into the shell without flashing the login screen; logout redirects back to `/login`; each of the five bottom-navigation destinations is reachable and renders its (placeholder or real, for Home) content; switching tabs and back preserves each tab's own navigation position (the concrete behavior `StatefulShellRoute` exists to provide). These mirror and extend Phase 4's own precedent (`auth_controller_test.dart` and friends), not a new testing philosophy.
9. **Directory placement**: new router/shell code follows the existing `apps/mobile/lib` convention (DEC-021) — most naturally a new `lib/app/router.dart` (or `lib/features/shell/`) rather than scattering route definitions across feature folders; this phase should record its actual placement in the resulting handoff, not invent a new top-level convention beyond what DEC-021 already established.

## Explicitly Out of Scope

Per the product owner's explicit direction, this phase is the foundation only — none of the following belong here, regardless of how small they might seem in isolation:

- Any real content for Tasks, Schedule, Messages, or More beyond an honestly-labeled placeholder (see item 4 above) — these are Phases 29, 30, 32, and (in part) several others.
- Real content for the Home tab beyond its existing placeholder plus the G-02 styling fix — that is Phase 27 (Employee Home / Dashboard).
- Any business-module screen: Staff Directory, Profile, Clients, Projects, Work Logs, Leave, Announcements, Notifications, Messaging (the actual conversation UI, as opposed to the empty `/messages` placeholder tab), Scheduler, Service Reports, Incident Reports, or attachment upload/download UI.
- The `AppStatusColors` semantic-color `ThemeExtension` DEC-046 specified — deliberately deferred to the phase that first renders a status concept (most likely Phase 29 or 31), since nothing in this phase's own scope needs it and defining it now with no consumer would be exactly the kind of speculative infrastructure `CLAUDE.md` §3 and DEC-046 itself warn against for `go_router`.
- A shared/reusable loading, empty, or error-state **widget library** (e.g. a generic `LoadingView`/`EmptyState`/`ErrorBanner` class) — `06_UI_UX_GUIDELINES.md` already states these conventions exist as guidance, "not code that exists yet," and this phase has no list/detail screen whose real usage should shape such a library's API. The one loading moment this phase does have (auth bootstrap) reuses the existing inline `CircularProgressIndicator` pattern as-is (see In Scope item 2) — building a generalized library now, before a second and third concrete usage exist to shape it, would be premature abstraction.
- Deep-link *consumption* (e.g. tapping a Notification through to a specific Task/Incident) — `go_router` makes this possible once routes exist, but no Notification-tap-through behavior is built in this phase, since Notifications has no mobile UI at all yet.
- Navigation state restoration across process death (Android `restorationScopeId` or equivalent) — no evidence of a concrete need yet at this application's scale; revisit only if a later phase surfaces a real complaint.
- Any Admin Backoffice change of any kind.
- Any Laravel/API change beyond what is strictly required to keep the existing `/auth/login`/`/auth/me`/`/auth/logout` contract working exactly as today — this phase should require none at all, since Authentication's API surface is unchanged.
- Staging TLS/domain/UFW/mobile-connectivity work — that is Phase 26.
- Any dependency-maintenance/version-bump work unrelated to adding `go_router` itself (e.g. the currently-pending PR #30/#31 Dependabot PRs are explicitly out of this phase's scope).
- UAT, production release, or any Phase 27+ business-module work of any kind.

## Dependencies

- **Phase 4 (Authentication):** `AuthController`, `AuthApiClient`, `TokenStorage`/`SecureTokenStorage`, and the `/auth/login`/`/auth/me`/`/auth/logout` API contract are reused entirely as-is — this phase adapts how they're *wired into navigation*, not their own behavior.
- **Phase 23 (Mobile UI/UX Audit & Foundation, DEC-046):** supplies the navigation architecture choice (`go_router`), the exact bottom-navigation shape, the Material 3 visual-design foundation (including the dark-theme approach this phase implements), and the accessibility baseline this phase's shell/navigation UI must follow (48×48dp touch targets, semantic labels on the bottom-navigation icons, focus order, etc. — `06_UI_UX_GUIDELINES.md`'s Accessibility Baseline section).
- **No dependency on Phase 26 or later** — this phase does not require staging, TLS, or any deployment change; it is developed and tested exactly as Phase 4's own Flutter work was, against a local/dev API target (`AppConfig`'s existing `--dart-define`-based `API_BASE_URL`, DEC-021 — unchanged).

## Architecture Constraints

- No new state-management package (Provider/Riverpod/Bloc) — `AuthController`'s existing plain `ChangeNotifier` (DEC-025) remains the single piece of shared app state, now also serving as `GoRouter`'s `refreshListenable`.
- No new HTTP client — the existing `http`-based `AuthApiClient` is unchanged.
- No new secure-storage mechanism — `TokenStorage`/`SecureTokenStorage` (DEC-026) is unchanged.
- `go_router` is the only new `pubspec.yaml` dependency this phase introduces.
- The existing `apps/mobile/lib` top-level structure (`app/`, `core/config/`, `features/`) is retained (DEC-021) — the shell/router is new code within that structure, not a restructuring of it.

## Implementation Boundaries

- `AuthGate` (`lib/features/auth/presentation/auth_gate.dart`) is retired by this phase's router-based redirect logic — its current responsibility moves into the `GoRouter` configuration, not into a second, parallel gating mechanism.
- `HomePage`'s constructor contract (`userName`/`onLogout` passed in directly) changes because its caller (`AuthGate`) no longer exists in this form — the replacement mechanism must be named and justified in this phase's own handoff, not left implicit.
- `LoginPage` itself is not required to change beyond whatever minimal adjustment is needed to work as a `go_router` route target (e.g. how it signals a successful login back to the router) — its actual form/validation/error-handling logic is unchanged.
- No change to `apps/api` (Laravel) of any kind is anticipated; if implementation discovers one is genuinely required (e.g. a CORS consideration that does not exist today because no browser client exists), it must be raised for explicit authorization before being made, per `CLAUDE.md` §3 — it is not pre-authorized by this document.

## Acceptance Criteria

- `go_router` is the sole navigation mechanism in the app — no remaining use of `MaterialApp.home`/imperative `Navigator.push` for top-level navigation.
- All five bottom-navigation destinations (`Home`, `Tasks`, `Schedule`, `Messages`, `More`) exist as real, reachable routes with a working `NavigationBar`/`BottomNavigationBar`, each preserving its own navigation stack across tab switches.
- An unauthenticated user is redirected to `/login` from any route; a successful login lands in the shell; an already-authenticated relaunch restores directly into the shell with no visible flash of the login screen; logout returns to `/login`.
- The app's `MaterialApp` supplies both `theme` and `darkTheme`, with `themeMode: ThemeMode.system` — the device's dark-mode setting is honored (G-01 closed).
- `HomePage`'s placeholder text uses the same `TextTheme` convention `LoginPage` already established (G-02 closed).
- `dart format --output=none --set-exit-if-changed .`, `flutter analyze`, and `flutter test` all pass (`CLAUDE.md` §5's standing Flutter quality gates), with no new warnings introduced.
- No business-module screen, no Admin Backoffice change, and no unrelated dependency bump is present in the resulting diff.

## Testing Expectations

- **Automated:** widget/route tests covering every scenario listed in In Scope item 8 above, following this project's existing Flutter test conventions (fake `TokenStorage`/`AuthApiClient` injection, as Phase 4's own tests already establish — see `docs/handoffs/V1_PHASE_04_HANDOFF.md`).
- **Manually verified:** the AI session implementing this phase should manually confirm dark-mode toggling (device/emulator dark mode on and off) and bottom-navigation tab switching on at least one platform target, and record this as "Manually verified," not "Tested automatically," per `CLAUDE.md` §7's testing-discipline distinction.
- **Awaiting UAT:** per `CLAUDE.md` §7, no UAT entry for this phase may be marked `PASS` except by the product owner directly. A `docs/testing/UAT_LOG.md` row should be added in `NOT RUN` state once this phase's scenarios are ready for the product owner's own review (most naturally: does the app feel navigable, does dark mode look right, does session restore still work) — this is optional-but-recommended for this phase given its foundation-only nature, not a hard gate before Phase 27 begins.

## Documentation Expectations

Per `CLAUDE.md` §6, an authorized implementation of this phase must update, at minimum:
- `docs/CURRENT_STATE.md` (mark Phase 25 complete, describe what was actually built vs. this document's plan, note any deviation).
- `docs/CHANGELOG.md`.
- `docs/06_UI_UX_GUIDELINES.md` (mark the Navigation Architecture section as implemented, not just decided; record the dark-theme implementation).
- `docs/02_ARCHITECTURE.md` if it references Flutter's current structure in a way this phase changes.
- `docs/DECISIONS.md` only if implementation surfaces a genuine new decision beyond what DEC-046/DEC-048 already made (e.g. a specific `go_router` version pin, or a concrete choice about how `HomePage` accesses `AuthController` post-`AuthGate`) — not a restatement of DEC-046.
- A phase handoff, `docs/handoffs/V1_PHASE_25_HANDOFF.md`, per `docs/handoffs/README.md`'s standard format.

## Completion Criteria

This phase is complete when: the acceptance criteria above are all met and verified; the documentation expectations above are all satisfied; the handoff is written; and the product owner has been given the result to review — per `CLAUDE.md` §8's Stop Discipline, implementation must not proceed into Phase 26 or any Phase 27+ business-module work automatically, even though the roadmap makes the next step obvious.

## Relevant Documentation

- `docs/06_UI_UX_GUIDELINES.md` — Navigation Architecture, Mobile Visual-Design Foundation, Accessibility Baseline, Shared Interaction/State Conventions sections.
- `docs/DECISIONS.md` — DEC-021 (Flutter foundation structure), DEC-025 (state management), DEC-026 (token storage), DEC-046 (Phase 23's foundation decisions), DEC-048 (this re-baseline).
- `docs/phases/V1_PHASE_23_MOBILE_UIUX_AUDIT.md` — the G-01/G-02 findings this phase closes.
- `docs/handoffs/V1_PHASE_04_HANDOFF.md` — the existing Authentication flow and its own testing conventions, both reused as-is.
- `docs/02_ARCHITECTURE.md` §12 (Flutter architecture pointer) and §13 (Authentication).

## Notes

- **This document is a specification, not an authorization.** Per `CLAUDE.md` §1, implementation of this phase requires explicit product-owner authorization of this document (or a revised version of it) before any Flutter code, dependency, or configuration change is made.
- **Open implementation-time question, not resolved here:** the exact mechanism by which `HomePage` (and the shell generally) accesses the shared `AuthController` once `AuthGate` is retired (see Implementation Boundaries) is left to the implementing session's judgment, constrained only by "no new state-management package" — this document deliberately does not over-specify a widget-tree mechanism that the actual `go_router` integration may make an obvious choice once real code is being written.
- **`go_router`'s exact version** should be whatever is current and stable at implementation time, recorded in the resulting handoff and `pubspec.lock` — not pinned speculatively in this planning document.
