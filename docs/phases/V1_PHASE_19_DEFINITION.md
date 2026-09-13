# Phase 19 — Incident Reports — Specification

**Status:** COMPLETE (pending product-owner review)
**Depends on:** Phase 7 (Staff), Phase 8 (Clients), Phase 10 (Projects), Phase 11 (Tasks), Phase 18 (shared Attachment infrastructure)

## Objective

Build Incident Reports — a record of an operational incident (workplace
safety, client-site, operational, property/equipment, IT/security, or
other) — per a product-owner-reviewed planning audit that resolved the
roadmap's terse "Incident creation, assignment, action history,
resolution" line into concrete architecture (see `docs/DECISIONS.md`
DEC-042). This phase is the second authorized consumer of the shared
attachment infrastructure Phase 18 built in anticipation of it
(DEC-041).

## Naming Correction

`03_DATABASE_MODEL.md`'s Phase-0-era sketch used the working names
`incidents`/`incident_actions`. This phase uses the canonical
`incident_reports`/`incident_report_actions`/`incident_report_participants`
(`App\Models\IncidentReport`/`IncidentReportAction`) — matching the
product terminology "Incident Reports," Service Reports' own naming
convention, and the `incident_report_id` attachment FK name Phase 18
already anticipated. The stale references in `03_DATABASE_MODEL.md` are
corrected, not left inconsistent.

## Core Concept

An Incident Report documents an operational incident. Unlike Service
Reports, **all three business anchors — Client, Project, and Task — are
optional**: an Incident Report may be entirely internal, with none of
the three present. When supplied, the same relational-coherence
principle Service Reports established applies (a supplied Project must
not contradict a supplied Client; a supplied Task must not contradict
Client/Project). `reporter_staff_id` is immutable at every status.
`assigned_to_staff_id` is a single, nullable investigator, never accepted
at creation, mutated only through dedicated action endpoints so every
change is captured in `incident_report_actions`.

## In Scope

- `incident_reports` table — `public_id`, nullable `client_id`/
  `project_id`/`task_id` (all optional), required `reporter_staff_id`,
  nullable `assigned_to_staff_id`, `created_by_user_id` (accountability),
  `occurred_at` (UTC `datetime`), `location` (nullable free text),
  `incident_type`, `severity` (default `medium`), narrative fields
  (`description` required; `immediate_action_taken`/`root_cause`/
  `corrective_action`/`preventive_action`/`follow_up_actions`/
  `resolution`/`people_involved`/`witness_notes` nullable), `status`.
- `incident_report_participants` — a simple pivot (no role/status
  hierarchy, no RSVP, no witness/injury-role enum), mirroring Service
  Report Participants exactly. The reporter/assignee are never
  duplicated into it.
- `incident_report_actions` — append-only workflow/assignment history
  (DEC-010), mirroring `service_report_actions`' exact shape, but with
  the initial `reported` entry recorded by the model's own `created`
  event (not the controller) so every creation path is complete.
- An investigation-oriented workflow (`reported`/`under_investigation`/
  `resolved`/`closed`) with explicit action endpoints
  (`assign`/`reassign`/`start-investigation`/`resolve`/`close`/`reopen`)
  — never a generic status PATCH.
- Row-level authorization with **no new permission** — visibility is
  reporter/assigned-investigator/participant/reporter's-current-Manager/
  Administrator (deliberately **no** Project-Lead carve-out, unlike
  Service Reports); assignment authority is the reporter's current
  Manager or Administrator; content-management authority is
  status-dependent (see Editing Authority below).
- The shared `attachments` table extended (DEC-042) — a new nullable
  `incident_report_id` FK/`AttachmentOwnerType::IncidentReport` case via
  a purely additive migration, with `service_report_id` widened to
  nullable in the same migration.
- Full CRUD for Incident Reports (`/api/v1/incident-reports`, flat
  top-level) and attachment upload/download/removal endpoints.
- Relational-integrity protections extending
  `ClientController`/`ProjectController`/`TaskController`/
  `StaffController::destroy`.

## Explicitly Out of Scope

