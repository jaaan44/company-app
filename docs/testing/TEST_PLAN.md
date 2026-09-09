# TEST PLAN

Testing strategy for Company App. This document defines *how* testing is approached; actual pass/fail state lives in `TEST_STATUS.md` (automated/manual) and `UAT_LOG.md` (product owner acceptance).

## Testing Levels

### 1. Automated Testing
Executed by CI and/or developers/AI sessions as part of implementation.

- **Backend (Laravel):** PHPUnit/Pest feature and unit tests. Feature tests cover API endpoints (authorization, validation, happy path, key edge cases). Unit tests cover non-trivial business logic (e.g. leave balance calculation, permission resolution).
- **Mobile (Flutter):** `flutter test` — widget tests for key screens/flows, unit tests for business logic (state management, API client behavior).
- Every phase that adds business logic or endpoints is expected to add corresponding automated tests as part of that phase, not as separate follow-up work, unless explicitly deferred and recorded in that phase's handoff under "Known issues/limitations."

### 2. Manual Verification
Developer/AI-performed manual checks that aren't automated (e.g. visual/UX checks, exploratory testing of a new flow, browser/device spot-checks). Recorded in `TEST_STATUS.md` with what was checked and the outcome — not just "looks good."

### 3. User Acceptance Testing (UAT)
Performed **only by the product owner** (the user). Recorded in `UAT_LOG.md`. An AI session may prepare UAT scenarios and mark them ready, but must never record a `PASS` on the product owner's behalf.

## Status Values

Used consistently across `TEST_STATUS.md` and `UAT_LOG.md`:

- `NOT RUN` — not yet executed
- `PASS` — executed and passed
- `FAIL` — executed and failed
- `BLOCKED` — cannot be executed yet (dependency missing, environment issue, etc.)

## Scope by Phase

Each phase's specification (`docs/phases/`) should state what testing is expected for that phase's scope. As a default:

- Phases 1–2 (Bootstrap, CI): verify the tooling itself runs (e.g. `composer test`, `flutter test` execute cleanly on a trivial/example test), not business logic.
- Phases 3 onward: automated tests for new endpoints/business logic; manual verification for anything UI-related that can be exercised (see the `run` skill / dev-server verification guidance in `CLAUDE.md`); UAT scenarios drafted for product-owner-facing behavior once there's something to look at.

## Current Status

No automated test suite exists yet (no Laravel/Flutter project exists — see `docs/CURRENT_STATE.md`). This plan takes effect starting Phase 1.
