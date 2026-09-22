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

**Phase 10 — Projects & Project Membership** *(complete — see `docs/handoffs/V1_PHASE_10_HANDOFF.md`)*
Projects, project members. (Project milestones were not part of the governing Phase 10 instructions and were deferred — later implemented in Phase 17, see `docs/handoffs/V1_PHASE_17_HANDOFF.md` and DEC-040. This roadmap originally listed Phase 17 as depending on "Phase 10 (Milestones)," incorrectly assuming they existed already — corrected below.)

**Phase 11 — Tasks** *(complete — see `docs/handoffs/V1_PHASE_11_HANDOFF.md`)*
Tasks (project-linked and independent, DEC-006), single-assignee assignment, lifecycle/priority/due date. Task comments (originally mentioned above) were not part of the governing Phase 11 instructions and remain unimplemented — deferred to a future collaboration phase alongside Work Logs/task activity/notifications (see `docs/DECISIONS.md` DEC-034).
*Depends on: Phase 10*

**Phase 12 — Work Logs** *(complete — see `docs/handoffs/V1_PHASE_12_HANDOFF.md`)*
Historical time/activity logging against tasks and/or projects (never neither), performed by Staff. Not payroll, attendance, or billing.
*Depends on: Phase 11*

### HR
*Depends on: Phase 7 (Staff)*

**Phase 13 — Leave Management** *(complete — see `docs/handoffs/V1_PHASE_13_HANDOFF.md`)*
Leave types, requests, approvals (with history), balances.

### Communication
*Depends on: Phase 5 (Roles/Permissions); Phase 7 (Staff)*

**Phase 14 — Announcements** *(complete — see `docs/handoffs/V1_PHASE_14_HANDOFF.md`)*
Company-wide/scoped announcements, recipients, acknowledgements. "Recipients" resolved as Department/Team audience targeting (union semantics), not arbitrary individual-Staff targeting; "acknowledgements" is a lightweight, idempotent, self-initiated acknowledgement record — deliberately not read/unread tracking, a mandatory-acknowledgement compliance workflow, or engagement analytics (see DEC-037).

**Phase 15 — Notifications** *(complete — see `docs/handoffs/V1_PHASE_15_HANDOFF.md`)*
Generic in-app notification infrastructure, feeding from other modules' events. "Feeding from other modules' events" resolved narrowly to the one producer the roadmap itself names above — Announcement publish; Leave Management/Task event integration remain unimplemented, deferred until a future phase names them explicitly (see `docs/DECISIONS.md` DEC-038). No queue infrastructure was introduced — synchronous, transactional creation is sufficient at this company's scale.

**Phase 16 — Messaging** *(complete — see `docs/handoffs/V1_PHASE_16_HANDOFF.md`)*
Direct, group, and project conversations. Preceded by a product-owner-reviewed planning audit (see DEC-039) that resolved participant identity (Staff, not User — a deliberate departure from Notifications' DEC-038), direct-conversation canonicalization, group ownership/membership rules, project-conversation lazy creation and Project Membership sync, message immutability, derived read/unread state, and the real-time-transport question (deferred — request/response only, no WebSockets/queue/broadcasting infrastructure). No new permission was introduced; authorization is membership/ownership-only, with no Administrator override.
*Depends on: Phase 10 (project conversations), Phase 15 (notification of new messages)*

### Operations
*Depends on: Phase 8 (Clients), Phase 9 (Check-in), Phase 10 (Projects)*

**Phase 17 — Scheduler** *(complete — see `docs/handoffs/V1_PHASE_17_HANDOFF.md`)*
Unified schedule/calendar surfacing meetings, visits, appointments, milestones, deadlines, events, leave, training. Resolved as a hybrid: a read-time aggregation API over exactly four sources (manually created Schedule Entries, Task due dates, approved Leave Requests, Project Milestones) plus a lightweight Scheduler-owned `schedule_entries` entity for activities with no other system-of-record module. No calendar rows are copied into a generic table — each source remains its own system of record (DEC-040).
*Depends on: Phase 13 (Leave), Phase 11 (Task deadlines), Phase 10 (Projects — Milestones deferred there, implemented here — see the note below)*

**Phase 18 — Service Reports** *(complete — see `docs/handoffs/V1_PHASE_18_HANDOFF.md`)*
Service report creation, review, attachments. Preceded by a product-owner-reviewed planning audit (see DEC-041) that resolved the roadmap's terse line into concrete architecture: Client is the required business anchor (Project/Task optional, mirroring DEC-006), a four-state workflow (`draft`/`submitted`/`reviewed`/`rejected` — `reviewed` is the single final state, no separate "approved"/"completed"), visibility/review authority resolved via row-level rules with no new permission (deliberately narrower than Scheduler's model — no broad Manager grant), and the project's previously deferred shared attachment infrastructure (`03_DATABASE_MODEL.md`'s Phase-0-era open question), built now because Phase 19 (Incident Reports) is the concrete next consumer.
*Depends on: Phase 8 (Clients), Phase 10 (Projects), Phase 11 (Tasks), Phase 7 (Staff)*

