# V1 Phase 21 — Integration Audit: Definition

**Status:** Authorized and implemented. Preceded by a product-owner-reviewed planning audit (delivered as a chat message, not a file, ahead of this phase) that resolved the roadmap's terse "cross-module consistency review" line, together with `docs/ROADMAP.md`'s own Notes-section backstop clause, into concrete, bounded scope — see `docs/DECISIONS.md` DEC-044 for the full accepted decisions.

## 1. Scope

Two bounded deliverables, and nothing else:

**(A) General Audit Log** — the DEC-009 project-wide auditability backstop the roadmap itself named Phase 21 as the fallback for, since it was never built during Phase 3/Phase 5 as originally anticipated. A new, lean, append-only `audit_logs` table plus a small, explicit `App\Services\Audit\AuditLogger` service, wired into a curated set of administrative/security-sensitive mutations across the application. Structurally separate from, and never a replacement for, any existing domain-specific history table (`leave_request_actions`, `service_report_actions`, `incident_report_actions`, `staff_statuses`, `staff_checkins`) — all remain unmodified and fully authoritative for their own workflow.

**(B) A bounded cross-module consistency audit** — a targeted review of Phases 1–20's actual implemented behavior against their own already-approved decisions, never a redesign of working modules and never a vehicle for pulling deferred/out-of-scope features into this phase. Every finding is classified as a **Phase 21 correction**, a **documentation correction**, a **Future consideration**, or **No action/intentional difference** — see `docs/handoffs/V1_PHASE_21_HANDOFF.md` for the full, categorized findings list and exactly which corrections (if any) were applied.

## 2. Audit Log schema (`audit_logs`)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `actor_user_id` | nullable FK → `users`, `nullOnDelete()` | Null for a failed login with no safely-resolvable account, or once the acting account is later removed. |
| `action` | string | Stable dot-notation name (`App\Support\Audit\AuditActions`) — never derived from a class/method name. |
| `entity_type` | string | Always populated (e.g. `"Staff"`). |
| `entity_public_id` | nullable string | A public ULID/identifier (DEC-017) — **not** a foreign key, **not** a polymorphic ownership relation. Never joined against. |
| `changed_fields` | nullable JSON | Field *names* only. |
| `before` / `after` | nullable JSON | Curated, allowlisted structural values only — never a full model snapshot or raw request payload. |
| `ip_address` | nullable string | |
| `user_agent` | nullable text | |
| `source` | string | `"api"` or `"admin"` (`App\Enums\AuditSource`). |
| `created_at` | timestamp | **No `updated_at`** — `AuditLog::UPDATED_AT = null`. Append-only; no update/delete route exists. |

Indexes: `(entity_type, entity_public_id)`, `(actor_user_id, created_at)`, `(action, created_at)`. No standalone `created_at` index (no anticipated V1 query needs one; add later only if a real query plan demonstrates it).

## 3. Redaction discipline

`changed_fields` names fields, never values. `before`/`after` are populated only from a short, explicit, per-integration-site allowlist of low-sensitivity structural values (status, a public-ID reference, a membership role) — never a generic "dump the model" or "dump the request" mechanism. Never audited under any circumstance: passwords/hashes, tokens/API keys/credentials, Authorization headers, cookies, Leave Request `reason`, Incident Report narrative fields, Service Report narrative content, Message `body`, attachment contents or original filenames, or any arbitrary/wholesale request payload. See `docs/DECISIONS.md` DEC-044 and `docs/05_SECURITY_MODEL.md` for the full rationale.

## 4. V1 audited event catalog

| Area | Events | Curated detail captured |
|---|---|---|
| Authentication (API + Admin) | `auth.login_succeeded`, `auth.login_failed`, `auth.logout` | Actor (null on failure), IP, user agent, `source` |
| Staff | `staff.created`, `staff.updated`, `staff.separated` | status, department/team/position/manager (as public IDs) — never ordinary profile fields |
| Organization Structure | `organization.{department,team,position}.{created,updated,deleted}` | name/title, status, department |
| Clients & Contacts | `client.{created,updated,deleted}`, `contact.{created,updated,deleted}` | status (Client); status/client/is_primary (Contact) — never personal contact details |
| Projects | `project.{created,updated,deleted}` | status, client |
| Project Membership | `project.membership_added`, `project.membership_removed`, `project.membership_role_changed` | Project + Staff public IDs, role |
| Tasks | `task.deleted` only | status, project |
| Announcements | `announcement.published`, `announcement.archived` | status transition only — never title/body |
| Attachments | `attachment.uploaded`, `attachment.deleted` (Service Report + Incident Report) | owning report's type/public ID — never the filename |
| Reports (Phase 20) | `report.exported` × 7 resources | which resource — never exported rows/filters |
| Audit Log itself | `audit_log.exported` | — |

