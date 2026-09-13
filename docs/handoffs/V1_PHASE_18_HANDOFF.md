# Phase 18 — Service Reports — Handoff

## 1. Phase Identification

**Phase:** 18 — Service Reports
**Date:** 2026-09-13
**Branch:** `claude/phase-18-service-reports` (branched from `main` at `3944354`, Phase 17 merged)

## 2. Objective

Implement Service Reports per the product-owner-approved decisions that superseded the preceding planning audit's preliminary recommendations, and — because Service Reports genuinely requires it and Phase 19 (Incident Reports) is the concrete next consumer — introduce the project's previously deferred shared attachment/file infrastructure (`03_DATABASE_MODEL.md` §1's Phase-0-era open question).

## 3. Scope Implemented

Everything in the product owner's 23-point instruction was implemented:
- Core Service Report concept: Client required, Project/Task optional, relational coherence validated.
- Report fields kept intentionally small (no labor-time, no parts/materials tables, no GPS, no signature).
- A simple Staff participant pivot with no role/status hierarchy.
- The exact closed four-state workflow (`draft`/`submitted`/`reviewed`/`rejected`) with explicit action endpoints.
- Draft-only editing/attachment mutation; content-immutable once submitted/reviewed/rejected.
- Append-only `service_report_actions` workflow history.
- Review authority: creator's current Manager, linked Project's Project Lead, or Administrator — no client/customer approval, no multi-level chain.
- Visibility deliberately narrower than Scheduler's model — no broad `service-reports.view` permission.
- Self-service + Administrator-on-behalf creation authority, mirroring Work Log's precedent.
- Deletion rules exactly as specified (draft only, creator/Administrator).
- The shared `attachments` table with a typed, non-polymorphic (real-FK-per-owner-type) design.
- Attachment metadata/security (never trusting original filenames, MIME/size validation, no internal IDs exposed).
- Attachment authorization inheriting the parent report's visibility, draft-only mutation, physical file cleanup on deletion.
- The specified flat API surface with explicit workflow-action endpoints and attachment endpoints.
- Explicit Scheduler/Notification/Messaging non-integration.
- Relational-integrity extensions to Client/Project/Task/Staff deletion guards.
- Comprehensive automated tests (see §9).
- Full documentation set (this handoff plus DECISIONS/ROADMAP/CURRENT_STATE/CHANGELOG/architecture/database-model/security-model/API-conventions/phase definition).
- All quality gates green.

## 4. Implementation Summary

**Schema.** `service_reports` (`public_id` ULID; required `client_id`/`restrictOnDelete()`; nullable `project_id`/`task_id`/`restrictOnDelete()`; required `creator_staff_id`/`restrictOnDelete()` — a real business-authority column mirroring `schedule_entries.creator_staff_id`; `created_by_user_id` nullable/`nullOnDelete()`; `service_date` DATE; `work_performed`/`findings`/`recommendations`/`follow_up_actions` `text` columns, bounded at the validation layer (10,000/5,000/5,000/5,000 chars); `site_representative_name` nullable plain string; `status` string). `service_report_participants` — a plain composite-PK pivot mirroring `schedule_entry_participants` exactly. `service_report_actions` — append-only history mirroring `leave_request_actions`. `attachments` — the shared table (see below).

**Workflow.** `App\Enums\ServiceReportStatus` (`draft`/`submitted`/`reviewed`/`rejected`) and `App\Enums\ServiceReportActionType` (`submitted`/`rejected`/`returned_to_draft`/`resubmitted`/`reviewed`). `ServiceReportController::submit()` inspects prior history to decide `submitted` vs. `resubmitted`. Every transition validates its own required starting status and aborts `409` otherwise.

**Relational coherence.** `App\Http\Requests\ServiceReports\Concerns\ResolvesServiceReportReferences` validates: a supplied Project must belong to the selected Client; a supplied Task must not contradict the selected Client/Project (with `project_id` server-derived from `task_id` when omitted, extending Work Log's single-source-of-truth rule with an added Client-coherence layer). The controller re-derives the same values at persistence time (mirroring `WorkLogController`'s identical two-layer pattern).

**Authorization.** `App\Http\Controllers\Api\V1\ServiceReports\Concerns\AuthorizesServiceReportAccess` resolves visibility (creator/participant/creator's-current-Manager/linked-Project's-Project-Lead/Administrator), review authority (Manager/Project-Lead/Administrator, never the creator), and draft-management authority (creator/Administrator only) entirely in-controller — no new permission. This is deliberately narrower than Schedule Entries' model: a Manager holding `projects.view` does not automatically see every Project-linked report.

**Attachments (DEC-041).** `App\Models\Attachment` — a shared table with a typed, non-polymorphic `owner_type` (`App\Enums\AttachmentOwnerType`, one case: `service_report`) paired with a real, `NOT NULL`, `cascadeOnDelete()` `service_report_id` foreign key — never a Laravel-style `attachable_type` raw-class-name column with a bare `attachable_id`. Storage goes through `App\Support\Attachments\AttachmentDisk` (the sole point of access to `config('attachments.disk')`, defaulting to Laravel's private `local` disk) and `App\Services\Attachments\AttachmentStorage` (generates a ULID-based physical filename, never trusting the original). A conservative allowlist (JPEG/PNG/PDF, 10 MB max) is enforced via Laravel's content-sniffing `mimes` rule. Downloads inherit the parent report's visibility; upload/removal require draft status plus creator/Administrator identity. Deleting a draft's attachments (individually or via deleting the draft) removes the physical file before the database row.

## 5. Files Changed

**Added (backend, `apps/api`):**
- Enums: `app/Enums/ServiceReportStatus.php`, `ServiceReportActionType.php`, `AttachmentOwnerType.php`.
- Models: `app/Models/ServiceReport.php`, `ServiceReportAction.php`, `Attachment.php`.
- Migrations: `2026_09_13_180000_create_service_reports_table.php`, `..._180001_create_service_report_participants_table.php`, `..._180002_create_service_report_actions_table.php`, `..._180003_create_attachments_table.php`.
- Controllers: `app/Http/Controllers/Api/V1/ServiceReports/ServiceReportController.php`, `ServiceReportAttachmentController.php`, `Concerns/AuthorizesServiceReportAccess.php`.
- Form Requests: `app/Http/Requests/ServiceReports/{Store,Update}ServiceReportRequest.php`, `{Submit,Review,Reject}ServiceReportRequest.php`, `ReturnServiceReportToDraftRequest.php`, `StoreServiceReportAttachmentRequest.php`, `Concerns/ResolvesServiceReportReferences.php`.
- Resources: `app/Http/Resources/ServiceReportResource.php`, `ServiceReportActionResource.php`, `AttachmentResource.php`.
- Support/Services: `app/Support/Attachments/AttachmentDisk.php`, `app/Services/Attachments/AttachmentStorage.php`.
- Config: `config/attachments.php`.
- Factories: `database/factories/ServiceReportFactory.php`, `AttachmentFactory.php`.
- Tests: `tests/Feature/Api/V1/ServiceReports/{ServiceReportTest,ServiceReportLifecycleTest,ServiceReportAttachmentTest}.php`.

**Modified (backend):**
- `routes/api/v1.php` — new Service Reports/Attachments route group.
- `app/Models/Client.php`/`Project.php`/`Task.php`/`Staff.php` — new `serviceReports()`/`createdServiceReports()`/`serviceReportParticipations()` relations.
- `app/Http/Controllers/Api/V1/Clients/ClientController.php`, `Projects/ProjectController.php`, `Tasks/TaskController.php`, `Staff/StaffController.php` — `destroy()` extended with Service Report deletion guards.
- `tests/Feature/Api/V1/{Clients/ClientTest,Projects/ProjectTest,Tasks/TaskTest,Staff/StaffTest}.php` — new relational-integrity tests.

**Documentation:** `docs/DECISIONS.md` (DEC-041), `docs/ROADMAP.md`, `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/02_ARCHITECTURE.md` (§28 added, §7 updated), `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`, `docs/phases/V1_PHASE_18_DEFINITION.md` (new), this handoff (new).

## 6. Database/Schema Changes

Four new tables: `service_reports`, `service_report_participants`, `service_report_actions`, `attachments` — see §4 above and the migration files themselves for full column-level detail and rationale. All foreign keys to master/business data (`client_id`, `project_id`, `task_id`, `creator_staff_id`) are `restrictOnDelete()`; child-only rows (`service_report_participants`, `service_report_actions`, `attachments`, all referencing `service_reports`) are `cascadeOnDelete()`. No existing table's schema was altered.

## 7. API Changes

New versioned REST endpoints under `/api/v1` (all behind `auth:sanctum` + `account.active`, no `can:<permission>` middleware):
- `GET/POST /service-reports`, `GET/PUT/PATCH/DELETE /service-reports/{public_id}`
- `POST /service-reports/{public_id}/submit`, `/review`, `/reject`, `/return-to-draft`
- `POST /service-reports/{public_id}/attachments` (upload, `multipart/form-data`)
- `GET /service-reports/{public_id}/attachments/{public_id}/download`
- `DELETE /service-reports/{public_id}/attachments/{public_id}`

Filters on the index endpoint: `?client=`, `?project=`, `?task=`, `?staff=`, `?status=`, `?from=`/`?to=` (service-date range). Standard Laravel pagination (`?per_page=`). No internal numeric ID is ever exposed by any response.

## 8. Authorization/Security Changes

**No new permission was introduced.** Visibility/authority is resolved entirely in-controller (`AuthorizesServiceReportAccess`) — see §4. This is the first phase to introduce genuine file-upload handling: type/size validation (content-sniffed, not just extension), a private (never web-served) storage disk, generated (never client-controlled) storage filenames, and authenticated/authorized-only downloads. Antivirus/malware scanning was not added (unchanged, documented future consideration).

## 9. Tests Added or Changed

- `ServiceReportTest.php` (24 tests) — creation, structural validation, relational coherence (Project/Client mismatch, Task/Project mismatch, Task-derived-Project/Client mismatch, independent-Task+Project rejection), self-service eligibility (Project membership, Task assignment), Administrator-on-behalf creation, filters, pagination, no internal IDs.
- `ServiceReportLifecycleTest.php` (29 tests) — visibility (creator/participant/manager/project-lead/administrator/unrelated), editing/deletion immutability across all four statuses, every workflow transition (submit/review/reject/return-to-draft) including invalid-transition rejections, review-authority edge cases (self-review, unrelated manager, participant), full action-history ordering (`submitted`→`rejected`→`returned_to_draft`→`resubmitted`→`reviewed`), suspended-account behavior.
- `ServiceReportAttachmentTest.php` (15 tests) — upload (image/PDF acceptance, disallowed type/oversized rejection, draft-only, authorization), download (creator/participant/unrelated/wrong-report), removal (authorization, draft-only, physical file deletion), cleanup on report deletion.
- New relational-integrity tests added to `ClientTest`, `ProjectTest`, `TaskTest`, `StaffTest` (2 tests) for the extended `destroy()` guards.
- Total new: 68 tests / 142 assertions specific to this phase (plus the relational-integrity additions counted within existing files' totals).

## 10. Commands/Checks Executed

```
composer validate --strict
vendor/bin/pint --test / vendor/bin/pint
vendor/bin/phpstan analyse
php artisan migrate:fresh
php artisan migrate:fresh --seed
php artisan test --filter=ServiceReport
php artisan test   (full suite)
```

## 11. Results

- `composer validate --strict`: `./composer.json is valid`
- `vendor/bin/pint --test`: `{"tool":"pint","result":"passed"}`
- `vendor/bin/phpstan analyse`: `{"tool":"phpstan","result":"passed","errors":0}` (level 5)
- `php artisan migrate:fresh`: all 40 migrations ran cleanly
- `php artisan migrate:fresh --seed`: `RolePermissionSeeder` ran cleanly (no catalog change)
- `php artisan test --filter=ServiceReport`: `{"tool":"phpunit","result":"passed","tests":68,"passed":68,"assertions":142}`
- `php artisan test` (full suite): `{"tool":"phpunit","result":"passed","tests":780,"passed":780,"assertions":2089}`

## 12. Deviations from Specification

- **PHPStan-driven type fixes (level 5, no behavioral change):** `ServiceReportAttachmentController::store()` originally wrote `$file->getMimeType() ?? $file->getClientMimeType()` and `$file->getSize() ?? 0` — PHPStan correctly flagged both left-hand expressions as non-nullable in this Laravel/Symfony version, so both `??` fallbacks were dead code; simplified to `$file->getMimeType()`/`$file->getSize()` directly. `download()`'s return type was corrected from `Illuminate\Http\Response` to `Symfony\Component\HttpFoundation\StreamedResponse`, matching what `Storage::download()` actually returns. Neither change altered runtime behavior.
- **A privacy fix found during self-review before running tests:** the initial `AuthorizesServiceReportAccess::authorizeReview()` did not call `authorizeView()` first, so a total stranger (no relationship to the report at all) attempting to review it would have received `403` instead of `404` — inconsistent with this codebase's established "existence is itself sensitive" convention (`AuthorizesScheduleEntryAccess::authorizeManage()`'s identical shape). Fixed to call `authorizeView()` first; the corresponding test (`test_an_unrelated_manager_cannot_review_a_report`) was updated to assert `404`.
- **Test syntax fix:** three test files initially used `[, ] = $this->actingAsStaffMember();` to discard both returned values; Pint's formatter rewrote the whitespace to `[] = ...`, which PHP rejects as "Cannot use empty list." Replaced with a plain (non-destructuring) method call in each case — no test behavior changed.
- No deviation from the 23-point product-owner specification itself was made.

## 13. Known Issues/Limitations

- Consistent with every prior module: no Flutter mobile UI, no Admin Backoffice UI — API/backend only.
- Object storage provider for production remains an open question in the abstract (`02_ARCHITECTURE.md` §9/§7) — this phase resolves only what V1 needs (a private local disk behind a swappable config value), not the production provider itself.
- `service_report_actions` is a scoped, per-module history table, not a replacement for the still-unbuilt, project-wide Audit Logging concern (DEC-009).
- The file-storage/database consistency boundary documented throughout (attachments migration, `AttachmentStorage`, DEC-041): a crash between physical file deletion and the database row's deletion can in principle leave a dangling DB reference to an already-removed file, never an orphaned file with no reference. This is an accepted, documented boundary, not a defect.
- Docker-based re-verification and GitHub Actions CI were not exercised this session (no Docker/CI configuration changed; consistent with recent sessions' precedent — see `docs/testing/TEST_STATUS.md`).

## 14. Manual/UAT Testing Instructions

Via `php artisan serve` (or the Dockerized backend) plus a Sanctum bearer token:
1. As a Staff-linked user, `POST /api/v1/service-reports` with a `client_id` (and optionally `project_id`/`task_id`) — confirm `201` and `status: draft`.
2. `PUT` the draft to change `work_performed` — confirm it applies. `POST .../submit` — confirm `status: submitted` and the response's `history` gains a `submitted` entry.
3. As the creator's Manager (or a linked Project's Project Lead), `POST .../reject` with a `reason` — confirm `status: rejected`. As the creator again, `POST .../return-to-draft`, edit, then `POST .../submit` again — confirm the new history entry reads `resubmitted`, not `submitted`.
4. As the Manager/Project Lead again, `POST .../review` — confirm `status: reviewed`, and that further edits/deletes/attachment changes are all rejected (`409`).
5. Upload a JPEG/PNG/PDF via `POST .../attachments` (multipart) to a fresh draft report — confirm `201` and that a `.exe`/oversized file is rejected (`422`). Confirm an unrelated Staff member gets `404` on the report itself and on `GET .../attachments/{public_id}/download`.
6. Attempt every documented invalid transition (e.g. `review` on a `draft`, `submit` on a `reviewed` report) — confirm `409` in each case.

See `docs/testing/UAT_LOG.md` (`UAT-18-01` through `UAT-18-04`) for the formal scenarios awaiting product-owner sign-off — none has been marked `PASS` here, per CLAUDE.md §7.

## 15. Documentation Updated

`docs/DECISIONS.md` (DEC-041), `docs/ROADMAP.md`, `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`, `docs/phases/V1_PHASE_18_DEFINITION.md`, this handoff.

## 16. Recommended Next Step

Phase 19 — Incident Reports, per `docs/ROADMAP.md` — **not authorized**. Per CLAUDE.md §8 (Stop Discipline), this session stops here and awaits explicit product-owner authorization before beginning any further work, including opening a pull request for this branch. Phase 19 is expected to reuse this phase's `service_report_actions` shape for its own incident-progress history and to extend the shared `attachments` table with its own `incident_report_id` column and `AttachmentOwnerType` case, per DEC-041's explicit design intent.
