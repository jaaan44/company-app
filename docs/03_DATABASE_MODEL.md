# 03 — Conceptual Database Model (Provisional)

Status: **Conceptual only. No migrations exist.** This document identifies likely entities, relationships, ownership concepts, and areas needing later design decisions. It intentionally does not specify every column — that happens per-phase, when each area is actually implemented.

## 1. Entity Groups

### Identity & Organization
`users`, `staff`, `departments`, `teams`, `positions`, `roles`, `permissions`

- `users` — authentication identity (login, credentials, account state). Likely 1:1 with `staff` for employees, but kept separate so the concept of "a login" isn't inherently the same as "an HR staff record" (e.g. future non-staff users, or staff without app access).
- `staff` — the HR/organizational record: name, position, department/team, employment status, manager relationship.
- `departments`, `teams`, `positions` — organizational structure. Likely relationships: a `team` belongs to a `department`; `staff` belongs to a `department`/`team` and holds a `position`. Whether teams can span departments is an **open question** for the Organization Structure phase.
- `roles`, `permissions` — authorization. Many-to-many `roles`↔`permissions`, many-to-many `users`↔`roles` (a user may hold more than one role). See `05_SECURITY_MODEL.md`.

### Clients
`clients`, `contacts`

- `contacts` belong to a `client` (many contacts per client). A `contact` is not a `user` — clients are external parties, not system logins, in V1.

### Staff Operations
`staff_statuses`, `locations`, `staff_checkins`

- `staff_statuses` — historical log of status changes per staff member (DEC-010: append, don't overwrite). "Current status" is a derived read (latest record), not a separate mutable column, or is a denormalized cache column on `staff` that is *only* ever written alongside a new `staff_statuses` row.
- `locations` — a catalog of known locations (office sites, client sites) OR free-form location capture on check-in. Likely both: a `locations` table for known sites plus optional free-text/geo on `staff_checkins` for ad hoc check-ins.
- `staff_checkins` — explicit check-in events (DEC-005: not continuous tracking). Belongs to `staff`, optionally references a `location` or a `client`.

### Work Management
`projects`, `project_members`, `project_milestones`, `tasks`, `task_assignees`, `task_comments`, `work_logs`

- `projects` optionally belong to a `client`. `project_members` is the many-to-many join between `projects` and `staff`, likely carrying a role-on-project (e.g. Project Manager vs Member).
- `project_milestones` belong to a `project`.
- `tasks` optionally belong to a `project` (DEC-006 — must support project-less tasks). `task_assignees` is many-to-many `tasks`↔`staff` (supports multiple assignees, or could be simplified to one primary + watchers — open question for the Tasks phase). `task_comments` belong to a `task` and an author (`staff`/`user`).
- `work_logs` belong to `staff` and optionally to a `task` and/or `project` — time or activity entries.

### HR
`leave_types`, `leave_requests`, `leave_approvals`, `leave_balances`

- `leave_requests` belong to `staff` and reference a `leave_type`.
- `leave_approvals` — historical decision record per request (DEC-010: an approval workflow may have multiple steps/actors over time; don't just add `status` + `approved_by` columns to `leave_requests` if multi-step approval is needed — open question, confirm at the HR phase whether V1 needs single-step or multi-step approval).
- `leave_balances` — per staff, per leave type, per period (e.g. per year) — accrual/consumption tracking. Exact accrual rules are an **open question** (see `01_PRODUCT_REQUIREMENTS.md` §6).

### Scheduling
`calendar_events`

- A likely unifying table for the schedule surface (meetings, client visits, service appointments, company events, training, internal activities), possibly polymorphic/linked to source records (e.g. an event that represents a `project_milestone` or a `leave_request` rather than duplicating data). Whether this is one unified table with a `type` discriminator, or several distinct tables surfaced through a combined read, is an **open question** for the Scheduler phase.

### Communication
`conversations`, `conversation_members`, `messages`, `announcements`, `announcement_recipients`, `announcement_acknowledgements`, `notifications`

- `conversations` — direct, group, or project-linked (optionally references a `project`). `conversation_members` is the many-to-many join to `staff`/`users`.
- `messages` belong to a `conversation` and an author.
- `announcements` are broadcast content; `announcement_recipients` scopes who an announcement targets (e.g. all staff, a department, a role); `announcement_acknowledgements` records who has read/acknowledged it (supports DEC-009-style traceability for important company-wide notices).
- `notifications` — generic per-user notification records, likely polymorphic (references a source event of varying type) — Laravel's built-in notifications table shape is a reasonable starting point.

### Operations
`service_reports`, `service_report_attachments`, `incidents`, `incident_actions`

- `service_reports` likely reference a `client` and the `staff` who performed the work, optionally a `project`/`task`.
- `service_report_attachments` belong to a `service_report` (see shared `attachments` note below).
- `incidents` reference reporting `staff`, optionally a `client`/`project`/`location`.
- `incident_actions` — historical action/progress log per incident (DEC-010 — incident progress is a history, not a single mutable status field).

### Cross-Cutting
`notifications`, `attachments`, `audit_logs`, `settings`

- `attachments` — a candidate for a **shared, polymorphic attachments table** (rather than a separate attachments table per module) so file-upload handling, validation, and storage conventions are written once. `service_report_attachments` above may turn out to be redundant with a generic `attachments` table — this is an **open question** to resolve when Service Reports and shared infrastructure are actually built; don't build both.
- `audit_logs` — cross-cutting record of significant actions: actor, action, subject type/id, before/after or metadata, timestamp. Introduced early (Core Architecture / Authentication phase) as shared infrastructure so later modules use it rather than invent their own.
- `settings` — application-level configuration that must be admin-editable at runtime (as opposed to `.env`/config files, which are deploy-time).

## 2. Ownership & Ambient Concepts

- **`staff` is the organizational anchor.** Most operational records (statuses, check-ins, work logs, leave, task assignments) hang off `staff`, not `users` directly, keeping "who is this person in the org" separate from "how do they log in."
- **`client` is a secondary anchor** for external-facing work (projects, service reports, some incidents).
- **History over mutation** (DEC-010) applies specifically to: staff status, leave approval decisions, incident progress. Other entities may use simple status columns where no meaningful history is needed (e.g. a task's `completed_at` timestamp) — this is a per-entity judgment call at implementation time, not a blanket rule.
- **Audit logging** (DEC-009) is expected to wrap administrative/workflow-significant mutations broadly, likely via a model observer/trait pattern established once and reused, rather than hand-written per module.

## 3. Deliberately Undecided

- Exact column lists, types, and constraints — decided per-phase against real requirements, not guessed here.
- Soft-deletes vs hard-deletes per entity.
- UUID vs auto-increment primary keys (recommend deciding once, project-wide, at the Core Architecture phase, for consistency).
- Whether `attachments` is one shared polymorphic table or per-module tables.
- Whether `calendar_events` is unified or composed from source tables.
- Multi-step vs single-step leave approval.

This document should be revisited and updated (not silently replaced) each time a phase implements one of these areas for real, so it stays a useful map rather than going stale.
