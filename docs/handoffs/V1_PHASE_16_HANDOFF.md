# V1 Phase 16 — Messaging — Handoff

## 1. Phase Identification

- **Phase:** 16 — Messaging
- **Date:** 2026-09-12
- **Branch:** `claude/affectionate-ritchie-291ode` (branched from `main`, not merged)

## 2. Objective

Build a lightweight backend/API Messaging module — direct (1:1), group, and project conversations with plain-text messages, basic unread state, and in-app Notification integration — per the product-owner-approved planning audit and decisions that preceded this implementation. Deliberately not a chat platform (DEC-007): no Slack/Teams/Messenger feature parity.

## 3. Scope Implemented

- `conversations`/`messages`/`conversation_members` tables and their Eloquent models.
- `App\Enums\ConversationType` (`direct`/`group`/`project`).
- Direct conversations: one canonical conversation per unique Staff pair, found-or-created.
- Group conversations: ad hoc, named, single permanent owner, owner-only member add/remove, self-leave, an owner-cannot-leave-while-others-remain invariant.
- Project conversations: one per Project, lazily created on first use by a current Project member, membership derived from and synchronized with Project Membership (Phase 10).
- Plain-text messages (≤4,000 chars), fully immutable after sending.
- Read state: a single per-member `last_read_message_id`; unread count always derived.
- Notification integration: a second real producer (`NotifiesConversationMembers`), extending `NotificationType`/`NotificationSourceType` by one case each.
- Membership/ownership-only authorization, no new permission, no Administrator override.
- `StaffController::destroy()`/`ProjectController::destroy()` extended with Messaging relational-integrity checks.
- Correction of `docs/02_ARCHITECTURE.md`'s stale Redis/queue/broadcast diagram and resolution of its long-open real-time-transport question.

## 4. Implementation Summary

**Participant identity (DEC-039):** Messaging participants are Staff, not User — a deliberate departure from Notifications' DEC-038 choice, since Messaging is chosen from the Staff Directory rather than a raw User list. `App\Http\Controllers\Api\V1\Messaging\Concerns\AuthorizesConversationAccess::resolveAuthenticatedStaff()` (mirroring Phase 9's `RequiresLinkedStaff`) is the single domain check every Messaging endpoint runs first — a User with no linked Staff record gets `403` everywhere.

**Direct conversations:** `ConversationController::storeDirect()` looks for an existing `type = direct` conversation with both the caller's and the target's Staff ids as members (two `whereHas` clauses — correct because a direct conversation is exactly two members, by construction, for its entire lifetime) before creating one inside a transaction. Opening the same pair again, in either order, returns the same row (`200`); a first-time open returns `201` (Laravel's own `wasRecentlyCreated`-driven `JsonResource` status calculation, not an explicit branch). `ConversationMemberController` rejects any add/remove attempt on a `direct` conversation with `409`.

**Group conversations:** `ConversationController::storeGroup()` creates the conversation with `owner_staff_id` set to the caller and inserts the deduplicated union of the caller and the requested `member_staff_ids`. `ConversationMemberController::store()`/`destroy()` enforce: only the owner may add a member or remove a *different* member; any member (owner included) may remove themselves; and — the owner invariant — the owner may never leave/be removed while any other member remains (`409`), but may do so once they are the sole remaining member (the group becomes an empty, harmless, never-deleted row — there is no conversation hard-delete endpoint anywhere).

**Project conversations:** `ProjectConversationController::storeOrShow()` requires current Project Membership (never merely `projects.view`) and lazily creates the conversation, seeded from the Project's current membership roster, on first call; a unique index on `conversations.project_id` plus a `QueryException`-catch-and-refetch handles the rare concurrent-first-call race without a distributed lock. `App\Http\Controllers\Api\V1\Messaging\Concerns\SyncsProjectConversationMembership` — a small trait, no-op whenever the Project has no conversation yet — is wired into `ProjectMembershipController::store()`/`destroy()` so a Project's conversation roster, once it exists, never drifts from the real Project Membership roster. `ConversationMemberController` rejects direct membership management on a `project`-type conversation with `409`.

**Messages:** `MessageController::store()` creates the message, immediately sets the sender's own `last_read_message_id` to it (they have obviously read their own message), and fans out Notifications — all inside one transaction. There is no update or delete route for a message anywhere in this API; immutability is enforced by the absence of a route, not a guard inside one. `body` is capped at 4,000 characters (a deliberate bound between a single line and Announcement's 10,000-character broadcast body) with a whitespace-only check added on top of Laravel's `required` rule (which alone would accept an all-space string).

