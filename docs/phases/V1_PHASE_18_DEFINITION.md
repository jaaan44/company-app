# Phase 18 — Service Reports — Specification

**Status:** COMPLETE (pending product-owner review)
**Depends on:** Phase 8 (Clients), Phase 10 (Projects), Phase 11 (Tasks), Phase 7 (Staff)

## Objective

Build Service Reports — a record of service/work performed for a Client
— per a product-owner-reviewed planning audit that resolved the
roadmap's terse "Service report creation, review, attachments" line into
concrete architecture (see `docs/DECISIONS.md` DEC-041). This phase also
introduces the project's previously deferred shared attachment/file
infrastructure (`03_DATABASE_MODEL.md` §1's Phase-0-era open question),
authorized now because Service Reports genuinely requires it and Phase
19 (Incident Reports) is the concrete next consumer.

## Core Concept

A Service Report documents service/work performed for a Client. Client
is the **required** business anchor — a Service Report is never a
generic internal activity record. Project and Task are both **optional**
(mirroring DEC-006's "optional project" precedent). Relational coherence
is enforced: a supplied Project must genuinely belong to the selected
Client; a supplied Task must not contradict the selected Client/Project.
`creator_staff_id` is immutable at every status. Client/Project/Task
remain correctable through the normal update endpoint while the report
is a `draft` — every such update re-runs the same relational-coherence
validation against the *effective* combination, so a Client change can
never silently leave an incompatible Project/Task in place. Once the
report leaves `draft` (`submitted`/`reviewed`/`rejected`), Client/
Project/Task become immutable together with the rest of the content — a
`rejected` report must first return to `draft` before they may change
again. This corrects an initial implementation that made the whole
anchor immutable immediately after creation (mirroring Work Log's
`staff_id`/`task_id`/`project_id` precedent, DEC-035) — stricter than
approved; see DEC-041's Correction.

## In Scope

- `service_reports` table — `public_id`, required `client_id`, nullable
  `project_id`/`task_id`, required `creator_staff_id` (the primary
  performer), `created_by_user_id` (accountability), `service_date`
  (DATE), `work_performed` (required), `findings`/`recommendations`/
  `follow_up_actions` (nullable), `site_representative_name` (nullable,
  plain text — no signature), `status`.
- `service_report_participants` — a simple pivot (no role/status
  hierarchy, no RSVP), mirroring Schedule Entry Participants exactly.
  The creator/primary performer is never duplicated into it.
- `service_report_actions` — append-only workflow-transition history
  (DEC-010), mirroring `leave_request_actions`' exact shape.
- A closed four-state workflow (`draft`/`submitted`/`reviewed`/
  `rejected`) with explicit action endpoints
  (`submit`/`review`/`reject`/`return-to-draft`) — never a generic status
  PATCH.
- Row-level authorization with **no new permission** — visibility is
  creator/participant/creator's-current-Manager/linked-Project's-
  Project-Lead/Administrator; review authority is the creator's current
  Manager, the Project Lead, or Administrator; draft-management
  authority (edit/delete/attachment mutation/submit/return-to-draft) is
  creator or Administrator only.
- The shared `attachments` table (DEC-041) — a typed, non-polymorphic
  owner reference (`owner_type` + a real per-owner-type foreign key,
  `service_report_id` today), private local disk storage in V1 via
  Laravel's filesystem abstraction, authenticated/authorized download
  only.
- Full CRUD for Service Reports (`/api/v1/service-reports`, flat
  top-level) and attachment upload/download/removal endpoints.
- Relational-integrity protections extending
  `ClientController`/`ProjectController`/`TaskController`/
  `StaffController::destroy`.

## Explicitly Out of Scope

- Flutter mobile UI and Admin Backoffice UI (consistent with every prior
  module's precedent through Phase 17).
- Offline data entry/synchronization.
- External/customer portal access, customer login (00_PROJECT_CHARTER.md
  rules out a public-facing client portal entirely).
- Digital signatures — `site_representative_name` is plain text only.
- PDF generation, printable report export, sequential report numbering.
- Scheduler integration of any kind: no `ScheduleSourceType` addition, no
  fifth `/schedule` source, no automatic Schedule Entry creation from a
  Service Report or vice versa, no Schedule Entry FK. The Scheduler's
  existing `service_appointment` activity type remains independent until
  a future phase deliberately designs a real linking workflow.
- Notification integration — no new `NotificationType`/
  `NotificationSourceType` case, no producer wired.
- Messaging integration — no conversation concept extended.
- Parts/materials inventory or structured line items — narrative only.
- Labor/time tracking, start/end timestamps, GPS/location capture.
- Antivirus/malware scanning of attachments.
- Multi-level approval — single-stage review only, mirroring Leave's
  precedent (DEC-036).
- Recurrence, background workers, queues, Redis, WebSockets.
- Generic project-wide Audit Logging (DEC-009 remains an unbuilt,
  project-wide gap; `service_report_actions` is a scoped, per-module
  history table, not a replacement for it).

## Workflow

```
draft --submit--> submitted --review--> reviewed (final)
                       |
                    reject
                       v
                  rejected --return-to-draft--> draft
```

`reviewed` is the single final successful state for V1 — there is no
separate "approved" vs "completed" distinction, since a Service Report
documents work already performed (unlike a Task, which continues after
approval). A resubmission after `return-to-draft` is recorded as
`resubmitted` in history, distinct from the report's original
`submitted` action.

## Editing and Immutability

- `draft` — freely editable (content, Client/Project/Task, participants,
  attachments) by the creator or Administrator; `creator_staff_id`
  remains immutable even in `draft`. Every Client/Project/Task change is
  re-validated against the same relational-coherence rule used at
  creation, evaluated against the effective combination (a changed
  field's new value, or the existing report's current value for any
  field left untouched).
- `submitted`/`reviewed`/`rejected` — content-, relationship-, and
  attachment-immutable. A `rejected` report must first transition back
  to `draft` (`return-to-draft`) before any content, Client/Project/
  Task, or attachment change.

## Visibility (no new permission)

A Service Report is visible to:
- its creator,
- its listed Staff participants,
- the creator's *current* direct Manager (`Staff.manager_id`, evaluated
  live — a plain relationship check, never a `*.view` permission grant),
- the linked Project's Project Lead, when the report is Project-linked,
- Administrator (unconditional, via a direct role check — the simplest
  convention consistent with every prior module).

This is deliberately **narrower** than Schedule Entries' visibility
model (DEC-040): a Manager holding `projects.view` does **not**
automatically see every Project-linked Service Report the way they do
Schedule Entries — only an actual Project Lead relationship grants that,
per the explicit product-owner instruction rejecting a broad
`service-reports.view` permission.

Review authority (submit for decision) belongs to the creator's current
Manager, the linked Project's Project Lead, or Administrator — never the
creator themselves. Draft-management authority (edit/delete/attachment
mutation/submit/return-to-draft) belongs only to the creator or
Administrator — participants gain visibility but never
workflow-management authority.

## Creation Authority

Any active User with a linked, active Staff record may create their own
Service Report. A Project-linked report requires current Project
membership (any role); an independent-Task-linked report requires being
that Task's current assignee (both mirror Work Log's identical
self-service eligibility precedent, DEC-035). Administrator may name
another Staff member via `creator_staff_id`, skipping these eligibility
checks entirely (trusted to backfill/correct a historical record,
mirroring Work Log's Administrator-on-behalf precedent) — a
non-Administrator supplying `creator_staff_id` for anyone but themselves
is rejected.

## Attachment Architecture (DEC-041)

The shared `attachments` table uses a **typed, non-polymorphic**
ownership design — never a Laravel-style `attachable_type` storing a raw
PHP class name with a bare `attachable_id` carrying no real foreign key.
`owner_type` (`App\Enums\AttachmentOwnerType`, currently one case:
`service_report`) is an explicit, closed discriminator column, paired
with a genuine, real foreign key **per owner type** —
`service_report_id` today, `NOT NULL` and `cascadeOnDelete()` since
exactly one owner type exists. This keeps full DB-level referential
integrity while remaining trivially extensible: Phase 19 (Incident
Reports) adds its own nullable `incident_report_id` column and its own
`AttachmentOwnerType` case via an additive migration, without
redesigning this table or Service Reports. No `incident_report` enum
value is pre-added here.

Storage: Laravel's filesystem abstraction, via a single named
`attachments` disk (`config('attachments.disk')`,
`App\Support\Attachments\AttachmentDisk` — the sole point of access,
mirroring `App\Support\CompanyTimezone`'s Phase 17 precedent). V1/local/
test default is the framework's private `local` disk (never web-served);
production remains swappable to an S3-compatible disk by changing this
one config value, resolving `02_ARCHITECTURE.md` §7's long-open object
storage question for this phase's actual need without committing to a
provider.

Metadata stored per attachment: `public_id`, `owner_type`,
`service_report_id`, `original_filename` (display only, never trusted as
the physical path), `storage_disk`, `storage_path` (a generated
ULID-based name, never derived from the original filename),
`mime_type`, `size_bytes`, `uploaded_by_user_id`.

**Validation (conservative V1 allowlist):** JPEG, PNG, and PDF only
(`config('attachments.allowed_extensions')`/`allowed_mime_types`),
max 10 MB per file (`config('attachments.max_size_kb')`), enforced by
Laravel's `mimes` rule (content-sniffed, not merely the client-supplied
extension) plus a re-check in the controller. Antivirus/malware scanning
is explicitly not a V1 commitment.

**Authorization/lifecycle:** attachment access always inherits the
parent Service Report's own visibility — whoever may view the report may
download its attachments. Upload/removal is allowed only while the
report is `draft` and only to the creator or Administrator. Deleting a
draft Service Report deletes its attachments' physical files (via
`App\Services\Attachments\AttachmentStorage`) **before** the database
row — file storage is not part of the database transaction, so a crash
between the two steps could in principle leave a dangling DB reference
to an already-removed file, but never an orphaned file with no remaining
database reference. This is the documented, accepted consistency
boundary.

## Relational Coherence

Enforced in `App\Http\Requests\ServiceReports\Concerns\
ResolvesServiceReportReferences`:
- If `project_id` is supplied, it must belong to the selected `client_id`.
- If `task_id` is supplied and its own Task has a Project, that Project
  must not contradict an explicitly supplied `project_id`; when
  `project_id` is omitted, it is server-derived from the Task (mirroring
  Work Log's single-source-of-truth rule, DEC-035) and must still belong
  to the selected Client.
- An independent (project-less) Task combined with an explicit
  `project_id` is rejected — it would misrepresent the Task as
  Project-linked when it is not.

This follows the actual existing schema (Client → Project → Task; Task
has no direct `client_id` of its own) rather than assuming a
relationship that isn't present.

The same rule applies on update, while the report is still a `draft`
(`validateClientProjectTaskCoherenceForUpdate()`), evaluated against the
*effective* combination — a changed field's new value, or the existing
report's current value for any field the update request leaves
untouched — so changing the Client alone can never silently leave an
incompatible Project/Task in place, and the single-source-of-truth
Project-from-Task derivation is re-applied whenever the Task changes
without an accompanying `project_id`.

## Relevant Documentation

- `docs/DECISIONS.md` DEC-041 (this phase's approved architecture).
- `02_ARCHITECTURE.md` §7 (file storage), new §28 this phase adds.
- `03_DATABASE_MODEL.md` §1 Operations/Cross-Cutting (the `attachments`
  open question this phase resolves).
- `04_API_CONVENTIONS.md` (flat filterable top-level resources, explicit
  action endpoints for workflow, standard pagination).
- `05_SECURITY_MODEL.md` (row-level authorization, 404-vs-403 privacy
  convention, File Uploads section — now implemented, not aspirational).

## Acceptance Criteria

- A Service Report can be created with only a required Client; Project
  and Task are both optional and, when supplied, pass relational
  coherence validation.
- The four-state workflow enforces every documented transition and
  rejects every undocumented one (e.g. reviewing a draft, deleting a
  submitted report).
- Visibility/review authority match the documented rules exactly — no
  broad Manager grant, no participant workflow authority.
- Attachments enforce the documented allowlist/size limit, inherit
  report visibility for downloads, and are mutable only on a draft.
- Deleting a draft's attachments (individually, or via deleting the
  draft itself) removes the physical file — no orphaned files.
- No internal numeric ID is ever exposed by any Service Report or
  Attachment endpoint.
- Full Phase 1–17 regression suite plus this phase's new tests pass;
  `composer validate --strict`, `vendor/bin/pint --test`, and
  `vendor/bin/phpstan analyse` are clean.

## Testing Expectations

See `docs/handoffs/V1_PHASE_18_HANDOFF.md` for the full, itemized test
list and quality-gate results.

## Notes

- Object storage provider for production remains open in the abstract
  (`02_ARCHITECTURE.md` §7) — this phase resolves only what V1 needs
  (a private local disk behind a swappable single-config-value
  abstraction), not the production provider decision itself.
- `service_report_actions` is a scoped, per-module history table
  (DEC-010), not a stand-in for the still-unbuilt, project-wide Audit
  Logging concern (DEC-009).
