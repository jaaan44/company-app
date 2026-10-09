# Phase 28 — UAT Preparation (Deployment, APK + Staging Data)

*Operator runbook. No AI session has run anything in this file against the staging VPS or on the Windows build machine: this AI session cannot reach the VPS or build an APK. Every step marked **[Operator]** is pending until its output is recorded. The data script in §7 was rehearsed on a disposable scratch database only (see `docs/testing/TEST_STATUS.md`, "Phase 28 — UAT preparation").*

**Status:** UAT-28-01…07 are `NOT RUN`. This runbook prepares the deployment, the APK and the data; it runs no scenario.

**Lessons carried over from Phase 27** (`PHASE_27_UAT_PREPARATION.md` §4a):
- **No password ever leaves the VPS terminal.**
  - Passwords go to a `0600` file, then into a password manager, and the file is shredded.
  - Never paste one into any chat, AI tool, ticket or document.
  - Unlike Phase 27's `seed`, this script's `seed` writes passwords **only** to STDOUT, as `email password` lines. Every other line goes to STDERR, so the redirected file holds nothing else.
- **Run the stages in order** and stop at the first unexpected output. There is no announcement stage this time, so no step depends on order beyond that.
- **Extract the script from merged `origin/main`** and check its SHA-256 before running it.

## 1. Baselines

