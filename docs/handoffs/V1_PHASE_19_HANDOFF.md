# Phase 19 — Incident Reports — Handoff

## 1. Phase Identification

**Phase:** 19 — Incident Reports
**Date:** 2026-09-13 (corrected — see note below; the original delivery mis-stated this as 2026-09-14, a generated-date slip, not a documented repository convention)
**Branch:** `claude/phase-19-incident-reports-audit-5e4awt` (branched from `main` at `91578ad`, Phase 18 merged)

## 2. Objective

Implement Incident Reports per the product-owner-approved decisions that superseded the preceding planning audit's preliminary recommendations, extending the shared attachment infrastructure Phase 18 built (`03_DATABASE_MODEL.md` §1/DEC-041) to its second authorized consumer.

**Post-review clarification (2026-09-13):** an implementation review requested explicit confirmation that `assign`/`reassign` respect final-state (`resolved`/`closed`) immutability, including for Administrator, restored only via `reopen`. This was already correctly implemented in the original delivery (`assign()`/`reassign()` both call `status->isContentMutable()` unconditionally, with no Administrator bypass) but was not stated with full clarity in this handoff or in DEC-042/the phase definition, and lacked explicit closed-state and post-reopen-reassignment test coverage. This revision adds that test coverage (11 new tests — see §9/§12), makes the rule explicit in `docs/DECISIONS.md` (DEC-042) and `docs/phases/V1_PHASE_19_DEFINITION.md`, and corrects this handoff's date. No application behavior changed.

## 3. Scope Implemented

Everything in the product owner's 35-point instruction was implemented:
- Naming correction: canonical `incident_reports`/`incident_report_actions`/`incident_report_participants` (not the stale `incidents`/`incident_actions` working names `03_DATABASE_MODEL.md` originally sketched).
- One generic Incident Report entity with a closed `incident_type` taxonomy — no category-specific submodules.
- Client/Project/Task all optional, with relational coherence enforced via a fresh reference-resolution trait.
- `occurred_at` as a genuine UTC datetime (with an explicit set-mutator ensuring true UTC storage regardless of submitted offset — see §12), reusing `CompanyTimezone` where company-timezone interpretation is needed; no second timezone system; no separate `reported_at`.
- Free-text `location` only — no master-data table, no GPS.
- Required, immutable `reporter_staff_id`; optional `assigned_to_staff_id` never accepted at creation.
- Assignment as a first-class, history-tracked concept via dedicated `assign`/`reassign`/bundled-`start-investigation` endpoints, with authority restricted to the reporter's current Manager or Administrator.
- A simple Staff participant pivot with no role/status/RSVP/witness-role enum.
- Narrative-only external-person/witness fields (`people_involved`/`witness_notes`) — no structured tables, no linkage to Users/Contacts.
- Closed `IncidentSeverity` enum, default `medium`.
- The exact investigation-oriented workflow (`reported`/`under_investigation`/`resolved`/`closed`, plus a `reopen` action) — never Service Reports' draft/submitted/reviewed/rejected shape.
- Status-and-actor-dependent editing authority exactly as specified per state.
- Resolution requirements (`resolution` + at least one of `corrective_action`/`immediate_action_taken`, never `root_cause` universally).
- Closing authority exactly as specified — no Project-Lead carve-out.
- Visibility deliberately narrower than Service Reports' model — no Project-Lead visibility at all.
- No confidentiality flag, no HR/harassment/whistleblower workflow.
- Append-only `incident_report_actions` history covering every specified event type.
- The shared `attachments` table extended with a second, typed owner type via a purely additive migration.
- Attachment authorization/lifecycle exactly as specified (status- and actor-gated mutation).
- Deletion rules exactly as specified (reported + unassigned only; reporter/Administrator only).
- The specified flat API surface with explicit workflow/assignment-action endpoints and attachment endpoints.
- Explicit filters (Client/Project/Task/reporter/assigned/status/severity/incident_type/date range).
- Relational-integrity extensions to Client/Project/Task/Staff deletion guards.
- No new permission.
- Comprehensive automated tests (see §9).
- Full documentation set (this handoff plus DECISIONS/ROADMAP/CURRENT_STATE/CHANGELOG/architecture/database-model/security-model/API-conventions/phase definition/test-status/UAT-log).
- All quality gates green.

## 4. Implementation Summary

