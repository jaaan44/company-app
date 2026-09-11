# Phase 5 Handoff — Roles & Permissions

## 1. Phase Identification / Objective

- **Phase:** 5 — Roles & Permissions
- **Date:** 2026-09-11
- **Branch:** `claude/wonderful-darwin-whxeyg` (from `main` @ `460e458`, the merge commit for PR #6, which contains the approved Phase 1–4A content)
- **Objective:** establish the reusable authorization foundation for Company App — a small V1 role catalog (Administrator/Manager/Staff), a distinct permission catalog, one role per user, a centralized reusable enforcement pattern, and retirement of the Phase 4 transitional `users.is_admin` flag. Not the future business modules' own permission catalogs.

## 2. Repository Recovery / Main Verification

Read, in order: `CLAUDE.md`, `docs/CURRENT_STATE.md`, `docs/ROADMAP.md`, `docs/handoffs/V1_PHASE_04_HANDOFF.md`, `docs/handoffs/V1_PHASE_04A_HANDOFF.md`, `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/06_UI_UX_GUIDELINES.md`, `docs/DECISIONS.md`, `docs/testing/{TEST_PLAN,TEST_STATUS,UAT_LOG}.md`, `docs/handoffs/README.md`, and the existing Authentication code (`User` model, `AccountStatus` enum, `EnsureAccountIsActive` middleware, `LoginForm`, `AuthController`, `UserResource`, migrations, factories, seeders, existing tests).

Fetched `origin/main` and confirmed it contains PR #6's merge (`460e458`) with the actual approved Phase 4/4A artifacts: `EnsureAccountIsActive`, `AdminUserSeeder`, `docker-compose.yml`, `docs/handoffs/V1_PHASE_04A_HANDOFF.md`, DEC-022 through DEC-027 in `docs/DECISIONS.md`. **No material contradiction found.** This session's designated push branch, per the harness environment (not the phase instructions' own suggested name), is `claude/wonderful-darwin-whxeyg` — that pre-existing branch was confirmed to sit at the exact same commit as `origin/main` before any work began, so branching from it is equivalent to branching from verified `origin/main`.

## 3. Role Architecture

**One role per user (DEC-028):** `users.role_id`, a nullable `BIGINT` foreign key to `roles.id` (`nullOnDelete`) — **not** a many-to-many `users`↔`roles` join table. A user with no role has no permissions. This is a deliberate resolution of `03_DATABASE_MODEL.md`'s original provisional many-to-many sketch, per the governing instructions' explicit requirement not to build user↔role infrastructure without a demonstrated need.

## 4. Initial Role Catalog

`App\Models\Role` constants: `ADMINISTRATOR = 'administrator'`, `MANAGER = 'manager'`, `STAFF = 'staff'`. These are system roles, not Departments/Teams/positions — no such structure was introduced. Seeded via `RolePermissionSeeder`.

## 5. Permission Architecture / Catalog

`permissions` table: `name` (unique, dot-notation, e.g. `admin.access`), `label`. Many-to-many `role_permissions` join to `roles` (composite primary key `role_id`+`permission_id`, no surrogate key — a plain pivot).

**Foundational catalog only** (proves the mechanism, not a speculative full catalog):
- `admin.access` — may enter the Admin Backoffice.
- `authorization.manage` — may manage users' role/authorization assignments (mechanism only; no management UI exists yet — that's future work).

No permissions for Staff/Clients/Projects/Leave/Tasks/Work Logs/Messaging or any other unimplemented module were created.

## 6. Database Schema / Migrations

