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
| UAT-04A-01 | Phase 4A — Docker Development Environment | Windows: `docker compose up -d --build` brings up all three containers, `mysql` healthy | **FAIL** (as reported) | 2026-09-11 | `f13de5a` | Product owner reported (Windows/PowerShell): `app` container failed with `exec /usr/local/bin/entrypoint.sh: no such file or directory` — root cause was a missing `.gitattributes` allowing `core.autocrlf=true` to check `docker/php/entrypoint.sh` out as CRLF. Fixed at `ed276fa` (`.gitattributes` added). Product owner's own workaround (manually converting the file to LF) confirmed all three containers then start and MySQL reports healthy. **Re-verification on Windows against the fixed commit is invited** — this AI session cannot itself confirm a Windows result and has not marked this PASS; see `docs/handoffs/V1_PHASE_04A_HANDOFF.md` §26 for this session's own (Linux-sandbox, simulated-checkout) verification of the fix. |

## Test ID Convention

`UAT-<phase#>-<sequence>`, e.g. `UAT-07-01` for the first UAT scenario in the Staff phase (Phase 7).
