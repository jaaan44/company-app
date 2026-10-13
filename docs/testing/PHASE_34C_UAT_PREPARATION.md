# Phase 34C — UAT Preparation (Staff Web Portal: HR & Operations — Deployment, Data and UAT)

*Operator runbook. No AI session has run anything in this file against the staging VPS; this AI session cannot reach it. Every step marked **[Operator]** is pending until its output is recorded. The data script in §6 was rehearsed on a disposable scratch SQLite database only (see `docs/testing/TEST_STATUS.md`, "Phase 34C — UAT preparation").*

**Status:** UAT-34C-01…07 are `NOT RUN`. **No APK is needed**: 34C changes no mobile code. **No account or password is created**: the scenarios use the UAT30B accounts already in your password manager.

**How each operator step is written:**
- **Where:** the machine, the user and the directory.
- **Run:** the exact commands, one block at a time.
- **Expected:** what a good result looks like, and when to **STOP**.
- **Paste back:** what to send to the AI session. **Never paste** a password, anything from your password manager, `.env.staging`, or anything containing `MYSQL_ROOT_PASSWORD`.

## 1. Baselines

| Item | SHA | Meaning |
|---|---|---|
| **UAT source baseline (deploy)** | `0248615c11129da4fe65aaa72b0e7ff34688d24c` | PR #101 merge into `main`: Phase 34C. |
| Running staging implementation (before §2) | `2a7bca9b50ae91738c3759e36bb5568459161911` | Phase 34B. |

