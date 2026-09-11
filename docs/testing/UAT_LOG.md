# UAT LOG

User Acceptance Testing performed by the product owner. **Only the product owner may record a `PASS` or `FAIL` here.** An AI session may add a row in `NOT RUN` state once a scenario is ready for review, or update `Notes`/`Build` fields, but must never assert an outcome on the product owner's behalf.

Status values: `NOT RUN` · `PASS` · `FAIL` · `BLOCKED`

| Test ID | Phase/Module | Scenario | Result | Date | Build/Commit | Notes |
|---|---|---|---|---|---|---|
| — | Phase 0 | N/A — no user-facing functionality exists yet | — | — | — | UAT begins once there is a working feature to review; expected no earlier than the first Company Core phase (Phase 6+), with a dedicated UAT phase (Phase 25) before release. |
| UAT-04-01 | Phase 4 — Authentication | Admin: sign in with valid credentials, reach the placeholder home, sign out | NOT RUN | — | — | Ready for review — see `docs/handoffs/V1_PHASE_04_HANDOFF.md` §Manual/UAT instructions. |
| UAT-04-02 | Phase 4 — Authentication | Admin: a suspended/inactive or non-admin account cannot enter the Admin Backoffice | NOT RUN | — | — | Ready for review. |
| UAT-04-03 | Phase 4 — Authentication | Mobile: sign in with valid credentials, app restores session on restart, sign out returns to login | NOT RUN | — | — | Ready for review. |
| UAT-04-04 | Phase 4 — Authentication | Mobile: invalid credentials and a simulated network failure both show a clear, non-crashing error | NOT RUN | — | — | Ready for review. |

## Test ID Convention

`UAT-<phase#>-<sequence>`, e.g. `UAT-07-01` for the first UAT scenario in the Staff phase (Phase 7).
