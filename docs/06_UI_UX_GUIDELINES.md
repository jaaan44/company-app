# 06 — UI/UX Guidelines

Status: **Foundation resolved and now implemented at the shell level, not screen designs.** Phase 4 built a functional (not visually refined) login screen for both the Admin Backoffice and the Flutter app, and a neutral authenticated placeholder, to make Authentication actually usable. Phase 23 (Mobile UI/UX Audit & Foundation) audited that existing UI and resolved the visual-design system, accessibility target, and mobile navigation architecture this document previously left open — see `docs/phases/V1_PHASE_23_MOBILE_UIUX_AUDIT.md` for the full audit and `docs/DECISIONS.md` DEC-046 for the decision record. Phase 25 (Mobile Application Foundation & Navigation Shell) then implemented the navigation architecture (`go_router`, the bottom-navigation shell) and closed the dark-theme (G-01) and typography-consistency (G-02) findings Phase 23 had documented but not fixed — see `docs/phases/V1_PHASE_25_DEFINITION.md` and `docs/handoffs/V1_PHASE_25_HANDOFF.md`. These guidelines otherwise steer future design/implementation phases and are not a substitute for actual UX design work when a phase builds a specific business-module screen (wireframes/detailed layouts remain that phase's own responsibility, not this document's).

## Staff Mobile App (Flutter) — Navigation

Primary bottom navigation, kept shallow and task-oriented:

```
Home | Tasks | Schedule | Messages | More
```

`More` may expose secondary areas without cluttering primary navigation:

```
Profile · Staff Directory · Clients · Projects · Work Logs · Leave ·
Service Reports · Incident Reports · Announcements · Location/Check-in · Settings
```

Principles:
- **Mobile-first staff workflows.** Design for a staff member using this on a phone, often in the field, possibly one-handed, possibly with poor connectivity — not a scaled-down desktop layout.
- **Minimal navigation depth.** Prefer surfacing the next action (e.g. "check in," "log work," "approve leave" if permitted) over deep menu trees. Two taps to any primary action is a reasonable target.
- **Clear status indicators.** Status (task state, leave request state, incident state, staff current status) should be visually unambiguous at a glance — consistent color/iconography per status value, reused across modules rather than invented per screen. See "Status Presentation" below for the concrete convention.

### Navigation Architecture (implemented, Phase 25 — DEC-046)

**`go_router` is installed and is the app's sole navigation mechanism**, replacing the former `AuthGate` widget-switch/`MaterialApp.home`. `lib/app/router.dart` builds a `GoRouter` with `refreshListenable: authController` and a declarative `redirect` callback (`AuthStatus.unknown` → `/splash`, unauthenticated → `/login`, authenticated → the shell). A `StatefulShellRoute.indexedStack` (`lib/features/shell/presentation/app_shell.dart`) implements the bottom-navigation shell exactly as specified below — each of the five destinations preserves its own navigation stack across tab switches.