**Schema.** `incident_reports` (`public_id` ULID; **all of** `client_id`/`project_id`/`task_id` nullable/`restrictOnDelete()` — deliberately more permissive than Service Reports' required-Client anchor; required `reporter_staff_id`/`restrictOnDelete()`; nullable `assigned_to_staff_id`/`restrictOnDelete()`; `created_by_user_id` nullable/`nullOnDelete()`; `occurred_at` UTC `datetime`; `location` nullable string; `incident_type`/`severity` strings backed by closed enums; narrative `text` columns bounded at the validation layer; `status` string). `incident_report_participants` — a plain composite-PK pivot mirroring `service_report_participants` exactly. `incident_report_actions` — append-only history mirroring `service_report_actions`, but with the initial `reported` entry recorded by the model's own `created` event (see §4's Action History note below), not the controller. `attachments` — extended, not recreated (see below).

**Naming correction.** `03_DATABASE_MODEL.md`'s Phase-0-era sketch (`incidents`/`incident_actions`) is corrected throughout the documentation to the canonical `incident_reports`/`incident_report_actions`/`incident_report_participants`, matching product terminology, Service Reports' own convention, and the `incident_report_id` FK name Phase 18 already committed to.

**Workflow.** `App\Enums\IncidentReportStatus` (`reported`/`under_investigation`/`resolved`/`closed`) and `App\Enums\IncidentReportActionType` (`reported`/`assigned`/`reassigned`/`investigation_started`/`resolved`/`closed`/`reopened`). Every transition (`IncidentReportController::startInvestigation()`/`resolve()`/`close()`/`reopen()`) validates its own required starting status inside a `DB::transaction()` with a `refresh()` guard against a stale in-request read, aborting `409` otherwise — mirroring `ServiceReportController`'s identical transaction/refresh/abort shape exactly.

**Assignment.** `assign()`/`reassign()` are dedicated endpoints requiring, respectively, currently-unassigned/currently-assigned state; both are gated by `canManageAssignment()` (reporter's current Manager or Administrator only). `startInvestigation()` may bundle an initial or changed assignment — supplying `assigned_to_staff_id` in that call requires assignment authority specifically (checked before the transaction), while calling it with no assignee (on an already-assigned report) requires only investigation-management authority (which the assigned investigator themselves holds). Every assignment/reassignment, however triggered, is logged as its own `incident_report_actions` row.

**Relational coherence.** `App\Http\Requests\IncidentReports\Concerns\ResolvesIncidentReportReferences` — a fresh trait, not a literal reuse of `ResolvesServiceReportReferences`, since Client's optionality here would have forced that trait's "Client is always present" logic into awkward branching. Validates: a supplied Project must belong to a supplied Client; a supplied Task must not contradict Client/Project (with `project_id` server-derived from `task_id` when omitted). Unlike Service Reports, every check is conditioned on the relevant field actually being supplied — no field's absence is itself an error.

**Editing authority.** `App\Http\Controllers\Api\V1\IncidentReports\Concerns\AuthorizesIncidentReportAccess::canManageContent()` is status-dependent: while `reported`, the reporter themselves additionally qualifies alongside the assigned investigator/reporter's-Manager/Administrator; once `under_investigation`, only the latter three do. `resolved`/`closed` are content-/attachment-immutable for everyone (the generic-update path is blocked by a separate `status->isContentMutable()` 409 check, applied after the 403 authority check — mirroring `ServiceReportController::update()`'s exact two-step ordering).

**Visibility (no new permission).** `AuthorizesIncidentReportAccess` grants visibility to the reporter, assigned investigator, participants, reporter's current Manager, and Administrator — **deliberately omitting the Project-Lead carve-out Service Reports has**, per explicit product-owner instruction. `scopeVisibleIncidentReports()` is the query-scoping counterpart applied to the index endpoint.

**Resolution requirements.** `ResolveIncidentReportRequest::withValidator()` computes the *effective* `resolution`/`corrective_action`/`immediate_action_taken` (this request's value, or the existing record's) and rejects the transition unless `resolution` is non-blank and at least one of the other two is — mirroring `UpdateServiceReportRequest`'s "effective combination" pattern for optional fields.

**Attachments (DEC-042, extending DEC-041).** `App\Enums\AttachmentOwnerType::IncidentReport` (new case) pairs with a new nullable `incident_report_id` FK/`cascadeOnDelete()` added to the existing `attachments` table via one additive migration; `attachments.service_report_id` is widened from `NOT NULL` to nullable in the same migration (a second owner type means every row now populates exactly one of the two FKs). `App\Services\Attachments\AttachmentStorage::store()` gained an optional third parameter (`$ownerTypeSegment`, defaulting to `'service-reports'`) so every existing Service Report call site/stored path is unaffected; `IncidentReportAttachmentController` passes `'incident-reports'`. Attachment mutation requires both `status->isContentMutable()` and the same `canManageContent()` authority gating the report's own content.

**Action history / model event.** Unlike Service Reports (whose first history row is created by the controller at `submit()` time), `IncidentReport`'s own model `created` event records the initial `reported` action — chosen deliberately so that *any* creation path (API, factory, a future seeder or Tinker session) produces a complete, accurate history, not only the API path. This was validated directly by a factory-only test asserting the `reported` row exists without ever calling the API (see §9).

## 5. Files Changed

**Added (backend, `apps/api`):**
- Enums: `app/Enums/IncidentReportType.php`, `IncidentSeverity.php`, `IncidentReportStatus.php`, `IncidentReportActionType.php`.
- Models: `app/Models/IncidentReport.php`, `IncidentReportAction.php`.
- Migrations: `2026_09_14_190000_create_incident_reports_table.php`, `..._190001_create_incident_report_participants_table.php`, `..._190002_create_incident_report_actions_table.php`, `..._190003_add_incident_report_id_to_attachments_table.php`.
- Controllers: `app/Http/Controllers/Api/V1/IncidentReports/IncidentReportController.php`, `IncidentReportAttachmentController.php`, `Concerns/AuthorizesIncidentReportAccess.php`.
- Form Requests: `app/Http/Requests/IncidentReports/{Store,Update}IncidentReportRequest.php`, `{Assign,Reassign,StartInvestigation,Resolve,Close,Reopen}IncidentReportRequest.php`, `StoreIncidentReportAttachmentRequest.php`, `Concerns/ResolvesIncidentReportReferences.php`.
- Resources: `app/Http/Resources/IncidentReportResource.php`, `IncidentReportActionResource.php` (the existing, owner-agnostic `AttachmentResource` is reused as-is).
- Factories: `database/factories/IncidentReportFactory.php`.
- Tests: `tests/Feature/Api/V1/IncidentReports/{IncidentReportTest,IncidentReportLifecycleTest,IncidentReportAttachmentTest}.php`.
- Documentation: `docs/phases/V1_PHASE_19_DEFINITION.md`, this handoff.

**Modified (post-review clarification — assignment final-state immutability):**
- `tests/Feature/Api/V1/IncidentReports/IncidentReportLifecycleTest.php` — 10 new tests added to the Assignment section (see §9/§12); no existing test removed or renamed.
- `docs/DECISIONS.md` (DEC-042) — the Reporter and Assignment paragraph and the Workflow-meaning bullet list now explicitly state that `assign`/`reassign` are gated by the same `status->isContentMutable()` rule as content editing, with no Administrator bypass, restored only via `reopen`.
- `docs/phases/V1_PHASE_19_DEFINITION.md` — new "Assignment state gate" paragraph under Assignment, stating the same rule and the required `resolved`/`closed` → `reopen` → `under_investigation` → `reassign` sequence.
- `docs/CHANGELOG.md` — Phase 19 entry's Assignment bullet extended with the same clarification; heading date corrected.
- This handoff — date corrected, this note added, §9/§11/§12 updated with the new test counts/results.

**Modified (backend):**
- `app/Enums/AttachmentOwnerType.php` — new `IncidentReport` case.
- `app/Models/Attachment.php` — `incident_report_id` in `$fillable`/docblock, `incidentReport()` relation.
- `app/Models/Client.php`/`Project.php`/`Task.php` — new `incidentReports()` relation.
- `app/Models/Staff.php` — new `reportedIncidentReports()`/`assignedIncidentReports()`/`incidentReportParticipations()` relations.
- `app/Services/Attachments/AttachmentStorage.php` — `store()` gained an optional `$ownerTypeSegment` parameter (default preserves existing behavior exactly).
- `app/Http/Controllers/Api/V1/Clients/ClientController.php`, `Projects/ProjectController.php`, `Tasks/TaskController.php`, `Staff/StaffController.php` — `destroy()` extended with Incident Report deletion guards (reporter/assignee/participant, for Staff).
- `config/attachments.php` — doc comment updated to mention the second consumer (no behavioral change).
- `routes/api/v1.php` — new Incident Reports/Attachments route group.
- `tests/Feature/Api/V1/{Clients/ClientTest,Projects/ProjectTest,Tasks/TaskTest,Staff/StaffTest}.php` — new relational-integrity tests.

**Documentation:** `docs/DECISIONS.md` (DEC-042), `docs/ROADMAP.md`, `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/02_ARCHITECTURE.md` (§29 added, §7 updated), `docs/03_DATABASE_MODEL.md` (naming correction + new entries), `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md` (new Incident Report Privacy section + Authorization/API Access/File Uploads/Data-isolation updates), `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`, `docs/phases/V1_PHASE_19_DEFINITION.md` (new), this handoff (new).

## 6. Database/Schema Changes

Three new tables (`incident_reports`, `incident_report_participants`, `incident_report_actions`) plus one schema-extension migration on the existing `attachments` table (adds nullable `incident_report_id`/`cascadeOnDelete()`; widens `service_report_id` from `NOT NULL` to nullable via `->change()`, verified to run cleanly against SQLite — see §11). All foreign keys to master/business data (`client_id`, `project_id`, `task_id`, `reporter_staff_id`, `assigned_to_staff_id`) are `restrictOnDelete()`; child-only rows (`incident_report_participants`, `incident_report_actions`, and `attachments` via `incident_report_id`) are `cascadeOnDelete()`. No other existing table's schema was altered.

## 7. API Changes

New versioned REST endpoints under `/api/v1` (all behind `auth:sanctum` + `account.active`, no `can:<permission>` middleware):
- `GET/POST /incident-reports`, `GET/PUT/PATCH/DELETE /incident-reports/{public_id}`
- `POST /incident-reports/{public_id}/assign`, `/reassign`, `/start-investigation`, `/resolve`, `/close`, `/reopen`
- `POST /incident-reports/{public_id}/attachments` (upload, `multipart/form-data`)
- `GET /incident-reports/{public_id}/attachments/{public_id}/download`
- `DELETE /incident-reports/{public_id}/attachments/{public_id}`

Filters on the index endpoint: `?client=`, `?project=`, `?task=`, `?reporter=`, `?assigned=`, `?status=`, `?severity=`, `?incident_type=`, `?from=`/`?to=` (occurrence-date range). Standard Laravel pagination (`?per_page=`). No internal numeric ID is ever exposed by any response.

## 8. Authorization/Security Changes

**No new permission was introduced.** Visibility/authority is resolved entirely in-controller (`AuthorizesIncidentReportAccess`) — see §4. This is the second phase to handle file uploads, reusing every control Phase 18 established unchanged (allowlist, size cap, private disk, generated filenames, authenticated download route) — no new upload mechanism was introduced. The tenth row-level visibility shape in this codebase, and the narrowest yet: no Project-Lead carve-out at all, and — a genuinely new dimension — content-management authority additionally depends on the report's own status, not just the requester's relationship to it.

## 9. Tests Added or Changed

- `IncidentReportTest.php` (34 tests) — creation (fully-internal, Client-only, Project-only-no-Client), structural validation (required fields, future-datetime rejection, invalid enum values), severity default/override, `assigned_to_staff_id` rejected at creation, Administrator-on-behalf creation, relational coherence (Project/Client mismatch, Task/Project mismatch, Task-derived-Project/Client mismatch, independent-Task+Project rejection, Task-alone-no-Client acceptance), filters (client/project/task/reporter/assigned/status/severity/incident_type/date-range), pagination, no internal IDs, ISO-8601 UTC `occurred_at` round-trip.
- `IncidentReportLifecycleTest.php` (57 tests) — visibility (reporter/assignee/participant/manager/unrelated-manager/Project-Lead-excluded/administrator), editing authority per status (`reported` vs. `under_investigation`, reporter/manager/investigator/participant combinations), immutability once resolved/closed, `reporter_staff_id`/`assigned_to_staff_id` never changeable via generic update, deletion rules (reporter/administrator, blocked once assigned/investigating/resolved/closed), assignment (assign/reassign authority and state guards, self-reassignment-by-investigator rejected), start-investigation (assignee-required validation, bundled-assignment authority split), resolve (resolution/corrective-or-immediate requirements, root_cause never required, effective-value reuse), close (investigator/manager authority, Project-Lead excluded), reopen (from resolved and from closed, restores editing, restores attachment mutation covered in the attachment test file), invalid-transition rejections, and full action-history ordering across the entire lifecycle including a reopen-then-reresolve cycle.
- `IncidentReportAttachmentTest.php` (19 tests) — upload (reporter/investigator authorization, disallowed type/oversized rejection, participant/unrelated rejection, blocked once resolved/closed, restored after reopen), download (reporter/investigator/participant/unrelated/Project-Lead-excluded/wrong-report), removal (authorization, blocked once resolved), cleanup on report deletion, and cross-module compatibility (`test_a_service_report_attachment_is_unaffected_by_the_incident_report_extension`, `test_incident_report_and_service_report_attachments_coexist_independently`) proving the shared-table extension left Phase 18 behavior untouched.
- New relational-integrity tests added to `ClientTest` (1), `ProjectTest` (1), `TaskTest` (1), `StaffTest` (3 — reporter/assignee/participant) for the extended `destroy()` guards.
- **Post-review addition — 10 new tests in `IncidentReportLifecycleTest.php`'s Assignment section:** `reassign` succeeding while `under_investigation` (Manager and, separately, Administrator); `assign`/`reassign` each rejected `409` when `resolved` and again when `closed` (four tests, explicit closed-state coverage that was previously untested); Administrator receiving the identical `409` in three of those final-state scenarios (no bypass); reassignment succeeding again after `reopen` from `resolved`, and again after `reopen` from `closed`, each asserting the resulting `reassigned` action-history row. All 10 passed on the first run against the existing (unmodified) controller code, confirming the rule was already correctly implemented — see §12.
- Total new: 120 tests / 248 assertions specific to this phase's own test files (110/228 from the original delivery + 10/20 from this review round), plus 6 relational-integrity tests added to existing files.

## 10. Commands/Checks Executed

```
composer install --no-interaction --prefer-dist --no-progress
composer validate --strict
vendor/bin/pint --test / vendor/bin/pint
vendor/bin/phpstan analyse
php artisan migrate:fresh
php artisan migrate:fresh --seed
php artisan test --filter=IncidentReport
php artisan test   (full suite)
```

## 11. Results

- `composer validate --strict`: `./composer.json is valid`
- `vendor/bin/pint --test` (first run): 2 files flagged (`ordered_imports`, `fully_qualified_strict_types` — both auto-fixable style issues, not logic bugs); `vendor/bin/pint` fixed both; re-run: `{"tool":"pint","result":"passed"}`
- `vendor/bin/phpstan analyse` (first run): 1 error — `nullsafe.neverNull` on `IncidentReportResource::occurred_at` (see §12 for the fix); re-run: `{"tool":"phpstan","result":"passed","errors":0}` (level 5)
- `php artisan migrate:fresh`: all 44 migrations ran cleanly (40 pre-existing + 4 new), including the `attachments.service_report_id` nullable-widening `->change()` against SQLite
- `php artisan migrate:fresh --seed`: `RolePermissionSeeder` ran cleanly (no catalog change — no new permission this phase)
- `php artisan test --filter=IncidentReport` (original delivery, first run): 2 failures (see §12 for both); after fixes: `{"tool":"phpunit","result":"passed","tests":110,"passed":110,"assertions":228}`
- `php artisan test` (full suite, original delivery): `{"tool":"phpunit","result":"passed","tests":910,"passed":910,"assertions":2360}` — the full Phase 1–18 regression suite (794 tests, matching Phase 18's own recorded count) plus this phase's 110 new tests plus 6 new relational-integrity tests, all passing together

**Post-review clarification round (assignment final-state immutability):**
- `composer validate --strict`: `./composer.json is valid`
- `vendor/bin/pint --test`: `{"tool":"pint","result":"passed"}`
- `vendor/bin/phpstan analyse`: `{"tool":"phpstan","result":"passed","errors":0}` (level 5)
- `php artisan migrate:fresh`: all 44 migrations ran cleanly (no schema change in this round)
- `php artisan migrate:fresh --seed`: `RolePermissionSeeder` ran cleanly (no catalog change)
- `php artisan test --filter=IncidentReport`: `{"tool":"phpunit","result":"passed","tests":120,"passed":120,"assertions":248}` — all 10 new tests passed on the first run against the unmodified controller (see §12)
- `php artisan test` (full suite): `{"tool":"phpunit","result":"passed","tests":920,"passed":920,"assertions":2380}` — the full Phase 1–18 regression suite plus this phase's now-120 tests plus 6 relational-integrity tests, all passing together

## 12. Deviations from Specification

No deviation from the 35-point product-owner specification itself was made. Two real bugs were found and fixed by this phase's own test suite before this handoff was written:

- **`occurred_at` timezone normalization (a real correctness bug, not a style issue):** the approved design requires `occurred_at` to be "stored in UTC" regardless of what timezone offset a client submits. Eloquent's `datetime` cast, by itself, only *reformats* a value for database storage — it does not convert timezones. A value submitted as `2026-09-10T08:30:00+02:00` was being stored as `2026-09-10 08:30:00` (the same wall-clock digits, silently relabeled as UTC on read-back) rather than the genuinely equivalent UTC instant `06:30:00`. This was caught by `test_occurred_at_is_returned_in_iso8601_utc`. Fixed by adding an explicit `Attribute`-based set-mutator on `IncidentReport::occurredAt()` (`Carbon::parse($value)->utc()`) that normalizes to a true UTC instant before the value ever reaches the `datetime` cast/database — the cast still governs read-side rehydration into a Carbon instance, unchanged.
- **A factory/test-fixture bug, not a bug in application code:** `IncidentReportFactory`'s `immediate_action_taken` field uses `fake()->optional()->sentence()` (mirroring `ServiceReportFactory`'s deliberate realistic-variability style for its own optional narrative fields). One test asserting the "resolving requires `corrective_action` OR `immediate_action_taken`" rule needs both fields to start genuinely blank to exercise that branch reliably; left to the factory's random default, roughly half of test runs would have had `immediate_action_taken` already non-blank, masking the very requirement under test. Fixed by explicitly pinning both fields to `null` in that one test's fixture, rather than changing the factory's intentional randomness (which is fine, and arguably more realistic, everywhere else).
- **PHPStan-driven type fix (level 5, no behavioral change):** `IncidentReportResource::occurred_at` used a nullsafe `?->toIso8601String()` call on a property PHPStan correctly identified as non-nullable (per `IncidentReport`'s own `@property Carbon $occurred_at` docblock) — corrected to a plain `->`, mirroring `ServiceReportResource::service_date`'s identical non-nullable precedent exactly.

**Post-review clarification round.** An implementation review flagged that the handoff's own phrasing of `assign()`/`reassign()` ("requires currently unassigned"/"requires currently assigned") did not clearly state whether either was also blocked once `resolved`/`closed`, and asked for explicit confirmation, for Administrator included, restored only via `reopen`. On inspection, `IncidentReportController::assign()`/`reassign()` **already** call `$incidentReport->status->isContentMutable()` unconditionally (the same check gating the generic `update()` endpoint) — this abort runs before either method's unassigned/assigned state check, applies regardless of who the caller is (the `canManageAssignment()` authority check that runs first grants no bypass of it), and the only route back to a mutable state is the existing `reopen()` action. **No controller code changed in this round** — this was a documentation/test-coverage gap, not a logic defect: the original delivery's tests exercised this rule only implicitly (`test_assignment_is_blocked_once_resolved_or_closed` covered `assign`+`resolved`; `test_reassigning_a_resolved_incident_is_rejected` covered `reassign`+`resolved`, both already via `actingAsAdministrator()`) and never exercised the `closed` state or a post-`reopen` reassignment at all. Ten tests were added closing exactly those gaps (§9); all ten passed against the unmodified controller on the first run, confirming the rule holds. `docs/DECISIONS.md` (DEC-042) and `docs/phases/V1_PHASE_19_DEFINITION.md` were updated to state the rule explicitly rather than leaving it merely implied by the code.
- **Handoff date correction.** This handoff originally reported `2026-09-14` as the phase date — a generated-date slip (this session's actual working date, and Phase 18's own recorded date, is `2026-09-13`; the repository has no documented convention of dating each phase a calendar day after its predecessor — that pattern in earlier phases reflects when each phase was actually worked, not a rule). Corrected to `2026-09-13` in this handoff's §1 and in DEC-042's own date field and the `CHANGELOG.md` heading. Migration filenames (`2026_09_14_19...`) were deliberately left unchanged — they are already-run, already-tested identifiers with no functional dependency on matching the handoff's narrative date, and renaming them post-hoc would be pure churn with no benefit.

## 13. Known Issues/Limitations

- Consistent with every prior module: no Flutter mobile UI, no Admin Backoffice UI — API/backend only.
- A dedicated confidential-case design (per-record confidentiality, HR/harassment/whistleblower workflow) was deliberately not built, per the governing instructions — flagged as a possible future decision if the product owner ever requires it, never a speculative addition here.
- `incident_report_actions` is a scoped, per-module history table, not a replacement for the still-unbuilt, project-wide Audit Logging concern (DEC-009) — this remains a standing, acknowledged gap through Phase 19, unchanged from every prior phase's own note.
- The file-storage/database consistency boundary Phase 18 documented (attachments migration, `AttachmentStorage`): a crash between physical file deletion and the database row's deletion can in principle leave a dangling DB reference to an already-removed file, never an orphaned file with no reference. Unchanged, and applies identically to Incident Report attachments.
- Docker-based re-verification and GitHub Actions CI were not exercised this session (no Docker/CI configuration changed; consistent with recent sessions' precedent — see `docs/testing/TEST_STATUS.md`). This session did need a fresh `composer install` (no `vendor/` present at session start) — it completed successfully via the now-familiar per-package Git-source fallback, within the extended `COMPOSER_PROCESS_TIMEOUT=1800`, with no manual `vendor/` file surgery needed (see `docs/CURRENT_STATE.md`'s Known Blockers).

## 14. Manual/UAT Testing Instructions

Via `php artisan serve` (or the Dockerized backend) plus a Sanctum bearer token:
1. As a Staff-linked user, `POST /api/v1/incident-reports` with only `occurred_at`/`incident_type`/`description` (no Client/Project/Task at all) — confirm `201`, `status: reported`, `client`/`project`/`task`/`assigned_to` all `null`, and the response's `history` already contains one `reported` entry.
2. `PUT` the report to change `description` while still `reported` — confirm it applies. As the reporter's Manager, `POST .../assign` naming an investigator — confirm `200`, `assigned_to` populated, and a new `assigned` history entry. Confirm the reporter themselves gets `403` attempting `/assign`.
3. As the assigned investigator, `POST .../start-investigation` (no body, since already assigned) — confirm `status: under_investigation` and an `investigation_started` history entry. As the reporter (not separately qualifying), attempt `PUT` — confirm `403`.
4. As the investigator, `POST .../resolve` with `resolution` and `corrective_action` — confirm `200`, `status: resolved`; confirm a further `PUT`/attachment upload now returns `409`. `POST .../close` — confirm `status: closed`.
5. `POST .../reopen` on the closed report — confirm `status: under_investigation` again, a `reopened` history entry, and that editing/attachment upload are both restored.
6. Upload a JPEG/PNG/PDF via `POST .../attachments` (multipart) to a `reported` incident — confirm `201`, and that a `.exe`/oversized file is rejected (`422`). Confirm an unrelated Staff member gets `404` on the report itself and on `GET .../attachments/{public_id}/download`.
7. As a Project Lead of the incident's linked Project (but not the reporter's Manager and not otherwise qualifying), attempt `GET`/any workflow action — confirm `404`/`403` throughout, unlike the equivalent Service Report scenario.
8. Attempt every documented invalid transition (e.g. `resolve` on a `reported` incident, `close` on one still `under_investigation`, `assign` on an already-assigned one) — confirm `409` in each case. Attempt deletion after assignment/investigation — confirm `409`; confirm deletion succeeds while still `reported` and unassigned.

See `docs/testing/UAT_LOG.md` (`UAT-19-01` through `UAT-19-05`) for the formal scenarios awaiting product-owner sign-off — none has been marked `PASS` here, per CLAUDE.md §7.

## 15. Documentation Updated

`docs/DECISIONS.md` (DEC-042), `docs/ROADMAP.md`, `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/02_ARCHITECTURE.md` (§29 added, §7 updated), `docs/03_DATABASE_MODEL.md` (naming correction + Phase 19 entries), `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md` (new Incident Report Privacy section + Authorization/API Access/File Uploads/Data-isolation updates), `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`, `docs/phases/V1_PHASE_19_DEFINITION.md`, this handoff.

## 16. Recommended Next Step

Phase 20 — Admin Dashboard & Reporting, per `docs/ROADMAP.md` — **not authorized**. Per CLAUDE.md §8 (Stop Discipline), this session stops here and awaits explicit product-owner review of this implementation before a pull request is opened for this branch, let alone merged, and before Phase 20 begins.
