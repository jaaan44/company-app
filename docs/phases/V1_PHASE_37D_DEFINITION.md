# Phase 37D — Staff Portal Visual Refresh (proposal)

**Status: PROPOSED — awaiting the product owner's approval of this definition. No code has been changed.**
**Authorization:** the product owner, 2026-10-24: "Authorize Phase 37D, web portal only, all screens should be moved to this style", with the reference `docs/design/portal-style-reference.webp`.
**Branch:** `claude/admiring-planck-hictyu`, from `main` at `ab24aac`.

## 1. How this phase came about

Phase 37C (DEC-069) rebuilt only Home after `docs/design/mobile-home-reference.png`. It kept the app-wide indigo palette, because changing the brand everywhere needed its own approval (DEC-069 §1). The product owner has now given that approval with a second reference: a Home dashboard and an "All Employees" screen, with messages and announcements. Every staff-portal screen should move to that style.

Your global UI rules call a change across every screen a major redesign. This document is the proposal to approve before implementation starts.

## 2. What the reference asks for (the style, not the sample data)

- **Palette:** a light grey-blue page (`#F4F6FA`) with white cards, one royal-blue accent (`#2563EB`), dark navy text and slate secondary text.
- **Colours for kinds of thing:** green, amber, blue, red and violet, used for status dots and for tinted round-square icon tiles. A dot always has its word beside it.
- **Cards:** large radius (about 16–20 px), almost no borders, one soft shadow, and generous padding.
- **Hierarchy:**
  - a bold section title with a blue "View all" link on the right;
  - rows built as avatar or icon tile, then a bold title over a grey subtitle, then a time or pill on the right;
  - counts as red badges.
- **Shell:**
  - a large bold title in the app bar, a back chevron, and search or filter icons on the right;
  - the bottom tab bar from 37C, with the current tab as a soft blue pill;
  - Home only: a photo or gradient hero with the greeting.
- **People:** circular avatars with a presence dot, then the name, the role, and the status as a dot plus its word.

## 3. Discovery findings

### 3.1 The architecture makes this mostly a token and component change

- All colours come from tokens in `apps/api/resources/css/app.css`. Changing the token values recolours every screen, in light and dark.
- Buttons, fields and chips are shared utilities and components (`btn-*`, `field`, `x-portal.chip`, `empty`, `notice` and others).
- **What tokens can't change:** about 40 Livewire views write their own container markup (`rounded-xl border border-line bg-raised`, `divide-y` lists, `px-5 py-3` rows). Moving those to the new card and row style means editing each view.

### 3.2 Screens in scope (staff portal, `/app/*`)

| Group | Screens |
|---|---|
| Shell | Layout (app bar, sidebar, bottom bar), More, toast, dialogs, error pages, sign-in and password pages (guest layout) |
| Home | Home |
| Work | Tasks, Task, Task form, Work logs, Work log form, Projects, Project, Project form, Clients, Client, Client form, Contact form |
| Schedule | Schedule, Schedule entry |
| Communication | Messages, Chat, New message, Notifications, Announcements, Announcement, Manage announcements (list, item, form) |
| People and HR | Staff directory, Staff member, My profile, Change password, My status, Status history, Leave, Leave request, Leave form, Team leave |
| Reports | Reports, Report (service and incident), Service report form, Incident form |

**Out of scope:**
- the Admin Backoffice (`/admin`, its own layout);
- the Flutter app (paused, DEC-067);
- any API, migration or permission change.

### 3.3 Which parts of the reference the existing data supports