Both needs that motivated the choice are realized: **auth-gated redirects at the route level** (the `redirect` callback above, evaluated on every `AuthController` change) and the **structural readiness for deep linking** (`go_router`'s path-based routes make it possible; actual deep-link *consumption* from a Notification is still unbuilt, deferred to whichever later phase first needs it).

Three of the five destinations (`Tasks`/`Schedule`/`Messages`) are still honest placeholders — Phase 25 built the shell and routing only, per its own scope boundary; Phases 27–33 give them real content. *(Home became real in Phase 27, `More` in Phase 28, and `Tasks` in Phase 29A.)* See `docs/phases/V1_PHASE_25_DEFINITION.md` and `docs/handoffs/V1_PHASE_25_HANDOFF.md` for the full account.

### Employee Home (implemented, Phase 27 — complete, formally closed 2026-09-24)

The Home tab is the first real mobile screen, and the first real use of the loading/empty/error conventions below. In order: greeting ("Hello, {preferred ?? first name}", position · department, team — no time-of-day wording), **Today** (the employee's own schedule entries and tasks due today, in server order, times in the *company* timezone via the server's `company_day.utc_offset`, "+N more today"), **Needs attention** (task, message and notification counts as tiles, each one semantics label; overdue shown in the `error` role *with* text), and **Latest announcements** (≤ 3, title + date). Users with no employee profile see an explanation and only the notifications count. States: centered `CircularProgressIndicator` on first load; inline error in the `error` role (live region) with a "Try again" `FilledButton`; pull-to-refresh that keeps content and shows a `SnackBar` if it fails. **Home content is deliberately non-interactive** (no `InkWell`/`ListTile`/`GestureDetector`/chevrons) until the Tasks/Schedule/Messages screens exist (spec R-1). Verified by widget tests in light and dark mode and at 200% text scale; not yet verified on a device.

### People — More, My profile, Staff directory (implemented, Phase 28 — complete, formally closed 2026-10-09)

- **`More`** lists only real areas: *My profile* (subtitle: the account name) and *Staff directory*. There are no "coming soon" rows; each later phase adds its own row (R-8). Rows are standard `ListTile`s with a chevron.
- **My profile** is read-only:
  - a header with display name, full name if different, position · department, and team;
  - **Work**, **Employment** and **Account** sections;
  - a closing line: "To change these details, contact an administrator.";
  - an account without a staff record shows "No staff profile is linked to this account." and the Account section only.
- **Staff directory:**
  - a search field ("Search by name", with a clear button) above the list;
  - rows show display name and "position · department", missing parts skipped;
  - pull-to-refresh;
  - empty states: "No active staff yet." and "No one matches "{q}".".
- **Staff detail:**
  - a name header (full name as a second line when it differs);
  - position, department, team, and a manager row that navigates;
  - company email and phone with a **Copy** button each (a "Copied" `SnackBar`).
  - No tap-to-call or tap-to-email (R-3).

Conventions this phase establishes for later list screens:
- **Missing values read "Not set"**, never blank.
- **Paged lists:**
  - load the next page automatically when the list nears its end, with an inline spinner row;
  - a failed page becomes a "Couldn't load more. Tap to retry." row, not a full-screen error, and the loaded rows stay;
  - a failed **first** page uses the full-screen error with "Try again";
  - a failed **refresh** keeps the list and shows the Home "Couldn't refresh. Showing earlier information." notice.
- **Search** waits about 300 ms after typing pauses, and only the latest search's results are ever shown.
- **List density:** Material's standard comfortable density proved sufficient for a ~100-person directory, so no denser variant was introduced (see Spacing below).

### Tasks — list, detail and the first write (implemented, Phase 29A — complete, formally closed 2026-10-10)

- **Tasks tab:**
  - An **Open | Done** `SegmentedButton` at the top; each segment keeps its own list, and Done loads the first time it is shown.
  - Rows: title (up to two lines), project name when there is one, then a status chip and the due label — "Due today", "Overdue · 3 Sep" (in the `error` role, semi-bold), "Due 12 Oct", or nothing. The year is added when it isn't the company year.
  - Phase 28 paging, retry row and pull-to-refresh; empty states "No open tasks assigned to you." / "No completed tasks yet."; "No staff profile is linked to this account." without a profile.
- **Task detail:** title as the header; status chip and "Priority: …"; a **Status** section; a **Details** section (due date with " · Overdue" or " · Due today", project or "Independent task", created by, completed date when there is one, "Not set" for missing values); a **Description** section ("No description." when empty).
- **Home (R-6, refining Phase 27's R-1):** the My tasks tile and Today **task** rows (with a chevron) are tappable and open the Tasks tab or the task. Schedule rows, the other tiles and announcements stay non-interactive until their own phases.

Conventions this phase establishes for writes:
- **Status chips** are the shared status convention made concrete: a container colour role (to do: surface-container-highest; in progress: primary-container; blocked: error-container; completed: tertiary-container; cancelled: outlined surface) **plus the status's text, always**.
- **Picking a value that saves immediately** uses a wrapping row of `ChoiceChip`s (not a segmented button, which clips at large text). Only the values the person may choose are offered; a value they can't change is shown read-only with a sentence saying why.
- **Saves are confirmed, not optimistic (R-7):** the control shows the saved value until the server confirms the new one. While saving, the control is disabled under a `LinearProgressIndicator`. Success shows a `SnackBar` ("Status changed to In progress."). A failure keeps the old value and shows the reason **under the control**, in the `error` role as a live region: the connection message, the server's own message for a 403 or 422, or "This task is no longer available." Nothing is retried automatically; the person retries by choosing again.
- **No confirmation dialog for reversible changes:** a status change can be undone by choosing again, so it doesn't use the destructive-action `AlertDialog` (that convention stays for hard-to-reverse actions).
- **After a change**, every other screen showing that data (lists, Home counts) refreshes quietly, so returning to it never shows stale state.

### Work logs — list and the first full form (implemented, Phase 29B — complete, formally closed 2026-10-10)

- **My work logs** (More → "My work logs"): date group headers "Today", "Yesterday", then "Wed 7 Oct" (the year added when it isn't the company year), judged against the server's company day. Each row: the task title or project name, the project under a task, the description (two lines) and the duration on the right ("1 h 30 min", "45 min", "2 h"). An extended **Add** button; the list keeps space at its end so the button never covers the last row. Empty: "No work logged yet."; no profile: "No staff profile is linked to this account." (no Add button).
- **Log work / Edit work log:** one column, in this order — **What was this for?**, **Date**, **Time spent** (Hours, Minutes), **What did you do?**, then **Save** (and **Delete** when editing). A task's detail offers **Log work** (not for cancelled tasks), opening the form with the task fixed.

Form conventions this phase establishes (extending 29A's write conventions):
- **Picker fields:** a value that's chosen rather than typed (what-for, date) is an outlined, labelled field with an icon; tapping it opens a bottom sheet or the system date picker. Read-only when it can't change (editing; opened from a task) — no arrow, no tap.
- **Ranges are enforced in the control** where possible (the date picker can't go past the company today or more than 365 days back), and checked again before sending.
- **Numbers:** short digit-only fields (Hours, Minutes), side by side, wrapping under large text.
- **Errors:** each field's message directly under it, in the `error` role, announced (live region); errors that aren't about one field (eligibility, offline, a refusal) in an error-container banner at the top. Editing a field clears its error. Nothing is sent while any check fails.
- **Save:** a full-width `FilledButton` that shows a small spinner and disables the form while saving; success is a `SnackBar` ("Work logged.", "Changes saved.", "Work log deleted.") and the form closes.
- **Leaving:** with unsaved changes, "Discard changes?" (Keep editing / Discard); without changes, no prompt. **Deleting:** "Delete this work log?" (Cancel / Delete in the error colour) — the destructive-action rule.

### Projects and Clients — read-only browsing (implemented, Phase 29C — complete, formally closed 2026-10-12)

- **More** has five rows: My profile, Staff directory, My work logs, **Projects** ("Projects you're a member of"), **Clients** ("Company clients and contacts").
- **Projects** (More → Projects): the Staff directory layout — a search field ("Search by name or code"), then rows with the name, "code · client", a **status chip** (Planned, Active, On hold, Completed, Cancelled) and "Project lead" in the primary colour when it applies. Empty: "You aren't a member of any projects yet."; no profile: "No staff profile is linked to this account." (no search field).
- **Project detail:** a header (name, code, status chip), then labelled rows — Client (opens the client), My role, Start date, Target end date, Completed (only when set) — the description under its own header, **Members (N)** (leads first; each opens their Staff directory entry) and **Milestones** (title, date, status chip; never an "overdue" label). Lists that load only their first page end with "Showing 50 of N". A project you can't see: "You don't have access to this project." with Try again.
- **Clients** (More → Clients): active clients, the same list layout (name, code). **Client detail:** a header (name, code, an "Inactive" chip when it is), Email, Phone, Website and Address, each with **Copy**; then **Contacts (N)** — each contact's name with a "Primary" chip, job title, and email/phone indented beneath with Copy.
- **Status chips** share one style with the Tasks chip: a container colour per state, the label always written and announced ("Status: Active"); muted states (Cancelled, Inactive) are outlined.
- **Links between areas:** a task's project opens in the **More** tab (the Tasks tab keeps the task); a project's client and members open within More. Notes are never shown.

### Schedule — my week, read-only (implemented, Phase 30A — complete, formally closed 2026-10-12)

- **Schedule tab:** "Schedule" with a **Today** text button (disabled on the current week). A week bar — **Previous week** / "14 – 20 Oct" / **Next week** (icon buttons with those tooltips) — then seven days from the **company** today, each headed "Today", "Tomorrow", "Yesterday" or "Fri 16 Oct" (today's header in the primary colour, bold; every header a semantics header). Never labelled from the device clock (DEC-052).
- **Rows** (server order within each day): an icon (event, task, leave) **with** a written line — "09:00 – 10:30 · Client visit · Plant Room", "All day · Task · To do", "All day · Leave". Times are company wall-clock time. A multi-day item appears on every day it covers ("23:00 – until Tue 27 Oct", "From Mon 26 Oct – 01:00"). An empty day reads "Nothing scheduled."
- **Taps (R-9/R-10):** a task opens its detail in the **Tasks** tab; a meeting opens its entry detail inside the Schedule tab (Back returns to the same week); **leave is not tappable** and has no chevron.
- **Entry detail** (read-only, no buttons): a header (title, type), then **When** (company time; "(device time)" is added only for a restored deep link that carries no offset), Project, Organizer, **People (N)** (or "No other people.") and the Description under its own header when present.
- **States:** a centred spinner; "No staff profile is linked to this account."; an error with **Try again** that reloads the week that failed; pull to refresh keeps the week and shows a `SnackBar` if it fails; a gone entry: "This item is no longer available." After a task's status changes in the Tasks tab the week refreshes quietly (as Home does).
- **Home (R-10):** Today's meeting rows are now tappable too (a chevron on every Today row) and open the same entry detail in the Schedule tab, in company time. This refines the Phase 27 "non-interactive" rule above, after Phase 29A's task rows.
- Verified by widget tests in light and dark mode and at 200% text on a 320×568 phone; not yet verified on a device.

### My status and Check in (implemented, Phase 30B — merged, awaiting the combined UAT round)

- **More** gains a sixth row, **My status**, second after My profile. Its subtitle is my current status ("In the field"), "No status set", or "Your status and check-ins" when it can't be read. It is read with one small request, and again on returning from My status. This replaces the Phase 28 "More fetches nothing" rule (R-13).
- **My status:**
  - my current status as an icon **and** its label, with "Set Today 09:00" (company time). Off duty adds "Not working right now. This isn't leave."
  - **Check in** (filled) and **Change status** (outlined);
  - **Recent check-ins** and **Recent status changes**, 5 each, with **All check-ins** / **All status changes** links, or "You haven't checked in yet." / "You haven't set a status yet.";
  - no profile: only "No staff profile is linked to this account." (no buttons).
- **Change status** opens a sheet: the five statuses, each with icon and label, the current one marked with a check. Choosing one closes the sheet and sends it. A progress bar shows while sending, and the buttons are disabled. The screen changes **only** when the server confirms ("Status set to In the field."); a failure keeps the old status with a SnackBar. Closing the sheet sends nothing.
- **Check in** (its own screen):
  - Unless location is already allowed, an explanation comes **first**: *"Company App reads your location once, only when you tap Check in. It never tracks you in the background. Your manager and administrators can see your check-ins. A check-in isn't a clock-in."* It offers **Continue** (then Android's prompt) and **Not now**.
  - "Finding your location…".
  - The form: "Location found (±9 m)" or "Approximate location (±1800 m)", the coordinates, an optional **Place** (≤ 100) and **Note** (≤ 500), **Send check-in** and **Cancel**. A failed send keeps the form with the message in the error role.
  - Errors, each with **Try again**: "Turn on location to check in." (+ **Open location settings**), "Location permission is needed to check in." (+ **Open app settings** once Android stops asking), and "Couldn't get your location. Try again, ideally outdoors or near a window."
  - On success it returns to My status with "Checked in." and the check-in on top. Back is held while a check-in is sending, because leaving wouldn't cancel it.
- **Rows:** a check-in shows the place or "No place name", "Today 10:30 · ±12 m", the note and the coordinates (5 decimals); a status change shows its icon, label and time. Times use Today / Yesterday / "Sat 10 Oct 16:00", in company time. No map; nothing is editable.
- **All check-ins / All status changes:** the paged list (20 a page) without a search field.
- Verified by widget tests in light and dark mode at 200% text on a 320×568 phone, plus tap-target and label guidelines. Not yet on a device.

### Reports (implemented, Phase 33 — awaiting the combined UAT round)

- **More** gains **Reports** ("Service and incident reports"), ten rows in all.
- **Reports:**
  - a segmented control, **Service reports** · **Incidents**, and an **add** icon in the app bar with the tooltip "New service report" or "Report an incident". A floating button, then a full-width button, both covered or squeezed the rows at 200% text;
  - service rows show the client, then "Tue 13 Oct 2026 · Plant Room", then a status chip (Draft neutral, Submitted secondary, Reviewed tertiary, Rejected error);
  - incident rows show the type, "Wed 14 Oct 2026 09:30 · Loading bay" (company time), then the severity chip (High and Critical in the error colour) and the status chip.
- **New service report:**
  - **Client** (a searchable sheet), then **Project (optional)**: my open projects for that client, or "No project";
  - **Service date**: default "Wed 14 Oct 2026 (today)", no later than the company today;
  - **Work performed** (required), Findings, Recommendations, Follow-up actions and Site representative;
  - **Save draft**, explained by "It's saved as a draft. You can add photos, then submit it for review.";
  - the new draft opens in place of the form; Back returns to the list.
- **Report an incident:**
  - **Type of incident** (a sheet), Severity (chips; Medium by default), **Date** and **Time** in company time (or "(device time)");
  - Location, **What happened** (required), Immediate action taken, People involved, Witness notes;
  - **Report incident**.
- **Details:**
  - a header (the client or type, the date or time, by whom, assigned to) with chips, then each text under its own heading (selectable);
  - **Photos and files (N)**: a photo opens full screen (zoomable; a close button); a PDF reads "1.5 MB · Open it on the web";
  - **Take photo** / **Choose photo**, with "Uploading photo…" while sending;
  - **History**: "Rejected by Grace Hopper · Wed 14 Oct 2026 10:00", with the note beneath.
- **Actions, each confirmed:**
  - my draft: **Submit** ("Your manager will review it. You can't edit it after submitting."), **Edit**, **Delete draft**;
  - my rejected report: **Return to draft**;
  - my reported incident: **Edit**;
  - photos: remove (trash icon with a tooltip).
  Refusals show in an error banner in the server's words. Back is held while an action is sending.
- Verified by widget tests in light and dark mode at 200% text on a 320×568 phone, plus tap-target and label guidelines. Not yet on a device.

### Messages, Notifications and Announcements (implemented, Phase 32 — awaiting the combined UAT round)

- **Messages tab** (no longer a placeholder):
  - each row shows an icon (person, group or folder), the name (the other person, the group or the project), the last message ("You: …" for mine, "Grace: …" in a group), its time ("09:12", "Yesterday", "Mon 12 Oct" in company time) and an unread **badge** (announced "2 unread"); unread rows are bold;
  - empty: "No conversations yet."; **New message** is a floating button;
  - no profile: "No staff profile is linked to this account."
- **A chat:**
  - the title is the conversation's name;
  - messages run oldest to newest, the newest at the bottom, under day separators (Today, Yesterday, "Mon 12 Oct");
  - my bubbles sit on the right in the primary container colour; others' on the left, with the sender's name in group and project chats; each bubble shows its time;
  - **Load earlier messages** at the top; "No messages yet. Say hello." when empty;
  - the composer: a field (1–4000) and a **Send** icon button (with tooltip) showing progress while sending; on failure, "Couldn't send. Try again." with the text kept;
  - Back is held while sending; new messages appear within about 15 s while the chat is open.
- **New message:** a search field and colleagues (never myself), each with their position. A tap opens our chat, reused if it exists; Back returns to Messages, not the picker.
- **Notifications** (from Home's tile or More):
  - newest first; unread ones bold with a dot (announced "Unread"); each shows title, message and time;
  - a tap marks it read and opens the announcement (in the same tab) or the chat (in the Messages tab);
  - **Mark all read** is an icon button in the app bar (a text button didn't fit beside the title at 200% text), disabled when nothing is unread.
- **Announcements** (from Home or More): newest first; unacknowledged ones bold with a **New** chip. The detail shows the title, "Ada Admin · 13 Oct 2026 10:00" (company time), the body as selectable plain text, and **Acknowledge** → "Acknowledged on …".
- **Home (refines Phase 27 R-1):**
  - the Messages tile opens the Messages tab and the Notifications tile the inbox;
  - each latest announcement opens its detail (with a chevron), plus **All announcements**;
  - the counts refresh after any change.
- **More** gains **Announcements** and **Notifications** (nine rows).
- Verified by widget tests in light and dark mode at 200% text on a 320×568 phone, plus tap-target and label guidelines. Not yet on a device.

### Leave (implemented, Phase 31 — awaiting the combined UAT round)

- **More** gains a seventh row, **Leave** ("Balances and requests"), after My status.
- **Leave screen:**
  - **Request leave** (filled);
  - **Team requests** ("2 pending" or "Nothing waiting for you"), only for reviewers who manage someone;
  - **Balances 2026**, one row per active type: "10 days left of 15" over "3 used · 2 pending" for paid types, "Unpaid — no balance" for unpaid ones;
  - **My requests**, newest first: the type, "Tue 20 Oct – Thu 22 Oct 2026 · 3 days", and the status chip **beneath** (a chip beside the text squeezed rows at 200% text). **Show more** loads 20 more. Empty: "You haven't requested leave yet.";
  - no profile: only "No staff profile is linked to this account."
- **Status chips (R-8):** Pending (secondary container), Approved (tertiary), Rejected (error container), Cancelled (muted, outlined); the label is always written and announced ("Status: Approved").
- **Request leave:**
  - **Type of leave** opens a sheet (paid types show "10 days left in 2026", unpaid "Unpaid").
  - **First day** and **Last day** open date pickers, both defaulting to the company today ("Today"); past dates are allowed. A first day after the last moves the last day with it.
  - "4 calendar days (weekends included)", then **Reason** (3–1000) and **Send request**.
  - Errors appear on their fields ("A request must stay within one calendar year. Make one request for each year."); the server's refusals (overlap, balance, inactive type) appear in a banner at the top, in its words.
  - On success: "Leave requested." and back to Leave, which reloads. "Discard this request?" asks before leaving with anything entered.
- **A request's detail:**
  - the type with Paid/Unpaid and the chip, the dates with "N calendar days (weekends included)", and the reason;
  - **History**: "Approved by Grace Hopper · Today 09:12" in company time, with the note beneath;
  - **Cancel request** (outlined, error colour) while pending, or approved and not yet started (company day). A dialog takes an optional reason: **Keep it** / **Cancel request**. On success: "Request cancelled.";
  - Back is held while an action is sending.
- **Team requests:**
  - chips **Pending (N)** · Approved · Rejected · All; each row shows the person, the type, the dates and days, and the chip;
  - a team request's detail adds **Requested by**, plus **Reject** (outlined) and **Approve** (filled) while pending;
  - Approve takes an optional note; Reject needs a reason (3–1000, checked in the dialog);
  - after a decision: "Request approved." / "Request rejected.", and the buttons go. Decided elsewhere: the server's message in a banner, and the request reloads.
- Schedule reloads quietly after any confirmed leave change, so approved leave appears there.
- Verified by widget tests in light and dark mode at 200% text on a 320×568 phone, plus tap-target and label guidelines. Not yet on a device.

## Admin Backoffice (Web) — Navigation

Primary sections:

```
Dashboard
People | Clients | Work | HR | Operations | Communication | Reports | Administration
```

Principles:
- **Responsive**, but optimized for desktop/tablet use by administrative and management roles — not required to be as thumb-friendly as the mobile app.
- **Permission-aware UI.** Navigation items, actions, and buttons for capabilities the current user lacks should not appear (not just be disabled) unless a disabled state with explanation is genuinely useful (e.g. "requires HR Administrator role" tooltip) — decided per case, but hiding is the default.
- **Consistent forms.** Shared form conventions (validation display, required-field marking, save/cancel placement) across modules — a leave-approval form and a client-edit form should feel like the same product.
- **As built (Phases 35B and 35C, DEC-065):**
  - **Shell:** `/admin` uses the portal's look (tokens, toasts, in-app dialogs, icons, navigation without reloads).
  - **Navigation:** a 16 rem sidebar with the sections in the order above, the current one marked; Staff portal and Log out at the bottom. On phones, a menu button opens the same list as a drawer from the left (Close focused, Escape closes).
  - **Section pages:** each lists its screens. Future ones show a muted "Coming in Phase 36" pill, which goes as each screen ships (People and Users and roles in 36A).
  - **Dashboard:** cards in a 1/2/3-column grid, with a From/To period form.
  - **Roles & permissions:**
    - a card per module, with its rule note;
    - a labelled Manager and Staff checkbox per permission (screen readers hear the permission too);
    - a sticky bar with Save changes (a dialog listing "Can now" / "No longer"), Undo changes and Reset to defaults.
- **As built (Phase 36A, DEC-066):**
  - **Lists** (Staff records, Users and roles) have search, a filter where useful, the main action as a button at the top right, and chips for states (Separated, No sign-in, Suspended, Must choose a password).
  - **A record page** shows the details, then one card per concern (Sign-in account, Status, Check-ins), each only for those who may see it. Edit and Delete sit at the top.
  - **Department, team and position editing** happens in place above its list, rather than on a separate page.
  - **A refusal** shows the API's message, plus what to do instead ("set their status to Separated", "set it to Inactive").
  - **A temporary password** appears once in a highlighted panel, in a monospace box with Copy, saying it won't be shown again and what the person does next.
  - **Choose your password** uses the sign-in page's layout, with Log out. **Change password** is a portal page linked from My profile.
- **As built (Phase 37B, DEC-068):**
  - **Dashboard:** **Needs attention** first: leave waiting for a decision, open incidents and overdue tasks, all as of now. Each links to its list, for those who may open that section. Then **Overview**, with the From/To form and the other figures.
  - **Section pages:** an item that opens a staff-portal screen says "Opens in the staff portal" and loads in full. People has two items (Staff records; Departments, teams and positions).
  - **Filtered lists** show **Clear filters** once a filter differs from the list's start.
  - **Audit log:** actions read as words, with the raw key after them.
- **As built (Phases 36B–36D, DEC-066):**
  - **Lists** default to what needs attention: pending leave, submitted service reports, open incidents. Each has filters for status, type, dates and person.
  - **Decisions** (approve, reject, cancel, mark reviewed, the incident steps) open a small form in place. A reason is required where the API requires one; otherwise there is an optional note.
  - **Person pickers** share one search component.
  - **Report tables** scroll sideways inside a labelled, focusable region on a phone. **Download CSV** sits beside the filters.
  - **Audit log:** a person's name and a record ID are links that filter the log, each at least 24 px tall.

The visual-design foundation for Flutter is below (DEC-046). **The web equivalent, for the Staff Web Portal and later the Admin Backoffice, was decided in Phase 34A (DEC-063 W-5); see "Web Visual-Design Foundation" next.**

## Web Visual-Design Foundation (resolved, DEC-063)

- **Toolkit:** Tailwind CSS v4 via Vite, server-rendered Blade + Livewire (DEC-019). There is no client-side component library. Alpine (bundled with Livewire) is used only for small conveniences, such as Copy buttons.
- **Tokens** (`apps/api/resources/css/app.css`):
  - `surface`, `raised`, `ink`, `muted`, `line`, `hover`, `accent`, `on-accent`, `accent-ink`, `accent-soft`, `danger`, `danger-soft`, `success` and `success-soft`, each with a light and a dark value; dark follows the browser's `prefers-color-scheme`.
  - They are the web counterparts of the mobile color roles. Since 34F (DEC-064) they take the app's M3 indigo palette: light surface `#fbf8ff`, accent `#4355b9`; dark surface `#121318`, accent `#bac3ff`.
  - Every text and surface pair meets WCAG 2.2 AA. Axe scans of the 34A pages in light and dark found no violations.
  - **Since 37B (DEC-068):**
    - `field` (light `#767680`, dark `#90909a`) is the boundary of every form control, ≥3:1 against its surface (WCAG 1.4.11; `line` stays for dividers and card borders);
    - `warning` and `warning-soft` (light `#7a5900` on `#ffdea6`, dark `#f5bf48` on `#5c4300`) are for a state someone must act on that isn't an error;
    - `hover` is stronger (light `#e3e1ec`, dark `#34343b`).
- **Since 37D (DEC-071, `docs/design/portal-style-reference.webp`):** the portal palette is royal blue on a grey-blue page:
  - light: surface `#f4f6fa`, raised `#ffffff`, edge `#e6ebf2`, ink `#0f172a`, muted `#475569`, accent `#2563eb`, accent-ink `#1d4ed8`;
  - dark: surface `#0e1525`, raised `#172033`, ink `#e5e9f2`, muted `#a3adbf`, accent `#93b4ff`;
  - **kind colours** (`card-*`, `tint-*`, `icon-*`: blue, green, amber, rose, violet, slate) are app-wide; each icon colour is ≥ 4.5:1 on its tint and on white.

  This supersedes the indigo values above. The Admin Backoffice shares these tokens.
- **Type:** the system font stack. No web fonts, so no third-party request.
- **Shell (34F, DEC-064):** the app's five tabs (Home, Tasks, Schedule, Messages, More), a bottom tab bar on phones and a sidebar from `md` up, with the current tab marked. More lists the other pages in the app's order. A sticky app bar shows the title, with a back arrow on sub-pages (to the parent, or to More). A list's main action is a "+" FAB on phones and a pill button on wider screens. The page content keeps clear of the tab bar and the safe areas.
- **Layout:**
  - one centered column, at most `3xl` wide (since 34F), with a 16 px gutter;
  - cards for sections;
  - grids collapse to one column on narrow screens;
  - no horizontal scrolling at 320 px (200% zoom of a 640 px window).
- **Interaction:** every target is at least 44 px tall (`min-h-11`); focus is always visible (a 3 px accent outline); there's a skip link and a labelled main navigation with `aria-current`.
- **Shapes and shared controls (37B, DEC-068; shapes 37D, DEC-071):**
  - **Three shapes:** controls (inputs, selects) `rounded-lg`; containers (cards, notices, lists) the **`card`** utility (`rounded-2xl`, a hairline in `edge`, `shadow-card`; add only layout classes); actions (buttons, chips, choice pills) `rounded-full`.
  - **Form controls use `field`.** Add only layout classes, such as a width or `mt-1`. Labels are `text-sm font-semibold`.
  - **Buttons use `btn` plus one variant:**
    - `btn-primary`, the page's main action, one per area;
    - `btn-secondary` (outlined), a second action (Edit);
    - `btn-danger-outline`, a destructive action before its confirmation (Delete…);
    - `btn-danger`, the confirmed destructive action;
    - `btn-text`, a quiet action (Cancel, Clear filters);
    - `btn-danger-text`, a quiet destructive action in a row (Remove);
    - `btn-plain`, an action in body colour (Previous, Next).

    `btn-compact` narrows a button inside a row. Never hand-write a button's colours or shape.
  - **Saving and loading need no per-view code:** Livewire disables a submitting button and marks a clicked one `data-loading`, and both dim the button and show a spinner. A live search or filter shows a spinner at its end while results load.
  - **A page's message in place of content** ("This task is no longer available.") is `x-portal.notice`; "No staff profile is linked to this account." is `x-portal.no-profile`; an empty list is `x-portal.empty`.
- **Focus (37B):**
  - after moving to another page without a reload, focus goes to the page heading (not on Back or Forward);
  - after a failed save, focus goes to the first field marked invalid.
- **Password fields (37B)** have Show/Hide. They're hidden again on submit.
- **Messages badge (37B):** the Messages tab shows the unread count in an accent pill ("99+" above 99), in both the sidebar and the tab bar. Screen readers hear "Messages, 3 unread". There is no badge when nothing is unread, or without a staff profile.
- **Sortable report columns (37B):** a column that can be sorted is a button in its header with ↕, ↑ or ↓. Tapping it sorts ascending, and tapping again reverses it. `aria-sort` says which way it's sorted. The CSV follows the same order.
- **Chat (37B)** opens at the latest message and follows new ones while you're near the end. The message box stays in view above the tab bar.
- **States, wording and order:** the same as the mobile screens they mirror (no-profile, error with "Try again", empty lists).
- **Status chips (34B):** `x-portal.chip`. The text label is always present, and the tone comes from one mapping in `App\Support\Portal\Format::tone()`:
  - neutral (To do);
  - info (In progress, Active, Planned, Pending, Submitted);
  - warning (Suspended, Must choose a password; since 37B);
  - danger (Blocked, Rejected);
  - success (Completed, Approved, Reviewed, Resolved);
  - muted (Cancelled, Inactive, On hold, Separated, Archived).

  Since 37B, staff, account and announcement statuses also take their labels from `Format`.

  A status concept looks the same on every web page ("Status presentation" below).
- **Chat (34D):** my messages sit on the right in the accent-soft colour; others' on the left with a border. Each shows its time, and the sender's name in group and project chats (screen readers always hear "You" or the sender). Day separators are centred headings. Unread counts are text in a badge with " unread" for screen readers; an unread notification is bold, with a dot and "Unread" for screen readers.
- **Files (34E):** a photo shows as a thumbnail with its name and size, and **Open photo** opens it in a new tab; a PDF shows its name and size with **Download**. The file input says what is allowed ("JPEG, PNG or PDF, up to 10 MB"), and Livewire's "Preparing the file…" and "Uploading…" are announced while they run. Destructive report actions (Submit, Delete draft, Return to draft, Remove) are confirmed with the mobile app's wording; since 34F in an in-app dialog, not the browser's.
- **Add, edit and delete (35D):**
  - **New:** a list's "+" FAB (pill on wider screens).
  - **Edit and Delete:** on the item's page.
  - **Forms:** full pages with labelled fields, messages under each field and the API's own message above. Saving redirects with a toast.
  - **Deletes and removals:** confirmed in the in-app dialog, with the reason a delete may be refused.
  - **Mine/All:** a segmented control, only for those who may see everything.
  - **Nothing new** appears for someone without the permission.
- **Feedback (34F):**
  - a confirmation message shows as a **toast** above the tab bar (`role="status"`, hides after 4.5 s);
  - a confirmation that needs no typing is an **in-app dialog** (`x-portal.confirm`, a modal `<dialog>`). It is a sheet from the bottom on phones and centred on wider screens. The question is its heading, the body follows, **Cancel** has focus, Escape closes it, and destructive confirms use the danger colour.
  - The browser's own dialogs are no longer used.
- **Home on phones (37C, DEC-069, `docs/design/mobile-home-reference.png`):**
  - **Hero:** a navy-to-blue gradient under the status bar, in place of the app bar, with the app's mark, the initials (a link to My profile), "Hello, *name*" and the role line and team. From `md` up, it's a rounded panel below the app bar.
  - **Sheet:** a rounded-top sheet overlaps the hero. It holds:
    - the **Today** card (blue tint, calendar icon, each item a row with a chevron button);
    - **Needs attention**: tasks (rose), messages (blue) and notifications (green), as pastel cards with a round icon, two columns on phones with notifications across the row, three columns from `lg`;
    - **Latest announcements**: a list card with violet megaphones and a "View all" heading link.
  - **Colours:** Home's own tokens (`hero-*`, `card-*`, `tint-*`, `icon-*`, `shadow-card`); the rest of the app keeps the indigo accent.
- **Portal style (37D, DEC-071):**
  - **App bar:** the page colour with no rule, a bold `text-xl` title.
  - **Section headings:** `text-lg font-bold`; `x-portal.section-header` adds a "View all" link (heard as "View all *what*").
  - **List rows:** a leading `x-portal.icon-tile` (its tone from the status, `Format::tileTone`) or `x-portal.avatar` (initials on a tint from the name), a bold title over a muted line, and a chevron when the row opens something. Detail pages put a large icon tile above the title; a colleague's page and My profile open with a header card and a large avatar.
  - **Operational status:** `x-portal.status-dot`, a dot in the status's colour plus its word (`Format::operationalTone`). An avatar may carry the same dot. Shown only with `staff-status.view`.
  - **Filter chips:** a list's filters are separate `filter-chip` buttons; the chosen one (`aria-pressed="true"`) is filled with the accent. Chips wrap; they never scroll sideways.
  - **Counts:** unread counts are a red bubble (`bg-danger`, the count, " unread" for screen readers); `x-portal.stat-chip` is a tinted count with its words ("1 due today").
  - **Home:** hero; quick actions (links to existing pages, permission-gated, the first filled); **Employees** (37E, DEC-072: the first four active staff A–Z as **compact** `x-portal.staff-card`s side by side, as in the reference's dashboard. On phones they sit in a row that scrolls sideways inside itself, 152 px each with the next one peeking in, snapping to each card; the page never scrolls sideways. Four columns from `md`. With "View all (N)"); Today rows; My work chips; Needs attention rows; Latest announcements. 37D's My team row is gone.
  - **Staff cards (37E):** `x-portal.staff-card` has two shapes, each the whole card as one link to the person's page.
    - **Compact** (Home): stacked. A large initials avatar with a status dot, the status (or On leave with a short range, "24 – 26 Oct"), the name and the position, then **Working on** below a rule at the card's foot.
    - **Row** (directory), with a chevron:
    - initials avatar with a status dot, name and role line;
    - status in words, or **On leave** (danger dot) with its dates;
    - when given, a **Working on** block with a violet project tile, below a rule on phones and beside a vertical rule from `sm`.

    There is no ⋯ menu.
  - **Staff directory (37E):** search; status `filter-chip`s (All, Available, In a meeting, Busy, In the field, Off duty, On leave), with `staff-status.view` only; an "All departments" select, with `organization.view`; then a list of staff cards. The filters are kept in the URL (`?show=`, `?department=`).
- **Bottom tab bar (37C):** a raised bar with rounded top corners and a soft shadow. The current tab is a filled accent-soft pill around its icon and label.
- **Phones first (37, DEC-067):** the staff portal is the product for staff on phones (the Flutter app is paused).
  - **Sign-in:** "Keep me signed in on this device" (unticked; "For 30 days. Leave it off on a shared phone or computer."). The email field uses `inputmode="email"` with no auto-capitalisation or spell-check; `enterkeyhint` is next, then go. After sign-in you return to the page you asked for ("Please sign in to continue.").
  - **Connection banner:** a bar across the top (danger colour, `role="status"`).
    - It says "You're offline. Changes won't be saved until you reconnect." when the phone is offline or a tap didn't reach the server.
    - It says "Something went wrong on our side. Please try again." on a server error.
    - It goes when things recover.
  - **No browser dialogs, even when a page expires:** the page reloads, and the sign-in page brings the person back.
  - **Error pages** (403, 404, 419, 429, 500, 503): the app's mark, a short title, one plain sentence and a full-width **Go to Home** (**Continue** for an expired page). Light and dark; no technical details.
  - **Empty lists:** `x-portal.empty`, a bordered card with one muted sentence.
  - **Choice pills:** a short single choice, like an incident's severity, is a row of 44 px pills, with the chosen one in accent-soft. Not 20 px radios.
  - **Long text** fields are multi-line (`textarea`).
  - **A file uploads as soon as it is chosen**, with "Uploading…" announced. There is no separate Upload button.
- **Icons (34F):** inline SVG Material shapes (`x-portal.icon`), always beside a text label and hidden from screen readers.
- **Confirmations (34C):** a decision that can't be undone from the page (Cancel request, Approve, Reject) opens an inline panel in place of its buttons: the question as a heading, the body when there is one, the text field (focused), and the confirm button next to Keep it / Back. Destructive confirms use the danger colour. No browser dialog is used for these, so the reason or note can be typed in the same place.

## Mobile Visual-Design Foundation (resolved, DEC-046)

Material 3, extending — not replacing — the `ColorScheme.fromSeed(seedColor: Colors.indigo)` already in use since Phase 4. This is a foundation for future screens to build on, not a component library — no shared widget code exists yet.

### Color — semantic roles, never raw colors in feature code

| Role | Source |
|---|---|
| Primary/brand | `Colors.indigo` seed (unchanged since Phase 4) → Material 3's generated `colorScheme.primary`/`onPrimary` |
| Secondary/accent | Material 3's own generated `colorScheme.secondary`/`tertiary` — no second hand-picked brand color |
| Surface/background | Material 3's own generated `colorScheme.surface`/`surfaceContainer*` roles |
| Success | `AppStatusColors.success`/`onSuccess` (new `ThemeExtension`, see below) |
| Warning | `AppStatusColors.warning`/`onWarning` |
| Error | Material 3's own `colorScheme.error`/`onError` (already in use in `LoginPage`) |
| Informational | `AppStatusColors.info`/`onInfo` |
| Disabled | Material 3's built-in disabled state layers — no separate custom color |
| Text/emphasis levels | `colorScheme.onSurface` (primary text) / `onSurfaceVariant` (secondary/metadata text) |
| Divider/border | `colorScheme.outline` (stronger, e.g. field borders) / `outlineVariant` (subtler, e.g. list dividers) |

Material 3 doesn't ship success/warning/info roles, so these three are added via a single `ThemeExtension<AppStatusColors>` (mirroring Material's own `color`/`onColor` pairing convention), registered once on `ThemeData.extensions` and looked up via `Theme.of(context).extension<AppStatusColors>()!` — this is the mechanism, so feature code never reaches for a raw `Color(0xFF...)` literal for a status meaning.

**Light/dark:** both supported, following the device's system setting by default (`ThemeMode.system`), with the dark scheme generated from the same seed color (`ColorScheme.fromSeed(seedColor: Colors.indigo, brightness: Brightness.dark)`) rather than a separately hand-tuned dark palette. **Implemented (Phase 25) — G-01 closed:** `CompanyApp`'s `MaterialApp.router` now supplies both `theme`/`darkTheme` plus `themeMode: ThemeMode.system`, exactly as specified here.

### Typography — Flutter's stock Material 3 `TextTheme`, no custom font

| Guideline role | `TextTheme` |
|---|---|
| Display/page heading | `headlineSmall` |
| Section heading | `titleMedium` |
| Title (list/card item) | `titleSmall` |
| Body text | `bodyMedium` |
| Label (field label, chip text) | `labelLarge` |
| Metadata/supporting text | `bodySmall` + `onSurfaceVariant` color |
| Button/action | `labelLarge` (Material's own default) |

No custom font family — the platform default is retained; a custom font is a future decision only if a real product/brand reason emerges.

### Spacing — one 4px-based scale

| Token | Value | Typical use |
|---|---|---|
| `xs` | 4px | Icon-to-label gaps |
| `sm` | 8px | Label-to-field, chip padding |
| `md` | 16px | Screen padding, form-field spacing, card padding |
| `lg` | 24px | Section spacing, spacing before a primary action |
| `xl` | 32px | Spacing between major page regions |
| `xxl` | 48px | Large empty-state/hero spacing |

`md`/`lg` are already the de facto values `LoginPage` uses informally — this scale formalizes, rather than changes, that existing pattern. List density defaults to Material's standard comfortable sizing; a denser variant is a decision for whichever module first demonstrates a real need, not decided speculatively here. *(Phase 28's Staff directory, the predicted candidate, uses standard density; a ~100-person, paged and searchable list did not need a denser variant.)*

### Components — baseline conventions, not a library

| Element | Convention |
|---|---|
| Primary button | `FilledButton` |
| Secondary button | `OutlinedButton` |
| Tertiary/inline action | `TextButton` |
| Destructive action | Same button types, styled with the `error` role — never a hardcoded red |
| Text field | `TextFormField` with `OutlineInputBorder` |
| Card/container | Material `Card`, default M3 shape/elevation, `md` (16px) padding |
| Dialog | `AlertDialog` — used for destructive-action confirmation |
| Sheet | `showModalBottomSheet` for lightweight contextual actions |
| Chip/status indicator | One status-color role **plus a text label, always** — never a color-only dot |
| App bar | Standard `AppBar`, one consistent title style, at most 1–2 action icons |
| List row | `ListTile`-based rows |
| Empty state | Icon + short message + optional primary action, centered |
| Loading state | `CircularProgressIndicator` — no shimmer/skeleton library |
| Error state | Inline text/banner in the `error` role, with a retry action when retryable |
| Success/confirmation | `SnackBar` for transient confirmations — no custom toast library |

## Accessibility Baseline (resolved, DEC-046)

**Target: WCAG 2.2 AA principles, applied where they translate to Flutter/mobile, plus native Flutter/platform accessibility practice.** This is an engineering/design baseline for future work, not a claim of formal certification or a compliance audit process.

- **Color contrast:** WCAG AA ratios (4.5:1 normal text, 3:1 large text/UI). Material 3's seed-generated role pairs meet this by construction; any new semantic color (the status-color extension above) must be spot-checked against both light and dark surfaces when defined.
- **Minimum touch targets:** 48×48dp (Material's own standard).
- **Text scaling:** support the OS text-scale factor up to at least 200% without clipped/overlapping text.
- **Semantic labels:** every icon-only control gets a `tooltip`/`semanticLabel` (the existing logout button's `tooltip` is the pattern to continue).
- **Screen-reader-friendly controls:** real Flutter/Material widgets, not a bespoke `GestureDetector`-on-`Container` that loses built-in semantics.
- **Meaningful focus/traversal order:** matches the visual top-to-bottom, natural-reading-order layout.
- **No color-only status communication:** every status indicator pairs color with a text label or icon.
- **Visible selected/disabled/error states:** Material's built-in state layers, never suppressed for a custom look.
- **Accessibility-aware loading/feedback:** stock `CircularProgressIndicator`/`SnackBar`, which already participate correctly in Flutter's semantics tree.
- **Avoid unnecessary motion:** no gratuitous custom animation; respect the OS-level reduce-motion signal where Flutter surfaces it.
- **Keyboard/focus support where relevant:** the existing `TextInputAction.next`/`.done` keyboard-chaining pattern in `LoginPage` should be preserved and extended to future forms.
- **Announce dynamic content:** an inline error/status message that appears after an action (as `LoginPage`'s already does) should be wrapped so assistive technology announces it, not merely rendered silently on-screen.

## Shared Interaction/State Conventions (Both Surfaces)

- **Loading / empty / error states are mandatory, not optional polish.** Every list/detail view needs a defined loading state, a defined "nothing here yet" empty state, and a defined error state before it's considered done — not just a happy-path implementation. Phase 4's login screens follow this: initial, submitting/loading, invalid-credential, and network-failure states are all handled — see `docs/handoffs/V1_PHASE_04_HANDOFF.md`.
- **Loading:** `CircularProgressIndicator`, centered for full-screen loads, inline (e.g. inside a button) for action-scoped loads.
- **Error:** inline text/banner in the semantic `error` role, with a retry affordance when retryable (network failures) and a plain message when not (validation errors).
- **Success/confirmation feedback:** `SnackBar` for transient, non-blocking confirmations.
- **Form behavior:** real `Form`/validated fields, inline per-field error text, submit disabled while in flight, keyboard chaining between fields (`LoginPage`'s existing pattern, generalized).
- **Confirmation for destructive actions.** Suspending staff, deleting a record, rejecting a leave request, resolving/closing an incident — anything hard to reverse gets an explicit `AlertDialog` confirmation step *before* the action, not a way to undo it after. Mirrors the engineering-side "check before destructive action" discipline in `CLAUDE.md`.
- **Status presentation.** A given status concept (e.g. "Pending," "Approved," "In Progress," "Resolved") should look and read the same way everywhere it appears across both the mobile app and the Admin Backoffice — one shared color-role+label(+icon) chip convention, reused for every status concept across every module, rather than each module inventing its own visual language.
- **Responsive/mobile behavior:** constrain form/content width on wider viewports (`LoginPage`'s existing `ConstrainedBox(maxWidth: 360)` pattern) rather than stretching every element edge-to-edge; scroll rather than clip when the keyboard reduces available height.
- **Accessibility.** Sufficient color contrast, readable type sizes, tappable/clickable target sizes, and semantic structure (labels on form fields, alt text where relevant) are baseline requirements for any shipped screen, not a later audit-only concern — see the resolved Accessibility Baseline above.

## Explicitly Not Covered Here

- Specific screen layouts/wireframes for any business module (Tasks, Schedule, Messages, Clients, Projects, Staff, Work Logs, Leave, Service Reports, Incident Reports, Announcements, Location/Check-in, Settings, or any other) — produced when each module's own UI phase is actually designed/built.
- A shared/reusable Flutter widget component library (e.g. an actual `StatusChip` class) — the Components table above is a set of conventions to follow, not code that exists yet.
- ~~The Admin Backoffice's own visual-design system~~ — **resolved by Phase 34A (DEC-063 W-5)**; see "Web Visual-Design Foundation".
- Detailed accessibility compliance certification (e.g. a formal WCAG audit/certification process) — the Accessibility Baseline above is a practical engineering/design target, deliberately not framed as certification.
