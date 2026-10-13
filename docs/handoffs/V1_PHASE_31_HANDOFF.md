# Phase 31 — HR: Leave Management (Mobile) — Handoff

**Status: implementation complete (Gates 1–4); the PR is merged on green CI under DEC-059. Phase 31 is NOT formally closed:** it waits for the combined staging deployment and UAT round (30B + 31–33, DEC-059).

## 1. Phase Identification

- **Phase:** 31 — HR: Leave Management (Mobile).
- **Date:** 2026-10-12
- **Specification:** `docs/phases/V1_PHASE_31_DEFINITION.md` revision 1. Decisions R-1…R-12 were approved under the product owner's blanket authorization (DEC-059). R-3 was revised in Gate 2, as noted in the spec.
- **Branch:** `claude/amazing-brahmagupta-dbsrjc`, from `main` at `fa52d42`.
- **Gates (one branch, no history rewritten):**
  - Gate 1, backend: `a557c5c`, plus the R-3 revision `2908444`;
  - Gate 2, mobile data and state: `57c7e6c`;
  - Gate 3, screens and routes: `9a7dc11`;
  - Gate 4, review, parity and docs: the final commit.

## 2. Objective

Let staff see their leave balances and requests, request and cancel leave from the phone, and let Managers approve or reject their direct reports' requests.

## 3. Scope Implemented

R-1…R-12 as specified, with one revision (§12):
- More → Leave;
- the backend company-day fixes and tie-breakers;
- the new team list;
- Request leave, a request's detail with Cancel, and Team requests with Approve and Reject;
- Schedule reloading after leave changes.

## 4. Implementation Summary

- **Backend:**
  - `App\Support\CompanyDay` (`meta()`, `year()`);
  - `LeaveBalanceController` defaults to the company year;
  - `MyLeaveRequestController` judges "started" by the company day and adds `meta.company_day`;
  - `id` tie-breakers on both request lists;
  - new `MyTeamLeaveRequestController` (`GET /me/team-leave-requests`).
- **Mobile `lib/features/leave/`:**
  - strict models and `LeaveApiClient`;
  - five controllers: overview, form, request (mine or team), team list and `LeaveChanges`;
  - four pages and shared widgets.
- **Wiring:**
  - five routes in the More branch;
  - the seventh More row;
  - `CompanyApp` injects `LeaveApiClient` and owns `LeaveChanges`;
  - `SchedulePage` listens to `LeaveChanges`;
  - `PagedSearchController.restart()` (a filter change never joins a stale first page).

## 5. Files Changed

- **Backend:**
  - `app/Support/CompanyDay.php` (new);
  - `app/Http/Controllers/Api/V1/Leave/{LeaveBalanceController, LeaveRequestController, MyLeaveRequestController}.php`;
  - `.../Leave/MyTeamLeaveRequestController.php` (new);
  - `routes/api/v1.php`;
  - tests `tests/Feature/Api/V1/Leave/{LeaveCompanyDayTest, MyTeamLeaveRequestTest}.php` (new).
- **Mobile:**
  - `lib/features/leave/**` (new: 1 domain, 1 data, 5 state and 5 presentation files);
  - `lib/app/{app,router}.dart`;
  - `lib/core/state/paged_search_controller.dart`;
  - `lib/features/people/presentation/more_page.dart`;
  - `lib/features/schedule/presentation/schedule_page.dart`;
  - tests `test/features/leave/{leave_models_test, leave_state_test, leave_pages_test}.dart` and `test/support/leave_fixtures.dart` (new);
  - `test/features/people/people_pages_test.dart` (seven More rows).