**Phase 19 — Incident Reports** *(complete — see `docs/handoffs/V1_PHASE_19_HANDOFF.md`)*
Incident creation, assignment, action history, resolution. Preceded by a product-owner-reviewed planning audit (see DEC-042) that resolved the roadmap's terse line into concrete architecture: a naming correction (canonical `incident_reports`/`incident_report_actions`, not the Phase-0-era `incidents`/`incident_actions` working names), all three business anchors (Client/Project/Task) optional — deliberately more permissive than Service Reports' required-Client anchor, since an incident may be entirely internal — a single nullable investigator (`assigned_to_staff_id`) mutated only through dedicated assign/reassign/start-investigation action endpoints, an investigation-oriented lifecycle (`reported`/`under_investigation`/`resolved`/`closed`, plus an explicit `reopen` action) deliberately not copying Service Reports' draft/submitted/reviewed/rejected shape, visibility/authority resolved via row-level rules with no new permission and deliberately **narrower** than Service Reports' (no Project-Lead carve-out at all), and the second authorized consumer of the shared attachment infrastructure Phase 18 built in anticipation of this phase.

### Management
*Depends on: most prior modules existing to have data to report on*

**Phase 20 — Admin Dashboard & Reporting** *(complete — see `docs/handoffs/V1_PHASE_20_HANDOFF.md`)*
Cross-module dashboard and administrative reports. Preceded by a product-owner-reviewed planning audit that resolved the roadmap's terse line into concrete architecture: two read-only API surfaces (a single `GET /api/v1/dashboard` aggregation endpoint and seven flat, paginated `GET /api/v1/reports/{resource}` detail resources, each with CSV export), a governing rule that aggregation must never widen a source module's own existing row-level visibility (no new permission was introduced anywhere in this phase), canonical "overdue Task"/"open Incident Report" definitions, and live relational queries only — no snapshot tables, caching, queues, or reporting infrastructure (see `docs/DECISIONS.md` DEC-043).

### Release Preparation
*Sequential, depends on all prior phases*

**Phase 21 — Integration Audit** *(complete — see `docs/handoffs/V1_PHASE_21_HANDOFF.md`)* — two bounded deliverables per DEC-044: the DEC-009 general Audit Log backstop (built now, since Phase 3/5 never introduced it as originally anticipated below), and a bounded cross-module consistency review of Phases 1–20 against their own already-approved decisions.
**Phase 22 — Security Audit** *(complete — see `docs/phases/V1_PHASE_22_SECURITY_AUDIT.md`, `docs/handoffs/V1_PHASE_22_HANDOFF.md`)* — review against `05_SECURITY_MODEL.md`. Delivered as two authorized steps: an evidence-based audit (no Critical/High findings) followed by a narrow, product-owner-approved remediation (one required login-disclosure fix, a bounded Sanctum token expiration, and inexpensive hardening — see DEC-045).
**Phase 23 — Mobile UI/UX Audit & Foundation** *(complete — see `docs/phases/V1_PHASE_23_MOBILE_UIUX_AUDIT.md`, `docs/handoffs/V1_PHASE_23_HANDOFF.md`)* — review against `06_UI_UX_GUIDELINES.md`; resolved the visual-design, accessibility, and navigation-architecture questions that document had left open (DEC-046). No Flutter code was changed.
**Phase 24 — Staging Deployment** *(complete, including a verified real VPS deployment — see `docs/phases/V1_PHASE_24_STAGING_DEPLOYMENT_PLAN.md`, `docs/handoffs/V1_PHASE_24_HANDOFF.md`, `docs/DEPLOYMENT_STAGING.md`)* — first real deployment. TLS/domain, public (UFW) exposure, and mobile-client verification remain a separate, explicitly deferred follow-up — see Phase 26 below.

---

### Employee Mobile Application
*Depends on: Phase 24 (Staging Deployment, for later connectivity validation); the completed backend API (Phases 5–21)*

**Product-owner direction (2026-09-22, see DEC-048):** the employee-facing Flutter mobile application is built out before the Admin Backoffice is built out to completion. Phase 25 (originally specified as UAT) is redefined as the mobile foundation phase below; UAT and Release Readiness move later in this roadmap. This is a re-baseline, not a reversal of any Phase 1–24 decision — see DEC-048 for the full reasoning and `docs/phases/V1_PHASE_25_DEFINITION.md` for Phase 25's formal specification.

