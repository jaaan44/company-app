# Phase 32 — Communication: Announcements, Notifications & Messages (Mobile) — Handoff

**Status: implementation complete (Gates 1–4); the PR is merged on green CI under DEC-059. NOT formally closed:** it waits for the combined staging deployment and UAT round (30B + 31–33).

## 1. Phase Identification

- **Phase:** 32 — Communication: Announcements, Notifications & Messages (Mobile).
- **Date:** 2026-10-12
- **Specification:** `docs/phases/V1_PHASE_32_DEFINITION.md` revision 1. Decisions R-1…R-12 were approved under DEC-059.
- **Branch:** `claude/amazing-brahmagupta-dbsrjc`, restarted from `main` at `35fd7a1` (Phase 31 merged).
- **Gates:**
  - Gate 1: `44d501f`;
  - Gate 2: `40b845b`;
  - Gate 3: `f8ed6b4`;
  - Gate 4: the final commit.

## 2. Objective

Replace the Messages placeholder with real conversations, and give staff the announcements feed and the notifications inbox on the phone.

## 3. Scope Implemented

R-1…R-12 as specified. A small 06-level deviation is noted in §12.

## 4. Implementation Summary

- **Backend:**
  - tie-breakers and `meta.company_day` on three lists, and the company day on messages;
  - `last_message` on conversations;
  - reading a chat reads its message notifications.
- **Mobile:**
  - three feature folders, `messages`, `notifications` and `announcements` (models, clients, controllers, pages);
  - `InboxChanges`;
  - routes in the Messages branch, plus inbox routes under both `/home` and `/more`;
  - Home's tiles and announcements wired; two More rows;
  - the last placeholder page removed.

## 5. Files Changed

- **Backend:**
  - `app/Http/Controllers/Api/V1/{Messaging/ConversationController, Messaging/MessageController, Messaging/ProjectConversationController, Notifications/NotificationController, Announcements/MyAnnouncementController}.php`;
  - `app/Http/Resources/ConversationResource.php`;
  - `tests/Feature/Api/V1/Messaging/CommunicationMobileTest.php` (new).
- **Mobile:**
  - `lib/features/{messages,notifications,announcements}/**` (new);
  - `lib/app/{app,router}.dart`;
  - `lib/core/state/paged_search_controller.dart`;
  - `lib/features/home/presentation/home_page.dart`;
  - `lib/features/people/presentation/more_page.dart`;
  - `lib/features/shell/presentation/placeholder_page.dart` (deleted).
- **Mobile tests:**
  - `test/features/inbox/{inbox_state_test, inbox_pages_test}.dart` and `test/support/inbox_fixtures.dart` (new);
  - `test/app/router_test.dart`, `test/features/home/home_page_test.dart` and `test/features/people/people_pages_test.dart` (updated).
- **Docs:**
  - the spec and DEC-061;
  - 02 §41, 04, 05 and 06;
  - CURRENT_STATE, CHANGELOG, ROADMAP, TEST_STATUS, UAT_LOG, and this handoff.

## 6. Database/Schema Changes

None.

## 7. API Changes

Additive or corrective (see `04_API_CONVENTIONS.md`, Phase 32):
- tie-breakers;
- `meta.company_day`;
- `last_message`;
- the read side effect, with `notifications_marked_read` in its response.

## 8. Authorization/Security Changes

None to visibility (DEC-039 unchanged). The read side effect touches only the caller's own notifications, and only after the membership check. Polling happens only in the foreground. No push tokens exist.

## 9. Tests Added or Changed

- **Backend:** 8 new tests; 9 mutations, all caught.
- **Mobile:** 47 new tests (26 state, 21 pages); 8 mutations, all caught.
- **Updated:**
  - the router test: Messages is now the real tab;
  - the Home tests: the R-8 links replace the "navigates nowhere" checks;
  - the More tests: nine rows, plus a scroll in the 200% resilience test.

## 10. Commands/Checks Executed

The CLAUDE.md §5 commands for both apps, mutation runs, and the contract parity.

## 11. Results (Gate 4 final run)

- **Backend:** composer validate and audit clean; Pint and PHPStan clean; **1,248/1,248**.
- **Flutter:** format and analyze clean; **704/704**.
- **Contract parity:** 17 real responses parsed by the production code, 2/2.
  - They covered conversations (group before direct by latest message), messages, a sent message, a direct chat, notifications before and after reading a chat (one read), mark one, read-all, announcements with a multi-line non-ASCII body, acknowledge, the self-chat `422`, the profile, and the no-profile `403` (conversations) next to a `200` (notifications).
  - The scratch tests were deleted.
- **Found during Gates 2–3 and fixed before commit:**
  - a chat opened from a deep link always sent a read call;
  - catch-up pages were appended out of order;
  - "Mark all read" overflowed the app bar at 200% text.

## 12. Deviations from Specification

- R-6's "Mark all read in the app bar" is an **icon button** with that tooltip, not a text button, because the text overflowed at 200% text.

## 13. Known Issues/Limitations

- **No push:** new messages show only while a chat is open (15 s), and in lists on refresh or return. Home's counts update on load, pull or any change made in the app.
- **`unread_count`** is still one count query per conversation row (Phase 16). That is fine at 20 a page, but not constant.
- **`message_received` notifications** still arrive one per message. They are now read when the chat is read.
- **An announcement opened from a restored deep link** (no list) shows times "(device time)", as Phase 30A's entry detail does.
- **No device run.** Out of scope: R-10.

## 14. Manual/UAT Testing Instructions

UAT-32-01…08 (`NOT RUN`) run in the combined round. They need:
- two staff accounts on two phones (or one phone and the Backoffice), so a message arrives while a chat is open;
- a published announcement;
- a long chat of more than 30 messages;
- a user without a profile.

## 15. Documentation Updated

See §5.

## 16. Recommended Next Step

Phase 33, under DEC-059.

## Addendum — UAT and Formal Closure (2026-10-13)

- **Deployed:** staging `d6dd29a` and the APK built at `d6dd29a` (combined round, `docs/testing/COMBINED_UAT_PREPARATION.md`; preparation results in `docs/testing/TEST_STATUS.md`).
- **UAT-32-01…08: PASS**, reported by the product owner after running each scenario on a physical Android 12+ device against staging. Recorded by the AI on that report.
- **Phase 32 is formally closed.** UAT data stays on staging; any cleanup needs its own authorization.

## Addendum — UAT withdrawn as not verified (2026-10-16)

- **What was found:** on 2026-10-13 the 34C deploy found none of the combined round's 31 or 32 test data on staging, and the round couldn't be reconciled with the server.
- **The decision:** the product owner withdrew **UAT-32-01…08** as not verified ("i choose C"). The rows are `NOT RUN` again, with the original record kept in their notes.
- **The phase stays closed.** Its code is merged and its automated checks passed.
- **Not covered:** the phone-app screens are untested by the product owner until the Phase 38 UAT. The server side has since passed UAT through the web portal (34B–34E).