Four new migrations, in order:
1. `2026_09_11_050000_create_roles_table.php` — `id`, `name` (unique), `label`, timestamps.
2. `2026_09_11_050001_create_permissions_table.php` — `id`, `name` (unique), `label`, timestamps.
3. `2026_09_11_050002_create_role_permissions_table.php` — `role_id`+`permission_id` FKs (both `cascadeOnDelete`), composite primary key.
4. `2026_09_11_050003_add_role_id_to_users_table.php` — adds `users.role_id` (nullable, `nullOnDelete`); backfills any pre-existing `is_admin = true` row onto the Administrator role (defensively creating that one role row if it doesn't already exist, so this migration is correct regardless of seeder execution order); drops `is_admin`. The `down()` migration reverses all of this, including restoring `is_admin` values from `role_id`.

No polymorphic/generic ACL tables. `roles`/`permissions` deliberately do **not** carry a `public_id` (ULID) — they're a small, fixed, internally-managed system catalog, not an externally addressable business entity in DEC-017's sense; `UserResource` exposes the role's name, never an ID, so nothing external needs to address a role by public identifier. Column types (`string`, `boolean`-free here) are portable across MySQL and SQLite; no native MySQL-only constructs.

## 7. Authorization Enforcement Pattern

A single `Gate::before` callback, registered in `App\Providers\AppServiceProvider::boot()` (alongside the existing `login` rate limiter):

```php
Gate::before(function (User $user, string $ability): ?bool {
    if ($user->hasRole(Role::ADMINISTRATOR)) {
        return true;
    }

    return $user->hasPermission($ability) ? true : null;
});
```

Because this runs through Laravel's real Gate resolution, every existing authorization surface already works against permission names with zero per-permission `Gate::define` boilerplate:
- **Route middleware:** Laravel's built-in `can` alias — `Route::middleware(['auth', 'account.active', 'can:admin.access'])` (used for `/home`).
- **Imperative checks:** `$user->can('some.permission')` / `$user->cannot(...)` (used in `LoginForm`).
- **Blade:** `@can('some.permission')` (available, not yet used — no Admin navigation UI exists yet).
- **Future Policies:** `$user->can(...)` inside a Policy method works identically.

Returning `null` (not `false`) when the user lacks the permission is deliberate — it lets an unrelated Policy/Gate ability (e.g. a future model Policy's `update` check) continue to resolve normally instead of being silently intercepted; Laravel's own default-deny behavior still denies anything nothing else grants.

`App\Models\User` gained `role(): BelongsTo`, `hasRole(string $name): bool`, `hasPermission(string $name): bool` — the single source of truth every surface above ultimately calls into.

## 8. Administrator Behavior

Administrator does **not** hold explicit `role_permissions` rows. Its "may do everything" behavior is expressed in exactly one place — the `Gate::before` override above — rather than by attaching every current and future permission to it. This was a deliberate choice (documented as DEC-028): explicit attachment risks a new permission being added later and someone forgetting to grant it to Administrator, silently locking out the highest-privilege role; a centralized override cannot have that failure mode and keeps Administrator's behavior in one auditable place instead of scattered `if ($user->hasRole('administrator'))` checks.

## 9. Manager / Staff Behavior

Both roles exist (seeded by `RolePermissionSeeder`) with **no attached permissions** in this phase — explicitly acceptable per the governing instructions §10. Confirmed by tests: neither role implicitly gains any Administrator-only ability (`PermissionMechanismTest::test_manager_does_not_implicitly_gain_administrator_permissions` / `test_staff_does_not_implicitly_gain_administrator_permissions`), and neither can enter the Admin Backoffice (`AdminBackofficeAuthorizationTest`).

## 10. `is_admin` Retirement

- Column dropped in migration 4 above (see §6), after backfilling any pre-existing `true` row onto the Administrator role.
- `User::casts()` no longer casts `is_admin`; the model has no remaining reference to it.
- `App\Livewire\Auth\LoginForm`'s `if (! $user->is_admin)` replaced with `if ($user->cannot('admin.access'))`.
- `routes/web.php`'s `/home` route gained `can:admin.access` middleware (in addition to the pre-existing `auth`/`account.active`).
- `UserFactory`'s `admin()` state replaced with `administrator()`/`manager()`/`staff()` (each idempotently look-up-or-creates its role via `firstOrCreate`, so tests don't depend on seeder execution order).
- `AdminUserSeeder` assigns `role_id` (Administrator) via direct attribute assignment instead of setting `is_admin` (both remain outside the model's mass-assignable `#[Fillable(...)]` list).
- A dedicated test (`PermissionMechanismTest::test_the_transitional_is_admin_column_no_longer_exists`) asserts `Schema::hasColumn('users', 'is_admin')` is `false`.
- No two competing authorization mechanisms coexist at any point — the retirement migration and the code changes landed together.

## 11. Admin Backoffice Changes

Access chain: `auth` → `account.active` → `can:admin.access` → `/home` (`routes/web.php`). This mirrors the existing double-enforcement pattern Phase 4 established for account status: checked once at login (`LoginForm`) **and** on every subsequent request via route middleware, so a role/permission change mid-session takes effect immediately, not just at next login (verified by `AdminBackofficeAuthorizationTest`'s suspended/inactive-mid-session tests, which — because `account.active` runs before `can:admin.access` in the middleware chain — exercise the same code path as Phase 4's suspension tests, now against an Administrator-role user rather than an `is_admin` one).

## 12. API Impact

No new endpoints. `App\Http\Resources\UserResource` gained a `role` field — the role's *name* (e.g. `"administrator"`), nullable, never the internal numeric `role_id`. Verified via `php artisan serve` + curl (see §17) and existing/new tests. No endpoint is currently permission-gated beyond what Phase 4 already authenticates; the same `Gate::before` mechanism is available to a future endpoint via `Route::middleware(['auth:sanctum', 'can:<permission>'])` when one needs it.

## 13. Seeders

- **`Database\Seeders\RolePermissionSeeder`** (new) — safe, deterministic, idempotent (`firstOrCreate` matched on each unique `name`) system data: the Administrator/Manager/Staff roles and the foundational permission catalog. Called unconditionally from `DatabaseSeeder::run()` — runs in every environment (local, testing, staging, production), never gated behind an environment check, and contains no credentials. Deliberately kept as a separate class from `AdminUserSeeder` per the governing instructions' "separate system authorization seed data from unsafe/demo credentials."
- **`Database\Seeders\AdminUserSeeder`** (updated) — still local/testing-only (unchanged environment guard), still refuses to run elsewhere. Now calls `RolePermissionSeeder` defensively (idempotent, so harmless if already run) and assigns `role_id` for the Administrator role instead of `is_admin`.
- **`RoleFactory`, `PermissionFactory`** (new) — standard Eloquent factories for ad hoc test data (e.g. `PermissionMechanismTest`'s "role grants only the permission attached to it" test).
- **`UserFactory`** — `administrator()`/`manager()`/`staff()` states, each idempotently resolving its role via `firstOrCreate`.

## 14. Security Review

- **Default-deny confirmed:** a user with `role_id = null` has no permissions (`PermissionMechanismTest::test_user_with_no_role_has_no_permissions`); an unrecognized ability name is denied, not merely "not explicitly granted" (`test_permission_checks_default_to_deny_for_an_unknown_ability`).
- **Inactive/suspended accounts remain denied regardless of role:** `AdminBackofficeAuthorizationTest::test_suspended_administrator_cannot_access_the_admin_backoffice` / `test_inactive_administrator_cannot_access_the_admin_backoffice` — even the highest-privilege role loses access the moment its account status is not `active`, because `account.active` middleware runs before the permission check.
- **No client-submitted role/permission escalation:** `role_id` is excluded from `User`'s `#[Fillable(...)]` list (whitelist, not blacklist) — confirmed by `PermissionMechanismTest::test_role_id_is_not_mass_assignable_on_user`, which attempts `User::create([..., 'role_id' => $role->id])` and asserts the persisted row's `role_id` is `null`.
- **Role assignment cannot be mass-assigned accidentally** — same mechanism as above; role assignment only ever happens via direct property assignment in trusted seeder/backend code, never through user-facing input.
- **Internal numeric authorization IDs are not unnecessarily exposed** — `UserResource` exposes `role` as a name string only; no endpoint returns a `role_id` or a `permission_id`.
- **Admin authorization is enforced server-side, never merely hidden in UI** — no Admin Backoffice navigation/UI was built in this phase (none existed before it either), so there was nothing to merely hide; the `can:admin.access` route middleware is the actual enforcement point, verified by HTTP-level tests (`AdminBackofficeAuthorizationTest`) that assert a `403`, not just that a link doesn't render.
- **Administrator override is centralized**, not scattered `if ($user->is_admin)`/`if ($user->role === 'admin')` checks — see §8.

## 15. Files Changed

**Added (backend):** `app/Models/Role.php`, `app/Models/Permission.php`, `database/migrations/2026_09_11_050000_create_roles_table.php`, `database/migrations/2026_09_11_050001_create_permissions_table.php`, `database/migrations/2026_09_11_050002_create_role_permissions_table.php`, `database/migrations/2026_09_11_050003_add_role_id_to_users_table.php`, `database/seeders/RolePermissionSeeder.php`, `database/factories/RoleFactory.php`, `database/factories/PermissionFactory.php`, `tests/Feature/Authorization/PermissionMechanismTest.php`, `tests/Feature/Authorization/AdminBackofficeAuthorizationTest.php`, `tests/Feature/Authorization/AdminUserSeederTest.php`, `tests/Feature/Authorization/RolePermissionSeederTest.php`.

**Modified (backend):** `app/Models/User.php`, `app/Http/Resources/UserResource.php`, `app/Livewire/Auth/LoginForm.php`, `app/Providers/AppServiceProvider.php`, `routes/web.php`, `database/seeders/AdminUserSeeder.php`, `database/seeders/DatabaseSeeder.php`, `database/factories/UserFactory.php`, `tests/Feature/Auth/AdminLoginTest.php`.

**Added (docs):** `docs/phases/V1_PHASE_05_DEFINITION.md`, `docs/handoffs/V1_PHASE_05_HANDOFF.md` (this file).

**Modified (docs):** `docs/02_ARCHITECTURE.md` (§4, new §15), `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/CHANGELOG.md`, `docs/CURRENT_STATE.md`, `docs/DECISIONS.md` (DEC-028; DEC-024 marked superseded), `docs/ROADMAP.md`, `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`.

No `apps/mobile` files changed — Flutter is entirely unaffected by this phase (no mobile UI currently needs role-aware behavior; `role` is available in the API response for whenever it does).

## 16. Tests Added/Changed

**20 new PHPUnit tests** in `tests/Feature/Authorization/`:
- `PermissionMechanismTest` (8) — no-role default-deny, unknown-ability default-deny, role grants only its attached permissions (plus the inverse `Permission::roles()` relationship), Administrator's centralized override, Manager/Staff don't implicitly gain Administrator permissions, `is_admin` column confirmed absent, `role_id` not mass-assignable.
- `AdminBackofficeAuthorizationTest` (7) — Administrator can access `/home`; active Staff/Manager/no-role users denied (`403`); suspended/inactive Administrator denied (redirect, mid-session); unauthenticated redirect.
- `AdminUserSeederTest` (2) — seeder assigns the Administrator role and grants `admin.access`; seeder is idempotent.
- `RolePermissionSeederTest` (3) — creates the exact V1 role catalog; creates the foundational permission catalog; running it twice doesn't duplicate rows.

**Modified:** `tests/Feature/Auth/AdminLoginTest.php` — `->admin()` → `->administrator()` throughout; the "non-admin account is rejected" test now uses `->staff()` instead of the removed `'is_admin' => false` array key (the column no longer exists).

**Unaffected in behavior:** `tests/Feature/Api/V1/Auth/{LoginTest,MeAndLogoutTest}.php`, `tests/Feature/Api/V1/HealthEndpointTest.php`, `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php` — all still pass unmodified (the `LoginTest::assertJsonMissingPath('data.user.is_admin')` assertion remains true, now because the field was never in the whitelist rather than being an old flag hidden by choice).

## 17. Commands Actually Executed

| Command | Result |
|---|---|
| `composer validate --strict` | `./composer.json is valid` |
| `vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` |
| `vendor/bin/phpstan analyse` | `{"tool":"phpstan","result":"passed","errors":0}` |
| `php artisan test` | `{"tool":"phpunit","result":"passed","tests":51,"passed":51,"assertions":136}` |
| `php artisan migrate:fresh --force` (SQLite) | All 9 migrations (5 pre-existing + 4 new) ran cleanly |
| `php artisan db:seed` / `db:seed --class=...AdminUserSeeder` | `RolePermissionSeeder` ran (catalog created); `AdminUserSeeder` ran (Administrator assigned) |
| `php artisan tinker` — role/permission/Gate spot checks | `role: administrator`, `can admin.access: YES`, `is_admin col exists: NO`, `staff can admin.access: NO` |
| `php artisan serve` + curl — `/login`, `/api/v1/health`, `/api/v1/auth/login` | `200`/`200`/`200`; login response includes `"role":"administrator"`, no `is_admin` |
| **Docker:** `docker compose build app` (real, committed Dockerfile) | Failed at the `apt-get` step — same documented sandbox network-policy limitation as Phase 4A (§20) |
| **Docker:** build via temporary, uncommitted Dockerfile variant (apt-get step removed) | Succeeded |
| `docker compose up -d` | All 3 containers started; `mysql` reported `healthy` |
| `docker compose exec app php artisan migrate:fresh --force` | All 9 migrations against real MySQL 8.4 |
| `docker compose exec app php artisan db:seed --class=...AdminUserSeeder --force` | Administrator assigned inside Docker/MySQL |
| `docker compose exec app php artisan test` | `51 passed (136 assertions)` |
| `docker compose exec app vendor/bin/pint --test` | `PASS .......... 57 files` |
| `docker compose exec app vendor/bin/phpstan analyse` | `[OK] No errors` |
| `curl http://localhost:8012/api/v1/health` / `/login` / `POST /api/v1/auth/login` (through Nginx) | `200`/`200`/`200`, login response confirmed via full MySQL round-trip |
| `docker compose down -v` | Stack torn down; temporary Dockerfile variant and compose override deleted, nothing test-only committed |

## 18. Exact Results

See §17's table in full; the same results are recorded per-check in `docs/testing/TEST_STATUS.md`'s new Phase 5 section.

## 19. Docker/MySQL Validation

Genuinely performed against a running Docker stack, not assumed — see §17. This sandbox's network policy blocks `apt-get`'s access to `deb.debian.org` during an image *build* (the identical, already-documented Phase 4A limitation — confirmed by reproducing the failure against the real, unmodified Dockerfile before working around it), while `docker pull` via the Docker Hub mirror (`mirror.gcr.io`) and running containers work normally. Per the established Phase 4A pattern: (1) the real, committed `docker/php/Dockerfile` was built as-is and confirmed to fail at exactly the `apt-get` line, proving the rest is unaffected; (2) a temporary, uncommitted Dockerfile variant (identical except that one line removed — `pdo_mysql`/`bcmath` install via `docker-php-ext-install` needs no network) was used purely to run the full stack; (3) the already-verified host `vendor/` was copied into the container via `docker cp` (same technique as Phase 4A) since Composer itself wasn't exercised in the container; (4) all temporary artifacts (`docker-compose.override.yml`, `docker/php/Dockerfile.temp`, test containers/volumes) were deleted after validation — confirmed via `git status` showing neither as tracked or untracked afterward.

**No Docker architecture was changed** — this phase didn't touch `docker-compose.yml`, the real `Dockerfile`, `entrypoint.sh`, or Nginx config, and none of that was genuinely required (no new service, no new port, no new build step for the real image).

## 20. GitHub Actions Status

A draft pull request ([#7](https://github.com/jaaan44/company-app/pull/7), `claude/wonderful-darwin-whxeyg` → `main`, not merged) was opened to exercise the `pull_request` trigger, matching the established pattern from Phases 2–4A.

**Backend CI ran and passed on commit `d1c7d73`:**

| Workflow | Job | Result | Duration | Run |
|---|---|---|---|---|
| Backend CI | Backend quality gates (PHP 8.4) | ✅ success | ~24s | [run 34581275152](https://github.com/jaaan44/company-app/actions/runs/34581275152) |

Mobile CI did not trigger — no `apps/mobile` files changed, correctly respecting the path-filtered CI design (DEC-015). PR mergeability confirmed `clean` (no conflict with `main`) at the time of this check. This confirms, independently of this session's local vendor-recovery work (§21), that `composer.json`/`composer.lock` remain correctly resolved and every dependency (including the hand-recovered `phpstan/phpstan`, `larastan/larastan`, `iamcal/sql-parser`) installs and runs cleanly on GitHub's unrestricted-network runner.

## 21. Deviations

- **Environment, not specification** (same class of issue as every prior phase, not new to this one): `vendor/` was not preinstalled in this session's sandbox. `composer install --prefer-source` succeeded for 111 of 114 packages via this sandbox's working plain `git clone` access; `phpstan/phpstan`, `larastan/larastan`, and `iamcal/sql-parser` (a larastan transitive dependency) required manual recovery because `phpstan/phpstan`'s locked `composer.lock` entry has no `source` key at all (only `dist`, an API-based zipball, matching the exact issue documented in Phase 4's handoff §23) and the initial `composer install`/`update` batch aborted on it before larastan/iamcal's already-cloned cache content was copied into `vendor/`. Recovered by: (1) a *shallow* `git fetch --depth 1 origin <exact-locked-commit-sha>` for `phpstan/phpstan` and `iamcal/sql-parser` directly against their GitHub repos (a full `git clone` of `phpstan/phpstan`'s complete history was attempted first and aborted after ballooning past 7GB — its repository apparently carries large historical PHAR artifacts across its full history; the shallow, single-commit fetch avoided this entirely and completed in seconds at ~140MB); (2) exporting `larastan/larastan` from this session's own local Composer VCS mirror cache (already fully cloned by the earlier `composer update --prefer-source` step) at its exact locked commit; (3) copying all three into `vendor/` and hand-registering their exact `composer.lock` metadata into `vendor/composer/installed.json`; (4) `composer dump-autoload -o` (a local, non-network operation) to regenerate valid autoload files from that updated `installed.json`; (5) manually symlinking `vendor/bin/phpstan`/`vendor/bin/phpstan.phar` (composer's own bin-linking step didn't run as part of `dump-autoload`). `composer.json`/`composer.lock` were untouched by any of this — both remain exactly as an unrestricted-network `composer install` would produce them, confirmed by `composer validate --strict` passing and every command in §17 succeeding identically to how they would on a normal developer machine or GitHub Actions' runner.
- No other deviations from `docs/phases/V1_PHASE_05_DEFINITION.md`.

## 22. Known Issues/Open Decisions

- No Admin Backoffice UI exists yet for managing roles/permissions/user assignments — `authorization.manage` exists as a permission identifier only, proving the catalog can hold more than one entry. Building that UI is explicitly out of scope for this phase and is future Staff Management/Admin Dashboard work.
- Manager and Staff hold no permissions yet — intentional (§9), not a gap; each future module attaches real permissions to these roles as it's built.
- No API endpoint is currently permission-gated (beyond Phase 4's authentication) — there was nothing needing it yet; the mechanism is ready for the first one that does.
- Sanctum token abilities remain unused (same open item carried from Phase 4) — still only one API client type.
- The Docker `apt-get` sandbox limitation (§19) is unchanged from Phase 4A — not introduced or worsened by this phase.

## 23. Resource-Efficiency Review

- No third-party RBAC package (e.g. `spatie/laravel-permission`) — a native ~150-line mechanism (two small models, one migration set, one `Gate::before` callback) is sufficient at ~100-user scale and was explicitly preferred by the governing instructions.
- No external IAM, OAuth authorization server, or policy engine service.
- No Redis or other caching layer for permissions — the permission check is a single cheap `belongsTo`+`belongsToMany` lazy load per request, negligible at this scale; the instructions explicitly warned against introducing "complicated permission caching," which was not needed.
- No many-to-many user↔role infrastructure — one nullable FK column on `users` is the entire schema cost of role assignment.
- No polymorphic/generic ACL tables.
- Three small tables (`roles`, `permissions`, `role_permissions`) with exactly the indexes needed (`roles.name`/`permissions.name` unique, `role_permissions`' composite primary key) — no over-indexing a catalog this size.

## 24. Documentation Updated

`docs/phases/V1_PHASE_05_DEFINITION.md` (new), this handoff (new), `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/DECISIONS.md` (DEC-028; DEC-024 marked `SUPERSEDED by DEC-028`), `docs/CHANGELOG.md`, `docs/CURRENT_STATE.md`, `docs/ROADMAP.md`, `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`. `CLAUDE.md` and `README.md` were reviewed and required no material changes — the quality-gate commands, repository layout, and local setup instructions are all unaffected by this phase.

## 25. Manual/UAT Testing Instructions

**Backend setup (direct install or Docker — both unaffected by this phase beyond the new migrations/seeder):**
```sh
cd apps/api
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan db:seed --class="Database\Seeders\AdminUserSeeder"   # admin@example.test / password — local only, now Administrator-role-based
php artisan serve
```

**Admin Backoffice:** visit `http://localhost:8000/login`, sign in with `admin@example.test` / `password`, confirm redirect to `/home` exactly as in Phase 4 (no visible change is expected — this confirms `is_admin`'s retirement didn't regress the flow). There is currently no way to create a non-Administrator account through the UI (no Staff Management yet); to manually verify denial, use `php artisan tinker`:
```php
$staff = App\Models\User::factory()->staff()->create(['email' => 'staff@example.test', 'password' => Hash::make('password')]);
```
then attempt to log in as `staff@example.test` at `/login` — expect the same "This account does not have Admin Backoffice access." message Phase 4's non-admin rejection used.

**Mobile API (curl):**
```sh
curl -X POST http://localhost:8000/api/v1/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.test","password":"password"}'
# {"data":{"user":{"public_id":"...","name":"Local Admin","email":"admin@example.test","status":"active","role":"administrator"},"token":"..."}}
```
Note the new `"role":"administrator"` field, absent in Phase 4's response shape.

**UAT:** two scenarios logged in `docs/testing/UAT_LOG.md` as `NOT RUN` (UAT-05-01, UAT-05-02) — ready for the product owner's review; not marked `PASS` by this session per CLAUDE.md §7. No new Flutter/mobile UI exists to review (the mobile app has no role-aware behavior yet).

## 26. Recommended Next Phase

**Phase 6 — Organization Structure**, per `docs/ROADMAP.md`: Departments, Teams, Positions and their relationships. (Company Core work, depending on this phase's authorization foundation being in place.)

## Business Functionality Statement

**No functionality outside Roles & Permissions was introduced in this phase.** No Staff, Clients, Projects, Leave, Tasks, Work Logs, Messaging, or any other business module; no Department/Team structure; no Admin Backoffice UI for managing roles/permissions/users; no third-party RBAC package; no many-to-many user↔role infrastructure; no speculative permission catalog beyond the two identifiers needed to prove the mechanism. `is_admin` is fully retired, not retained alongside the new mechanism.

---

*Per CLAUDE.md §8 (Stop Discipline): this phase is complete. Not merged into `main`. Phase 6 is not authorized by this handoff and will not begin without explicit user instruction.*
