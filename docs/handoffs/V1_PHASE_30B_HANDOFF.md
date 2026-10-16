# Phase 30B — Operations: Status & Check-in (Mobile) — Handoff

**Status: implementation complete and merged (PR #77, `4a85918`); UAT preparation runbook written — pending staging deployment and product-owner UAT. Phase 30B is NOT formally closed.** See the addendum at the end. When 30B closes, **Phase 30 closes with it**.

## 1. Phase Identification

- **Phase:** 30B — Status & Check-in, the second and last sub-phase of Phase 30 — Operations: Schedule & Check-in (Mobile) (R-1).
- **Date:** 2026-10-12
- **Specification:** `docs/phases/V1_PHASE_30_DEFINITION.md` §6 (revision 2: R-13…R-22 approved as written), merged via PR #76 as `13a37c1`. That merge is the **authoritative specification baseline**.
- **Branch:** `claude/amazing-brahmagupta-dbsrjc`, restarted from exactly `13a37c1` for implementation.
- **Gates (all on the one branch, no history rewritten):**
  - Gate 1, backend: `99ef67c`
  - Gate 2, mobile data, state and location: `e4ce2cb`
  - Gate 3, screens and routes: `568640c`
  - Gate 4, integration review, this handoff and the implementation PR: the final commit
- **Pull request:** the Phase 30B implementation PR (`claude/amazing-brahmagupta-dbsrjc` → `main`) is opened in Gate 4. It is **not merged**.

## 2. Objective

Let staff see and set their operational status from the phone, and record an explicit, foreground-only location check-in, with nothing ever read in the background.

## 3. Scope Implemented

- **Backend:** `id` tie-breakers on the four status and check-in history lists; `meta.company_day` on `/me/status` and `/me/check-ins` (R-14).
- **Mobile:**
  - More → My status (R-13);
  - Change status (R-16);
  - the check-in flow (R-19/R-20), with the `geolocator` dependency and coarse and fine location permissions (R-17/R-18);
  - All check-ins and All status changes;
  - the no-profile state (R-15);
  - read-only rows with no map (R-21). Colleagues' statuses, the manager view and Home are unchanged (R-22).

## 4. Implementation Summary

- **Gate 1:** `->orderByDesc('id')` in the shared `historyFor` of both Phase 9 controllers; `myIndex` adds `meta.company_day` through `->additional()`. A test pins the SQL `ORDER BY`, because SQLite returns same-second rows newest-first even without it.
- **Gate 2:**
  - the dependency and manifest;
  - strict models;
  - the API client;
  - `LocationService`: one adapter, the only importer of `geolocator`;
  - `MyStatusController`, the two paged list controllers and `CheckInFlowController`.
- **Gate 3:** the four screens, routes in the More branch, More's new row and subtitle, and `PagedSearchList`'s optional search field.
- **Gate 4:**
  - full gates;
  - contract parity against real Laravel output;
  - one review fix: Back is held while sending;
  - a correction about `package_info_plus`;
  - docs, DEC-058, UAT rows and this handoff.

## 5. Files Changed

- **Backend (`apps/api`):** `app/Http/Controllers/Api/V1/StaffOperations/OperationalStatusController.php`, `CheckInController.php`; new test `tests/Feature/Api/V1/StaffOperations/StatusCheckInHistoryTest.php`.
- **Mobile (`apps/mobile`), new:**
  - `lib/features/my_status/{domain/my_status.dart, data/my_status_api_client.dart, location/location_service.dart, state/my_status_controller.dart, state/history_list_controllers.dart, state/check_in_flow_controller.dart, presentation/my_status_page.dart, presentation/check_in_page.dart, presentation/history_pages.dart, presentation/my_status_widgets.dart}`
  - Tests: `test/features/my_status/{my_status_models_test, my_status_state_test, my_status_pages_test}.dart`, `test/support/my_status_fixtures.dart`
- **Mobile, changed:**
  - `pubspec.yaml` and `pubspec.lock` (two direct packages, 16 lock entries, none changed);
  - `android/app/src/main/AndroidManifest.xml` (two permissions, one service removed);
  - `lib/app/app.dart`, `lib/app/router.dart`;
  - `lib/core/presentation/paged_search_list.dart` (optional search);
  - `lib/features/people/presentation/more_page.dart` (the My status row and subtitle);
  - `test/features/people/people_pages_test.dart`.
- **Docs:** see §15.

## 6. Database/Schema Changes

None. No migration (still 45).

## 7. API Changes

- **Order only:** `GET /api/v1/me/status`, `/me/check-ins`, `/staff/{public_id}/status` and `/staff/{public_id}/check-ins` end their order in `id`. Visible order is unchanged.
- **Additive:** `GET /me/status` and `/me/check-ins` add `meta.company_day {date, timezone, utc_offset}`. Items, validation, status codes and scoping are unchanged.

## 8. Authorization/Security Changes

- **API:** no change. DEC-032's visibility (own; Manager for direct reports; Administrator) stands.
- **App, the first sensitive permission:**
  - `ACCESS_COARSE_LOCATION` and `ACCESS_FINE_LOCATION` only. Never background location or a foreground service; the plugin's location service is removed from the manifest.
  - The position is read only on the person's tap, after an explanation of who sees check-ins.
  - Nothing is queued, and no status snapshot is sent.

See DEC-058 and `05_SECURITY_MODEL.md` (Location Data).

## 9. Tests Added or Changed

- **Backend (+15):** `StatusCheckInHistoryTest`:
  - order and paging on all four lists, and the pinned `ORDER BY`;
  - the company day at 07:00 and 23:30 Manila;
  - unchanged item shapes, no-profile `403`, validation and scoping.
- **Flutter (+64):**
  - models 11 (including the single-importer guard);
  - client and controllers 29 (including the check-in state machine against a fake device);
  - pages 24 through the whole app: More, My status, Change status, every check-in path, Back held while sending, both lists, dark mode and 200% text on a small phone, tap-target guidelines.
- **Changed (1):** the Phase 28 More test now expects six rows and the one status read.
- **Mutation checks:** Gate 1 10 (9 caught, 1 equivalent); Gate 2 17/17; Gate 3 20/20; Gate 4 1/1.

## 10. Commands/Checks Executed

- `apps/api`: `composer validate --strict`, `composer audit --locked`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test`.
- `apps/mobile` (Flutter 3.47.2 / Dart 3.13.2): `flutter pub get`, `dart format --output=none --set-exit-if-changed .`, `flutter analyze`, `flutter test` (and per area).
- **Contract parity (Gate 4, temporary, not committed):** 13 real responses recorded by a scratch Laravel test and replayed through the production app code by a scratch Flutter test: 5/5. Details are in `TEST_STATUS.md` (Phase 30B — Gate 4). Both files were deleted.

## 11. Results (Gate 4 final run)

| Check | Result |
|---|---|
| `composer validate --strict` / `composer audit --locked` | Valid; no advisories |
| Pint / PHPStan level 5 | Pass; 0 errors |
| `php artisan test` | **1,221/1,221** (3,578 assertions) |
| `dart format` / `flutter analyze` | 0 files changed; no issues |
| `flutter test` | **577/577** (my_status 64, people 84, home 67, schedule 39, tasks 95, and the rest) |
| `pubspec.yaml` / `pubspec.lock` | `geolocator` 14.0.3 and `geolocator_android` 5.0.3 exact; 16 lock entries added, none changed |
| Contract parity | 5/5 |
| Merged Android manifest | **Not verified here** (see §13) |

## 12. Deviations from Specification

- **`geolocator_platform_interface` 4.4.0** (2026-09-26, about two weeks old) was resolved rather than pinned. Both approved packages allow `^4.x`; its changes are pure Dart additions the app doesn't use. Pinning would need a third entry beyond R-17. It is recorded, and the product owner did not ask for a pin.
- **`package_info_plus`** is bundled as an Android plugin (through `geolocator_linux`). Its manifest is empty and it is never called. It is not named in the spec; recorded at Gate 4.
- **More now makes one request** (R-13's subtitle), replacing Phase 28's "More fetches nothing".
- **Added at Gate 4 (not in the spec):** Back is held while a check-in is sending.
- **Status rows have no id** (DEC-017): rows are keyed by time and status, so two identical changes in the same second would show once.

## 13. Known Issues/Limitations

- **The shipped permission list is not verified yet.** This environment's network policy blocks Google's Android repository, so no APK could be built here. The UAT APK step **must** show exactly `INTERNET`, `ACCESS_COARSE_LOCATION`, `ACCESS_FINE_LOCATION` and the AndroidX `DYNAMIC_RECEIVER_NOT_EXPORTED_PERMISSION`, and no background or foreground-service permission. Anything else blocks UAT.
- `GeolocatorLocationService` (the plugin adapter) has no automated test by design; it is exercised only on a device.
- No anti-spoofing: a mocked position is accepted (as the API already notes).
- No offline queue, by design (R-19).
- No retention policy for check-ins (unchanged since Phase 9).
- No rate limit on `POST /me/check-ins` (no demonstrated need).
- A no-profile account's visit to More costs an `/auth/me` re-check after the `403`.
- Carried forward, untouched: `per_page` caps on legacy lists (Phase 36); debug signing (Phase 38); iOS (no build); the deferred UAT password rotation from 29B; the UAT27–UAT30A staging data.

## 14. Manual/UAT Testing Instructions

A separately authorized UAT30B runbook will give the exact steps. Needed for UAT-30B-01…08:
- **Staging:** redeploy to the 30B merge (no migration; caches rebuilt); smoke tests show `401` for `/me/status` and `/me/check-ins`.
- **APK:** built from the merge. Expected Flutter 577 (my_status 64). **The `aapt2 dump badging` permission check above is a hard gate.**
- **Data (dedicated `uat30b.*` accounts):**
  - a Staff member with a manager, some status history (including two changes in the same second) and more than 20 check-ins (for paging);
  - their Manager;
  - a colleague who is not their manager (UAT-30B-05);
  - a no-profile account.

  A `verify` stage shows a new check-in as seen by the manager, and the colleague's refusal.
- **Device:** a physical Android 12+ phone, so that Approximate, "Only this time" and the Privacy dashboard can be checked.
  - UAT-30B-03 needs the app's location permission reset between attempts (Android Settings → Apps → the app → Permissions).
  - UAT-30B-07 needs the usual `revoke` stage.

## 15. Documentation Updated

- `docs/phases/V1_PHASE_30_DEFINITION.md`: status; Gate 1–4 notes (including the Gate 2 correction).
- `docs/02_ARCHITECTURE.md` §39 (and §38 marked closed); `docs/04_API_CONVENTIONS.md` (Gate 1); `docs/05_SECURITY_MODEL.md` (location capture on mobile); `docs/06_UI_UX_GUIDELINES.md` (My status and Check in; the 30A heading).
- `docs/DECISIONS.md`: DEC-058.
- `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/ROADMAP.md`.
- `docs/testing/TEST_STATUS.md` (Gates 1–4), `docs/testing/UAT_LOG.md` (UAT-30B-01…08 `NOT RUN`).
- This handoff.

## 16. Recommended Next Step

1. Review the Phase 30B implementation PR and its CI (Backend CI and Mobile CI both run).
2. On approval, merge.
3. Prepare a UAT30B runbook (staging redeploy, APK provenance with the permission gate, data script) — a separately authorized documentation step.
4. Deploy to staging and seed the UAT data (operator).
5. The product owner runs UAT-30B-01…08 on a physical Android device.
6. Formally close 30B, and with it Phase 30.

Nothing further begins until explicitly authorized (`CLAUDE.md` §8).

**Status summary:**
- **Implemented:** yes.
- **Tested automatically:** yes, backend and mobile, plus a real-output contract parity check.
- **Manually verified on a device:** no.
- **Awaiting UAT:** yes (UAT-30B-01…08 `NOT RUN`).
- **Deployed:** no.

## Addendum — Merge and UAT Preparation Runbook (2026-10-12)

- **Merged:** PR #77 merged into `main` as `4a85918cc36bc5631636168f1443f95109ab0b1c`, a standard merge commit. Backend CI and Mobile CI passed on the PR head (`1180e39`) before the merge. This is the source for both the staging deployment and the final UAT APK.
- **Runbook:** `docs/testing/PHASE_30B_UAT_PREPARATION.md`:
  - §2: the staging redeploy from `0e7fce6` (no migration; caches rebuilt) with smoke tests for `/me/status` and `/me/check-ins`.
  - §3: the APK build (always run), expected counts 577 / my_status 64 / people 84 / home 67, and the **hard permission gate**. `aapt2 dump badging` must list exactly `INTERNET`, `ACCESS_COARSE_LOCATION`, `ACCESS_FINE_LOCATION` and the AndroidX `DYNAMIC_RECEIVER_NOT_EXPORTED_PERMISSION`, and an `xmltree` search for `GeolocatorLocationService`, background location and foreground service must print nothing. It also checks for Android 12+.
  - §4–§5: the UAT30B data plan and stages.
  - §6: per-scenario UAT notes, including how to reset the app's location permission between attempts.
  - §7: the script `uat30b_data.php`, SHA-256 `b020d7171a826f0de1243ec474aecfa9bd7d50676834973bea37ff8ced4e0f6f`.
- **Data:**
  - four `uat30b.*` accounts: staff (Lia), her manager (Ben), a colleague who isn't her manager (Kai), and no profile;
  - Lia's status history is replaced on every seed: 22 changes, two in the same second, ending one hour before the run with Available;
  - 22 seeded check-ins, re-dated on each seed; check-ins made in the app are never touched.
  - `verify` shows what Lia, Ben, Kai and the no-profile account each get, and lists every check-in made in the app.
- **Rehearsal:** on scratch SQLite only, including a check-in made through the real controller with the app's exact body, drift then re-seed, the refusals, the all-or-nothing `rotate` and a non-UAT data snapshot. Results are in `TEST_STATUS.md`.
- **Status:** nothing is deployed or seeded on staging. UAT-30B-01…08 remain `NOT RUN`. Phase 30B is not closed.

## Addendum — UAT and Formal Closure (2026-10-13)

- **Deployed:** staging `d6dd29a` (combined round, `docs/testing/COMBINED_UAT_PREPARATION.md` §2; first attempt at `1d4f437` failed on `.dockerignore`, fixed in PR #83). APK built at `d6dd29a`; the permission gate showed the same four permissions as planned.
- **UAT-30B-01…08: PASS**, reported by the product owner after running each scenario on a physical Android 12+ device against staging. Recorded by the AI on that report.
- **Phase 30B is formally closed, and with it Phase 30** (30A closed 2026-10-12).
- **Open, non-blocking:** UAT30B data stays on staging (cleanup needs its own authorization); the deferred UAT password rotation from 29B.

## Addendum — UAT withdrawn as not verified (2026-10-16)

- **What was found:** on 2026-10-13 the 34C deploy found none of the combined round's 31 or 32 test data on staging, and the round couldn't be reconciled with the server.
- **The decision:** the product owner withdrew **UAT-30B-01…08** as not verified ("i choose C"). The rows are `NOT RUN` again, with the original record kept in their notes.
- **The phase stays closed.** Its code is merged and its automated checks passed.
- **Not covered:** the phone-app screens are untested by the product owner until the Phase 38 UAT. The server side has since passed UAT through the web portal (34B–34E).

