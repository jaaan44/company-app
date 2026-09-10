# Phase 3 — Core Architecture — Specification

**Status:** COMPLETE
**Depends on:** Phase 2

## Objective

Establish foundational application conventions — API versioning/response shape, identifier strategy, Admin Backoffice direction, production database direction, Laravel/Flutter organization — that later business modules will build on, implemented with minimal real code (not documentation alone).

## In Scope

- Production database direction: MySQL (DEC-016), closing the open PostgreSQL-vs-MySQL question for production. No server provisioned.
- Identifier strategy: numeric `BIGINT` internal PK + `ULID public_id` for external entities, documented (DEC-017), no business migrations.
- Laravel application organization: modular monolith conventions documented (DEC-018).
- Admin Backoffice direction: Blade + Livewire (DEC-019); `livewire/livewire` installed. No Admin pages/screens.
- API foundation: `/api/v1` routing implemented, `GET /api/v1/health` endpoint (tested), response/error conventions confirmed as actually working (DEC-020).
- Flutter foundation: `lib/app/`, `lib/core/config/`, `lib/features/` structure (DEC-021); routing and state management deliberately deferred.
- Mobile configuration convention: API base URL via `--dart-define`, no hard-coded production URLs.

## Explicitly Out of Scope

- Authentication, RBAC, or any Company App business module (Staff, Clients, Projects, Tasks, Leave, Messaging, Service Reports, Incidents).
- Any Admin Backoffice page/screen/dashboard.
- Any business/domain migrations.
- Provisioning a MySQL server or migrating CI to use one.
- Adding a routing package or state-management framework to Flutter.
- Rate limiting, account-state checks (belong to Phase 4/5).

## Acceptance Criteria

AC-01 through AC-18 as listed in the governing Phase 3 instruction; verified in `docs/handoffs/V1_PHASE_03_HANDOFF.md`.

## Validation Requirements

**Backend:** `composer validate --strict`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test` (including the new health-endpoint tests). Same known session-specific limitation as Phase 2 applies to any composer package needing a fresh install alongside `phpstan/phpstan` — see the handoff.

**Mobile:** `flutter pub get`, `dart format --output=none --set-exit-if-changed .`, `flutter analyze`, `flutter test`.

**CI:** Both GitHub Actions workflows must pass on the pushed branch (verified via a draft PR, as in Phase 2) before this phase is considered merge-ready.

## Notes

- This session has the same sandboxed GitHub API access as Phase 2 (scoped to `jaaan44/company-app` only). Since `phpstan/phpstan` (a permanent dev dependency since Phase 2) is dist-only and can't be fetched locally, **any** `composer require`/`composer install` run in this session fails at that step before finishing the checkout of other newly-added packages — this affected `livewire/livewire` in this phase the same way it affected `larastan/larastan` in Phase 2. `composer.json`/`composer.lock` are correctly resolved regardless; verification happens via GitHub Actions.
