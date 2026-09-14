# Phase 21 — Integration Audit — Handoff

## 1. Phase Identification

- **Phase:** 21 — Integration Audit
- **Date:** 2026-09-14
- **Branch:** `claude/phase-21-integration-audit` (branched from `main` at `38b24d5`, Phase 20 merged; pushed to `origin`, not merged, no PR opened)

## 2. Objective

Implement Phase 21 per the product owner's explicit authorization following a preceding planning audit: two bounded deliverables — (A) the DEC-009 general Audit Log backstop the roadmap itself named Phase 21 as the fallback for, and (B) a bounded cross-module consistency review of Phases 1–20 against their own already-approved decisions, with corrections limited to two categories (implementation-vs-decision contradictions, and documentation drift). No redesign of any working module, no unrelated deferred feature pulled into scope, no pull request, and no Phase 22 work was authorized as part of this objective.

## 3. Scope Implemented

- New `audit_logs` table (`App\Models\AuditLog`) — lean, append-only schema per DEC-044.
- `App\Services\Audit\AuditLogger` — the single explicit service every integration site calls.
- `App\Support\Audit\AuditActions` — the complete stable action-name catalog.
- `App\Enums\AuditSource` — `api`/`admin`.
- Audit integration wired into: `AuthController`/`LoginForm`/the web `logout` route (login success/failure/logout); `StaffController` (create/update/separate); `DepartmentController`/`TeamController`/`PositionController` (CRUD); `ClientController`/`ContactController` (CRUD); `ProjectController` (CRUD) and `ProjectMembershipController` (add/remove/role-change); `TaskController` (deletion only); `AnnouncementController` (publish/archive); `ServiceReportAttachmentController`/`IncidentReportAttachmentController` (upload/delete); all seven Phase 20 report controllers (export); and the Audit Log's own `export()`.
- `GET /api/v1/audit-logs` + `GET /api/v1/audit-logs/export` (`AuditLogController`, `AuthorizesAuditLogAccess`) — Administrator-only, no new permission, strictly read-only.
- Full automated test coverage (62 new tests) and a full Phase 1–20 regression run (1062 tests total, all passing).
- Bounded cross-module consistency audit — findings below, with only the applicable corrections applied.
- Documentation: `docs/DECISIONS.md` (DEC-044), this handoff, `docs/phases/V1_PHASE_21_DEFINITION.md`, and updates to `docs/ROADMAP.md`, `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/00_PROJECT_CHARTER.md`, `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`.

Not implemented, per the governing instructions' explicit exclusions: S3/object-storage migration, antivirus scanning, check-in retention jobs, Messaging moderation/admin access, Incident confidentiality/HR/whistleblower workflow, Task comments, Leave→Task/Work Log automation, any Admin Backoffice or Flutter Audit Log UI, external SIEM, a third-party integration framework, Redis/queues/background workers/materialized views, automatic retention/purge, and — per the codebase investigation below — any new User account-management (suspend/reactivate/role-change) mutation surface.

## 4. Implementation Summary

**Schema.** `audit_logs`: `id`; `actor_user_id` (nullable, `nullOnDelete()`); `action` (string); `entity_type` (string, always populated); `entity_public_id` (nullable string — a generic reference, never a foreign key, never a polymorphic ownership relation); `changed_fields`/`before`/`after` (nullable JSON, curated only); `ip_address` (nullable string); `user_agent` (nullable text); `source` (string, `AuditSource`-cast); `created_at` only (`AuditLog::UPDATED_AT = null`, no `updated_at` column at all). Indexes: `(entity_type, entity_public_id)`, `(actor_user_id, created_at)`, `(action, created_at)` — no standalone `created_at` index, per the explicit "only if a real query plan justifies it" instruction (none does at this scale).

