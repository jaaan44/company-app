# Phase 2 — Development Environment & CI — Specification

**Status:** COMPLETE
**Depends on:** Phase 1

## Objective

Establish a lean, reproducible local-development and CI quality baseline for `apps/api` and `apps/mobile`, so future changes are automatically validated before merge — without introducing infrastructure sized for a much larger system than ~100 users.

## In Scope

- Local development strategy decision (Docker vs. locally-installed toolchain) — see DEC-013.
- Backend static analysis: Larastan (PHPStan for Laravel) installed, configured conservatively, documented.
- GitHub Actions CI for both apps: dependency install, Composer validation, Pint, Larastan, PHPUnit (backend); dependency resolution, format, analyze, test (mobile).
- Path-filtered workflow triggers so an unrelated app's changes don't trigger the other's CI.
- Safe, ephemeral secret handling in CI (no real credentials).
- Test database safety (isolated, in-memory SQLite for the test run — already established by Laravel's own `phpunit.xml` in Phase 1; this phase confirms and documents it rather than re-implementing it).
- Documentation of authoritative validation commands in one place (`CLAUDE.md` §5), kept consistent with what CI actually runs.

## Explicitly Out of Scope

- Authentication, RBAC, or any Company App business module.
- Docker as the default dev environment (deliberately not adopted — DEC-013).
- Kubernetes, microservices, Kafka, Elasticsearch/OpenSearch, unnecessary Redis, container orchestration layers, complex CI matrices — none introduced (resource-efficiency direction, `02_ARCHITECTURE.md` §0).
- Resolving PostgreSQL vs. MySQL for production (DEC-012 stands; SQLite remains the CI/test database only).
- Building Android/iOS release artifacts in CI.
- Merging into `main`, or beginning Phase 3.

## Acceptance Criteria

AC-01 through AC-18 as listed in the governing Phase 2 instruction; verified in `docs/handoffs/V1_PHASE_02_HANDOFF.md`, including an honest statement of which were locally verified vs. left pending an actual GitHub Actions run (this session's sandboxed network could not download `phpstan/phpstan`'s package directly — see the handoff's Known Issues section; the dependency and CI configuration are correct and complete, but AC-04's *local* PHPStan execution could not be performed in this session).

## Validation Requirements

**Backend:** `composer install`, `composer validate --strict`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test` — all as configured in `.github/workflows/backend-ci.yml` and documented in `CLAUDE.md` §5.

**Mobile:** `flutter pub get`, `dart format --output=none --set-exit-if-changed .`, `flutter analyze`, `flutter test` — as configured in `.github/workflows/mobile-ci.yml`.

**GitHub Actions:** Workflow YAML validated for syntax (no execution possible until pushed and either a pull request or a push to `main` triggers it, since both workflows are deliberately scoped that way — see `02_ARCHITECTURE.md` §11). Actual execution status is reported honestly in the handoff.

## Notes

- The blocking issue encountered (this sandboxed session's GitHub API access being scoped to `jaaan44/company-app` only, which prevented downloading `phpstan/phpstan`'s GitHub-hosted, dist-only package locally) is a **session/environment constraint**, not a defect in the Phase 2 implementation. `composer.json`/`composer.lock` correctly declare and resolve the dependency; a real GitHub Actions runner has unrestricted internet access and is expected to install it normally.
