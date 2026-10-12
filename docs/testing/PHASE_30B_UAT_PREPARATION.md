# Phase 30B — UAT Preparation (Deployment, APK + Staging Data)

*Operator runbook. No AI session has run anything in this file against the staging VPS or on the Windows build machine: this AI session cannot reach the VPS or build an APK. Every step marked **[Operator]** is pending until its output is recorded. The data script in §7 was rehearsed on a disposable scratch database only (see `docs/testing/TEST_STATUS.md`, "Phase 30B — UAT preparation").*

**Status:** UAT-30B-01…08 are `NOT RUN`. This runbook prepares the deployment, the APK and the data; it runs no scenario. When 30B closes, **Phase 30 closes with it**.

**How each operator step is written.** Every step says:
- **Where:** the machine and directory.
- **Run:** the exact commands, to copy as they are.
- **What it does.**
- **Expected:** what a good result looks like, and when to **STOP**.
- **Paste back:** what to send to the AI session. **Never paste** anything from a credentials file, a password, or a password manager.

**Lessons carried over from Phases 27–30A:**
- **No password ever leaves the VPS terminal, and none is ever typed or pasted into a shell.** Passwords go to a `0600` file, then into a password manager, and the file is shredded. Paste a password **only into the app's sign-in field**.
- **Run the stages in order** and stop at the first unexpected output.
- **Extract the script from merged `origin/main`** and check its SHA-256 before running it.
- **Backup check:** look for `-- Dump completed` and a non-zero size. Never compare sizes between dumps.
- **Always run the APK build** and show its output, even if an earlier build exists.

**New for 30B:** the APK is the first with a location permission. **§3's permission check is a hard gate:** UAT must not start unless it passes. It could not be checked during implementation, because no Android SDK could be installed in the AI sandbox.

## 1. Baselines

