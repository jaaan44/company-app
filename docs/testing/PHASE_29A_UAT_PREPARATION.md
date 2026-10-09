# Phase 29A — UAT Preparation (Deployment, APK + Staging Data)

*Operator runbook. No AI session has run anything in this file against the staging VPS or on the Windows build machine: this AI session cannot reach the VPS or build an APK. Every step marked **[Operator]** is pending until its output is recorded. The data script in §7 was rehearsed on a disposable scratch database only (see `docs/testing/TEST_STATUS.md`, "Phase 29A — UAT preparation").*

**Status (as written):** UAT-29A-01…07 are `NOT RUN`. This runbook prepares the deployment, the APK and the data; it runs no scenario.

**Lessons carried over from Phases 27 and 28:**
- **No password ever leaves the VPS terminal.**
  - Passwords go to a `0600` file, then into a password manager, and the file is shredded.
  - Never paste one into any chat, AI tool, ticket or document.
  - `seed` and `rotate` write passwords **only** to STDOUT, as `email password` lines. Every other line goes to STDERR, so the redirected file holds nothing else.
- **Run the stages in order** and stop at the first unexpected output.
- **Extract the script from merged `origin/main`** and check its SHA-256 before running it.
- **After recreating containers, check that MySQL is reachable** before anything else (the Phase 28 network incident): `migrate:status` failing with a DNS error for `mysql` means the MySQL container is on the wrong network. Recreate it from `docker-compose.staging.yml` (`$C up -d mysql`, then `$C restart app`) — never `down -v`.

**New for 29A — the data is dated.** Due dates are set relative to the **company day** (`Asia/Manila`) on which `seed` runs. If UAT happens on a later day, re-run `seed` that morning (§5 step 4) so "overdue", "due today" and "due later" mean what the scenarios expect. Re-running `seed` also undoes any status changes, and the `reassign`, made during an earlier UAT attempt.

## 1. Baselines

