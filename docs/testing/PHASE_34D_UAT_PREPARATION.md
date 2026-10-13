# Phase 34D — UAT Preparation (Staff Web Portal: Communication — Deployment, Data and UAT)

*Operator runbook. No AI session has run anything in this file against the staging VPS; this AI session cannot reach it. Every step marked **[Operator]** is pending until its output is recorded. The data script in §6 was rehearsed on a disposable scratch SQLite database only (see `docs/testing/TEST_STATUS.md`, "Phase 34D — UAT preparation").*

**Status:** UAT-34D-01…06 are `NOT RUN`. **No APK is needed**: 34D changes no mobile code. **No account or password is created**: the scenarios use the UAT30B accounts already in your password manager.

**How each operator step is written:**
- **Where:** the machine, the user and the directory.
- **Run:** the exact commands, one block at a time.
- **Expected:** what a good result looks like, and when to **STOP**.
- **Paste back:** what to send to the AI session. **Never paste** a password, anything from your password manager, `.env.staging`, or anything containing `MYSQL_ROOT_PASSWORD`.

## 1. Baselines

| Item | SHA | Meaning |
|---|---|---|
| **UAT source baseline (deploy)** | `fd57c7c0a72984bcf8939419425b3f61d0347640` | PR #105 merge into `main`: Phase 34D. |
| Running staging implementation (before §2) | `0248615c11129da4fe65aaa72b0e7ff34688d24c` | Phase 34C. |

