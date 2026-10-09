# Phase 28 — People: Staff Directory & Profile (Mobile) — Handoff

**Status: implementation complete — pending PR review/CI, merge, staging deployment and product-owner UAT. Phase 28 is NOT formally closed.**

## 1. Phase Identification

- **Phase:** 28 — People: Staff Directory & Profile (Mobile)
- **Date:** 2026-10-09
- **Specification:** `docs/phases/V1_PHASE_28_DEFINITION.md` (revision 2: R-1…R-8 approved as recommended), merged via PR #55. That merge, `0bb3a64fe5d4da44f4c79fce152047acabb049dc`, is the **authoritative specification baseline**.
- **Branch:** `claude/amazing-brahmagupta-dbsrjc`, restarted from exactly `0bb3a64` for implementation.
- **Gates (all on the one branch, no history rewritten):**
  - Gate 1, backend `GET /api/v1/me/profile` and the `/staff` tie-breaker: `f1bb90a` (pushed before validation, labelled WIP), `dbe1931` (validation record)
  - Merge of `main` at `116526f` (PR #56, `league/commonmark` 2.10.3 security fix): `ab6f13b`
  - Gate 2, Flutter People data and state: `76cb6fc`
  - Gate 3, Flutter People screens and routes: `d2aefd5`
  - Gate 4, final integration review, this handoff and the implementation PR: the final documentation commit
- **Related, outside the phase:** PR #56 (`league/commonmark` 2.10.1 → 2.10.3). It was opened separately from `main` on the product owner's instruction, after Gate 1 found a pre-existing `composer audit` failure, and merged at `116526f`.
- **Pull request:** the Phase 28 implementation PR (`claude/amazing-brahmagupta-dbsrjc` → `main`) is opened in Gate 4. It is **not merged**.

## 2. Objective

Replace the Phase 25 `More` placeholder with the first two real secondary areas: **My profile** (the signed-in person's own company record and account) and the **Staff directory** (find an active colleague, see where they sit, and copy their company contact details). Both are read-only. There is no self-service editing, no staff management, and no Phase 29+ work.

## 3. Scope Implemented

- **Backend:**
  - `GET /api/v1/me/profile`: self-scoped, no permission, no Staff required, Phase 7 `StaffResource` shape (spec §6.1).
  - A unique `id` tie-breaker on `GET /api/v1/staff` (spec §6.2).
- **Mobile:**
  - the `More` menu (spec §8);
  - My profile, with a no-profile state;
  - the Staff directory, with debounced search, load-more paging, pull-to-refresh and two empty states;
  - Staff detail, with Copy and manager navigation;
  - the `/more` sub-routes.
- **Approved decisions honoured:**
  - R-1: active staff only (client-side `status=active`; API unchanged).
  - R-2: `/me/profile`.
  - R-3: Copy only, no new dependency.
  - R-4: no operational status.
  - R-5: colleague field set (no employee number or dates).
  - R-6: deterministic order.
  - R-7: manager navigation.
  - R-8: two-row `More`; logout unchanged on Home.

**Excluded as specified:** editing, photos, an org chart, tap-to-call/email, operational status and location, Admin Backoffice screens, and Tasks/Schedule/Messages (still placeholders).

## 4. Implementation Summary

### Backend (`apps/api`)

- **`MyProfileController::show`:**
  - route `api.v1.me.profile`, `auth:sanctum` + `account.active`, no `can:` middleware;
  - `user` is built from the authenticated `User` (`public_id`, `name`, `email`, `role`);
  - `staff` is `(new StaffResource($staff))->resolve($request)` after loading `StaffController`'s relations, so it is field-identical to `GET /staff/{public_id}` for the same requester, including the `staff.manage`-only `user` block.
- **`StaffController::index`:** `->orderBy('id')` appended after `last_name, first_name`.

### Mobile (`apps/mobile/lib/features/people/`)

- **Domain:** `StaffMember` / `OrgRef`, `MyProfile` / `ProfileUser`, `StaffDirectoryPage`. Strict: a missing or wrongly typed required field → `FormatException` → `ApiRequestException`; nullable fields stay null.
- **Data:** `PeopleApiClient` over the shared `ApiClient`. Directory URLs are built with `Uri(queryParameters:)`; the public id is `Uri.encodeComponent`-ed into the path.
- **State:**
  - `ResourceController<T>` (abstract) holds the Phase 27 Home lifecycle; `MyProfileController` and `StaffDetailController` add only the call and their messages.
  - `StaffDirectoryController` adds:
    - a debounced `setQuery` (300 ms);
    - `load` / `refresh` / `loadMore`, with first-page requests joined and load-more never concurrent;
    - de-duplication by `public_id`;
    - stale-response dropping: every search or refresh increments a generation, and responses from older generations are ignored.
- **Presentation:**
  - `MorePage` (no request; the subtitle is the account name from `AuthController`).
  - `MyProfilePage`, `StaffDirectoryPage` (a footer row triggers the next page when built; a failed page becomes a tap-to-retry row) and `StaffDetailPage`.
  - Shared `people_widgets.dart`.
- **Wiring:**
  - `buildAppRouter` takes a required `PeopleApiClient`.
  - `CompanyApp` builds one `ApiClient` shared by `HomeApiClient` and `PeopleApiClient` (both injectable).
  - `MorePlaceholderPage` was removed.

## 5. Files Changed

39 files against `0bb3a64`, excluding the separately merged PR #56 changes: 22 added, 17 modified, 0 deleted.

- **Backend implementation (3):**
  - `apps/api/app/Http/Controllers/Api/V1/Profile/MyProfileController.php` (new)
  - `apps/api/app/Http/Controllers/Api/V1/Staff/StaffController.php`
  - `apps/api/routes/api/v1.php`
- **Backend tests (2):** `apps/api/tests/Feature/Api/V1/Profile/MyProfileTest.php` (new), `apps/api/tests/Feature/Api/V1/Staff/StaffTest.php`
- **Mobile implementation (16):**
  - `lib/app/{app,router}.dart`
  - `lib/features/shell/presentation/placeholder_page.dart` (`MorePlaceholderPage` removed)
  - `lib/features/people/data/people_api_client.dart`
  - `lib/features/people/domain/{staff_member,my_profile,staff_directory_page}.dart`
  - `lib/features/people/state/{resource_controller,my_profile_controller,staff_detail_controller,staff_directory_controller}.dart`
  - `lib/features/people/presentation/{more_page,my_profile_page,staff_directory_page,staff_detail_page,people_widgets}.dart`
- **Mobile tests (7):**
  - `test/app/router_test.dart` (More expectation)
  - `test/features/people/{people_models_test,people_api_client_test,resource_controllers_test,staff_directory_controller_test,people_pages_test}.dart`
  - `test/support/people_fixtures.dart`
- **Documentation (11, including this handoff):**
  - `docs/{02_ARCHITECTURE,04_API_CONVENTIONS,05_SECURITY_MODEL,06_UI_UX_GUIDELINES,CHANGELOG,CURRENT_STATE,DECISIONS}.md`
  - `docs/phases/V1_PHASE_28_DEFINITION.md`
  - `docs/testing/{TEST_STATUS,UAT_LOG}.md`
  - `docs/handoffs/V1_PHASE_28_HANDOFF.md`

Unchanged:
- migrations, environment files, Docker/infrastructure and CI workflows;
- `composer.json` (the `composer.lock` change came only via PR #56);
- `pubspec.yaml` and `pubspec.lock`.

## 6. Database/Schema Changes

None. The tie-breaker orders by the primary key.

## 7. API Changes

- **Added `GET /api/v1/me/profile`:** `{"data": {"user": {public_id, name, email, role}, "staff": StaffResource | null}}`.
  - **Errors:** `401` for a missing, invalid, revoked or expired token; `403` for an inactive account (`account.active`).
  - There is no `404` and no `422`.
- **Changed `GET /api/v1/staff`:** deterministic order `last_name, first_name, id`. The response shape, filters, authorization and the visible order of distinct names are unchanged.

## 8. Authorization/Security Changes

- **No new permission.** `/me/profile` is token-scoped only, and query parameters are ignored (tested with another user's and staff member's public and numeric ids).
- **No new exposure:**
  - `user` is the person's own identity;
  - `staff` is the existing `StaffResource`;
  - the Administrator-only `user` block appears exactly as on `/staff/{id}` (tested for Staff, Manager and Administrator).
- A user without `staff.view` (no role) can read their own profile and still gets `403` on `/staff`. The app shows an access message and keeps the session.
- **R-1 is a presentation choice, not access control:** DEC-030's visibility of inactive and separated records to `staff.view` holders is unchanged, and recorded in `05_SECURITY_MODEL.md`.
- Recorded in DEC-053.

## 9. Tests Added or Changed

- **Backend:** 17 new.
  - `MyProfileTest` (15): auth, contract, parity with `/staff/{id}` for each role, the manage-only block, no internal ids, null placement, separated status, no-profile `200`, no-role access, subject immune to parameters, constant query count.
  - `StaffTest` (+2): page determinism across `per_page=1` pages plus the SQL `ORDER BY`; visible name order unchanged.
- **Mobile:** 84 new, 1 changed.

  | File | Tests |
  |---|---|
  | `people_models_test` | 15 |
  | `people_api_client_test` | 13 |
  | `resource_controllers_test` | 14 |
  | `staff_directory_controller_test` | 19 |
  | `people_pages_test` | 23 (widget, through the real app, including 5 theme/text-scale/width variants and the Android tap-target and labelled-tap-target guidelines) |

  `router_test` now expects `MorePage`.
- **Regression proof:** each key behaviour was verified by temporarily breaking it and restoring the file afterwards:
  - removing the tie-breaker fails the determinism test;
  - removing the generation guard fails exactly the two stale-response tests;
  - disabling the footer's load-more fails exactly the two paging tests.

## 10. Commands/Checks Executed

All `CLAUDE.md` §5 commands, at the final tree.

- **Backend (`apps/api`):**
  - `composer install`
  - `composer validate --strict`
  - `composer audit --locked`
  - `cp .env.example .env && php artisan key:generate`
  - `vendor/bin/pint --test`
  - `vendor/bin/phpstan analyse`
  - `php artisan test`
- **Mobile (`apps/mobile`):**
  - `flutter pub get`
  - `dart format --output=none --set-exit-if-changed .`
  - `flutter analyze`
  - `flutter test`
  - plus `test/features/home`, `test/core/network test/features/auth` and `test/app`
- **Integration check (temporary, not committed):** a scratch backend test captured real `/me/profile`, `/staff/{id}` and `/staff` responses for every role, a no-profile account, an all-optional-null record and non-ASCII names. A scratch Flutter test parsed them all with the production models.

## 11. Results (Gate 4 final run)

| Check | Result |
|---|---|
| `composer validate --strict` | valid |
| `composer audit --locked` | no advisories (after PR #56) |
| Pint | pass |
| PHPStan level 5 | 0 errors |
| `php artisan test` | **1,147/1,147** (3,215 assertions; 1,130 baseline + 17) |
| `flutter pub get` | ok; `pubspec` unchanged |
| `dart format` | 51 files, 0 changed |
| `flutter analyze` | no issues |
| `flutter test` | **213/213** (129 baseline + 84); Home 64/64; network + auth 45/45; `test/app` 19/19; 0 hit-test warnings |
| Contract parity | real Laravel output parsed by the Flutter models for all cases above |

## 12. Deviations from Specification

- **`More` subtitle (§8):** the spec said "own display name, once known". It shows the **account name** from `AuthController` instead, so `More` makes no request; the staff display name is on My profile. This is cosmetic.
- **Resilience variants (§11):** five variants, the same set as Phase 27's accepted tests (light/dark phone at 100% and 200% text, plus a light 200% tablet), rather than the full light/dark × 100%/200% × phone/tablet product. The 200% phone variants are the hardest and are covered in both themes.
- **UAT data (§12):** the spec placed the UAT seed's *design* in implementation. §14 below gives the data recipe; a scripted, rehearsed operator runbook (like `PHASE_27_UAT_PREPARATION.md`) is a pre-UAT step after merge, as in Phase 27.
- **Implementation refinement, not a scope change:** the shared `ResourceController<T>` base was added so the profile and detail controllers don't duplicate the Home lifecycle.

## 13. Known Issues/Limitations

- **Cross-cutting, recorded for Phase 36 (not changed):**
  - `per_page` is uncapped on about 30 collection endpoints (the app always sends 25);
  - `q` treats `%`/`_` as wildcards (it can only widen a match within data the user may already see).
- **`StaffDirectoryReportController`** (Phase 20) has the same name-only order; it was left unchanged (optional under R-6).
- **R-1 trade-off (accepted):** the API still returns inactive and separated records to `staff.view` holders who ask for them (DEC-030).
- **Small cross-feature coupling:** `people_widgets.dart` reuses `formatDate` from `features/home/presentation/home_formatting.dart`. Moving shared formatting to `core/` is a candidate for Phase 36.
- **Search** is the server's `LIKE` match: no fuzzy or accent-insensitive search (depends on the MySQL collation).
- **Staging directory** will list every active staging staff record, including the Phase 27 UAT27 accounts (intentional test data).
- **Phase 27 carry-forwards, unchanged:** offline-launch sign-out, `ApiClient` charset hardening, the DST display limitation, Android debug signing and the `mobile` label (Phase 38), and the Phase 26 items.
- **No device testing:** no device or emulator was available. Nothing in Phase 28 is manually verified on a device.

## 14. Manual/UAT Testing Instructions

**Prerequisites:**
- **Merge** this PR.
- **Deploy to staging:** an app image rebuild is required (new route and controller); no migration.
- **Smoke test:** an unauthenticated `GET /api/v1/me/profile` → `401`.
- **Build the UAT APK** from the merge commit with `--dart-define=API_BASE_URL=https://company-staging.storm-ark.com/api/v1`, and record its provenance as in Phase 27 (SHA, toolchain, size, SHA-256).

**UAT data** (there is no Admin UI; use a reviewed script or Tinker, with dedicated records only):
- **A Staff user with a full profile:** position, department, team, a manager, company email and phone, hire date, and a preferred name different from the first name (UAT-28-01/05). The Phase 27 `uat27.staff` account already matches this, apart from the preferred name, and could be reused.
- **An Administrator without a linked profile** (UAT-28-02). Phase 27's `uat27.admin.noprofile` matches.
- **Enough active staff for more than one page at 25 per page** (at least 26), including a same-name pair (UAT-28-03/04).
- **One `inactive` and one `separated` record** with distinctive names, to confirm they never appear (UAT-28-03).
- **Passwords:** rotate or generate them privately; never paste them into any chat or the repository (the Phase 27 incident).

Then run UAT-28-01…07 as listed in `docs/testing/UAT_LOG.md`.

## 15. Documentation Updated

- `CURRENT_STATE.md`, `CHANGELOG.md`
- `02_ARCHITECTURE.md` (§34)
- `04_API_CONVENTIONS.md`
- `05_SECURITY_MODEL.md` (My Profile self-scope)
- `06_UI_UX_GUIDELINES.md` (People, and the paged-list and "Not set" conventions; the list-density question resolved)
- `DECISIONS.md` (DEC-053)
- `phases/V1_PHASE_28_DEFINITION.md` (status only; the approved text is unchanged)
- `testing/TEST_STATUS.md` (Gates 1–4)
- `testing/UAT_LOG.md` (UAT-28-01…07, **all `NOT RUN`**)
- this handoff

`ROADMAP.md` is deliberately left for formal closure, as in previous phases.

## 16. Recommended Next Step

1. Review the Phase 28 implementation PR and its CI. Backend CI and Mobile CI should both run, because it touches `apps/api/**` and `apps/mobile/**`.
2. On approval, merge.
3. Deploy to staging.
4. Prepare the UAT28 data and the final UAT APK (operator).
5. The product owner runs UAT-28-01…07.
6. Formally close Phase 28.

Phase 29 (Work) must not begin until Phase 28 is closed and Phase 29 is explicitly authorized (`CLAUDE.md` §8).

**Status summary:**
- **Implemented:** yes.
- **Tested automatically:** yes, backend and mobile, plus a real-output contract parity check.
- **Manually verified on a device:** no.
- **Awaiting UAT:** yes (UAT-28-01…07 `NOT RUN`).
- **Deployed:** no.
- **Formally closed:** no.

## Addendum — Merge and UAT Preparation Runbook (2026-10-09)

- **Merged:** PR #57 merged into `main` as `b6e85c5a5b8bceac4328805af562fd7a2bb4f5a9`, a standard merge commit with parents `116526f` and `d85b62f`. Backend CI and Mobile CI passed on the PR head. This is the source for both the staging deployment and the final UAT APK.
- **Runbook:** `docs/testing/PHASE_28_UAT_PREPARATION.md`, which turns §14 above into concrete operator steps:
  - §2: the staging redeploy (`route:cache` is required for the new route; no migration);
  - §3: the APK build and provenance procedure;
  - §4–§5: the UAT28 data plan and data steps;
  - §6: per-scenario UAT notes;
  - §7: the script `uat28_data.php`, SHA-256 `4e846aec912c5ebc48b29d334311468c590542e82b4fbbcc3ba1474c088cabbd`.
- **Rehearsal:** the script was rehearsed on scratch databases only, including refusals, the all-or-nothing `rotate` and the runbook's own extraction path. Results are in `TEST_STATUS.md`.
- **Change from §14:** dedicated `uat28.*` accounts instead of reusing the UAT27 ones, so Phase 28 UAT does not depend on Phase 27's data state or rotated passwords.
- **Status:** nothing is deployed or seeded on staging. UAT-28-01…07 remain `NOT RUN`. Phase 28 is not closed.
