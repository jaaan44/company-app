# Phase 15 — Notifications — Specification

**Status:** COMPLETE
**Depends on:** Phase 14 (Announcements — first real notification producer), Phase 2 (CI)

## Objective

Build the foundational in-app Notifications module: a lean, unified place for employees to see application-generated alerts about events elsewhere in the application. This is infrastructure plus exactly one wired producer (Announcement publish) — not a messaging system, email platform, push infrastructure, or workflow engine.

## In Scope

- `notifications` table / `App\Models\Notification` — recipient is the `User` account (DEC-038), not `Staff`.
- `App\Enums\NotificationType` (one case: `announcement_published`) and `App\Enums\NotificationSourceType` (one case: `announcement`) — both closed, small sets.
- Read/unread state (`read_at`, nullable), server-controlled, idempotent mark-read (preserves the original first-read timestamp).
- Self-service API: `GET /me/notifications` (paginated, `?unread=1`, `?type=`), `GET /me/notifications/{public_id}`, `GET /me/notifications/unread-count`, `POST /me/notifications/{public_id}/read`, `POST /me/notifications/read-all`.
- Exactly one event integration: `AnnouncementController::publish()` fans out one Notification per resolved recipient (company-wide → every Staff-linked User; scoped → union of targeted Departments'/Teams' Staff-linked Users), as a publish-time snapshot, inside the same DB transaction as the publish itself.
- Removal of the unused, default-scaffold `Illuminate\Notifications\Notifiable` trait from `App\Models\User` (see Deviations/Discoveries below) — it declared a `notifications`/`notify()` shape incompatible with this phase's own `notifications` table and was never used anywhere in the codebase.

## Explicitly Out of Scope

Flutter Notification screens; Admin Notification CRUD UI; manually authored/arbitrary notifications; direct messaging, group/project chat, comments, reactions, a social feed; notification preferences; any external delivery channel (FCM, APNs, Web Push, email, SMS, WhatsApp, Slack/Teams); digests; a reminder/escalation engine; per-device notification state; read receipts beyond a single `read_at`; an analytics/read-rate dashboard; automatic retention/cleanup jobs; queues/broadcasting infrastructure; a generic polymorphic event bus; new permissions (`notifications.view`/`.manage`); wiring Leave Management or Tasks into notifications (not named by the governing roadmap line for this phase — see Notes).

## Relevant Documentation

- `docs/ROADMAP.md` — Phase 15: "Generic in-app notification infrastructure, feeding from other modules' events. Depends on: Phase 14 (first real notification producer)."
- `docs/03_DATABASE_MODEL.md` §Communication — updated to reflect the actual (typed, non-polymorphic) schema now that this is implemented.
- `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md` — updated with this phase's endpoints/authorization shape.
- `docs/DECISIONS.md` DEC-038.

## Acceptance Criteria

- An employee can list, view, and mark their own notifications read (one at a time or all at once) via `/api/v1/me/notifications...`, and never another employee's (404, not 403).
- Publishing an Announcement creates exactly one Notification per eligible recipient, matching the same audience rules `/me/announcements` itself uses, evaluated once at publish time (a snapshot — later org changes never retroactively add/remove/alter those rows).
- No sensitive domain content (e.g. Announcement body) appears in Notification text.
- No Admin CRUD surface, new permission, external delivery channel, or queue was introduced.

## Testing Expectations

- Self-service visibility, ownership, and 404-vs-403 boundaries.
- Listing (newest-first, pagination, `?unread=`, `?type=`).
- Read-state semantics (default unread, mark-read idempotency preserving the original timestamp, mark-all scoping, ownership).
- API shape (no internal ids, no recipient identity, bounded `source`).
- A real HTTP smoke test driven by an actual Announcement publish (not just factory-inserted rows).
- Announcement fan-out: company-wide, Department-scoped, Team-scoped, union dedupe, Staff-without-account skipped, no second Notification on archive, later org changes don't alter existing rows.
- Full regression of the Phase 1–14 suite.

## Notes

- The roadmap names Phase 14 specifically as Phase 15's "first real notification producer" — Leave Management and Tasks are not named anywhere in the roadmap or technical docs as Phase 15 event sources, so neither was wired in, per CLAUDE.md §3's scope discipline. Wiring either is future work, recorded as a roadmap note rather than built speculatively.
- The roadmap also lists "Phase 2 (queue infra)" as a Phase 15 dependency, but no queue/worker infrastructure was ever actually built (Phase 4A's Docker Compose stack explicitly has no queue worker) — Notification creation is synchronous, inside the same request/transaction as its triggering action, consistent with this codebase's existing "no infrastructure without a demonstrated need" pattern and this phase's own governing instructions.
