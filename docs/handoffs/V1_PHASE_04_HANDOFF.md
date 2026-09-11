# Phase 4 Handoff — Authentication

## 1. Phase Identification

- **Phase:** 4 — Authentication
- **Date:** 2026-09-11
- **Branch:** `claude/v1-phase-04-authentication-grt1ca` (from `main` @ `38c15c4`, which contains the approved Phase 1–3 content)

## 2. Objective

Establish the first real security/business functionality: who a user is, how they prove it (Admin session/cookie auth, mobile Sanctum bearer tokens), account state (active/suspended/inactive) enforced centrally, and the minimum Admin-access distinction needed — without building Phase 5's RBAC or any other future module.

## 3. Scope Implemented

Everything listed in `docs/phases/V1_PHASE_04_DEFINITION.md`'s "In Scope" — schema, Sanctum, Admin login/logout/placeholder, mobile `/auth/login|logout|me`, central account-status middleware, rate limiting, the full Flutter auth flow with secure token storage, a local-only admin seeder, and automated tests on both sides. Full detail below.

**Explicitly not introduced:** RBAC/permissions, the Staff domain table, password reset, email verification workflow, or any other business module — see `docs/phases/V1_PHASE_04_DEFINITION.md`'s "Explicitly Out of Scope" for the complete list.

## 4. Repository Recovery Verification

Read, in order: `CLAUDE.md`, `docs/CURRENT_STATE.md`, `docs/ROADMAP.md`, `docs/handoffs/V1_PHASE_03_HANDOFF.md`, `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/06_UI_UX_GUIDELINES.md`, `docs/DECISIONS.md`, and the existing `apps/api`/`apps/mobile` code (models, migrations, config, routes, Flutter `lib/` structure).