**Explicitly excluded in V1:** every ordinary `GET`/list/show request, Dashboard/Report viewing, attachment downloads, Notification reads/delivery, Message sends/reads, Scheduler CRUD, Work Log CRUD, ordinary Task create/update/status change, Staff check-ins, Announcement acknowledgement, and the three workflow-transition families already captured by their own domain history table (Leave Request approve/reject/cancel, Service Report submit/review/reject, Incident Report assign/resolve/close/reopen). The last exclusion is a V1 event-selection decision, not a permanent statement that general auditing may never overlap domain history later.

**Known, documented gap:** no mutation surface exists anywhere in the application for User account suspension/reactivation/role assignment — see DEC-044's Known Limitation and the consistency-audit findings in the handoff. These three action names are reserved, not implemented.

## 5. Authorization & API

Administrator-only, resolved by a direct `hasRole(Role::ADMINISTRATOR)` check (`App\Http\Controllers\Api\V1\Audit\Concerns\AuthorizesAuditLogAccess`) — no new permission was added to the catalog. Manager and Staff have no access at all; there is deliberately no Manager-of-direct-reports tier.

- `GET /api/v1/audit-logs` — paginated JSON list.
- `GET /api/v1/audit-logs/export` — CSV, reusing `App\Support\Reporting\CsvExport` verbatim (streamed, UTF-8/BOM, formula-injection-safe, stable headers, no internal ids); `before`/`after` are deliberately excluded from the CSV shape (no raw JSON in a cell) — `changed_fields` alone appears, as a plain comma-joined list.
- Filters: `from`, `to`, `actor` (public ID), `action`, `entity_type`, `entity_public_id`. No free-text search, no full-text indexing.

API-only in V1 — no Admin Backoffice UI, no Flutter UI, consistent with every module since Phase 6.

## 6. Architecture

`App\Services\Audit\AuditLogger` — one small, explicit service, called directly from each integration site (every one a controller in this phase). No generic model observer attached broadly, no mutation middleware, no event/listener bus, no third-party auditing package. `AuditLogger::diff()` compares two curated attribute arrays across a caller-supplied field allowlist — the allowlist is always decided by the calling site, never guessed centrally. Each event path uses exactly one mechanism, so no action can be double-logged.

**Transactions:** an audited administrative mutation's audit write happens synchronously, inside the same DB transaction as the business mutation, so a failure to write the audit entry rolls back the mutation too. Authentication is the one exception (no business mutation to roll back on a failed login). Logout ordering captures the actor before the token/session is invalidated, on both the API and Admin surfaces.

**Infrastructure:** ordinary synchronous relational writes only — no Redis, queue, background worker, scheduled processing, Kafka, Elasticsearch, external SIEM, audit microservice, or materialized view.

## 7. Explicitly out of scope

S3/object-storage migration, antivirus/malware scanning, check-in retention/purge jobs, Messaging moderation/admin access, Incident confidentiality/HR/whistleblower workflow, Task comments, Leave→Task/Work Log automation, any Admin Backoffice CRUD UI, Flutter/Admin Audit Log UI, external SIEM, a third-party integration framework, and — per the codebase investigation in §4 — a new User account management mutation surface (suspend/reactivate/role-change), since building one is new product behavior outside an Integration Audit's scope.

## 8. Testing

See `docs/testing/TEST_STATUS.md`'s Phase 21 section — automated coverage spans the Audit Log's own schema/immutability, authorization (Administrator/Manager/Staff/no-role/suspended), filtering/pagination/CSV parity, redaction (no password/token/narrative/PII content in any row across a representative cross-section of flows), every catalogued event actually firing exactly once, every excluded action firing none, transaction/fail-closed behavior, and a full Phase 1–20 regression run confirming no existing endpoint's behavior changed.
