# Phase 22 — Security Audit — Handoff

## 1. Phase Identification

- **Phase:** 22 — Security Audit
- **Date:** 2026-09-14
- **Branch:** `claude/eloquent-dijkstra-r1a09v` (branched from `main` at `ada32a6`, Phase 21 merged; pushed to `origin`, not merged, no PR opened)

## 2. Objective

Phase 22 was delivered in two explicitly authorized steps, per the product owner's own two-message direction:

1. **Audit/planning session** (no code changes): a repository inspection and evidence-based security audit against `docs/05_SECURITY_MODEL.md`, producing `docs/phases/V1_PHASE_22_SECURITY_AUDIT.md`.
2. **Implementation session** (this handoff): following explicit product-owner review and authorization of a **narrow** remediation scope (not the full hardening list the audit enumerated) — one required fix (F-01), a configured Sanctum token expiration (F-02), an investigated-and-dropped optional item (F-03), a short list of inexpensive hardening items (F-04/F-05/F-07/F-08/F-10/F-11), and everything else explicitly left deferred.

No unrelated authentication redesign, no refresh-token architecture, no new infrastructure, and no deferred item (most notably the DEC-044 User suspend/reactivate/role-change mutation surface) was pulled into scope.

## 3. Scope Implemented

- **F-01 (required):** unified the externally observable login-failure message across "wrong credentials" and "correct-credentials-but-suspended/inactive-account" on both `AuthController::login` (mobile API) and `LoginForm::login` (Admin Backoffice).
- **F-02:** configured Sanctum personal-access-token expiration to 43,200 minutes (30 days) via `env('SANCTUM_EXPIRATION', 43200)`.
- **F-03:** investigated and confirmed the audit's own optional, lowest-priority item was unnecessary — no code change.
- **Hardening:** `permissions: contents: read` on both GitHub Actions workflows; `composer audit --locked` added as a standing quality-gate/CI command; `apps/api/.gitignore`'s env-file pattern generalized; the `local` filesystem disk's dormant `serve` route disabled; a README reminder about `APP_DEBUG` in production; a new, conservative `.github/dependabot.yml`.
- **Documentation:** `docs/05_SECURITY_MODEL.md`, `docs/DECISIONS.md` (DEC-045), `docs/phases/V1_PHASE_22_SECURITY_AUDIT.md` (post-implementation remediation-status addendum), `docs/CURRENT_STATE.md`, `docs/ROADMAP.md`, `docs/CHANGELOG.md`, `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`, `CLAUDE.md` (§5, new `composer audit` command), `README.md`.

Not implemented, per explicit authorization: the DEC-044 User account-management mutation surface, `config/cors.php`, Sanctum ability/token scoping beyond the time-bound expiration above, any refresh-token architecture, new infrastructure, SIEM/monitoring platforms, microservices, Kubernetes, unrelated dependency upgrades, or speculative enterprise hardening.

## 4. Implementation Summary

**F-01 — login disclosure fix.** Both `AuthController::login` and `LoginForm::login` previously threw a distinct `ValidationException` message ("This account is not currently active. Contact an administrator.") when a submitted email/password matched a real, correctly-authenticated account that was `suspended`/`inactive`, versus a generic "these credentials do not match our records" message for a wrong password or unknown email. The distinct message itself confirmed account existence and current employment/access status to anyone holding valid credentials for the account — contradicting `docs/05_SECURITY_MODEL.md`'s own stated guarantee. The fix keeps the two failure branches structurally separate (preserving PHPStan-friendly null-narrowing and matching the pre-existing code shape exactly) but changes the second branch's externally-thrown message to be byte-identical to the first's. `User::isActive()`/`EnsureAccountIsActive` enforcement is completely unchanged — a suspended/inactive account still never receives a token/session; only the HTTP-visible text changed. The specific reason (`invalid_credentials` vs. `inactive_account`) is preserved internally as a curated `reason` value passed to the existing `AuditLogger::recordForRequest()`/`record()` call for the `auth.login_failed` event (Administrator-only via the existing Audit Log, Phase 21/DEC-044) — this is an additive, allowlisted value on an already-audited event, not a new audit capability or a widening of what's captured.

