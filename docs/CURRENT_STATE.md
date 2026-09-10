# CURRENT STATE

*Read this first. Kept intentionally short — for depth, follow the pointers, don't expect this file to contain everything.*

**Product:** Company App — internal operations & communication platform
**Current phase:** Phase 3 — Core Architecture
**Phase status:** COMPLETE (pending user review)
**Last completed phase:** Phase 3 (Phases 1–2 are merged into `main`)
**Next planned phase:** Phase 4 — Authentication (see `ROADMAP.md`) — **not authorized yet**

## Current Objective

Awaiting review of Phase 3 and authorization for Phase 4.

## Completed

- **Phase 0:** Full documentation/governance foundation.
- **Phase 1:** Monorepo bootstrap — `apps/api` (Laravel) and `apps/mobile` (Flutter). Merged into `main`.
- **Phase 2:** Development environment & CI (Larastan, GitHub Actions). Merged into `main`.
- **Phase 3:** Core architecture. See `docs/handoffs/V1_PHASE_03_HANDOFF.md` for full detail.
  - Backend: `/api/v1` routing implemented (`routes/api.php` → `routes/api/v1.php`), `GET /api/v1/health` endpoint with automated tests. `livewire/livewire` (^4.4) installed as the confirmed Admin Backoffice foundation — no Admin pages yet.
  - Database: **MySQL is now the production direction** (DEC-016); SQLite remains local/test-only.
  - Identifiers: numeric internal PK + ULID `public_id` for external entities, documented (DEC-017) — no business migrations.
  - Mobile: restructured into `lib/app/`, `lib/core/config/`, `lib/features/home/` — no routing package or state-management framework added.
  - All local checks pass except `vendor/bin/phpstan analyse` (same session-specific limitation as Phase 2 — see Known Blockers); **confirmed passing via GitHub Actions** (draft PR #3, commit `6308c4a`) before handoff.
  - No business functionality in either app.

## Pending / Not Started

- Authentication (Phase 4) and everything after it on the roadmap.

## Known Blockers / Issues

- **Session-specific, resolved via CI each time:** this sandboxed session's GitHub API access is scoped to `jaaan44/company-app` only. `phpstan/phpstan` (dist-only) can't be freshly fetched here, and because it's now a permanent dependency, it also blocks the local checkout step for *any other newly-added* composer package in the same session (this phase: `livewire/livewire`). `composer.json`/`composer.lock` are always correctly resolved; GitHub Actions (unrestricted network) is the real verification. See the Phase 3 handoff for this phase's confirmed CI run.
- Open design questions: real-time transport, object storage provider, Departments/Teams hierarchy shape — see `docs/02_ARCHITECTURE.md` §9.

## Repository / Branch Information

- Repository: `jaaan44/company-app`
- Default branch: `main` (contains the approved Phase 0–2 baseline)
- Phase 3 branch: `claude/v1-phase-03-core-architecture` (branched from `main`, not merged)

## Latest Relevant Handoff

`docs/handoffs/V1_PHASE_03_HANDOFF.md`

## For the Next Session

Read `CLAUDE.md`, then this file, then `docs/ROADMAP.md`, then `docs/handoffs/V1_PHASE_03_HANDOFF.md` if working on anything architecture-related. Phase 4 needs a specification and explicit user authorization before any implementation starts.
