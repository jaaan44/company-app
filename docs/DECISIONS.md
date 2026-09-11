# DECISIONS — Architectural & Product Decision Ledger

Durable record of accepted decisions. Each entry is permanent once recorded — a superseded decision is marked `SUPERSEDED` and links to its replacement; it is never deleted or silently rewritten. New decisions get new, incrementing IDs.

---

### DEC-001 — Repository-based development memory
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** Repository documentation is authoritative for project state. Conversation history is not authoritative.
**Rationale:** Enables reliable recovery of project state across sessions, developers, and AI context resets without dependency on chat history.

### DEC-002 — Phase-based development
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** Development proceeds through explicitly authorized phases. A phase must be completed, reviewed, and tested as appropriate, and approved before the next phase begins.
**Rationale:** Prevents scope creep and uncontrolled speculative implementation; keeps each unit of work reviewable.

### DEC-003 — Laravel + Flutter architecture
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** Laravel provides the backend/API and the Admin Backoffice. Flutter provides the staff mobile application.
**Rationale:** Given technology direction for the project; Laravel's ecosystem covers API + admin web needs, Flutter covers cross-platform mobile with a single codebase.

### DEC-004 — Granular authorization
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** Authorization is permission-oriented rather than relying solely on hard-coded role checks.
**Rationale:** Roles alone don't scale to the operational nuance the product needs (e.g. a Supervisor's data visibility vs. an Administrator's). Permissions as the primitive, roles as bundles, keeps this flexible.

### DEC-005 — Location model
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** V1 favors explicit employee location/check-ins instead of continuous GPS tracking. Continuous tracking is outside the initial product direction.
**Rationale:** Simpler, more private, and sufficient for the intended use cases (client visit confirmation, field presence) without the privacy/battery/infrastructure burden of continuous tracking.

### DEC-006 — Tasks may exist independently
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** Tasks may optionally belong to a project. Internal company tasks must be possible without creating artificial projects.
**Rationale:** Forcing every task into a project would create noise (fake "Internal" projects) and misrepresent the data model.

### DEC-007 — Simple messaging first
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** The initial messaging system remains lightweight. Do not attempt to reproduce Slack/Teams functionality in V1.
**Rationale:** Messaging is a supporting feature of an operations platform, not the product's core value; scope discipline avoids an open-ended chat-platform build.

### DEC-008 — Connected domain model
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** Modules should not become isolated mini-applications. Staff, clients, projects, tasks, work logs, schedules, service reports, and incidents should have meaningful relationships where appropriate.
**Rationale:** Preserves the value of a unified operations platform over a collection of disconnected tools; supports reporting and cross-module workflows.

### DEC-009 — Auditability
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** Important administrative and workflow actions should eventually have auditable histories.
**Rationale:** Required for accountability in an internal operations tool handling HR, incident, and administrative data.

### DEC-010 — Historical operational records
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** Important state changes such as staff status, leave approvals, and incident progress should preserve history where appropriate rather than simply overwriting previous state.
**Rationale:** Operational and compliance value in knowing not just current state but how it got there; supports reporting and dispute resolution.

### DEC-011 — Monorepo structure: apps/api and apps/mobile
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** Company App is developed as a single monorepo. The Laravel backend/API (and, eventually, the Admin Backoffice) lives under `apps/api`; the Flutter staff mobile app lives under `apps/mobile`. No monorepo orchestration tooling (Nx/Turborepo/Melos) is introduced — the two applications operate independently within the shared repository.
**Rationale:** Resolves the "monorepo vs polyrepo" open question from `02_ARCHITECTURE.md` §9 in favor of the suggested default, appropriate for a single team with tightly coupled release cadence at ~100-user scale. This decision fixes *structure and location* only — it does not decide the Admin Backoffice's rendering approach (Blade/Livewire/Inertia/SPA), which remains open.

