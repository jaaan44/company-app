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

These commands are **not yet established** in this repository (no Laravel or Flutter project exists yet as of Phase 0). Do not invent or run commands the repository does not yet support.

Once the respective projects are bootstrapped (Phase 1 onward), expect and wire up:

**Laravel (backend/API + Admin Backoffice):**
- Laravel Pint (code style)
- PHPStan / Larastan (static analysis)
- PHPUnit / Pest (automated tests)
- `composer validate` (composer.json integrity)

**Flutter (staff mobile app):**
- `dart format`
- `flutter analyze`
- `flutter test`

Update this section once these tools are actually wired into the repository, and record the exact commands here so future sessions don't have to guess.

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

Backend (`backend/` or similar), admin frontend, and mobile app (`mobile/` or similar) directories do not exist yet. They will be created in Phase 1 (Project Bootstrap) and this section must be updated at that point.
