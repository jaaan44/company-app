# Phase 34E — Staff Web Portal: Reports — Handoff

**Status: complete and formally closed (2026-10-13).** Merged (PR #108, `bcdd6f5`), deployed to staging, UAT-34E-01…06 **PASS**. **Phase 34 (Staff Web Portal) is complete.** UAT-34E-01…06 are `NOT RUN`. This is the last web sub-phase of Phase 34.

## 1. Phase Identification

- **Phase:** 34E — Staff Web Portal, Reports (the fifth and last sub-phase of Phase 34, DEC-063).
- **Date:** 2026-10-13
- **Authorization:** "proceed with 34E" (product owner).
- **Specification:** `docs/phases/V1_PHASE_34_DEFINITION.md` §5 and §15; W-8 for files.
- **Branch:** `claude/amazing-brahmagupta-dbsrjc`, from `main` at `c455070` (34D closed).

## 2. Objective

Bring the mobile app's service and incident reports (Phase 33) to the browser, with photos and PDFs uploaded from the browser's file picker (W-8).

## 3. Scope Implemented

Everything listed for 34E in §5 and §15. Not included, as in Phase 33 R-12 and the Phase 34 non-goals: reviewing and rejecting, the incident investigation workflow, participants, task links, signatures and exports. Those are management tasks for the Admin Backoffice phases.

## 4. Implementation Summary

**New Livewire components** in `App\Livewire\Portal`:
- `Reports`;
- `ServiceReportForm`, `IncidentForm`, with a shared `Concerns\ReportForm` trait (the mobile `_check` text limits, and the API refusal mapped onto fields);
- `ReportShow`, one detail component for both kinds (a locked `kind`), with `WithFileUploads`.

**A new controller**, `App\Http\Controllers\Portal\ReportFileController`, serves files at `/app/reports/{kind}/{id}/files/{fileId}`.

**`InternalApi` gains two methods,** both on one private `dispatch()` that `send()` now also uses, so the request restoration is shared:
- `upload()`: forwards one file as a multipart request. The Livewire temporary file is wrapped as a trusted local `UploadedFile`, so the API's own `file|max|mimes` validation and storage run unchanged.
- `raw()`: returns the API's own response unread, for streaming a download.

**Key behavior:**
- **The creator's actions come from the API's data and my staff id**, as on mobile:
  - a service report can be edited, submitted, deleted and given files while it is **my draft**;
  - **Return to draft** is offered only for **my rejected** report;
  - an incident can be edited and given files while it is **mine and still reported**.
  - The API still decides every action, and a refusal shows in its words.
- **The service form:**
  - clients are found by searching the active clients (`/clients?status=active&q=`);
  - projects are my open projects for that client (`/projects?member=me`, completed and cancelled hidden), as on mobile;
  - the date is at most the company today.
- **The incident form** takes a date and time in company time, by default now. It refuses a future time, and sends at most the server's now (the mobile skew guard).
- **Files:**
  - the portal checks JPEG, PNG or PDF of at most 10 MB, then the API checks again;
  - after the upload, success or not, the temporary file is deleted;
  - photos show as thumbnails and open in a new tab; PDFs download.
  - The file route answers `404` for anything the API refuses, and sets `nosniff`, a sandboxing CSP, `no-store`, and `inline` only for JPEG and PNG.
- **`Format`** gains `reportDate`, `reportTime`, `fileSize`, `serviceStatus`, `incidentStatus`, `incidentType`, `severity`, `reportTone`, `severityTone` and `reportAction`, the same as the mobile helpers.
- **Routes:** eight under `/app/reports`.
- **Navigation:** Reports, after Announcements.

## 5. Files Changed

**New:**
- the 4 components and the `ReportForm` trait;
- `ReportFileController`;
- 4 Livewire views and `partials/report-field.blade.php`;
- `tests/Feature/Portal/ReportPagesTest.php`;
- this handoff.

**Changed:**
- `app/Services/Portal/InternalApi.php`;
- `app/Support/Portal/Format.php`;
- `routes/web.php`;
- `components/layouts/portal.blade.php`;
- `tests/Unit/Support/Portal/FormatTest.php`;
- docs: 02, 05, 06; DECISIONS (DEC-063 status line only); the Phase 34 definition; ROADMAP; CURRENT_STATE; CHANGELOG; TEST_STATUS; UAT_LOG.

## 6. Database/Schema Changes

None.

## 7. API Changes

None. `InternalApi` (the portal's client) changed, as above.

## 8. Authorization/Security Changes

None to the rules. The portal now writes reports and their attachments through the same endpoints as the mobile app. Downloads go only through the API's authorized download (see `05_SECURITY_MODEL.md`). Portal uploads are audited with `source: web` (pinned by a test).

## 9. Tests Added or Changed

- **`ReportPagesTest`** (16 tests):
  - guests are sent to login, and all four roles open every page; the navigation order;
  - both lists (order, dates, project, chips, others' excluded); empty and no profile;
  - the service form's messages; client search (inactive excluded), project options (closed and others' excluded), and a draft saved with a trimmed text and `null` blanks; editing my draft only;
  - my draft's actions, and Submit persisted; Delete draft; Return to draft;
  - my manager can read my draft but not act on it; someone else's and an unknown report;
  - uploading a JPEG and a PDF (stored on the attachment disk, audited `web`) and removing one;
  - the type and 10 MB checks;
  - the file route: inline for a photo, a download for a PDF, guests sent to login, another staff member and a wrong report/file pair `404`;
  - reporting an incident (messages, a future time refused, stored in UTC from Manila time); an incident page; editing only while reported.
- **`FormatTest`**: 1 more unit test, 14 in all.
- **`InternalApiTest`** (11): unchanged and green after the refactor.

## 10. Commands/Checks Executed

- In `apps/api`: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test`, and `npm run build`.
- Mutation checks.
- Playwright with Chromium and axe-core 4.10.2, against `php artisan serve` on a scratch SQLite database, uploading real files (a generated PNG, a minimal PDF and a `.txt`) through Livewire's upload endpoint.

## 11. Results

- **Backend:** 1,371/1,371 tests pass; Pint and PHPStan are clean.
- **Browser:** 49/49 checks pass, in light and dark:
  - both lists;
  - the service form's messages, a client search, a project, and a draft;
  - a `.txt` refused; a PNG and a PDF added;
  - the photo rendered on the page, and served `inline` as `image/png`; the PDF downloaded under its own name;
  - Remove; Submit; Return to draft;
  - an incident reported, with a photo;
  - no horizontal scroll at 320 px on 5 pages; no JavaScript errors;
  - **axe WCAG 2.x AA: 0 violations** on 7 page states × 2 schemes.
- **Mutations:** 8 run.
  - 5 were caught at first.
  - Two survivors exposed missing tests (a manager's view of a draft, and Return to draft only when rejected); with those tests, both are caught.
  - One survivor is equivalent: Laravel turns blank strings into `null` before the API sees them.
- **Found and fixed during testing:** the component's `upload()` method clashed with Livewire's own client-side `$wire.upload` (the file-upload helper), so the form never reached the server method. It is now `addFile()`. Only the browser run could show this; the Livewire test harness calls methods directly.
- **Not run here:** MySQL, the staging deployment, a real upload through the host nginx (12 MB limit), and UAT.

## 12. Deviations from Specification

None. Two notes:
- W-8 said photos "open in a viewer". They show as thumbnails on the page and open full size in a new browser tab, which is the browser's own viewer.
- The API answers `404`, not `403`, to someone who may not see a report, so the page says "This report is no longer available."

## 13. Known Issues/Limitations

- **A report shows its files as the API returns them,** with no paging (as on mobile).
- **Large uploads** go through two limits on staging: Livewire's 12 MB temporary upload and the host and container nginx 12 MB. The 10 MB rule is enforced first by the portal, then by the API.
- **Livewire's temporary files** are deleted after each upload. One left behind by an abandoned upload is removed by Livewire's own cleanup of `livewire-tmp` (files older than 24 hours).

## 14. Manual/UAT Testing Instructions

A runbook will be written after merge: `docs/testing/PHASE_34E_UAT_PREPARATION.md`, covering the deploy, any data the scenarios need (reusing the UAT30B accounts, no passwords) and UAT-34E-01…06.

## 15. Documentation Updated

See §5.

## 16. Recommended Next Step

1. Merge on green CI.
2. Deploy to staging and run UAT-34E.
3. Close Phase 34. The next phase on the roadmap (35, Admin Backoffice) needs its own authorization.

## Addendum — Merge and UAT runbook (2026-10-13)

- **PR #108 merged as `bcdd6f5`.** CI was green, including the staging image build.
- **Runbook `docs/testing/PHASE_34E_UAT_PREPARATION.md`:**
  - the deploy `fd57c7c` → `bcdd6f5`, with no migration;
  - the data script `uat34e_data.php` (SHA-256 `7b894d2a…`), which reuses the UAT30B accounts and makes no password. It seeds a client and project, a rejected service report and a resolved incident for Lia. Its `verify` also prints the container's PHP upload limits;
  - UAT-34E-01…06, including a real upload through the host nginx.
- **The script was rehearsed on scratch SQLite.**
- **Not run:** the deployment and UAT.

## Addendum — Deployment, UAT and Formal Closure (2026-10-13)

- **Deployed:** staging `fd57c7c` → `bcdd6f5` (the operator's output was pasted: fast-forward, backup, `BUILD OK`, migrations Ran, assets in both containers, smoke tests including the file route).
- **Data:** `uat34e_data.php` seeded and verified on staging (SHA `7b894d2a…`, `seed exit=0`, `verify` as expected, PHP upload limits 12M/13M), output pasted.
- **UAT-34E-01…06: PASS**, reported by the product owner after running them in desktop and phone browsers against staging, with real photo and PDF uploads, including one from a phone browser. Recorded by the AI on that report.
- **Phase 34E is formally closed, and with it Phase 34 (34A–34E).** The UAT34E data and the reports and files made during UAT stay on staging; cleanup needs its own authorization. The 30B and 31–33 UAT records are still to be reconciled (see TEST_STATUS).
