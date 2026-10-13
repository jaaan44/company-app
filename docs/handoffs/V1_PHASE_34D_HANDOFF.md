# Phase 34D — Staff Web Portal: Communication — Handoff

**Status: implemented (2026-10-13), awaiting merge, staging deployment and UAT.** UAT-34D-01…06 are `NOT RUN`. 34E is not authorized.

## 1. Phase Identification

- **Phase:** 34D — Staff Web Portal, Communication (the fourth sub-phase of Phase 34, DEC-063).
- **Date:** 2026-10-13
- **Authorization:** "proceed with 34D" (product owner).
- **Specification:** `docs/phases/V1_PHASE_34_DEFINITION.md` §5 and §14.
- **Branch:** `claude/amazing-brahmagupta-dbsrjc`, from `main` at `c37137c` (34C closed).

## 2. Objective

Bring the mobile app's Messages, Notifications and Announcements (Phase 32) to the browser.

## 3. Scope Implemented

Everything listed for 34D in §5 and §14 of the specification, including Home's links (R-8). Nothing from 34E, and nothing in Phase 32's R-10 list.

## 4. Implementation Summary

**New Livewire components** in `App\Livewire\Portal`:
- `Messages`, `Chat`, `NewMessage`;
- `Notifications`;
- `Announcements`, `AnnouncementShow`.

Each calls only `/api/v1`, through `InternalApi`, with the mobile app's page sizes (20 for lists, 30 for messages).

**Key behavior:**
- **My staff id** comes from `/me/profile`, as on mobile: it tells my messages from others' and names a direct chat after the other person. Without one, Messages and New message show the no-profile state.
- **A conversation:**
  - one request reads the newest `30 × pages` messages (at most 20 pages), shown oldest first with day separators;
  - **Load earlier messages** adds a page;
  - `wire:poll.15s` re-reads while the page is open. When the newest message differs from the last one marked read, the page calls `POST …/read`, which also reads my message notifications for that chat (Phase 32 R-2d).
  - **Send** trims the text, ignores blank text, and caps it at 4,000 characters; it clears the field only after the API accepts the message.
- **A notification** is opened by its own id: the page reads it from `GET /me/notifications/{id}` (so the source comes from the API, never the browser), marks it read if unread (a failure is quiet), then redirects to `/app/announcements/{id}` or `/app/messages/{id}`. An unknown source only marks it read.
- **Acknowledge** re-reads the announcement afterwards, so "Acknowledged on …" is the API's time.
- **`Format`** gains `inboxTime`, `chatDay`, `dateTime`, `conversationTitle`, `showsSenderNames` and `lastMessagePreview`, the same as the mobile helpers.
- **Routes:** six under `/app`: `messages`, `messages/new`, `messages/{id}`, `notifications`, `announcements`, `announcements/{id}`.
- **Navigation:** Messages, Notifications and Announcements, after My status.

## 5. Files Changed

**New:**
- the 6 components and 6 Livewire views;
- `tests/Feature/Portal/CommunicationPagesTest.php`;
- this handoff.

**Changed:**
- `app/Support/Portal/Format.php`;
- `routes/web.php`;
- `components/layouts/portal.blade.php`, `livewire/portal/home.blade.php`;
- `tests/Unit/Support/Portal/FormatTest.php`;
- docs: 02, 05, 06; DECISIONS (DEC-063 status line only); the Phase 34 definition; ROADMAP; CURRENT_STATE; CHANGELOG; TEST_STATUS; UAT_LOG.

## 6. Database/Schema Changes

None.

## 7. API Changes

None.

## 8. Authorization/Security Changes

None to the rules. The portal now also sends messages, marks chats and notifications read, opens direct chats and acknowledges announcements, through the same endpoints as the mobile app (see `05_SECURITY_MODEL.md`). Only members can open a conversation; the API answers `404` to anyone else.

## 9. Tests Added or Changed

- **`CommunicationPagesTest`** (18 tests):
  - guests are sent to login, and all four roles open every page; the navigation order;
  - the inbox: order, names (group, direct, project), previews ("You:", "Grace:", "No messages yet"), times, unread counts, and other people's chats excluded; empty and no profile;
  - a chat: oldest first, day separators, sender names, the poll attribute, and marking read (my membership and my message notification);
  - Send confirmed and blank text ignored (no API call); a failed send keeps the text; Load earlier messages; someone else's and an unknown chat;
  - New message: myself excluded, search, the direct chat opened, and found again rather than duplicated;
  - Notifications: unread first in bold, times, opening marks read and redirects to a chat or an announcement; someone else's notification refused; Mark all read; works without a profile; empty;
  - Announcements: order, dates, New, drafts excluded; the detail ("Published by · date time"), Acknowledge persisted; unknown and no profile;
  - Home's links; an ended session goes to login.
- **`FormatTest`**: 2 more unit tests, 13 in all.

## 10. Commands/Checks Executed

- In `apps/api`: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test`, and `npm run build`.
- Mutation checks.
- Playwright with Chromium and axe-core 4.10.2, against `php artisan serve` on a scratch SQLite database seeded with two signed-in users (a staff member and her manager), chats, notifications and announcements.

## 11. Results

- **Backend:** 1,354/1,354 tests pass; Pint and PHPStan are clean.
- **Browser:** 49/49 checks pass, in light and dark:
  - Home's tiles and links;
  - a notification opening its chat;
  - the inbox;
  - a chat: Send, and the field cleared;
  - **live freshness:** a reply sent from a second browser signed in as the manager appeared on the staff member's open chat **within the 15 s poll (14 s in both runs)**;
  - New message (myself excluded, search in the address, the direct chat opened);
  - announcements and Acknowledge; Mark all read;
  - no horizontal scroll at 320 px on 5 pages; no JavaScript errors;
  - **axe WCAG 2.x AA: 0 violations** on 7 page states × 2 schemes.
- **Mutations:** 7 run. 6 were caught at first. The survivor (blank text being sent) exposed a missing assertion; with it, the mutation is caught.
- **Found during testing:**
  - The API answers `404`, not `403`, to someone opening another person's conversation, so the page says "This conversation is no longer available." That reveals less than an access message.
  - The inbox is ordered by the newest message's id, so test data must be created in time order.
- **Not run here:** MySQL, the staging deployment, and UAT.

## 12. Deviations from Specification

None. Two notes:
- The browser slows `wire:poll` down in a background tab. The mobile app polls only in the foreground, so this matches its intent.
- Unread counts and Mark all read refresh when a page is next opened or reloaded, as on mobile (R-11). There is no badge in the navigation, as R-10 also leaves out for mobile.

## 13. Known Issues/Limitations

- **A chat page shows at most 600 messages** (20 pages of 30); older ones remain on the phone.
- **Every 15 s poll re-reads the conversation and its loaded messages**, which is two small requests per open chat.

## 14. Manual/UAT Testing Instructions

A runbook will be written after merge: `docs/testing/PHASE_34D_UAT_PREPARATION.md`, covering the deploy, any data the scenarios need (reusing the UAT30B accounts, no passwords) and UAT-34D-01…06.

## 15. Documentation Updated

See §5.

## 16. Recommended Next Step

1. Merge on green CI.
2. Deploy to staging and run UAT-34D.
3. Then 34E (Reports) needs its own authorization.