| Item | SHA | Meaning |
|---|---|---|
| **Phase 30B implementation and UAT source baseline** | `4a85918cc36bc5631636168f1443f95109ab0b1c` | PR #77 merge into `main`. The source for both the staging deployment **and** the final UAT APK. |
| Running staging implementation (before §2) | `0e7fce6afd243ecb27cec3123214cd8fbb8e1a20` | Phase 30A (PR #73). |
| Changes between them | — | `apps/api`: an `id` tie-breaker and `meta.company_day` in `OperationalStatusController` and `CheckInController`, plus their test. **No migration** (still 45 Ran), no route, no environment-variable change, `composer.lock` unchanged. The rest is Phase 30B mobile code (including the `geolocator` dependency) and docs (PRs #74–#77). |

## 2. Staging deployment — **[Operator, VPS]**

**Where:** the VPS, as the `deploy` user, in the staging checkout.

**What it does:** moves the checkout from `0e7fce6` to `4a85918` (fast-forward only), takes a MySQL backup, rebuilds and restarts only `app` and `nginx` (MySQL and its data are not touched), confirms no migration is pending, and rebuilds the Laravel caches. This follows `docs/DEPLOYMENT_STAGING.md` §9.

**Run** (one block at a time):

```sh
cd /home/deploy/company-app/company-app-build/company-app
git status --porcelain                  # expect: nothing
git fetch origin
git merge --ff-only 4a85918cc36bc5631636168f1443f95109ab0b1c
git rev-parse HEAD                      # must print 4a85918cc36bc5631636168f1443f95109ab0b1c
```

```sh
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
$C exec mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  > /home/deploy/backups/company-app-$(date +%Y%m%d-%H%M%S).sql
ls -lt /home/deploy/backups/company-app-*.sql | head -3     # newest first
tail -c 200 "$(ls -t /home/deploy/backups/company-app-*.sql | head -1)" | grep -- '-- Dump completed'
$C build app nginx
$C up -d --no-deps app nginx            # mysql untouched
$C exec app php artisan migrate:status  # expect all 45 Ran, none pending
$C exec app php artisan config:cache
$C exec app php artisan route:cache
$C exec app php artisan view:cache
$C exec app php artisan config:show scheduling.company_timezone   # Asia/Manila
ss -ltnp | grep 8012                    # only 127.0.0.1:8012
$C ps                                   # mysql "healthy", app and nginx "Up"
```

**Expected:**
- **Checkout:** `git status --porcelain` prints nothing (if it lists a file, **STOP** and paste its name; if the name looks like a password, paste nothing and say so). `git merge` prints `Updating 0e7fce6..4a85918` and `Fast-forward`, and `git rev-parse HEAD` prints the full `4a85918…` SHA.
- **Backup:** the first `ls -lt` line is today's file with a non-zero size, and `grep` prints `-- Dump completed on …`. Otherwise **STOP**.
- **Migrations:** 45, all `Ran`. **Timezone:** ends in `Asia/Manila`.

**Smoke tests** (same VPS, any directory):

```sh
B=https://company-staging.storm-ark.com
curl -s -o /dev/null -w 'up %{http_code}\n'        $B/up
curl -s -o /dev/null -w 'login %{http_code}\n'     $B/login
curl -s -o /dev/null -w 'home %{http_code}\n'      -H 'Accept: application/json' $B/api/v1/me/home
curl -s -o /dev/null -w 'status %{http_code}\n'    -H 'Accept: application/json' $B/api/v1/me/status
curl -s -o /dev/null -w 'check-ins %{http_code}\n' -H 'Accept: application/json' $B/api/v1/me/check-ins
```

**Expected:** `up 200`, `login 200`, then `401` for the other three. A `404` or `500`: **STOP**.

**Paste back:** the `git` lines, the three `ls -lt` lines and the `-- Dump completed` line, the `migrate:status` summary, the timezone line, the five smoke-test lines, the `ss` line and the `$C ps` table. **Never paste:** `.env.staging` contents or anything containing `MYSQL_ROOT_PASSWORD`.

## 3. Final UAT APK — **[Operator, Windows build machine]**

**Where:** PowerShell, in the repository root on the Windows build machine.

**What it does:**
- Checks out exactly `4a85918` and runs the same Flutter checks CI runs.
- **Builds** the release APK against staging. It downloads `geolocator`'s Android parts the first time.
- **Proves the permission list**, then installs the APK on the phone.

**Run:**

```powershell
git fetch origin
git checkout --detach 4a85918cc36bc5631636168f1443f95109ab0b1c
git rev-parse HEAD                 # must print 4a85918cc36bc5631636168f1443f95109ab0b1c
git status --porcelain             # must print nothing
cd apps/mobile

flutter --version
dart --version
java -version

flutter pub get
dart format --output=none --set-exit-if-changed .
flutter analyze
flutter test                            # expect +577 "All tests passed!"
flutter test test/features/my_status    # expect +64
flutter test test/features/people       # expect +84
flutter test test/features/home         # expect +67

# ALWAYS run this step, even if an APK already exists.
flutter build apk --release --dart-define=API_BASE_URL=https://company-staging.storm-ark.com/api/v1

git -C ../.. status --porcelain     # still nothing
$apk = "build\app\outputs\flutter-apk\app-release.apk"
Get-Item $apk | Select-Object FullName, Length, LastWriteTime
Get-FileHash -Algorithm SHA256 $apk
$bt = "C:\Android\android-sdk\build-tools\36.0.0"    # where 30A's build found them
& "$bt\aapt2.exe" dump badging $apk | Select-String "package:|compileSdkVersion|sdkVersion|targetSdkVersion|uses-permission"
& "$bt\aapt2.exe" dump xmltree $apk --file AndroidManifest.xml | Select-String "GeolocatorLocationService|BACKGROUND_LOCATION|FOREGROUND_SERVICE"
& "$bt\apksigner.bat" verify --print-certs $apk

adb devices                        # the phone's serial, state "device"
adb install -r $apk                # expect "Performing Streamed Install" then "Success"
adb shell getprop ro.build.version.release    # Android version: 12 or higher
```

**Expected:**
- **Checkout:** `git rev-parse HEAD` prints the full `4a85918…` SHA, and both `git status --porcelain` print nothing. Otherwise **STOP**.
- **Checks:** `dart format` changes nothing; `flutter analyze` says `No issues found!`; the test counts above, exactly. A different count means a different checkout: **STOP**.
- **Build:** ends with `√ Built build\app\outputs\flutter-apk\app-release.apk`, and `LastWriteTime` is **now**.
- **Permission gate (hard):** the `uses-permission` lines are **exactly these four**, no more:
  - `android.permission.INTERNET`
  - `android.permission.ACCESS_COARSE_LOCATION`
  - `android.permission.ACCESS_FINE_LOCATION`
  - `com.companyapp.mobile.DYNAMIC_RECEIVER_NOT_EXPORTED_PERMISSION`
- **Manifest check:** the `xmltree` line prints **nothing**: no `GeolocatorLocationService`, no background location, no foreground service.
- **If either check shows anything else: STOP.** Don't install, and paste the output.
- **Signer and install:** a debug signer (known, deferred to Phase 38); `adb install` ends with `Success`; Android version **12 or higher** (needed for the Approximate option and the Privacy dashboard in UAT).

**Paste back:** all of the output above (it contains no secrets). This APK replaces the Phase 30A APK on the test device. Do not change signing, dependencies or source.

## 4. UAT data plan

**Accounts:** four dedicated `UAT30B` accounts. No existing user or business record is modified; earlier UAT data is left as it is.

| Key | Email | Role | Staff profile | Used by |
|---|---|---|---|---|
| staff | `uat30b.staff@company-app.test` | staff | `UAT30B-001`, Lia UAT30B-Ramos (reports to Ben) | 30B-01…04, 06…08 |
| manager | `uat30b.manager@company-app.test` | manager | `UAT30B-002`, Ben UAT30B-Cruz | 30B-05 |
| colleague | `uat30b.colleague@company-app.test` | staff | `UAT30B-003`, Kai UAT30B-Tan (also reports to Ben, not Lia's manager) | 30B-05 |
| noprofile | `uat30b.noprofile@company-app.test` | staff | none | 30B-05 (no-profile state) |

**Lia's data** (`D` = the company day of the last `seed`; times from the moment it ran):
- **Status history: replaced on every `seed`.**
  - 22 changes, hourly, ending **one hour before the run** with **Available**. My status shows "Available", "Set Today HH:MM".
  - Three hours before the run, **Busy** and **In a meeting** share the same second. In a meeting is listed first.
  - Two pages in All status changes.
- **Check-ins:**
  - 22 seeded check-ins, `[UAT30B] Seeded 01` (yesterday) … `22` (22 days ago), near Makati, ±11…32 m. `01` has a two-line note.
  - Two pages in All check-ins.
  - **Check-ins made in the app are never touched by `seed`**, and `verify` lists them.

## 5. Staging data preparation — **[Operator, VPS]**

All steps: **Where:** the VPS, in the staging checkout, after §2.

**Step 0. Get the script and check it.**

**What it does:** copies the PHP block in §7 of this file, as merged on `origin/main`, into your home directory. It does not change the deployed checkout. The SHA-256 proves the file is exactly the reviewed and rehearsed text.

```sh
cd /home/deploy/company-app/company-app-build/company-app
git fetch origin main
git show origin/main:docs/testing/PHASE_30B_UAT_PREPARATION.md | awk '/^```php$/{f=1;next} /^```$/{f=0} f' > "$HOME/uat30b_data.php"
sha256sum "$HOME/uat30b_data.php"
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
U="$HOME/uat30b_data.php"
$C exec -T app php -- plan < "$U"
```

**Expected:** the SHA equals the one in §7 (**any mismatch: STOP**), then:
```
stage=plan utc_now=<now> company_day=<today> (Asia/Manila)
staff     absent
manager   absent
colleague absent
noprofile absent
UAT30B staff records: 0 of 3
UAT30B status changes: 0 of 22
UAT30B check-ins: 0 (seeded 22 expected after seed)
roles present: 3/3
staff role has location.view: no
```
**STOP** if a UAT30B account exists that nobody can account for, roles are not `3/3`, or the staff role has `location.view`. (`C` and `U` must be set again in every new shell.)

**Paste back:** the `sha256sum` line and the whole `plan` output.

**Step 1. Seed.**

**What it does:** in one database transaction, creates the §4 accounts, staff records, status history and seeded check-ins. It writes the four new passwords, and nothing else, into a new file only you can read.

```sh
CRED="$HOME/uat30b-credentials.txt"
test ! -e "$CRED" && (umask 077; set -o noclobber; $C exec -T app php -- seed < "$U" > "$CRED"); echo "seed exit=$?"
stat -c '%a %U' "$CRED"      # must be: 600 deploy
wc -l < "$CRED"              # must be: 4
```

**Expected** (on screen):
```
stage=seed utc_now=<now> company_day=<today> (Asia/Manila)
seed: staff created
seed: manager created
seed: colleague created
seed: noprofile created
seed: 22 status changes, 22 seeded check-ins set for company day <today>
seed: done; 4 new account(s)
seed exit=0
600 deploy
4
```
- **Non-zero exit:** nothing was changed. **STOP** and paste the screen output, never the file.
- **Mode not `600`:** run `shred -u "$CRED"` and **STOP**.
- **Otherwise:**
  1. Run `less "$CRED"` (press `q` to leave).
  2. Copy all four passwords into your password manager.
  3. Run `shred -u "$CRED"`, then `ls "$CRED"`. Expect `No such file or directory`.

**Paste back:** the screen output and the `ls` line. **Never paste** the file's contents.

**Step 2. Verify and exposure.**

**What it does:** read-only. Runs the real status and check-in controllers as Lia, Ben (her manager), Kai (a colleague) and the no-profile account, and prints what each one gets. This is also the **MySQL check** of the Gate 1 order and company day. Then it counts each account's sign-ins.

```sh
$C exec -T app php -- verify < "$U"
$C exec -T app php -- exposure < "$U"
```

**Expected:**
```
stage=verify utc_now=<now> company_day=<D> (Asia/Manila)
staff current status: available set <weekday D MMM HH:MM, one hour before the seed> (company_day <D> +08:00)
staff status changes: 22 over 2 page(s) of 20; same second: in_meeting above busy
staff check-ins: 22 over 2 page(s) of 20 (no repeats); seeded 22, made in the app 0
manager sees staff check-ins: 200, 22 in all; newest "[UAT30B] Seeded 01"
colleague: location.view=no (the route refuses with 403); controller 403 (correct)
noprofile /me/status: 403 (no-profile state: correct)
```
then four `exposure` lines, each `api_tokens=0 last_used=- web_sessions=0 audit: none`. **STOP** on any line that says `UNEXPECTED` or `REPEATS`.

**Paste back:** both outputs (no secrets).

**Step 3. On the UAT day, if it is not the seeding day, or to start a scenario again.**

**What it does:** resets Lia's status history to the plan and re-dates the seeded check-ins; **check-ins made in the app are kept**. It creates no account, so it prints no password.

```sh
$C exec -T app php -- seed < "$U" > /dev/null; echo "seed exit=$?"
$C exec -T app php -- verify < "$U"
```

**Expected:** `already existed (password unchanged)` ×4, `seed: done; 0 new account(s)`, `seed exit=0`; `verify` as in step 2, except that `made in the app` counts any check-ins already made, each listed on its own line.

**If a password is ever exposed:**
1. Run `exposure` and paste the output.
2. Run `rotate`. It makes exactly four new passwords, all or nothing, and revokes the accounts' tokens and sessions.
   ```sh
   CRED="$HOME/uat30b-credentials.txt"
   test ! -e "$CRED" && (umask 077; set -o noclobber; $C exec -T app php -- rotate < "$U" > "$CRED"); echo "rotate exit=$?"
   ```
3. Do the same `stat`, `wc` (4), password-manager and `shred` steps as for `seed`.
4. **A non-zero `rotate` exit changed nothing**, even if `rotated …` lines were printed. Shred the (empty) file and paste the screen output.

## 6. UAT-time notes (for **execution**, not preparation)

- **Device:** an Android 12+ phone with the §3 APK. Sign in as `uat30b.staff`, pasting the password from the password manager into the app's sign-in field.
  - The app appears as **"mobile"** in Android's Settings → Apps.
  - **Before UAT-30B-02, make sure the app has no location permission yet:** Settings → Apps → mobile → Permissions → Location → **Don't allow**.
- **UAT-30B-01:** open **More**. The **My status** row reads "Available". Open it:
  - "Available", "Set Today HH:MM" (one hour before the seed);
  - the 5 newest check-ins (Seeded 01 first, about a day old, with the two-line note) and the 5 newest status changes;
  - **Change status** shows the five with icons and labels, Available ticked; Off duty's line says it isn't leave.
  - Choose each status in turn. The screen changes only after a short progress bar, and says "Status set to …". End on **In the field**.
  - Go back to More: the row says "In the field".
- **UAT-30B-02:** on My status tap **Check in**.
  - The explanation appears **before** any Android prompt and names the manager and administrators. Tap **Continue**, then Android's **While using the app** with **Precise** on.
  - The form shows "Location found (±N m)" and coordinates. Enter Place **"UAT30B Office"** and a note, then **Send check-in**: "Checked in." and the row on top in Manila time.
  - On the VPS, run `verify`: `made in the app 1`, with the same place, note, accuracy and coordinates; `manager sees … newest "UAT30B Office"`.
- **UAT-30B-03:**
  - Settings → Apps → mobile → Permissions → Location → **Ask every time**. Then Check in → Continue → choose **Approximate** → "Approximate location (±… m)" → Send.
  - Reset to **Don't allow**, Check in → Continue → **Don't allow**: "Location permission is needed to check in." Try again → Continue → **Don't allow** again.
  - Once Android stops asking, **Open app settings** appears and opens the app's settings. The Location options there are **Allow only while using the app / Ask every time / Don't allow**, never "Allow all the time".
  - Set it back to **Allow only while using the app**.
- **UAT-30B-04:**
  - Turn location off in quick settings, then Check in: "Turn on location to check in." **Open location settings** opens Android's location settings. Turn it on, then **Try again**: the form appears.
  - Then switch on airplane mode and **Send check-in** (place "UAT30B Offline test"): the form stays with "Couldn't send your check-in…". Turn airplane mode off and send again: "Checked in.".
  - Run `verify`: exactly **one** "UAT30B Offline test".
- **UAT-30B-05:** `verify` shows the manager sees Lia's check-ins (the newest is yours) and the colleague is refused.
  - Optionally, sign in to the Administrator Backoffice as an administrator to see them there.
  - In the app, sign out and sign in as `uat30b.noprofile`: More → My status shows only "No staff profile is linked to this account." Sign back in as `uat30b.staff`.
- **UAT-30B-06:** with the app in the background for a few minutes, and then closed, the status-bar location indicator never appears for it.
  - Settings → Security & privacy → **Privacy dashboard** → **Location** (the path varies by phone) shows **mobile** only at the check-in moments.
- **UAT-30B-07:**
  - My status → **All check-ins**: scroll past 20; page 2 loads with no repeats. **All status changes**: the same (22+).
  - **Revoked:** on the VPS run `$C exec -T app php -- revoke < "$U"` (expected `revoke: uat30b.staff api_tokens_revoked=1` or more). Then pull to refresh My status: the app returns to Login with "Your session has ended. Please sign in again."
- **UAT-30B-08:** system dark mode and the largest font: My status, the Change status sheet, the explanation, the check-in form (scroll to Send) and both "All" lists.
- **Afterwards:** run `exposure` once more and paste it. The UAT30B data stays in place; any cleanup needs its own authorization.

## 7. Script — `uat30b_data.php`

**Revision 1 (2026-10-12), SHA-256 `b020d7171a826f0de1243ec474aecfa9bd7d50676834973bea37ff8ced4e0f6f`** (the exact text below, with a trailing newline).

```php
<?php

/*
 * Phase 30B UAT data — operator tool (NOT application code; never commit to
 * apps/api, never expose over HTTP).
 *
 * Run inside the staging `app` container, reading this file from stdin:
 *
 *   $C exec -T app php -- <stage> < uat30b_data.php
 *
 * Stages:
 *   plan      read-only: the company day, existing UAT30B accounts and
 *             records, roles, and whether the colleague's role can see
 *             location
 *   seed      idempotent: 4 accounts (staff, manager, colleague, no-profile)
 *             and 3 staff records (the staff member and the colleague both
 *             report to the manager); the staff member's status history is
 *             replaced with 22 changes (two in the same second) ending one
 *             hour before the run, and 22 seeded check-ins are re-dated.
 *             Check-ins made in the app are never touched. A generated
 *             password is written to STDOUT, as an "email password" line,
 *             ONLY for an account created by this run — redirect STDOUT to a
 *             new 0600 file. Passwords never change on a re-run.
 *   verify    read-only: runs the real status and check-in controllers as
 *             the UAT30B accounts and prints what the app and the manager
 *             see, including every check-in made in the app
 *   revoke    UAT-30B-07 only: deletes uat30b.staff's API tokens
 *   exposure  read-only: per UAT30B account, API tokens, web sessions and
 *             login/logout audit events
 *   rotate    new password for EXACTLY the four UAT30B accounts, all-or-
 *             nothing; revokes their API tokens and web sessions. STDOUT
 *             carries only "email password" lines.
 *
 * Every status line, header and error goes to STDERR, so STDOUT of `seed`
 * and `rotate` is only ever credentials. Only "UAT30B"-prefixed records and
 * the uat30b.* accounts are created or changed. No truncation, no
 * migrations, no other business data edited.
 */

use App\Enums\AccountStatus;
use App\Enums\OperationalStatus;
use App\Enums\StaffStatus;
use App\Http\Controllers\Api\V1\StaffOperations\CheckInController;
use App\Http\Controllers\Api\V1\StaffOperations\OperationalStatusController;
use App\Models\Role;
use App\Models\Staff;
use App\Models\StaffCheckIn;
use App\Models\StaffOperationalStatus;
use App\Models\User;
use App\Support\CompanyTimezone;
use App\Support\Reporting\OverdueTasks;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
    'staff' => ['uat30b.staff@company-app.test', 'UAT30B Staff', Role::STAFF, 'UAT30B-001'],
    'manager' => ['uat30b.manager@company-app.test', 'UAT30B Manager', Role::MANAGER, 'UAT30B-002'],
    'colleague' => ['uat30b.colleague@company-app.test', 'UAT30B Colleague', Role::STAFF, 'UAT30B-003'],
    'noprofile' => ['uat30b.noprofile@company-app.test', 'UAT30B No-Profile', Role::STAFF, null],
];

// employee_number => [first name, last name, manager's employee_number]
$staffRecords = [
    'UAT30B-002' => ['Ben', 'UAT30B-Cruz', null],
    'UAT30B-001' => ['Lia', 'UAT30B-Ramos', 'UAT30B-002'],
    'UAT30B-003' => ['Kai', 'UAT30B-Tan', 'UAT30B-002'],
];

const UAT30B_SEEDED = '[UAT30B] Seeded ';
const UAT30B_STATUS_COUNT = 22;
const UAT30B_CHECKIN_COUNT = 22;

$today = OverdueTasks::todayInCompanyTimezone();
$tz = CompanyTimezone::value();
$err("stage={$stage} utc_now=".now('UTC')->toIso8601String()." company_day={$today} ({$tz})");

$uat30bOnly = function (User $user): void {
    if (! preg_match('/^uat30b\.[a-z.]+@company-app\.test$/', $user->email)) {
        fwrite(STDERR, "refusing non-UAT30B account\n");
        exit(1);
    }
};

$users = fn () => collect($accounts)->map(fn ($a) => User::query()->where('email', $a[0])->first());
$staffId = fn (string $number) => Staff::query()->where('employee_number', $number)->value('id');
$manila = fn (string $iso) => Carbon::parse($iso)->tz($tz)->format('D j M H:i');

/** Calls a real controller as $user and returns [status, decoded body]. */
$call = function (User $user, string $uri, array $query, callable $invoke): array {
    $request = Request::create($uri, 'GET', $query);
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $user);

    try {
        $result = $invoke($request);
    } catch (HttpException $e) {
        return [$e->getStatusCode(), ['message' => $e->getMessage()]];
    }
    $response = $result instanceof Responsable ? $result->toResponse($request) : $result;

    return [$response->getStatusCode(), json_decode($response->getContent(), true)];
};

if ($stage === 'plan') {
    foreach ($users() as $key => $user) {
        $err(str_pad($key, 10).($user ? "exists (status={$user->status->value}, staff=".($user->staff ? 'yes' : 'no').')' : 'absent'));
    }
    $lia = $staffId('UAT30B-001');
    $err('UAT30B staff records: '.Staff::query()->where('employee_number', 'like', 'UAT30B-%')->count().' of '.count($staffRecords));
    $err('UAT30B status changes: '.($lia ? StaffOperationalStatus::query()->where('staff_id', $lia)->count() : 0).' of '.UAT30B_STATUS_COUNT);
    $err('UAT30B check-ins: '.($lia ? StaffCheckIn::query()->where('staff_id', $lia)->count() : 0).' (seeded '.UAT30B_CHECKIN_COUNT.' expected after seed)');
    $err('roles present: '.Role::query()->whereIn('name', [Role::ADMINISTRATOR, Role::MANAGER, Role::STAFF])->count().'/3');
    $staffRole = Role::query()->where('name', Role::STAFF)->first();
    $err('staff role has location.view: '.($staffRole?->permissions()->where('name', 'location.view')->exists() ? 'yes (UNEXPECTED)' : 'no'));
    exit(0);
}

if ($stage === 'seed') {
    $clash = Staff::query()->where('employee_number', 'like', 'UAT30B-%')->whereNotNull('user_id')
        ->whereHas('user', fn ($q) => $q->where('email', 'not like', 'uat30b.%'))->exists();
    if ($clash) {
        $err('seed: a UAT30B staff record is linked to a non-UAT30B account; nothing changed');
        exit(1);
    }

    $passwords = [];
    DB::transaction(function () use ($accounts, $staffRecords, $staffId, &$passwords, $err, $today) {
        foreach ($staffRecords as $number => [$first, $last]) {
            $staff = Staff::query()->firstOrNew(['employee_number' => $number]);
            $staff->fill(['first_name' => $first, 'last_name' => $last, 'status' => StaffStatus::Active]);
            $staff->save();
        }
        foreach ($staffRecords as $number => [, , $manager]) {
            Staff::query()->where('employee_number', $number)->update(['manager_id' => $manager === null ? null : $staffId($manager)]);
        }

        $staffUserId = null;
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
            if ($key === 'staff') {
                $staffUserId = $user->id;
            }
            $err("seed: {$key} ".(isset($passwords[$email]) ? 'created' : 'already existed (password unchanged)'));
        }

        $lia = $staffId('UAT30B-001');
        $now = now('UTC')->startOfMinute();

        // The status history is replaced: 22 changes, hourly, ending one hour
        // before this run; the 20th and 21st share a second (R-14).
        StaffOperationalStatus::query()->where('staff_id', $lia)->delete();
        $cycle = [OperationalStatus::Busy, OperationalStatus::InField, OperationalStatus::OffDuty, OperationalStatus::Available];
        for ($i = 0; $i < UAT30B_STATUS_COUNT; $i++) {
            $status = match ($i) {
                19 => OperationalStatus::Busy,
                20 => OperationalStatus::InMeeting,
                21 => OperationalStatus::Available,
                default => $cycle[$i % 4],
            };
            $hoursAgo = $i === 20 ? 3 : UAT30B_STATUS_COUNT - $i;
            $row = new StaffOperationalStatus(['staff_id' => $lia, 'status' => $status, 'changed_by_user_id' => $staffUserId]);
            $row->created_at = $now->copy()->subHours($hoursAgo);
            $row->updated_at = $row->created_at;
            $row->save();
        }

        // Seeded check-ins are re-dated; check-ins made in the app are kept.
        StaffCheckIn::query()->where('staff_id', $lia)->where('location_label', 'like', UAT30B_SEEDED.'%')->delete();
        for ($i = 1; $i <= UAT30B_CHECKIN_COUNT; $i++) {
            $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $row = new StaffCheckIn([
                'staff_id' => $lia,
                'latitude' => 14.5547 + $i / 1000,
                'longitude' => 121.0244 + $i / 1000,
                'accuracy_meters' => 10 + $i,
                'location_label' => UAT30B_SEEDED.$n,
                'note' => $i === 1 ? "Seeded note\nsecond line" : null,
                'status' => null,
            ]);
            $row->created_at = $now->copy()->subDays($i)->subHours(2);
            $row->updated_at = $row->created_at;
            $row->save();
        }

        $err('seed: '.UAT30B_STATUS_COUNT.' status changes, '.UAT30B_CHECKIN_COUNT." seeded check-ins set for company day {$today}");
    });

    foreach ($passwords as $email => $password) {
        echo "{$email} {$password}".PHP_EOL;
    }
    $err('seed: done; '.count($passwords).' new account(s)');
    exit(0);
}

if ($stage === 'verify') {
    $all = $users();
    if ($all->contains(null)) {
        echo 'accounts missing: run seed first'.PHP_EOL;
        exit(1);
    }
    $staffUser = $all['staff'];
    $lia = $staffUser->staff;

    // What the app reads: the 5 newest, then 20 a page.
    [, $recent] = $call($staffUser, '/api/v1/me/status', ['per_page' => 5, 'page' => 1], fn ($r) => app(OperationalStatusController::class)->myIndex($r));
    $first = $recent['data'][0] ?? null;
    echo 'staff current status: '.($first ? "{$first['status']} set ".$manila($first['recorded_at']) : 'none')
        .' (company_day '.($recent['meta']['company_day']['date'] ?? 'MISSING — UNEXPECTED').' '.($recent['meta']['company_day']['utc_offset'] ?? '').')'.PHP_EOL;

    $statusRows = [];
    $page = 1;
    do {
        [, $body] = $call($staffUser, '/api/v1/me/status', ['per_page' => 20, 'page' => $page], fn ($r) => app(OperationalStatusController::class)->myIndex($r));
        array_push($statusRows, ...$body['data']);
    } while ($page++ < $body['meta']['last_page']);
    $pair = null;
    foreach ($statusRows as $k => $row) {
        if (isset($statusRows[$k + 1]) && $row['recorded_at'] === $statusRows[$k + 1]['recorded_at']) {
            $pair = "{$row['status']} above {$statusRows[$k + 1]['status']}";
        }
    }
    echo 'staff status changes: '.count($statusRows).' over '.$body['meta']['last_page'].' page(s) of 20; same second: '.($pair ?? 'none — UNEXPECTED').PHP_EOL;

    $checkIns = [];
    $page = 1;
    do {
        [, $body] = $call($staffUser, '/api/v1/me/check-ins', ['per_page' => 20, 'page' => $page], fn ($r) => app(CheckInController::class)->myIndex($r));
        array_push($checkIns, ...$body['data']);
    } while ($page++ < $body['meta']['last_page']);
    $fromApp = array_values(array_filter($checkIns, fn ($c) => ! str_starts_with((string) $c['location_label'], UAT30B_SEEDED)));
    $ids = array_column($checkIns, 'public_id');
    echo 'staff check-ins: '.count($checkIns).' over '.$body['meta']['last_page'].' page(s) of 20 ('
        .(count($ids) === count(array_unique($ids)) ? 'no repeats' : 'REPEATS — UNEXPECTED')
        .'); seeded '.(count($checkIns) - count($fromApp)).', made in the app '.count($fromApp).PHP_EOL;
    foreach ($fromApp as $c) {
        echo '   '.$manila($c['checked_in_at']).' "'.($c['location_label'] ?? '(no place)').'"'
            .' note='.json_encode($c['note'], JSON_UNESCAPED_UNICODE)
            .' ±'.($c['accuracy_meters'] ?? '?').' m'
            .' '.number_format((float) $c['latitude'], 5).', '.number_format((float) $c['longitude'], 5)
            .' status='.($c['status'] ?? 'none').PHP_EOL;
    }

    // Who can see Lia's check-ins (UAT-30B-05).
    [$status, $body] = $call($all['manager'], "/api/v1/staff/{$lia->public_id}/check-ins", ['per_page' => 20], fn ($r) => app(CheckInController::class)->staffIndex($r, $lia));
    echo 'manager sees staff check-ins: '.($status === 200 ? "200, {$body['meta']['total']} in all; newest \"".($body['data'][0]['location_label'] ?? '(no place)').'"' : "{$status} — UNEXPECTED").PHP_EOL;
    $colleague = $all['colleague'];
    [$status] = $call($colleague, "/api/v1/staff/{$lia->public_id}/check-ins", ['per_page' => 20], fn ($r) => app(CheckInController::class)->staffIndex($r, $lia));
    echo 'colleague: location.view='.($colleague->can('location.view') ? 'yes — UNEXPECTED' : 'no (the route refuses with 403)')
        .'; controller '.($status === 403 ? '403 (correct)' : "{$status} — UNEXPECTED").PHP_EOL;

    [$status] = $call($all['noprofile'], '/api/v1/me/status', ['per_page' => 5], fn ($r) => app(OperationalStatusController::class)->myIndex($r));
    echo 'noprofile /me/status: '.($status === 403 ? '403 (no-profile state: correct)' : "{$status} — UNEXPECTED").PHP_EOL;
    exit(0);
}

if ($stage === 'revoke') {
    $user = $users()['staff'];
    if ($user === null) {
        $err('revoke: uat30b.staff absent');
        exit(1);
    }
    $uat30bOnly($user);
    $err('revoke: uat30b.staff api_tokens_revoked='.$user->tokens()->delete());
    exit(0);
}

if ($stage === 'exposure') {
    foreach ($users() as $key => $user) {
        if ($user === null) {
            echo "{$key}: absent".PHP_EOL;

            continue;
        }
        $uat30bOnly($user);
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
        $err('rotate: expected all four UAT30B accounts to exist; nothing changed');
        exit(1);
    }
    $all->each($uat30bOnly);
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