**AuditLogger architecture.** One final class, two public methods (`record()` — the primitive; `recordForRequest()` — the convenience wrapper deriving actor/IP/user-agent from the current request) plus a static `diff()` helper. Every integration site is a controller method calling `AuditLogger::recordForRequest()` (or, for the two-admin-surface login/logout paths, `record()` directly since there's no single "current request" abstraction shared between Sanctum and session auth in the same call). No model observer was added anywhere — the governing instructions set a high bar for observers ("only where they demonstrably prevent bypass across multiple legitimate mutation paths, and remain easier to reason about than explicit calls"), and every event in this phase's catalog has exactly one legitimate mutation path already (a single controller action per event), so an explicit call is simpler and sufficient. No middleware, no event/listener bus, no third-party package.

**Redaction.** Every integration site builds its own small, explicit `curatedSnapshot()`/inline array — never a generic "serialize the model" helper. `AuditLogger::diff()` only ever compares the specific field names a caller passes in; it has no knowledge of any model's full attribute set. This structurally prevents the class of bug where a future edit to a model (e.g. adding a new sensitive column) could silently start leaking into the Audit Log — a new field only ever appears if a specific integration site is deliberately extended to include it.

**Transactions.** Where an integration site's business mutation was already inside a `DB::transaction()` (Announcement `publish()`/`archive()`, both attachment controllers' upload/delete), the audit write was added inside that same closure. Where a business mutation was a single already-atomic Eloquent statement (Staff/Organization/Client/Project/Task/Membership create/update/delete), a `DB::transaction()` was not introduced around it purely for the audit write in most cases, since Eloquent's `update()`/`delete()`/`create()` are each already a single, atomic statement immediately followed by the audit write in the same PHP request — a failure in the audit write (e.g. a DB-level exception) propagates as an unhandled exception, which Laravel's exception handler turns into a `500` and — critically — since the preceding model mutation was already committed as its own statement, this is not perfectly atomic in the strictest two-phase sense for every non-transaction-wrapped site. This is a deliberate, documented trade-off: wrapping every single-statement CRUD write in a `DB::transaction()` purely to add an audit call would be a broad, mechanical change to add transactional overhead that MySQL doesn't otherwise need for a lone statement, and the realistic failure mode (the audit insert itself failing after a successful, already-durable business write) is exceptionally rare compared to the transaction-wrapped cases (multi-step business logic) where atomicity genuinely matters. The `attachment.uploaded`/`.deleted` and `announcement.published`/`.archived` events — the ones this phase's own governing instructions specifically called out as needing transactional coupling — are correctly wrapped.

**Authorization.** `AuthorizesAuditLogAccess::authorizeAdministrator()` — a direct `$request->user()?->hasRole(Role::ADMINISTRATOR)` check, aborting `403` otherwise. No permission was added to the catalog; Manager and Staff have no access at all.

**Codebase investigation — no User account-management mutation surface exists.** Before wiring `user.suspended`/`user.reactivated`/`user.role_changed`, a direct search of `app/Http/Controllers`, `app/Livewire`, and `routes/` confirmed there is no endpoint anywhere — API or Admin Backoffice — that mutates `users.status` or `users.role_id` after account creation. Building one was judged out of this phase's scope (new product behavior, not an integration-audit or audit-logging concern); the three action names are documented as reserved in DEC-044 but not defined in `AuditActions` as unused code, matching CLAUDE.md's "no speculative infrastructure" direction.

## 5. Files Changed

**New:**
- `apps/api/database/migrations/2026_09_20_100000_create_audit_logs_table.php`
- `apps/api/app/Models/AuditLog.php`
- `apps/api/app/Enums/AuditSource.php`
- `apps/api/app/Support/Audit/AuditActions.php`
- `apps/api/app/Services/Audit/AuditLogger.php`
- `apps/api/app/Http/Controllers/Api/V1/Audit/AuditLogController.php`
- `apps/api/app/Http/Controllers/Api/V1/Audit/Concerns/AuthorizesAuditLogAccess.php`
- `apps/api/app/Http/Resources/AuditLogResource.php`
- `apps/api/database/factories/AuditLogFactory.php`
- `apps/api/tests/Feature/Api/V1/Audit/AuditLogApiTest.php`
- `apps/api/tests/Feature/Api/V1/Audit/AuditLoggingCoverageTest.php`
- `apps/api/tests/Feature/Api/V1/Audit/AuditLogRedactionTest.php`
- `apps/api/tests/Unit/Services/Audit/AuditLoggerTest.php`
- `docs/phases/V1_PHASE_21_DEFINITION.md`
- `docs/handoffs/V1_PHASE_21_HANDOFF.md` (this file)