| Reference element | Data today | Proposal |
|---|---|---|
| Presence status per person (Working, In Meeting…) | **Yes.** `GET /staff` already returns `operational_status`: `available`, `busy`, `in_meeting`, `in_field` or `off_duty`. | Show a dot plus its word on directory rows and the staff page. Use the app's existing words for each status. |
| Status filter chips (All / Working / WFH / On leave) | **No.** `/staff` filters by team, department and position, not by operational status. "WFH" and "On leave" aren't statuses in the app. | Not built. Filtering by status needs an API change (open question Q3). |
| Profile photos | **No.** There's no photo field. | Initials avatars, with a background colour derived from the name. |
| "Working on *project*" per person, project progress % | **No.** | Not built. Project rows show status, client and dates instead. |
| Person's location ("Main Office", "Conf. Room A") | Only check-in locations, which are permission-gated and sensitive. | Not shown on lists. |
| My Team on Home | **Yes.** `GET /staff?team=<my team>&status=active` includes `operational_status`. | Optional (Q2). It adds one API call to Home. |
| Quick actions (New task, Message, Apply leave, Attendance, More) | Every one maps to a real route: tasks.new (permission-gated), messages.new, leave.new, status, more. | Optional (Q2). These are links only, with no new behaviour. |
| My Work counts (due today / upcoming / overdue) | Partly. `/me/home` gives open, overdue and due-today counts. | The existing Needs attention card, restyled as the reference's stat chips. |
| Today's schedule | **Yes** (`/me/home` today). | The existing Today card, restyled as a time-and-dot timeline. |
| Message previews and unread bubbles | **Yes** (conversations list). | Restyle Messages as avatar rows with an unread bubble. |
| Announcement icons and pinned items | One kind only; check whether `pinned` exists during implementation. | One megaphone tile; a pin only if the field exists. |
| Hero photo | No image asset. | Keep 37C's gradient, or use a photo you supply (Q4). |
| "A great team builds great things" banner, emoji 👋 | Decorative. | Not built. Emoji aren't used as icons. |
| One-tap circular checkbox to complete a task | It would change behaviour, because status changes live on the task page. | Not built. A status icon in that place doesn't act as a checkbox. |

## 4. Proposed design system (replaces DEC-064's indigo palette for the portal)

### 4.1 Colour tokens

Same token names, so every screen follows. Contrast is computed (WCAG 2.2).

| Token | Light | Dark | Check |
|---|---|---|---|
| `surface` (page) | `#F4F6FA` | `#0E1525` | — |
| `raised` (cards, bars) | `#FFFFFF` | `#172033` | — |
| `ink` | `#0F172A` | `#E5E9F2` | 16.5:1 / 15.0:1 |
| `muted` (secondary text) | `#475569` | `#A3ADBF` | 7.0:1 on surface / 7.2:1 on raised |
| `line` (dividers) | `#E2E8F0` | `#273248` | dividers only |
| `field` (control border) | `#7B8496` | `#8C97AB` | ≥ 3:1 (WCAG 1.4.11) |
| `accent` | `#2563EB` | `#93B4FF` | white on accent 5.2:1; on-accent dark 6.6:1 |
| `accent-ink` (links, "View all") | `#1D4ED8` | `#93B4FF` | 6.2:1 on surface / 7.9:1 |
| `accent-soft` (selected pill) | `#DBEAFE` | `#1E3A8A` | accent-ink on it 5.5:1 |

- **Why not the reference's `#64748B` grey:** it measures only 4.4:1 on the grey page, below AA for small text. `#475569` keeps the look and passes.
- `danger`, `success` and `warning` keep their roles, retuned to the new hue family. Each stays ≥ 4.5:1 for text.
- 37C's Home tokens (`hero-*`, `tint-*`, `icon-*`) become general **kind colours** for any screen: blue, green, amber, rose and violet.

### 4.2 Status colours (dot plus word, never colour alone)

| Status | Colour |
|---|---|
| `available` | green |
| `in_meeting` | amber |
| `busy` | rose |
| `in_field` | violet |
| `off_duty` | grey |

These come from one mapping in `App\Support\Portal\Format`, as 34B did for chips.

### 4.3 Shape, depth and type

- **Three shapes, updated from 37B:**
  - controls `rounded-lg`;
  - containers (cards, lists, notices) `rounded-2xl`, with no border in light mode, a hairline in dark, and `shadow-card`;
  - actions `rounded-full` (unchanged).
- **Icon tiles:** a 40 px rounded square in a kind tint, holding the existing inline SVG icons.
- **Avatars:** 40 px circles (48 px on the staff page), with an optional presence dot ringed in the card colour.
- **Type:**
  - the system font stack stays (DEC-063: no web fonts, so no third-party request). On iPhones that's SF Pro, the reference's face;
  - scale: page title 22/700, section title 18/600, row title 15–16/600, meta 13–14/400. Nothing below 12 px; body text stays 16 px in forms.
- **Spacing:** 16 px gutters on phones, 12 px between cards, 24 px between sections.

### 4.4 Shell

- **App bar:**
  - on the surface colour with no bottom border; it gets its shadow once the page scrolls;
  - a larger bold title;
  - the back chevron as now, with page actions (search, filter, "+") on the right where a page has them;
  - Home keeps its hero (37C).
- **Sidebar (from `md`):** the same items, with the current one as a soft blue pill (as now, recoloured) and a little more spacing.
- **Bottom bar:** 37C's design, recoloured.
- **FAB, toast, dialogs:** recoloured and on the new radius scale.

