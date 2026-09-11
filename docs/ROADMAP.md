# ROADMAP

This is the authoritative phase sequence for Company App. Phase numbering may be adjusted (with a note in `DECISIONS.md` or `CHANGELOG.md`) if implementation reveals a better boundary — but phases are not skipped or implemented out of order without explicit authorization.

Each phase, when authorized, gets a specification document in `docs/phases/` before implementation begins, and a handoff document in `docs/handoffs/` when complete.

**For current status, see `docs/CURRENT_STATE.md` — this file is the plan, not the tracker.**

## Phase 0 — Project Definition & Development Governance
Repository documentation foundation: charter, requirements, architecture, conceptual data model, API conventions, security model, UI/UX principles, roadmap, decision ledger, testing strategy, `CLAUDE.md`. No application code. *(This phase.)*

---

### Foundation
*Depends on: Phase 0*

**Phase 1 — Project Bootstrap**
Initialize the Laravel backend project and the Flutter mobile project (repository scaffolding, base dependencies, base directory structure per `02_ARCHITECTURE.md`). No business features.

**Phase 2 — Development Environment & CI**
Local dev environment (e.g. Docker/Sail), CI pipeline wiring (lint, static analysis, test run) for both projects. Establishes the real commands referenced in `CLAUDE.md` §5.

**Phase 3 — Core Architecture**
Base API response/error conventions actually implemented, base Flutter app shell/navigation shell, primary key strategy decision, Admin Backoffice implementation approach decision (see `02_ARCHITECTURE.md` open questions).

**Phase 4 — Authentication**
Login, logout, token issuance/refresh for both mobile and admin, password handling, account states (active/suspended).
*Depends on: Phase 3*

**Phase 4A — Docker Development Environment**
Docker Compose (`nginx` + `app`/PHP-FPM + `mysql`) as the standard local backend development environment, superseding DEC-013. Flutter remains outside Docker. No business functionality.
*Depends on: Phase 4*

**Phase 5 — Roles & Permissions** *(complete — see `docs/handoffs/V1_PHASE_05_HANDOFF.md`)*
V1 role catalog (Administrator/Manager/Staff, one role per user) and a foundational permission catalog, role-permission storage, a centralized `Gate::before`-based authorization pattern established for reuse by every later module. The full permission catalog for each business module is added when that module is actually built, not here.
*Depends on: Phase 4*

### Company Core
*Depends on: Phase 5*

**Phase 6 — Organization Structure** *(complete — see `docs/handoffs/V1_PHASE_06_HANDOFF.md`)*
Departments, Teams, Positions and their relationships.

**Phase 7 — Staff** *(complete — see `docs/handoffs/V1_PHASE_07_HANDOFF.md`)*
Staff records, profiles, employment data, manager relationships. Staff Directory.

**Phase 8 — Clients & Contacts** *(complete — see `docs/handoffs/V1_PHASE_08_HANDOFF.md`)*
Client records and their contacts.

**Phase 9 — Staff Status & Location Check-in** *(complete — see `docs/handoffs/V1_PHASE_09_HANDOFF.md`)*
Current status tracking (with history, DEC-010), explicit check-in flow (DEC-005).

### Work Management
*Depends on: Phase 7 (Staff); Phase 8 (Clients, for project↔client linkage)*

**Phase 10 — Projects & Project Membership**
Projects, project members, project milestones.

**Phase 11 — Tasks**
Tasks (project-linked and independent, DEC-006), assignments, comments.
*Depends on: Phase 10*

**Phase 12 — Work Logs**
Time/activity logging against tasks/projects.
*Depends on: Phase 11*

### HR
*Depends on: Phase 7 (Staff)*

**Phase 13 — Leave Management**
Leave types, requests, approvals (with history), balances.

### Communication
*Depends on: Phase 5 (Roles/Permissions); Phase 7 (Staff)*

**Phase 14 — Announcements**
Company-wide/scoped announcements, recipients, acknowledgements.

**Phase 15 — Notifications**
Generic in-app notification infrastructure, feeding from other modules' events.
*Depends on: Phase 14 (first real notification producer), Phase 2 (queue infra)*

**Phase 16 — Messaging**
Direct, group, and project conversations.
*Depends on: Phase 10 (project conversations), Phase 15 (notification of new messages)*

### Operations
*Depends on: Phase 8 (Clients), Phase 9 (Check-in), Phase 10 (Projects)*

**Phase 17 — Scheduler**
Unified schedule/calendar surfacing meetings, visits, appointments, milestones, deadlines, events, leave, training.
*Depends on: Phase 13 (Leave), Phase 11 (Task deadlines), Phase 10 (Milestones)*

**Phase 18 — Service Reports**
Service report creation, review, attachments.

**Phase 19 — Incident Reports**
Incident creation, assignment, action history, resolution.

### Management
*Depends on: most prior modules existing to have data to report on*

**Phase 20 — Admin Dashboard & Reporting**
Cross-module dashboard and administrative reports.

### Release Preparation
*Sequential, depends on all prior phases*

**Phase 21 — Integration Audit** — cross-module consistency review.
**Phase 22 — Security Audit** — review against `05_SECURITY_MODEL.md`.
**Phase 23 — Mobile UI/UX Audit** — review against `06_UI_UX_GUIDELINES.md`.
**Phase 24 — Staging Deployment** — first real deployment.
**Phase 25 — UAT** — product owner acceptance testing (see `docs/testing/UAT_LOG.md`).
**Phase 26 — Release Readiness** — final go/no-go.

---

## Notes

- Audit logging (DEC-009) is not a standalone phase — it is expected to be introduced as shared infrastructure during Phase 3 (Core Architecture) or Phase 5 (Roles & Permissions), then used by every subsequent module. If that doesn't happen, Phase 21 (Integration Audit) is the backstop to catch the gap.
- This roadmap is provisional beyond Phase 1. Boundaries may shift as real implementation surfaces better groupings — any shift should be noted in `CHANGELOG.md` and, if it reflects a real decision (not just a rename), in `DECISIONS.md`.