### DEC-012 — Local bootstrap database: SQLite, production engine still open
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** The Laravel application's local development bootstrap uses SQLite (Laravel's own default for a fresh install). This is a local/dev convenience only. It does **not** resolve the PostgreSQL vs. MySQL open question recorded in `02_ARCHITECTURE.md` §9 for production — that choice remains deliberately open until a phase that genuinely requires it.
**Rationale:** Avoids silently converting an intentionally deferred architecture decision into a permanent one merely because a bootstrap step needed *some* working database driver. SQLite requires no separate service, matching the resource-efficiency direction for Phase 1.
**Update (2026-09-10):** the production engine question this decision left open is now resolved — see DEC-016 (MySQL). This entry's own content (SQLite for local/test use) is unchanged and still accurate.

### DEC-013 — No Docker by default for local development
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** Company App's default local development workflow does not use Docker. PHP, Composer, Node.js, and the Flutter SDK installed directly on the developer's machine are sufficient and are what both apps have actually been built and validated against (Phases 1–2).
**Rationale:** At ~100 users, an always-running container layer adds idle resource overhead and setup complexity with no demonstrated need — both apps run cleanly without it. This does not forbid Docker later for a specific, demonstrated need (e.g. standardizing a chosen non-SQLite database across contributors); it only sets the default.

### DEC-014 — Static analysis: Larastan v3, level 5
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** Backend static analysis uses Larastan (PHPStan for Laravel) v3, configured at `apps/api/phpstan.neon` scanning `app/` at level 5, with no ignored-error baseline.
**Rationale:** Level 5 is a realistic, moderate starting point for a currently near-empty codebase — strict enough to catch real classes of bugs, not so strict (e.g. max/9) that it would demand suppression rules or fight Eloquent's dynamic behavior before any real business logic exists. The level should be raised deliberately as the codebase matures, not lowered to make a failing check pass.

### DEC-015 — CI layout: two path-filtered GitHub Actions workflows
**Date:** 2026-09-09
**Status:** ACCEPTED
**Decision:** CI is two separate workflow files — `.github/workflows/backend-ci.yml` and `.github/workflows/mobile-ci.yml` — each triggered on pull requests targeting `main` and pushes to `main`, and each scoped via `paths:` to its own app directory (plus its own workflow file) so an unrelated app's changes don't trigger it. Single PHP version (8.4) and single Flutter version (3.47.2); no build matrix; no Android/iOS artifact builds.
**Rationale:** Matches the resource-efficiency direction (`02_ARCHITECTURE.md` §0) — fast, cheap CI appropriate for a ~100-user internal tool. Two small workflows were chosen over one workflow with two jobs for clearer independent history/status per app, without adding real complexity (no shared logic between them to justify combining).

### DEC-016 — Production database: MySQL
**Date:** 2026-09-10
**Status:** ACCEPTED
**Decision:** MySQL is the intended production relational database for Company App. This closes the PostgreSQL-vs-MySQL open question left by DEC-012 for production use. SQLite continues to be used for lightweight local development and automated tests where behavior is database-neutral. No production database server is provisioned by this decision — it records direction only.
**Rationale:** A single, well-understood, conventional choice appropriate for a ~100-user internal Laravel application; avoids carrying an open question indefinitely once the phase authorized to close it did so. DEC-012's original content (SQLite for local bootstrap) remains accurate and is not rewritten — this decision only resolves what DEC-012 explicitly left open.