| Item | SHA | Meaning |
|---|---|---|
| **Phase 28 implementation and UAT source baseline** | `b6e85c5a5b8bceac4328805af562fd7a2bb4f5a9` | PR #57 merge into `main`. The source for both the staging deployment **and** the final UAT APK. |
| Running staging implementation (before §2) | `be43663f1e3527867c04adb73071eb3bace01ba5` | Phase 27 (PR #44). It lacks `GET /api/v1/me/profile`, so staging **must** be redeployed before UAT-28. |
| Changes between them | — | `apps/api`: the new `/me/profile` route and controller, plus the `/staff` tie-breaker. `composer.lock`: `league/commonmark` 2.10.3 (PR #56). Phase 28 mobile code and docs. **No migration** (`migrate:status` should still show all 45 Ran) and no environment-variable change. |

## 2. Staging deployment — **[Operator, VPS]**

This follows `docs/DEPLOYMENT_STAGING.md` §9 (redeploy), run from the staging checkout:

```sh
cd /home/deploy/company-app/company-app-build/company-app
git status --porcelain                  # expect nothing (an ignored .env.staging is fine)
git fetch origin
git merge --ff-only b6e85c5a5b8bceac4328805af562fd7a2bb4f5a9
git rev-parse HEAD                      # must print b6e85c5a5b8bceac4328805af562fd7a2bb4f5a9

C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
$C exec mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  > /home/deploy/backups/company-app-$(date +%Y%m%d-%H%M%S).sql      # record file name and size
$C build app nginx
$C up -d --no-deps app nginx            # mysql untouched
$C exec app php artisan migrate:status  # expect all 45 Ran, none pending (Phase 28 adds none)
$C exec app php artisan config:cache
$C exec app php artisan route:cache     # REQUIRED: /me/profile is a new route
$C exec app php artisan view:cache
$C exec app php artisan config:show scheduling.company_timezone   # still Asia/Manila
```

**Smoke tests (public HTTPS):**

```sh
B=https://company-staging.storm-ark.com
curl -s -o /dev/null -w 'up %{http_code}\n'      $B/up                                              # 200
curl -s -o /dev/null -w 'login %{http_code}\n'   $B/login                                           # 200
curl -s -o /dev/null -w 'home %{http_code}\n'    -H 'Accept: application/json' $B/api/v1/me/home    # 401
curl -s -o /dev/null -w 'profile %{http_code}\n' -H 'Accept: application/json' $B/api/v1/me/profile # 401, not 404
curl -s -o /dev/null -w 'staff %{http_code}\n'   -H 'Accept: application/json' $B/api/v1/staff      # 401
```

A `404` for `/me/profile` means the route cache is stale or the checkout is wrong. **STOP** in that case.

Record:
- the checkout SHA;
- the backup file and its size;
- the `migrate:status` result;
- the company timezone;
- the five status codes;
- that `127.0.0.1:8012` is the only listener and MySQL is healthy.

## 3. Final UAT APK — **[Operator, Windows build machine]**

In PowerShell, from the repository root:

```powershell
git fetch origin
git checkout --detach b6e85c5a5b8bceac4328805af562fd7a2bb4f5a9
git rev-parse HEAD                 # must print b6e85c5a5b8bceac4328805af562fd7a2bb4f5a9
git status --porcelain             # must print nothing
cd apps/mobile

flutter --version
dart --version
java -version
flutter doctor -v                  # record the Java/Gradle JDK and Android SDK/build-tools lines

flutter pub get
dart format --output=none --set-exit-if-changed .
flutter analyze
flutter test                          # expect 213/213
flutter test test/features/people     # expect 84/84
flutter test test/features/home       # expect 64/64

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

Do not change signing, dependencies or source. **This APK is the only authorized APK for Phase 28 UAT.** It replaces the Phase 27 APK on the test device.

## 4. UAT data plan

**Accounts:** three dedicated `UAT28` accounts. No existing user or business record is modified, and Phase 27's UAT27 data is left as it is.

| Key | Email | Role | Staff profile | Used by |
|---|---|---|---|---|
| staff | `uat28.staff@company-app.test` | staff | `UAT28-001`, Rosalind "Ros" UAT28-Abad: UAT28 Senior Technician · UAT28 Field Services, UAT28 Team North; manager Marcus UAT28-Bautista; company email and phone; hired 2024-03-01 | 28-01, 03, 04, 05, 06, 07 |
| manager | `uat28.manager@company-app.test` | manager | `UAT28-002`, Marcus UAT28-Bautista (same placement, no manager) | the target of 28-05's manager tap; optional second sign-in |
| admin_noprofile | `uat28.admin.noprofile@company-app.test` | administrator | none | 28-02 |

**Directory records** (no accounts):
- 26 active `Member UAT28-Directory-01…26`. Every third one has no position, department or team, to show the subtitle omission and "Not set".
- The active same-name pair `Jamie UAT28-Twin` ×2.
- An active `José UAT28-Ñuñez` (non-ASCII).
- One `inactive` record (`Ina UAT28-Inactive`) and one `separated` record (`Sep UAT28-Separated`). These must **never** appear in the app.

**Organization records:** department `UAT28 Field Services`, team `UAT28 Team North`, position `UAT28 Senior Technician`.

**Searching `UAT28` in the app** returns exactly **31 active people over 2 pages** (25 + 6), starting with `Ros` (UAT28-Abad) and `Marcus UAT28-Bautista`.
- Where `José UAT28-Ñuñez` sorts relative to `UAT28-Twin` depends on the MySQL collation; it is listed exactly once either way.
- The unfiltered directory also shows every other active staging staff member, including the Phase 27 UAT27 records. That is expected.

## 5. Staging data preparation — **[Operator, VPS]**

**0. Get the script** from merged `origin/main`, without changing the deployed checkout:

```sh
cd /home/deploy/company-app/company-app-build/company-app
git fetch origin main
git show origin/main:docs/testing/PHASE_28_UAT_PREPARATION.md | awk '/^```php$/{f=1;next} /^```$/{f=0} f' > "$HOME/uat28_data.php"
sha256sum "$HOME/uat28_data.php"
#   4e846aec912c5ebc48b29d334311468c590542e82b4fbbcc3ba1474c088cabbd  uat28_data.php
#   -> any mismatch: STOP.
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
U="$HOME/uat28_data.php"
```

**1. Read-only plan:**

```sh
$C exec -T app php -- plan < "$U"
```

Expected:
- `phase 28 deployed (route api.v1.me.profile): yes`;
- all three accounts `absent`;
- `UAT28 staff records: 0 of 33`;
- `roles present: 3/3`.

**STOP** if the route line says `NO`, or if a UAT28 account already exists that nobody can account for.

**2. Seed.** Passwords go straight into a new `0600` file:

```sh
CRED="$HOME/uat28-credentials.txt"
test ! -e "$CRED" && (umask 077; set -o noclobber; $C exec -T app php -- seed < "$U" > "$CRED"); echo "seed exit=$?"
stat -c '%a %U' "$CRED"      # must be: 600 <your user>
wc -l < "$CRED"              # must be: 3
```

- **Expected STDERR:** `seed: staff created`, `seed: manager created`, `seed: admin_noprofile created`, then `seed: done; 33 UAT28 staff records; 3 new account(s)`.
- **Non-zero exit:** nothing was changed, because everything runs in one transaction. **STOP** and report the STDERR, never the file.
- **Mode is not `600`:** run `shred -u "$CRED"` and **STOP**.
- **Otherwise:**
  1. Open the file privately (`less "$CRED"`).
  2. Move the three passwords into a password manager.
  3. Run `shred -u "$CRED"`.
- **Re-running `seed` is safe:** it never changes an existing password and writes nothing to STDOUT.

**3. Verify.** Both stages are read-only; `verify` runs the real controllers.

```sh
$C exec -T app php -- verify   < "$U"
$C exec -T app php -- exposure < "$U"
```

Expected `verify` output:
- `staff`: `Ros (full: Rosalind UAT28-Abad) / UAT28 Senior Technician / UAT28 Field Services / team UAT28 Team North / manager Marcus UAT28-Bautista / … / UAT28-001 / hired 2024-03-01`;
- `admin_noprofile`: `staff: none (no-profile variant)`;
- `/staff?q=UAT28` `page 1/2: 25 rows` and `page 2/2: 6 rows`;
- `directory check: 31 active UAT28 rows, 31 distinct; inactive/separated present: no`;
- `tie-breaker check … distinct — OK`. If it says `PROBLEM`, staging is not running `b6e85c5`.

Expected `exposure` output: all three accounts show `api_tokens=0 web_sessions=0 audit: none`.

Record the `plan`, `verify` and `exposure` output in `docs/testing/TEST_STATUS.md`. They contain no secrets.

**If a password is ever exposed:**
1. Run `exposure` first.
2. Run `rotate`: exactly three new passwords, all or nothing, and the accounts' tokens and sessions are revoked.
   ```sh
   CRED="$HOME/uat28-credentials.txt"
   test ! -e "$CRED" && (umask 077; set -o noclobber; $C exec -T app php -- rotate < "$U" > "$CRED"); echo "rotate exit=$?"
   ```
3. Follow the same `stat`, `wc`, password-manager and `shred` steps as for `seed`.

## 6. UAT-time notes (for **execution**, not preparation)

- **Device:** replace or uninstall the Phase 27 APK and install the §3 APK. Sign in with the seeded or rotated passwords from the password manager.
- **UAT-28-01:** sign in as `uat28.staff` and open More → My profile. Expect:
  - **Ros**, with "Rosalind UAT28-Abad" beneath;
  - UAT28 Senior Technician · UAT28 Field Services; UAT28 Team North;
  - **Work:** manager Marcus UAT28-Bautista, `uat28.ros.abad@company-app.test`, `+63 2 8000 2801`;
  - **Employment:** `UAT28-001`, **1 Mar 2024**;
  - **Account:** `uat28.staff@company-app.test`, **Staff**;
  - no edit control.
- **UAT-28-02:** sign in as `uat28.admin.noprofile`. Expect "No staff profile is linked to this account." plus the Account section (Administrator).
- **UAT-28-03:**
  - search `UAT28`: the 31 people above, by last name;
  - search `Rosalind`, `Abad` or `Ros`: narrows to her;
  - search `zzz-none`: `No one matches "zzz-none".`;
  - `Ina` and `Sep` must not appear under any search.
- **UAT-28-04:** search `UAT28` and scroll to the end. Page 2 loads (`UAT28-Directory-24…26`, both Jamies, José) with no duplicates or gaps. Then pull down to refresh.
- **UAT-28-05:**
  1. Open Ros, then Copy email and Copy phone. Paste somewhere private to check.
  2. Tap the manager: Marcus's entry opens. Go back.
  3. Switch to Home and back to More: the stack is kept.
- **UAT-28-06:**
  - With the directory or profile open, switch on airplane mode and pull to refresh: the earlier content stays, with "Couldn't refresh…".
  - Open a screen that hasn't loaded yet while offline: the error shows with "Try again". Reconnect and tap it.
  - **Session revocation:**
    ```sh
    $C exec -T app php artisan tinker --execute='App\Models\User::where("email","uat28.staff@company-app.test")->first()->tokens()->delete();'
    ```
    Then refresh: the app returns to Login with "Your session has ended. Please sign in again."
- **UAT-28-07:** turn on system dark mode and the largest font, and check More, Profile, Directory and Detail.
- **Afterwards:** run `exposure` once more and record it. The UAT28 data stays in place; any cleanup needs its own authorization.

## 7. Script — `uat28_data.php`

**Revision 1 (2026-10-09), SHA-256 `4e846aec912c5ebc48b29d334311468c590542e82b4fbbcc3ba1474c088cabbd`** (the exact text below, with a trailing newline).

```php
<?php

/*
 * Phase 28 UAT data — operator tool (NOT application code; never commit to
 * apps/api, never expose over HTTP).
 *
 * Run inside the staging `app` container, reading this file from stdin:
 *
 *   $C exec -T app php -- <stage> < uat28_data.php
 *
 * Stages:
 *   plan      read-only: Phase 28 deployed?, existing UAT28 accounts and
 *             staff, roles, the size of the active directory
 *   seed      idempotent: UAT28 organisation records, 3 accounts, and the
 *             UAT28 staff directory records. A generated password is written
 *             to STDOUT, as an "email password" line, ONLY for an account
 *             created by this run — redirect STDOUT to a new 0600 file.
 *   verify    read-only: runs the real GET /me/profile controller for each
 *             UAT28 account and the real GET /staff controller as the UAT28
 *             staff user, and prints what the app will show
 *   exposure  read-only: per UAT28 account, API tokens, web sessions and
 *             login/logout audit events
 *   rotate    new password for EXACTLY the three UAT28 accounts, all-or-
 *             nothing in one transaction; revokes their API tokens and web
 *             sessions. STDOUT carries only "email password" lines.
 *
 * Every status line, header and error goes to STDERR, so STDOUT of `seed`
 * and `rotate` is only ever credentials (and is empty when nothing new was
 * created). Only "UAT28"-prefixed records and the uat28.* accounts are
 * created or changed. No truncation, no migrations, no existing business
 * data edited, nothing deleted.
 */

use App\Enums\AccountStatus;
use App\Enums\StaffStatus;
use App\Http\Controllers\Api\V1\Profile\MyProfileController;
use App\Http\Controllers\Api\V1\Staff\StaffController;
use App\Models\Department;
use App\Models\Position;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
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
    'staff' => ['uat28.staff@company-app.test', 'UAT28 Staff', Role::STAFF, 'UAT28-001'],
    'manager' => ['uat28.manager@company-app.test', 'UAT28 Manager', Role::MANAGER, 'UAT28-002'],
    'admin_noprofile' => ['uat28.admin.noprofile@company-app.test', 'UAT28 Admin No-Profile', Role::ADMINISTRATOR, null],
];

// employee_number => attributes. 'org' => true places the record in the UAT28
// department/team/position; false leaves all three null.
$people = [
    // UAT-28-01/05: full profile, preferred name differs from first name.
    'UAT28-001' => ['first_name' => 'Rosalind', 'last_name' => 'UAT28-Abad', 'preferred_name' => 'Ros',
        'company_email' => 'uat28.ros.abad@company-app.test', 'company_phone' => '+63 2 8000 2801',
        'hire_date' => '2024-03-01', 'org' => true, 'manager' => 'UAT28-002'],
    // The staff user's manager (UAT-28-05 manager navigation).
    'UAT28-002' => ['first_name' => 'Marcus', 'last_name' => 'UAT28-Bautista',
        'company_email' => 'uat28.marcus.bautista@company-app.test', 'company_phone' => '+63 2 8000 2802',
        'hire_date' => '2021-07-15', 'org' => true],
    // Same-name pair (R-6, UAT-28-04: both appear exactly once).
    'UAT28-127' => ['first_name' => 'Jamie', 'last_name' => 'UAT28-Twin', 'org' => true],
    'UAT28-128' => ['first_name' => 'Jamie', 'last_name' => 'UAT28-Twin', 'org' => false],
    // Non-ASCII name.
    'UAT28-129' => ['first_name' => 'José', 'last_name' => 'UAT28-Ñuñez', 'org' => true],
    // UAT-28-03: must NEVER appear in the app.
    'UAT28-901' => ['first_name' => 'Ina', 'last_name' => 'UAT28-Inactive', 'status' => StaffStatus::Inactive, 'org' => true],
    'UAT28-902' => ['first_name' => 'Sep', 'last_name' => 'UAT28-Separated', 'status' => StaffStatus::Separated,
        'hire_date' => '2020-01-06', 'separation_date' => '2026-06-30', 'org' => true],
];
// 26 more active records, so the UAT28 search spans two pages of 25.
for ($i = 1; $i <= 26; $i++) {
    $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
    $people['UAT28-1'.$n] = ['first_name' => 'Member', 'last_name' => "UAT28-Directory-{$n}", 'org' => $i % 3 !== 0];
}

$err("stage={$stage} utc_now=".now('UTC')->toIso8601String());

$uat28Only = function (User $user): void {
    if (! preg_match('/^uat28\.[a-z.]+@company-app\.test$/', $user->email)) {
        fwrite(STDERR, "refusing non-UAT28 account\n");
        exit(1);
    }
};

$users = fn () => collect($accounts)->map(fn ($a) => User::query()->where('email', $a[0])->first());

/** Calls a real controller as $user and returns the decoded JSON body. */
$call = function (User $user, string $uri, array $query, callable $invoke): array {
    $request = Request::create($uri, 'GET', $query);
    // Bind first: the paginator reads ?page= from the bound request, and
    // binding it re-installs the auth guard's user resolver — so the
    // UAT28 user must be set after binding, not before.
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $user);

    $result = $invoke($request);
    $response = $result instanceof Responsable ? $result->toResponse($request) : $result;

    return json_decode($response->getContent(), true);
};

