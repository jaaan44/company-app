# Phase 30A — Operations: Schedule (Mobile) — Handoff

**Status: implementation complete and merged (PR #73, `0e7fce6`); UAT preparation runbook written — pending staging deployment and product-owner UAT. Phase 30A is NOT formally closed.** See the addendum at the end. No later step is started or authorized.

## 1. Phase Identification

- **Phase:** 30A — Schedule, the first sub-phase of Phase 30 — Operations: Schedule & Check-in (Mobile) (R-1).
- **Date:** 2026-10-12
- **Specification:** `docs/phases/V1_PHASE_30_DEFINITION.md` §5 (revision 1: R-1…R-12 approved as written), merged via PR #72 as `84bd25f` — the **authoritative specification baseline**.
- **Branch:** `claude/amazing-brahmagupta-dbsrjc`, restarted from exactly `84bd25f` for implementation.
- **Gates (all on the one branch, no history rewritten):**
  - Gate 1, backend: `b897cf0`
  - Gate 2, mobile data and state: `4dab362`
  - Gate 3, screens, routes and Home's entry links: `9e964ec`
  - Gate 4, final integration review, this handoff and the implementation PR: the final commit
- **Pull request:** the Phase 30A implementation PR (`claude/amazing-brahmagupta-dbsrjc` → `main`) is opened in Gate 4. It is **not merged**.

## 2. Objective

Give staff their own week on the phone — meetings, tasks due and approved leave, in company time — and let them open a meeting's details, read-only; and fix `/schedule`'s day boundaries.

## 3. Scope Implemented

- **Backend:** `GET /api/v1/me/schedule` (R-2…R-7); company-day boundaries for `GET /schedule` (R-5).
- **Mobile:** the Schedule tab (a seven-day agenda from the company today, Previous/Next/Today, pull to refresh), a read-only entry detail, and Home's Today meeting rows opening it (R-8…R-10).
- **Approved decisions honoured:**
  - R-1: 30A only; nothing from 30B.
  - R-2/R-3: my entries (creator or participant), my open tasks due, my approved leave; no milestones; never widened by any role.
  - R-4: company-day boundaries (the company day sits in `data`, see §12).
  - R-5: `/schedule` fixed, regression-tested at 07:00 and 23:30 Manila.
  - R-6: ≤ 31 days, unpaginated, total order, constant query count.
  - R-7: `items: null` and the no-profile message.
  - R-8: the week agenda as specified.
  - R-9: read-only.
  - R-10: task → Tasks tab; entry → detail; leave not tappable; Home's entry rows → detail.

## 4. Implementation Summary

- **Gate 1:** `MyScheduleController` (three queries with eager loads; PHP-side total order with byte-wise titles); `ScheduleController` compares against `CompanyTimezone` day bounds.
- **Gate 2:** strict models; `groupByDay` with end-exclusive company days; `MyScheduleController` learns the company week from one padded request (the device clock never labels a day), with one exact follow-up only if the guess misses; stale responses dropped; `ScheduleEntryController`.
- **Gate 3:** `SchedulePage`, `ScheduleEntryPage`, routes under the Schedule branch; Home's entry rows tappable; `retry()` so Try again reloads the week that failed.
- **Gate 4:** full gates; contract parity against real Laravel output; one review fix (refresh after task changes); docs, DEC-057, UAT rows and this handoff.

## 5. Files Changed

- **Backend (`apps/api`):**
  - New: `app/Http/Controllers/Api/V1/Scheduling/MyScheduleController.php`; tests `tests/Feature/Api/V1/Scheduling/MyScheduleTest.php`, `ScheduleCompanyDayTest.php`.
  - Changed: `app/Http/Controllers/Api/V1/Scheduling/ScheduleController.php` (day bounds), `routes/api/v1.php` (one route).
- **Mobile (`apps/mobile`), new:**
  - `lib/features/schedule/{domain/schedule.dart, data/schedule_api_client.dart, state/my_schedule_controller.dart, state/schedule_entry_controller.dart, presentation/schedule_page.dart, presentation/schedule_entry_page.dart, presentation/schedule_widgets.dart}`
  - Tests: `test/features/schedule/{schedule_models_test, schedule_state_test, schedule_pages_test}.dart`, `test/support/schedule_fixtures.dart`
- **Mobile, changed:** `lib/app/app.dart` (injectable `ScheduleApiClient`), `lib/app/router.dart` (`/schedule` and `/schedule/entries/:publicId`), `lib/features/home/presentation/home_page.dart` (entry rows tappable), `lib/features/shell/presentation/placeholder_page.dart` (`SchedulePlaceholderPage` removed), `test/app/router_test.dart`, `test/features/home/home_page_test.dart`.
- **Docs:** see §15.

## 6. Database/Schema Changes

None. No migration (still 45).

## 7. API Changes

- **New:** `GET /api/v1/me/schedule?from=YYYY-MM-DD&to=YYYY-MM-DD` → `data: {company_day: {date, timezone, utc_offset}, range: {from, to}, items}`; `422` for a bad date, `to < from` or more than 31 days. Leave items are titled with their leave type's name.
- **Changed behaviour:** `GET /api/v1/schedule` reports items on their **company** days (an entry at 07:00 Manila is no longer on the previous day). Scope, sources, shape and parameters unchanged; a datetime `from` still means its date.

## 8. Authorization/Security Changes

No permission, policy or visibility change. `/me/schedule` is self-scoped for every role and returns a subset of what the requester already sees; `/schedule`'s visibility is unchanged. The entry detail uses the unchanged `/schedule-entries/{id}` (a `404` for an entry the reader can't see), and a `403`/`404` never ends the session (the Phase 27 rule). See DEC-057 and `05_SECURITY_MODEL.md` (My Schedule).

