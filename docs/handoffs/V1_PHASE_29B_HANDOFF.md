# Phase 29B — Work: Work Logs (Mobile) — Handoff

**Status: COMPLETE — Phase 29B formally closed 2026-10-10.** Physical-device UAT-29B-01…08 all `PASS`; see the final addendum. Earlier status lines: *implementation complete — pending PR review/CI, merge, staging deployment and product-owner UAT*, then *merged (PR #65) and UAT runbook written*. Each addendum below was true when it was written. Phase 29C (Projects & Clients) is not started and not authorized.

## 1. Phase Identification

- **Phase:** 29B — Work logs, the second sub-phase of Phase 29 — Work: Tasks, Work Logs, Projects & Clients (Mobile) (R-1).
- **Date:** 2026-10-10
- **Specification:** `docs/phases/V1_PHASE_29_DEFINITION.md` §6 (revision 3: R-10…R-18 approved as written), merged via PR #64. That merge, `496bd5c`, is the **authoritative specification baseline**.
- **Branch:** `claude/amazing-brahmagupta-dbsrjc`, restarted from exactly `496bd5c` for implementation.
- **Gates (all on the one branch, no history rewritten):**
  - Gate 1, backend company-time "today", `/me/work-logs` tie-breaker and company day: `58e91ec`
  - Gate 2, mobile work-log data and state: `90dfb70`
  - Gate 3, work-log screens, "Log work" and routes: `733d0fc`
  - Gate 4, final integration review, this handoff and the implementation PR: the final documentation commit
- **Pull request:** the Phase 29B implementation PR (`claude/amazing-brahmagupta-dbsrjc` → `main`) is opened in Gate 4. It is **not merged**.

## 2. Objective

Let staff log their work time from the phone: see their own work logs by date, add one for a task or a project, edit or delete their own, and log work directly from a task. Fix the long-standing defect that rejected "today" every morning before 08:00 Manila time (R-8).

## 3. Scope Implemented

- **Backend:**
  - "No later than today" is the **company date** on all four work-log requests, self-service and Administrator (R-8/R-10).
  - `GET /api/v1/me/work-logs`: a unique tie-breaker (R-11) and an additive `meta.company_day` (R-12).
- **Mobile:**
  - More → **My work logs** (grouped by date; paging; Add).
  - The add/edit **form** (what-for picker, date picker, hours and minutes, description; discard and delete confirmations).
  - **"Log work"** on a task's detail.
- **Approved decisions honoured:**
  - R-10: the company "today" in all four requests.
  - R-11: the `/me/work-logs` tie-breaker.
  - R-12: `meta.company_day`.
  - R-13: no API change for a missing profile; the app shows the no-profile state from the `403`.
  - R-14: the picker offers my open tasks and my non-closed projects; no new endpoint.
  - R-15: entry from More and from a task (not cancelled tasks).
  - R-16: no day totals.
  - R-17: a 365-day date picker up to the company today; hours and minutes, 1 minute to 24 hours.
  - R-18: "Discard changes?" and "Delete this work log?".

**Excluded as specified:** day totals and reports, logging for other people, changing a log's task or project, timers, approvals, attachments, offline queues, Admin Backoffice screens, and 29C (projects and clients).

## 4. Implementation Summary

### Backend (`apps/api`)

- **`LimitsWorkDateToCompanyToday`** (new trait in `app/Http/Requests/WorkLogs/Concerns/`): `work_date` rules `date` + `before_or_equal:<OverdueTasks::todayInCompanyTimezone()>`, and the message "The work date cannot be later than today." Used by `StoreMyWorkLogRequest`, `UpdateMyWorkLogRequest`, `StoreWorkLogRequest` and `UpdateWorkLogRequest`, each keeping its own presence rules.
- **`MyWorkLogController::myIndex`:** `->orderByDesc('id')` after `work_date`/`created_at`, and `->additional(['meta' => ['company_day' => {date, timezone}]])` on the resource collection (the paginator's keys are unchanged).

### Mobile (`apps/mobile/lib/features/work_logs/`)

- **Domain:** `WorkLog` (strict; a log must have a task or a project), `MyWorkLogsPage` (with `companyDay`), `WorkTarget` (`TaskTarget` | `ProjectTarget`), `MemberProject` (closed = completed or cancelled), `WorkLogLimits`, `formatDuration`.
- **Data:** `WorkLogsApiClient` — the list (25 per page), the company day from a one-row page, `POST` with `task_id` or `project_id`, `PATCH` with only date, duration and description, `DELETE`, own staff id from `/me/profile`, and my projects from `/projects?member=` (all pages, at most 10).
- **State:**
  - `MyWorkLogsController`: the Phase 28/29A list rules; grouping by date across pages; a `403` after the session re-check is the no-profile state (R-13).
  - `WorkLogFormController`: create/edit; a fixed task from a task's detail; loads the company day (if not given) and the picker (my open tasks through `TasksApiClient`, my non-closed projects); checks before sending; confirmed, single-flight save and delete; server `422`s on the matching fields and eligibility errors at the top; a `404` treated as "gone"; dirty state.
  - `WorkLogChanges`: the session-wide record of confirmed saves and deletions; the list applies and refreshes.
- **Presentation:** `MyWorkLogsPage`, `WorkLogFormPage` (with a bottom-sheet picker, `showDatePicker`, `PopScope` discard check, `AlertDialog` delete), `work_log_widgets.dart` (route args, paths, day labels, date helpers).
- **Wiring:**
  - Routes `/more/work-logs`, `/more/work-logs/new`, `/more/work-logs/:publicId`, and `/tasks/:publicId/log-work`.
  - `CompanyApp` owns a `WorkLogsApiClient` (injectable) and `WorkLogChanges`.
  - More gains "My work logs"; the task detail gains "Log work".

## 5. Files Changed

38 files against `496bd5c` (including this handoff): 17 added, 21 modified, 0 deleted.

- **Backend implementation (6):**
  - `apps/api/app/Http/Requests/WorkLogs/Concerns/LimitsWorkDateToCompanyToday.php` (new)
  - `apps/api/app/Http/Requests/WorkLogs/{StoreMyWorkLogRequest,UpdateMyWorkLogRequest,StoreWorkLogRequest,UpdateWorkLogRequest}.php`
  - `apps/api/app/Http/Controllers/Api/V1/WorkLogs/MyWorkLogController.php`
- **Backend tests (2):** `apps/api/tests/Feature/Api/V1/WorkLogs/WorkLogCompanyDayTest.php` (new), `apps/api/tests/Feature/Api/V1/WorkLogs/WorkLogTest.php` (one test rewritten)
- **Mobile implementation (12):**
  - `lib/app/{app,router}.dart`
  - `lib/features/people/presentation/more_page.dart`
  - `lib/features/tasks/presentation/task_detail_page.dart`
  - `lib/features/work_logs/data/work_logs_api_client.dart`
  - `lib/features/work_logs/domain/work_log.dart`
  - `lib/features/work_logs/state/{my_work_logs_controller,work_log_form_controller,work_log_changes}.dart`
  - `lib/features/work_logs/presentation/{my_work_logs_page,work_log_form_page,work_log_widgets}.dart`
- **Mobile tests (7):**
  - `test/features/people/people_pages_test.dart` (More now has three rows)
  - `test/features/work_logs/{work_log_models_test,work_logs_api_client_test,my_work_logs_controller_test,work_log_form_controller_test,work_logs_pages_test}.dart`
  - `test/support/work_log_fixtures.dart`
- **Documentation (11, including this handoff):**
  - `docs/{02_ARCHITECTURE,04_API_CONVENTIONS,05_SECURITY_MODEL,06_UI_UX_GUIDELINES,CHANGELOG,CURRENT_STATE,DECISIONS}.md`
  - `docs/phases/V1_PHASE_29_DEFINITION.md`
  - `docs/testing/{TEST_STATUS,UAT_LOG}.md`
  - `docs/handoffs/V1_PHASE_29B_HANDOFF.md`

Unchanged: migrations, routes in `apps/api`, environment files, Docker/infrastructure, CI workflows, `composer.json`/`composer.lock`, `pubspec.yaml`/`pubspec.lock`.

## 6. Database/Schema Changes

None.

## 7. API Changes

- **Changed rule (all four work-log requests):** `work_date` may not be after the **company** today; `422` on `work_date` with "The work date cannot be later than today." Previously the UTC date, which wrongly rejected the Manila today from 00:00 to 07:59.
- **Changed `GET /api/v1/me/work-logs`:** deterministic order `work_date DESC, created_at DESC, id DESC`, and an additive `meta.company_day: {date, timezone}`. `data`, `links` and the paginator's `meta` keys are unchanged.
- **Unchanged:** every other work-log rule and response, including the `403` without a linked Staff record and `404` for someone else's log; `GET /projects?member=` and `GET /me/profile` are used as they are.

## 8. Authorization/Security Changes

- **None to the authorization model.** Eligibility (active staff; project member, or the assignee of an independent task), ownership (`404`) and the Administrator `work-logs.manage` writes are unchanged.
- The date ceiling no longer depends on the server's UTC clock; it is the same company day for everyone.
- `meta.company_day` exposes only the date and the timezone name.
- The app's restrictions (no closed projects or cancelled tasks to choose; the 365-day picker) are presentation choices, recorded in `05_SECURITY_MODEL.md` and DEC-055.

## 9. Tests Added or Changed

- **Backend:** 9 new, 1 changed.
  - `WorkLogCompanyDayTest` (9): the 00:30 Manila regression on create and update, self-service and Administrator; 23:30 Manila; `meta.company_day` and the unchanged paginator keys; the company day turning over at Manila midnight; the unchanged no-profile `403`; stable paging plus the SQL ORDER BY.
  - `WorkLogTest::test_work_date_cannot_be_in_the_future` rewritten against the company tomorrow; the old version was shown to accept the date at 17:00 UTC with a Manila company day.
- **Mobile:** 91 new, 1 changed.

  | File | Tests |
  |---|---|
  | `work_log_models_test` | 15 |
  | `work_logs_api_client_test` | 13 |
  | `my_work_logs_controller_test` | 13 |
  | `work_log_form_controller_test` | 30 |
  | `work_logs_pages_test` | 20 (widget, through the real app, including light/dark at 200% text) |

  `people_pages_test`: More now lists exactly three rows.
- **Regression proof (mutation checks):** backend 8, all caught; mobile data/state 13, all caught (one first attempt didn't compile and was redone); mobile UI 10 — 9 caught directly, and the tenth (future dates allowed in the picker) exposed a weak test, which was strengthened and then caught it. Details per gate in `TEST_STATUS.md`.

## 10. Commands/Checks Executed

All `CLAUDE.md` §5 commands, at the final tree.

- **Backend (`apps/api`):** `composer validate --strict`, `composer audit --locked`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test`.
- **Mobile (`apps/mobile`):** `flutter pub get`, `dart format --output=none --set-exit-if-changed .`, `flutter analyze`, `flutter test`.
- **Integration check (temporary, not committed):**
  - A scratch backend test recorded 31 real responses for a Staff user and a Manager, in `Asia/Manila` at 00:30 local time: the list (25 and 1 per page), `POST` for a project, a project task and an independent task (1440 minutes), every `422` kind (future date, not a member, both task and project, zero minutes plus a missing description), `PATCH`, `PATCH` with `task_id` (prohibited), `DELETE` (`204`), someone else's log (`404`), `/me/profile`, `/projects?member=`, and the no-profile `403`. Non-ASCII project, task and description text.
  - A scratch Flutter test replayed them through the production code: models, the API client, the form controller's error mapping and the list's no-profile state: **7/7 passed**.
  - Two mistakes in my scratch test had to be fixed on the way (it parsed the empty `204` body as JSON, and reused a unique project code); neither concerned the application. Both scratch files were deleted.

## 11. Results (Gate 4 final run)

| Check | Result |
|---|---|
| `composer validate --strict` | valid |
| `composer audit --locked` | no advisories |
| Pint | pass |
| PHPStan level 5 | 0 errors |
| `php artisan test` | **1,178/1,178** (3,359 assertions; 1,169 baseline + 9) |
| `flutter pub get` | ok; `pubspec` unchanged |
| `dart format` | 0 changed |
| `flutter analyze` | no issues |
| `flutter test` | **421/421** (330 baseline + 91) |
| Contract parity | real Laravel output replayed through the app (7/7); confirmed the Manager sees only member projects with `member=`, and same-date logs come newest first |

## 12. Deviations from Specification

- **Picker source location (§6.4):** the spec named a separate "picker source"; it lives inside `WorkLogFormController` (`_loadTargets`) instead. Same behaviour, tested there.
- **A `404` on delete counts as deleted:** the log is gone either way, so the app treats it as the outcome the person asked for. A `404` on save says "This work log is no longer available." Both record the removal so the list drops the row. Not spelled out in §6; recorded in the spec's Gate 2 notes.
- **Form layout (§6.5):** "What was this for?" opens a bottom sheet (My open tasks, then My projects); hours and minutes are two number fields (digits only). Recorded in the spec's Gate 3 notes.
- **UAT data (§6.7):** a UAT29B seed is still needed; it follows the 29A runbook pattern after merge.

## 13. Known Issues/Limitations

- **Duplicate on a lost reply (accepted, spec §6.6):** if the connection drops after the server saved a log, the app reports a failure and a retry creates a second log. There is no idempotency key in V1; the duplicate can be deleted.
- **No day totals (R-16):** a correct total needs a server aggregate.
- **Picker reads at most 10 pages** of my open tasks and of my projects (250 tasks, 500 projects) — far beyond one person's count.
- **A task reassigned or a membership removed after the picker loaded:** the server refuses with a `422`, shown at the top of the form.
- **Cross-feature reuse** continues: work logs use the People widgets and Home formatting, and the 29A `TasksApiClient` (Phase 36 candidate to move shared pieces to `core/`).
- **Not run on MySQL here:** the ORDER BY is plain columns; staging exercises it (the 29A runbook's `verify` approach).
- **Carried forward, unchanged:** the 29A device-timezone "Completed" date; `per_page`/`q` hardening (Phase 36); the Phase 28/27/26 items.
- **No device testing:** no device or emulator was available. Nothing in 29B is manually verified on a device.

## 14. Manual/UAT Testing Instructions

**Prerequisites:**
- **Merge** this PR.
- **Deploy to staging:** an app image rebuild and `route:cache`/`config:cache`; no migration and no new route (the API changes are rules and response data).
- **Smoke test:** an unauthenticated `GET /api/v1/me/work-logs` → `401`.
- **Build the UAT APK** from the merge commit with the staging `--dart-define`, and record its full provenance (as in 29A).

**UAT data** (a reviewed operator script in the 29A runbook style; dedicated `uat29b.*` records; passwords in a `0600` file, shredded, never pasted):
- **A Staff user** who is a member of an active project and of a completed project, with one open project task, one open independent task, one cancelled task, and existing logs on today, yesterday and an older date, plus enough logs (≥ 26) for a second page (UAT-29B-01…05, 07).
- **A project the user is not a member of**, to show the server's eligibility message (UAT-29B-04).
- **An account without a staff profile** (UAT-29B-01).
- **UAT-29B-06 (00:00–07:59 Manila):** if UAT can't be run in that window, a runbook stage runs the real validation on staging with the clock set to 00:30 Manila, saving nothing.

Then run UAT-29B-01…08 as listed in `docs/testing/UAT_LOG.md`.

## 15. Documentation Updated

- `CURRENT_STATE.md`, `CHANGELOG.md`
- `02_ARCHITECTURE.md` (§36 Work — Work logs)
- `04_API_CONVENTIONS.md` (the company "today" rule; `/me/work-logs` order and `meta.company_day`)
- `05_SECURITY_MODEL.md` (Work Logs)
- `06_UI_UX_GUIDELINES.md` (Work logs; form conventions as built: field errors, the picker field, date and duration input, discard and delete confirmations)
- `DECISIONS.md` (DEC-055)
- `phases/V1_PHASE_29_DEFINITION.md` (status and per-gate notes; the approved text is unchanged)
- `testing/TEST_STATUS.md` (Gates 1–4)
- `testing/UAT_LOG.md` (UAT-29B-01…08, **all `NOT RUN`**)
- this handoff

`ROADMAP.md` is updated at the end of 29C (spec §11).

## 16. Recommended Next Step

1. Review the Phase 29B implementation PR and its CI (Backend CI and Mobile CI both run).
2. On approval, merge.
3. Prepare a UAT29B runbook (staging redeploy, APK provenance, data script) — a separately authorized documentation step.
4. Deploy to staging and seed the UAT data (operator).
5. The product owner runs UAT-29B-01…08.
6. Formally close 29B.

29C (Projects & Clients) must not begin until 29B is closed and 29C (with its detailed revision) is explicitly authorized (`CLAUDE.md` §8).

**Status summary:**
- **Implemented:** yes.
- **Tested automatically:** yes, backend and mobile, plus a real-output contract parity check.
- **Manually verified on a device:** no.
- **Awaiting UAT:** yes (UAT-29B-01…08 `NOT RUN`).
- **Deployed:** no.
- **Formally closed:** no.

## Addendum — Merge and UAT Preparation Runbook (2026-10-10)

- **Merged:** PR #65 merged into `main` as `7e29ffe809c1282c4c9f7e177087cee02fe8410d`, a standard merge commit with parents `496bd5c` and `c4ee043`. Backend CI and Mobile CI passed on the merge commit, and the merged tree is identical to the reviewed head. This is the source for both the staging deployment and the final UAT APK.
- **Runbook:** `docs/testing/PHASE_29B_UAT_PREPARATION.md`, which turns §14 into concrete operator steps. Each step states where to run it, the exact commands, what it does, the expected output, and what to paste back (never a password):
  - §2: the staging redeploy from `3632ce1` (no migration, no new route; caches rebuilt), with the 29A backup-listing correction and a `/me/work-logs` smoke test;
  - §3: the APK build (always run) and provenance, expected counts 421 / work_logs 91 / tasks 95 / home 66 / network 29, plus `adb install`;
  - §4–§5: the UAT29B data plan and steps (extract and SHA, `plan`, `seed`, `verify`, `todaycheck`, a same-day re-`seed`, `exposure`);
  - §6: per-scenario UAT notes, including `unjoin` (the server-422 test in UAT-29B-04) and `revoke` (UAT-29B-07);
  - §7: the script `uat29b_data.php`, SHA-256 `327b56b761740ab8c7f771451250e17690c39933462d6240aadba8a7bb10ad0f`.
- **UAT-29B-06 (R-8):** the `todaycheck` stage runs the real `StoreMyWorkLogRequest` validation with that process's clock at 00:30 Manila on the next company day: the company "today" must be accepted and "tomorrow" rejected. It saves nothing. With the old UTC rule restored, the same stage reports `PROBLEM` (mutation check, scratch only).
- **Rehearsal:** on scratch SQLite databases only, including the refusals, the all-or-nothing `rotate`, a non-UAT data snapshot and the runbook's own extraction path. Results are in `TEST_STATUS.md`. Its `verify` stage is the first MySQL check of the new `/me/work-logs` ordering.
- **Changes from §14:** dedicated `uat29b.*` accounts (two, including the no-profile one); the membership removal and token revocation are script stages, not ad hoc commands.
- **Status:** nothing is deployed or seeded on staging. UAT-29B-01…08 remain `NOT RUN`. Phase 29B is not closed.

## Addendum — Staging Deployment, UAT and Formal Closure (2026-10-10)

*Operator- and product-owner-reported; this AI session had no VPS or device access. Full record: `docs/testing/TEST_STATUS.md`, "Phase 29B — staging deployment, UAT data and physical-device UAT".*

- **Runbook merged:** PR #66 (`491215a`).
- **Deployment:** staging runs `7e29ffe809c1282c4c9f7e177087cee02fe8410d` (fast-forward from `3632ce1`); 45 Ran, none pending; `Asia/Manila`; only `127.0.0.1:8012`; MySQL healthy. Smoke tests `/up` 200, `/login` 200, `/me/home`, `/me/tasks`, `/me/work-logs`, `/projects` all 401. Backup `company-app-20261010-105229.sql`, 131,099 bytes.
- **UAT:** UAT-29B-01…08 all **`PASS`** (2026-10-10), reported by the product owner. No defects or observations were reported.
- **Not supplied:** the `seed`, `verify`, `todaycheck` and `exposure` outputs, the APK build output and its SHA-256, and the `adb install` output. Recorded as gaps, not invented.
- **Security incident (open, non-blocking by the product owner's decision):** a stray untracked file in the staging checkout root (13,067 bytes, created 2026-10-10 00:59 UTC) whose **name contains a live UAT password** (the operator confirmed it matches a stored UAT password; the account set was not identified). The name appeared in an AI chat session, so the password is treated as exposed. The recommended `rotate` was **deferred by the product owner's decision** (2026-10-10); deleting the file with `shred` was recommended, but its deletion was not confirmed. The file name and the password are deliberately not recorded anywhere.
- **Runbook correction learned in use:** list backups with `ls -lt /home/deploy/backups/company-app-*.sql | head -3` (newest first); the `| tail -2` form sorts by name and can miss the new dump.
- **Closure:**
  - **Implemented:** yes.
  - **Tested automatically:** yes (backend 1,178, Flutter 421).
  - **Manually verified on a device:** yes, by the product owner through UAT.
  - **UAT:** `PASS`.
  - **Deployed:** staging `7e29ffe`.
  - **Formally closed:** **yes, 2026-10-10.**
  - **Carried forward, non-blocking:** the deferred password rotation and the stray file (above); the 29A carry-forwards (device-timezone "Completed" date; shared UI to `core/` and `per_page`/`q` hardening in Phase 36); the empty `company-app_company-app` network on the VPS; the UAT29B, UAT29A, UAT28 and UAT27 staging data, left in place.

**Phase 29C — Projects & Clients has not started.** It needs its detailed specification revision and explicit authorization (`CLAUDE.md` §8).