if ($stage === 'plan') {
    $err('phase 28 deployed (route api.v1.me.profile): '.(app('router')->has('api.v1.me.profile') ? 'yes' : 'NO — deploy first'));
    foreach ($users() as $key => $user) {
        $err(str_pad($key, 16).($user ? "exists (status={$user->status->value}, staff=".($user->staff ? 'yes' : 'no').')' : 'absent'));
    }
    $err('UAT28 staff records: '.Staff::query()->where('employee_number', 'like', 'UAT28-%')->count().' of '.count($people));
    $err('roles present: '.Role::query()->whereIn('name', [Role::ADMINISTRATOR, Role::MANAGER, Role::STAFF])->count().'/3');
    $err('active staff in the whole directory: '.Staff::query()->where('status', StaffStatus::Active->value)->count());
    exit(0);
}

if ($stage === 'seed') {
    // Refuse to touch a UAT28 employee number that is linked to a non-UAT28 account.
    $clash = Staff::query()->where('employee_number', 'like', 'UAT28-%')->whereNotNull('user_id')
        ->whereHas('user', fn ($q) => $q->where('email', 'not like', 'uat28.%'))->exists();
    if ($clash) {
        $err('seed: a UAT28 staff record is linked to a non-UAT28 account; nothing changed');
        exit(1);
    }

    $passwords = [];
    DB::transaction(function () use ($accounts, $people, &$passwords, $err) {
        $department = Department::query()->firstOrCreate(['name' => 'UAT28 Field Services'], ['description' => 'Phase 28 UAT only']);
        $team = Team::query()->firstOrCreate(
            ['department_id' => $department->id, 'name' => 'UAT28 Team North'],
            ['description' => 'Phase 28 UAT only'],
        );
        $position = Position::query()->firstOrCreate(
            ['department_id' => $department->id, 'title' => 'UAT28 Senior Technician'],
            ['description' => 'Phase 28 UAT only'],
        );

        // Staff first (managers before their reports: UAT28-002 is seeded before
        // UAT28-001 below by sorting reports last).
        $ordered = collect($people)->sortBy(fn ($p) => isset($p['manager']) ? 1 : 0);
        foreach ($ordered as $number => $p) {
            $staff = Staff::query()->firstOrNew(['employee_number' => $number]);
            $staff->fill([
                'first_name' => $p['first_name'],
                'last_name' => $p['last_name'],
                'preferred_name' => $p['preferred_name'] ?? null,
                'company_email' => $p['company_email'] ?? null,
                'company_phone' => $p['company_phone'] ?? null,
                'status' => $p['status'] ?? StaffStatus::Active,
                'hire_date' => $p['hire_date'] ?? null,
                'separation_date' => $p['separation_date'] ?? null,
                'department_id' => $p['org'] ? $department->id : null,
                'team_id' => $p['org'] ? $team->id : null,
                'position_id' => $p['org'] ? $position->id : null,
                'manager_id' => isset($p['manager'])
                    ? Staff::query()->where('employee_number', $p['manager'])->value('id')
                    : null,
            ]);
            $staff->save();
        }

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
            $err("seed: {$key} ".(isset($passwords[$email]) ? 'created' : 'already existed (password unchanged)'));
        }
    });

    foreach ($passwords as $email => $password) {
        echo "{$email} {$password}".PHP_EOL;
    }
    $err('seed: done; '.count($people).' UAT28 staff records; '.count($passwords).' new account(s)');
    exit(0);
}