## 5. Component work (`resources/views/components/portal/`)

- **New:**
  - `avatar` (initials, a colour from the name, an optional status dot);
  - `icon-tile`;
  - `status-dot` (dot plus its word);
  - `list-card` (rounded card holding rows with dividers);
  - `row` (leading slot, title and subtitle, trailing slot, optional link with chevron);
  - `stat-chip` (tinted count, as in "3 due today").
- **Updated:** `section-header` (now used on every screen with sections), `summary-card`, `chip`, `empty`, `notice`, `error`, `fab`, `pager`, `search`, `info-row`, `leave-row` and `announcement-row` (moved onto `row`).

## 6. Screen-by-screen

- **Home:**
  - the hero as now;
  - quick actions row (if Q2 is approved);
  - Today as a schedule timeline;
  - My Work: stat chips plus Needs attention (Messages and Notifications as rows with badges);
  - My Team (if Q2 is approved);
  - Latest announcements as icon-tile rows.
- **Tasks / Task:** rows show priority (flag icon plus word) and a due pill ("Due today" in red tint, a date in neutral), with the project as subtitle. The detail page uses grouped cards.
- **Schedule / entry:** a time column, a coloured dot for the kind, and the title.
- **Messages / Chat / New message:**
  - avatar rows: group avatar for teams, icon tile for project chats;
  - bold name, one-line preview, time, unread bubble;
  - chat bubbles: mine in accent-soft, others' on raised.
- **Notifications / Announcements:** icon-tile rows, with the time on the right and unread shown in bold with a dot.
- **Staff directory / Staff member / My profile:**
  - employee rows: avatar with presence dot, name, role, status dot plus word;
  - the profile page has a header card with a large avatar and the contact rows below.
- **My status / history, Leave, Team leave, Leave request:** cards and rows on the new components. Leave balances become stat chips.
- **Projects, Clients (and their pages):** icon-tile rows with status chip and client or contact line. No progress bar (no data).
- **Reports, report pages:** icon-tile rows. Severity and status keep their chips.
- **All forms:** grouped in cards, with the same labels, errors, focus rules and buttons (37B). Restyled by tokens only.
- **More:** grouped list cards of icon-tile rows (Account, Work, People, Communication).
- **Guest pages and error pages:** the new palette, the radius scale and the app's mark.

## 7. What does not change

- Every route, permission check, API call, Livewire action, wire key, `aria-*` label, focus behaviour and piece of wording, except where a heading becomes the reference's "View all" pattern.
- Contrast, 44 px targets, the skip link, reduced motion, and no horizontal page scroll at 320 px. Chip rows wrap; they don't scroll sideways.

## 8. Delivery and verification

- **One PR**, committed in steps: tokens and shell, then components, then screens group by group, then docs.
- **Tests:** feature tests that assert markup are updated only where the markup changes. No behaviour test is removed.
- **Checks:**
  - `npm run build`;
  - Pint, PHPStan and `php artisan test` (CLAUDE.md §5) if Composer can install here; otherwise CI;
  - Playwright screenshots of every screen group at 360, 390, 768 and 1280 px, light and dark;
  - a horizontal-overflow check;
  - contrast recomputed for every new pair.
- **Docs:** CURRENT_STATE, CHANGELOG, `06_UI_UX_GUIDELINES.md` (the web foundation), DECISIONS (a new DEC that supersedes DEC-064's portal palette and part of DEC-068's shape rule), TEST_STATUS, UAT_LOG (new UAT-37D scenarios, `NOT RUN`), and the phase handoff.
- **Order with 37B and 37C UAT:** they are still `NOT RUN`. 37D changes their look but not their behaviour. Their scenarios can run on a 37D deploy, or before it, as the product owner prefers.

## 9. Questions for the product owner

1. **Palette:** replace the indigo accent with royal blue `#2563EB` (and the grey-blue page) app-wide, as in §4.1? *(Recommended: yes. It's what "all screens to this style" means.)*
2. **Home additions:** add (a) the quick actions row and (b) the My Team row (one extra API call)? Both use existing data and routes. *(Recommended: both.)*
3. **Directory status filters:** these need a small API addition (filter `/staff` by operational status). It's outside "no API change". *(Recommended: leave out of 37D; record it as a follow-up.)*
4. **Hero image:** keep the gradient, or supply a company photo (it would ship as a compressed asset)? *(Recommended: keep the gradient unless you have a photo.)*