## 9. Tests Added or Changed

- **Backend (+23):** `MyScheduleTest` 19 (sources, exclusions, self-scope per role, Manila boundaries, order, range validation, no profile, query count); `ScheduleCompanyDayTest` 4 (the `/schedule` regression, shown failing against the old controller).
- **Flutter (+40):**
  - models 10; API client and controllers 11 (including `retry()`);
  - pages 18 through the whole app (agenda, labels, weeks, multi-day items, refresh, states, `401`, task/entry/leave taps, entry detail, `404`, deep link, refresh after a task change, dark mode and 200% text on a small phone, tap-target guidelines);
  - Home 1 new (entry row → detail in the Schedule tab).
- **Changed (3):** router (Schedule renders `SchedulePage`); Home's two interactivity tests (entry rows now tappable).
- **Mutation checks:** Gate 1 12/12 (after strengthening two tests), Gate 2 12/12, Gate 3 19/20 (the 20th, `push` vs `go` for the entry route, is equivalent), Gate 4 2/2.

## 10. Commands/Checks Executed

- `apps/api`: `composer validate --strict`, `composer audit --locked`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test`.
- `apps/mobile` (Flutter 3.47.2 / Dart 3.13.2): `flutter pub get`, `dart format --output=none --set-exit-if-changed .`, `flutter analyze`, `flutter test` (and per area).
- **Contract parity (Gate 4, temporary, not committed):** 9 real responses recorded by a scratch Laravel test and replayed through the production app code by a scratch Flutter test — 5/5. Details in `TEST_STATUS.md` (Phase 30A — Gate 4). Both files deleted.

## 11. Results (Gate 4 final run)

| Check | Result |
|---|---|
| `composer validate --strict` / `composer audit --locked` | Valid; no advisories |
| Pint / PHPStan level 5 | Pass; 0 errors |
| `php artisan test` | **1,206/1,206** (3,486 assertions) |
| `dart format` / `flutter analyze` | 0 files changed; no issues |
| `flutter test` | **513/513** (schedule 39, home 67, tasks 95, work_logs 91, projects 52, people 84, and the rest) |
| `pubspec.yaml` / `pubspec.lock` | Unchanged |
| Contract parity | 5/5 |

## 12. Deviations from Specification

- **`company_day` in `data`, not `meta`** (R-4's wording): R-7's `/me/tasks` pattern puts it in `data`, and nothing is paginated. Recorded at Gate 1 and in DEC-057.
- **Leave titles** are the leave type's name ("Vacation leave"), not `/schedule`'s generic "Leave": it is the person's own record.
- **Entry rows in the Schedule tab `push`** the detail (Back keeps the week with no new request); Home's entry rows `go` to it, as §5.3 says.
- **Added at Gate 4 (not in the spec):** a quiet refresh after a task status change, matching Home, so the agenda isn't stale after a task is completed from it.

## 13. Known Issues/Limitations

- The offset at the start of the company today is used for the whole window; a DST switch inside a week would shift times by an hour (Asia/Manila has none — the same limitation as Home).
- An entry detail opened from a restored deep link (no route arguments) shows device time and says "(device time)".
- The agenda doesn't roll forward by itself at midnight; Today or a pull does it.
- Not verified here: MySQL (staging's `verify` will exercise the queries) and a real device.
- Carried forward, untouched: `per_page`/`q` hardening on legacy endpoints and the in-memory aggregation inside `/schedule` (Phase 36); debug signing (Phase 38); the deferred UAT password rotation from 29B; the UAT27/28/29A/29B/29C staging data.

## 14. Manual/UAT Testing Instructions

A separately authorized UAT30A runbook will give the exact steps. Needed for UAT-30A-01…08:
- **Staging:** redeploy to the 30A merge (no migration; caches rebuilt); smoke `/me/schedule` → `401` without a token.
- **APK:** built from the merge; expected Flutter 513 (schedule 39).
- **Data (dedicated `uat30a.*` accounts), dated relative to the company day:** a Staff member with a meeting today at 07:00 Manila (UAT-30A-03), a timed meeting later today, an overnight or multi-day entry, an all-day entry, an entry they only participate in, open tasks due this week and next, a completed task (must not show), approved leave over two days and pending leave (must not show); a colleague's private entry (must not show); a Manager and an Administrator, each with their own items and a report's or colleague's items that must not show (UAT-30A-06); a no-profile account.
- UAT-30A-07 needs the usual offline toggle and a `revoke` stage.

## 15. Documentation Updated

- `docs/phases/V1_PHASE_30_DEFINITION.md`: status; Gate 1–4 notes.
- `docs/02_ARCHITECTURE.md` §38; `docs/04_API_CONVENTIONS.md` (Gate 1); `docs/05_SECURITY_MODEL.md` (My Schedule); `docs/06_UI_UX_GUIDELINES.md` (Schedule).
- `docs/DECISIONS.md`: DEC-057.
- `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/ROADMAP.md`.
- `docs/testing/TEST_STATUS.md` (Gates 1–4), `docs/testing/UAT_LOG.md` (UAT-30A-01…08 `NOT RUN`).
- This handoff.

## 16. Recommended Next Step

1. Review the Phase 30A implementation PR and its CI (Backend CI and Mobile CI both run).
2. On approval, merge.
3. Prepare a UAT30A runbook (staging redeploy, APK provenance, data script) — a separately authorized documentation step.
4. Deploy to staging and seed the UAT data (operator).
5. The product owner runs UAT-30A-01…08.
6. Formally close 30A; then the 30B revision.

Nothing further begins until explicitly authorized (`CLAUDE.md` §8).

**Status summary:**
- **Implemented:** yes.
- **Tested automatically:** yes, backend and mobile, plus a real-output contract parity check.
- **Manually verified on a device:** no.
- **Awaiting UAT:** yes (UAT-30A-01…08 `NOT RUN`).
- **Deployed:** no.

## Addendum — Merge and UAT Preparation Runbook (2026-10-12)

- **Merged:** PR #73 merged into `main` as `0e7fce6afd243ecb27cec3123214cd8fbb8e1a20`, a standard merge commit. Backend CI and Mobile CI passed on the PR head (`ce062e8`) before the merge. This is the source for both the staging deployment and the final UAT APK.
- **Runbook:** `docs/testing/PHASE_30A_UAT_PREPARATION.md`. Each step states where to run it, the exact commands, what it does, the expected output and what to paste back (never a password):
  - §2: the staging redeploy from `c79ed90` (no migration; the route cache rebuilt for the new route; a `route:list` check), with the 29C backup correction (check `-- Dump completed`, never compare sizes) and smoke tests for `/me/schedule`, `/schedule` and `/schedule-entries`;
  - §3: the APK build (always run) and provenance, expected counts 513 / schedule 39 / home 67 / tasks 95, no location permission, plus `adb install`;
  - §4–§5: the UAT30A data plan and steps (extract and SHA, `plan`, `seed`, `verify`, `exposure`, a re-`seed` that re-dates everything);
  - §6: per-scenario UAT notes, including the `revoke` stage for UAT-30A-07;
  - §7: the script `uat30a_data.php`, SHA-256 `50187156dbda9a3fa1f76f198b76c75c808e1522a0656731e9a1eb39ba13676b`.
- **Data:** four `uat30a.*` accounts (staff, manager, administrator, no profile) and a colleague without an account; Rosa and Noel report to Ben. Ten entries dated from the company day (a 07:00 Manila meeting, an overnight entry, a two-day all-day event before a timed one, next- and previous-week entries, a colleague's private entry, the manager's and admin's own), five tasks (one completed, one a report's), and three leave requests on an inactive `UAT30A Vacation leave` type (one pending, one a report's). `verify` prints each week as the app lays it out and checks it exactly for every role.
- **Rehearsal:** on scratch SQLite only, including drift-then-reseed, refusals, the all-or-nothing `rotate`, a non-UAT data snapshot and the runbook's own extraction path. Results are in `TEST_STATUS.md`. Its `verify` stage is the first MySQL check of the 30A queries.
- **Status:** nothing is deployed or seeded on staging. UAT-30A-01…08 remain `NOT RUN`. Phase 30A is not closed.
