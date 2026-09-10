# Phase 3 Handoff — Core Architecture

## 1. Phase Identification

- **Phase:** 3 — Core Architecture
- **Date:** 2026-09-10
- **Branch:** `claude/v1-phase-03-core-architecture` (from `main` @ `1166fc2`, which contains the approved Phase 1 + Phase 2 content)

## 2. Objective

Establish foundational application conventions — API versioning/response shape, identifier strategy, Admin Backoffice direction, production database direction, Laravel/Flutter organization — implemented with minimal real code, for later business modules to build on.

## 3. Scope Implemented

- Production database direction: MySQL (DEC-016).
- Identifier strategy: numeric internal PK + ULID `public_id`, documented (DEC-017).
- Laravel application organization: modular monolith conventions documented (DEC-018).
- Admin Backoffice: Blade + Livewire confirmed, `livewire/livewire` (^4.4) installed (DEC-019). No pages built.
- API foundation: `/api/v1` routing, `GET /api/v1/health` endpoint with tests, response conventions confirmed working (DEC-020).
- Flutter foundation: `lib/app/`, `lib/core/config/`, `lib/features/home/` structure (DEC-021).

Full scope/out-of-scope statement: `docs/phases/V1_PHASE_03_DEFINITION.md`.

## 4. Laravel Architecture Established

