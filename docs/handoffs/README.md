# Handoff Standard

Every implementation phase produces a handoff document in this directory, named `V1_PHASE_<NN>_HANDOFF.md` (zero-padded phase number, e.g. `V1_PHASE_01_HANDOFF.md`).

## Required Sections

1. **Phase Identification** — phase number, name, date.
2. **Objective** — what this phase was authorized to do.
3. **Scope Implemented** — what was actually built, mapped to the objective.
4. **Implementation Summary** — how it was built, key structural decisions made.
5. **Files Changed** — list or summary of files added/modified/removed.
6. **Database/Schema Changes** — migrations added, schema impact.
7. **API Changes** — endpoints added/changed.
8. **Authorization/Security Changes** — permissions, policies, or security-relevant changes.
9. **Tests Added or Changed** — what automated tests were added.
10. **Commands/Checks Executed** — exact commands run (lint, static analysis, tests, etc.).
11. **Results** — output/outcome of those commands.
12. **Deviations from Specification** — anything implemented differently than the phase spec, and why.
13. **Known Issues/Limitations** — anything intentionally deferred or incomplete.
14. **Manual/UAT Testing Instructions** — how the product owner or a developer can verify this phase manually.
15. **Documentation Updated** — which docs were touched (should include `CURRENT_STATE.md` and `CHANGELOG.md` at minimum).
16. **Recommended Next Step** — the proposed next phase, without assuming authorization to start it.

## Discipline

- Clearly distinguish **implemented** vs. **tested automatically** vs. **manually verified** vs. **awaiting UAT** for every claim of "done."
- Never record a UAT `PASS` on behalf of the product owner — see `docs/testing/UAT_LOG.md`.
- A handoff is written when a phase is complete, not partway through. If a phase is abandoned or paused mid-way, note that explicitly rather than writing a handoff that implies completion.
