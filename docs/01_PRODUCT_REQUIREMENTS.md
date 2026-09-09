# 01 — Product Requirements (Provisional)

Status: **Provisional / directional.** This document describes intended product scope and behavior at the level needed to plan phases. It is not a finalized spec for any single phase — each phase gets its own detailed specification in `docs/phases/` before implementation.

## 1. Primary Domains & Modules

### Organization
Staff, Departments, Teams, Positions, Roles, Permissions.

### Clients
Clients, Client Contacts.

### Staff Operations
Current Status (e.g. available, on leave, in the field, off duty), Location Check-ins (explicit, not continuous tracking — see DEC-005).

### Work Management
Projects, Project Members, Project Milestones, Tasks (may exist independently of projects — see DEC-006), Task Assignments, Work Logs.

### HR
Leave Types, Leave Requests, Leave Approvals, Leave History, Leave Balances.

### Scheduling
Meetings, Client Visits, Service Appointments, Project Milestones, Task Deadlines, Company Events, Leave, Training, Internal Activities — unified into a schedule/calendar view.

### Communication
Direct Messages, Group Conversations, Project Conversations, Announcements, Notifications. Kept intentionally lightweight (DEC-007).

### Operations
Service Reports, Incident Reports.

### Administration
Dashboard, Reports, Master Data, Application Settings, Audit Logs.

## 2. User Roles (Anticipated, Not Final)

Super Administrator, Administrator, HR Administrator, Manager, Project Manager, Supervisor, Staff, Field Staff/Technician, Management/Executive.

Roles are a convenience grouping, not the authorization mechanism itself — see DEC-004 and `05_SECURITY_MODEL.md`. Final role list is expected to be confirmed during the Roles & Permissions phase.

## 3. Example Permission Catalog (Illustrative, Not Final)

```
staff.view            staff.create           staff.update           staff.suspend
client.view           client.create          client.update
project.view          project.create         project.update         project.manage_members
task.create           task.assign            task.update            task.complete
work_log.create       work_log.view_team
leave.request         leave.approve          leave.reject
service_report.create service_report.review
incident.create       incident.assign        incident.resolve
announcement.create   announcement.publish
reports.view          settings.manage
```

The final permission catalog will be defined during the Roles & Permissions phase and recorded there — this list only illustrates the granularity intended (module.action).

## 4. Cross-Module Behavioral Expectations

- **Connected domain model (DEC-008):** modules are not isolated mini-apps. E.g., a Task can relate to a Project, which relates to a Client; a Work Log relates to a Task and/or Project and to Staff; an Incident can relate to a Client, a Project, and involved Staff.
- **Historical records (DEC-010):** state transitions that matter operationally (staff status changes, leave approval decisions, incident progress) are recorded as history, not overwritten in place.
- **Auditability (DEC-009):** administrative and workflow-significant actions are expected to eventually be traceable to who did what and when.

## 5. Non-Functional Expectations

- Mobile-first experience for staff (Flutter app is the primary staff-facing surface).
- Admin Backoffice is desktop/responsive-web, used by administrative and management roles.
- The system must remain usable and understandable as more modules are added — no module should require understanding unrelated modules to use.

## 6. Explicitly Deferred / Open Questions

These are intentionally unresolved at Phase 0 and should be answered when the relevant phase is planned, not guessed at now:

- Exact leave accrual/balance calculation rules (accrual policy, carryover, etc.)
- Whether Departments/Teams are strictly hierarchical or allow cross-membership
- Notification delivery channels beyond in-app (email/push) and their priority
- Real-time transport choice for messaging/notifications (see `02_ARCHITECTURE.md` open questions)
- File/attachment storage strategy at scale (local vs. S3-compatible) — direction proposed in `02_ARCHITECTURE.md`, decision deferred to the relevant phase
- Multi-language/localization requirements (assumed out of scope for V1 unless stated otherwise)

## 7. Traceability

Each future phase specification (`docs/phases/`) should reference which of the modules/requirements above it implements, and this document should be updated if a phase reveals the requirement was wrong or incomplete — with a note, not a silent rewrite.
