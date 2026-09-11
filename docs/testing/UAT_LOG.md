# UAT LOG

User Acceptance Testing performed by the product owner. **Only the product owner may record a `PASS` or `FAIL` here.** An AI session may add a row in `NOT RUN` state once a scenario is ready for review, or update `Notes`/`Build` fields, but must never assert an outcome on the product owner's behalf.

Status values: `NOT RUN` · `PASS` · `FAIL` · `BLOCKED`

| Test ID | Phase/Module | Scenario | Result | Date | Build/Commit | Notes |
|---|---|---|---|---|---|---|
| — | Phase 0 | N/A — no user-facing functionality exists yet | — | — | — | UAT begins once there is a working feature to review; expected no earlier than the first Company Core phase (Phase 6+), with a dedicated UAT phase (Phase 25) before release. |
| UAT-04-01 | Phase 4 — Authentication | Admin: sign in with valid credentials, reach the placeholder home, sign out | **PASS** | 2026-09-11 | `6e7f395` | Product owner tested on Windows against the Dockerized backend: valid Admin login redirects to protected `/home`; Admin logout works. Reported directly by the product owner. |
| UAT-04-02 | Phase 4 — Authentication | Admin: a suspended/inactive or non-admin account cannot enter the Admin Backoffice | NOT RUN | — | — | Not part of this UAT round — ready for review. |
| UAT-04-03 | Phase 4 — Authentication | Mobile: sign in with valid credentials, app restores session on restart, sign out returns to login | **PASS** | 2026-09-11 | `6e7f395` | Product owner tested on Windows, Flutter against the Dockerized API: sign-in succeeds; authenticated state is restored after completely closing and reopening the app; logout returns to the login screen. Reported directly by the product owner. |
| UAT-04-04 | Phase 4 — Authentication | Mobile: invalid credentials show a clear, non-crashing error and the app remains unauthenticated | **PASS** | 2026-09-11 | `6e7f395` | Product owner tested on Windows: an invalid password produces a clean error, app stays unauthenticated. Reported directly by the product owner. *(Scope narrowed from the original combined wording — see UAT-04-05 for the untested half.)* |
| UAT-04-05 | Phase 4 — Authentication | Mobile: a simulated network failure shows a clear, non-crashing error | NOT RUN | — | — | Not part of this UAT round — ready for review. Automated coverage exists (`auth_controller_test.dart`'s network-failure test), but that is not a substitute for product-owner UAT. |
| UAT-04-06 | Phase 4 — Authentication | Admin: direct `/home` access while logged out redirects to `/login` | **PASS** | 2026-09-11 | `6e7f395` | Product owner tested on Windows against the Dockerized backend. Reported directly by the product owner. |
| UAT-04A-01 | Phase 4A — Docker Development Environment | Windows: `docker compose up -d --build` brings up all three containers, `mysql` healthy | **PASS** | 2026-09-11 | `6e7f395` | Originally **FAIL** (`exec /usr/local/bin/entrypoint.sh: no such file or directory` — CRLF checkout, see `docs/handoffs/V1_PHASE_04A_HANDOFF.md` §26). Fixed at `ed276fa`. Product owner has now retested on Windows against the fixed commit and confirmed all three containers start successfully with `mysql` healthy. Reported directly by the product owner. |
| UAT-04A-02 | Phase 4A — Docker Development Environment | Windows: first-time setup inside Docker — `composer install`, `php artisan key:generate`, migrations against Docker MySQL, `AdminUserSeeder` | **PASS** | 2026-09-11 | `6e7f395` | Product owner tested on Windows: all four steps succeeded against the Dockerized MySQL. Reported directly by the product owner. |
| UAT-04A-03 | Phase 4A — Docker Development Environment | Windows: `/api/v1/health` and Admin `/login` both load through Nginx | **PASS** | 2026-09-11 | `6e7f395` | Product owner tested on Windows. Reported directly by the product owner. |
| UAT-05-01 | Phase 5 — Roles & Permissions | Admin: sign in with the seeded local Admin account (now Administrator-role-based, not `is_admin`) and reach `/home` exactly as before | NOT RUN | — | — | Not part of this UAT round — ready for review. No visible UI change is expected; this confirms the retirement of `is_admin` didn't regress the existing login flow. |
| UAT-05-02 | Phase 5 — Roles & Permissions | Admin: a Manager- or Staff-role account (no `admin.access` permission) cannot enter the Admin Backoffice | NOT RUN | — | — | Not part of this UAT round — ready for review. No dedicated UI exists yet to create such an account manually; automated coverage exists (`tests/Feature/Authorization/AdminBackofficeAuthorizationTest.php`). |

## Test ID Convention

`UAT-<phase#>-<sequence>`, e.g. `UAT-07-01` for the first UAT scenario in the Staff phase (Phase 7).
