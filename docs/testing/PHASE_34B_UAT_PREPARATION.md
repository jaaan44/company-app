# Phase 34B — UAT Preparation (Staff Web Portal: Work — Deployment, Data and UAT)

*Operator runbook. No AI session has run anything in this file against the staging VPS; this AI session cannot reach it. Every step marked **[Operator]** is pending until its output is recorded. The data script in §6 was rehearsed on a disposable scratch SQLite database only (see `docs/testing/TEST_STATUS.md`, "Phase 34B — UAT preparation").*

**Status:** UAT-34B-01…07 are `NOT RUN`. **No APK is needed**: 34B changes no mobile code. **No account or password is created**: the scenarios use the UAT30B accounts already in your password manager.

**How each operator step is written:**
- **Where:** the machine, the user and the directory.
- **Run:** the exact commands, one block at a time.
- **Expected:** what a good result looks like, and when to **STOP**.
- **Paste back:** what to send to the AI session. **Never paste** a password, anything from your password manager, `.env.staging`, or anything containing `MYSQL_ROOT_PASSWORD`.

## 1. Baselines

| Item | SHA | Meaning |
|---|---|---|
| **UAT source baseline (deploy)** | `2a7bca9b50ae91738c3759e36bb5568459161911` | PR #92 merge into `main`: Phase 34B. |
| Running staging implementation (before §2) | `d3cf84d13f20a50f4bd67fec7195ac1ecb517820` | Phase 34A. |