**F-02 — Sanctum token expiration.** `config/sanctum.php`'s `expiration` key changed from a hardcoded `null` (tokens never expire) to `(int) env('SANCTUM_EXPIRATION', 43200)` — the exact env-driven pattern this same config file already uses for `SANCTUM_STATEFUL_DOMAINS`/`SANCTUM_TOKEN_PREFIX`, so no new configuration convention was introduced. 43,200 minutes is exactly 30 days, the duration the product owner explicitly specified. This activates Sanctum's own built-in expiration check (`Laravel\Sanctum\Guard::isValidAccessToken()`, which compares a token's `created_at` against `now()->subMinutes($expiration)`) — no application code change was needed beyond the config value, and no refresh-token endpoint, rotation logic, or custom token model was introduced. Neither `.env.example` nor `.env.docker.example` was changed — following this same config file's existing convention of only documenting a Sanctum-related env var when a *local* override is actually needed (`SANCTUM_STATEFUL_DOMAINS`/`SANCTUM_TOKEN_PREFIX` are likewise undocumented there), and 30 days is a perfectly usable default for local development too.

**F-03 — investigated and dropped.** The audit's own §8 item 8 (lowest priority, explicitly optional) suggested a defense-in-depth controller-level check inside `WorkLogController::update()`/`destroy()`, since every sibling module (Tasks, Projects, Service/Incident Reports) re-verifies authority in-controller in addition to route middleware, while Work Logs relies on route middleware alone. Direct inspection of `database/seeders/RolePermissionSeeder.php` confirms `work-logs.manage` is never synced to the Manager or Staff role (only `work-logs.view` is, to Manager) — so `can:work-logs.manage` route middleware is satisfied *only* via the centralized `Gate::before` Administrator override (`AppServiceProvider::boot()`), exactly as intended. `store()`, `update()`, and `destroy()` on `/api/v1/work-logs` are all wrapped in that middleware (`routes/api/v1.php`). The audit's observation (a single line of defense rather than two) was accurate, but it did not describe an actual authorization gap — per the explicit instruction against duplicating authorization for cosmetic redundancy, no code was added.

**Hardening items.** Each maps to exactly one audited finding (see the audit document's Post-Implementation Remediation Status table, §14, for the full one-to-one mapping) and required no new dependency, schema change, or infrastructure:
- `.github/workflows/backend-ci.yml`/`mobile-ci.yml`: added a workflow-level `permissions: contents: read` block (F-04).
- `backend-ci.yml`: added a `composer audit --locked` step immediately after `composer validate --strict`; `CLAUDE.md` §5's authoritative command list updated to match exactly (F-10) — CI and the documented local-validation sequence must never drift apart per CLAUDE.md's own rule.
- `apps/api/.gitignore`: replaced three explicitly named `.env.backup`/`.env.production` entries with a `.env.*` wildcard plus `!.env.example`/`!.env.docker.example` negations — verified with `git check-ignore` that the two tracked example files remain tracked while any future `.env.local`/`.env.staging`/etc. is automatically ignored (F-05).
- `apps/api/config/filesystems.php`: the `local` disk's `'serve'` key set to `false` — this is the attachments disk (`config/attachments.php`), and no code anywhere calls `Storage::url()`/`temporaryUrl()` for it (confirmed by the original audit's repo-wide grep), so the framework-registered signed-URL route for this disk is now disabled outright rather than left dormant (F-07).
- `README.md`: a one-line callout after the direct-install setup instructions that a real deployment's `.env` must explicitly set `APP_DEBUG=false` — `config/app.php`'s code-level default (`env('APP_DEBUG', false)`) was already safe; this is a documentation safeguard against copying `.env.example`'s dev-convenience `APP_DEBUG=true` verbatim into a real deployment (F-08).
- `.github/dependabot.yml` (new): three ecosystems (`composer` for `apps/api`, `pub` for `apps/mobile`, `github-actions` for `.github/workflows`), weekly schedule, a 5-PR-per-ecosystem open cap, no auto-merge configuration of any kind (F-11, partial — `CODEOWNERS`/`SECURITY.md` remain deferred as organizational decisions, not application-security defects).

## 5. Files Changed

**New:**
- `apps/api/tests/Feature/Api/V1/Auth/TokenExpirationTest.php`
- `.github/dependabot.yml`
- `docs/handoffs/V1_PHASE_22_HANDOFF.md` (this file)

**Modified (application code):**
- `apps/api/app/Http/Controllers/Api/V1/Auth/AuthController.php` — F-01
- `apps/api/app/Livewire/Auth/LoginForm.php` — F-01
- `apps/api/config/sanctum.php` — F-02
- `apps/api/config/filesystems.php` — F-07

**Modified (tests):**
- `apps/api/tests/Feature/Api/V1/Auth/LoginTest.php` — F-01 regression tests
- `apps/api/tests/Feature/Auth/AdminLoginTest.php` — F-01 regression tests

**Modified (config/CI/repo hygiene):**
- `.github/workflows/backend-ci.yml` — F-04, F-10
- `.github/workflows/mobile-ci.yml` — F-04
- `apps/api/.gitignore` — F-05
- `README.md` — F-08
- `CLAUDE.md` — F-10 (authoritative command list)

**Documentation:**
- `docs/phases/V1_PHASE_22_SECURITY_AUDIT.md` (post-implementation remediation-status addendum)
- `docs/05_SECURITY_MODEL.md`
- `docs/DECISIONS.md` (DEC-045)
- `docs/CURRENT_STATE.md`
- `docs/ROADMAP.md`
- `docs/CHANGELOG.md`
- `docs/testing/TEST_STATUS.md`
- `docs/testing/UAT_LOG.md`

No existing controller's authorization/visibility logic, no migration, and no model schema was changed beyond the four application-code files listed above — every other diff is test, configuration, CI, or documentation.

## 6. Database/Schema Changes

None. No migration was added or modified.

## 7. API Changes

No endpoint's URL, request shape, or success response shape changed. The only externally observable behavior changes are: (1) `POST /api/v1/auth/login` and the Admin login form now return the identical error message for a suspended/inactive account as for a wrong password (previously distinguishable); (2) a mobile Sanctum bearer token now stops authenticating 30 days after issuance (previously never).

## 8. Authorization/Security Changes

- No permission, role, or `Gate::before` behavior changed.
- No row-level visibility rule (any of the eleven documented shapes in `05_SECURITY_MODEL.md`) was modified.
- `EnsureAccountIsActive` and `User::isActive()` are unchanged — suspended/inactive accounts still lose all access identically to before F-01; only the login *message* changed, not enforcement.
- `AuditLogger`'s redaction discipline (Phase 21/DEC-044) was extended by exactly one additive, curated `reason` field on the pre-existing `auth.login_failed` event — no new event type, no new audited surface, no change to what is captured for any other event.

## 9. Tests Added or Changed

- **`tests/Feature/Api/V1/Auth/LoginTest.php`** — 3 new tests: `test_suspended_account_with_correct_password_gets_the_same_response_as_wrong_password`, `test_inactive_account_with_correct_password_gets_the_same_response_as_wrong_password`, `test_suspended_account_never_receives_a_token_regardless_of_message_unification`.
- **`tests/Feature/Auth/AdminLoginTest.php`** — 3 new tests: `test_suspended_admin_account_gets_the_same_error_message_as_wrong_password`, `test_inactive_admin_account_gets_the_same_error_message_as_wrong_password`, `test_suspended_admin_account_never_gains_a_session_regardless_of_message_unification`. (Also fixed two pre-existing bugs in the two message-comparison tests: Livewire's testable `->errors()` method takes no arguments and returns the whole `MessageBag` — the tests originally called `->errors('email')[0]`, which throws; corrected to `->errors()->first('email')`.)
- **`tests/Feature/Api/V1/Auth/TokenExpirationTest.php`** (new file) — 6 tests: configured-value assertion, fresh-token success, just-under-expiration success, past-expiration rejection (`401`), logout-still-revokes-regardless-of-expiration, suspended-account-still-blocked-with-an-unexpired-token. All use Laravel's `travel()`/`travelTo()` test helpers (Carbon test-now, automatically reset after each test via the framework's own `InteractsWithTestCaseLifecycle`) rather than real-time waiting, per the explicit instruction to avoid brittle timing-based tests.
- One testing-artifact fix discovered while writing the logout-then-recheck test: `Illuminate\Auth\RequestGuard::user()` caches its resolved user for the guard instance's lifetime, and the same `AuthManager` singleton persists across multiple HTTP calls within one test method — so a second `getJson()` call after logout would otherwise still see the pre-logout cached user. Fixed with `$this->app['auth']->forgetGuards();` between the two calls, mirroring `AdminLoginTest::test_suspended_account_loses_access_to_the_home_placeholder_mid_session`'s identical, already-documented precedent for the `web` `SessionGuard`. This is a testing artifact only, not a production behavior (a real request always gets a freshly resolved guard).
- **12 new tests total.** Full suite: 1,080/1,080 passing (1,068 Phase 1–21 baseline + 12 new).

## 10. Commands/Checks Executed

```
composer validate --strict
composer audit --locked
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test --filter=Auth
php artisan test
```

## 11. Results

- `composer validate --strict` — `./composer.json is valid`.
- `composer audit --locked` — `No security vulnerability advisories found.` (run against all 114 locked PHP packages; also independently confirmed during the original audit session).
- `vendor/bin/pint --test` — `{"tool":"pint","result":"passed"}`.
- `vendor/bin/phpstan analyse` — `{"tool":"phpstan","result":"passed","errors":0}` at level 5.
- `php artisan test --filter=Auth` — `{"tool":"phpunit","result":"passed","tests":187,"passed":187,"assertions":517}`.
- `php artisan test` (full suite) — `{"tool":"phpunit","result":"passed","tests":1080,"passed":1080,"assertions":2918}` — full Phase 1–21 regression (1,068 tests) unaffected.

## 12. Deviations from Specification

- The audit's own recommended remediation for F-01 (§7) suggested preserving the specific failure reason "only in the `AuditLogger` entry" — implemented exactly as an additive `reason` field, not a structural change to the Audit Log schema.
- No deviation from the authorized scope occurred in either direction: nothing in F-04/F-05/F-07/F-08/F-10/F-11 was skipped, and no deferred item was implemented. F-03 was investigated as instructed and dropped per the explicit "if confirmed sufficient, omit" branch of the authorization — this is a followed instruction, not a deviation.
- `CODEOWNERS` and `.github/SECURITY.md` (part of the original F-11 finding) were **not** added — the authorization named "the proposed Dependabot configuration" specifically for F-11, and the audit document itself already classified `CODEOWNERS`/`SECURITY.md` as organizational/product-owner decisions rather than application-security defects. Only `dependabot.yml` was added.

## 13. Known Issues/Limitations

- No new limitation was introduced by this phase's changes.
- Pre-existing, unaffected limitations carried forward unchanged: no User suspend/reactivate/role-change mutation surface (DEC-044's Known Limitation, explicitly not addressed here); no `config/cors.php` (no browser client exists to need one); Sanctum token ability scoping remains undefined (only expiration was added); no antivirus/malware scanning for attachments (documented future consideration since Phase 18); no retention/purge policy for check-ins or audit logs (documented future consideration since Phase 9/21).
- `SANCTUM_EXPIRATION` is not documented in `.env.example`/`.env.docker.example`, matching this config file's existing convention for `SANCTUM_STATEFUL_DOMAINS`/`SANCTUM_TOKEN_PREFIX` (undocumented there, relying on the code-level default) — a future session should add it explicitly if a non-default value is ever needed in a real deployment.

## 14. Manual/UAT Testing Instructions

See `docs/testing/UAT_LOG.md` (`UAT-22-01`, `UAT-22-02`) — this phase changed API/backend behavior only (no Admin Backoffice UI or Flutter mobile screens exist for these endpoints beyond the existing login screens). `UAT-22-01` asks the product owner to eyeball-confirm the two login failure messages are indistinguishable in the real login form, beyond what the automated tests already assert. `UAT-22-02` asks the product owner to confirm the chosen 30-day token expiration duration is an acceptable real-world experience for staff — this was their own explicit decision (DEC-045), so this UAT item is a confirmation, not a validation of unknown behavior.

## 15. Documentation Updated

`docs/phases/V1_PHASE_22_SECURITY_AUDIT.md` (remediation-status addendum), `docs/05_SECURITY_MODEL.md`, `docs/DECISIONS.md` (DEC-045), `docs/CURRENT_STATE.md`, `docs/ROADMAP.md`, `docs/CHANGELOG.md`, `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`, `CLAUDE.md` (§5), `README.md`, this handoff.

## 16. Recommended Next Step

Per `docs/ROADMAP.md`, **Phase 23 — Mobile UI/UX Audit** (review against `docs/06_UI_UX_GUIDELINES.md`) is next. This is a recommendation only — per CLAUDE.md §8 Stop Discipline, Phase 23 must not begin without explicit product-owner authorization, and this session has not begun it. Separately, the audit's deferred items (§9 of `docs/phases/V1_PHASE_22_SECURITY_AUDIT.md`) — most notably a User account-management mutation surface — remain candidates for a future, explicitly-scoped phase whenever the product owner decides to authorize one; this handoff does not recommend starting one implicitly.
