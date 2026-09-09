# CURRENT STATE

*Read this first. Kept intentionally short — for depth, follow the pointers, don't expect this file to contain everything.*

**Product:** Company App — internal operations & communication platform
**Current phase:** Phase 2 — Development Environment & CI
**Phase status:** COMPLETE (pending user review)
**Last completed phase:** Phase 2 (Phase 1 is merged into `main`)
**Next planned phase:** Phase 3 — Core Architecture (see `ROADMAP.md`) — **not authorized yet**

## Current Objective

Awaiting review of Phase 2 and authorization for Phase 3.

## Completed

- **Phase 0:** Full documentation/governance foundation.
- **Phase 1:** Monorepo bootstrap — `apps/api` (Laravel 13.31.0) and `apps/mobile` (Flutter 3.47.2). Merged into `main`.
- **Phase 2:** Development environment & CI. See `docs/handoffs/V1_PHASE_02_HANDOFF.md` for full detail.
  - Backend: Larastan (PHPStan for Laravel) v3 installed and configured (`apps/api/phpstan.neon`, level 5). All checks pass — `composer validate --strict`, `vendor/bin/pint --test`, `php artisan test` verified locally; `vendor/bin/phpstan analyse` verified via GitHub Actions (couldn't install locally in this sandboxed session — see Known Blockers).
  - Mobile: `dart format`, `flutter analyze`, `flutter test` all pass locally.
  - CI: `.github/workflows/backend-ci.yml` and `.github/workflows/mobile-ci.yml`, path-filtered, triggered on PRs to `main` and pushes to `main`. **Both confirmed green** on a real GitHub Actions run (draft PR #2, commit `fb4c548`) — see the handoff for run links.
  - No Docker adopted as the default dev environment (DEC-013).
  - No business functionality in either app.

## Pending / Not Started

- Core Architecture (Phase 3) and everything after it on the roadmap.

## Known Blockers / Issues

- **Session-specific, resolved via CI:** this sandboxed authoring session's GitHub API access is scoped to `jaaan44/company-app` only, which blocked a local `composer install` of `phpstan/phpstan` (dist-only, GitHub-hosted package). Confirmed not a repository defect — a real GitHub Actions run installed and passed it cleanly. Future sessions in a similarly sandboxed environment should expect the same local limitation and rely on CI for that specific check.
- Open design questions (DB engine, Admin Backoffice rendering approach, real-time transport, object storage) remain deliberately deferred — see `docs/02_ARCHITECTURE.md` §9.

## Repository / Branch Information

- Repository: `jaaan44/company-app`
- Default branch: `main` (contains the approved Phase 0 + Phase 1 baseline)
- Phase 2 branch: `claude/v1-phase-02-development-ci` (branched from `main`, not merged)
- Draft PR #2 (`claude/v1-phase-02-development-ci` → `main`) open — created only to exercise CI's `pull_request` trigger for verification; not merged.

## Latest Relevant Handoff

`docs/handoffs/V1_PHASE_02_HANDOFF.md`

## For the Next Session

Read `CLAUDE.md`, then this file, then `docs/ROADMAP.md`, then `docs/handoffs/V1_PHASE_02_HANDOFF.md` if working on anything CI/tooling-related. Phase 3 needs a specification and explicit user authorization before any implementation starts.