**What changes between them:**
- **Web only:** the Leave, Team requests and My status pages, and two navigation items. The rest is documentation.
- **No migration** (still 45 Ran). `composer.lock`, `package-lock.json`, the Docker files and the environment variables are unchanged.
- **CI built both staging images** on this exact change (the "Build staging images" check on PR #101).

## 2. Staging deployment — **[Operator, VPS]**

**Where:** the VPS, as `deploy`, in `/home/deploy/company-app/company-app-build/company-app`. Run each block separately.

**Block 1: get the code**
```sh
cd /home/deploy/company-app/company-app-build/company-app
git status --porcelain
git fetch origin
git merge --ff-only 0248615c11129da4fe65aaa72b0e7ff34688d24c
git rev-parse HEAD
```
**Expected:** `git status --porcelain` prints nothing; then `Updating 2a7bca9..0248615` and `Fast-forward`; the last line is `0248615c11129da4fe65aaa72b0e7ff34688d24c`.

**Block 2: back up the database**
```sh
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
$C exec mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  > /home/deploy/backups/company-app-$(date +%Y%m%d-%H%M%S).sql
ls -lt /home/deploy/backups/company-app-*.sql | head -3
tail -c 200 "$(ls -t /home/deploy/backups/company-app-*.sql | head -1)" | grep -- '-- Dump completed'
```
**Expected:** the newest file is from now and not empty, and a `-- Dump completed on …` line appears. The `Using a password on the command line` warning is normal.

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
for p in app/leave app/leave/new app/leave/team app/status app/status/changes app/status/check-ins; do
  curl -s -o /dev/null -w "$p %{http_code} -> %{redirect_url}\n" $B/$p
done
CSS=$(curl -s $B/login | grep -o '/build/assets/app-[A-Za-z0-9_-]*\.css' | head -1); curl -s -o /dev/null -w "css %{http_code} %{content_type}\n" "$B$CSS"
for p in me/leave-requests me/leave-balances me/team-leave-requests me/status me/check-ins; do
  curl -s -o /dev/null -w "api $p %{http_code}\n" -H 'Accept: application/json' $B/api/v1/$p
done
```
**Expected:**
- the six pages each show `302 -> https://company-staging.storm-ark.com/login`;
- `css 200 text/css`;
- five `api … 401` lines.

**Anything else: STOP** and paste the output. For example, a `404` on a page means the `app` container still runs old code (check Block 3 and `$C ps`).

**Paste back:** the output of Blocks 1–5; for the build, its last few lines are enough.

## 3. UAT data plan

**Accounts:** `uat30b.staff` (Lia) and `uat30b.manager` (Ben), who manages Lia and Kai. Kai appears only as a name on his requests. `D` is the company day of the last `seed`.

**Leave types:** the two Phase 31 types, **UAT31 Annual Leave** (paid; Lia has 15 days this year) and **UAT31 Unpaid Leave**. The seed sets them exactly as Phase 31's seed did.

**Requests** (the reason starts with `[UAT34C]`; the pages show the type and dates, and the reason on the request page):

| Whose | Type | Dates | Status | Reason | History |
|---|---|---|---|---|---|
| Lia | UAT31 Annual Leave | D+30 – D+31 | Pending | Training course | Requested |
| Lia | UAT31 Annual Leave | D+40 | Approved | Check-up | Requested; Approved by Ben, "Fine, enjoy." |
| Lia | UAT31 Unpaid Leave | D−1 – D+1 | Approved (**already started**) | Site visit week | Requested; Approved by Ben |
| Lia | UAT31 Unpaid Leave | D−12 | Rejected | Long weekend | Requested; Rejected by Ben, "Stock count that day." |
| Kai | UAT31 Annual Leave | D+21 – D+22 | Pending | Moving house | Requested |
| Kai | UAT31 Unpaid Leave | D+25 | Pending | Exam | Requested |

**Lia's check-ins:**
- "UAT34C Client site": 90 minutes before the seed, ±12 m, note "[UAT34C] Gate B", at 14.55471, 121.02445;
- no place name: 3 days before the seed, note "[UAT34C] No signal at the dock", at 14.58660, 121.06140.

**Statuses** aren't seeded: the scenarios set them on the web and the phone.

**A `seed` resets only the `[UAT34C]` requests and check-ins** for the day it runs. Requests, statuses and check-ins made in the apps, and earlier rounds' data, are kept. Because those earlier requests count too, **the balance and pending numbers on staging are larger than the `[UAT34C]` ones alone**: compare the web with the phone, not with a fixed number.

## 4. Staging data — **[Operator, VPS]**

**Where:** the VPS, as `deploy`, in the staging checkout, after §2.

**Step 0: get the script and check it**
```sh
cd /home/deploy/company-app/company-app-build/company-app
git fetch origin main
git show origin/main:docs/testing/PHASE_34C_UAT_PREPARATION.md | awk '/^```php$/{f=1;next} /^```$/{f=0} f' > "$HOME/uat34c_data.php"
sha256sum "$HOME/uat34c_data.php"
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
W="$HOME/uat34c_data.php"
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
company year: <YYYY>
UAT31 leave types: 2 of 2
[UAT34C] leave requests: 0, check-ins: 0
```
**STOP** on `ABSENT` or a `WARNING` on the `company year` line (the leave dates would cross into next year).

**Step 1: seed and verify**
```sh
$C exec -T app php -- seed < "$W"; echo "seed exit=$?"
$C exec -T app php -- verify < "$W"
```
**Expected** (`<…>` are numbers that include earlier rounds' data):
```
stage=seed utc_now=<now> company_day=<D> (Asia/Manila)
seed: UAT31 types and Lia's balance (15 for <YYYY>); [UAT34C] 4 requests for Lia, 2 pending for Kai, 2 check-ins for Lia, set for company day <D>
seed: done
seed exit=0
stage=verify utc_now=<now> company_day=<D> (Asia/Manila)
Lia balances: 200; UAT31 Annual <n> left of 15 (<used> used, <pending> pending), year <YYYY>
Lia requests: 200; [UAT34C] <D+40> approved, <D+30> pending, <D-1> approved, <D-12> rejected
Lia team: 200; can_review=false reports=0 (no Team requests)
Ben team: 200; can_review=true reports=2 pending=<p>; [UAT34C] Kai UAT30B-Tan <D+21>, Kai UAT30B-Tan <D+25>, Lia UAT30B-Ramos <D+30>
Lia status: 200; current <a status or none>, changes <n>
Lia check-ins: 200; total <n>, [UAT34C] UAT34C Client site, No place name
```
- **The `<n> left of 15` must be at least 1**, so that UAT-34C-02 can request one Annual day. If it's 0 or below, it's still fine: use **UAT31 Unpaid Leave** in UAT-34C-02 instead.
- `pending=<p>` is 3 or more: Kai's two, Lia's Training course, and any still pending from earlier rounds.

**STOP** on a non-zero exit, `ERROR`, `MISSING`, or a `[UAT34C]` part that differs.

**Paste back:** the `sha256sum` line and the three outputs. They contain no secrets.

**On a later UAT day, or to start a scenario again:** run `seed` and `verify` again. Nothing else is needed.

## 5. UAT-time notes (for **execution**)

- **Browsers:** a desktop browser, plus your phone's browser for 34C-07, at `https://company-staging.storm-ark.com`.
- **Accounts:** `uat30b.staff` (Lia) and `uat30b.manager` (Ben). Paste a password from your password manager **only into the sign-in page's password field**. Use **Log out** (or a private window) before switching accounts.
- **Phone app:** keep it signed in as Lia for the comparisons.
- Dates below are relative to `D`, the seed day. If you run UAT on a later day, run `seed` again first.

**UAT-34C-01: Leave overview** (as Lia)
1. The navigation shows **… Clients, Leave, My status, Staff directory …**. Click **Leave**.
2. **Balances <YYYY>:** "UAT31 Annual Leave" shows "N days left of 15" and "X used · Y pending"; "UAT31 Unpaid Leave" shows **"Unpaid — no balance"**. The numbers match the phone's Leave screen (More → Leave).
3. **My requests** lists, among others, the four seeded ones with their dates, days and status: D+40 **Approved**, D+30 – D+31 **Pending**, D−1 – D+1 **Approved**, D−12 **Rejected**, in the same order as on the phone.
4. There is **no "Team requests"** entry for Lia.
5. Log out. Sign in as **Ben** and click **Leave**. A **Team requests** entry shows **"N pending"**, where N equals `pending=<p>` from the `verify` output (at least 3). Log out.

**UAT-34C-02: Request leave** (as Lia)
1. Click **Request leave**, then **Send request** with nothing filled in. You see **"Choose a type of leave."**, **"Choose the first day."**, **"Choose the last day."** and **"Give a reason (at least 3 characters)."**
2. Choose **UAT31 Annual Leave**. The list shows "N days left in <YYYY>".
3. Set the **first day to D+45**. The last day fills in with the same date, and **"1 calendar day (weekends included)"** appears.
4. Set the **last day** to the day before the first. Click Send request: **"The last day cannot be before the first day."**
5. Set the **last day to 2 January next year**. Click Send request: **"A request must stay within one calendar year. Make one request for each year."**
6. Set the last day back to D+45 and type the reason **`ab`**. Click Send request: the reason message again.
7. Change the reason to **`UAT34C web test`** and click Send request. You're on Leave with **"Leave requested."**, and a new **Pending** row for D+45 is at the top of My requests.
8. Pull to refresh the phone's Leave: the same request is there, Pending.

If step 7 shows a message about the balance, the seeded and earlier requests have used up the Annual days. Choose **UAT31 Unpaid Leave** and send again.

**UAT-34C-03: Cancel** (as Lia)
1. Open the **D+30 – D+31 Pending** request ("Training course"). It shows UAT31 Annual Leave, **Paid**, **Pending**, "2 calendar days (weekends included)", the reason, and **History**: "Requested by Lia UAT30B-Ramos · …".
2. Click **Cancel request**. A panel asks **"Cancel this request?"** with "Your manager will no longer see it as pending." and **Reason (optional)**. Click **Keep it**: the panel closes and nothing changes.
3. Click **Cancel request** again, type **`UAT34C cancel test`**, and click the panel's **Cancel request**. You see **"Request cancelled."** The status is **Cancelled**, the Cancel button is gone, and History ends with "Cancelled by Lia UAT30B-Ramos · Today hh:mm" and "UAT34C cancel test".
4. Go back and open the **D+40 Approved** request ("Check-up"). History shows "Approved by Ben UAT30B-Cruz · …" with "Fine, enjoy.". Click **Cancel request**: the panel says **"Your approved leave will be cancelled."** Click **Keep it** (don't cancel this one).
5. Open the **D−1 – D+1 Approved** request ("Site visit week", Unpaid): there is **no Cancel request**, because it has started. Open the **D−12 Rejected** one: no Cancel, and History shows "Rejected by Ben UAT30B-Cruz" with "Stock count that day."
6. On the phone, the Training course request is **Cancelled**.

**UAT-34C-04: Team requests and Approve** (as Ben)
1. Sign in as Ben. Click **Leave → Team requests**. **Pending (N)** lists his direct reports' pending requests, soonest first, with the person's name, the type, the dates and days. It includes Kai's D+21 – D+22 and D+25 requests and Lia's D+45 "UAT34C web test"; Lia's cancelled Training course is no longer there.
2. Click **Approved**, **Rejected** and **All**: each list changes; **All** includes Lia's cancelled request. Click **Pending (N)** again.
3. Open **Lia's D+45** request. It shows **Requested by Lia UAT30B-Ramos**, the dates, the reason, and the **Approve** and **Reject** buttons (no Cancel).
4. Click **Approve**. The panel asks **"Approve this request?"** with **Note (optional)**. Type **`UAT34C approve test`** and click **Approve**. You see **"Request approved."**, the status is **Approved**, the buttons are gone, and History ends with "Approved by Ben UAT30B-Cruz · Today hh:mm" and "UAT34C approve test".
5. On the phone (as Lia), pull to refresh Leave: the D+45 request is **Approved**, with Ben's note in its history.
6. **Copy the address** of this team request page from the browser's address bar (it ends `/app/leave/team/…`). You need it in 34C-05.

**UAT-34C-05: Reject, and access** (as Ben, then Lia)
1. Back on **Team requests**, open **Kai's D+25** request ("Exam", Unpaid). Click **Reject**. The panel asks **"Reject this request?"** with **Reason**.
2. Type **`no`** and click **Reject**: **"Give a reason (at least 3 characters)."**, and nothing is rejected.
3. Change it to **`UAT34C reject test`** and click **Reject**: **"Request rejected."**, the status is **Rejected**, and History shows the reason.
4. Leave Kai's D+21 – D+22 request **Pending**. Log out.
5. Sign in as **Lia** and paste the address you copied in 34C-04 step 6 into the address bar. The page says **"You don't have access to this request."** and shows none of its details.

**UAT-34C-06: My status** (as Lia)
1. Click **My status**. It shows **Current status** (the latest status set on the phone, with "Set …"), or **"No status set yet"**.
2. Under **Change status**, click **Busy**. **"Status set to Busy."** appears and Busy is highlighted. Pull to refresh the phone's My status: **Busy**.
3. On the phone, change the status to **In the field**. Reload the web page: **In the field**, "Set Today hh:mm".
4. **Recent status changes** lists the newest five, starting with In the field and Busy, matching the phone. Click **All status changes**: the full list, 20 per page, with **Previous / Next** if there are more than 20.
5. **Recent check-ins** includes:
   - **UAT34C Client site**, "<time> · ±12 m", "[UAT34C] Gate B", "14.55471, 121.02445";
   - **No place name**, "<day, 3 days ago> <time>", "[UAT34C] No signal at the dock", "14.58660, 121.06140".

   Click **All check-ins**: the full list, matching the phone's.
6. There is **no Check in button** on the web. The page says "Check in from the Company App on your phone."

**UAT-34C-07: Display and keyboard**
1. **Dark mode:** Leave, Request leave, a request with its Cancel panel, Team requests (as Ben), a team request, My status and both All pages are readable.
2. **Zoom:** at **200%**, the navigation wraps, nothing scrolls sideways, and every button can be clicked.
3. **Phone browser:** open the same pages on your phone's browser. They are readable and easy to tap, with no sideways scrolling.
4. **Keyboard only** (desktop): on a request, Tab to **Cancel request** and press Enter. Focus moves into the reason field; Tab reaches the panel's buttons, and every control shows a visible outline.

**Afterwards:** the `[UAT34C]` records stay on staging (cleanup needs its own authorization). Kai's D+21 – D+22 request stays Pending. Report each scenario as **PASS**, or **FAIL with what you saw**. Only you record a `PASS`.

## 6. Script — `uat34c_data.php`

**Revision 1 (2026-10-13), SHA-256 `3e0f0e0c7cc633110fc5d9948945021b0f2151a0b1202e2adb758570a771a309`** (the exact text below, with a trailing newline). It requires `uat30b_data.php`'s accounts. Rehearsed on scratch SQLite: `plan`, `seed`, `verify`, a re-seed (idempotent; a request made in the app is kept; a changed `[UAT34C]` status is reset; check-ins replaced, not duplicated), and an unknown stage (exit 1).

```php
<?php

/*
 * Phase 34C UAT data — operator tool (NOT application code; never commit
 * to apps/api, never expose over HTTP).
 *
 * Uses the UAT30B accounts made by uat30b_data.php: Lia (staff), Ben
 * (manager, Lia's and Kai's) and Kai (staff). It creates NO account and
 * prints NO password.
 *
 * Run inside the staging `app` container, reading this file from stdin:
 *
 *   $C exec -T app php -- <stage> < uat34c_data.php
 *
 * Stages:
 *   plan    read-only: the UAT30B accounts, the company day, and what
 *           UAT34C data already exists
 *   seed    idempotent; sets the two UAT31 leave types and Lia's UAT31
 *           Annual Leave balance (15 days this year, as Phase 31's seed
 *           does), replaces only "[UAT34C]" leave requests (with their
 *           history) and "[UAT34C]" check-ins, for the company day it runs
 *           on. Nothing else is touched: requests, statuses and check-ins
 *           made in the apps are kept.
 *   verify  read-only: runs the real controllers as Lia and Ben and
 *           prints what the web portal and the phone see
 *
 * No secret is ever printed. Any failure exits 1 and changes nothing
 * (one transaction).
 */

use App\Enums\LeaveRequestActionType;
use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeStatus;
use App\Http\Controllers\Api\V1\Leave\LeaveBalanceController;
use App\Http\Controllers\Api\V1\Leave\MyLeaveRequestController;
use App\Http\Controllers\Api\V1\Leave\MyTeamLeaveRequestController;
use App\Http\Controllers\Api\V1\StaffOperations\CheckInController;
use App\Http\Controllers\Api\V1\StaffOperations\OperationalStatusController;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Staff;
use App\Models\StaffCheckIn;
use App\Models\User;
use App\Support\CompanyTimezone;
use App\Support\Reporting\OverdueTasks;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

const T34C = '[UAT34C] ';

$emails = [
    'staff' => 'uat30b.staff@company-app.test',
    'manager' => 'uat30b.manager@company-app.test',
    'colleague' => 'uat30b.colleague@company-app.test',
];

$tz = CompanyTimezone::value();
$today = OverdueTasks::todayInCompanyTimezone();
$year = (int) substr($today, 0, 4);
$D = fn (int $days) => Carbon::parse($today)->addDays($days)->toDateString();
$out("stage={$stage} utc_now=".now('UTC')->toIso8601String()." company_day={$today} ({$tz})");

$users = collect($emails)->map(fn ($e) => User::query()->where('email', $e)->first());
$need = function () use ($users, $out): void {
    if ($users->contains(null) || $users->contains(fn ($u) => $u->staff === null)) {
        $out('UAT30B accounts missing or without staff records: run uat30b_data.php seed first; nothing changed');
        exit(1);
    }
    $ben = $users['manager']->staff;
    if ($users['staff']->staff->manager_id !== $ben->id || $users['colleague']->staff->manager_id !== $ben->id) {
        $out('Lia and Kai must both report to Ben: run uat30b_data.php seed first; nothing changed');
        exit(1);
    }
};

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
        $out(str_pad($key, 10).($u ? 'exists, staff='.($u->staff ? $u->staff->employee_number : 'none') : 'ABSENT (run uat30b_data.php seed first)'));
    }
    $out('company year: '.$year.($D(46) > $year.'-12-31' ? ' — WARNING: leave dates cross into next year; run after 1 January' : ''));
    $out('UAT31 leave types: '.LeaveType::query()->whereIn('name', ['UAT31 Annual Leave', 'UAT31 Unpaid Leave'])->count().' of 2');
    $out('[UAT34C] leave requests: '.LeaveRequest::query()->where('reason', 'like', T34C.'%')->count()
        .', check-ins: '.StaffCheckIn::query()->where('note', 'like', T34C.'%')->count());
    exit(0);
}

if ($stage === 'seed') {
    $need();
    if ($D(46) > $year.'-12-31') {
        $out('leave dates would cross into next year: run seed after 1 January; nothing changed');
        exit(1);
    }
    $liaUser = $users['staff'];
    $benUser = $users['manager'];
    $kaiUser = $users['colleague'];
    $lia = $liaUser->staff;
    $kai = $kaiUser->staff;
    $now = now();

    DB::transaction(function () use ($lia, $kai, $liaUser, $benUser, $kaiUser, $D, $now, $year, $today, $out): void {
        // The UAT31 types and Lia's balance, exactly as Phase 31's seed sets them.
        $annual = LeaveType::query()->firstOrNew(['name' => 'UAT31 Annual Leave']);
        $annual->fill(['code' => 'UAT31A', 'is_paid' => true, 'status' => LeaveTypeStatus::Active, 'sort_order' => 900])->save();
        $unpaid = LeaveType::query()->firstOrNew(['name' => 'UAT31 Unpaid Leave']);
        $unpaid->fill(['code' => 'UAT31U', 'is_paid' => false, 'status' => LeaveTypeStatus::Active, 'sort_order' => 901])->save();
        LeaveBalance::query()->updateOrCreate(
            ['staff_id' => $lia->id, 'leave_type_id' => $annual->id, 'year' => $year],
            ['allocated_days' => 15, 'notes' => 'UAT31 allocation', 'created_by_user_id' => $benUser->id],
        );

        // Only "[UAT34C]" requests are replaced.
        $old = LeaveRequest::query()->where('reason', 'like', T34C.'%')->whereIn('staff_id', [$lia->id, $kai->id])->pluck('id');
        DB::table('leave_request_actions')->whereIn('leave_request_id', $old)->delete();
        LeaveRequest::query()->whereIn('id', $old)->delete();

        $leave = function (Staff $staff, User $by, LeaveType $type, string $from, string $to, LeaveRequestStatus $status, string $reason, array $steps) use ($now): void {
            $r = new LeaveRequest([
                'staff_id' => $staff->id, 'leave_type_id' => $type->id, 'start_date' => $from, 'end_date' => $to,
                'total_days' => Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1,
                'reason' => T34C.$reason, 'status' => $status, 'created_by_user_id' => $by->id,
            ]);
            $r->created_at = $now->copy()->subDays(2);
            $r->updated_at = $r->created_at;
            $r->save();
            foreach ($steps as $i => [$action, $actor, $note]) {
                $a = $r->actions()->make(['action' => $action, 'acted_by_user_id' => $actor->id, 'note' => $note]);
                $a->created_at = $now->copy()->subDays(2)->addHours($i);
                $a->updated_at = $a->created_at;
                $a->save();
            }
        };
        $S = LeaveRequestActionType::Submitted;
        $A = LeaveRequestActionType::Approved;
        // Lia: one to cancel while pending, one approved and not started, one started, one rejected.
        $leave($lia, $liaUser, $annual, $D(30), $D(31), LeaveRequestStatus::Pending, 'Training course', [[$S, $liaUser, null]]);
        $leave($lia, $liaUser, $annual, $D(40), $D(40), LeaveRequestStatus::Approved, 'Check-up', [[$S, $liaUser, null], [$A, $benUser, 'Fine, enjoy.']]);
        $leave($lia, $liaUser, $unpaid, $D(-1), $D(1), LeaveRequestStatus::Approved, 'Site visit week', [[$S, $liaUser, null], [$A, $benUser, null]]);
        $leave($lia, $liaUser, $unpaid, $D(-12), $D(-12), LeaveRequestStatus::Rejected, 'Long weekend', [[$S, $liaUser, null], [LeaveRequestActionType::Rejected, $benUser, 'Stock count that day.']]);
        // Kai: two pending for Ben to decide.
        $leave($kai, $kaiUser, $annual, $D(21), $D(22), LeaveRequestStatus::Pending, 'Moving house', [[$S, $kaiUser, null]]);
        $leave($kai, $kaiUser, $unpaid, $D(25), $D(25), LeaveRequestStatus::Pending, 'Exam', [[$S, $kaiUser, null]]);

        // Lia's check-ins (only "[UAT34C]" ones are replaced): one named, one not.
        StaffCheckIn::query()->where('staff_id', $lia->id)->where('note', 'like', T34C.'%')->delete();
        foreach ([
            ['UAT34C Client site', 14.5547123, 121.0244456, 12, 'Gate B', $now->copy()->subMinutes(90)],
            [null, 14.5866, 121.0614, null, 'No signal at the dock', $now->copy()->subDays(3)],
        ] as [$label, $lat, $lng, $accuracy, $note, $at]) {
            $c = new StaffCheckIn([
                'staff_id' => $lia->id, 'latitude' => $lat, 'longitude' => $lng, 'accuracy_meters' => $accuracy,
                'location_label' => $label, 'note' => T34C.$note, 'status' => null,
            ]);
            $c->created_at = $at;
            $c->updated_at = $at;
            $c->save();
        }

        $out("seed: UAT31 types and Lia's balance (15 for {$year}); [UAT34C] 4 requests for Lia, 2 pending for Kai, 2 check-ins for Lia, set for company day {$today}");
    });
    $out('seed: done');
    exit(0);
}

if ($stage === 'verify') {
    $need();
    $liaUser = $users['staff'];
    $benUser = $users['manager'];

    [$s, $b] = $call($liaUser, fn ($r) => app(LeaveBalanceController::class)->myIndex($r));
    $uat = collect($b['data'] ?? [])->first(fn ($x) => $x['leave_type']['name'] === 'UAT31 Annual Leave');
    $out("Lia balances: {$s}; UAT31 Annual ".($uat ? "{$uat['remaining_days']} left of {$uat['allocated_days']} ({$uat['used_days']} used, {$uat['pending_days']} pending), year {$uat['year']}" : 'MISSING — UNEXPECTED'));
    [$s, $b] = $call($liaUser, fn ($r) => app(MyLeaveRequestController::class)->myIndex($r), ['per_page' => 100]);
    $mine = collect($b['data'] ?? [])->filter(fn ($x) => str_starts_with($x['reason'], T34C));
    $out("Lia requests: {$s}; [UAT34C] ".$mine->map(fn ($x) => "{$x['start_date']} {$x['status']}")->implode(', '));
    [$s, $b] = $call($liaUser, fn ($r) => app(MyTeamLeaveRequestController::class)->index($r));
    $out("Lia team: {$s}; can_review=".json_encode($b['meta']['can_review'] ?? null).' reports='.($b['meta']['direct_reports_count'] ?? '?').' (no Team requests)');
    [$s, $b] = $call($benUser, fn ($r) => app(MyTeamLeaveRequestController::class)->index($r), ['per_page' => 100]);
    $kai = collect($b['data'] ?? [])->filter(fn ($x) => str_starts_with($x['reason'], T34C));
    $out("Ben team: {$s}; can_review=".json_encode($b['meta']['can_review'] ?? null).' reports='.($b['meta']['direct_reports_count'] ?? '?').' pending='.($b['meta']['pending_count'] ?? '?').'; [UAT34C] '.$kai->map(fn ($x) => "{$x['staff']['display_name']} {$x['start_date']}")->implode(', '));
    [$s, $b] = $call($liaUser, fn ($r) => app(OperationalStatusController::class)->myIndex($r), ['per_page' => 5]);
    $current = $b['data'][0] ?? null;
    $out("Lia status: {$s}; current ".($current['status'] ?? 'none').', changes '.($b['meta']['total'] ?? '?'));
    [$s, $b] = $call($liaUser, fn ($r) => app(CheckInController::class)->myIndex($r), ['per_page' => 100]);
    $out("Lia check-ins: {$s}; total ".($b['meta']['total'] ?? '?').', [UAT34C] '.collect($b['data'] ?? [])->filter(fn ($c) => str_starts_with((string) $c['note'], T34C))->map(fn ($c) => $c['location_label'] ?? 'No place name')->implode(', '));
    exit(0);
}

$out("unknown stage: {$stage}");
exit(1);
```
