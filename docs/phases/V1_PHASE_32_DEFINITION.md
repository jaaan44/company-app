# Phase 32 — Communication: Announcements, Notifications & Messages (Mobile) — Specification

**Status:** Revision 1 (2026-10-12); **implemented, Gates 1–4** (handoff `docs/handoffs/V1_PHASE_32_HANDOFF.md`, DEC-061), awaiting the combined UAT round. **Decisions R-1…R-12 approved under the product owner's blanket authorization (DEC-059).** Implementation is authorized through Gate 4; merged on green CI; UAT in the combined round.

**Depends on:**
- Phase 14 (Announcements, DEC-037), Phase 15 (Notifications, DEC-038) and Phase 16 (Messaging, DEC-039).
- Phases 27–31 (the `ApiClient` 401/403 rule, the paged lists, confirmed writes, the no-profile `403` pattern, the company day DEC-052, and the `*Changes` notifiers).

## 1. Objective

Replace the Messages placeholder with real conversations, and give staff the announcements feed and their notifications inbox on the phone.

## 2. Current-State Findings (read-only discovery on `main` with Phase 31)

| Endpoint | Behaviour today |
|---|---|
| `GET /me/announcements` | Published announcements in my audience; `published_at DESC` only (**no tie-breaker**); `acknowledged_at` for me; no company day. No profile: `403`. |
| `GET /me/announcements/{id}`, `POST …/acknowledge` | Outside my audience: `404`. Acknowledge is idempotent. |
| `GET /me/notifications?unread=&type=` | Mine (by user, **no profile needed**); `created_at DESC` only (**no tie-breaker**). Types: `announcement_published`, `message_received`; `source {type: announcement|conversation, public_id}`. |
| `GET /me/notifications/unread-count`, `POST …/read-all`, `POST …/{id}/read` | Idempotent. |
| `GET /conversations` | My conversations (direct, group, project) by latest message id (**empty ones tie**); each with members and my `unread_count` (one count query per row); **no last message**. No profile: `403`. Not a member: `404`. |
| `GET /conversations/{id}/messages` | `id DESC`, paged. |
| `POST /conversations/{id}/messages` | `body` 1–4000; marks it read for me; creates one `message_received` notification **per message** for every other member. |
| `POST /conversations/{id}/read` | Marks the conversation read for me. **Leaves my message notifications for it unread**, so the inbox fills with "New message" items already seen. |
| `POST /conversations/direct` | Find-or-create with `staff_id` (not myself: `422`). |

**Mobile:** the Messages tab is a placeholder. Home shows the unread message and notification counts (not tappable) and up to three latest announcements (not tappable).

## 3. Product Decisions (R-1…R-12, approved under DEC-059)

| # | Decision | Why |
|---|---|---|
| **R-1** | **Scope:** the Messages tab (my conversations, a conversation, and New message to a colleague); the Notifications inbox; the Announcements feed with detail and Acknowledge. | The three communication surfaces staff use daily. |
| **R-2** | **Backend, small and additive:** (a) `id` tie-breakers on `/me/announcements`, `/me/notifications` and `/conversations`; (b) `meta.company_day` on those three and on `/conversations/{id}/messages`; (c) `last_message {public_id, body, sender {public_id, name}, created_at}` (or `null`) on every conversation, eager-loaded; (d) `POST /conversations/{id}/read` also marks **my** unread `message_received` notifications for that conversation read, and answers `{unread_count: 0, notifications_marked_read: N}`. | Stable paging, company time, a usable inbox list, and an honest notification count once a chat is read. |
| **R-3** | **Messages tab:** my conversations, newest activity first. Each row shows the name (the group name, the project name, or the other person for a direct chat), the last message ("You: …" for mine, "Grace: …" in a group), its time in company time, and an unread count badge. Empty: "No conversations yet." **New message** opens a colleague picker (the directory search, without myself), then find-or-creates the direct chat and opens it. | The inbox people expect. |
| **R-4** | **A conversation:** messages oldest to newest, the newest at the bottom; older ones load when scrolling up (30 a page). Mine are on the right; others' show the sender's name in group and project chats. Times are in company time, with day separators (Today, Yesterday, "Mon 12 Oct"). A composer (1–4000, trimmed). **Send** sends once: on success the message appears; on failure the text stays with "Couldn't send. Try again." The screen marks the chat read on open and when new messages arrive while visible. | Confirmed sends, as everywhere in the app. |
| **R-5** | **Freshness without push:** while a conversation is on screen, it checks for new messages every **15 s** (one small request, only while the app is in the foreground). The conversations list refreshes on pull, on returning from a chat, and after any confirmed change. No push notifications, websockets or background work. | Close to live chat with no new infrastructure or dependency. |
| **R-6** | **Notifications inbox:** Home's Notifications tile and More → **Notifications**. Newest first, 20 a page. Unread ones are bold with a dot (also announced as "Unread"); each shows title, message and time. Tapping marks it read, then opens its source: an announcement's detail, or the conversation in the Messages tab. An unknown source only marks it read. **Mark all read** sits in the app bar. Nothing is deleted. | One place for what's new; the sources stay authoritative. |
| **R-7** | **Announcements:** More → **Announcements**, newest first, 20 a page. Each row shows the title, the published date, and "New" until acknowledged. The detail shows the title, published by and when, and the full body as selectable plain text. **Acknowledge** shows while unacknowledged, then "Acknowledged on …" (confirmed). Home's latest announcements open the detail. | Read and acknowledge as the API intends. |
| **R-8** | **Home:** the Messages tile goes to the Messages tab; the Notifications tile opens the inbox; announcement rows open their detail, plus **All announcements**. The counts refresh after any change here (R-11). | Home's counts become actionable. |
| **R-9** | **No profile:** announcements and messages keep the `403`, shown as "No staff profile is linked to this account." Notifications work without a profile (they belong to the user account). | No contract change. |
| **R-10** | **Not in Phase 32:** push notifications, attachments in messages, editing or deleting messages, creating groups or managing members, starting a project chat, announcement management, read receipts and typing indicators. | Scope; most need new API or infrastructure. |
| **R-11** | **An `InboxChanges` notifier** records confirmed changes (a message sent, a chat read, a notification read, all read, an announcement acknowledged). Home, the Messages list, Notifications and Announcements reload when next shown. | The `TaskChanges` pattern. |
| **R-12** | **No new permission, migration or dependency.** | — |