### DEC-017 — Identifier strategy: numeric internal ID + ULID public ID
**Date:** 2026-09-10
**Status:** ACCEPTED
**Decision:** Internal relational primary keys remain auto-incrementing unsigned `BIGINT` (Laravel's `$table->id()`), used for foreign keys and internal joins. Externally addressable business entities (APIs, URLs, mobile references) additionally carry a `ULID public_id` column (`$table->ulid('public_id')->unique()`) once such an entity is actually built. Not every table needs one — pivot/history tables generally don't; user-facing entities (Staff, Clients, Projects, Tasks, Leave Requests, Service Reports, Incidents) likely will. Each future phase decides per entity rather than applying this blindly everywhere.
**Rationale:** Numeric PKs keep joins/indexes fast and simple; ULIDs avoid exposing predictable sequential IDs externally and sort chronologically (useful for pagination/ordering) unlike random UUIDv4. Resolves the "UUID vs auto-increment" open question from `03_DATABASE_MODEL.md` §3. No business migrations were created to demonstrate this — it is a documented convention for future phases to apply when they build real entities.

### DEC-018 — Laravel application architecture: modular monolith, conventional structure
**Date:** 2026-09-10
**Status:** ACCEPTED
**Decision:** Company App's backend remains a single Laravel application (no microservices, no independently deployable modules). Future modules are organized through standard Laravel conventions — Controllers (thin), Form Requests (validation), Policies (authorization), Models/Eloquent (no repository pattern), Action/Service classes only where business logic genuinely warrants extraction, Jobs for genuinely asynchronous/expensive work, Events/Listeners only for meaningful decoupling. No empty directories or placeholder classes are created for modules that don't exist yet.
**Rationale:** Matches the resource-efficiency direction (`02_ARCHITECTURE.md` §0) and avoids premature abstraction (repository pattern, service-layer-for-everything) that adds indirection without a demonstrated need at this scale.

### DEC-019 — Admin Backoffice: Laravel Blade + Livewire
**Date:** 2026-09-10
**Status:** ACCEPTED
**Decision:** The future Admin Backoffice will be built with Laravel Blade + Livewire, inside `apps/api` (per DEC-011's location decision). No separate React/Vue/Inertia SPA. `livewire/livewire` (^4.4) is installed as of this phase; no Admin pages, dashboard, or business CRUD screens are built yet.
**Rationale:** Reduces frontend duplication and toolchain surface for an internal, ~100-user system built primarily around forms, CRUD, and dashboards — Livewire lets the same Laravel application/team produce the Admin UI without a separate frontend build pipeline, consistent with the resource-efficiency direction. This resolves the "Admin Backoffice implementation style" open question from `02_ARCHITECTURE.md` §9.

### DEC-020 — API foundation: versioned REST at /api/v1, response conventions implemented
**Date:** 2026-09-10
**Status:** ACCEPTED
**Decision:** The API is rooted at `/api/v1` (`routes/api.php` → `routes/api/v1.php`, so a future `/api/v2` adds a parallel file/group without touching or duplicating v1). A minimal `GET /api/v1/health` endpoint establishes the routing and response conventions documented in `04_API_CONVENTIONS.md`: success responses wrapped in `{"data": ...}`, JSON error rendering for `api/*` requests (already configured in `bootstrap/app.php` since Phase 1), Laravel's standard 422 validation-error shape, and standard HTTP status codes — no proprietary API envelope was invented.
**Rationale:** Establishes the convention with real, tested code rather than documentation alone, per the Phase 3 mandate, while keeping the surface area minimal (one non-business endpoint) since no business modules are authorized yet.

### DEC-021 — Flutter foundation structure; routing and state management deliberately deferred
**Date:** 2026-09-10
**Status:** ACCEPTED
**Decision:** `apps/mobile/lib` is organized as `app/` (root widget/theme), `core/config/` (build-time configuration, e.g. API base URL via `--dart-define`), and `features/` (one folder per future feature area; `home/` exists today as a placeholder). No routing package (e.g. `go_router`) and no state-management framework (Provider/Riverpod/Bloc) are introduced — Flutter's built-in `Navigator`/`MaterialApp.home` is sufficient until a real feature (starting with Authentication) needs more.
**Rationale:** Matches "structure, not screens" — a maintainable foundation for future modules without speculative packages or layering that nothing yet needs. Both choices should be revisited when Phase 4 (Authentication) introduces the first real navigation/state requirements, not decided in the abstract now.

### DEC-022 — Authentication mechanisms: session/cookie for Admin, Sanctum bearer tokens for Mobile
**Date:** 2026-09-11
**Status:** ACCEPTED
**Decision:** The Admin Backoffice (Blade + Livewire) authenticates using Laravel's conventional session/secure-cookie authentication via the `web` guard — no bearer tokens for ordinary browser sessions. The Flutter mobile app authenticates using Laravel Sanctum personal access tokens, sent as `Authorization: Bearer <token>` — no cookie-based SPA/stateful authentication for Flutter, no OAuth server, no JWT infrastructure, no Passport. `laravel/sanctum` (^4.0) is installed; its `personal_access_tokens` migration is published as-is.
**Rationale:** Two client types, two conventional Laravel-native mechanisms, matching CLAUDE.md's Phase 4 instructions and the ~100-employee resource-efficiency direction (`02_ARCHITECTURE.md` §0) — avoids the operational overhead of an OAuth/JWT server for an internal tool this size. Sanctum's token model supports future multi-device use without inventing a refresh-token scheme.

### DEC-023 — No public self-registration
**Date:** 2026-09-11
**Status:** ACCEPTED
**Decision:** Company App has no `POST /register` route (web or API) and no public registration screen in either surface. All accounts are company-provisioned: for this phase, via `php artisan db:seed --class=Database\Seeders\AdminUserSeeder` (local/testing environments only) or Tinker; a future Staff Management phase may add an Admin-driven account-creation UI.
**Rationale:** Company App accounts represent employees of a specific organization — self-service signup has no product meaning here and would be a security liability (arbitrary account creation) for an internal system.

### DEC-024 — Transitional Admin Backoffice access flag, pending Phase 5 RBAC
**Date:** 2026-09-11
**Status:** ACCEPTED
**Decision:** A boolean `users.is_admin` column (default `false`, not mass-assignable) is the sole gate on whether an authenticated account may enter the Admin Backoffice. It is checked once, at login time, in `App\Livewire\Auth\LoginForm`. This is deliberately minimal and will be superseded by Phase 5's granular role/permission system — `is_admin` is not intended to survive as a permanent authorization primitive.
**Rationale:** Phase 4 must distinguish "may enter the Admin Backoffice" from "is an authenticated user" (CLAUDE.md §9) without building any part of Phase 5's RBAC. A single boolean is the smallest mechanism that satisfies this without pre-building roles/permissions tables or policies.

### DEC-025 — Flutter authentication state management: plain `ChangeNotifier`
**Date:** 2026-09-11
**Status:** ACCEPTED
**Decision:** `AuthController` (in `lib/features/auth/state/`) extends Flutter's built-in `ChangeNotifier` and is consumed via `ListenableBuilder` — no third-party state-management package (Provider, Riverpod, Bloc, GetX) is introduced. `CompanyApp` owns a single `AuthController` instance for the app's lifetime and injects it into `AuthGate`.
**Rationale:** Phase 4 is the first phase with a genuine shared-state need (DEC-021 deferred this exact decision to here). One piece of app-wide state (authentication) doesn't justify a dependency-injection/state-management framework at this project's scale — `ChangeNotifier` is part of the Flutter SDK already in use and is sufficient. Revisit if a later phase's state needs (e.g., multiple independent feature stores, computed/derived state across many providers) outgrow it.

### DEC-026 — Flutter token persistence: `flutter_secure_storage`
**Date:** 2026-09-11
**Status:** ACCEPTED
**Decision:** The Sanctum bearer token is persisted on-device via the `flutter_secure_storage` package (iOS Keychain; Android EncryptedSharedPreferences/Keystore), behind a `TokenStorage` interface (`lib/features/auth/data/token_storage.dart`) so tests can substitute an in-memory fake. The token is never written to plain `SharedPreferences`, source code, or an unencrypted file.
**Rationale:** CLAUDE.md's Phase 4 instructions explicitly require platform secure storage for the auth token, not ordinary local storage. `flutter_secure_storage` is the standard, actively maintained Flutter package for this and is the only new package this phase adds for storage (paired with `http` for the API client itself).

---

## Template for Future Decisions

```
### DEC-XXX — <short title>
**Date:** YYYY-MM-DD
**Status:** ACCEPTED | SUPERSEDED by DEC-YYY | REJECTED
**Decision:** <what was decided>
**Rationale:** <why>
```