**Read/unread state:** `conversation_members.last_read_message_id` is the sole read-position representation — no separate timestamp, since message ordering is already exact via the `messages` table's own auto-increment `id`. `ConversationMember::unreadCount()` derives the count live (`messages` where `id >` the reference, or all messages when null) — never a stored/cached column. `POST /conversations/{public_id}/read` sets the reference to the conversation's current latest message id; calling it again with nothing new is a harmless no-op.

**Notification integration:** `App\Http\Controllers\Api\V1\Messaging\Concerns\NotifiesConversationMembers` mirrors `NotifiesAnnouncementAudience`'s synchronous, transactional, no-queue shape exactly. It extends the closed `NotificationType` (new case: `MessageReceived`) and `NotificationSourceType` (new case: `Conversation`) enums by exactly one case each. Every sent message notifies every *other* current member with a linked User account (a defensive `whereNotNull('user_id')` filter, mirroring Announcement's identical skip-if-unlinked precedent); the sender is always excluded; and — per the governing instructions, unlike Announcement's structurally single-fire `publish()` guard — multiple messages are never collapsed into one notification. Content is fixed and generic regardless of conversation type (`"New message"` / `"You have a new message in one of your conversations."`), never the message body, sender name, or conversation/group/project name; the Notification's `source` points at the conversation, never the message (there is no individual message endpoint to navigate to).

**Authorization:** `AuthorizesConversationAccess` never calls `$user->can(...)` — no `messages.view`/`messages.manage` permission exists, and Administrator's centralized `Gate::before` override (DEC-028) is therefore never invoked for any Messaging endpoint, the second such case in this codebase after Notifications' ownership check. A conversation/message outside the requester's current membership is `404`, never `403`, for anyone, Administrator included.

**Relational integrity:** `StaffController::destroy()` gained three new checks (current conversation membership, group ownership, sent-message authorship — all `409`), mirroring every prior module's `restrictOnDelete()`-backed pattern exactly. `ProjectController::destroy()` gained one (a still-existing conversation) — necessary because a Project's own membership list can empty out (unblocking the pre-existing membership check) while its conversation, and the message history inside it, still exists.