## 4. Gates

1. **Backend:** R-2 (a–d), with tests: the SQL-pinned tie-breakers; `company_day`; `last_message`, including an empty chat (`null`), shape and no N+1 growth for `last_message`; read marking only my notifications for that conversation, other conversations' and other people's untouched, and idempotent.
2. **Mobile data and state:** `lib/features/messages/`, `lib/features/notifications/`, `lib/features/announcements/`: strict models, API clients, controllers (the conversations list, a conversation with paging, polling and sending, new message, inbox, announcements and detail), and `InboxChanges`.
3. **Mobile UI:** the Messages tab, the conversation screen, New message, Notifications, Announcements and detail, Home's links, and More's two rows.
4. **Integration review and PR:** full gates, contract parity, docs, DEC entry, UAT rows, handoff, PR, and merge on green CI.

## 5. UAT scenarios (all `NOT RUN`; run in the combined round)

| ID | Scenario |
|---|---|
| UAT-32-01 | Messages lists my conversations newest first with the last message, time and unread counts; a direct chat is named after the other person. |
| UAT-32-02 | New message to a colleague opens (or reuses) our chat; send a message; it appears on the right; the colleague (second phone or account) sees it within about 15 s while the chat is open, and their unread count and notification appear. |
| UAT-32-03 | Opening a chat marks it read: its unread badge clears, and its "New message" notifications in the inbox show as read; Home's counts drop accordingly. |
| UAT-32-04 | A long chat loads older messages when scrolling up, without repeats; offline send keeps the text with "Couldn't send. Try again."; sending again succeeds once. |
| UAT-32-05 | Notifications: newest first, unread bold; tapping an announcement notification opens the announcement; a message notification opens the chat; Mark all read clears the count. |
| UAT-32-06 | Announcements: the feed shows "New" on unacknowledged items; the detail shows the full text; Acknowledge shows "Acknowledged on …" and the "New" mark goes; Home's latest announcements open the detail. |
| UAT-32-07 | A user without a profile: Messages and Announcements show the no-profile message, and Notifications still work; after token revocation the app returns to Login. |
| UAT-32-08 | Dark mode and large text on Messages, a chat, Notifications and an announcement. |

## 6. Out of Scope

Everything in R-10, plus a Messages tab badge, sound and vibration, search in messages, and Administrator views of others' messages (never, DEC-039).

## Notes

- **Revision 1 (2026-10-12):** discovery on `main` after Phase 31; R-1…R-12 recorded as approved under DEC-059.
- **Gate 4 (2026-10-12):** Mark all read became an icon button with a tooltip (the text button overflowed the app bar at 200% text on 320 px). Contract parity confirmed the real self-chat refusal is a `422` on `staff_id`.
