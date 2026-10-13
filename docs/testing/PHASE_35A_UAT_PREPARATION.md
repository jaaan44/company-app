# Phase 35A — UAT Preparation (Granular Permissions — Deployment, Check and UAT)

*Operator runbook. No AI session has run anything in this file against the staging VPS; this AI session cannot reach it. Every step marked **[Operator]** is pending until its output is recorded. The check script in §5 was rehearsed on a scratch SQLite database only (see `docs/testing/TEST_STATUS.md`, "Phase 35A").*

**Status:** UAT-35A-01…04 are `NOT RUN`.
- **No APK is needed:** 35A changes no mobile code.
- **No account or password is created.** The check script is **read-only**.
- **This deploy runs one migration.** It is the first since Phase 24, and staging does not run migrations automatically, so Block 5 runs it.

**How each operator step is written:**
- **Where:** the machine, the user and the directory.
- **Run:** the exact commands, one block at a time.
- **Expected:** what a good result looks like, and when to **STOP**.
- **Paste back:** what to send to the AI session. **Never paste** a password, anything from your password manager, `.env.staging`, or anything containing `MYSQL_ROOT_PASSWORD`.

## 1. Baselines

| Item | SHA | Meaning |
|---|---|---|
| **UAT source baseline (deploy)** | `999380e80dc0f8b07347ae318a20be4f771e625e` | PR #115 merge into `main`: Phase 35A. |
| Running staging implementation (before §2) | `b682935c42e5c2ef2365797ba3df79ecb3e7330e` | Phase 34F. |

**What changes between them:**
- **The permission catalog:** five `.manage` permissions become separate add / edit / delete permissions (23 → 35).
- **The route checks** that use them.
- **One data migration**, `2026_10_13_000000_split_manage_permissions` (45 → 46 migrations). It changes rows in `permissions` and `role_permissions` only, with **no schema change**, in one transaction.
- Everything else in between is documentation.
- `composer.lock`, `package-lock.json`, the Dockerfiles, the nginx config and the environment variables are unchanged. The web assets are unchanged too, so the CSS and JS names stay the same.

**Nobody's access changes.** On staging no role holds any of the five old permissions (only Administrator has them, through the code), so the migration grants nothing to anyone. Block 0 and Block 6 prove this by comparing the before and after outputs.

## 2. Staging deployment — **[Operator, VPS]**

**Where:** the VPS, as `deploy`, in `/home/deploy/company-app/company-app-build/company-app`. Run each block separately.

**Block 0: get the check script, and record "before"** (read-only; the site is not touched)
```sh
cd /home/deploy/company-app/company-app-build/company-app
git fetch origin
git show origin/main:docs/testing/PHASE_35A_UAT_PREPARATION.md | awk '/^```php$/{f=1;next} /^```$/{f=0} f' > "$HOME/p35a_check.php"
sha256sum "$HOME/p35a_check.php"
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
W="$HOME/p35a_check.php"
$C exec -T app php -- check < "$W" | tee "$HOME/p35a_before.txt"
```
**Expected:**
- **The SHA equals the one in §5. Any mismatch: STOP.**
- Then:
```
stage=check utc_now=<now>
migration split_manage_permissions: not run; migrations: 45
permissions: 23
old names present: staff.manage,clients.manage,projects.manage,tasks.manage,announcements.manage
new names present: 0/17 MISSING staff.create,…
role administrator: 0 other: -
role administrator: add/edit/delete held: none
role manager: 10 other: clients.view,leave-requests.view,leave-types.view,location.view,organization.view,projects.view,staff-status.view,staff.view,tasks.view,work-logs.view
role manager: add/edit/delete held: none
role staff: 5 other: clients.view,leave-types.view,organization.view,staff-status.view,staff.view
role staff: add/edit/delete held: none
uat30b.staff@company-app.test (role staff): may none of the old write permissions
uat30b.manager@company-app.test (role manager): may none of the old write permissions
check: done (read-only, nothing changed)
```
Before the deploy, `MISSING` on the `new names` line is expected.

**If a role's `add/edit/delete held` line is not `none`: STOP** and paste it. Someone has granted one of the old permissions, and the migration would carry it onto every part. That is safe, but you should decide on it first.

**Block 1: get the code**
```sh
git status --porcelain
git merge --ff-only 999380e80dc0f8b07347ae318a20be4f771e625e
git rev-parse HEAD
```
**Expected:**
- `git status --porcelain` prints nothing;
- then `Updating b682935..999380e` and `Fast-forward`;
- the last line is `999380e80dc0f8b07347ae318a20be4f771e625e`.

**If the `Updating` line starts from anything other than `b682935`: STOP** and paste it.