- Flutter mobile UI and Admin Backoffice UI (consistent with every prior
  module's precedent through Phase 18).
- Offline data entry/synchronization.
- External/customer incident submission, a customer portal, customer
  login (`00_PROJECT_CHARTER.md` rules out a public-facing client portal
  entirely).
- Structured named-witness records — `witness_notes`/`people_involved`
  are narrative-only.
- Multi-investigator assignment — a single `assigned_to_staff_id` only.
- A per-record confidentiality flag, visibility enum, or any HR/
  harassment/whistleblower case-management workflow.
- A medical/injury-records subsystem, workers'-compensation workflow, or
  regulatory (OSHA/DOLE-style) reporting.
- Digital signatures, PDF generation, printable report export,
  sequential incident numbering.
- Notification integration — no new `NotificationType`/
  `NotificationSourceType` case, no producer wired.
- Messaging integration, Scheduler integration (no `ScheduleSourceType`
  addition, no automatic Schedule Entry linkage).
- Automatic Service Report creation, automatic Work Log creation,
  automatic Task mutation beyond the optional `task_id` FK itself.
- SLA/escalation timers, reminders, cron/queue/background-worker/Redis/
  WebSocket infrastructure.
- Automatic severity/risk scoring.
- Generic project-wide Audit Logging (DEC-009 remains an unbuilt,
  project-wide gap; `incident_report_actions` is a scoped, per-module
  history table, not a replacement for it).

## Workflow

```
reported --start-investigation--> under_investigation --resolve--> resolved --close--> closed
                                          ^                              |                |
                                          |                           reopen            reopen
                                          +------------------------------+----------------+
```

`reported` is the initial state — an incident exists and is visible
immediately upon reporting; it may be incomplete, awaiting assignment,
or awaiting investigation. Entering `under_investigation` requires an
assignee to already exist or be supplied in the same
`start-investigation` call. `resolved` requires a non-empty `resolution`
and at least one of `corrective_action`/`immediate_action_taken` (never
`root_cause` universally). `closed` is the final state. `reopen` is
available from `resolved` or `closed`, returning the report to
`under_investigation` — "reopened" is recorded only as an action-history
event, never a fifth persisted status.

## Assignment

`assigned_to_staff_id` is never accepted at creation (an incident may be
reported unassigned). It is mutated only via:
- `POST .../assign` — requires currently unassigned; authority is the
  reporter's current Manager or Administrator.
- `POST .../reassign` — requires currently assigned; same authority.
- `POST .../start-investigation` — may bundle an initial/changed
  assignment; supplying `assigned_to_staff_id` in that call requires
  assignment authority specifically, distinct from mere
  investigation-management authority (which the assigned investigator
  themselves also holds once assigned).

Every assignment/reassignment is recorded as its own
`incident_report_actions` entry (`assigned`/`reassigned`), never silently
folded into a generic content update.

## Editing Authority

- **`reported`** — the reporter, the reporter's current Manager, the
  assigned investigator (if any), or Administrator may edit content.
- **`under_investigation`** — narrows to the assigned investigator, the
  reporter's current Manager, or Administrator; the reporter alone,
  without separately qualifying, is view-only.
- **`resolved`/`closed`** — content and attachments are immutable for
  everyone, Administrator included. Only `reopen` (same authority as
  investigation-management) restores editing/attachment capability.
- `reporter_staff_id` is immutable in every state; `assigned_to_staff_id`
  and `status` are never accepted by the generic update endpoint.
- Client/Project/Task remain correctable while the report is
  content-mutable (`reported`/`under_investigation`), subject to the
  same relational-coherence validation as creation, evaluated against
  the *effective* combination.

## Resolution Requirements

Before transitioning to `resolved`: `resolution` must be non-empty
(either already on the record or supplied in the same request), and at
least one of `corrective_action`/`immediate_action_taken` must be
non-empty (likewise). `root_cause` is never required.

## Visibility (no new permission)

An Incident Report is visible to:
- its reporter,
- its assigned investigator,
- its listed Staff participants,
- the reporter's *current* direct Manager (`Staff.manager_id`, evaluated
  live — a plain relationship check, never a `*.view` permission grant),
- Administrator (unconditional, via a direct role check).

This is deliberately **narrower** than Service Reports' visibility model
(DEC-041), which was itself already narrower than Schedule Entries'
(DEC-040): **a linked Project's Project Lead gains no visibility or
authority here at all**, even though the identical `ProjectMembership`
check is readily available and reused by Service Reports — an explicit
product-owner instruction reflecting that incident subject matter is
treated as more sensitive than routine client-work documentation.

No per-record confidentiality flag, HR/harassment/whistleblower
workflow, or medical/injury-record handling was built. V1's narrow
row-level visibility is judged sufficient for the roadmap's actual
scope; a dedicated confidential-case design remains a future,
explicitly-requested decision.

## Creation Authority

Any active User with a linked, active Staff record may report their own
Incident Report. Administrator may name another Staff member via
`reporter_staff_id`, skipping the active-status check entirely (trusted
to backfill/correct a historical record, mirroring Service Reports'
Administrator-on-behalf precedent, DEC-041) — a non-Administrator
supplying `reporter_staff_id` for anyone but themselves is rejected.
Unlike Service Reports, no Project-membership or Task-assignee
eligibility gate was added at creation — an incident may legitimately be
reported by someone with no other standing relationship to the optional
Client/Project/Task (e.g. a bystander to a workplace incident), so
requiring one would misrepresent who can legitimately report an
incident.

## Deletion