**Phase 25 — Mobile Application Foundation & Navigation Shell** — see `docs/phases/V1_PHASE_25_DEFINITION.md`. Establishes the `go_router`-based navigation shell (the bottom-navigation tab structure `06_UI_UX_GUIDELINES.md`/DEC-046 already specified), session-aware auth redirects, the Material 3 light/dark theme (closing Phase 23's G-01/G-02 findings), and a mobile testing foundation — no business-module screen is implemented.
**Phase 26 — Staging Mobile Connectivity & TLS Validation** — the staging TLS/domain decision, the corresponding UFW change, and real-device connectivity verification against the live staging API (using the existing Login screen) — deliberately placed immediately after Phase 25, before further mobile-module phases accumulate on an unvalidated foundation.
**Phase 27 — Employee Home / Dashboard (Mobile)** — the Home tab's real content, likely a self-scoped view of Phase 20's dashboard aggregation.
**Phase 28 — People: Staff Directory & Profile (Mobile)** — Staff Directory browsing and the staff member's own profile.
**Phase 29 — Work: Clients, Projects, Tasks & Work Logs (Mobile)** — the Tasks tab, Projects/Client browsing, and Work Log entry; may be split into sub-phases at its own planning time if too large for one phase.
**Phase 30 — Operations: Schedule & Check-in (Mobile)** — the Schedule tab and Location/Check-in.
**Phase 31 — HR: Leave Management (Mobile)** — leave requests, balances, and (where permitted) approvals.
**Phase 32 — Communication: Announcements, Notifications & Messages (Mobile)** — the Messages tab, the Announcements feed, and the Notifications inbox.
**Phase 33 — Field Reporting: Service Reports, Incident Reports & Attachments (Mobile)** — grouped because both modules share the same Phase 18 attachment infrastructure; the mobile attachment upload/download UI is built once and reused for both.

---

### Admin Backoffice
*Depends on: the completed backend API (Phases 5–21); does not block, and is not blocked by, the Employee Mobile Application phases above — the two tracks share only the already-stable API*

**Phase 34 — Admin Backoffice Foundation** — the Blade/Livewire navigation shell (`06_UI_UX_GUIDELINES.md`'s existing `Dashboard / People | Clients | Work | HR | Operations | Communication | Reports | Administration` section layout) and the web visual-design-system decision `06_UI_UX_GUIDELINES.md` explicitly left open for "whichever phase first builds real Admin Backoffice screens."
**Phase 35 — Admin Backoffice Business Management Screens** — CRUD/management UI for the business modules, grouped by the same section layout; may be split into sub-phases at its own planning time.

---

### Release Preparation (re-baselined)
*Sequential, depends on the phases above*

**Phase 36 — Mobile/Admin Integration & UX Hardening** — cross-cutting polish and real-device/cross-browser verification across both surfaces once real workflows exist on both; the natural point to revisit whether a shared loading/empty/error-state widget library (deferred at Phase 25 as premature) is now justified by real, repeated usage.
**Phase 37 — UAT** — product owner acceptance testing (see `docs/testing/UAT_LOG.md`), now meaningful because real client workflows exist on both surfaces. Formerly numbered Phase 25 — see DEC-048.
**Phase 38 — Release Readiness** — final go/no-go. Formerly numbered Phase 26 — see DEC-048.

---

## Notes

- Audit logging (DEC-009) was not introduced during Phase 3 (Core Architecture) or Phase 5 (Roles & Permissions) as originally anticipated here — every phase from 6 through 20 re-flagged the same standing gap. Phase 21 (Integration Audit) was the named backstop for exactly this case, and built it — see DEC-044.
- **Phase 25 onward was re-baselined on 2026-09-22 (DEC-048).** The original roadmap assumed Phase 25 = UAT and Phase 26 = Release Readiness, on the (reasonable, at the time) assumption that the backend API would be the long pole. A dedicated discovery/readiness assessment established that the backend is in fact substantially complete while both user-facing clients (Flutter mobile, Admin Backoffice) remain foundation-only — meaning UAT as originally sequenced would only have been able to exercise authentication. The product owner then directed that the employee mobile application be built out before the Admin Backoffice, without either blocking the other. See DEC-048 for the full reasoning and this file's Employee Mobile Application / Admin Backoffice / Release Preparation sections above for the resulting sequence.
- This roadmap is provisional beyond Phase 1. Boundaries may shift as real implementation surfaces better groupings — any shift should be noted in `CHANGELOG.md` and, if it reflects a real decision (not just a rename), in `DECISIONS.md`.