Verified `origin/main` @ `38c15c4` (merge commit for PR #3) actually contains the claimed Phase 3 artifacts before branching: `GET /api/v1/health` route and controller, `docs/handoffs/V1_PHASE_03_HANDOFF.md`, DEC-016 through DEC-021 in `docs/DECISIONS.md`, `livewire/livewire` in `composer.json`, and the Flutter `lib/app/`, `lib/core/config/`, `lib/features/home/` structure. **No material contradiction found** — proceeded to branch and implement.

## 5. Authentication Architecture

- **Admin Backoffice:** Laravel's conventional session/secure-cookie authentication (`web` guard). No bearer tokens for browser sessions.
- **Mobile API:** Laravel Sanctum personal access tokens, `Authorization: Bearer <token>`. No cookie-based SPA/stateful authentication, no OAuth server, no JWT infrastructure, no Passport, no invented refresh-token mechanism.
- Recorded as DEC-022 (mechanisms), DEC-023 (no registration), DEC-024 (transitional Admin-access flag), DEC-025 (Flutter state management), DEC-026 (Flutter token storage) in `docs/DECISIONS.md`.

## 6. User/Account Schema Changes

New migration `2026_09_11_000001_add_authentication_fields_to_users_table.php`, applied to the existing `users` table:

- `public_id` — `ulid`, nullable, unique. Nullable (not not-null) specifically to avoid a backfill/DBAL column-modify step; `User::booted()` always assigns one via `Str::ulid()` on creation, so it's never actually null for any row created through the app.
- `status` — `string`, default `'active'`. A plain string (not a native MySQL `ENUM`) for MySQL/SQLite portability; the valid-value set is owned by `App\Enums\AccountStatus` (`Active`/`Suspended`/`Inactive`), cast on the model.
- `is_admin` — `boolean`, default `false`. Deliberately excluded from `User`'s `#[Fillable(...)]` list — never settable via mass assignment/user input.

Sanctum's own migration was published as-is: `2026_09_11_001733_create_personal_access_tokens_table.php` (`php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"`).

`App\Models\User` changes: `HasApiTokens` trait added; `casts()` maps `status` to `AccountStatus::class` and `is_admin` to `boolean`; a `@property AccountStatus $status` PHPDoc was added (needed for Larastan to correctly type-infer the enum cast — see §20); `booted()` assigns `public_id` on creation; a new `isActive(): bool` method centralizes the account-state check (`$this->status->canAuthenticate()`).

## 7. Admin Authentication

- `App\Livewire\Auth\LoginForm` (class-based Livewire 4 component, generated via `php artisan make:livewire Auth/LoginForm --class`) — `#[Validate]` on `email`/`password`, `Auth::attempt()`, then explicit `isActive()` and `is_admin` checks (each failure calls `Auth::logout()` and throws a `ValidationException` with a generic-but-honest message), then `session()->regenerate()` and a redirect to `route('home')`.
- Rate limiting is enforced **inside the component**, not via route middleware: Livewire's actual form submission is an internal AJAX POST to its own `/livewire*/update` endpoint, not a direct hit on the `/login` route, so a `throttle` route middleware on `GET /login` would never see login attempts. `RateLimiter::tooManyAttempts`/`hit`/`clear`, keyed by `email|ip`, 5/minute.
- Views: `resources/views/components/layouts/app.blade.php` (a plain anonymous Blade layout — no Tailwind/Vite build required), `resources/views/livewire/auth/login-form.blade.php`, `resources/views/auth/login.blade.php` (the page wrapping `@livewire('auth.login-form')`), `resources/views/home.blade.php` (the protected placeholder, with a logout form).
- Routes (`routes/web.php`): `GET /login` (name `login`, `guest` middleware), `GET /home` (name `home`, `auth` + `account.active` middleware), `POST /logout` (name `logout`, `auth` middleware only — deliberately **not** gated by `account.active`, since revoking one's own session must always be possible).

## 8. Mobile API Authentication

`App\Http\Controllers\Api\V1\Auth\AuthController` under `routes/api/v1.php`'s new `auth.` route group:

- `POST /api/v1/auth/login` (public, `throttle:login`) — `App\Http\Requests\Auth\ApiLoginRequest` validates `email`/`password`; looks up the user, `Hash::check`s the password, and if either fails throws the **same** generic `ValidationException` message regardless of which failed (never discloses whether an email is registered); then checks `isActive()`; on success, `$user->createToken('mobile')->plainTextToken` plus `App\Http\Resources\UserResource`.
- `POST /api/v1/auth/logout` (`auth:sanctum`) — `$request->user()->currentAccessToken()->delete()`, revoking only the token used for this request.
- `GET /api/v1/auth/me` (`auth:sanctum` + `account.active`) — returns `UserResource` for the authenticated user.
- `UserResource` exposes exactly `public_id`, `name`, `email`, `status` — never the internal numeric id, password hash, remember token, or `is_admin`.

## 9. Sanctum Configuration

`laravel/sanctum: ^4.0` (resolved v4.3.3). Config published (`config/sanctum.php`) and left at defaults — `'guard' => ['web']` is Sanctum's own default for its internal guard-check order (see §20 for a related PHPStan-surfaced edge case) and doesn't change anything: Flutter never sends session cookies, so it always resolves via the bearer-token path. No stateful-domain configuration changes were made — Flutter is bearer-token-only, never a cookie-based SPA client, per CLAUDE.md §13.

## 10. Flutter Authentication Architecture

`lib/features/auth/`:

- `domain/auth_user.dart` — `AuthUser` (`publicId`, `name`, `email`, `status`), matching `UserResource`'s shape.
- `data/auth_api_client.dart` — `AuthApiClient` wraps an injectable `http.Client` (defaults to a real one; tests inject `package:http/testing.dart`'s `MockClient`) for `login`/`me`/`logout`; extracts a user-safe message from either Laravel's validation-error shape or its generic `message` field; wraps genuine network failures (any thrown `Exception`) in a friendly `AuthApiException`.
- `data/token_storage.dart` — `TokenStorage` interface + `SecureTokenStorage` (real) implementation.
- `state/auth_controller.dart` — `AuthController extends ChangeNotifier`, `AuthStatus` enum (`unknown`/`authenticating`/`authenticated`/`unauthenticated`), `bootstrap()`/`login()`/`logout()`.
- `presentation/login_page.dart`, `presentation/auth_gate.dart`.

`lib/app/app.dart`'s `CompanyApp` became a `StatefulWidget` that owns one `AuthController` for the app's lifetime (constructed with the real `AuthApiClient`/`SecureTokenStorage`, or injected for tests) and renders `AuthGate` as `MaterialApp.home`. `lib/features/home/home_page.dart` gained optional `userName`/`onLogout` parameters so the existing Phase 3 placeholder is reused, not replaced, as the authenticated destination (per the governing instruction's item 17).

## 11. Secure-Storage Approach

`flutter_secure_storage` (^11.1.0) — iOS Keychain, Android EncryptedSharedPreferences/Keystore. `TokenStorage` is an interface specifically so tests never touch a real platform channel (`FakeTokenStorage`, in-memory, in `test/features/auth/fake_token_storage.dart`). No plain `SharedPreferences`, no hard-coded token, no unencrypted file.

## 12. State-Management Decision

Plain `ChangeNotifier` (`AuthController`) + `ListenableBuilder` (`AuthGate`) — no Provider/Riverpod/Bloc/GetX. This is the first phase with a genuine shared-state need (DEC-021 explicitly deferred this decision to here); one piece of app-wide state doesn't justify a DI/state-management framework at this project's scale. See DEC-025.

## 13. Routes/Endpoints

**Web:**
```
GET|HEAD  login    (guest)
GET|HEAD  home     (auth, account.active)
POST      logout   (auth)
```

**API (`/api/v1`):**
```
POST      auth/login   (throttle:login)
POST      auth/logout  (auth:sanctum)
GET|HEAD  auth/me       (auth:sanctum, account.active)
```

Confirmed via `php artisan route:list`.

## 14. Rate Limiting

One named limiter, `login` (`App\Providers\AppServiceProvider::boot()`), 5 attempts/minute keyed by `strtolower(email)|ip`. Applied to the API route via `throttle:login` middleware; enforced directly inside `LoginForm::login()` for the Admin surface (see §7 for why route middleware doesn't work there). Exceeding it returns `429` (API) or a validation-style error (Admin).

## 15. Security Considerations

- Passwords: Laravel's default hashing (`'password' => 'hashed'` cast, bcrypt) — never rolled by hand, never logged, never returned.
- Login failures (wrong password vs. unknown email) return the identical generic message on both surfaces — no email-enumeration signal.
- Suspended/inactive status is checked at login (both surfaces) **and** on already-authenticated access via one central `EnsureAccountIsActive` middleware — not scattered per-controller checks (CLAUDE.md §7). For the API this revokes the current token (with a `try`/`catch` around the revoke call, since a session-authenticated request wrapped by Sanctum's guard as a `TransientToken` has no `delete()` method to call — a real edge case Larastan's static analysis surfaced, see §20); for the Admin session it logs out and redirects to `/login`.
- `is_admin` is never mass-assignable (excluded from `User`'s fillable list); `AdminUserSeeder` sets it via direct property assignment, not `update()`/`create()`.
- No public registration route exists on either surface; confirmed by a test hitting `POST /register` and `POST /api/v1/auth/register` and expecting `404`.
- `AdminUserSeeder` refuses to run outside `local`/`testing` environments and uses an unmistakably non-production password (`password`).

## 16. Files Changed

**Added (backend):** `app/Enums/AccountStatus.php`, `app/Http/Controllers/Api/V1/Auth/AuthController.php`, `app/Http/Middleware/EnsureAccountIsActive.php`, `app/Http/Requests/Auth/ApiLoginRequest.php`, `app/Http/Resources/UserResource.php`, `app/Livewire/Auth/LoginForm.php`, `config/livewire.php`, `config/sanctum.php`, `database/migrations/2026_09_11_000001_add_authentication_fields_to_users_table.php`, `database/migrations/2026_09_11_001733_create_personal_access_tokens_table.php`, `database/seeders/AdminUserSeeder.php`, `resources/views/auth/login.blade.php`, `resources/views/components/layouts/app.blade.php`, `resources/views/home.blade.php`, `resources/views/livewire/auth/login-form.blade.php`, `tests/Feature/Api/V1/Auth/LoginTest.php`, `tests/Feature/Api/V1/Auth/MeAndLogoutTest.php`, `tests/Feature/Auth/AdminLoginTest.php`.

**Modified (backend):** `app/Models/User.php`, `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`, `composer.json`/`composer.lock`, `database/factories/UserFactory.php`, `routes/api/v1.php`, `routes/web.php`.

**Added (mobile):** `lib/features/auth/**` (domain/data/state/presentation, listed in §10), `test/features/auth/*.dart` (fake_token_storage, auth_controller_test, login_page_test, auth_gate_test).

**Modified (mobile):** `lib/app/app.dart`, `lib/features/home/home_page.dart`, `pubspec.yaml`/`pubspec.lock`, `test/widget_test.dart`.

**Added (docs):** `docs/phases/V1_PHASE_04_DEFINITION.md`, `docs/handoffs/V1_PHASE_04_HANDOFF.md` (this file).

**Modified (docs):** `README.md`, `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/06_UI_UX_GUIDELINES.md`, `docs/CHANGELOG.md`, `docs/CURRENT_STATE.md`, `docs/DECISIONS.md`, `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`.

## 17. Dependencies Introduced

- **Backend:** `laravel/sanctum: ^4.0` (production dependency, resolves to v4.3.3).
- **Mobile:** `http: ^1.6.0`, `flutter_secure_storage: ^11.1.0` (both production dependencies).

## 18. Migrations

- `2026_09_11_000001_add_authentication_fields_to_users_table.php` — adds `public_id`, `status`, `is_admin` to `users`.
- `2026_09_11_001733_create_personal_access_tokens_table.php` — Sanctum's own, published unmodified.

Both are plain-string/boolean/ulid column types with portable defaults — no native MySQL-only constructs — consistent with the production-MySQL, test-SQLite split (`02_ARCHITECTURE.md` §12).

## 19. Tests Added/Changed

**Backend (PHPUnit), 23 new tests, 31 total, all passing:**
- `tests/Feature/Auth/AdminLoginTest.php` (12 tests) — login page accessible to guests; authenticated guest-route redirect; valid login; session-ID regeneration; invalid credentials; non-admin rejected; suspended/inactive rejected; rate limiting; logout; unauthenticated `/home` redirect; mid-session suspension revokes access; authenticated view renders.
- `tests/Feature/Api/V1/Auth/LoginTest.php` (7 tests) — valid login returns token+safe shape; invalid password; unknown email (same message); suspended; inactive; required-field validation; rate limiting; no public registration.
- `tests/Feature/Api/V1/Auth/MeAndLogoutTest.php` (6 tests) — authenticated `/me`; unauthenticated `/me`; logout revokes current token; revoked token rejected; logout doesn't revoke other devices' tokens; mid-session suspension revokes API access.
- `database/factories/UserFactory.php` — added `admin()`, `suspended()`, `inactive()` states.

**Mobile (`flutter_test`), 16 new tests, 17 total, all passing, network/storage faked (`http`'s `MockClient`, an in-memory `FakeTokenStorage` — never a live server):**
- `test/features/auth/auth_controller_test.dart` (8) — bootstrap with no/valid/invalid stored token; login success/invalid-credentials/network-failure; logout success and logout-despite-server-failure.
- `test/features/auth/login_page_test.dart` (3) — empty-form validation; loading indicator while in flight; server error message displayed.
- `test/features/auth/auth_gate_test.dart` (5) — loading state; no-token shows login; valid-token restores to home; successful login transitions to home; logout returns to login.
- `test/widget_test.dart` — rewritten (the old test asserted the pre-auth bootstrap-shell text unconditionally); now asserts an unauthenticated boot shows the login screen, via an injected fake controller.

## 20. Commands Actually Executed

**Backend:**
| Command | Result |
|---|---|
| `composer require laravel/sanctum:^4.0` | Succeeded in resolving/locking the dependency; the actual `vendor/` download step failed mid-run (see below) |
| `composer validate --strict` | `./composer.json is valid` |
| `vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` |
| `vendor/bin/phpstan analyse` | `{"tool":"phpstan","result":"passed","errors":0}` |
| `php artisan test` | `{"tool":"phpunit","result":"passed","tests":31,"passed":31,"assertions":96}` |
| `php artisan migrate:fresh` | All 5 migrations ran cleanly against SQLite |
| `php artisan route:list` | Confirmed all routes in §13 |
| Manual `php artisan serve` + `curl` cycle | Login → token; wrong password → 422 generic message; `/me` with/without token → 200/401; logout → 200; `/me` after logout → 401 (see §27) |

**Mobile:**
| Command | Result |
|---|---|
| `flutter pub add http flutter_secure_storage` | Both added, `pubspec.yaml`/`pubspec.lock` updated |
| `dart format --output=none --set-exit-if-changed .` | Exit 0, no changes needed (after one formatting pass) |
| `flutter analyze` | `No issues found!` |
| `flutter test` | `+17: All tests passed!` |

## 21. Exact Results

See §20 above and `docs/testing/TEST_STATUS.md`'s Phase 4 section for the full checklist.

## 22. GitHub CI Status

A draft pull request ([#5](https://github.com/jaaan44/company-app/pull/5), `claude/v1-phase-04-authentication-grt1ca` → `main`, not merged) was opened to exercise the `pull_request` trigger, matching the Phase 2/3 approach.

**Both workflows ran and passed on commit `7eb3717`:**

| Workflow | Job | Result | Duration | Run |
|---|---|---|---|---|
| Backend CI | Backend quality gates (PHP 8.4) | ✅ success | ~18s | [run 34548321478](https://github.com/jaaan44/company-app/actions/runs/34548321478) |
| Mobile CI | Mobile quality gates (Flutter 3.47.2) | ✅ success | ~49s | [run 34548321404](https://github.com/jaaan44/company-app/actions/runs/34548321404) |

This confirms, independently of this session's local recovery work (§23), that `composer.json`/`composer.lock` and `pubspec.yaml`/`pubspec.lock` are correctly resolved and that every dependency (including `laravel/sanctum`, `phpstan/phpstan`, `larastan/larastan`, `http`, `flutter_secure_storage`) installs cleanly on GitHub's unrestricted-network runners.

**All of AC-01 through AC-20 are satisfied**, including AC-16 through AC-19 (backend/Flutter tests, static analysis/formatting, and GitHub Actions all passing) via this confirmed run.

## 23. Deviations

- **Environment, not specification:** this sandboxed session had neither a working `vendor/` for one `composer require` mid-run (see below) nor a preinstalled Flutter SDK at all — both were fully recovered/installed within the session (not worked around by skipping checks), so no quality gate was actually skipped. Documented in detail because it's a materially different situation from Phases 2–3's narrower, accepted PHPStan-only limitation.
  - `composer require laravel/sanctum` correctly resolved and locked the dependency, but the subsequent package-download step hit this sandbox's restricted `api.github.com` access (the same root cause as Phases 2–3's PHPStan limitation) and, more severely this time, left the entire `vendor/` directory empty and `vendor/composer/installed.json` missing before failing. Recovered by: (1) using this sandbox's working plain `git clone` access (not blocked, unlike Composer's API-based dist downloads) to restore all 113 recoverable packages from `composer.lock`'s exact locked commits via this session's local Composer VCS mirror cache; (2) temporarily removing `phpstan/phpstan`/`larastan/larastan` from `composer.json` and running a real `composer update --prefer-source` to regenerate valid Composer metadata (`installed.json`, autoload files) for everything else from those local mirrors, entirely offline; (3) restoring the two dev packages to `composer.json` and fixing `composer.lock` via `composer update larastan/larastan phpstan/phpstan --no-install` (lock-file-only, no network-dependent download); (4) manually `git clone`-ing `phpstan/phpstan` itself (which has no VCS source in `composer.lock`, unlike the other 113) at its exact locked commit and hand-registering it in `installed.json` before a final `composer dump-autoload`. `composer.json`/`composer.lock` are correctly resolved throughout and were never the issue — this was purely a local `vendor/` recovery exercise.
  - Flutter was not present in this sandbox at all (a difference from whatever environment ran Phases 1–3's sessions). Cloned `flutter/flutter` at tag `3.47.2` (matching the project's pinned version exactly) directly from GitHub via `git clone` and bootstrapped it (`flutter --version` triggered its own Dart-SDK/tool download, which succeeded).
  - Net effect: **every quality gate in CLAUDE.md §5, including `vendor/bin/phpstan analyse`, actually ran and passed in this session** — a stronger local verification position than Phases 2–3, not a weaker one. GitHub Actions remains the authoritative confirmation regardless (§22).
- PHPStan's run surfaced two genuine, fixed issues before it passed (not deviations from the spec, but worth recording): a redundant `method_exists()` check in `EnsureAccountIsActive` that didn't actually protect against the real edge case (a session-authenticated request wrapped by Sanctum as a `TransientToken`, which has no `delete()` method) — replaced with a `try`/`catch` around the revoke call; and a misleading `@return array<string, string>` PHPDoc on `User::casts()` that caused both Larastan and, transitively, `UserResource`/`isActive()` to mistype `status` as `string` — removed, plus an explicit `@property AccountStatus $status` added for clarity.
- No other deviations from `docs/phases/V1_PHASE_04_DEFINITION.md`.

## 24. Known Issues/Open Decisions

- Password reset and email verification are explicitly deferred (not required by this phase's specification) — `email_verified_at` remains on `users`, unused.
- `is_admin` is a deliberately temporary mechanism; Phase 5 must replace it, not build alongside it.
- Sanctum token abilities (scoping) are not used — there's currently only one API client type (the Flutter app), so this hasn't been needed yet; revisit if a second API-consuming client type appears.
- Real-time transport, object storage provider, and Departments/Teams hierarchy shape remain open (`02_ARCHITECTURE.md` §9) — untouched by this phase.

## 25. Resource-Efficiency Review

- No OAuth server, JWT infrastructure, Passport, external identity provider, or dedicated authentication service — Sanctum and Laravel's own session guard, both already part of the framework.
- No Redis introduced for authentication — rate limiting uses Laravel's existing cache-backed `RateLimiter` on whatever cache store is already configured (database, per Phase 1's bootstrap).
- Flutter: no state-management package, no routing package — `ChangeNotifier` (SDK-included) and `Navigator`/`MaterialApp.home` remain sufficient. Only two focused packages added (`http`, `flutter_secure_storage`), both directly required by the phase's explicit instructions.
- No microservices, no new always-running services, no build-pipeline addition to the Admin Backoffice (plain Blade views, no Tailwind/Vite required for these screens).

## 26. Documentation Updated

`README.md`, `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/06_UI_UX_GUIDELINES.md`, `docs/CHANGELOG.md`, `docs/CURRENT_STATE.md`, `docs/DECISIONS.md` (DEC-022–DEC-026), `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md` (four `NOT RUN` scenarios logged, none marked `PASS` — that's the product owner's call per CLAUDE.md §7), `docs/phases/V1_PHASE_04_DEFINITION.md` (new), this handoff (new).

## 27. Manual/UAT Testing Instructions

**Backend setup:**
```sh
cd apps/api
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan db:seed --class="Database\Seeders\AdminUserSeeder"   # admin@example.test / password — local only
php artisan serve
```

**Admin Backoffice:** visit `http://localhost:8000/login`, sign in with `admin@example.test` / `password`, confirm redirect to `/home` showing "Signed in as Local Admin", click "Log out", confirm redirect back to `/login`. Visiting `/home` directly while logged out redirects to `/login`.

**Mobile API (curl), matching this session's actual verification:**
```sh
curl -X POST http://localhost:8000/api/v1/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.test","password":"password"}'
# {"data":{"user":{"public_id":"...","name":"Local Admin","email":"admin@example.test","status":"active"},"token":"..."}}

curl http://localhost:8000/api/v1/auth/me -H "Authorization: Bearer <token>"
# 200, same user shape

curl -X POST http://localhost:8000/api/v1/auth/logout -H "Authorization: Bearer <token>"
# 200 {"data":{"message":"Logged out successfully."}}

curl http://localhost:8000/api/v1/auth/me -H "Authorization: Bearer <token>"
# 401 — token revoked
```

**Mobile app:**
```sh
cd apps/mobile
flutter run --dart-define=API_BASE_URL=http://localhost:8000/api/v1
```
Confirm: app opens on the login screen; submitting with a valid seeded account transitions to the placeholder home showing "Signed in as Local Admin"; the logout icon in the app bar returns to the login screen; killing and relaunching the app while logged in restores straight to the home screen (token validated via `/auth/me`); an intentionally wrong password shows an inline error without crashing.

**UAT:** four scenarios logged in `docs/testing/UAT_LOG.md` as `NOT RUN` (UAT-04-01 through UAT-04-04) — ready for the product owner's review; not marked `PASS` by this session per CLAUDE.md §7.

## 28. Recommended Next Phase

**Phase 5 — Roles & Permissions**, per `docs/ROADMAP.md`: final role/permission catalog, role-permission/user-role storage, policy/gate scaffolding, and a reusable permission-checking pattern — which should also retire the transitional `is_admin` flag introduced here (DEC-024).

## Business Functionality Statement

**No functionality outside Authentication was introduced in this phase.** No RBAC/permissions system, no Staff domain table or profile, no Clients/Projects/Tasks/Leave/Messaging/other business module, no password reset, and no email verification workflow. The only "extra" surface touched was the pre-existing `HomePage` placeholder (Phase 3), extended with an optional logout action so it could serve as Phase 4's authenticated destination — not replaced with real dashboard content.

---

*Per CLAUDE.md §8 (Stop Discipline): this phase is complete. Not merged into `main`. Phase 5 is not authorized by this handoff and will not begin without explicit user instruction.*