**What changes between them:**
- **Web only:** the Messages, conversation, New message, Notifications and Announcements pages, Home's links, and three navigation items. Everything else in between is documentation.
- **No migration** (still 45 Ran). `composer.lock`, `package-lock.json`, the Docker files and the environment variables are unchanged.
- **CI built both staging images** on this exact change (the "Build staging images" check on PR #105).

## 2. Staging deployment — **[Operator, VPS]**

**Where:** the VPS, as `deploy`, in `/home/deploy/company-app/company-app-build/company-app`. Run each block separately.

**Block 1: get the code**
```sh
cd /home/deploy/company-app/company-app-build/company-app
git status --porcelain
git fetch origin
git merge --ff-only fd57c7c0a72984bcf8939419425b3f61d0347640
git rev-parse HEAD
```
**Expected:** `git status --porcelain` prints nothing; then `Updating 0248615..fd57c7c` and `Fast-forward`; the last line is `fd57c7c0a72984bcf8939419425b3f61d0347640`. **If the `Updating` line starts from anything other than `0248615`: STOP** and paste it.

**Block 2: back up the database**
```sh
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
$C exec mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  > /home/deploy/backups/company-app-$(date +%Y%m%d-%H%M%S).sql
ls -lt /home/deploy/backups/company-app-*.sql | head -3
tail -c 200 "$(ls -t /home/deploy/backups/company-app-*.sql | head -1)" | grep -- '-- Dump completed'
```
**Expected:** the newest file is from now and not empty, and a `-- Dump completed on …` line appears.

**Block 3: build, on its own**
```sh
$C build app nginx && echo "BUILD OK"
```
**Expected:** the last line is `BUILD OK`. **`ERROR` or `failed to solve`: STOP** and paste the error lines; the running site stays as it is.

**Block 4: restart and check**
```sh
$C up -d --no-deps app nginx
$C exec app php artisan migrate:status | tail -3
$C exec app php artisan config:cache
$C exec app php artisan route:cache
$C exec app php artisan view:cache
$C exec app sh -c 'ls public/build/assets'
$C exec nginx sh -c 'ls /var/www/html/public/build/assets'
$C ps
```
**Expected:**
- the last migrations show `Ran`, with none pending;
- the same `app-….css` and `app-….js` names in both containers;
- in `$C ps`, `company-app-api` and `company-app-nginx` were both created seconds or minutes ago.

**Block 5: smoke tests**
```sh
B=https://company-staging.storm-ark.com
for p in app/messages app/messages/new app/notifications app/announcements; do
  curl -s -o /dev/null -w "$p %{http_code} -> %{redirect_url}\n" $B/$p
done
CSS=$(curl -s $B/login | grep -o '/build/assets/app-[A-Za-z0-9_-]*\.css' | head -1); curl -s -o /dev/null -w "css %{http_code} %{content_type}\n" "$B$CSS"
for p in conversations me/notifications me/announcements; do
  curl -s -o /dev/null -w "api $p %{http_code}\n" -H 'Accept: application/json' $B/api/v1/$p
done
```
**Expected:**
- the four pages each show `302 -> https://company-staging.storm-ark.com/login`;
- `css 200 text/css`;
- three `api … 401` lines.

**Anything else: STOP** and paste the output.

**Paste back:** the output of Blocks 1–5; for the build, its last few lines are enough.

## 3. UAT data plan

**Accounts:** `uat30b.staff` (Lia) and `uat30b.noprofile`; Ben and Kai only as other people in the chat. `D` is the company day of the last `seed`.

- **Group chat "UAT34D Cold room crew"** (Lia, Ben, Kai), four messages:
  1. Ben, about 26 hours before the seed: "Morning team. The cold room inspection is on Thursday."
  2. Lia, about 25 hours before: "Noted." / "I'll bring the checklist." (two lines)
  3. Kai, 3 hours before: "Compressor 1 is making a noise again."
  4. Ben, 2 hours before: "Lia, can you take a look before lunch?"

  Lia has read up to her own message, so **2 are unread** for her.
- **Announcements** (published by Ben, company-wide): **"[UAT34D] Holiday hours"** (5 hours before the seed, two lines) and **"[UAT34D] New parking rules"** (3 days before). Neither is acknowledged by Lia.
- **Notifications:**
  - Lia: "[UAT34D] New announcement" (Holiday hours, **unread**), "[UAT34D] New message in UAT34D Cold room crew" (**unread**), and "[UAT34D] New announcement" (New parking rules, already read);
  - the no-profile account: "[UAT34D] New announcement" (Holiday hours, unread).
- **`reply` stage:** sends "UAT34D reply from Ben at hh:mm" as Ben in the group chat, **through the real API**, so Lia also gets its notification. Used in UAT-34D-02.

**A `seed` resets only these records** (the chat's messages and read positions, Lia's acknowledgements of the two announcements, the `[UAT34D]` notifications). Chats you start in the apps are kept.

## 4. Staging data — **[Operator, VPS]**

**Where:** the VPS, as `deploy`, in the staging checkout, after §2.

**Step 0: get the script and check it**
```sh
cd /home/deploy/company-app/company-app-build/company-app
git fetch origin main
git show origin/main:docs/testing/PHASE_34D_UAT_PREPARATION.md | awk '/^```php$/{f=1;next} /^```$/{f=0} f' > "$HOME/uat34d_data.php"
sha256sum "$HOME/uat34d_data.php"
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
W="$HOME/uat34d_data.php"
$C exec -T app php -- plan < "$W"
```
**Expected:**
- the SHA equals the one in §6. **Any mismatch: STOP.**
- then:
```
stage=plan utc_now=<now> company_day=<D> (Asia/Manila)
staff     exists, staff=UAT30B-001
manager   exists, staff=UAT30B-002
colleague exists, staff=UAT30B-003
noprofile exists
UAT34D chat: absent, [UAT34D] announcements: 0, notifications: 0
```

**Step 1: seed and verify**
```sh
$C exec -T app php -- seed < "$W"; echo "seed exit=$?"
$C exec -T app php -- verify < "$W"
```
**Expected:**
```
stage=seed utc_now=<now> company_day=<D> (Asia/Manila)
seed: UAT34D chat (3 members, 4 messages, 2 unread for Lia), 2 [UAT34D] announcements (Lia: not acknowledged), 4 [UAT34D] notifications (Lia 2 unread + 1 read, no-profile 1) set for company day <D>
seed: done
seed exit=0
stage=verify utc_now=<now> company_day=<D> (Asia/Manila)
Lia chats: 200; UAT34D Cold room crew unread=2 last="Lia, can you take a look before lunch?" by Ben UAT30B-Cruz
Lia notifications: 200; [UAT34D] 3, unread 2
Lia announcements: 200; [UAT34D] Holiday hours (new), [UAT34D] New parking rules (new)
noprofile notifications: 200; [UAT34D] 1 (works without a profile)
noprofile /conversations: 403 (no-profile state: correct)
```
**STOP** on a non-zero exit, `ERROR`, `MISSING`, `UNEXPECTED`, or any line that differs.

**Paste back:** the `sha256sum` line and the three outputs.

**On a later UAT day, or to start a scenario again:** run `seed` and `verify` again.

## 5. UAT-time notes (for **execution**)

- **Browsers:** a desktop browser, plus your phone's browser for 34D-06, at `https://company-staging.storm-ark.com`.
- **Sign in as `uat30b.staff`** (Lia), pasting the password from your password manager into the sign-in page only. 34D-04 also uses `uat30b.noprofile`.
- **Phone app:** keep it signed in as Lia for the comparisons.
- **Keep a VPS terminal open** in the staging checkout, with `C` and `W` set as in §4, for the `reply` step in 34D-02.

**UAT-34D-01: Messages**
1. The navigation shows **… My status, Messages, Notifications, Announcements, Staff directory …**.
2. On **Home**, the **Messages** tile shows the unread count (at least 2). Click it: you're on **Messages**.
3. At the top is **UAT34D Cold room crew** with **"Ben: Lia, can you take a look before lunch?"**, its time ("hh:mm" if today, otherwise "Yesterday"), and a **2** badge.
4. The phone's Messages tab shows the same chat with the same preview, time and count.

**UAT-34D-02: A conversation**
1. Open **UAT34D Cold room crew**. Check:
   - messages oldest at the top, newest at the bottom;
   - day separators ("Yesterday", "Today");
   - Ben's and Kai's messages on the left with their names; Lia's ("Noted." / "I'll bring the checklist.") on the right, on two lines.
2. Go back to **Messages**: the **2** badge is gone (opening the chat read it).
3. Open the chat again. Type **`UAT34D web test`** and click **Send**. The message appears at the bottom on the right, and the box empties. The phone's chat shows it after a refresh.
4. **Leave the chat open in the browser.** On the VPS run:
   ```sh
   $C exec -T app php -- reply < "$W"
   ```
   **Expected:** `reply: sent as Ben: "UAT34D reply from Ben at hh:mm"`. **Without touching the browser**, the reply appears in the open chat within about **15 seconds**.
5. Click **Send** with an empty box: nothing is sent.

**UAT-34D-03: New message**
1. On **Messages**, click **New message**. Lia herself is **not** in the list.
2. Search **`UAT30B`**: the list narrows (the address bar shows `?q=UAT30B`). Click **Kai UAT30B-Tan**. You're in a chat titled **Kai UAT30B-Tan**.
3. Send **`UAT34D hello Kai`**.
4. Go to **Messages → New message**, choose **Kai UAT30B-Tan** again: you're in **the same chat**, with "UAT34D hello Kai" in it (not a new, empty one).
5. The phone's Messages tab shows the chat with Kai.

**UAT-34D-04: Notifications**
1. On **Home**, click the **Notifications** tile. Unread notifications are **bold** with a dot:
   - **"[UAT34D] New announcement"** (Holiday hours) is bold;
   - **"[UAT34D] New message in UAT34D Cold room crew"** and the notification for Ben's reply are **not** bold, because opening the chat in 34D-02 also read that chat's notifications (as on the phone);
   - "[UAT34D] New announcement" for New parking rules is not bold (seeded as read).
2. Click the bold **"[UAT34D] New announcement"** (Holiday hours): the **Holiday hours** announcement opens. Go back to Notifications: it's no longer bold.
3. Click **"[UAT34D] New message in UAT34D Cold room crew"**: the group chat opens.
4. **Mark all read:** if anything is still bold (for example from chats outside this test), click **Mark all read**: **"All marked read."** Nothing is bold, and the button disappears. With nothing unread, the button isn't shown. The phone's Notifications (pull to refresh) shows them all read. *To see the button work on its own, run `seed` again on the VPS first: it makes two of Lia's notifications unread again.*
5. Log out. Sign in as **`uat30b.noprofile`**: **Notifications** shows "[UAT34D] New announcement"; **Messages** and **Announcements** say **"No staff profile is linked to this account."** Log out and sign in as Lia again.

**UAT-34D-05: Announcements**
1. Click **Announcements**. **[UAT34D] Holiday hours** (newest) and **[UAT34D] New parking rules** both show their date and **New**, in bold.
2. Open **New parking rules**. It shows "Ben UAT30B-Cruz · <date> <time>" and the text. Click **Acknowledge**: **"✓ Acknowledged on <today> <time>"** replaces the button.
3. Back on **Announcements**: New parking rules no longer shows New. On the phone (More → Announcements, pull to refresh) it's acknowledged too.
4. On **Home**, **Latest announcements** lists Holiday hours; click it to open it. Click **All announcements** to reach the list.

**UAT-34D-06: Display and keyboard**
1. **Dark mode:** Messages, a chat, New message, Notifications, Announcements and an announcement are readable.
2. **Zoom:** at **200%**, the navigation wraps, nothing scrolls sideways, and every button can be clicked.
3. **Phone browser:** the same pages are readable and easy to tap, with no sideways scrolling; a chat's messages don't overflow.
4. **Keyboard only** (desktop): in a chat, Tab reaches the message box and Send; Enter on Send sends. On Notifications, Tab reaches each notification and Mark all read, with a visible outline.

**Afterwards:** the UAT34D records stay on staging (cleanup needs its own authorization). Report each scenario as **PASS**, or **FAIL with what you saw**. Only you record a `PASS`.

## 6. Script — `uat34d_data.php`

**Revision 1 (2026-10-13), SHA-256 `ee6507336d05ff7d54193da926ade0680cd5dce59d8a630bb5c536206414a9b5`** (the exact text below, with a trailing newline). It requires `uat30b_data.php`'s accounts. Rehearsed on scratch SQLite: `plan`, `seed`, `verify`, `reply` (through the real API; the unread count rose to 3), a re-seed (an acknowledgement and the reply were reset; identical `verify`), and an unknown stage (exit 1).

```php
<?php

/*
 * Phase 34D UAT data — operator tool (NOT application code; never commit
 * to apps/api, never expose over HTTP).
 *
 * Uses the UAT30B accounts made by uat30b_data.php: Lia (staff), Ben
 * (manager), Kai (staff) and the account without a profile. It creates NO
 * account and prints NO password.
 *
 * Run inside the staging `app` container, reading this file from stdin:
 *
 *   $C exec -T app php -- <stage> < uat34d_data.php
 *
 * Stages:
 *   plan    read-only: the UAT30B accounts and what UAT34D data exists
 *   seed    idempotent; sets the "UAT34D Cold room crew" group chat (Lia,
 *           Ben, Kai) and its messages (2 unread for Lia), two published
 *           "[UAT34D]" announcements (Lia's acknowledgements reset), and
 *           "[UAT34D]" notifications for Lia and the no-profile account.
 *           Only these records are replaced; nothing else is touched.
 *   reply   sends one message as Ben in the UAT34D group chat, through the
 *           real API (so Lia also gets its notification) — for UAT-34D-02
 *   verify  read-only: runs the real controllers as Lia and prints what the
 *           web portal and the phone see
 *
 * No secret is ever printed. Any failure exits 1; seed changes nothing
 * unless it completes (one transaction).
 */

use App\Enums\AnnouncementAudienceType;
use App\Enums\AnnouncementStatus;
use App\Enums\ConversationType;
use App\Enums\NotificationSourceType;
use App\Enums\NotificationType;
use App\Http\Controllers\Api\V1\Announcements\MyAnnouncementController;
use App\Http\Controllers\Api\V1\Messaging\ConversationController;
use App\Http\Controllers\Api\V1\Notifications\NotificationController;
use App\Models\Announcement;
use App\Models\AnnouncementAcknowledgement;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\Notification;
use App\Models\User;
use App\Services\Portal\InternalApi;
use App\Support\CompanyTimezone;
use App\Support\Reporting\OverdueTasks;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, 'ERROR '.$e::class.': '.preg_replace('/ \(Connection: .*$/s', '', $e->getMessage()).PHP_EOL);
    exit(1);
});

$stage = $argv[1] ?? 'plan';
$out = fn (string $line) => fwrite(STDOUT, $line.PHP_EOL);

const T34D = '[UAT34D] ';
const CHAT = 'UAT34D Cold room crew';

$emails = [
    'staff' => 'uat30b.staff@company-app.test',
    'manager' => 'uat30b.manager@company-app.test',
    'colleague' => 'uat30b.colleague@company-app.test',
    'noprofile' => 'uat30b.noprofile@company-app.test',
];

$tz = CompanyTimezone::value();
$today = OverdueTasks::todayInCompanyTimezone();
$out("stage={$stage} utc_now=".now('UTC')->toIso8601String()." company_day={$today} ({$tz})");

$users = collect($emails)->map(fn ($e) => User::query()->where('email', $e)->first());
$need = function () use ($users, $out): void {
    if ($users->contains(null) || collect(['staff', 'manager', 'colleague'])->contains(fn ($k) => $users[$k]->staff === null)) {
        $out('UAT30B accounts missing or without staff records: run uat30b_data.php seed first; nothing changed');
        exit(1);
    }
};
$chat = fn () => Conversation::query()->where('type', ConversationType::Group)->where('name', CHAT)->first();

/** Calls a real controller action as $user with a GET query; returns [status, body]. */
$call = function (User $user, callable $invoke, array $query = []): array {
    Auth::guard('web')->setUser($user);
    $request = Request::create('/uat', 'GET', $query);
    $request->setUserResolver(fn () => $user);
    app()->instance('request', $request);
    try {
        $result = $invoke($request);
    } catch (HttpException $e) {
        return [$e->getStatusCode(), ['message' => $e->getMessage()]];
    }
    $response = $result instanceof Responsable ? $result->toResponse($request) : $result;

    return [$response->getStatusCode(), json_decode($response->getContent(), true)];
};

if ($stage === 'plan') {
    foreach ($users as $key => $u) {
        $out(str_pad($key, 10).($u ? 'exists'.($key === 'noprofile' ? '' : ', staff='.($u->staff ? $u->staff->employee_number : 'none')) : 'ABSENT (run uat30b_data.php seed first)'));
    }
    $c = $chat();
    $out('UAT34D chat: '.($c ? 'exists, messages '.$c->messages()->count() : 'absent')
        .', [UAT34D] announcements: '.Announcement::query()->where('title', 'like', T34D.'%')->count()
        .', notifications: '.Notification::query()->where('title', 'like', T34D.'%')->count());
    exit(0);
}

if ($stage === 'seed') {
    $need();
    $lia = $users['staff']->staff;
    $ben = $users['manager']->staff;
    $kai = $users['colleague']->staff;
    $now = now();

    DB::transaction(function () use ($lia, $ben, $kai, $users, $chat, $now, $today, $out): void {
        // The group chat: Lia, Ben and Kai; its messages are replaced.
        $c = $chat() ?? Conversation::query()->create(['type' => ConversationType::Group, 'name' => CHAT, 'owner_staff_id' => $ben->id]);
        foreach ([$lia, $ben, $kai] as $s) {
            ConversationMember::query()->firstOrCreate(['conversation_id' => $c->id, 'staff_id' => $s->id]);
        }
        ConversationMember::query()->where('conversation_id', $c->id)->update(['last_read_message_id' => null]);
        $c->messages()->delete();
        $messages = [];
        foreach ([
            [$ben, 'Morning team. The cold room inspection is on Thursday.', $now->copy()->subDay()->subHours(2)],
            [$lia, "Noted.\nI'll bring the checklist.", $now->copy()->subDay()->subHour()],
            [$kai, 'Compressor 1 is making a noise again.', $now->copy()->subHours(3)],
            [$ben, 'Lia, can you take a look before lunch?', $now->copy()->subHours(2)],
        ] as [$sender, $body, $at]) {
            $m = new Message(['conversation_id' => $c->id, 'sender_staff_id' => $sender->id, 'body' => $body]);
            $m->created_at = $at;
            $m->updated_at = $at;
            $m->save();
            $messages[] = $m;
        }
        // Lia has read up to her own message: Kai's and Ben's latest are unread (2).
        ConversationMember::query()->where('conversation_id', $c->id)->where('staff_id', $lia->id)->update(['last_read_message_id' => $messages[1]->id]);
        ConversationMember::query()->where('conversation_id', $c->id)->whereIn('staff_id', [$ben->id, $kai->id])->update(['last_read_message_id' => $messages[3]->id]);

        // Two published announcements; Lia's acknowledgements reset.
        $announcements = [];
        foreach ([
            ['Holiday hours', "The office closes at 3 pm on Friday.\nEnjoy the long weekend.", $now->copy()->subHours(5)],
            ['New parking rules', 'From Monday, staff parking moves to level B2.', $now->copy()->subDays(3)],
        ] as [$title, $body, $at]) {
            $a = Announcement::query()->firstOrNew(['title' => T34D.$title]);
            $a->fill([
                'body' => $body, 'status' => AnnouncementStatus::Published, 'audience_type' => AnnouncementAudienceType::CompanyWide,
                'published_at' => $at, 'created_by_user_id' => $users['manager']->id, 'published_by_user_id' => $users['manager']->id,
            ])->save();
            AnnouncementAcknowledgement::query()->where('announcement_id', $a->id)->where('staff_id', $lia->id)->delete();
            $announcements[$title] = $a;
        }

        // Notifications: Lia's two unread (an announcement, the chat) and one read; one for the no-profile account.
        Notification::query()->where('title', 'like', T34D.'%')->whereIn('recipient_user_id', [$users['staff']->id, $users['noprofile']->id])->delete();
        foreach ([
            [$users['staff'], NotificationType::AnnouncementPublished, 'New announcement', 'Holiday hours', NotificationSourceType::Announcement, $announcements['Holiday hours']->public_id, null, $now->copy()->subHours(5)],
            [$users['staff'], NotificationType::MessageReceived, 'New message in '.CHAT, 'Lia, can you take a look before lunch?', NotificationSourceType::Conversation, $c->public_id, null, $now->copy()->subHours(2)],
            [$users['staff'], NotificationType::AnnouncementPublished, 'New announcement', 'New parking rules', NotificationSourceType::Announcement, $announcements['New parking rules']->public_id, $now->copy()->subDays(2), $now->copy()->subDays(3)],
            [$users['noprofile'], NotificationType::AnnouncementPublished, 'New announcement', 'Holiday hours', NotificationSourceType::Announcement, $announcements['Holiday hours']->public_id, null, $now->copy()->subHours(5)],
        ] as [$user, $type, $title, $message, $sourceType, $sourceId, $readAt, $at]) {
            $n = new Notification([
                'recipient_user_id' => $user->id, 'type' => $type, 'title' => T34D.$title, 'message' => $message,
                'source_type' => $sourceType, 'source_public_id' => $sourceId, 'read_at' => $readAt,
            ]);
            $n->created_at = $at;
            $n->updated_at = $at;
            $n->save();
        }

        $out("seed: UAT34D chat (3 members, 4 messages, 2 unread for Lia), 2 [UAT34D] announcements (Lia: not acknowledged), 4 [UAT34D] notifications (Lia 2 unread + 1 read, no-profile 1) set for company day {$today}");
    });
    $out('seed: done');
    exit(0);
}

if ($stage === 'reply') {
    $need();
    $c = $chat();
    if ($c === null) {
        $out('UAT34D chat absent: run seed first; nothing changed');
        exit(1);
    }
    $ben = $users['manager'];
    Auth::guard('web')->setUser($ben);
    app()->instance('request', Request::create('/uat', 'GET'));
    $body = 'UAT34D reply from Ben at '.now($tz)->format('H:i');
    $sent = app(InternalApi::class)->send('POST', '/conversations/'.$c->public_id.'/messages', json: ['body' => $body])['data'] ?? null;
    $out('reply: sent as Ben: "'.($sent['body'] ?? 'MISSING — UNEXPECTED').'"');
    exit(0);
}

if ($stage === 'verify') {
    $need();
    $lia = $users['staff'];
    $myId = $lia->staff->public_id;

    [$s, $b] = $call($lia, fn ($r) => app(ConversationController::class)->index($r), ['per_page' => 100]);
    $c = collect($b['data'] ?? [])->firstWhere('name', CHAT);
    $out("Lia chats: {$s}; ".($c ? CHAT.' unread='.$c['unread_count'].' last="'.($c['last_message']['body'] ?? '').'" by '.(($c['last_message']['sender']['public_id'] ?? null) === $myId ? 'Lia' : ($c['last_message']['sender']['name'] ?? '?')) : 'UAT34D chat MISSING — UNEXPECTED'));
    [$s, $b] = $call($lia, fn ($r) => app(NotificationController::class)->myIndex($r), ['per_page' => 100]);
    $mine = collect($b['data'] ?? [])->filter(fn ($n) => str_starts_with($n['title'], T34D));
    $out("Lia notifications: {$s}; [UAT34D] ".$mine->count().', unread '.$mine->whereNull('read_at')->count());
    [$s, $b] = $call($lia, fn ($r) => app(MyAnnouncementController::class)->myIndex($r), ['per_page' => 100]);
    $out("Lia announcements: {$s}; ".collect($b['data'] ?? [])->filter(fn ($a) => str_starts_with($a['title'], T34D))->map(fn ($a) => $a['title'].($a['acknowledged_at'] ? ' (acknowledged)' : ' (new)'))->implode(', '));
    [$s, $b] = $call($users['noprofile'], fn ($r) => app(NotificationController::class)->myIndex($r), ['per_page' => 100]);
    $out("noprofile notifications: {$s}; [UAT34D] ".collect($b['data'] ?? [])->filter(fn ($n) => str_starts_with($n['title'], T34D))->count().' (works without a profile)');
    [$s] = $call($users['noprofile'], fn ($r) => app(ConversationController::class)->index($r));
    $out("noprofile /conversations: {$s}".($s === 403 ? ' (no-profile state: correct)' : ' — UNEXPECTED'));
    exit(0);
}

$out("unknown stage: {$stage}");
exit(1);
```