if ($stage === 'verify') {
    $all = $users();
    foreach ($all as $key => $user) {
        if ($user === null) {
            echo "{$key}: absent".PHP_EOL;

            continue;
        }
        $d = $call($user, '/api/v1/me/profile', [], fn ($r) => app(MyProfileController::class)->show($r))['data'];
        $s = $d['staff'];
        echo "== /me/profile {$key} ({$user->email}) role={$d['user']['role']}".PHP_EOL;
        echo '   '.($s === null
            ? 'staff: none (no-profile variant)'
            : "{$s['display_name']} (full: {$s['first_name']} {$s['last_name']}) / ".($s['position']['title'] ?? '—')
                .' / '.($s['department']['name'] ?? '—').' / team '.($s['team']['name'] ?? '—')
                .' / manager '.($s['manager']['display_name'] ?? '—')
                ." / {$s['company_email']} / {$s['company_phone']} / {$s['employee_number']} / hired {$s['hire_date']}").PHP_EOL;
    }

    $staffUser = $all['staff'];
    if ($staffUser === null) {
        echo 'directory: not checked (uat28.staff absent)'.PHP_EOL;
        exit(0);
    }
    $index = fn (array $q) => $call($staffUser, '/api/v1/staff', $q, fn ($r) => app(StaffController::class)->index($r));

    // Exactly what the app requests for a "UAT28" search (R-1: active only, 25 per page).
    $seen = [];
    $page = 1;
    do {
        $body = $index(['status' => 'active', 'per_page' => 25, 'page' => $page, 'q' => 'UAT28']);
        $names = array_map(fn ($s) => $s['display_name'], $body['data']);
        echo "== /staff?q=UAT28 page {$page}/{$body['meta']['last_page']}: ".count($body['data']).' rows'.PHP_EOL;
        echo '   '.implode(' | ', $names).PHP_EOL;
        $seen = [...$seen, ...array_column($body['data'], 'public_id')];
    } while ($page++ < $body['meta']['last_page']);
    $all28 = array_column($index(['per_page' => 100, 'q' => 'UAT28'])['data'], 'last_name', 'public_id');
    echo 'directory check: '.count($seen).' active UAT28 rows, '.count(array_unique($seen)).' distinct; '
        .'inactive/separated present: '.(count(array_intersect(['UAT28-Inactive', 'UAT28-Separated'],
            array_map(fn ($id) => $all28[$id], $seen))) ? 'YES — PROBLEM' : 'no').PHP_EOL;

    // R-6 deployed: the same-name pair on one-row pages gives two different people.
    $twins = array_map(fn ($p) => $index(['status' => 'active', 'per_page' => 1, 'page' => $p, 'q' => 'UAT28-Twin'])['data'][0]['public_id'] ?? null, [1, 2]);
    echo 'tie-breaker check (UAT28-Twin, per_page=1): '.($twins[0] && $twins[1] && $twins[0] !== $twins[1] ? 'distinct — OK' : 'NOT distinct — PROBLEM').PHP_EOL;
    exit(0);
}

if ($stage === 'exposure') {
    foreach ($users() as $key => $user) {
        if ($user === null) {
            echo "{$key}: absent".PHP_EOL;

            continue;
        }
        $uat28Only($user);
        $tokens = $user->tokens()->get(['created_at', 'last_used_at']);
        $audit = DB::table('audit_logs')->where('actor_user_id', $user->id)
            ->whereIn('action', ['auth.login_succeeded', 'auth.logout'])
            ->selectRaw('action, count(*) as n, max(created_at) as last')->groupBy('action')->get()
            ->map(fn ($r) => "{$r->action}={$r->n} (last {$r->last})")->implode(', ');
        echo str_pad($key, 16)."api_tokens={$tokens->count()} last_used=".($tokens->max('last_used_at') ?? '-')
            .' web_sessions='.DB::table('sessions')->where('user_id', $user->id)->count()
            .' audit: '.($audit ?: 'none').PHP_EOL;
    }
    exit(0);
}

if ($stage === 'rotate') {
    $all = $users();
    if ($all->contains(null) || $all->count() !== 3) {
        $err('rotate: expected all three UAT28 accounts to exist; nothing changed');
        exit(1);
    }
    $all->each($uat28Only);
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