**Block 2: back up the database** (required: this deploy changes data)
```sh
$C exec mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  > /home/deploy/backups/company-app-$(date +%Y%m%d-%H%M%S).sql
ls -lt /home/deploy/backups/company-app-*.sql | head -3
tail -c 200 "$(ls -t /home/deploy/backups/company-app-*.sql | head -1)" | grep -- '-- Dump completed'
```
**Expected:** the newest file is from now and not empty, and a `-- Dump completed on …` line appears. **Without it: STOP.**

**Block 3: build, on its own**
```sh
$C build app nginx && echo "BUILD OK"
```
**Expected:** the last line is `BUILD OK`. **`ERROR` or `failed to solve`: STOP** and paste the error lines; the running site stays as it is.

**Block 4: restart**
```sh
$C up -d --no-deps app nginx
$C exec app php artisan migrate:status | tail -3
```
**Expected:** the last line shows `2026_10_13_000000_split_manage_permissions` as **`Pending`**, and the line above it ends in `Ran`.

**Block 5: run the migration, then the caches**
```sh
$C exec app php artisan migrate --force
$C exec app php artisan migrate:status | tail -2
$C exec app php artisan config:cache
$C exec app php artisan route:cache
$C exec app php artisan view:cache
$C ps
```
**Expected:**
- `INFO  Running migrations.` and `2026_10_13_000000_split_manage_permissions … DONE`;
- the last two migrations show `Ran`;
- the three caches succeed;
- in `$C ps`, `company-app-api` and `company-app-nginx` were created just now.

**On `FAIL` or any error: STOP** and paste it. The migration runs in one transaction, so a failure leaves the permissions as they were.

**Block 6: record "after", compare, smoke tests**
```sh
$C exec -T app php -- check < "$W" | tee "$HOME/p35a_after.txt"
diff <(grep ' other: ' "$HOME/p35a_before.txt") <(grep ' other: ' "$HOME/p35a_after.txt") && echo "ROLE GRANTS IDENTICAL"
B=https://company-staging.storm-ark.com
for p in app app/tasks app/more; do curl -s -o /dev/null -w "$p %{http_code} -> %{redirect_url}\n" $B/$p; done
for p in tasks projects clients staff announcements; do curl -s -o /dev/null -w "api $p %{http_code}\n" -H 'Accept: application/json' $B/api/v1/$p; done
```
**Expected:**
- `migration split_manage_permissions: Ran; migrations: 46`;
- `permissions: 35`, `old names present: none`, `new names present: 17/17`;
- the role lines are the same as in Block 0, every `add/edit/delete held: none`;
- both UAT30B accounts: `may none of the new write permissions`;
- **`ROLE GRANTS IDENTICAL`**;
- the three pages each show `302 -> https://company-staging.storm-ark.com/login`;
- five `api … 401` lines.

**Anything else: STOP** and paste the output.

**Paste back:** the `sha256sum` line and the output of Blocks 0–6. For the build, its last few lines are enough.

## 3. If something goes wrong (rollback) — **[Operator, VPS]** only if the AI session asks

```sh
$C exec app php artisan migrate:rollback --step=1 --force
git checkout --detach b682935c42e5c2ef2365797ba3df79ecb3e7330e
$C build app nginx && $C up -d --no-deps app nginx
```
The rollback restores the five old permissions. A role gets one back only if it held every part, which is none on staging. The database backup from Block 2 is the last resort.

## 4. UAT-time notes (for **execution**)

- **Where:** a phone with the app, and a browser at `https://company-staging.storm-ark.com`. Paste passwords from your password manager into the sign-in fields only.
- **Nothing on the screens is new in 35A.** These scenarios check that nothing changed.

**UAT-35A-01: The check script** (done in §2)
- Block 6 shows 35 permissions, no old names, 17/17 new, `ROLE GRANTS IDENTICAL`, and both UAT30B accounts with none of the new write permissions.

**UAT-35A-02: Staff** (sign in as `uat30b.staff`, in the phone app and then the web portal)
1. **Tasks:** your tasks are listed. Open one and change its status (for example To do → In progress), then change it back. Both save.
2. **More → Projects:** your projects are listed and open.
3. **More → Clients:** the client list opens.
4. **More → Staff directory:** the list opens, and a colleague's page opens.
5. **More → Announcements:** the announcements are listed, and one opens.

**UAT-35A-03: Manager** (sign in as `uat30b.manager`, in the phone app and then the web portal)
1. **Tasks:** your tasks are listed as before.
2. **More → Projects:** all projects are listed.
3. **More → Leave → Team requests:** your team's requests are listed.
4. **More → Staff directory:** opens as before.

