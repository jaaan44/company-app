# CLAUDE.md — Operating Manual for AI Sessions

This file governs how any Claude (or other AI) session must work in this repository. It is authoritative. If conversation history conflicts with this file or with repository documentation, **the repository wins**.

Company App is developed through **explicitly authorized, numbered phases**. Never assume you are authorized to implement anything beyond the phase the user has explicitly named for this session.

## 1. Startup Protocol

Before doing any work, read in this order:

1. `CLAUDE.md` (this file)
2. `docs/CURRENT_STATE.md` — what phase is active, what's done, what's pending
3. `docs/ROADMAP.md` — full phase sequence and dependencies
4. The active phase specification in `docs/phases/`
5. Whichever of the technical docs are relevant to the work (`docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/06_UI_UX_GUIDELINES.md`)
6. The most recent handoff in `docs/handoffs/` **only if directly relevant** to the current task

Then, before modifying any existing code, **inspect it**. Do not assume something is missing, unimplemented, or broken merely because a prompt implies it — check the repository first.

If asked to work without a phase being specified, stop and ask which phase is authorized, or propose the next roadmap phase and get explicit confirmation before starting.

## 2. Source of Truth

- Repository state and documentation override assumptions carried over from conversation memory or prior sessions.
- If `docs/CURRENT_STATE.md` says something is not yet implemented, believe it over any impression from chat history.
- If code contradicts documentation, treat the code as reality, flag the discrepancy, and update the documentation as part of the current phase's work.

## 3. Scope Control

Implement **only** the authorized phase.

Do NOT:
- Implement future phases, even partially, "while you're in there"
- Perform unrelated refactors
- Introduce speculative infrastructure, abstractions, or config for features not yet in scope
- Change unrelated behavior
- Silently expand requirements beyond what the phase specification states

If you discover necessary work outside the current phase's scope, record it (in the phase handoff, under "Known issues/limitations" or as a roadmap note) instead of doing it.

## 4. Existing Implementation

Never assume something is missing merely because a prompt requests it or describes it as new. Search the codebase first. Company App is still pre-implementation as of Phase 0 — but this rule applies for the life of the project.

## 5. Quality Expectations

Established as of Phase 1 (Project Bootstrap), extended in Phase 2 (Development Environment & CI). **This section is the single authoritative source for validation commands** — the GitHub Actions workflows (`.github/workflows/backend-ci.yml`, `.github/workflows/mobile-ci.yml`) run exactly these commands, in this order. Run from each app's own directory. Before any commit/handoff, run the same commands CI will run — don't let local and CI checks drift apart.

**Laravel (`apps/api`) — Laravel 13.31.0, PHP 8.4 (requires `^8.3`):**
```sh
composer install --no-interaction --prefer-dist --no-progress
composer validate --strict
cp .env.example .env && php artisan key:generate   # first run / CI only — never commit .env
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test
```
Static analysis: Larastan (PHPStan for Laravel) v3, configured at `apps/api/phpstan.neon`, level 5 — see DEC-014 for rationale. Raise the level deliberately in a future phase as real business logic accumulates; don't lower it to make a failing check pass.

**Flutter (`apps/mobile`) — Flutter 3.47.2 stable, Dart 3.13.2:**
```sh
flutter pub get
dart format --output=none --set-exit-if-changed .
flutter analyze
flutter test
```

If a future phase changes these versions or commands, update this section — don't let it go stale.

## 6. Documentation Duties

Every implementation phase must update, at minimum:

- `docs/CURRENT_STATE.md`
- `docs/CHANGELOG.md`
- Any technical document (`02_ARCHITECTURE.md`, `03_DATABASE_MODEL.md`, `04_API_CONVENTIONS.md`, `05_SECURITY_MODEL.md`, `06_UI_UX_GUIDELINES.md`) whose subject matter the phase touched
- `docs/DECISIONS.md` if any new architectural/product decision was made or an old one superseded

...and must produce a phase handoff in `docs/handoffs/` following the standard in `docs/handoffs/README.md` (format defined during Phase 0).

## 7. Testing Discipline

Distinguish clearly, always, in every handoff and status update:

- **Implemented** — code exists
- **Tested automatically** — an automated test exists and passed
- **Manually verified** — a developer/AI ran it and checked the behavior
- **Awaiting UAT** — not yet confirmed by the product owner

Never mark a UAT entry in `docs/testing/UAT_LOG.md` as `PASS` on behalf of the product owner. Only the product owner (the user) can record a UAT `PASS`. An AI session may record `NOT RUN`, or log that a scenario is ready for UAT, but not assert it passed unless the user explicitly reports that they ran it and it passed.

## 8. Stop Discipline

After completing an authorized phase:

1. Update all required documentation (Section 6).
2. Write the phase handoff.
3. Report the result to the user.
4. **STOP.** Do not begin the next phase automatically, even if the roadmap makes the next step obvious. Wait for explicit authorization.

## 9. Repository Layout Reference

```
CLAUDE.md                      — this file
README.md                      — project overview / getting started
apps/
  api/                          — Laravel backend/API (+ future Admin Backoffice)
  mobile/                       — Flutter staff mobile app
docs/
  00_PROJECT_CHARTER.md
  01_PRODUCT_REQUIREMENTS.md
  02_ARCHITECTURE.md
  03_DATABASE_MODEL.md
  04_API_CONVENTIONS.md
  05_SECURITY_MODEL.md
  06_UI_UX_GUIDELINES.md
  ROADMAP.md
  CURRENT_STATE.md              — read this first for "where are we"
  DECISIONS.md                  — decision ledger (DEC-XXX)
  CHANGELOG.md
  phases/                       — per-phase specifications
  handoffs/                     — per-phase handoff reports
  testing/
    TEST_PLAN.md
    TEST_STATUS.md
    UAT_LOG.md
```

`apps/api` and `apps/mobile` were created in Phase 1 (Project Bootstrap) as clean, minimal application shells — no business modules yet. See `docs/phases/V1_PHASE_01_DEFINITION.md` and `docs/handoffs/V1_PHASE_01_HANDOFF.md`.