**What changes between them:**
- **Web only:** the ten Work pages, Home's links, and shared components.
- **No migration** (still 45 Ran). `composer.lock`, `package-lock.json` and the environment variables are unchanged.
- **CI built both staging images** on this exact change (the "Staging images" check on PR #92).

## 2. Staging deployment — **[Operator, VPS]**

**Where:** the VPS, as `deploy`, in `/home/deploy/company-app/company-app-build/company-app`. Run each block separately.

**Block 1: get the code**
```sh
cd /home/deploy/company-app/company-app-build/company-app
git status --porcelain
git fetch origin
git merge --ff-only 2a7bca9b50ae91738c3759e36bb5568459161911
git rev-parse HEAD
```
**Expected:** `git status --porcelain` prints nothing; then `Updating d3cf84d..2a7bca9` and `Fast-forward`; the last line is `2a7bca9b50ae91738c3759e36bb5568459161911`.

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
for p in app app/tasks app/work-logs app/work-logs/new app/projects app/clients app/schedule; do
  curl -s -o /dev/null -w "$p %{http_code} -> %{redirect_url}\n" $B/$p
done
CSS=$(curl -s $B/login | grep -o '/build/assets/app-[A-Za-z0-9_-]*\.css' | head -1); curl -s -o /dev/null -w "css %{http_code} %{content_type}\n" "$B$CSS"
for p in me/tasks me/work-logs projects clients me/schedule; do
  curl -s -o /dev/null -w "api $p %{http_code}\n" -H 'Accept: application/json' $B/api/v1/$p
done
```
**Expected:**
- the seven pages each show `302 -> https://company-staging.storm-ark.com/login`;
- `css 200 text/css`;
- five `api … 401` lines.

**Anything else: STOP** and paste the output.

**Paste back:** the output of Blocks 1–5; for the build, its last few lines are enough.

## 3. UAT data plan

**Accounts:** `uat30b.staff` (Lia), with Ben and Kai only as names on her data. All the records are prefixed `[UAT34B]` or coded `UAT34B`. `D` is the company day of the last `seed`.
- **Client:** **UAT34B Harbour Foods**, with email, phone, website and an address in Pasig.
  - Contacts: **Rosa Lim** (Primary, Plant manager) and **Jun Reyes** (Maintenance lead), plus one inactive contact that must not show.
- **Project:** **UAT34B Cold room upgrade** (code UAT34B), Active.
  - Ben is Project lead; Lia and Kai are Members.
  - Start D−30, target end D+60, a two-line description.
  - Milestones "Compressors delivered" (Completed) and "Room back in service" (Pending).
- **Lia's tasks** (created by Ben):
  - "Inspect the door seals": To do, High, due D−3, so **overdue**;
  - "Replace compressor 1": **In progress**, Urgent, **due today**, two-line description;
  - "Write the handover note": To do, due D+10;
  - "Renew the forklift licence": To do, Low, due D+20, **no project** ("Independent task");
  - "Order spare fan belts": **Blocked**, due D+2;
  - "Old survey request": **Cancelled**;
  - "Measure the room temperature": **Completed** yesterday.
- **Lia's work logs:**
  - today, 1 h 30 min on "Replace compressor 1";
  - yesterday, 45 min on the project, and 30 min on "Measure the room temperature";
  - D−6, 2 h on the project.
- **Schedule** (Lia and Kai invited):
  - "Daily check-in": a Meeting **today 16:00–16:30**;
  - "Compressor install": a Service appointment **tomorrow 09:00–12:30**, with a description;
  - "Overnight temperature watch": D+3 22:00 to D+4 06:00, so it shows on both days.

**A `seed` resets these records** (task statuses included) for the day it runs. Work you log in the app is kept.

## 4. Staging data — **[Operator, VPS]**

**Where:** the VPS, as `deploy`, in the staging checkout, after §2.

**Step 0: get the script and check it**
```sh
cd /home/deploy/company-app/company-app-build/company-app
git fetch origin main
git show origin/main:docs/testing/PHASE_34B_UAT_PREPARATION.md | awk '/^```php$/{f=1;next} /^```$/{f=0} f' > "$HOME/uat34b_data.php"
sha256sum "$HOME/uat34b_data.php"
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
W="$HOME/uat34b_data.php"
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
[UAT34B] tasks: 0, schedule entries: 0, work logs: 0
UAT34B client: absent, project: absent
```

**Step 1: seed and verify**
```sh
$C exec -T app php -- seed < "$W"; echo "seed exit=$?"
$C exec -T app php -- verify < "$W"
```
**Expected:**
```
stage=seed utc_now=<now> company_day=<D> (Asia/Manila)
seed: UAT34B client (3 contacts, 2 active), project (3 members, 2 milestones), 7 tasks for Lia, 4 work logs, 3 schedule entries set for company day <D>
seed: done
seed exit=0
stage=verify utc_now=<now> company_day=<D> (Asia/Manila)
tasks open: 200; UAT34B 5 (overdue 1, due today 1)
tasks done: 200; UAT34B 2
work logs: 200; UAT34B 4, minutes 285
projects (mine, q=UAT34B): 200; UAT34B Cold room upgrade
clients (q=UAT34B): 200; UAT34B Harbour Foods
schedule week: 200; UAT34B 5 (task 2, schedule_entry 3)
```
**STOP** on a non-zero exit, `ERROR`, or any line that differs.

**Paste back:** the `sha256sum` line and the three outputs.

**On a later UAT day, or to start a scenario again:** run `seed` and `verify` again. Nothing else is needed.

## 5. UAT-time notes (for **execution**)

- **Browsers:** a desktop browser, and your phone's browser for 34B-07, at `https://company-staging.storm-ark.com`.
- **Sign in as `uat30b.staff`** (Lia), pasting the password from your password manager into the sign-in page only.
- **Phone app:** keep it open as Lia for the comparisons.

**UAT-34B-01: Tasks**
1. Click **Tasks**. **Open** lists the five open UAT34B tasks, including:
   - "Inspect the door seals" with **Overdue · <date>** in red;
   - "Replace compressor 1" with **In progress** and **Due today**;
   - "Order spare fan belts" with **Blocked**;
   - "Renew the forklift licence" with no project.
2. Click **Done**: "Measure the room temperature" (Completed) and "Old survey request" (Cancelled).
3. The phone's Tasks tab shows the same tasks and labels.

**UAT-34B-02: A task and its status**
1. Open "Replace compressor 1". Check that it shows:
   - **In progress** and **Priority: Urgent**;
   - Due date "<today> · Due today";
   - Project "UAT34B Cold room upgrade" as a link;
   - Created by "Ben UAT30B-Cruz";
   - the two-line description.
2. Click **Blocked**. You see **"Status changed to Blocked."**, and Blocked is highlighted. Open the task on the phone (pull to refresh): it's **Blocked**.
3. Set it back to **In progress**.
4. From **Done**, open "Old survey request". It says **"This task was cancelled. Its status can't be changed here."** There are no status buttons and no Log work.

**UAT-34B-03: Log work**
1. On "Replace compressor 1", click **Log work**. The form shows the task, fixed, and today's date.
2. Click **Save** with nothing else filled in: **"Enter how long you worked."** and **"Describe the work."**
3. Enter **0** hours and **45** minutes, and "UAT34B web test". Click **Save**: you're on My work logs with **"Work logged."**, and the log is under **Today**.
4. Click **Log work** (top right). Under "What was this for?", choose **UAT34B Cold room upgrade** from "My projects".
   - Set the date to **tomorrow**, and click Save: **"The work date cannot be later than today."**
   - Set yesterday's date, 1 h, "UAT34B project log", and Save: it appears under **Yesterday**.
5. The phone's My work logs shows both.

**UAT-34B-04: Edit and delete**
1. Click "UAT34B web test". You're on **Edit work log**. Change the minutes to **50** and click **Save changes**: **"Changes saved."**, and the row shows 50 min.
2. Open it again and click **Delete**. The browser asks "Delete this work log? This can't be undone."; click **OK**. You see **"Work log deleted."**, and it's gone.
3. Refresh the phone's list: it's gone there too.

**UAT-34B-05: Projects**
1. Click **Projects**. It lists only Lia's projects, including **UAT34B Cold room upgrade**, with "UAT34B · UAT34B Harbour Foods" and **Active**.
2. Search **`cold`**: only the matching projects show, and the address bar has `?q=cold`. Click **Clear search**.
3. Open the project. Check:
   - Client "UAT34B Harbour Foods" (a link), My role **Member**, the start and target end dates, and the two-line description;
   - **Members (3)**: Ben (Project lead), Lia and Kai. Clicking Ben opens his Staff page.
   - **Milestones:** "Compressors delivered" (**Completed**) and "Room back in service" (**Pending**).

**UAT-34B-06: Clients**
1. Click **Clients** and search **`harbour`**. Only "UAT34B Harbour Foods" (UAT34B) shows.
2. Open it. Check:
   - Email, Phone, Website and Address ("12 Pier Road, Pasig, Metro Manila 1600, Philippines"), each with a Copy button. Try **Copy address** and paste it to check.
   - **Contacts (2):** Rosa Lim with **Primary** and "Plant manager"; Jun Reyes. **"Old Contact" is not shown.**

**UAT-34B-07: Schedule, Home links, and display**
1. Click **Schedule**. The week starts **Today**:
   - **Today:** "Replace compressor 1" (Task · In progress · All day) and "Daily check-in" (Meeting · 16:00 – 16:30);
   - **Tomorrow:** "Compressor install" (Service appointment · 09:00 – 12:30);
   - the day after: "Order spare fan belts" (Task · Blocked · All day);
   - on D+3 and D+4: "Overnight temperature watch" with "22:00 – until …" and "From … – 06:00";
   - any approved leave from earlier rounds as "Leave · All day", not a link.
2. Click **Next week**, then **Previous week**, then **Today**. The week changes each time, and **Today** returns to the plain address (no `?from=`).
3. Click "Compressor install". Check:
   - When "<tomorrow> · 09:00 – 12:30";
   - Project, Organizer "Ben UAT30B-Cruz";
   - **People (2)**: Lia and Kai;
   - the description.
4. **Home:**
   - the **My tasks** tile opens Tasks;
   - in Today, "Replace compressor 1" opens the task, and "Daily check-in" opens its entry.
5. **Display:** dark mode, **200% zoom**, and your **phone's browser**, on Tasks, a task, Work logs, the Log work form, Projects, a project, Clients, a client and Schedule. Everything is readable, there's no sideways scrolling, and every button can be reached.

**Afterwards:** the `[UAT34B]` records stay on staging (cleanup needs its own authorization). Report each scenario as **PASS**, or **FAIL with what you saw**. Only you record a `PASS`.

## 6. Script — `uat34b_data.php`

**Revision 1 (2026-10-13), SHA-256 `cef6db23844bb07efcc65686f6906b3258ad9a5fff9824ff72b936956bfb1bc7`** (the exact text below, with a trailing newline). It requires `uat30b_data.php`'s accounts. Rehearsed on scratch SQLite: `plan`, `seed`, `verify`, a re-seed (idempotent; work made in the app is kept; task statuses reset).

```php
<?php

/*
 * Phase 34B UAT data — operator tool (NOT application code; never commit
 * to apps/api, never expose over HTTP).
 *
 * Uses the UAT30B accounts made by uat30b_data.php: Lia (staff), Ben
 * (manager) and Kai (staff). It creates NO account and prints NO password.
 *
 * Run inside the staging `app` container, reading this file from stdin:
 *
 *   $C exec -T app php -- <stage> < uat34b_data.php
 *
 * Stages:
 *   plan    read-only: the UAT30B accounts, the company day, and what
 *           UAT34B data already exists
 *   seed    idempotent; sets the "[UAT34B]" tasks, schedule entries and
 *           work logs, and the UAT34B client, contact, project, membership
 *           and milestone, for the company day it runs on. Tasks and
 *           entries are updated in place; only "[UAT34B]" work logs are
 *           replaced, so work logged in the app is kept.
 *   verify  read-only: runs the real controllers as Lia and prints what the
 *           web portal and the phone see
 *
 * No secret is ever printed. Any failure exits 1 and changes nothing
 * (one transaction).
 */

use App\Enums\ClientStatus;
use App\Enums\ContactStatus;
use App\Enums\ProjectMembershipRole;
use App\Enums\ProjectMilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\ScheduleEntryActivityType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Http\Controllers\Api\V1\Clients\ClientController;
use App\Http\Controllers\Api\V1\Projects\ProjectController;
use App\Http\Controllers\Api\V1\Scheduling\MyScheduleController;
use App\Http\Controllers\Api\V1\Tasks\MyTaskController;
use App\Http\Controllers\Api\V1\WorkLogs\MyWorkLogController;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ProjectMilestone;
use App\Models\ScheduleEntry;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkLog;
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

const T34B = '[UAT34B] ';

$emails = [
    'staff' => 'uat30b.staff@company-app.test',
    'manager' => 'uat30b.manager@company-app.test',
    'colleague' => 'uat30b.colleague@company-app.test',
];

$tz = CompanyTimezone::value();
$today = OverdueTasks::todayInCompanyTimezone();
$D = fn (int $days) => Carbon::parse($today)->addDays($days)->toDateString();
$at = fn (int $days, string $time) => Carbon::parse($D($days).' '.$time, $tz)->utc();
$out("stage={$stage} utc_now=".now('UTC')->toIso8601String()." company_day={$today} ({$tz})");

$users = collect($emails)->map(fn ($e) => User::query()->where('email', $e)->first());
$need = function () use ($users, $out): void {
    if ($users->contains(null) || $users->contains(fn ($u) => $u->staff === null)) {
        $out('UAT30B accounts missing or without staff records: run uat30b_data.php seed first; nothing changed');
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
    $out('[UAT34B] tasks: '.Task::query()->where('title', 'like', T34B.'%')->count()
        .', schedule entries: '.ScheduleEntry::query()->where('title', 'like', T34B.'%')->count()
        .', work logs: '.WorkLog::query()->where('description', 'like', T34B.'%')->count());
    $out('UAT34B client: '.(Client::query()->where('client_code', 'UAT34B')->exists() ? 'exists' : 'absent')
        .', project: '.(Project::query()->where('project_code', 'UAT34B')->exists() ? 'exists' : 'absent'));
    exit(0);
}

if ($stage === 'seed') {
    $need();
    $lia = $users['staff']->staff;
    $ben = $users['manager']->staff;
    $kai = $users['colleague']->staff;
    $benUser = $users['manager'];

    DB::transaction(function () use ($lia, $ben, $kai, $benUser, $D, $at, $today, $out): void {
        // Client and contacts (Clients pages).
        $client = Client::query()->firstOrNew(['client_code' => 'UAT34B']);
        $client->fill([
            'name' => 'UAT34B Harbour Foods', 'status' => ClientStatus::Active,
            'email' => 'ops@harbour.example', 'phone' => '+63 2 8555 0134', 'website' => 'harbour.example',
            'address_line1' => '12 Pier Road', 'address_line2' => null, 'city' => 'Pasig', 'state_province' => 'Metro Manila',
            'postal_code' => '1600', 'country' => 'Philippines',
        ])->save();
        foreach ([
            ['Rosa', 'Lim', 'Plant manager', 'rosa.lim@harbour.example', '+63 917 555 0101', true, ContactStatus::Active],
            ['Jun', 'Reyes', 'Maintenance lead', 'jun.reyes@harbour.example', null, false, ContactStatus::Active],
            ['Old', 'Contact', null, null, null, false, ContactStatus::Inactive],
        ] as [$first, $last, $title, $email, $phone, $primary, $status]) {
            Contact::query()->firstOrNew(['client_id' => $client->id, 'first_name' => $first, 'last_name' => $last])
                ->fill(['job_title' => $title, 'email' => $email, 'phone' => $phone, 'is_primary' => $primary, 'status' => $status])->save();
        }

        // Project with Ben as lead, Lia and Kai as members, two milestones.
        $project = Project::query()->firstOrNew(['project_code' => 'UAT34B']);
        $project->fill([
            'name' => 'UAT34B Cold room upgrade', 'client_id' => $client->id, 'status' => ProjectStatus::Active,
            'description' => "Replace the cold room compressors.\nKeep the room below 4 °C during the work.",
            'start_date' => $D(-30), 'target_end_date' => $D(60), 'completed_date' => null,
        ])->save();
        foreach ([[$ben, ProjectMembershipRole::ProjectLead], [$lia, ProjectMembershipRole::Member], [$kai, ProjectMembershipRole::Member]] as [$staff, $role]) {
            ProjectMembership::query()->firstOrNew(['project_id' => $project->id, 'staff_id' => $staff->id])->fill(['role' => $role])->save();
        }
        foreach ([['Compressors delivered', $D(-5), ProjectMilestoneStatus::Completed], ['Room back in service', $D(30), ProjectMilestoneStatus::Pending]] as [$title, $due, $status]) {
            ProjectMilestone::query()->firstOrNew(['project_id' => $project->id, 'title' => $title])->fill(['due_date' => $due, 'status' => $status])->save();
        }

        // Lia's tasks: overdue, today (in progress), later, independent, blocked, cancelled, completed.
        $tasks = [];
        foreach ([
            ['Inspect the door seals', $project->id, TaskStatus::Todo, TaskPriority::High, $D(-3), null, 'Check both doors for gaps.'],
            ['Replace compressor 1', $project->id, TaskStatus::InProgress, TaskPriority::Urgent, $today, null, "Isolate power first.\nLog the old serial number."],
            ['Write the handover note', $project->id, TaskStatus::Todo, TaskPriority::Normal, $D(10), null, null],
            ['Renew the forklift licence', null, TaskStatus::Todo, TaskPriority::Low, $D(20), null, null],
            ['Order spare fan belts', $project->id, TaskStatus::Blocked, TaskPriority::Normal, $D(2), null, 'Waiting for the supplier quote.'],
            ['Old survey request', null, TaskStatus::Cancelled, TaskPriority::Normal, null, null, null],
            ['Measure the room temperature', $project->id, TaskStatus::Completed, TaskPriority::Normal, $D(-1), now()->subDay(), null],
        ] as [$title, $projectId, $status, $priority, $due, $completedAt, $description]) {
            $task = Task::query()->firstOrNew(['title' => T34B.$title, 'assignee_staff_id' => $lia->id]);
            $task->fill([
                'project_id' => $projectId, 'status' => $status, 'priority' => $priority, 'due_date' => $due,
                'completed_at' => $completedAt, 'description' => $description, 'created_by_user_id' => $benUser->id,
            ])->save();
            $tasks[$title] = $task;
        }

        // Lia's work logs (only "[UAT34B]" ones are replaced).
        WorkLog::query()->where('staff_id', $lia->id)->where('description', 'like', T34B.'%')->delete();
        foreach ([
            [$tasks['Replace compressor 1'], $today, 90, 'Disconnected the old compressor'],
            [null, $D(-1), 45, 'Site walk-through with Ben'],
            [$tasks['Measure the room temperature'], $D(-1), 30, 'Logged readings every 10 minutes'],
            [null, $D(-6), 120, 'Planning the shutdown'],
        ] as [$task, $date, $minutes, $text]) {
            WorkLog::query()->create([
                'staff_id' => $lia->id, 'task_id' => $task?->id, 'project_id' => $project->id, 'created_by_user_id' => null,
                'work_date' => $date, 'duration_minutes' => $minutes, 'description' => T34B.$text,
            ]);
        }

        // Schedule: a meeting today (Home's Today), one tomorrow, one across midnight.
        foreach ([
            ['Daily check-in', ScheduleEntryActivityType::Meeting, $at(0, '16:00'), $at(0, '16:30'), $ben, null],
            ['Compressor install', ScheduleEntryActivityType::ServiceAppointment, $at(1, '09:00'), $at(1, '12:30'), $ben, 'Bring the lifting gear.'],
            ['Overnight temperature watch', ScheduleEntryActivityType::Other, $at(3, '22:00'), $at(4, '06:00'), $lia, null],
        ] as [$title, $type, $start, $end, $creator, $description]) {
            $entry = ScheduleEntry::query()->firstOrNew(['title' => T34B.$title]);
            $entry->fill([
                'creator_staff_id' => $creator->id, 'activity_type' => $type, 'starts_at' => $start, 'ends_at' => $end,
                'is_all_day' => false, 'project_id' => $project->id, 'description' => $description,
            ])->save();
            $entry->participants()->sync(array_values(array_unique([$lia->id, $kai->id])));
        }

        $out("seed: UAT34B client (3 contacts, 2 active), project (3 members, 2 milestones), 7 tasks for Lia, 4 work logs, 3 schedule entries set for company day {$today}");
    });
    $out('seed: done');
    exit(0);
}

if ($stage === 'verify') {
    $need();
    $lia = $users['staff'];

    [$s, $b] = $call($lia, fn ($r) => app(MyTaskController::class)->index($r), ['state' => 'open', 'per_page' => 50]);
    $open = collect($b['data']['tasks'] ?? [])->filter(fn ($t) => str_starts_with($t['title'], T34B));
    $out("tasks open: {$s}; UAT34B ".$open->count().' (overdue '.$open->where('is_overdue', true)->count().', due today '.$open->where('is_due_today', true)->count().')');
    [$s, $b] = $call($lia, fn ($r) => app(MyTaskController::class)->index($r), ['state' => 'closed', 'per_page' => 50]);
    $out("tasks done: {$s}; UAT34B ".collect($b['data']['tasks'] ?? [])->filter(fn ($t) => str_starts_with($t['title'], T34B))->count());

    [$s, $b] = $call($lia, fn ($r) => app(MyWorkLogController::class)->myIndex($r), ['per_page' => 100]);
    $logs = collect($b['data'] ?? [])->filter(fn ($l) => str_starts_with($l['description'], T34B));
    $out("work logs: {$s}; UAT34B ".$logs->count().', minutes '.$logs->sum('duration_minutes'));

    [$s, $b] = $call($lia, fn ($r) => app(ProjectController::class)->index($r), ['member' => $lia->staff->public_id, 'q' => 'UAT34B']);
    $out("projects (mine, q=UAT34B): {$s}; ".collect($b['data'] ?? [])->pluck('name')->implode(', '));

    [$s, $b] = $call($lia, fn ($r) => app(ClientController::class)->index($r), ['status' => 'active', 'q' => 'UAT34B']);
    $out("clients (q=UAT34B): {$s}; ".collect($b['data'] ?? [])->pluck('name')->implode(', '));

    [$s, $b] = $call($lia, fn ($r) => app(MyScheduleController::class)->index($r), ['from' => $today, 'to' => $D(6)]);
    $items = collect($b['data']['items'] ?? [])->filter(fn ($i) => str_starts_with($i['title'], T34B));
    $out("schedule week: {$s}; UAT34B ".$items->count().' ('.$items->pluck('source_type')->countBy()->map(fn ($n, $k) => "$k $n")->implode(', ').')');
    exit(0);
}

$out("unknown stage: {$stage}");
exit(1);
```