**Real-time transport (resolving `02_ARCHITECTURE.md` §5/§9's long-open question):** none was adopted. Phase 16 is request/response only — no Laravel Reverb, WebSockets, Redis, queue workers, or managed vendor. `02_ARCHITECTURE.md` §1's architecture diagram, which had depicted a Redis/queue-worker layer as though already built, is corrected to show what actually exists (nothing beyond the Laravel app + MySQL) and to record this as a deliberate decision, not a stale aspiration.

## 5. Files Changed

**Added:**
- `apps/api/database/migrations/2026_09_12_160000_create_conversations_table.php`
- `apps/api/database/migrations/2026_09_12_160001_create_messages_table.php`
- `apps/api/database/migrations/2026_09_12_160002_create_conversation_members_table.php`
- `apps/api/app/Enums/ConversationType.php`
- `apps/api/app/Models/Conversation.php`
- `apps/api/app/Models/ConversationMember.php`
- `apps/api/app/Models/Message.php`
- `apps/api/app/Http/Requests/Messaging/StoreDirectConversationRequest.php`
- `apps/api/app/Http/Requests/Messaging/StoreGroupConversationRequest.php`
- `apps/api/app/Http/Requests/Messaging/StoreConversationMemberRequest.php`
- `apps/api/app/Http/Requests/Messaging/StoreMessageRequest.php`
- `apps/api/app/Http/Resources/ConversationResource.php`
- `apps/api/app/Http/Resources/ConversationMemberResource.php`
- `apps/api/app/Http/Resources/MessageResource.php`
- `apps/api/app/Http/Controllers/Api/V1/Messaging/Concerns/AuthorizesConversationAccess.php`
- `apps/api/app/Http/Controllers/Api/V1/Messaging/Concerns/NotifiesConversationMembers.php`
- `apps/api/app/Http/Controllers/Api/V1/Messaging/Concerns/SyncsProjectConversationMembership.php`
- `apps/api/app/Http/Controllers/Api/V1/Messaging/ConversationController.php`
- `apps/api/app/Http/Controllers/Api/V1/Messaging/ConversationMemberController.php`
- `apps/api/app/Http/Controllers/Api/V1/Messaging/MessageController.php`
- `apps/api/app/Http/Controllers/Api/V1/Messaging/ProjectConversationController.php`
- `apps/api/database/factories/ConversationFactory.php`
- `apps/api/database/factories/ConversationMemberFactory.php`
- `apps/api/database/factories/MessageFactory.php`
- `apps/api/tests/Feature/Api/V1/Messaging/DirectConversationTest.php`
- `apps/api/tests/Feature/Api/V1/Messaging/GroupConversationTest.php`
- `apps/api/tests/Feature/Api/V1/Messaging/ProjectConversationTest.php`
- `apps/api/tests/Feature/Api/V1/Messaging/MessageTest.php`
- `apps/api/tests/Feature/Api/V1/Messaging/ReadStateTest.php`
- `apps/api/tests/Feature/Api/V1/Messaging/MessagingNotificationFanoutTest.php`
- `apps/api/tests/Feature/Api/V1/Messaging/MessagingRelationalIntegrityTest.php`
- `apps/api/tests/Feature/Authorization/MessagingAuthorizationTest.php`
- `docs/phases/V1_PHASE_16_DEFINITION.md`
- `docs/handoffs/V1_PHASE_16_HANDOFF.md` (this file)

**Modified:**
- `apps/api/app/Enums/NotificationType.php` — added `MessageReceived`.
- `apps/api/app/Enums/NotificationSourceType.php` — added `Conversation`.
- `apps/api/app/Models/Staff.php` — added `conversationMemberships()`, `ownedConversations()`, `sentMessages()` relations.
- `apps/api/app/Models/Project.php` — added `conversation(): HasOne`.
- `apps/api/app/Http/Controllers/Api/V1/Staff/StaffController.php` — `destroy()` extended with three Messaging checks.
- `apps/api/app/Http/Controllers/Api/V1/Projects/ProjectController.php` — `destroy()` extended with a conversation-existence check.
- `apps/api/app/Http/Controllers/Api/V1/Projects/ProjectMembershipController.php` — `store()`/`destroy()` now call `SyncsProjectConversationMembership`.
- `apps/api/routes/api/v1.php` — new Messaging and project-conversation route groups.
- `docs/02_ARCHITECTURE.md`, `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/CHANGELOG.md`, `docs/CURRENT_STATE.md`, `docs/DECISIONS.md`, `docs/ROADMAP.md`.

## 6. Database/Schema Changes

Three new tables:

**`conversations`**
- `id`, `public_id` (ULID, unique)
- `type` (string — `ConversationType`)
- `name` (string, nullable — group only)
- `project_id` (FK `projects`, nullable, **unique**, `restrictOnDelete()`)
- `owner_staff_id` (FK `staff`, nullable, `restrictOnDelete()` — group only)
- `timestamps()`

**`messages`**
- `id`, `public_id` (ULID, unique)
- `conversation_id` (FK `conversations`, `cascadeOnDelete()`)
- `sender_staff_id` (FK `staff`, `restrictOnDelete()`)
- `body` (string, 4000)
- `timestamps()`
- Index: `(conversation_id, id)`

**`conversation_members`**
- `id`
- `conversation_id` (FK `conversations`, `cascadeOnDelete()`)
- `staff_id` (FK `staff`, `restrictOnDelete()`)
- `last_read_message_id` (FK `messages`, nullable, `nullOnDelete()`)
- `timestamps()`
- Unique: `(conversation_id, staff_id)`

No other table's schema changed. No new permission rows — `RolePermissionSeeder` output is unchanged (still 23 permissions, 3 roles).

## 7. API Changes

All new, all under `/api/v1`:

| Method | Path | Notes |
|---|---|---|
| GET | `/conversations` | Caller's own conversations, ordered by latest message activity |
| GET | `/conversations/{public_id}` | Membership-scoped |
| POST | `/conversations/direct` | Find-or-create; `200`/`201` |
| POST | `/conversations/group` | Creator becomes owner |
| POST | `/conversations/{public_id}/read` | Marks caller's own membership read to latest |
| POST | `/conversations/{public_id}/members` | Group-only, owner-only |
| DELETE | `/conversations/{public_id}/members/{staff_public_id}` | Group-only; owner-or-self, subject to the owner invariant |
| GET | `/conversations/{public_id}/messages` | Paginated, newest first |
| POST | `/conversations/{public_id}/messages` | Sends a message |
| POST | `/projects/{public_id}/conversation` | Lazy get-or-create; requires current Project Membership |

No `PUT`/`PATCH`/`DELETE` route exists for a message, or for a conversation itself, anywhere.

## 8. Authorization/Security Changes

- No new permission defined or attached (`messages.view`/`.manage` were considered and rejected, per the approved product-owner decisions).
- Membership/ownership-only authorization throughout; `AuthorizesConversationAccess` never calls `$user->can(...)`, so Administrator's `Gate::before` override never applies to Messaging.
- A non-member's request for a conversation/its messages is `404`, not `403`.
- Group membership management is owner-only, with an owner-cannot-be-left-ownerless-with-others-present invariant enforced as a single guard clause.
- Project conversation access requires actual current Project Membership — `projects.view` grants no bypass.
- `account.active` middleware (unchanged) is the only account-status gate; no Messaging-specific status framework was introduced.
- Notification content for messages is fixed and generic — never the message body, sender name, or conversation/group/project name.

## 9. Tests Added or Changed

67 new tests, 206 assertions, across eight files:
- `tests/Feature/Api/V1/Messaging/DirectConversationTest.php` (8 tests) — uniqueness in both participant orders, self-conversation rejection, fixed membership, non-participant 404, a no-linked-User target, nonexistent target validation.
- `tests/Feature/Api/V1/Messaging/GroupConversationTest.php` (13 tests) — ownership on creation, owner dedup, minimum-member validation, owner-only add/remove, self-leave, the owner invariant (blocked while others remain, permitted once alone), non-member visibility.
- `tests/Feature/Api/V1/Messaging/ProjectConversationTest.php` (10 tests) — no-conversation-until-first-use, lazy creation, idempotent re-fetch, non-member and Administrator-without-membership rejection, full-roster seeding, membership sync on add/remove (before and after conversation creation), rejection of direct membership management.
- `tests/Feature/Api/V1/Messaging/MessageTest.php` (13 tests) — send/list, validation (required, max length, whitespace-only, at-limit acceptance), non-member rejection, pagination, newest-first ordering, absence of edit/delete routes, resource shape.
- `tests/Feature/Api/V1/Messaging/ReadStateTest.php` (8 tests) — default unread, sender-only auto-read, mark-read, idempotency, post-read accrual, non-member rejection, list-level unread count.
- `tests/Feature/Api/V1/Messaging/MessagingNotificationFanoutTest.php` (7 tests) — every-other-member fan-out, sender exclusion, no cross-message dedup, no-linked-User skip, generic content (including group/project name non-leakage), correct source, visibility via the existing Notifications API.
- `tests/Feature/Api/V1/Messaging/MessagingRelationalIntegrityTest.php` (4 tests) — Staff deletion blocked by current membership, by (even emptied) group ownership, and by sent-message history; Project deletion blocked by an existing conversation.
- `tests/Feature/Authorization/MessagingAuthorizationTest.php` (6 tests) — unauthenticated rejection, suspended-account mid-session rejection, no-linked-Staff rejection, every role's access when linked, Administrator's lack of implicit access to a foreign conversation, 404-not-403.

Full suite: 631 tests, 1736 assertions, 0 failures (564 pre-existing + 67 new).

## 10. Commands/Checks Executed

```sh
cp .env.example .env && php artisan key:generate
COMPOSER_PROCESS_TIMEOUT=1800 composer install --no-interaction --prefer-dist --no-progress
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan migrate:fresh --force
php artisan migrate:fresh --force --seed
php artisan test
php artisan test --filter="Messaging"
```

## 11. Results

- `composer validate --strict` → `./composer.json is valid`
- `vendor/bin/pint --test` → `{"tool":"pint","result":"passed"}`
- `vendor/bin/phpstan analyse` (Larastan level 5) → `{"tool":"phpstan","result":"passed","errors":0}`
- `php artisan migrate:fresh --force` → all 34 migrations run cleanly, including the three new Phase 16 tables.
- `php artisan migrate:fresh --force --seed` → `RolePermissionSeeder` runs cleanly; **23 permissions, 3 roles** (unchanged from Phase 15 — confirms no new permission was introduced).
- `php artisan test` → `{"tool":"phpunit","result":"passed","tests":631,"passed":631,"assertions":1736}` — full regression, zero failures.
- Phase 16 tests isolated → `{"tool":"phpunit","result":"passed","tests":67,"passed":67,"assertions":206}`.

**Environment note (this session):** the container started with no `vendor/` at all. `composer install --no-interaction --prefer-dist --no-progress` (`COMPOSER_PROCESS_TIMEOUT=1800`) ran as a background command and completed normally (`EXIT_CODE=0`) — every dependency resolved via Composer's own local VCS mirror cache, no manual `vendor/` file surgery needed, no repeat of the `phpstan/phpstan`-mirror-clone-timeout issue documented in Phases 9–15. This does not affect the correctness of what was committed (`vendor/` is never committed either way).

## 12. Deviations from Specification

None from the approved product-owner decisions. Two implementation details worth flagging as resolved judgment calls, not deviations:
- Message `body` is capped at 4,000 characters — a specific figure the approved decisions asked to be "chosen and documented," not itself specified. Recorded in DEC-039 and the `messages` migration's own comment.
- `POST /conversations/direct` and `POST /projects/{public_id}/conversation` report `200` vs `201` via Laravel's own `wasRecentlyCreated`-driven `JsonResource` status calculation, discovered during testing rather than hand-coded — no explicit status-code branch was needed in either controller.

## 13. Known Issues/Limitations

- No Flutter mobile screens exist for Messaging, despite `06_UI_UX_GUIDELINES.md` placing "Messages" as a primary navigation tab — a deliberate product-owner scope decision for this phase, not an oversight.
- No real-time delivery: a client must poll for new messages/unread counts. This resolves `02_ARCHITECTURE.md`'s long-open real-time-transport question in favor of deferral, not indecision — see DEC-039.
- No message editing, deletion, attachments, search, archive/mute, or Admin moderation/read-all capability of any kind — all deliberately out of scope per the approved decisions.
- Every sent message fans out a Notification per other member with no cross-message deduplication (as explicitly instructed) — a very active group conversation will generate a correspondingly large number of Notification rows; no volume mitigation was requested or built.
- Audit Logging (DEC-009) remains an unbuilt, pre-existing, project-wide gap, predating and independent of this phase — Messaging does not attempt a one-off version of it.

## 14. Manual/UAT Testing Instructions

1. As Staff member A (Sanctum bearer token, linked to a Staff record), `POST /api/v1/conversations/direct` with `staff_id` = Staff member B's `public_id` — confirm `201` and a `direct` conversation with exactly two members.
2. Repeat the same call — confirm `200` and the identical `public_id` (no duplicate).
3. As A, `POST /api/v1/conversations/{public_id}/messages` with a `body` — confirm `201`, and that `GET /api/v1/conversations/{public_id}` for A shows `unread_count: 0`.
4. As B, `GET /api/v1/conversations/{public_id}` — confirm `unread_count: 1`; `GET /api/v1/me/notifications` — confirm one `message_received` notification with generic text and a `source` pointing at the conversation.
5. As B, `POST /api/v1/conversations/{public_id}/read` — confirm the response and a follow-up `GET` both report `unread_count: 0`.
6. As A, `POST /api/v1/conversations/group` with a `name` and `member_staff_ids` including B and a third Staff member C — confirm A is the `owner` and all three appear in `members`.
7. As B (not the owner), attempt `POST .../members` to add a fourth member — confirm `403`.
8. As A (the owner), attempt to remove themselves while B/C remain — confirm `409`; remove B and C individually, then remove themselves — confirm the final removal succeeds (`204`).
9. Create a Project with A and B as members (Administrator), then as A call `POST /api/v1/projects/{public_id}/conversation` — confirm `201` and a `project`-type conversation with both members; call again — confirm `200`, same conversation.
10. As Administrator, add C to the Project's membership — confirm C now appears in the project conversation via `GET /api/v1/conversations/{public_id}`; remove B — confirm B no longer sees the conversation (`404`).
11. As an unrelated Staff member (or even an Administrator with no membership), attempt to `GET` any of the above conversations or their messages — confirm `404` in every case.
12. Attempt `PUT`/`DELETE` on a message's URL — confirm no such route exists (`404`/`405`).

This is **Implemented** and **Tested automatically** (see §9/§11). Not yet **Manually verified** by a human, and **Awaiting UAT** — no `UAT_LOG.md` entry has been recorded, and none will be marked `PASS` except by the product owner.

## 15. Documentation Updated

`docs/CURRENT_STATE.md`, `docs/CHANGELOG.md`, `docs/ROADMAP.md`, `docs/DECISIONS.md` (DEC-039), `docs/02_ARCHITECTURE.md` (including the corrected §1 architecture diagram and resolved §5/§9 real-time-transport question), `docs/03_DATABASE_MODEL.md`, `docs/04_API_CONVENTIONS.md`, `docs/05_SECURITY_MODEL.md`, `docs/phases/V1_PHASE_16_DEFINITION.md`, and this handoff.

## 16. Recommended Next Step

Phase 17 — Scheduler (per `docs/ROADMAP.md`). **Not authorized to begin** — awaiting explicit product-owner authorization, per CLAUDE.md §8's Stop Discipline.