**Modified (audit integration):**
- `apps/api/app/Http/Controllers/Api/V1/Auth/AuthController.php`
- `apps/api/app/Livewire/Auth/LoginForm.php`
- `apps/api/routes/web.php`
- `apps/api/app/Http/Controllers/Api/V1/Staff/StaffController.php`
- `apps/api/app/Http/Controllers/Api/V1/Organization/{DepartmentController,TeamController,PositionController}.php`
- `apps/api/app/Http/Controllers/Api/V1/Clients/{ClientController,ContactController}.php`
- `apps/api/app/Http/Controllers/Api/V1/Projects/{ProjectController,ProjectMembershipController}.php`
- `apps/api/app/Http/Controllers/Api/V1/Tasks/TaskController.php`
- `apps/api/app/Http/Controllers/Api/V1/Announcements/AnnouncementController.php`
- `apps/api/app/Http/Controllers/Api/V1/ServiceReports/ServiceReportAttachmentController.php`
- `apps/api/app/Http/Controllers/Api/V1/IncidentReports/IncidentReportAttachmentController.php`
- `apps/api/app/Http/Controllers/Api/V1/Reports/{StaffDirectoryReportController,WorkLogReportController,LeaveRequestReportController,ProjectReportController,TaskReportController,ServiceReportReportController,IncidentReportReportController}.php`
- `apps/api/routes/api/v1.php` (new `audit-logs` route group)

**Modified (test-quality fix, pre-existing, discovered during this phase's full-suite run):**
- `apps/api/tests/Feature/Api/V1/Reports/StaffDirectoryReportTest.php`

**Documentation:**
- `docs/DECISIONS.md`, `docs/ROADMAP.md`, `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/00_PROJECT_CHARTER.md`, `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`.

No existing controller's authorization/visibility/validation logic was changed — every diff to an existing file is additive (an `AuditLogger` call, a curated snapshot helper, and in a few `destroy()` methods a `Request $request` parameter added, mirroring this codebase's own existing convention elsewhere for methods that need request context).

## 6. Database/Schema Changes

One new migration, one new table (`audit_logs`), zero changes to any existing table. See §4 for the full column list and indexes.

## 7. API Changes

New: `GET /api/v1/audit-logs` (paginated JSON, filters `from`/`to`/`actor`/`action`/`entity_type`/`entity_public_id`), `GET /api/v1/audit-logs/export` (CSV). No existing endpoint's request/response shape changed.

## 8. Authorization/Security Changes

- No new permission added to the catalog.
- `GET /api/v1/audit-logs`/`.export` — Administrator-only via a direct `hasRole()` check, no Manager or Staff access.
- Redaction discipline enforced at every integration site (see §4 and DEC-044).
- No existing authorization rule (permission, row-level visibility trait, or `Gate::before` behavior) was modified.

## 9. Tests Added or Changed

- 62 new tests across 4 files (`AuditLogApiTest`, `AuditLoggingCoverageTest`, `AuditLogRedactionTest`, `AuditLoggerTest`) — see `docs/testing/TEST_STATUS.md`'s Phase 21 section for the full breakdown.
- One pre-existing test corrected for a latent flakiness bug unrelated to this phase's own feature (`StaffDirectoryReportTest`) — see §12 below.

## 10. Commands/Checks Executed

```
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan migrate:fresh
php artisan migrate:fresh --seed
php artisan test --filter=Audit
php artisan test
```

## 11. Results

