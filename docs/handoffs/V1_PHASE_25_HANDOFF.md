# Phase 25 — Mobile Application Foundation & Navigation Shell — Handoff

## 1. Phase Identification

- **Phase:** 25 — Mobile Application Foundation & Navigation Shell
- **Date:** 2026-09-22
- **Branch:** `claude/phase-25-mobile-foundation-uj4aqb`, from `main` at `f2bddba7647bf7deca12d2ab5b811e5a6f15b475` (verified: local `main` matched `origin/main`, HEAD matched exactly, working tree clean, no open pull requests — all confirmed before implementation began).
- **Pull request:** [#37](https://github.com/jaaan44/company-app/pull/37), opened against `main`, not yet merged.

## 2. Objective

Establish the production-quality Flutter navigation and theming foundation — a `go_router`-based bottom-navigation shell wired to the existing authentication state — into which every subsequent mobile business-module phase (Phases 27–33) can add a real screen without ever again having to touch routing, theming, or app-shell architecture. Per `docs/phases/V1_PHASE_25_DEFINITION.md`, this phase makes the Flutter app *structurally* ready for the business modules that follow; it does not build any of those modules itself.

## 3. Scope Implemented

All of `V1_PHASE_25_DEFINITION.md`'s In Scope items were implemented:

1. `go_router` added as a dependency (`^18.0.1`).
2. `MaterialApp(home: AuthGate(...))` replaced with `MaterialApp.router`, using a `GoRouter` with an unauthenticated `/login` route, an authenticated `StatefulShellRoute` (five branches: `/home`, `/tasks`, `/schedule`, `/messages`, `/more`), a `redirect` callback mapping `AuthController.status` to the correct location, and `refreshListenable: authController`.
3. The bottom-navigation shell widget (`AppShell`) built.
4. Four new placeholder destination screens (Tasks, Schedule, Messages, More) — honest, no API calls, no business logic.
5. G-01 fixed: `darkTheme`/`themeMode: ThemeMode.system` added, dark scheme from the same seed.
6. G-02 fixed: `HomePage`'s placeholder text uses the `TextTheme` convention `LoginPage` already established.
7. `HomePage`'s `userName`/`onLogout` constructor coupling to the now-retired `AuthGate` replaced with `AuthScope` (an `InheritedNotifier<AuthController>`).
8. A mobile testing foundation for the shell/redirect behavior (`test/app/router_test.dart`).
9. New code placed at `lib/app/router.dart` + `lib/app/auth_scope.dart` (root-level, alongside `lib/app/app.dart`) and `lib/features/shell/presentation/` (a new feature folder, following DEC-021's existing convention).

The `AuthStatus.unknown` bootstrap-loading behavior is preserved as-is (a `CircularProgressIndicator` Scaffold), relocated from `AuthGate`'s widget switch into a dedicated `/splash` route the router redirects to and away from — not redesigned.

## 4. Implementation Summary

**Router (`lib/app/router.dart`):** `buildAppRouter(AuthController)` returns a `GoRouter` with `initialLocation: '/splash'`, `refreshListenable: authController`, and a `redirect` function:
- `AuthStatus.unknown` → `/splash` (unless already there).
- Not authenticated (`unauthenticated`/`authenticating`) → `/login` (unless already there).
- Authenticated and at `/login` or `/splash` → `/home`.
- Otherwise, no redirect.

Because `redirect` only returns a new location when the current one doesn't already match the target, a settled state produces no further redirect — this is the loop-prevention mechanism, verified by a dedicated test (§9 below) that navigates an authenticated session back to `/login` and confirms it bounces straight back without hanging.

**Shell (`lib/features/shell/presentation/app_shell.dart`):** `AppShell` wraps `StatefulNavigationShell` in a `Scaffold` with a Material 3 `NavigationBar`, `selectedIndex`/`onDestinationSelected` driven by `navigationShell.currentIndex`/`goBranch()`. `StatefulShellRoute.indexedStack` (go_router's built-in mechanism, not custom code) keeps every branch's `Navigator` mounted off-screen when inactive — the concrete way each tab's own navigation stack survives a tab switch.

**Auth/shell wiring (`lib/app/auth_scope.dart`):** `AuthScope extends InheritedNotifier<AuthController>`, instantiated once by `CompanyApp` and wrapped around `MaterialApp.router` — every route the router builds is therefore a descendant and can call `AuthScope.of(context)`. `HomePage` uses this instead of constructor parameters; no other screen needed it in this phase.

**`CompanyApp` (`lib/app/app.dart`):** now owns both the `AuthController` (as before) and a `GoRouter` built from it (`late final GoRouter _router = buildAppRouter(_authController)`), calls `_authController.bootstrap()` from `initState` (moved up from the former `AuthGate.initState`), and disposes both the router and the controller. `build()` returns `AuthScope(controller: ..., child: MaterialApp.router(theme: ..., darkTheme: ..., themeMode: ThemeMode.system, routerConfig: _router))`.

**Placeholders (`lib/features/shell/presentation/placeholder_page.dart`):** one shared internal `PlaceholderPage(title, message)` widget plus four thin, individually-addressable public classes (`TasksPlaceholderPage`, `SchedulePlaceholderPage`, `MessagesPlaceholderPage`, `MorePlaceholderPage`) so each remains independently findable by type in tests and independently replaceable by its owning future phase.

**`AuthGate` retired:** `lib/features/auth/presentation/auth_gate.dart` deleted. Its former responsibility (deciding between the login screen and the authenticated shell based on `AuthController.status`) now lives entirely in the router's `redirect` callback — not duplicated into a second gating mechanism, per the phase definition's Implementation Boundaries.

## 5. Files Changed

**Added:**
- `apps/mobile/lib/app/router.dart`
- `apps/mobile/lib/app/auth_scope.dart`
- `apps/mobile/lib/features/shell/presentation/app_shell.dart`
- `apps/mobile/lib/features/shell/presentation/placeholder_page.dart`
- `apps/mobile/test/app/router_test.dart`
- `docs/handoffs/V1_PHASE_25_HANDOFF.md` (this file)

**Modified:**
- `apps/mobile/lib/app/app.dart` — `MaterialApp.router`, dark theme, router/AuthScope wiring.
- `apps/mobile/lib/features/home/home_page.dart` — `AuthScope` instead of constructor params; G-02 typography fix.
- `apps/mobile/pubspec.yaml` — `go_router: ^18.0.1` added.
- `apps/mobile/pubspec.lock` — updated by `flutter pub add go_router` (Flutter tooling, not hand-edited); `go_router` is the only new "direct main" entry — `flutter_localizations`/`intl`/`material_ui`/`cupertino_ui` are transitive.
- `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/02_ARCHITECTURE.md` (§12/§13 updated, new §32), `docs/06_UI_UX_GUIDELINES.md` (Navigation Architecture + dark-theme sections marked implemented), `docs/DECISIONS.md` (new DEC-049), `docs/testing/UAT_LOG.md` (four new `NOT RUN` Phase 25 scenarios).

**Removed:**
- `apps/mobile/lib/features/auth/presentation/auth_gate.dart` — retired per the phase definition's Implementation Boundaries.
- `apps/mobile/test/features/auth/auth_gate_test.dart` — tested the now-nonexistent `AuthGate` widget in isolation; its scenarios are re-expressed at the `CompanyApp`+router level in `test/app/router_test.dart`.

**Not touched:** `apps/api` (no Laravel/API change of any kind — confirmed by `git status`), Admin Backoffice, any infrastructure/staging asset, `lib/features/auth/data/`, `lib/features/auth/state/`, `lib/features/auth/domain/`, `lib/core/config/` (all unchanged, as the phase definition required).

## 6. Database/Schema Changes

None. This phase touches only the Flutter mobile client.

## 7. API Changes

None. The existing `/auth/login`/`/auth/me`/`/auth/logout` contract is consumed exactly as before — `AuthApiClient`/`TokenStorage`/`AuthController` are unmodified.

## 8. Authorization/Security Changes

None. No new permission, role, or authorization mechanism — this phase is purely client-side navigation/theming.

## 9. Tests Added or Changed

**New: `apps/mobile/test/app/router_test.dart` (10 tests):**
- Unauthenticated launch redirects to the login screen.
- A valid stored token restores directly into the shell with no login-screen flash (asserts neither `LoginPage` nor `HomePage` is present while bootstrap is still resolving, only the splash `CircularProgressIndicator`).
- Successful login lands in the shell on the Home tab.
- Logging out returns to the login screen and clears the token.
- An authenticated user navigating back to `/login` is bounced straight back to the shell (redirect-loop prevention).
- Every one of the five destinations is reachable and renders its own content.
- The active tab is indicated in the `NavigationBar` (`selectedIndex`).
- Switching away from a tab keeps its branch mounted (`skipOffstage: false` still finds it) rather than disposing and rebuilding it — the concrete `StatefulShellRoute.indexedStack` guarantee.
- Light theme is applied by default; dark theme is applied when `platformBrightnessTestValue` is set to `Brightness.dark`.

**Removed:** `apps/mobile/test/features/auth/auth_gate_test.dart` (5 tests) — the widget it tested (`AuthGate`) no longer exists; its scenarios (loading indicator during bootstrap, no-token→login, valid-token→shell, login→shell, logout→login) are all re-covered by the new file, now exercised through the real router instead of a standalone widget.

**Unchanged and still passing:** `auth_controller_test.dart` (8 tests), `login_page_test.dart` (3 tests), `widget_test.dart` (1 test) — none of `AuthController`, `LoginPage`, or their own tests needed to change.

**Full suite result: 22/22 passing** — the baseline 17 tests (confirmed passing before any change was made) minus the 5 removed `auth_gate_test.dart` tests, plus the 10 new `router_test.dart` tests: 17 − 5 + 10 = 22.

## 10. Commands/Checks Executed

Run from `apps/mobile/` against a real Flutter 3.47.2/Dart 3.13.2 SDK (installed for this session — see §16):

```
flutter pub get
dart format --output=none --set-exit-if-changed .
flutter analyze
flutter test
```

## 11. Results

- `flutter pub get` — resolved cleanly; `go_router` and its transitive dependencies installed, no version conflicts.
- `dart format --output=none --set-exit-if-changed .` — **initially failed** (3 new/modified files needed formatting: `lib/app/router.dart`, `lib/features/shell/presentation/placeholder_page.dart`, `test/app/router_test.dart`); ran `dart format .` to apply formatting, then re-ran the check — **passed clean, 0 files changed.**
- `flutter analyze` — **No issues found!**
- `flutter test` — **22/22 passing**, 0 failures, 0 skipped.

## 12. Deviations from Specification

None of substance. Two implementation-time specifics the definition deliberately left open were resolved and recorded as **DEC-049**: the exact `go_router` version (`^18.0.1`, current stable at implementation time) and the mechanism for `HomePage`'s post-`AuthGate` `AuthController` access (`AuthScope`, an `InheritedNotifier` — chosen over threading the controller through every route `builder` or a per-widget `ListenableBuilder`, both more repetitive for the same effect).

## 13. Known Issues/Limitations

- **No genuine manual/device verification was possible in this session.** `V1_PHASE_25_DEFINITION.md`'s Testing Expectations ask for dark-mode toggling and bottom-navigation tab switching to be "manually verified" on at least one platform target. This session's sandbox has no Android/iOS toolchain, emulator, simulator, or browser target — `flutter devices` finds only a `linux-desktop` target the project has no `linux/` platform folder for (adding one was judged out of this phase's scope, since it would add a new platform directory unrelated to the phase's actual goal). Automated widget-test coverage of both behaviors exists (§9 above) and passed, but this is "Tested automatically," not "Manually verified," per `CLAUDE.md` §7's distinction — recorded honestly as a known limitation, not claimed done. Four `NOT RUN` UAT-25-0x entries were added to `docs/testing/UAT_LOG.md` for the product owner's own review; a natural point to pick up the device-level check is Phase 26, which already requires a real device for staging connectivity verification.
  - A secondary attempt was made to capture actual rendered screenshots (light/dark, multiple tabs) via the Flutter test engine's own `RenderRepaintBoundary.toImage()` as an informal visual sanity check, run as a temporary scratch test file never intended to be committed. It produced one screenshot successfully (confirming the app renders and the light theme looks correct) before stalling on a later step; it was killed and the scratch file deleted rather than debugged further, since it was never a required deliverable and the automated test suite already provides the required coverage. This does not affect the correctness of anything committed — no scratch file or screenshot is part of this phase's diff.
- **`go_router`'s transitive dependencies** (`flutter_localizations`, `intl`, `material_ui`, `cupertino_ui`) are new to `pubspec.lock` but are not directly used by any of this phase's own code — they exist because `go_router` itself depends on them. Confirmed via `pubspec.lock` inspection that `go_router` is the only "direct main" addition.

- **Later finding (D-1, discovered 2026-09-23 during Phase 26 Gate 2F, not in this phase's original report):** Android release builds lacked `android.permission.INTERNET`. Only the `debug`/`profile` manifests declared it, as in the Flutter template since Phase 1, so this phase's automated tests and any debug run could not reveal it. Fixed in Phase 26 Gate 2F by adding the permission to the main manifest; see `docs/handoffs/V1_PHASE_26_HANDOFF.md`, Gate 2F addendum.

## 14. Manual/UAT Testing Instructions

For the product owner, on a real device or emulator with the API reachable (local dev, per `AppConfig`'s existing `--dart-define=API_BASE_URL=...`):

1. Launch the app fresh (no prior login) → should land on the login screen with no flash of anything else.
2. Sign in with valid credentials → should land on the Home tab of the bottom-navigation shell.
3. Force-quit and relaunch the app → should restore directly into the shell (no login-screen flash) since the token is still valid.
4. Tap through Home, Tasks, Schedule, Messages, More → each should show its own content (Home's real placeholder text; the other four's "— coming soon" placeholders); the active tab should be visually indicated in the bottom bar.
5. Navigate away from a tab and back → should feel instant, no reload.
6. Toggle the device's system dark-mode setting → the app's colors should follow it.
7. Tap the logout icon (top-right of the Home tab) → should return to the login screen and require signing in again on next launch.

See the four `UAT-25-0x` rows added to `docs/testing/UAT_LOG.md` (all `NOT RUN`) for the exact scenarios awaiting the product owner's own confirmation.

## 15. Documentation Updated

- `docs/CURRENT_STATE.md` — Phase 25 marked complete; "Next planned phase" now Phase 26; new Completed-list entry; Pending/Not Started, Known Blockers/Issues, Repository/Branch Information, Latest Relevant Handoff, and For the Next Session sections all updated.
- `docs/CHANGELOG.md` — new dated entry for this implementation session.
- `docs/02_ARCHITECTURE.md` — §12 (Flutter application organization) and §13 (Authentication) updated to reflect `AuthGate`'s retirement and the router; new §32 (Mobile Application Foundation & Navigation Shell).
- `docs/06_UI_UX_GUIDELINES.md` — Navigation Architecture section marked implemented (not just decided); the Light/dark theme paragraph marked G-01 closed; the top Status line updated.
- `docs/DECISIONS.md` — new **DEC-049** (implementation-time specifics: `go_router` version, `AuthScope` mechanism).
- `docs/testing/UAT_LOG.md` — four new `NOT RUN` scenarios (`UAT-25-01` through `UAT-25-04`).

## 16. Session Environment Note

No Flutter SDK was pre-installed in this session's sandbox (`flutter`/`dart` not on `PATH`, nothing cached on disk). Downloaded and installed the official Flutter **3.47.2** stable archive (Dart 3.13.2) from `storage.googleapis.com/flutter_infra_release/...` — the exact version `CLAUDE.md` §5 already documents as this project's baseline, not an arbitrary "current stable" substitute. Every quality-gate command in §10 above ran for real against this SDK; none were assumed or skipped. `flutter devices` confirmed no Android/iOS toolchain or emulator is available in this sandbox (see §13).

## 17. Recommended Next Step

Per `CLAUDE.md` §8's Stop Discipline, this session does not begin the next phase. Per `docs/ROADMAP.md`'s re-baselined sequence (DEC-048), the next phase is **Phase 26 — Staging Mobile Connectivity & TLS Validation** — not authorized by this session. A reasonable point to also fold in Phase 25's own deferred manual-verification gap (§13) is alongside Phase 26's real-device work, since Phase 26 already requires a physical device/emulator that this session's sandbox lacks.
