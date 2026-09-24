# Phase 27 — Employee Home / Dashboard (Mobile) — Handoff

**Status: COMPLETE — Phase 27 formally closed 2026-09-24.** Physical-device UAT-27-01…08 all `PASS`; see the final addendum, "Recovery Execution, Physical-Device UAT and Formal Closure (2026-09-24)". The status line as first written was: *implementation merged (PR #44, `be43663`) and deployed to staging — pending the company-timezone decision and product-owner UAT. Phase 27 is NOT formally closed.* The addenda below record each step in order; each one's status was true when it was written.

## 1. Phase Identification

- **Phase:** 27 — Employee Home / Dashboard (Mobile)
- **Date:** 2026-09-23
- **Specification:** `docs/phases/V1_PHASE_27_DEFINITION.md` (revision 2), approved and merged via PR #43. That merge, `b63599d4249d3aa621e29468378dea93d540abe4`, is the **authoritative specification baseline**.
- **Branch:** `claude/quirky-curie-82ufli`, restarted from exactly `b63599d` for implementation (after PR #43 merged). Before each gate this session checked the branch, the expected HEAD, a clean tree, `main` at `b63599d`, and that no PRs were open.
- **Gates (all on the one branch, no history rewritten):**
  - Gate 1, backend `GET /api/v1/me/home`: `e6a4908`, `43155f4`
  - Gate 1A, MySQL verification record: `67ed99b`
  - Gate 2, Flutter authenticated API client and session lifecycle: `68c8f89`, `6d79ed2`
  - Gate 3, Flutter employee Home screen: `c95c8fd`, `8f16869`
  - Gate 4, final integration review, this handoff and the implementation PR: the final documentation commit
- **Pull request:** the Phase 27 implementation PR (`claude/quirky-curie-82ufli` → `main`) was opened in Gate 4. It is **not merged**.

## 2. Objective

Replace the Phase 25 Home placeholder with a small, read-only employee Home that always describes the signed-in person. It shows who they are, what needs their attention, what is on for them today, and the latest announcements addressed to them. No role turns it into a company-wide dashboard, and no later-phase module is pulled forward.

## 3. Scope Implemented

- **Backend:** `GET /api/v1/me/home`: self-scoped, bounded and read-only, with no parameters, no new permission and no migration (spec §6).
- **Mobile authenticated requests:** `ApiClient` with centralized 401/403 session rules, and a single-flight, stale-token-safe session-ending routine shared by manual logout and automatic expiry. Login shows a session-ended notice after automatic expiry (spec §7).
- **Mobile Home screen:** greeting, Today, Needs attention and Latest announcements. It also has a no-profile state, a loading state, an error state with "Try again", and pull-to-refresh. Content is non-interactive (spec §8).
- **Product decisions honoured:**
  - R-1: no navigation from Home content.
  - R-2: announcements preview, at most 3.
  - R-3: Today contains only the employee's own schedule entries and tasks, with no leave.
  - R-4: no operational status.

Excluded as specified: People, Tasks, Schedule, Operations, HR/leave, Communication inboxes and detail views, Field Reporting, Admin screens, and any Phase 28+ work. The Tasks/Schedule/Messages/More tabs are unchanged Phase 25 placeholders.

## 4. Implementation Summary

### Backend (`apps/api`)

- **Controller:** `App\Http\Controllers\Api\V1\Home\MyHomeController::show`. Route `api.v1.me.home`, behind `auth:sanctum` and `account.active`.
- **Subject:** taken only from `$request->user()` and its linked Staff record. No request input is read, and there is no `tasks.view`, Project Lead, Manager direct-report or `Gate::before` branch.
- **No linked Staff:** `200`, with `staff`, `today`, `tasks`, `messages` and `announcements` set to `null`. `user`, `company_day` and `notifications` are always present.
- **Existing definitions reused, none redefined:**
  - `CompanyTimezone` and `OverdueTasks::todayInCompanyTimezone()` for the company date and day window;
  - `OverdueTasks::scope()` for overdue tasks;
  - the terminal-status set `completed`/`cancelled` for "open";
  - the Schedule Entry creator/participant clauses for Today ownership;
  - the task's single assignee for task ownership;
  - `ConversationMember::unreadCount()`'s predicate, summed in **one** aggregate query, for unread messages;
  - the `/me/notifications/unread-count` query for unread notifications;
  - `ScopesAnnouncementVisibility::scopeVisibleToStaff()` for announcements.
- **Today:**
  - **Contents:** own schedule entries overlapping the company day (inclusive), plus own open tasks due on the company date.
  - **Order:** start, then all-day first, then entry before task, then byte-wise title, then `public_id`.
  - **Limit:** at most 5 items plus `total_count`.
  - **Queries:** each source is limited to 5 in the database with the same ordering. The database uses `CAST(title AS BINARY)` on MySQL/MariaDB and SQLite's default BINARY collation; PHP compares with `strcmp`.
- **Queries per request:** 12, whatever the data volume (measured).

### Mobile (`apps/mobile`)

- **`lib/core/network/api_client.dart`:** `ApiClient.getJson(path)` with a bearer token from the current session.
  - **401:** `expireSession`, which clears the session locally.
  - **403:** exactly one `GET /auth/me` re-check through `AuthApiClient`, so it can never recurse. A 401 from that check expires the session. A 200, 403, 5xx or network failure keeps the session and reports the original 403.
- **`lib/core/network/api_exception.dart`:** a sealed error model with five cases: `ApiSessionExpiredException`, `ApiForbiddenException`, `ApiNetworkException`, `ApiServerException` and `ApiRequestException`.
- **`AuthController`:**
  - implements `ApiSession` and keeps the current token in memory (it is still persisted in `TokenStorage`);
  - `logout()` (server revoke, then local clear) and `expireSession(tokenUsed:)` (local clear only) share one single-flight `_endSession`;
  - an expiry applies only if the session is still authenticated and `tokenUsed` is the current token;
  - token deletion is awaited, and a storage failure still signs out.
- **`LoginPage`:** shows "Your session has ended. Please sign in again." in a live region, only after automatic expiry.
- **`AuthApiException`:** gains an optional `statusCode`.
- **`lib/features/home/`:**
  - `domain/home_summary.dart`: strict typed contract; `null` sections stay `null`.
  - `data/home_api_client.dart`: one `ApiClient` call.
  - `state/home_controller.dart`: a `ChangeNotifier` with states loading / loaded / error; refresh keeps content; one request in flight; session expiry ignored because Gate 2 handles it.
  - `presentation/home_page.dart`: loads once in `initState`.
  - `presentation/home_formatting.dart`: company-time formatting from `company_day.utc_offset`, with no timezone package.
- **Wiring:** `CompanyApp` and `buildAppRouter` accept an optional injected `HomeApiClient`, mirroring the existing `authController` injection.

## 5. Files Changed

36 files against `b63599d`: 19 added, 16 modified, 1 deleted. The deleted file, `apps/mobile/lib/features/home/home_page.dart`, was rewritten at `presentation/home_page.dart`.

- **Backend implementation (2):**
  - `apps/api/app/Http/Controllers/Api/V1/Home/MyHomeController.php`
  - `apps/api/routes/api/v1.php`
- **Backend tests (3):** `apps/api/tests/Feature/Api/V1/Home/{MyHomeTest,MyHomeTodayTest,MyHomeCountsTest}.php`
- **Mobile implementation (13, including the deleted placeholder):**
  - `lib/app/{app,router}.dart`
  - `lib/core/network/{api_client,api_exception}.dart`
  - `lib/features/auth/{data/auth_api_client,presentation/login_page,state/auth_controller}.dart`
  - `lib/features/home/{data/home_api_client,domain/home_summary,state/home_controller,presentation/home_page,presentation/home_formatting}.dart`
  - the removed `lib/features/home/home_page.dart`
- **Mobile tests (10):**
  - `test/app/{router_test,session_expiry_test}.dart`
  - `test/core/network/api_client_test.dart`
  - `test/features/auth/{login_page_test,session_lifecycle_test}.dart`
  - `test/features/home/{home_summary_test,home_controller_test,home_page_test}.dart`
  - `test/support/{fake_backend,home_fixtures}.dart`
- **Documentation (9 before this handoff, plus this file):**
  - `docs/{02_ARCHITECTURE,04_API_CONVENTIONS,05_SECURITY_MODEL,06_UI_UX_GUIDELINES,CHANGELOG,CURRENT_STATE,DECISIONS}.md`
  - `docs/phases/V1_PHASE_27_DEFINITION.md`
  - `docs/testing/{TEST_STATUS,UAT_LOG}.md`
  - `docs/handoffs/V1_PHASE_27_HANDOFF.md`

Nothing else changed: no migrations, environment files, Docker/infrastructure, CI workflows, `composer.json`/`composer.lock`, or `pubspec.yaml`/`pubspec.lock`.

## 6. Database/Schema Changes

None. Existing indexes suffice: `tasks(assignee_staff_id, status)`, `messages(conversation_id, id)`, `conversation_members(conversation_id, staff_id)`, `notifications(recipient_user_id, read_at)`, `schedule_entries(creator_staff_id, starts_at)`, and the participant pivot's foreign keys.

## 7. API Changes

Added `GET /api/v1/me/home` (spec §6.2): a single object with `user`, `staff`, `company_day` (`date`, `timezone`, UTC `starts_at`/`ends_at`, `utc_offset`), `today` (`total_count`, ≤ 5 `items`), `tasks` (`open_count`/`overdue_count`/`due_today_count`), `messages.unread_count`, `notifications.unread_count`, and `announcements.latest` (≤ 3; `public_id`/`title`/`published_at`).

Errors:
- `401`: missing, invalid, revoked or expired token.
- `403`: inactive account; `account.active` also revokes the token.

No existing endpoint changed.

## 8. Authorization/Security Changes

- **No new permission.** The subject is token-derived only, and role never widens the response. This is tested for an ordinary employee, an Administrator, a Manager with direct reports and a Project Lead.
- **No leakage:** no internal ids, contact details, manager, employment or operational status, location, message or announcement bodies, or acknowledgement data.
- **Mobile session rule:** 401 ends the session. 403 alone never does, and never parses message text. Session ending is single-flight and idempotent, a stale token can't sign out a newer login, and tokens never appear in error text.
- Recorded in DEC-052 and `05_SECURITY_MODEL.md`.

## 9. Tests Added or Changed

- **Backend: 50 feature tests, 229 assertions.** They cover:
  - every 401 variant and inactive → 403 with the token revoked;
  - the exact response shape and forbidden fields;
  - request parameters being ignored;
  - profile and no-profile responses;
  - four-role isolation;
  - Today ownership and exclusions (project-only entries, leave, milestones, terminal/other-day/others' tasks);
  - inclusive overlap boundaries and a non-UTC company day;
  - ordering and tie-breaks, including byte-wise and numeric-looking titles;
  - the limit plus `total_count`, and the bounded fetch equalling a full sort (randomized);
  - count definitions, with parity against `OverdueTasks`, `ConversationMember::unreadCount()` and `/me/notifications/unread-count`;
  - announcement eligibility, limit, fields, tie-break, and being a subset of `/me/announcements`;
  - constant query count.
- **Gate 1A (MySQL 8.4.11):** a temporary, uncommitted test on the real endpoint confirmed that `CAST(title AS BINARY)` ordering equals the PHP comparator and that the `LIMIT 5` boundary is correct. A negative control (the plain collation order) failed as expected. The committed Home suite also passed on MySQL.
- **Mobile: 107 new tests** (43 in Gate 2, 64 in Gate 3). They cover:
  - bearer token and base URL;
  - error propagation;
  - 401 expiry;
  - 403 with each `/auth/me` outcome, with no recursion;
  - concurrent 401s;
  - 401 racing manual logout in both orders;
  - stale token-A 401/403 after a token-B login;
  - storage failure;
  - the Login notice;
  - Home parsing, including malformed payloads;
  - controller states, retry and refresh;
  - UI sections and states;
  - no-profile;
  - non-interactivity, including tap-throughs;
  - no re-fetch on rebuild;
  - session integration;
  - light/dark and 200% text with long content at phone and tablet sizes;
  - semantics and tap-target guidelines.
- **Changed existing tests:** only where the placeholder was intentionally replaced. One `router_test.dart` assertion changed ("Signed in as …" → the Home app bar), and two import paths changed because `HomePage` moved.
- **Mutation checks (manual):**
  - Gate 1: 9 controller mutations.
  - Gate 2: 8 session mutations.
  - Gate 3: 10 Home mutations.

  Each made at least one test fail, after two gaps were closed with added tests.

## 10. Commands/Checks Executed

Backend (`apps/api`):
- `composer validate --strict`
- `composer audit --locked`
- `vendor/bin/pint --test`
- `vendor/bin/phpstan analyse`
- `php artisan test`
- `php artisan test tests/Feature/Api/V1/Home`
- Gate 1A, against the repository's `docker-compose.yml` `mysql` service (bound to `127.0.0.1` only, a throwaway database, torn down afterwards): `vendor/bin/phpunit tests/Feature/Api/V1/Home` with `DB_CONNECTION=mysql`.

Mobile (`apps/mobile`), Flutter 3.47.2:
- `flutter pub get`
- `dart format --output=none --set-exit-if-changed .`
- `flutter analyze`
- `flutter test`
- `flutter test test/features/home`
- `flutter test` on the Gate 2 suites

Repository: `git diff --check`.

## 11. Results (Gate 4 final run)

- **Backend:**
  - `php artisan test` 1,130/1,130 (3,147 assertions); Home 50/50 (229).
  - Pint passed; PHPStan level 5 reported 0 errors.
  - `composer validate --strict` valid; `composer audit --locked` found no advisories.
- **Mobile:**
  - `flutter test` 129/129; Home 64/64; Gate 2 suites 46/46.
  - `dart format` exit 0; `flutter analyze` found no issues; `flutter pub get` OK.
  - `pubspec.yaml`/`pubspec.lock` unchanged from `b63599d`.
- **Cross-layer contract:** `MyHomeController` output matches `HomeSummary.fromJson` field by field (see `docs/testing/TEST_STATUS.md`).
- **CI:** see the implementation PR.

## 12. Deviations from Specification

Implementation refinements of spec §7, not scope changes; both are recorded in DEC-052 and the CHANGELOG:

1. The 401/403 handling lives in the shared `ApiClient` rather than in each feature controller calling `expireSession()`. This was per the Gate 2 instructions, so Home and later phases don't duplicate it.
2. `AuthController` keeps the current token in memory, so the stale-token comparison has no async gap. This replaces having `CompanyApp` share one `TokenStorage` instance with the client.

Presentation detail within §8:
- the greeting shows the team on its own line;
- multi-day entries read "From Wed 23 Sep – 10:00" or "18:00 – until Fri 25 Sep";
- announcement dates read "22 Sep 2026".

## 13. Known Issues/Limitations

These are deferred and were not changed in Phase 27:

- **Staging `SCHEDULING_COMPANY_TIMEZONE`:** unverified; it defaults to `UTC`. Confirm or set it **before UAT-27-02** as an operational step (spec §14 R-7).
- **`/schedule` day boundaries:** it filters on UTC day boundaries rather than `CompanyTimezone`. This is pre-existing and deferred to Phase 30 (Schedule).
- **Offline launch signs the user out:** `AuthController.bootstrap()` treats any launch-time `/auth/me` failure, including a network failure, as an invalid token. Pre-existing since Phase 4; a candidate for Phase 36 (Integration & UX Hardening).
- **Response decoding:** mobile `ApiClient` reads `response.body`, which `package:http` decodes as Latin-1 when there is no charset. This is harmless today because Laravel escapes non-ASCII JSON as `\uXXXX`; a hardening candidate for Phase 36.
- **DST display:** a daylight-saving switch within the company day can shift displayed times after the switch by an hour. This is the documented limitation (spec §8/§14).
- **Strict parsing:** an unknown future Today `source_type` would put Home into its error state; this is contract-strict by design.
- **Phase 26 carry-forwards, unchanged:**
  - UAT-24-04's historical `:8012` wording;
  - the inert shared DigitalOcean firewall `8012` rule (out of scope, no phase assigned);
  - client-IP accuracy behind `trustProxies(at: '*')` (revisit only if a feature comes to depend on real client IPs);
  - the Android app label `mobile` and release debug signing (a future release-preparation phase, i.e. Phase 38).
- **No device testing:** no device or emulator was available. Nothing in Phase 27 is manually verified on a device.

## 14. Manual/UAT Testing Instructions

Prerequisites: the PR is merged and deployed to staging, and staging's company timezone is confirmed. Build the app with `--dart-define=API_BASE_URL=https://company-staging.storm-ark.com/api/v1`.

Seed the UAT-27-02 data with Tinker on staging, because no Admin UI exists. For a Staff user with a linked Staff record, create:
- a `ScheduleEntry` today with `creator_staff_id` set to them;
- a second entry where they are attached as a participant;
- a `Task` with `assignee_staff_id` set to them and `due_date` today;
- another such task with a past `due_date`;
- a direct `Conversation` with one unread `Message` from another staff member;
- an unread `Notification` for their user;
- a published company-wide `Announcement`.

Then run UAT-27-01…08 exactly as listed in `docs/testing/UAT_LOG.md`:
- sign-in and greeting;
- seeded data correct and isolated;
- empty states;
- refresh after a new message;
- airplane-mode error and retry;
- server-side revocation → Login with the notice;
- dark mode and large text, with nothing tappable;
- Administrator (with and without a profile) and Manager see only their own data.

## 15. Documentation Updated

- `CURRENT_STATE.md`, `CHANGELOG.md`
- `02_ARCHITECTURE.md` (§33)
- `04_API_CONVENTIONS.md`
- `05_SECURITY_MODEL.md` (Employee Home isolation; mobile session rule)
- `06_UI_UX_GUIDELINES.md` (Employee Home)
- `DECISIONS.md` (DEC-052)
- `phases/V1_PHASE_27_DEFINITION.md` (status only; the approved text is unchanged)
- `testing/TEST_STATUS.md` (Gates 1, 1A, 2, 3 and 4)
- `testing/UAT_LOG.md` (UAT-27-01…08, **all `NOT RUN`**)
- this handoff

`ROADMAP.md` is deliberately left for formal closure, as in previous phases.

## 16. Session Environment Note

- **Backend dependencies:** `vendor/` needed the recurring Composer workaround. `--prefer-source` clones worked, but `phpstan/phpstan` is dist-only. Its exact locked commit was archived from a verified git mirror into Composer's dist cache; there was no `vendor/` surgery.
- **Flutter SDK:** 3.47.2 was downloaded from `storage.googleapis.com`, as in Phase 25.
- **Gate 1A MySQL:** Docker was started with `nohup dockerd` (the Phase 24 note), and `mysql:8.4` was pulled via `mirror.gcr.io` (the Phase 4A note).

`.env` and `vendor/` were never committed.

## 17. Recommended Next Step

1. Review the Phase 27 implementation PR and its CI.
2. On approval, merge.
3. Deploy to staging.
4. Confirm staging's `SCHEDULING_COMPANY_TIMEZONE`.
5. Run UAT-27-01…08 as product owner.
6. Formally close Phase 27.

Phase 28 (People) must not begin until Phase 27 is closed and Phase 28 is explicitly authorized (`CLAUDE.md` §8).

**Status summary:**
- **Implemented:** yes.
- **Tested automatically:** yes, backend and mobile.
- **Manually verified on a device:** no.
- **Awaiting UAT:** yes (UAT-27-01…08 `NOT RUN`).
- **Deployed:** no.
- **Formally closed:** no.

---

## Addendum — Merge and Staging Deployment (2026-09-23)

- **Merge:** PR #44 merged into `main` with a standard merge commit, **`be43663f1e3527867c04adb73071eb3bace01ba5`**. Its parents are `b63599d` and `c252601`, and its tree is identical to the PR head. This is the authoritative Phase 27 merged implementation baseline. CI passed on `c252601` (Backend quality gates (PHP 8.4); Mobile quality gates (Flutter 3.47.2)).
- **Deployment:** run by the operator on the staging VPS; this AI session has no VPS access, and its network policy blocks the staging hostname and `dl.google.com`. Following `DEPLOYMENT_STAGING.md` §9, using the required `-p company-app -f docker-compose.staging.yml --env-file .env.staging` invocation:
  - **Pre-deploy checkout:** `4cf55c0`. `.env.staging` showed as untracked because the older checkout predated its `.gitignore` rule; it is ignored after the update.
  - **Backup:** `company-app-20260923-092600.sql`, 88,356 bytes.
  - **Update:** fast-forwarded to exactly `be43663`.
  - **Rebuild:** `build app nginx`, then `up -d --no-deps app nginx`. `app` was recreated. `nginx` kept running, because its inputs (`apps/api/public`, `docker/nginx`, the staging Compose file) are unchanged between `4cf55c0` and `be43663`. `mysql` was untouched.
  - **Migrations:** `migrate:status` shows all 45 migrations Ran.
  - **Caches:** config, route and view caches rebuilt.
  - **Config:** `app.env` staging, `app.debug` false, `app.url` `https://company-staging.storm-ark.com`, `session.secure` true.
- **Smoke tests (public HTTPS):**
  - `/up` 200 and `/login` 200.
  - HTTP `/up` returns 301 to HTTPS.
  - Unauthenticated `GET /api/v1/me/home` returns 401.
  - Only `127.0.0.1:8012` is listening, with no `8442`.
  - `mysql` is healthy.
  - Not tested: an authenticated `/me/home` call, and a read-only check of the other hosted projects.
- **Company timezone:** effective `scheduling.company_timezone` = **`UTC`**, and `SCHEDULING_COMPANY_TIMEZONE` is **not set** in `apps/api/.env`, so this is the default. It was not changed. **A product-owner decision on the company's timezone is required before UAT-27-02.** The deployment itself went ahead after this finding. That did not change the timezone, but the UAT prerequisite remains unmet. Setting a value is an `.env` edit; follow `DEPLOYMENT_STAGING.md` §7a, because `apps/api/.env` is a single-file bind mount, then `config:cache`.
- **Release APK:** built on the operator's Windows machine with `flutter build apk --release --dart-define=API_BASE_URL=https://company-staging.storm-ark.com/api/v1`.
  - Output: `build/app/outputs/flutter-apk/app-release.apk`, 51,582,167 bytes.
  - SHA-256: `4C95BA4D5D58B50B4AA9388B9C5F52AA4AB1A669FE25D16DAADAB94A757EBC1C`.
  - Package `com.companyapp.mobile`, versionName 1.0.0, versionCode 1, compileSdk 36; `android.permission.INTERNET` is present.
  - Debug-signed, deferred to Phase 38.
  - Before building, `flutter test` passed 129/129 and `dart format`/`flutter analyze` were clean.
  - Not captured in the output: the build machine's checkout SHA, and its Flutter/Dart versions.
- **Test-quality note (non-blocking):** the build machine's `flutter test` output showed hit-test warnings.
  - **What happens:** in `home_page_test.dart`, the refresh `fling`s and the 200%-text/phone overflow tests' final `drag` target the greeting's centre, which isn't hit-testable. The `drag` is therefore skipped, and those tests never scroll to the lower sections.
  - **Impact:** a scratch re-run using `scrollUntilVisible` reached every section with no overflow in all five variants, so the product is unaffected. The refresh tests still pass on their own assertions.
  - **Follow-up:** tightening these tests is a small change. It needs its own authorization, because the implementation is merged.
- **UAT data:** the seeding recipe is in §14 above, and nothing has been seeded yet. UAT-27-02 additionally needs the timezone decision.
- **UAT-27-01…08:** `NOT RUN`. **Phase 27 is not formally closed**, and Phase 28 has not started.

## Addendum — Pre-UAT Remediation (2026-09-23)

- **Company timezone decided:** `Asia/Manila` (product owner). It is applied through configuration only (`SCHEDULING_COMPANY_TIMEZONE`), never hardcoded in source. The operator applied it on staging by appending in place, so the inode, ownership and mode were unchanged. They then recreated only `app` (MySQL and nginx untouched) and ran `config:cache`. It is verified on staging 2026-09-23: `config:show scheduling.company_timezone` → `Asia/Manila`; company now `2026-09-23T21:29:06+08:00`. App config and smoke checks are unchanged (staging, debug false, secure sessions; 200/200/401; 8012 loopback only). UAT-27-02 is unblocked.
- **Test-scroll gap corrected (test-only)** in `apps/mobile/test/features/home/home_page_test.dart`:
  - the 200%-text/phone overflow tests walk every lower section with `scrollUntilVisible` on Home's own scrollable and assert each is reached;
  - pull-to-refresh gestures target that scrollable;
  - hit-test warnings are fatal in the file.
- **Proof:** an injected Announcements overflow failed all 4 corrected phone variants, while the original test missed it in both 200%-text variants. Results: `flutter test` 129/129, Home 64/64, `dart format`/`flutter analyze` clean, no hit-test warnings. No production or dependency change.
- **Final UAT APK:** to be built only after this correction merges into `main`, so its source provenance is exact.
- **Status:** UAT-27-01…08 remain `NOT RUN`. Phase 27 is not formally closed.

## Addendum — Final UAT Preparation (2026-09-23)

- **UAT source baseline:** `093441a9526a96a285afe6fe7a0e21d66bc3f764` (PR #45 merged with a merge commit; parents `be43663` and `644695a`). Staging runs `be43663`. The diff between them contains no production code, dependency or runtime-config change, so staging needs no rebuild.
- **Company timezone:** `Asia/Manila` is the effective setting on staging, verified by the operator 2026-09-23 with `+08:00`. The prerequisite is satisfied.
- **Quality gates at `093441a`:** run in this AI sandbox (Flutter 3.47.2 / Dart 3.13.2).
  - Results: Home 64/64; Gate 2 45/45; full 129/129; analyze and format clean.
  - They must be re-run on the APK build machine.
- **Final UAT APK: not built.** This session has no Android SDK and cannot download one. The operator builds it from `093441a` per `docs/testing/PHASE_27_UAT_PREPARATION.md` §2 and records full provenance. The earlier APK `4C95BA4D…EBC1C` is superseded.
- **UAT data: not seeded.** §14's recipe is made concrete as a deterministic, idempotent script with dedicated `UAT27` accounts and records dated to the Asia/Manila company day (`PHASE_27_UAT_PREPARATION.md` §3–§6).
  - It was checked on a scratch database only.
  - The staging run is pending the operator.
  - Order constraint: publish the UAT announcement only after UAT-27-03.
- **Status:** UAT-27-01…08 remain `NOT RUN`. Phase 27 is not formally closed. Phase 28 has not started.

## Addendum — UAT Data Preparation Incident and Recovery Design (2026-09-24)

- **Staging preparation (operator):** script revision 1 ran on 2026-09-24 (Asia/Manila). `plan` passed, `seed` succeeded, and `verify` matched the plan exactly.
- **Incident:**
  - all six first-generation UAT27 passwords were pasted into an external AI chat, so they are exposed and invalid for UAT;
  - `announce` ran before physical-device UAT-27-03, so the UAT27 announcement is published on staging.
- **Recovery:** designed and rehearsed on a scratch database only; **not executed on staging**. See `docs/testing/PHASE_27_UAT_PREPARATION.md` §4a. It uses:
  - script revision 2 (`398dab9cebe8b0b34fe33020d2cf050a1db227471d94e384025956572e57a88b`): read-only `exposure`; all-or-nothing `rotate` of exactly the six UAT27 accounts, with their API tokens and web sessions revoked; an `announce` that never revives an archived announcement;
  - `uat27_archive.sh` (`e057bfa4dd5ddf6fd816dd1e9cbf8a21e71df49d3280f8eb81a4fee2d2808fe9`): archives the exact accidental announcement through the app's own admin API.
- **Status:** results in `docs/testing/TEST_STATUS.md`. UAT-27-01…08 remain `NOT RUN` (server-side `verify` is not UAT-27-03). Phase 27 is not closed. Phase 28 has not started.

## Addendum — Recovery Execution, Physical-Device UAT and Formal Closure (2026-09-24)

*Operator- and product-owner-reported. This AI session had no VPS, build-machine or device access. Full record: `docs/testing/PHASE_27_UAT_PREPARATION.md` §8; per-scenario UAT evidence: `docs/testing/UAT_LOG.md`.*

**1. Historical pre-UAT state (unchanged, see the previous addendum):**
- UAT data was seeded with script revision 1.
- The first-generation UAT27 passwords were then exposed in an external AI chat.
- `announce` ran before UAT-27-03, publishing `01M38G2Z97D8H6KP2WDJ48X1WH` `[UAT27] Office closed Friday` (`2026-09-24T01:22:10+00:00`).

**2. Recovery (executed on staging; PASS before any physical UAT):**
- Both tools were taken from merged `origin/main`, and their SHA-256s were verified: revision 2 `398dab9c…a57a88b`, archive helper `e057bfa4…808fe9`.
- Read-only `exposure` first: all six UAT27 accounts had 0 API tokens, 0 web sessions and no auth audit, and exactly one UAT27 announcement was published. There is no evidence that the exposed passwords were used.
- `rotate`: exactly six accounts, `exit=0`. The file was mode `600`, owner `deploy`, 6 lines; the passwords were stored privately and the file was `shred`ded. No password is in the repository.
- Archive: `01M38G2Z97D8H6KP2WDJ48X1WH` archived (`archived_audit=1`) and the helper's token revoked.
- Post-recovery: all UAT27 tokens and sessions zero; the only admin auth audit was the archive's sign-in/sign-out.
- The accidental announcement stays archived as historical evidence.

**3. Final UAT APK:**
- **Source:** built and verified from `093441a9526a96a285afe6fe7a0e21d66bc3f764` (operator-confirmed; the repository holds no build-time `git` transcript). Staging runs `be43663f1e3527867c04adb73071eb3bace01ba5`; the two differ only in `home_page_test.dart` and `docs/`.
- **Build-machine gates:** format (32 files, 0 changed) and analyze clean; Home 64/64; core network + auth 45/45; full 129/129.
- **Toolchain:** Flutter 3.47.2 (`d3b14c8769`), Dart 3.13.2, Temurin JDK 17.0.15+6, Android SDK 36.
- **Artifact:** 51,582,167 bytes, SHA-256 `4C95BA4D5D58B50B4AA9388B9C5F52AA4AB1A669FE25D16DAADAB94A757EBC1C`, `com.companyapp.mobile` 1.0.0 (1), SDK 36/24/36, INTERNET present, v2 debug signature (`CN=Android Debug`).
- **Identical hash:** this is byte-identical to the earlier APK in the Merge and Staging Deployment addendum. That earlier copy **remains superseded**, because its checkout provenance was not recorded. The identical hash does not retroactively establish its provenance. The authoritative artifact is identified by its controlled provenance **and** its hash.

**4. Physical-device UAT (2026-09-24, staging `be43663`, company date 2026-09-24 Asia/Manila): UAT-27-01…08 all `PASS`.**
- 01: linked-profile greeting ("Uat", UAT27 Field Technician / UAT27 Operations).
- 02: own data only — Today 3; tasks 2/1/1; 1 unread message; 1 unread notification; no colleague items; stable across refresh.
- 03: every empty state, with **no announcement**. Only after this passed was the replacement announcement `01M38R3M012BFS7JMWFVJ36QDM` published (`2026-09-24T03:42:20+00:00`); a refresh then showed it while everything else stayed empty.
- 04: the colleague's API message (`201`/`200`, runbook §5; the Messages tab is still a Phase 25 placeholder) moved Staff Messages from 1 to 2 unread.
- 05: an offline refresh kept the earlier content, showed "Couldn't refresh. Showing earlier information.", did not crash or sign out, and recovered once back online. The initial-load "Try again" state was not exercised on the device; it is covered by automated tests.
- 06: server-side token deletion led to Login with "Your session has ended. Please sign in again."; signing in again worked (Messages 2, Notifications 2).
- 07: dark mode and large font were readable with no overflow; Today items, My tasks, Messages, Notifications and the announcement were not tappable.
- 08: Manager and Administrator saw only their own task (1/0/1), never other employees' data; the Administrator without a profile degraded gracefully (no fabricated profile, no crash or endless loading, no forced logout; Notifications 0).

**5. Final staging state (intentional; not cleaned up):**
- The final `verify`/`exposure` (04:21:51Z/04:21:56Z) matched the expected post-UAT state.
- Staff has 1 API token, because the device was left signed in. Every other UAT27 account has none.
- `01M38G2Z97D8H6KP2WDJ48X1WH` is archived and `01M38R3M012BFS7JMWFVJ36QDM` is published.
- This closure made no staging, database, credential or announcement change.

**6. Closure.**
- **Implemented:** yes.
- **Tested automatically:** yes, backend and mobile.
- **Manually verified on a device:** yes, by the product owner through UAT.
- **UAT:** UAT-27-01…08 `PASS`, reported by the product owner.
- **Deployed:** staging `be43663`.
- **Formally closed:** **yes, 2026-09-24.**
- No blocking defect is open.
- **Carried forward, non-blocking:**
  - the §13 deferred items (`/schedule` UTC day boundaries → Phase 30; offline-launch sign-out and `ApiClient` charset hardening → Phase 36; the DST display limitation);
  - the Phase 26 carry-forwards;
  - the Android debug signing and `mobile` label (Phase 38);
  - the UAT27 staging data, left in place.

**Phase 28 — People (Mobile) has not started.** It needs explicit authorization (`CLAUDE.md` §8).