- `composer validate --strict` — `./composer.json is valid`.
- `vendor/bin/pint --test` — `{"tool":"pint","result":"passed"}` (after one auto-fix pass on two new test files — import ordering/fully-qualified-strict-types).
- `vendor/bin/phpstan analyse` — `{"tool":"phpstan","result":"passed","errors":0}` at level 5 (after fixing two `nullsafe.neverNull` findings in this phase's own new code — see §12).
- `php artisan migrate:fresh` — all 45 migrations passed.
- `php artisan migrate:fresh --seed` — `RolePermissionSeeder` ran cleanly, catalog unchanged.
- `php artisan test --filter=Audit` — `{"tool":"phpunit","result":"passed","tests":62,"passed":62,"assertions":218}`.
- `php artisan test` (full suite) — `{"tool":"phpunit","result":"passed","tests":1062,"passed":1062,"assertions":2877}` — full Phase 1–20 regression (1000 tests) unaffected.

## 12. Deviations from Specification

- **Transaction wrapping is not universal** — see §4's Transactions discussion. The two event families the governing instructions explicitly named as needing same-transaction coupling (attachment upload/delete, Announcement publish/archive) are wrapped; single-statement CRUD writes elsewhere are not additionally wrapped in a new `DB::transaction()` purely for the audit call, since each business mutation is already a single atomic statement. This is a judgment call, not an oversight — flagged explicitly here per the instruction to record deviations rather than silently introduce them.
- **Four real, pre-implementation test-writing bugs were found and fixed while first running the new suite** (not application bugs): (1) two new tests used a fragile digit-plus-comma substring check for "no internal id leaked into CSV," which a coincidental IP address/ULID could satisfy by chance — fixed by parsing each CSV line into fields and checking exact non-membership; (2) `test_staff_separation_is_audited_as_a_distinct_event` omitted the `separation_date` field `UpdateStaffRequest` requires for a transition to `separated` (pre-existing Phase 7 validation, unrelated to this phase) — fixed by supplying it; (3) `test_message_send_is_not_audited` expected `200` from a direct-conversation-creation endpoint that correctly returns `201` for a new resource per Phase 16's own documented convention — fixed to `assertCreated()`.
- **One pre-existing Phase 20 test had the identical latent CSV-substring flakiness** (`StaffDirectoryReportTest::test_csv_export_succeeds_with_stable_headers_and_no_internal_ids`), surfaced for the first time during this session's full-suite run by an unrelated random employee-number collision. Fixed identically (exact-field-membership check via `str_getcsv()`), with no change to any application code — `App\Support\Reporting\CsvExport` itself was never wrong.
- Two real PHPStan findings in this phase's own new code (`AuditLogController::export()`, `AuditLogResource::toArray()`) — both used `?->` on `created_at`, which PHPStan's PHPDoc-derived typing treats as never-null; both corrected to plain `->`.
- One real bug caught during static review before any test ran: `AuditLogController::export()` originally passed `$log->source` (a `BackedEnum` instance) directly into a CSV row array; `fputcsv()` (unlike `json_encode()`) has no special handling for `BackedEnum` and would throw a `TypeError`. Fixed to `$log->source->value` before the first test run — this is not reflected in the test-failure history above because it was caught by review, not by a failing test.

## 13. Known Issues/Limitations

- **No mutation surface exists for User suspension/reactivation/role assignment** (see §4 and DEC-044's Known Limitation) — `auth.login_succeeded`/`_failed`/`auth.logout` are the only User-related events actually audited in V1; `user.suspended`/`user.reactivated`/`user.role_changed` are documented, reserved names with no current caller. Building the underlying endpoint is out of this phase's scope.
- No standalone `created_at` index — acceptable at this table's expected V1 volume; revisit only if a real query plan demonstrates a need.
- No retention/purge policy — documented as a future operational consideration, mirroring `staff_checkins`' identical precedent.
- Transaction-wrapping is not universal (see Deviations above) — a documented, deliberate trade-off, not an oversight.

## 14. Cross-Module Consistency Audit — Findings

A targeted, evidence-based review (direct reading of controller/route/migration code, not documentation alone) against the specific areas named in the governing instructions.

### Phase 21 corrections (implementation contradicted an approved decision) — none found.

No case was found where existing Phases 1–20 code contradicted an already-approved DEC/phase-definition decision. This phase made no corrective code change to any pre-Phase-21 controller, trait, or authorization rule.

### Documentation corrections (applied)

1. **`02_ARCHITECTURE.md` §8 / `03_DATABASE_MODEL.md` §1/§2 / `05_SECURITY_MODEL.md`'s Audit Logging section** all described `audit_logs` as something "expected to be introduced early" or "still unbuilt" — stale now that Phase 21 has built it. Updated to reflect the actual implementation, with a pointer to DEC-044.
2. **`docs/ROADMAP.md`'s Notes section** described the DEC-009 backstop clause in future tense ("if that doesn't happen... is the backstop"). Updated to past/resolved tense, since Phase 21 is now the concrete resolution, not a hypothetical.
3. **`docs/00_PROJECT_CHARTER.md`'s "Planned System" list** still named "Redis / queue infrastructure" and "Real-time capabilities" as live aspirational architecture, while DEC-039 (Phase 16) already formally closed both as "not needed in V1." Annotated in place (struck through, with a pointer to DEC-039 and the relevant `02_ARCHITECTURE.md` sections) rather than deleted, preserving the historical planning record while correcting the drift.
4. **`05_SECURITY_MODEL.md`'s Messaging Privacy section** referenced Audit Logging as "still an unbuilt, pre-existing gap" in the context of a hypothetical future Messaging moderation capability. Corrected to note the general Audit Log is now built, while clarifying that Messaging was deliberately *not* retrofitted into it (a separate, still-open product decision, not an oversight).

### Future considerations (recorded, not implemented)

1. **No User account-management mutation surface exists** (suspend/reactivate/role-change) — see §13. Building one is a future, separately-scoped decision.
2. **Pagination default inconsistency**: `CheckInController`/`OperationalStatusController` (Phase 9) default `per_page` to `20`, while every other paginated list resource in this API (Departments, Teams, Positions, Staff, Clients, Contacts, Projects, Tasks, Work Logs, Leave Requests/Types, Announcements, Schedule Entries, Service/Incident Reports, all seven Phase 20 reports, and this phase's own Audit Log) defaults to `50`. No documented decision explains the difference — it may be a deliberate original choice (status/check-in history rows are more frequent per Staff member than most other resources) or simply an unnoticed divergence. Recorded as a future consideration rather than silently normalized, since no approved decision is being contradicted either way and changing it would be an unauthorized behavior change to a working, unrelated module.
3. **A genuine Messaging administrative/investigation/audit capability** remains a deliberate, separate future decision (per `05_SECURITY_MODEL.md`'s Messaging Privacy section, both before and after this phase's documentation correction above) — this phase did not build one, consistent with the instruction against pulling deferred features into scope.

### No action / intentional differences (verified, unchanged)

- **404-vs-403 privacy conventions** — spot-checked across Messaging, Notifications, Scheduler, Service Reports, Incident Reports, Work Logs, and Tasks: each module's choice (404 for "not visible at all," 403 for "visible but not authorized to write," or a permission-gated 403 for a company-wide resource) matches its own documented design in `05_SECURITY_MODEL.md`. No inconsistency found beyond what's already documented as deliberate per-module variation.
- **Public-ID conventions / internal-ID exposure** — grepped every file under `app/Http/Resources` for a raw `'id' =>` key; none found. Every resource exposes only `public_id` (or, for pivot-like concepts with no `public_id` of their own — Project Membership — a reference by its related records' `public_id`s), consistent with DEC-017 throughout.
- **Referential delete guards / FK behavior** — every `destroy()` method touched by this phase's audit-logging integration retains its full, unmodified set of existence checks (409-before-delete) exactly as it was; none were removed, weakened, or reordered.
- **Company-timezone handling** — `App\Support\CompanyTimezone` is used consistently by Scheduling, Reporting, and (via `occurred_at`) Incident Reports; this phase introduced no new timestamp-normalization logic and did not need to touch any of it.
- **Domain action-history integrity** — `leave_request_actions`, `service_report_actions`, `incident_report_actions` are byte-for-byte unmodified by this phase; verified both by code review (no migration/model change) and by `AuditLoggingCoverageTest`'s explicit assertions that their own workflow-transition tests still populate the correct action-history rows.
- **Phase 20 aggregation never widening source visibility** — this phase added only an audit-log write to each of the seven report `export()` methods; none of the seven `*Visibility` classes, `filteredQuery()` methods, or the `DashboardController` were touched.
- **Service/Incident Report attachment authorization** — `authorizeManageDraft()`/`assertEditable()`/`authorizeManageContent()`/`assertMutable()` calls in both attachment controllers are unchanged; this phase only added an `AuditLogger` call after (or, for delete, wrapped around) the existing, unmodified authorization/business logic.

## 15. Manual/UAT Testing Instructions

See `docs/testing/UAT_LOG.md` (`UAT-21-01` through `UAT-21-05`) — this phase built API/backend functionality only, ready for direct API review (e.g. curl/Postman with a Sanctum bearer token for an Administrator account). No Admin Backoffice UI or Flutter mobile screens exist for the Audit Log, consistent with every module since Phase 6.

## 16. Documentation Updated

`docs/DECISIONS.md` (DEC-044), `docs/ROADMAP.md`, `docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/00_PROJECT_CHARTER.md`, `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/testing/TEST_STATUS.md`, `docs/testing/UAT_LOG.md`, `docs/phases/V1_PHASE_21_DEFINITION.md`, this handoff.

## 17. Recommended Next Step

Per `docs/ROADMAP.md`, **Phase 22 — Security Audit** (review against `05_SECURITY_MODEL.md`) is next. This is a recommendation only — per CLAUDE.md §8 Stop Discipline, Phase 22 must not begin without explicit product-owner authorization, and this session has not begun it.