| Item | SHA | Meaning |
|---|---|---|
| **Phase 29A implementation and UAT source baseline** | `3632ce1e821ccaca205f19abf5196e3df54fd35b` | PR #61 merge into `main`. The source for both the staging deployment **and** the final UAT APK. |
| Running staging implementation (before §2) | `b6e85c5a5b8bceac4328805af562fd7a2bb4f5a9` | Phase 28 (PR #57). It lacks `GET /api/v1/me/tasks`, so staging **must** be redeployed before UAT-29A. |
| Changes between them | — | `apps/api`: the new `/me/tasks` route and `MyTaskController`, plus the `/tasks` tie-breaker. Phase 29A mobile code, and docs only from PRs #58–#60 (Phase 28 UAT runbook and closure, the Phase 29 spec). **No migration** (`migrate:status` should still show all 45 Ran), no environment-variable change, and `composer.lock` is unchanged. |

## 2. Staging deployment — **[Operator, VPS]**

This follows `docs/DEPLOYMENT_STAGING.md` §9 (redeploy), run from the staging checkout:

```sh
cd /home/deploy/company-app/company-app-build/company-app
git status --porcelain                  # expect nothing (an ignored .env.staging is fine)
git fetch origin
git merge --ff-only 3632ce1e821ccaca205f19abf5196e3df54fd35b
git rev-parse HEAD                      # must print 3632ce1e821ccaca205f19abf5196e3df54fd35b

C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
$C exec mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  > /home/deploy/backups/company-app-$(date +%Y%m%d-%H%M%S).sql      # record file name and size
$C build app nginx
$C up -d --no-deps app nginx            # mysql untouched
$C exec app php artisan migrate:status  # expect all 45 Ran, none pending (Phase 29A adds none)
$C exec app php artisan config:cache
$C exec app php artisan route:cache     # REQUIRED: /me/tasks is a new route
$C exec app php artisan view:cache
$C exec app php artisan config:show scheduling.company_timezone   # still Asia/Manila
```

**Smoke tests (public HTTPS):**

```sh
B=https://company-staging.storm-ark.com
curl -s -o /dev/null -w 'up %{http_code}\n'      $B/up                                              # 200
curl -s -o /dev/null -w 'login %{http_code}\n'   $B/login                                           # 200
curl -s -o /dev/null -w 'home %{http_code}\n'    -H 'Accept: application/json' $B/api/v1/me/home    # 401
curl -s -o /dev/null -w 'mytasks %{http_code}\n' -H 'Accept: application/json' $B/api/v1/me/tasks   # 401, not 404
curl -s -o /dev/null -w 'tasks %{http_code}\n'   -H 'Accept: application/json' $B/api/v1/tasks      # 401
curl -s -o /dev/null -w 'profile %{http_code}\n' -H 'Accept: application/json' $B/api/v1/me/profile # 401
```

A `404` for `/me/tasks` means the route cache is stale or the checkout is wrong. **STOP** in that case.

Record:
- the checkout SHA;
- the backup file and its size;
- the `migrate:status` result;
- the company timezone;
- the six status codes;
- that `127.0.0.1:8012` is the only listener (`ss -ltnp | grep 8012`) and MySQL is healthy.

## 3. Final UAT APK — **[Operator, Windows build machine]**

In PowerShell, from the repository root:

```powershell
git fetch origin
git checkout --detach 3632ce1e821ccaca205f19abf5196e3df54fd35b
git rev-parse HEAD                 # must print 3632ce1e821ccaca205f19abf5196e3df54fd35b
git status --porcelain             # must print nothing
cd apps/mobile

flutter --version
dart --version
java -version
flutter doctor -v                  # record the Java/Gradle JDK and Android SDK/build-tools lines

flutter pub get
dart format --output=none --set-exit-if-changed .
flutter analyze
flutter test                          # expect 330/330
flutter test test/features/tasks      # expect 95/95
flutter test test/features/home       # expect 66/66
flutter test test/core/network        # expect 29/29

flutter build apk --release --dart-define=API_BASE_URL=https://company-staging.storm-ark.com/api/v1

git -C ../.. status --porcelain     # still nothing
$apk = "build\app\outputs\flutter-apk\app-release.apk"
(Get-Item $apk).Length
Get-FileHash -Algorithm SHA256 $apk
$bt = "$env:LOCALAPPDATA\Android\Sdk\build-tools\<ver>"
& "$bt\aapt2.exe" dump badging $apk | Select-String "package:|compileSdkVersion|sdkVersion|targetSdkVersion|uses-permission"
& "$bt\apksigner.bat" verify --print-certs $apk
```

Record:
- the **literal** `git rev-parse HEAD` and `git status --porcelain` output;
- the toolchain versions and test counts;
- the APK path, size and SHA-256;
- package, version and SDK levels;
- that `INTERNET` is present;
- the signer. Debug signing and the `mobile` label are known and deferred to Phase 38.

Do not change signing, dependencies or source. **This APK is the only authorized APK for Phase 29A UAT.** It replaces the Phase 28 APK on the test device. (Phase 28's provenance details were never supplied; please record these.)

## 4. UAT data plan

**Accounts:** four dedicated `UAT29A` accounts. No existing user or business record is modified, and the UAT27 and UAT28 data is left as it is.

| Key | Email | Role | Staff profile | Used by |
|---|---|---|---|---|
| staff | `uat29a.staff@company-app.test` | staff | `UAT29A-001`, Tomas UAT29A-Reyes; manager Marta UAT29A-Santos | 29A-01, 02, 03, 05, 06, 07 |
| manager | `uat29a.manager@company-app.test` | manager | `UAT29A-002`, Marta UAT29A-Santos (Tomas's manager) | 29A-04 |
| admin | `uat29a.admin@company-app.test` | administrator | `UAT29A-003`, Ada UAT29A-Admin | 29A-04 |
| noprofile | `uat29a.noprofile@company-app.test` | staff | none | 29A-04 |

Plus `UAT29A-004`, Olive UAT29A-Other: a staff record **without** an account, used as "someone else" and as the target of `reassign`.

**Project:** `UAT29A-P1` "UAT29A Plant Room Upgrade" (active), with Tomas as a member.

**Tasks** (all titled `[UAT29A] …`, created by the manager account, so "Created by" reads Marta UAT29A-Santos). `D` is the company day on which `seed` last ran:

| Task | Assignee | Status | Due | Project | Notes |
|---|---|---|---|---|---|
| Replace pump seal | Tomas | To do | D−5 | yes | Overdue; High; two-line description |
| Waiting on parts | Tomas | Blocked | D−1 | yes | Overdue; Urgent |
| Call the supplier | Tomas | To do | D | no | **Due today** — also Home's Today task row |
| Inspect wiring | Tomas | In progress | D+3 | yes | No description |
| Reassign me | Tomas | To do | D+5 | yes | UAT-29A-06 (`reassign` moves it to Olive) |
| Order filters | Tomas | To do | D+10 | yes | |
| Routine check 01…22 | Tomas | To do | D+21…D+42 | odd ones | Fills the second page |
| Tidy the store | Tomas | To do | none | no | Undated: last in Open |
| Finished survey | Tomas | Completed | D−3 | yes | Done; completed yesterday |
| Dropped request | Tomas | Cancelled | D−2 | no | Done; read-only (R-4) |
| Manager's own task | Marta | To do | D | no | The manager's only task |
| Admin's own task | Ada | To do | D+1 | no | The admin's only task |
| Someone else's task | Olive | To do | D+2 | yes | Nobody's list |
| Unassigned task | — | To do | D+2 | yes | Nobody's list |

**What the app should show** for `uat29a.staff`:
- **Open:** 29 tasks over 2 pages (25 + 4) in this order: Replace pump seal (Overdue), Waiting on parts (Overdue), Call the supplier (Due today), Inspect wiring, Reassign me, Order filters, Routine check 01…22, Tidy the store (no label).
- **Done:** Finished survey, then Dropped request.
- **Home:** My tasks **29** open, **2** overdue, **1** due today; Today lists Call the supplier.
- The manager and admin each see exactly their own one task; neither sees Tomas's (the manager's direct report) or anyone else's.

## 5. Staging data preparation — **[Operator, VPS]**

**0. Get the script** from merged `origin/main`, without changing the deployed checkout:

```sh
cd /home/deploy/company-app/company-app-build/company-app
git fetch origin main
git show origin/main:docs/testing/PHASE_29A_UAT_PREPARATION.md | awk '/^```php$/{f=1;next} /^```$/{f=0} f' > "$HOME/uat29a_data.php"
sha256sum "$HOME/uat29a_data.php"
#   5091d5bb9ea2004428cefe543d9e98040f220f75892325f81c27632a7245732b  uat29a_data.php
#   -> any mismatch: STOP.
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
U="$HOME/uat29a_data.php"
```

**1. Read-only plan:**

```sh
$C exec -T app php -- plan < "$U"
```

Expected:
- the first line names today's company day in `Asia/Manila`;
- `phase 29A deployed (route api.v1.me.tasks.index): yes`;
- all four accounts `absent`;
- `UAT29A staff records: 0 of 4`, `UAT29A tasks: 0 of 35`, `UAT29A project: absent`;
- `roles present: 3/3`.

**STOP** if the route line says `NO`, or if a UAT29A account already exists that nobody can account for.

**2. Seed.** Passwords go straight into a new `0600` file:

```sh
CRED="$HOME/uat29a-credentials.txt"
test ! -e "$CRED" && (umask 077; set -o noclobber; $C exec -T app php -- seed < "$U" > "$CRED"); echo "seed exit=$?"
stat -c '%a %U' "$CRED"      # must be: 600 <your user>
wc -l < "$CRED"              # must be: 4
```

- **Expected STDERR:** `seed: staff created`, `seed: manager created`, `seed: admin created`, `seed: noprofile created`, `seed: 35 UAT29A tasks set for company day <D>`, then `seed: done; 4 UAT29A staff records; 4 new account(s)`.
- **Non-zero exit:** nothing was changed, because everything runs in one transaction. **STOP** and report the STDERR, never the file.
- **Mode is not `600`:** run `shred -u "$CRED"` and **STOP**.
- **Otherwise:**
  1. Open the file privately (`less "$CRED"`).
  2. Move the four passwords into a password manager.
  3. Run `shred -u "$CRED"`.

**3. Verify.** Both stages are read-only; `verify` runs the real `/me/tasks` and `/me/home` controllers.

```sh
$C exec -T app php -- verify   < "$U"
$C exec -T app php -- exposure < "$U"
```

Expected `verify` output:
- `staff /me/tasks?state=open page 1/2: 25 of 29` and `page 2/2: 4 of 29`, in the §4 order, with `OVERDUE` on Replace pump seal and Waiting on parts and `TODAY` on Call the supplier;
- `staff /me/tasks?state=closed page 1/1: 2 of 2`: `completed` Finished survey, then `cancelled` Dropped request;
- `manager … 1 of 1` (Manager's own task, `TODAY`) and `admin … 1 of 1` (Admin's own task); both `closed … 0 of 0`;
- `noprofile …: tasks null (no-profile variant)` for both states;
- `parity /me/home vs /me/tasks (open, overdue, due today): home=29/2/1 tasks=29/2/1 — OK`.

This is also the **MySQL check** for the new ordering (only SQLite was used before): the open list must be in exactly the §4 order, with Tidy the store last. If the order differs or parity says `MISMATCH`, **STOP** and report.

Expected `exposure` output: all four accounts show `api_tokens=0 web_sessions=0 audit: none`.

Record the `plan`, `verify` and `exposure` output in `docs/testing/TEST_STATUS.md`. They contain no secrets.

**4. On the UAT day, if it is not the seeding day:** re-run `seed` the same way as step 2 but with `> /dev/null` (it creates no account and prints no password), then `verify` again. Never re-run it in the middle of a scenario.

```sh
$C exec -T app php -- seed < "$U" > /dev/null; echo "seed exit=$?"
$C exec -T app php -- verify < "$U"
```

**If a password is ever exposed:**
1. Run `exposure` first.
2. Run `rotate`: exactly four new passwords, all or nothing, and the accounts' tokens and sessions are revoked.
   ```sh
   CRED="$HOME/uat29a-credentials.txt"
   test ! -e "$CRED" && (umask 077; set -o noclobber; $C exec -T app php -- rotate < "$U" > "$CRED"); echo "rotate exit=$?"
   ```
3. Follow the same `stat`, `wc` (4), password-manager and `shred` steps as for `seed`.
4. **A non-zero `rotate` exit changed nothing**, even if STDERR already printed `rotated …` lines for some accounts: everything is rolled back, and the old passwords still work. Shred the (empty) file and report the STDERR.

## 6. UAT-time notes (for **execution**, not preparation)

- **Device:** replace or uninstall the Phase 28 APK and install the §3 APK. Sign in with the passwords from the password manager.
- **UAT-29A-01:** sign in as `uat29a.staff` and open the Tasks tab. Expect:
  - Open: the §4 order; "Overdue · <date>" in red on the first two, "Due today" on Call the supplier, "Due <date>" on the rest, no label on Tidy the store; the status chip text on each (To do / Blocked / In progress); the project name on project tasks only;
  - scroll to the end: page 2 loads (Routine check 20…22, Tidy the store) with no duplicates or gaps;
  - Done: Finished survey (Completed) and Dropped request (Cancelled), with no "Overdue" label on either.
- **UAT-29A-02:** open **Call the supplier**. Check the details: due date with "· Due today", project "Independent task", created by Marta UAT29A-Santos, description "Ask about the seal kit.". Then:
  1. choose **In progress**: a progress bar, then the chip moves and "Status changed to In progress." appears;
  2. choose **Completed**: the "Completed" date appears;
  3. go back: it has left Open and appears in Done;
  4. Home: My tasks now reads **28** open and **0** due today (after Home's quiet refresh; pull to refresh if in doubt);
  5. open it from Done and choose **To do**: it returns to Open with "Due today".
- **UAT-29A-03:** the status choices are To do, In progress, Blocked and Completed only. Open **Dropped request** from Done: it shows "Cancelled" and "This task was cancelled. Its status can't be changed here.", with no choices.
- **UAT-29A-04:** sign out, then in turn sign in as `uat29a.manager` and `uat29a.admin`: each Tasks tab lists only their own one task (none of Tomas's). Then `uat29a.noprofile`: "No staff profile is linked to this account." Sign back in as `uat29a.staff` afterwards.
- **UAT-29A-05:** on Home, tap the **My tasks** tile: the Tasks tab opens. Back on Home, tap **Call the supplier** in Today: its detail opens inside the Tasks tab ("· Due today" shown); back shows the Tasks list.
- **UAT-29A-06:**
  - **Offline:** open Order filters, switch on airplane mode and choose Blocked: "Couldn't save the change. Check your connection and try again." and the chip stays on To do. Reconnect and choose Blocked again: it saves.
  - **Reassigned:** open **Reassign me** and leave it open. On the VPS run `$C exec -T app php -- reassign < "$U"`. In the app choose In progress: the server's message appears ("You do not have permission to update this task."), the choices lock, and after going back it is gone from Open.
  - **Revoked:** on the VPS run `$C exec -T app php -- revoke < "$U"`, then pull to refresh in the app: it returns to Login with "Your session has ended. Please sign in again."
- **UAT-29A-07:** turn on system dark mode and the largest font; check the Tasks list (both segments) and a task detail, including the status choices.
- **Afterwards:** run `exposure` once more and record it. The UAT29A data stays in place; any cleanup needs its own authorization. Re-run `seed` if any scenario needs to be repeated.

## 7. Script — `uat29a_data.php`

**Revision 1 (2026-10-09), SHA-256 `5091d5bb9ea2004428cefe543d9e98040f220f75892325f81c27632a7245732b`** (the exact text below, with a trailing newline).

```php
<?php

/*
 * Phase 29A UAT data — operator tool (NOT application code; never commit to
 * apps/api, never expose over HTTP).
 *
 * Run inside the staging `app` container, reading this file from stdin:
 *
 *   $C exec -T app php -- <stage> < uat29a_data.php
 *
 * Stages:
 *   plan      read-only: Phase 29A deployed?, the company day, existing
 *             UAT29A accounts, staff and tasks, roles
 *   seed      idempotent: 4 accounts, 4 staff records, 1 project and the
 *             UAT29A tasks, dated relative to TODAY's company date. A
 *             generated password is written to STDOUT, as an "email
 *             password" line, ONLY for an account created by this run —
 *             redirect STDOUT to a new 0600 file. Re-running resets every
 *             UAT29A task (status, due date, assignee) to the plan for the
 *             current company day; passwords are never changed.
 *   verify    read-only: runs the real GET /me/tasks and GET /me/home
 *             controllers for each UAT29A account and prints what the app
 *             will show, plus a parity check of the counts
 *   reassign  UAT-29A-06 only: moves "[UAT29A] Reassign me" to another
 *             person (UAT29A-004)
 *   revoke    UAT-29A-06 only: deletes uat29a.staff's API tokens
 *   exposure  read-only: per UAT29A account, API tokens, web sessions and
 *             login/logout audit events
 *   rotate    new password for EXACTLY the four UAT29A accounts, all-or-
 *             nothing in one transaction; revokes their API tokens and web
 *             sessions. STDOUT carries only "email password" lines.
 *
 * Every status line, header and error goes to STDERR, so STDOUT of `seed`
 * and `rotate` is only ever credentials (and is empty when nothing new was
 * created). Only "UAT29A"-prefixed records and the uat29a.* accounts are
 * created or changed. No truncation, no migrations, no existing business
 * data edited, nothing deleted.
 */

use App\Enums\AccountStatus;
use App\Enums\ProjectMembershipRole;
use App\Enums\ProjectStatus;
use App\Enums\StaffStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Http\Controllers\Api\V1\Home\MyHomeController;
use App\Http\Controllers\Api\V1\Tasks\MyTaskController;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use App\Support\CompanyTimezone;
use App\Support\Reporting\OverdueTasks;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Any failure: exit 1 with a short message on STDERR only — never on STDOUT
// (which may be a credentials file), never with SQL bindings (password hashes).
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, 'ERROR '.$e::class.': '.preg_replace('/ \(Connection: .*$/s', '', $e->getMessage()).PHP_EOL);
    exit(1);
});

$stage = $argv[1] ?? 'plan';
$err = fn (string $line) => fwrite(STDERR, $line.PHP_EOL);

// key => [email, user name, role, employee_number of the linked staff record or null]
$accounts = [
    'staff' => ['uat29a.staff@company-app.test', 'UAT29A Staff', Role::STAFF, 'UAT29A-001'],
    'manager' => ['uat29a.manager@company-app.test', 'UAT29A Manager', Role::MANAGER, 'UAT29A-002'],
    'admin' => ['uat29a.admin@company-app.test', 'UAT29A Admin', Role::ADMINISTRATOR, 'UAT29A-003'],
    'noprofile' => ['uat29a.noprofile@company-app.test', 'UAT29A No-Profile', Role::STAFF, null],
];

// employee_number => [first, last, manager employee_number or null]
$people = [
    'UAT29A-002' => ['Marta', 'UAT29A-Santos', null],
    'UAT29A-001' => ['Tomas', 'UAT29A-Reyes', 'UAT29A-002'], // the manager's direct report
    'UAT29A-003' => ['Ada', 'UAT29A-Admin', null],
    'UAT29A-004' => ['Olive', 'UAT29A-Other', null],          // no account
];

// title => [assignee employee_number|null, status, due offset in company days|null,
//           in project?, priority, description|null]
$tasks = [
    // uat29a.staff — open (29 in all: two pages of 25 + 4).
    '[UAT29A] Replace pump seal' => ['UAT29A-001', TaskStatus::Todo, -5, true, TaskPriority::High,
        "Pump 2 in the basement plant room.\nIsolate the supply first."],
    '[UAT29A] Waiting on parts' => ['UAT29A-001', TaskStatus::Blocked, -1, true, TaskPriority::Urgent, 'Seal kit back-ordered.'],
    '[UAT29A] Call the supplier' => ['UAT29A-001', TaskStatus::Todo, 0, false, TaskPriority::Normal, 'Ask about the seal kit.'],
    '[UAT29A] Inspect wiring' => ['UAT29A-001', TaskStatus::InProgress, 3, true, TaskPriority::Normal, null],
    '[UAT29A] Reassign me' => ['UAT29A-001', TaskStatus::Todo, 5, true, TaskPriority::Low, 'Used by UAT-29A-06.'],
    '[UAT29A] Order filters' => ['UAT29A-001', TaskStatus::Todo, 10, true, TaskPriority::Normal, null],
    '[UAT29A] Tidy the store' => ['UAT29A-001', TaskStatus::Todo, null, false, TaskPriority::Low, null],
    // uat29a.staff — closed.
    '[UAT29A] Finished survey' => ['UAT29A-001', TaskStatus::Completed, -3, true, TaskPriority::Normal, null],
    '[UAT29A] Dropped request' => ['UAT29A-001', TaskStatus::Cancelled, -2, false, TaskPriority::Normal, null],
    // Other people's tasks — never on uat29a.staff's, the manager's or the admin's list.
    "[UAT29A] Manager's own task" => ['UAT29A-002', TaskStatus::Todo, 0, false, TaskPriority::Normal, null],
    "[UAT29A] Admin's own task" => ['UAT29A-003', TaskStatus::Todo, 1, false, TaskPriority::Normal, null],
    "[UAT29A] Someone else's task" => ['UAT29A-004', TaskStatus::Todo, 2, true, TaskPriority::Normal, null],
    '[UAT29A] Unassigned task' => [null, TaskStatus::Todo, 2, true, TaskPriority::Normal, null],
];
for ($i = 1; $i <= 22; $i++) {
    $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $tasks["[UAT29A] Routine check {$n}"] = ['UAT29A-001', TaskStatus::Todo, 20 + $i, $i % 2 === 1, TaskPriority::Normal, null];
}

$today = OverdueTasks::todayInCompanyTimezone();
$err("stage={$stage} utc_now=".now('UTC')->toIso8601String()." company_day={$today} (".CompanyTimezone::value().')');

$uat29aOnly = function (User $user): void {
    if (! preg_match('/^uat29a\.[a-z.]+@company-app\.test$/', $user->email)) {
        fwrite(STDERR, "refusing non-UAT29A account\n");
        exit(1);
    }
};

$users = fn () => collect($accounts)->map(fn ($a) => User::query()->where('email', $a[0])->first());
$staffId = fn (?string $number) => $number === null ? null : Staff::query()->where('employee_number', $number)->value('id');

/** Calls a real controller as $user and returns the decoded JSON body. */
$call = function (User $user, string $uri, array $query, callable $invoke): array {
    $request = Request::create($uri, 'GET', $query);
    // Bind first: the paginator reads ?page= from the bound request, and
    // binding it re-installs the auth guard's user resolver — so the
    // UAT29A user must be set after binding, not before.
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $user);

    return json_decode($invoke($request)->getContent(), true);
};

if ($stage === 'plan') {
    $err('phase 29A deployed (route api.v1.me.tasks.index): '.(app('router')->has('api.v1.me.tasks.index') ? 'yes' : 'NO — deploy first'));
    foreach ($users() as $key => $user) {
        $err(str_pad($key, 10).($user ? "exists (status={$user->status->value}, staff=".($user->staff ? 'yes' : 'no').')' : 'absent'));
    }
    $err('UAT29A staff records: '.Staff::query()->where('employee_number', 'like', 'UAT29A-%')->count().' of '.count($people));
    $err('UAT29A tasks: '.Task::query()->where('title', 'like', '[UAT29A]%')->count().' of '.count($tasks));
    $err('UAT29A project: '.(Project::query()->where('project_code', 'UAT29A-P1')->exists() ? 'exists' : 'absent'));
    $err('roles present: '.Role::query()->whereIn('name', [Role::ADMINISTRATOR, Role::MANAGER, Role::STAFF])->count().'/3');
    exit(0);
}

if ($stage === 'seed') {
    // Refuse to touch a UAT29A employee number that is linked to a non-UAT29A account.
    $clash = Staff::query()->where('employee_number', 'like', 'UAT29A-%')->whereNotNull('user_id')
        ->whereHas('user', fn ($q) => $q->where('email', 'not like', 'uat29a.%'))->exists();
    if ($clash) {
        $err('seed: a UAT29A staff record is linked to a non-UAT29A account; nothing changed');
        exit(1);
    }

    $passwords = [];
    DB::transaction(function () use ($accounts, $people, $tasks, $today, $staffId, &$passwords, $err) {
        foreach ($people as $number => [$first, $last, $manager]) {
            $staff = Staff::query()->firstOrNew(['employee_number' => $number]);
            $staff->fill([
                'first_name' => $first,
                'last_name' => $last,
                'status' => StaffStatus::Active,
                'manager_id' => $staffId($manager),
            ]);
            $staff->save();
        }

        $creator = null;
        foreach ($accounts as $key => [$email, $name, $roleName, $number]) {
            $user = User::query()->firstOrNew(['email' => $email]);
            if (! $user->exists) {
                $passwords[$email] = Str::password(20, symbols: false);
                $user->password = Hash::make($passwords[$email]);
            }
            $user->name = $name;
            $user->role_id = Role::query()->where('name', $roleName)->value('id');
            $user->status = AccountStatus::Active;
            $user->save();

            if ($number !== null) {
                Staff::query()->where('employee_number', $number)->update(['user_id' => $user->id]);
            }
            if ($key === 'manager') {
                $creator = $user;
            }
            $err("seed: {$key} ".(isset($passwords[$email]) ? 'created' : 'already existed (password unchanged)'));
        }

        $project = Project::query()->firstOrNew(['project_code' => 'UAT29A-P1']);
        $project->fill(['name' => 'UAT29A Plant Room Upgrade', 'status' => ProjectStatus::Active,
            'description' => 'Phase 29A UAT only']);
        $project->save();
        ProjectMembership::query()->firstOrCreate(
            ['project_id' => $project->id, 'staff_id' => $staffId('UAT29A-001')],
            ['role' => ProjectMembershipRole::Member],
        );

        $base = Carbon::parse($today);
        foreach ($tasks as $title => [$assignee, $status, $due, $inProject, $priority, $description]) {
            $task = Task::query()->firstOrNew(['title' => $title]);
            $task->fill([
                'description' => $description,
                'status' => $status,
                'priority' => $priority,
                'project_id' => $inProject ? $project->id : null,
                'assignee_staff_id' => $staffId($assignee),
                'created_by_user_id' => $creator?->id,
                'due_date' => $due === null ? null : $base->copy()->addDays($due)->toDateString(),
                'completed_at' => $status === TaskStatus::Completed ? now()->subDay() : null,
            ]);
            $task->save();
        }
        $err('seed: '.count($tasks)." UAT29A tasks set for company day {$today}");
    });

    foreach ($passwords as $email => $password) {
        echo "{$email} {$password}".PHP_EOL;
    }
    $err('seed: done; '.count($people).' UAT29A staff records; '.count($passwords).' new account(s)');
    exit(0);
}

if ($stage === 'verify') {
    $myTasks = fn (User $u, array $q) => $call($u, '/api/v1/me/tasks', $q, fn ($r) => app(MyTaskController::class)->index($r));
    $flag = fn (array $t) => $t['is_overdue'] ? 'OVERDUE' : ($t['is_due_today'] ? 'TODAY' : '-');

    foreach ($users() as $key => $user) {
        if ($user === null) {
            echo "{$key}: absent".PHP_EOL;

            continue;
        }
        foreach (['open', 'closed'] as $state) {
            $page = 1;
            do {
                $body = $myTasks($user, ['state' => $state, 'per_page' => 25, 'page' => $page]);
                if ($body['data']['tasks'] === null) {
                    echo "== {$key} /me/tasks?state={$state}: tasks null (no-profile variant)".PHP_EOL;
                    break;
                }
                echo "== {$key} /me/tasks?state={$state} page {$page}/{$body['meta']['last_page']}: "
                    .count($body['data']['tasks'])." of {$body['meta']['total']}".PHP_EOL;
                foreach ($body['data']['tasks'] as $t) {
                    echo '   '.str_pad($t['status'], 12).str_pad($t['due_date'] ?? 'no date', 12)
                        .str_pad($flag($t), 9).$t['title'].PHP_EOL;
                }
            } while ($page++ < $body['meta']['last_page']);
        }
    }

    // /me/home and /me/tasks must agree (spec §5.1).
    $staffUser = $users()['staff'];
    if ($staffUser !== null) {
        $counts = $call($staffUser, '/api/v1/me/home', [], fn ($r) => app(MyHomeController::class)->show($r))['data']['tasks'];
        $open = $myTasks($staffUser, ['state' => 'open', 'per_page' => 50, 'page' => 1]);
        $list = collect($open['data']['tasks']);
        $mine = [$open['meta']['total'], $list->where('is_overdue', true)->count(), $list->where('is_due_today', true)->count()];
        $home = [$counts['open_count'], $counts['overdue_count'], $counts['due_today_count']];
        echo 'parity /me/home vs /me/tasks (open, overdue, due today): home='.implode('/', $home)
            .' tasks='.implode('/', $mine).' — '.($home === $mine ? 'OK' : 'MISMATCH — PROBLEM').PHP_EOL;
    }
    exit(0);
}

if ($stage === 'reassign') {
    $task = Task::query()->where('title', '[UAT29A] Reassign me')->first();
    if ($task === null) {
        $err('reassign: task absent; run seed first');
        exit(1);
    }
    $task->assignee_staff_id = $staffId('UAT29A-004');
    $task->save();
    $err("reassign: '[UAT29A] Reassign me' now assigned to UAT29A-004 (Olive UAT29A-Other)");
    exit(0);
}

if ($stage === 'revoke') {
    $user = $users()['staff'];
    if ($user === null) {
        $err('revoke: uat29a.staff absent');
        exit(1);
    }
    $uat29aOnly($user);
    $err('revoke: uat29a.staff api_tokens_revoked='.$user->tokens()->delete());
    exit(0);
}

if ($stage === 'exposure') {
    foreach ($users() as $key => $user) {
        if ($user === null) {
            echo "{$key}: absent".PHP_EOL;

            continue;
        }
        $uat29aOnly($user);
        $tokens = $user->tokens()->get(['created_at', 'last_used_at']);
        $audit = DB::table('audit_logs')->where('actor_user_id', $user->id)
            ->whereIn('action', ['auth.login_succeeded', 'auth.logout'])
            ->selectRaw('action, count(*) as n, max(created_at) as last')->groupBy('action')->get()
            ->map(fn ($r) => "{$r->action}={$r->n} (last {$r->last})")->implode(', ');
        echo str_pad($key, 10)."api_tokens={$tokens->count()} last_used=".($tokens->max('last_used_at') ?? '-')
            .' web_sessions='.DB::table('sessions')->where('user_id', $user->id)->count()
            .' audit: '.($audit ?: 'none').PHP_EOL;
    }
    exit(0);
}

if ($stage === 'rotate') {
    $all = $users();
    if ($all->contains(null) || $all->count() !== 4) {
        $err('rotate: expected all four UAT29A accounts to exist; nothing changed');
        exit(1);
    }
    $all->each($uat29aOnly);
    $passwords = [];
    DB::transaction(function () use ($all, &$passwords, $err) {
        foreach ($all as $user) {
            $passwords[$user->email] = Str::password(20, symbols: false);
            $user->password = Hash::make($passwords[$user->email]);
            $user->setRememberToken(Str::random(60));
            $user->save();
            $revoked = $user->tokens()->delete();
            $sessions = DB::table('sessions')->where('user_id', $user->id)->delete();
            $err("rotated {$user->email}: api_tokens_revoked={$revoked} web_sessions_removed={$sessions}");
        }
    });
    foreach ($passwords as $email => $password) {
        echo "{$email} {$password}".PHP_EOL;
    }
    $err('rotate: done ('.count($passwords).' accounts)');
    exit(0);
}

$err("unknown stage: {$stage}");
exit(1);
```