- `app/Http/Controllers/Api/V1/` — versioned API controllers namespace (currently: `HealthController`).
- `routes/api.php` → `Route::prefix('v1')->name('api.v1.')->group(base_path('routes/api/v1.php'))` — a future `/api/v2` adds `routes/api/v2.php` and a parallel group, without touching or duplicating v1.
- `bootstrap/app.php` updated to wire `api: __DIR__.'/../routes/api.php'` into `withRouting()` (Laravel's default `apiPrefix` of `api` combines with the `v1` group to produce `/api/v1/...`).
- No new empty directories (Policies/, Actions/, Services/, Jobs/) were created — per DEC-018, structure is added only where a phase genuinely needs it. Conventions for when to use each are documented in `02_ARCHITECTURE.md` §12, not scaffolded as empty folders.

## 5. API Foundation

`GET /api/v1/health` — registered and confirmed via `php artisan route:list --path=api`:
```
GET|HEAD api/v1/health .. api.v1.health › Api\V1\HealthController
```
No authentication middleware (intentionally public — see `05_SECURITY_MODEL.md`). No database connectivity check (optional per spec, skipped to keep it lightweight).

## 6. Health Endpoint

`App\Http\Controllers\Api\V1\HealthController` (invokable) returns:
```json
{ "data": { "status": "ok", "timestamp": "2026-09-10T..." } }
```
No secrets, environment variables, PHP config, database credentials, internal paths, or dependency versions exposed. Two feature tests (`tests/Feature/Api/V1/HealthEndpointTest.php`) assert the 200 status, the JSON structure, and the explicit absence of `env`/`debug`/`database`/`version` keys.

## 7. API Conventions

Confirmed working with real code rather than documentation alone: success responses wrapped in `{"data": ...}` (manually, for this plain-array response); JSON error/validation rendering for `api/*` requests already configured in `bootstrap/app.php` since Phase 1 (`shouldRenderJsonWhen`); no proprietary envelope invented. Full detail: `04_API_CONVENTIONS.md` (updated this phase to mark these as implemented, not just principles).

## 8. Database Decision

MySQL is the production direction (DEC-016). No server was provisioned or configured. SQLite remains the local/test database (`phpunit.xml`, unchanged since Phase 1) — CI was not migrated to a MySQL service, per the instruction to keep CI lightweight and only add MySQL-backed testing when a feature's behavior genuinely can't be faithfully tested against SQLite.

## 9. Identifier Strategy

Documented (DEC-017): numeric `BIGINT` internal PK (`$table->id()`) + `ULID public_id` for externally addressable entities, decided per-entity when each is actually built. No business migrations were created to demonstrate this. `03_DATABASE_MODEL.md` §3 lists likely candidate entities.

## 10. Admin Backoffice Decision/Foundation

Blade + Livewire (DEC-019). `livewire/livewire` ^4.4 added to `apps/api/composer.json` (resolves to v4.4.4). No Livewire component, page, or route was created — per the instruction, installing/documenting the dependency was judged sufficient for this foundational phase; building even a placeholder page risked reading as Admin Backoffice scaffolding beyond what's authorized. See §16 for why local verification of the installed package itself was limited in this session.

## 11. Flutter Architecture

```
lib/
  app/app.dart              — CompanyApp (MaterialApp root + theme)
  core/config/app_config.dart — build-time config (API base URL via --dart-define)
  features/home/home_page.dart — placeholder screen (was BootstrapHomePage in Phase 1)
  main.dart                 — entrypoint only
```

## 12. Flutter Navigation/Routing Approach

Flutter's built-in `Navigator`/`MaterialApp.home` — no routing package added (DEC-021). No state-management framework (Provider/Riverpod/Bloc) added — none is needed until a real feature (starting with Authentication, Phase 4) requires one. Both choices are documented as deliberately deferred, not unresolved by oversight.

## 13. Packages/Dependencies Introduced

- **Backend:** `livewire/livewire: ^4.4` (production dependency, resolves to v4.4.4).
- **Mobile:** none — the restructuring used only existing Flutter/Dart capabilities.

## 14. Files Changed

- Added: `apps/api/app/Http/Controllers/Api/V1/HealthController.php`, `apps/api/routes/api.php`, `apps/api/routes/api/v1.php`, `apps/api/tests/Feature/Api/V1/HealthEndpointTest.php`, `apps/mobile/lib/app/app.dart`, `apps/mobile/lib/core/config/app_config.dart`, `apps/mobile/lib/features/home/home_page.dart`, `docs/phases/V1_PHASE_03_DEFINITION.md`, `docs/handoffs/V1_PHASE_03_HANDOFF.md` (this file).
- Modified: `apps/api/bootstrap/app.php` (wired `api:` routing), `apps/api/composer.json`/`composer.lock` (added Livewire), `apps/mobile/lib/main.dart` (trimmed to entrypoint), `apps/mobile/test/widget_test.dart` (updated import path), `README.md`, `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/CHANGELOG.md`, `docs/CURRENT_STATE.md`, `docs/DECISIONS.md`, `docs/testing/TEST_STATUS.md`.

## 15. Tests Added/Changed

- `tests/Feature/Api/V1/HealthEndpointTest.php` — 2 new tests (status/shape; no sensitive keys).
- `test/widget_test.dart` (Flutter) — import path updated to `package:mobile/app/app.dart`; assertions unchanged (still valid against the restructured code).

## 16. Commands Executed

| Command | Location | Result |
|---|---|---|
| `php artisan route:list --path=api` | `apps/api` | `GET\|HEAD api/v1/health .. api.v1.health` |
| `composer validate --strict` | `apps/api` | `./composer.json is valid` |
| `vendor/bin/pint --test` | `apps/api` | `{"tool":"pint","result":"passed"}` |
| `php artisan test` | `apps/api` | `{"tool":"phpunit","result":"passed","tests":4,"passed":4,"assertions":11}` |
| `vendor/bin/phpstan analyse` | `apps/api` | **Could not run locally** — see §20 |
| `flutter pub get` | `apps/mobile` | Dependencies resolved |
| `dart format --output=none --set-exit-if-changed .` | `apps/mobile` | Exit 0 |
| `flutter analyze` | `apps/mobile` | `No issues found!` |
| `flutter test` | `apps/mobile` | `+1: All tests passed!` |

## 17. Exact Results

See §16 and `docs/testing/TEST_STATUS.md` (Phase 3 section).

## 18. GitHub CI Status

A draft pull request (#3, `claude/v1-phase-03-core-architecture` → `main`, not merged) was opened to exercise the `pull_request` trigger, matching the Phase 2 approach.

**Both workflows ran and passed on commit `6308c4a`:**

| Workflow | Job | Result | Duration | Run |
|---|---|---|---|---|
| Backend CI | Backend quality gates (PHP 8.4) | ✅ success | ~18s | [run 34542479092](https://github.com/jaaan44/company-app/actions/runs/34542479092) |
| Mobile CI | Mobile quality gates (Flutter 3.47.2) | ✅ success | ~44s | [run 34542479141](https://github.com/jaaan44/company-app/actions/runs/34542479141) |

Confirmed from the actual backend job log (not assumed): **`vendor/bin/phpstan analyse` → `[OK] No errors`, 4 files analyzed** (up from 3 in Phase 2 — the new `HealthController`); `php artisan test` → `Tests: 4 passed (11 assertions)`, explicitly showing `Tests\Feature\Api\V1\HealthEndpointTest` passing both new tests. Since the job completed successfully end-to-end (including Pint and PHPStan, both of which require a fully installed `vendor/`), `livewire/livewire` and every other dependency installed cleanly on the unrestricted-network runner — confirming, again, that the local blocker (§20) is a constraint of this session's sandboxing, not of the dependency or configuration.

**All of AC-01 through AC-18 are satisfied**, including AC-14/15/16 (quality gates and GitHub Actions passing) via this confirmed run.

## 19. Deviations from Specification

None from the phase instruction's scope. One repeat of Phase 2's environment constraint (see §20) — not a specification deviation, an environment one.

## 20. Known Issues/Open Decisions

- **Same session-specific limitation as Phase 2:** `phpstan/phpstan` is dist-only and requires a GitHub API zipball this sandboxed session can't fetch (access scoped to `jaaan44/company-app` only). Because it's now a permanent dev dependency, *every* `composer install`/`require` in this session fails at that step — which this phase discovered also prevents any **other newly-added** package (here, `livewire/livewire`) from finishing its local checkout in the same run, even though `iamcal/sql-parser`, `larastan/larastan`, and `livewire/livewire` all sync successfully from git source first. `composer.json`/`composer.lock` are correctly resolved (`livewire/livewire: ^4.4` → v4.4.4) regardless. The app was confirmed to still boot and all other checks (Pint, PHPUnit) to still pass despite the incomplete `vendor/livewire/` — Livewire isn't referenced anywhere in application code yet, so its absence from local `vendor/` doesn't affect anything this phase actually tests. Real verification is via GitHub Actions (§18).
- Real-time transport, object storage provider, and Departments/Teams hierarchy shape remain open (`02_ARCHITECTURE.md` §9) — untouched by this phase.
- No Admin Backoffice page exists yet — intentional (out of scope).

## 21. Resource-Efficiency Review

- No microservices, Kubernetes, Kafka, Elasticsearch/OpenSearch, distributed databases, or new always-running services.
- No MySQL server provisioned or added to CI — SQLite remains sufficient for the current database-neutral test suite.
- Livewire chosen specifically to avoid a separate SPA build pipeline (DEC-019's rationale).
- Flutter: no routing/state-management package added without a genuine current need (DEC-021).
- API: one lightweight, unauthenticated health endpoint — no heavyweight monitoring subsystem.

## 22. Security Review

- Health endpoint deliberately public; exposes only `status` and `timestamp` — verified by an explicit test asserting the absence of `env`/`debug`/`database`/`version` keys.
- No authentication/RBAC implemented — not in scope; existing security model documentation (`05_SECURITY_MODEL.md`) updated to record the health endpoint as the one deliberate public exception, not a precedent for defaulting new endpoints to public.
- No secrets, credentials, or production URLs introduced anywhere (Flutter's `AppConfig.apiBaseUrl` defaults to a local dev URL, overridden only via `--dart-define` at build time).

## 23. Documentation Updated

`README.md`, `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/CHANGELOG.md`, `docs/CURRENT_STATE.md`, `docs/DECISIONS.md`, `docs/testing/TEST_STATUS.md`, `docs/phases/V1_PHASE_03_DEFINITION.md` (new), `docs/handoffs/V1_PHASE_03_HANDOFF.md` (this file, new). `CLAUDE.md` and `06_UI_UX_GUIDELINES.md` were reviewed and required no material change this phase (no quality-command or UI changes occurred).

## 24. Manual Verification Instructions

**Backend:**
```sh
cd apps/api
composer install
cp .env.example .env && php artisan key:generate
php artisan serve
curl http://localhost:8000/api/v1/health
# expect: {"data":{"status":"ok","timestamp":"..."}}
```

**Mobile:**
```sh
cd apps/mobile
flutter pub get
flutter run
# expect the same "Company App" bootstrap shell as Phase 1, now served from lib/app/ + lib/features/home/
```

## 25. Recommended Next Phase

**Phase 4 — Authentication**, per `docs/ROADMAP.md`: login, logout, token issuance/refresh for both mobile and Admin, password handling, account states.

## Business Functionality Statement

**No Company App business functionality was introduced in this phase.** This phase touched only architectural foundations: routing, one non-business endpoint, dependency installation, and documentation.

---

*Per `CLAUDE.md` §8 (Stop Discipline): this phase is complete. Not merged into `main`. Phase 4 is not authorized by this handoff and will not begin without explicit user instruction.*
