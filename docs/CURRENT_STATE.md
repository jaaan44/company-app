# CURRENT STATE

*Read this first. Kept intentionally short — for depth, follow the pointers, don't expect this file to contain everything.*

**Product:** Company App — internal operations & communication platform
**Current phase:** Phase 1 — Project Bootstrap
**Phase status:** COMPLETE (pending user review)
**Last completed phase:** Phase 1
**Next planned phase:** Phase 2 — Development Environment & CI (see `ROADMAP.md`) — **not authorized yet**

## Current Objective

Awaiting review of Phase 1 and authorization for Phase 2.

## Completed

- **Phase 0:** Full documentation/governance foundation (`docs/`, `CLAUDE.md`, `README.md`).
- **Phase 1:** Monorepo structure established — `apps/api` (Laravel) and `apps/mobile` (Flutter). See `docs/handoffs/V1_PHASE_01_HANDOFF.md` for full detail.
  - `apps/api`: Laravel 13.31.0 on PHP 8.4.19. Boots; framework-default migrations only (users/cache/jobs); `composer validate --strict`, `vendor/bin/pint --test`, `php artisan test` all pass.
  - `apps/mobile`: Flutter 3.47.2 / Dart 3.13.2. Minimal neutral shell (demo counter removed, no business screens); `dart format`, `flutter analyze`, `flutter test` all pass.
  - No business functionality in either app.

## Pending / Not Started

- Development Environment & CI (Phase 2) and everything after it on the roadmap.
- PHPStan/Larastan (deferred to Phase 2, per `CLAUDE.md` §5).

## Known Blockers / Issues

None blocking. Notable environment detail: the Flutter/Dart SDK is not preinstalled in this session's container and was installed to `/opt/flutter` to run Phase 1's validation — this is a session detail, not a repository dependency (nothing in the repo assumes that path). Open design questions (DB engine, Admin Backoffice rendering approach, real-time transport, object storage) remain deliberately deferred — see `docs/02_ARCHITECTURE.md` §9.

## Repository / Branch Information

- Repository: `jaaan44/company-app`
- Default branch: `main` (contains the approved Phase 0 baseline)
- Phase 1 branch: `claude/v1-phase-01-project-bootstrap` (branched from `main`, not merged)

## Latest Relevant Handoff

`docs/handoffs/V1_PHASE_01_HANDOFF.md`

## For the Next Session

Read `CLAUDE.md`, then this file, then `docs/ROADMAP.md`, then `docs/handoffs/V1_PHASE_01_HANDOFF.md` if working on anything bootstrap-related. Phase 2 needs a specification and explicit user authorization before any implementation starts.
