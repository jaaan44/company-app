# Phase 33 — Field Reporting: Service Reports, Incident Reports & Attachments (Mobile) — Handoff

**Status: implementation complete (Gates 1–4); the PR is merged on green CI under DEC-059. NOT formally closed:** it waits for the combined staging deployment and UAT round (30B + 31–33). This completes the Employee mobile application's planned phases (27–33).

## 1. Phase Identification

- **Phase:** 33 — Field Reporting (Mobile).
- **Date:** 2026-10-12
- **Specification:** `docs/phases/V1_PHASE_33_DEFINITION.md` revision 1. Decisions R-1…R-13 were approved under DEC-059. **Flagged sensitive:** the new dependency and file transfer.
- **Branch:** `claude/amazing-brahmagupta-dbsrjc`, restarted from `main` at `09780b9` (Phase 32 merged).
- **Gates:**
  - Gate 1: `1d3b0a2`;
  - Gate 2: `0efb729`;
  - Gate 3: `9ee0cff`;
  - Gate 4: the final commit.

## 2. Objective

Let field staff write service reports and report incidents from the phone, with photos, and follow their status.

## 3. Scope Implemented

R-1…R-13. Small deviations are noted in §12.

## 4. Implementation Summary

- **Backend:**
  - the company-today rule for `service_date`;
  - tie-breakers and the company day on both lists.
- **Mobile:**
  - `ApiClient` multipart and bytes;
  - the `image_picker` adapter;
  - the reports feature: models, client, lists, forms, details, photos and `ReportChanges`;
  - six routes and the More row.

## 5. Files Changed

- **Backend:**
  - `app/Http/Requests/ServiceReports/Concerns/LimitsServiceDateToCompanyToday.php` (new);
  - `Store` and `UpdateServiceReportRequest`;
  - `ServiceReportController` and `IncidentReportController`;
  - `tests/Feature/Api/V1/ServiceReports/ReportsMobileTest.php` (new).
- **Mobile:**
  - `pubspec.yaml` and `pubspec.lock` (+15 packages, none changed);
  - `lib/core/network/api_client.dart`;
  - `lib/features/reports/**` (new);
  - `lib/app/{app,router}.dart`;
  - `lib/features/people/presentation/more_page.dart`.
- **Mobile tests:**
  - `test/core/network/api_client_files_test.dart`, `test/features/reports/*` and `test/support/report_fixtures.dart` (new);
  - `test/features/people/people_pages_test.dart` (ten rows; `openDirectory` scrolls).
- **Docs:**
  - the spec and DEC-062;
  - 02 §42, 04, 05 and 06;
  - CURRENT_STATE, CHANGELOG, ROADMAP, TEST_STATUS, UAT_LOG, and this handoff.

## 6. Database/Schema Changes

None.

## 7. API Changes

- `service_date` uses the company today (`422` message "The service date cannot be later than today.").
- Both report lists add the `id` tie-breaker and `meta.company_day`.

## 8. Authorization/Security Changes

None to authority.
- **The new dependency** adds a private `FileProvider` and photo-picker metadata to the merged manifest, and **no permission**. This is unverifiable here and is a hard gate in the UAT APK step.
- **Photos:** scaled and checked before sending, validated again by the server, and held in memory only.

## 9. Tests Added or Changed

- **Backend:** 5 new tests; 3 mutations, all caught.
- **Mobile:** 43 new tests (7 files, 21 state, 15 pages); 8 mutations, 7 caught and 1 equivalent.

## 10. Commands/Checks Executed

The CLAUDE.md §5 commands for both apps, the dependency audit (publish dates and plugin manifests), mutation runs, and the contract parity.

## 11. Results (Gate 4 final run)

- **Backend:** **1,253/1,253**; Pint, PHPStan, composer validate and audit clean.
- **Flutter:** **747/747**; format and analyze clean.
- **Dependency audit:**
  - `image_picker` 1.2.3 (2026-06-30), `image_picker_android` 0.8.13+23 (2026-09-10), `image_picker_platform_interface` 2.11.1 (2025-10-22, pinned instead of the days-old 2.11.2), `cross_file` 0.3.5+5, `mime` 2.1.0, `flutter_plugin_android_lifecycle` 2.0.35, and the `file_selector_*` and other platform packages that never ship in the Android build;
  - `image_picker_ios` 0.8.13+10 (2026-10-08) is recent but never built for Android, and is recorded rather than pinned.
- **Contract parity:**
  - 15 real responses: create with a non-ASCII body at 00:30 Manila (accepted), the future-date `422`, a PNG upload, a GIF `422`, the streamed download (bytes match), show, submit, the post-submit upload `409`, both lists, incident create, the future `422`, patch, and the profile;
  - all parsed by the production models, 1/1; scratch tests deleted.
- **Layout finding fixed:** the "New" control moved to the app bar.

## 12. Deviations from Specification

- **R-1's "New button"** is an app-bar add icon with a tooltip.
- **R-7's "progress shown"** is an indeterminate "Uploading photo…", not a percentage.
- **The incident time** defaults to now rounded down to the minute, to tolerate a device clock slightly ahead of the server's. A clock far ahead is refused by the server with its `422` on the field.

## 13. Known Issues/Limitations

- **Not verified here:** Laravel parsing the exact multipart bytes Dart sends (UAT-33-03), and the merged manifest (the APK gate).
- **Deep links:** incident times show "(device time)" when opened without the list's company day.
- **Projects:** the project picker reads at most five pages of my projects.
- **No device run.** Out of scope: R-12.

## 14. Manual/UAT Testing Instructions

UAT-33-01…08 run in the combined round. They need:
- a staff member who is a member of a client's project;
- that staff member's manager (to reject on the web and start an investigation);
- an Android 12+ phone with a camera;
- a PDF attached on the web;
- a user without a profile.

## 15. Documentation Updated

See §5.

## 16. Recommended Next Step

The combined UAT runbook for 30B + 31–33 (DEC-059). After UAT and closure, the Laravel web frontend the product owner intends needs its own specification and authorization.
