# Phase 16 — Messaging — Specification

**Status:** COMPLETE
**Depends on:** Phase 10 (Projects & Project Membership — project conversations), Phase 15 (Notifications — notification of new messages)

## Objective

Build a lightweight backend/API Messaging module: direct (1:1), group, and project conversations with plain-text messages, basic unread state, and in-app Notification integration. Deliberately not a chat platform (DEC-007) — no Slack/Teams/Messenger feature parity.

This phase followed a product-owner-approved planning audit (conducted before any implementation) that resolved every open architectural/product question named in `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, and `docs/05_SECURITY_MODEL.md`'s prior Communication/Messaging notes. The approved decisions are recorded in DEC-039 and implemented here without deviation.

## In Scope

- `conversations`/`messages`/`conversation_members` tables — see DEC-039 for full schema.
- `App\Enums\ConversationType` (`direct`/`group`/`project`).
- Direct conversations: exactly one canonical conversation per unique Staff pair, found-or-created, never duplicated.
- Group conversations: ad hoc, named, single permanent owner (the creator) — owner-only member add/remove, self-leave for any member, an owner-cannot-leave-while-others-remain invariant.
- Project conversations: exactly one per Project, lazily created on first use by a current Project member, membership derived exclusively from and kept in sync with Project Membership (Phase 10) — never an independently managed roster.
- Plain-text messages, ≤4,000 characters, immutable after sending (no edit, no soft/hard delete).
- Read state: a single per-member `last_read_message_id` reference; unread count always derived, never cached.
- Notification integration: `App\Enums\NotificationType::MessageReceived` / `NotificationSourceType::Conversation`, one Notification per other current member per message, synchronous, generic content, sender excluded, no deduplication across messages.
- Authorization: membership/ownership-only, no new permission, no Administrator override; `404` for non-members.
- `StaffController::destroy()`/`ProjectController::destroy()` extended with Messaging relational-integrity checks.
- Correction of `docs/02_ARCHITECTURE.md`'s stale Redis/queue/broadcast architecture diagram to reflect what was actually built (nothing) and to record the deliberate real-time deferral.

## Explicitly Out of Scope

Flutter/mobile Messaging UI; attachments/files; message editing; message deletion/retraction (sender or Administrator); message/conversation search; archive/mute; reactions; mentions; typing indicators; online/presence state; rich text/HTML; Team- or Department-linked conversations; visible per-message read receipts/"seen by"; any Admin moderation/read-all capability; external push/email/SMS delivery; WebSockets/Reverb/Redis/queue workers/broadcasting infrastructure of any kind; retention/cleanup jobs; a generic polymorphic event bus; Messaging-specific audit logging (Audit Logging, DEC-009, remains a known, pre-existing, project-wide gap — not addressed here).

## Relevant Documentation

- `docs/ROADMAP.md` — Phase 16: "Direct, group, and project conversations." Depends on Phase 10, Phase 15.
- `docs/DECISIONS.md` — DEC-007 (simple messaging first), DEC-039 (this phase's full architecture).
- `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md` — updated with this phase's schema/endpoints/authorization shape and the corrected architecture diagram.

## Acceptance Criteria

- Opening a direct conversation for the same Staff pair twice never creates a duplicate; it works in either participant order.
- A direct conversation always has exactly two participants and its membership can never be changed.
- A group conversation's creator is its owner; only the owner adds/removes other members; any member may leave; the owner can never be left as the sole remaining party to an otherwise-populated group (the leave/remove path itself is blocked while other members remain, not merely discouraged).
- A Project's conversation does not exist until first requested by a current Project member, and its roster always matches current Project Membership afterward, including through later additions/removals.
- A message is plain text, ≤4,000 characters, and cannot be edited or deleted through any endpoint.
- A conversation/message a requester is not currently a member of is `404`, including for Administrator — no permission grants blanket Messaging visibility.
- Every sent message creates exactly one Notification for every other current member with a linked User account, excluding the sender, with generic non-leaking content; multiple messages are never collapsed into one Notification.
- Unread count is always correct and always derived from `last_read_message_id`, never a stored count.
- A Staff member with a Messaging-related reference (current membership, group ownership, or sent-message history) cannot be deleted; a Project with a conversation cannot be deleted.
- Suspended/inactive accounts lose Messaging access via the existing `account.active` middleware, with no separate mechanism.

## Testing Expectations

Direct-conversation uniqueness (both participant orders); direct participant fixedness; group creation/ownership; group member add/remove authorization; the owner invariant (blocked while others remain, permitted once alone); member self-leave; project conversation lazy creation; project membership synchronization (add and remove, before and after conversation creation); project conversation membership rejecting direct management; message send/list/pagination; message immutability (no edit/delete route exists); membership-only visibility (including the Administrator-gets-no-implicit-access case); `404`-not-`403` for foreign conversations/messages; read/unread behavior including idempotent mark-read and post-read new-message accrual; derived unread counts at both the single-conversation and list level; notification fan-out (every other member, sender excluded, no dedup, generic content, correct source); a Staff member with no linked User as a message target/skip case; suspended-account rejection; a User with no linked Staff rejection; Staff/Project deletion restrictions; API shape (no internal ids); full Phase 1–15 regression.

## Notes

- Participant identity is Staff, not User — a deliberate, product-owner-approved departure from Notifications' DEC-038 User-based choice. See DEC-039.
- No real-time transport was adopted; `02_ARCHITECTURE.md` §5/§9's long-open question is resolved in favor of deliberate deferral, not indecision — see DEC-039's closing section and the corrected `02_ARCHITECTURE.md` §1 diagram.
- Flutter's `06_UI_UX_GUIDELINES.md` already places "Messages" as a primary navigation tab; this phase deliberately does not build that UI (product-owner scope decision) — a future phase remains free to.