Hard deletion is permitted only while an Incident Report is `reported`
**and** unassigned (`assigned_to_staff_id IS NULL`) — since entering
investigation always moves status away from `reported`, this single
condition captures "not yet assigned, not yet progressed into
investigation." Deletion authority is the reporter or Administrator
only. Once assigned or investigated, an Incident Report is permanent
operational history — resolution/closure are the only paths forward;
Administrator has no deletion bypass.

## Attachment Architecture (DEC-042, extending DEC-041)

`AttachmentOwnerType::IncidentReport` (new enum case) pairs with a new
nullable `incident_report_id` real FK (`cascadeOnDelete()`) added to the
existing `attachments` table via one additive migration.
`attachments.service_report_id` — originally `NOT NULL` "since exactly
one owner type exists today" — is widened to nullable in the same
migration, since a second owner type now means every row populates
exactly one of the two FKs. No existing Service Report attachment row or
behavior changes.

`App\Support\Attachments\AttachmentDisk`/`App\Services\Attachments\
AttachmentStorage` are reused as-is; the latter's `store()` gained an
optional directory-prefix parameter (`'service-reports'` by default, so
every existing call site/stored path is unaffected — Incident Reports
passes `'incident-reports'`). The same JPEG/PNG/PDF allowlist, 10 MB
cap, private-disk-only storage, and generated-filename discipline apply
unchanged.

**Authorization/lifecycle:** attachment access always inherits the
parent Incident Report's own (narrower) visibility. Upload/removal
requires both a content-mutable status (`reported`/`under_investigation`)
and the same content-management authority that gates the report's own
content — reopening restores this for authorized investigation actors.
Deleting an Incident Report deletes its attachments' physical files
(via `AttachmentStorage`) **before** the database row, mirroring Service
Reports' identical consistency-boundary discipline.

## Relational Coherence

Enforced in `App\Http\Requests\IncidentReports\Concerns\
ResolvesIncidentReportReferences` — a fresh trait, not a literal reuse of
`ResolvesServiceReportReferences`, since Client's optionality here would
have forced that trait's "Client is always present" logic into awkward
branching rather than a clean shared abstraction:
- If both `client_id` and `project_id` are supplied, the Project must
  belong to that Client.
- If `task_id` is supplied and its own Task has a Project, that Project
  must not contradict an explicitly supplied `project_id`; when
  `project_id` is omitted, it is server-derived from the Task and (if a
  Client was supplied) must still belong to it.
- An independent (project-less) Task combined with an explicit
  `project_id` is rejected.
- Unlike Service Reports, a Client is never required for any of these
  checks to pass — an Incident Report with only a Project, only a Task,
  or none of the three is a fully valid, coherent combination.

The same rule applies on update, while the report is content-mutable,
evaluated against the *effective* combination exactly as Service
Reports' update-time counterpart does.

## Relevant Documentation

- `docs/DECISIONS.md` DEC-042 (this phase's approved architecture).
- `02_ARCHITECTURE.md` §29 (new section this phase adds).
- `03_DATABASE_MODEL.md` §1 Operations/Cross-Cutting (corrects the
  Phase-0-era `incidents`/`incident_actions` sketch).
- `04_API_CONVENTIONS.md` (flat filterable top-level resources, explicit
  action endpoints for workflow/assignment, standard pagination).
- `05_SECURITY_MODEL.md` (row-level authorization, 404-vs-403 privacy
  convention, the new Incident Report Privacy section).

## Acceptance Criteria

- An Incident Report can be created fully internal (no Client/Project/
  Task) or with any coherent combination of the three.
- The investigation-oriented workflow enforces every documented
  transition and rejects every undocumented one (e.g. resolving a
  `reported` incident, closing one still `under_investigation`).
- Assignment/reassignment always require assignment authority
  specifically and always produce an action-history entry; the generic
  update endpoint never accepts `assigned_to_staff_id`.
- Visibility/editing authority match the documented rules exactly — no
  Project-Lead carve-out, status-dependent content-management authority.
- Resolution enforces its documented field requirements.
- Attachments enforce the documented allowlist/size limit, inherit
  report visibility for downloads, and are mutable only while
  `reported`/`under_investigation`.
- Deletion is blocked once assigned or investigated, for everyone.
- Existing Service Report attachments are provably unaffected by the
  shared-table extension.
- No internal numeric ID is ever exposed by any Incident Report or
  Attachment endpoint.
- Full Phase 1–18 regression suite plus this phase's new tests pass;
  `composer validate --strict`, `vendor/bin/pint --test`, and
  `vendor/bin/phpstan analyse` are clean.

## Testing Expectations

See `docs/handoffs/V1_PHASE_19_HANDOFF.md` for the full, itemized test
list and quality-gate results.

## Notes

- `incident_report_actions` is a scoped, per-module history table
  (DEC-010), not a stand-in for the still-unbuilt, project-wide Audit
  Logging concern (DEC-009).
- A dedicated confidential-case design (per-record confidentiality,
  HR/harassment/whistleblower workflow) was deliberately not built —
  flagged as a possible future decision, never a speculative addition
  here.