- **Docs:**
  - the spec, DEC-059 and DEC-060;
  - `02_ARCHITECTURE.md` §40 (and §39's stale "not yet merged");
  - `04_API_CONVENTIONS.md`, `05_SECURITY_MODEL.md` and `06_UI_UX_GUIDELINES.md` (and 30B's stale heading);
  - `CURRENT_STATE.md`, `CHANGELOG.md`, `ROADMAP.md`, `TEST_STATUS.md`, `UAT_LOG.md`, and this handoff.

## 6. Database/Schema Changes

None. No migration, seeder or permission.

## 7. API Changes

- **Changed:**
  - `GET /me/leave-balances` and `/staff/{id}/leave-balances` default to the company year;
  - self-cancel of approved leave is refused from 00:00 company time on its first day (previously from 08:00 Manila);
  - `GET /me/leave-requests` and `/leave-requests` add an `id` tie-breaker, and `GET /me/leave-requests` adds `meta.company_day`.
- **New:** `GET /me/team-leave-requests?status=&per_page=&page=` (see `04_API_CONVENTIONS.md`).
- Every change is additive or a correction; no field was removed or renamed.

## 8. Authorization/Security Changes

None to authority.
- The team list is a subset of the existing supervisory visibility: direct reports only, Administrators included.
- Callers without `leave-requests.view` get an empty list with `can_review: false`, which reveals nothing.
- Approve and reject rules are unchanged.
- The cancel fix narrows the requester's power.

See `05_SECURITY_MODEL.md` "Leave on mobile".

## 9. Tests Added or Changed

- **Backend: 19 new tests.**
  - `LeaveCompanyDayTest` (10):
    - the company year at 07:00 Manila on 1 January, at 23:30 on 31 December, and with an explicit year;
    - self-cancel refused at 00:30 and 23:30 Manila on the first day, allowed the evening before, and pending allowed after the start;
    - `meta.company_day` with the paginator meta intact;
    - tie-breakers pinned in the SQL on both lists.
  - `MyTeamLeaveRequestTest` (9): scoping for Manager, Administrator, no reports, Staff (`can_review: false`) and no profile; counts; both orders with SQL pins; `all`; `422`; guests.
- **Mobile: 79 new tests.**
  - models (23);
  - state (34): client requests and bodies; overview (parallel reads, no profile, errors with no unhandled futures, load more, stale load-more dropped); form (defaults, company-day read, checks, balance hint, send once, `422` mapping, `409`, offline); request detail (mine and team, `409` reload, offline, `404`); team list (filter restart against a slow stale page);
  - pages (22): balances and rows, empty, no profile, offline, Show more, the form end to end with date pickers, checks, refusal banner, discard prompt, cancel with a reason, the approved-started rule, Team requests order and filters, approve with Schedule reload, reject needing a reason, decided elsewhere, a reviewer with no reports, dark and 200% text on a 320×568 phone, and tap-target and label guidelines.
- **Changed:** the More test now expects seven rows.
- **Mutation checks (each reverted):**
  - backend 15: 14 caught; the 15th, dropping `startOfDay()` before formatting the offset, is equivalent;
  - mobile 10: all caught, after two test gaps found by the first run were closed.

## 10. Commands/Checks Executed

The CLAUDE.md §5 commands for both apps, plus the mutation runs and the contract parity (§11).

## 11. Results (Gate 4 final run)

- **Backend:**
  - `composer validate --strict`: valid;
  - `composer audit --locked`: no advisories;
  - Pint: pass; PHPStan: 0 errors;
  - `php artisan test`: **1,240/1,240**.
- **Flutter:**
  - `dart format`: 0 changed; `flutter analyze`: no issues;
  - `flutter test`: **656/656** (leave 79).
- **Contract parity:** a scratch Laravel test (Asia/Manila, 07:00 on 14 Oct) recorded 16 real responses. A scratch Flutter test parsed them all through the production models and form controller, 3/3. Both scratch tests were deleted.
  - Responses recorded: balances, store, a `422` (reason and two years), the balance refusal, my list, show, cancel, the team list as a Staff member (`can_review: false`), the team list as Manager (pending soonest first), team show, approve, a second approve (`409`), reject, the `all` list, and the no-profile `403`.
  - **Finding:** an insufficient balance is a **`422` on `leave_type_id`**, not a `409`. The form already shows a `leave_type_id` error at the top, so nothing changed; the spec's wording was corrected.
- **Not run:**
  - MySQL: plain-column `ORDER BY` only; exercised at staging;
  - a real device: the combined UAT round.

## 12. Deviations from Specification

- **R-3 revised in Gate 2:** a caller without `leave-requests.view` gets `200` with an empty list and `can_review: false`, not `403`. The app asks on every Leave load and has no role information; a `403` would cost an `/auth/me` re-check every time. Recorded in the spec and DEC-060.
- **R-5 wording:** the balance refusal is a `422`, not a `409` (§11).
- **06 detail:** status chips sit beneath the row text, not beside it. The 200% text test found the trailing chip squeezed rows to over 1,600 px tall.

## 13. Known Issues/Limitations

- **No device run.** Date-picker locale and first-day-of-week follow the device.
- **No balance pre-check in the app:** the server's message is shown. The balance hint reflects the company year only.
- **A team request opened from a restored deep link** (no list) shows history without times. Lists always pass the company day.
- **Leave notifications** (a Manager learning of a request) don't exist in the API; Phase 32's inbox reads existing notifications only.
- **Out of scope (spec §6):** a Manager's own company-wide view, attachments, half days and editing a request.

## 14. Manual/UAT Testing Instructions

UAT-31-01…08 are in `docs/testing/UAT_LOG.md` (`NOT RUN`). They run in the combined round, whose runbook (one deploy, one APK, one data script) is written after Phase 33. It needs:
- a Manager with two direct reports;
- a Staff member with a paid balance and requests in each status;
- an approved request starting today and one starting in the future;
- an Administrator who manages nobody;
- a user without a profile.

## 15. Documentation Updated

See §5.

## 16. Recommended Next Step

Phase 32, under DEC-059, without a stop.

## Addendum — UAT and Formal Closure (2026-10-13)

- **Deployed:** staging `d6dd29a` and the APK built at `d6dd29a` (combined round, `docs/testing/COMBINED_UAT_PREPARATION.md`; preparation results in `docs/testing/TEST_STATUS.md`).
- **UAT-31-01…08: PASS**, reported by the product owner after running each scenario on a physical Android 12+ device against staging. Recorded by the AI on that report.
- **Phase 31 is formally closed.** UAT data stays on staging; any cleanup needs its own authorization.