**UAT-35A-04: Administrator** (sign in, in a browser, with an administrator account)
1. Signing in works, and **Admin** shows in the sidebar.
2. Clicking **Admin** opens the Admin Backoffice page as before.

**Afterwards:** report each scenario as **PASS**, or **FAIL with what you saw**. Only you record a `PASS`.

## 5. Script — `p35a_check.php`

**Revision 1 (2026-10-13), SHA-256 `66b1b4b15911131b1ccde4ec99cf134d379b93dc88d3047b14edaf754e376a40`** (the exact text below, with a trailing newline). It is **read-only**.

**Rehearsed on scratch SQLite:**
- **Before the migration** (main's seeder plus two accounts with the UAT30B emails): it printed the Block 0 lines, with 45 migrations, 23 permissions and the old names.
- **After it:** the Block 6 lines, with 46 migrations, 35 permissions and 17/17 new. The role lines were identical to the run before.
- **An unknown stage:** exit 1.

```php
<?php

/*
 * Phase 35A permission check — operator tool (NOT application code; never
 * commit to apps/api, never expose over HTTP).
 *
 * READ-ONLY: it changes nothing and prints no secret. Run it inside the
 * staging `app` container before and after the 35A deploy, reading this
 * file from stdin:
 *
 *   $C exec -T app php -- check < p35a_check.php
 *
 * It prints the migration's state, the permission catalog, what each role
 * holds, and what the UAT30B Staff and Manager accounts may do, so the two
 * runs can be compared: the role lines must be identical apart from the
 * five old names becoming their parts (and no role holds those today).
 */

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, 'ERROR '.$e::class.': '.preg_replace('/ \(Connection: .*$/s', '', $e->getMessage()).PHP_EOL);
    exit(1);
});

$stage = $argv[1] ?? 'check';
$out = fn (string $line) => fwrite(STDOUT, $line.PHP_EOL);
if ($stage !== 'check') {
    $out("unknown stage {$stage}; the only stage is: check");
    exit(1);
}

$old = ['staff.manage', 'clients.manage', 'projects.manage', 'tasks.manage', 'announcements.manage'];
$new = [
    'staff.create', 'staff.update', 'staff.delete',
    'clients.create', 'clients.update', 'clients.delete',
    'projects.create', 'projects.update', 'projects.delete', 'projects.members',
    'tasks.create', 'tasks.update', 'tasks.delete',
    'announcements.view-all', 'announcements.create', 'announcements.publish', 'announcements.delete',
];

$out('stage=check utc_now='.now('UTC')->toIso8601String());
$ran = DB::table('migrations')->where('migration', '2026_10_13_000000_split_manage_permissions')->exists();
$out('migration split_manage_permissions: '.($ran ? 'Ran' : 'not run').'; migrations: '.DB::table('migrations')->count());

$names = Permission::query()->pluck('name');
$out('permissions: '.$names->count());
$oldLeft = array_values(array_intersect($old, $names->all()));
$out('old names present: '.($oldLeft === [] ? 'none' : implode(',', $oldLeft)));
$missing = array_values(array_diff($new, $names->all()));
$out('new names present: '.(count($new) - count($missing)).'/'.count($new).($missing === [] ? '' : ' MISSING '.implode(',', $missing)));

$split = array_merge($old, $new);
foreach ([Role::ADMINISTRATOR, Role::MANAGER, Role::STAFF] as $roleName) {
    $role = Role::query()->where('name', $roleName)->first();
    if ($role === null) {
        $out("role {$roleName}: MISSING");

        continue;
    }
    $held = $role->permissions()->orderBy('name')->pluck('name');
    $other = $held->reject(fn ($n) => in_array($n, $split, true))->values();
    $splitHeld = $held->filter(fn ($n) => in_array($n, $split, true))->values();
    $out("role {$roleName}: ".$other->count().' other: '.($other->isEmpty() ? '-' : $other->implode(',')));
    $out("role {$roleName}: add/edit/delete held: ".($splitHeld->isEmpty() ? 'none' : $splitHeld->implode(',')));
}

foreach (['uat30b.staff@company-app.test', 'uat30b.manager@company-app.test'] as $email) {
    $user = User::query()->where('email', $email)->first();
    if ($user === null) {
        $out("{$email}: not found");

        continue;
    }
    $can = collect($ran ? $new : $old)->filter(fn ($p) => $user->can($p))->values();
    $out("{$email} (role ".($user->role?->name ?? 'none').'): may '.($can->isEmpty() ? 'none' : $can->implode(',')).' of the '.($ran ? 'new' : 'old').' write permissions');
}

$out('check: done (read-only, nothing changed)');
```
