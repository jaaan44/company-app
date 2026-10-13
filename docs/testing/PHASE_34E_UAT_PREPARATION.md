# Phase 34E — UAT Preparation (Staff Web Portal: Reports — Deployment, Data and UAT)

*Operator runbook. No AI session has run anything in this file against the staging VPS; this AI session cannot reach it. Every step marked **[Operator]** is pending until its output is recorded. The data script in §6 was rehearsed on a disposable scratch SQLite database only (see `docs/testing/TEST_STATUS.md`, "Phase 34E — UAT preparation").*

**Status:** UAT-34E-01…06 are `NOT RUN`. **No APK is needed**: 34E changes no mobile code. **No account or password is created**: the scenarios use the UAT30B accounts already in your password manager.

**How each operator step is written:**
- **Where:** the machine, the user and the directory.
- **Run:** the exact commands, one block at a time.
- **Expected:** what a good result looks like, and when to **STOP**.
- **Paste back:** what to send to the AI session. **Never paste** a password, anything from your password manager, `.env.staging`, or anything containing `MYSQL_ROOT_PASSWORD`.

## 1. Baselines

| Item | SHA | Meaning |
|---|---|---|
| **UAT source baseline (deploy)** | `bcdd6f577895c4f45ec83dd870f1e9f0854b63ca` | PR #108 merge into `main`: Phase 34E. |
| Running staging implementation (before §2) | `fd57c7c0a72984bcf8939419425b3f61d0347640` | Phase 34D. |

**What changes between them:**
- **Web only:** the Reports pages, the two report forms, the report pages with files, the file download route, and `InternalApi` gaining `upload()` and `raw()`. Everything else in between is documentation.
- **No migration** (still 45 Ran). `composer.lock`, `package-lock.json`, the Docker files and the environment variables are unchanged. The upload limits already in place since Phase 33 (PHP 12 MB / 13 MB, the container and host nginx 12 MB) are what 34E relies on.
- **CI built both staging images** on this exact change (the "Build staging images" check on PR #108).

## 2. Staging deployment — **[Operator, VPS]**

**Where:** the VPS, as `deploy`, in `/home/deploy/company-app/company-app-build/company-app`. Run each block separately.

**Block 1: get the code**
```sh
cd /home/deploy/company-app/company-app-build/company-app
git status --porcelain
git fetch origin
git merge --ff-only bcdd6f577895c4f45ec83dd870f1e9f0854b63ca
git rev-parse HEAD
```
**Expected:** `git status --porcelain` prints nothing; then `Updating fd57c7c..bcdd6f5` and `Fast-forward`; the last line is `bcdd6f577895c4f45ec83dd870f1e9f0854b63ca`. **If the `Updating` line starts from anything other than `fd57c7c`: STOP** and paste it.

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
for p in app/reports app/reports/service/new app/reports/incidents/new app/reports/service/01ZZZZZZZZZZZZZZZZZZZZZZZZ/files/01ZZZZZZZZZZZZZZZZZZZZZZZZ; do
  curl -s -o /dev/null -w "$p %{http_code} -> %{redirect_url}\n" $B/$p
done
CSS=$(curl -s $B/login | grep -o '/build/assets/app-[A-Za-z0-9_-]*\.css' | head -1); curl -s -o /dev/null -w "css %{http_code} %{content_type}\n" "$B$CSS"
for p in service-reports incident-reports; do
  curl -s -o /dev/null -w "api $p %{http_code}\n" -H 'Accept: application/json' $B/api/v1/$p
done
```
**Expected:**
- the four pages (including the file route) each show `302 -> https://company-staging.storm-ark.com/login`;
- `css 200 text/css`;
- two `api … 401` lines.

**Anything else: STOP** and paste the output.

**Paste back:** the output of Blocks 1–5; for the build, its last few lines are enough.

## 3. UAT data plan

**Accounts:** `uat30b.staff` (Lia); Ben (`uat30b.manager`) appears only as the reviewer and assignee in the history. `D` is the company day of the last `seed`.

- **Client "UAT34E Pier Logistics"** (code UAT34E, active) and **project "UAT34E Dock repairs"** (Active; Ben lead, Lia member).
- **A rejected service report of Lia's** (`[UAT34E]`): UAT34E Pier Logistics, UAT34E Dock repairs, service date **D−3**, work performed "Replaced two rotten dock boards." / "Secured the railing.", findings "The rest of the boards are sound.". History: Submitted by Lia, then **Rejected by Ben, "Add the board count and a photo, please."**
- **A resolved incident of Lia's** (`[UAT34E]`): Workplace safety, **High**, **Resolved**, 5 days before the seed, at Pier 3, assigned to Ben, with root cause, corrective action and resolution. History: Reported, Assigned, Resolved ("Board replaced.").

**A `seed` resets only these** (the two `[UAT34E]` reports, with their history and any files added to them). Reports you create during UAT are kept.

**Files for UAT-34E-03** (on the computer you test from):
- **a photo**: any JPEG or PNG, for example one from your phone;
- **a PDF**: any PDF under 10 MB;
- **a file of another type**: any `.txt`, `.docx` or similar;
- **a file over 10 MB** (optional): for example a long video or a large photo. Between 10 and 12 MB the page says "The file is larger than 10 MB."; above 12 MB the upload itself is stopped by the size limits and the page says "The file failed to upload.". Either refusal is a pass.

## 4. Staging data — **[Operator, VPS]**

**Where:** the VPS, as `deploy`, in the staging checkout, after §2.

**Step 0: get the script and check it**
```sh
cd /home/deploy/company-app/company-app-build/company-app
git fetch origin main
git show origin/main:docs/testing/PHASE_34E_UAT_PREPARATION.md | awk '/^```php$/{f=1;next} /^```$/{f=0} f' > "$HOME/uat34e_data.php"
sha256sum "$HOME/uat34e_data.php"
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
W="$HOME/uat34e_data.php"
$C exec -T app php -- plan < "$W"
```
**Expected:**
- the SHA equals the one in §6. **Any mismatch: STOP.**
- then:
```
stage=plan utc_now=<now> company_day=<D> (Asia/Manila)
staff     exists, staff=UAT30B-001
manager   exists, staff=UAT30B-002
UAT34E client: absent, [UAT34E] service reports: 0, incidents: 0
```

**Step 1: seed and verify**
```sh
$C exec -T app php -- seed < "$W"; echo "seed exit=$?"
$C exec -T app php -- verify < "$W"
```
**Expected:**
```
stage=seed utc_now=<now> company_day=<D> (Asia/Manila)
seed: UAT34E client and project (Lia member), [UAT34E] rejected service report and resolved incident for Lia, set for company day <D>
seed: done (0 old file(s) removed)
seed exit=0
stage=verify utc_now=<now> company_day=<D> (Asia/Manila)
Lia service reports: 200; [UAT34E] UAT34E Pier Logistics <D-3> rejected
Lia incidents: 200; [UAT34E] workplace_safety high resolved
UAT34E project: UAT34E Dock repairs, Lia member: yes
attachments limit: 10240 KB; upload_max_filesize=12M, post_max_size=13M
```
The last line confirms the container's PHP upload limits. **STOP** on a non-zero exit, `ERROR`, `MISSING`, `UNEXPECTED`, or any line that differs.

**Paste back:** the `sha256sum` line and the three outputs.

**On a later UAT day, or to start a scenario again:** run `seed` and `verify` again.

## 5. UAT-time notes (for **execution**)

- **Browsers:** a desktop browser, plus your phone's browser for 34E-06, at `https://company-staging.storm-ark.com`.
- **Sign in as `uat30b.staff`** (Lia), pasting the password from your password manager into the sign-in page only.
- **Phone app:** keep it signed in as Lia for the comparisons (More → Reports).

**UAT-34E-01: Reports**
1. The navigation shows **… Announcements, Reports, Staff directory …**. Click **Reports**.
2. **Service reports** lists **UAT34E Pier Logistics**, "<D−3 date> · UAT34E Dock repairs", **Rejected**, among any others. The phone's Service reports shows the same.
3. Click **Incidents**: **Workplace safety**, "<date> <time> · Pier 3", **High** and **Resolved**. The phone's Incidents shows the same.

**UAT-34E-02: New service report**
1. On **Service reports**, click **New service report**. Click **Save draft** with nothing filled in: **"Choose a client."** and **"Describe the work performed."**
2. Set the service date to **tomorrow** and click Save draft: **"The service date cannot be later than today."** Set it back to today.
3. In the client search type **`UAT34E`** and click **UAT34E Pier Logistics**. Under **Project (optional)** choose **UAT34E Dock repairs**.
4. In **Work performed** type **`UAT34E web report`**. Click **Save draft**: the report opens with **"Draft saved."**, **Draft**, the client, project and today's date.
5. The phone's Service reports (pull to refresh) shows it as a **Draft**.

**UAT-34E-03: Photos and files** (on the draft from 34E-02)
1. Under **Add a photo or PDF**, choose your **photo** and click **Upload**: **"File added."**, and the photo shows with its name and size.
2. Choose your **PDF** and click **Upload**: it appears with **Download**. **Photos and files (2)**.
3. Choose the **file of another type** and click **Upload**: **"Choose a JPEG, PNG or PDF file."**, and nothing is added.
4. (Optional) Choose the **file over 10 MB** and click **Upload**: "The file is larger than 10 MB." or "The file failed to upload.", and nothing is added.
5. Click **Open photo**: the photo opens full size in a new tab. Click **Download** on the PDF: the PDF downloads under its own name.
6. Click **Remove** on the PDF and confirm: **"File removed."**, **Photos and files (1)**.
7. On the phone, open the draft: the photo is there.

**UAT-34E-04: Submit, Return to draft, Delete draft**
1. On the draft, click **Submit**. The browser asks "Submit this report? Your manager will review it. You can't edit it after submitting."; click **OK**. **"Report submitted."**; the status is **Submitted**, and Edit, Delete draft, Remove and Upload are gone. The phone shows it **Submitted**.
2. Go to **Reports** and open the **Rejected** UAT34E Pier Logistics report. History shows **"Rejected by Ben UAT30B-Cruz"** with "Add the board count and a photo, please.". There is **Return to draft** and no Edit.
3. Click **Return to draft** and confirm: **"Returned to draft."**; it is a **Draft** with **Edit**, **Submit** and **Delete draft**.
4. Click **Delete draft** and confirm ("Delete this draft? This can't be undone."): you're back on **Reports** with **"Draft deleted."**, and it's gone from the list (and from the phone after a refresh). *A later `seed` brings it back.*

**UAT-34E-05: Incidents**
1. On **Reports → Incidents**, click **Report an incident**. The date and time are now; Severity is **Medium**. Click **Report incident**: **"Choose the type of incident."** and **"Describe what happened."**
2. Set the time an hour ahead: **"The time cannot be in the future."** Set it back.
3. Choose **Workplace safety**, severity **Low**, location **`UAT34E Pier 1`**, what happened **`UAT34E web incident`**. Click **Report incident**: the incident opens with **"Incident reported."**, **Low**, **Reported**, your time and location, and **Edit**.
4. Click **Edit**, change the location to **`UAT34E Pier 2`**, **Save changes**: **"Changes saved."**, and the page shows Pier 2. Add your photo with **Upload**.
5. The phone's Incidents shows it, with the photo.
6. Open the **Resolved** Workplace safety incident (Pier 3): it shows **Assigned to Ben UAT30B-Cruz**, the root cause, corrective action, resolution and history, and **no Edit and no Upload**.

**UAT-34E-06: Display, phone browser and keyboard**
1. **Dark mode:** Reports, both forms, a service report and an incident with files are readable.
2. **Zoom:** at **200%**, the navigation wraps, nothing scrolls sideways, and every button can be clicked.
3. **Phone browser:** open the same pages on your phone's browser. Then, on a **new draft** made in the phone's browser (or the incident from 34E-05), **upload a photo from the phone** (the file chooser offers the camera or the gallery); it appears on the page.
4. **Keyboard only** (desktop): on a draft, Tab reaches Edit, Submit, Delete draft, each file's Open/Download and Remove, the file input and Upload, with a visible outline.

**Afterwards:** the UAT34E records, and the reports and files you created, stay on staging (cleanup needs its own authorization). Report each scenario as **PASS**, or **FAIL with what you saw**. Only you record a `PASS`.

## 6. Script — `uat34e_data.php`

**Revision 1 (2026-10-13), SHA-256 `7b894d2a4682eac0f43c939d6d55ac386db311f1cd917013ec7aed0597326882`** (the exact text below, with a trailing newline). It requires `uat30b_data.php`'s accounts. Rehearsed on scratch SQLite: `plan`, `seed`, `verify`, a re-seed (identical `verify`; a file attached to a seeded report was removed with it: "1 old file(s) removed"), and an unknown stage (exit 1).

```php
<?php

/*
 * Phase 34E UAT data — operator tool (NOT application code; never commit
 * to apps/api, never expose over HTTP).
 *
 * Uses the UAT30B accounts made by uat30b_data.php: Lia (staff) and Ben
 * (manager). It creates NO account and prints NO password.
 *
 * Run inside the staging `app` container, reading this file from stdin:
 *
 *   $C exec -T app php -- <stage> < uat34e_data.php
 *
 * Stages:
 *   plan    read-only: the UAT30B accounts and what UAT34E data exists
 *   seed    idempotent; sets the client "UAT34E Pier Logistics" (code
 *           UAT34E) and the project "UAT34E Dock repairs" (Ben lead, Lia
 *           member), and replaces only the "[UAT34E]" service reports and
 *           incidents: a rejected service report of Lia's (Ben's reason in
 *           its history) and a resolved incident of Lia's. Files attached
 *           to those two are removed with them. Reports made in the apps
 *           are kept.
 *   verify  read-only: runs the real controllers as Lia and prints what the
 *           web portal and the phone see
 *
 * No secret is ever printed. Any failure exits 1 and changes nothing
 * (one transaction).
 */

use App\Enums\ClientStatus;
use App\Enums\IncidentReportActionType;
use App\Enums\IncidentReportStatus;
use App\Enums\IncidentReportType;
use App\Enums\IncidentSeverity;
use App\Enums\ProjectMembershipRole;
use App\Enums\ProjectStatus;
use App\Enums\ServiceReportActionType;
use App\Enums\ServiceReportStatus;
use App\Http\Controllers\Api\V1\IncidentReports\IncidentReportController;
use App\Http\Controllers\Api\V1\ServiceReports\ServiceReportController;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\IncidentReport;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ServiceReport;
use App\Models\User;
use App\Support\Attachments\AttachmentDisk;
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

const T34E = '[UAT34E] ';

$emails = [
    'staff' => 'uat30b.staff@company-app.test',
    'manager' => 'uat30b.manager@company-app.test',
];

$tz = CompanyTimezone::value();
$today = OverdueTasks::todayInCompanyTimezone();
$D = fn (int $days) => Carbon::parse($today)->addDays($days)->toDateString();
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
    $out('UAT34E client: '.(Client::query()->where('client_code', 'UAT34E')->exists() ? 'exists' : 'absent')
        .', [UAT34E] service reports: '.ServiceReport::query()->where('work_performed', 'like', T34E.'%')->count()
        .', incidents: '.IncidentReport::query()->where('description', 'like', T34E.'%')->count());
    exit(0);
}

if ($stage === 'seed') {
    $need();
    $liaUser = $users['staff'];
    $benUser = $users['manager'];
    $lia = $liaUser->staff;
    $ben = $benUser->staff;
    $now = now();
    $files = [];

    DB::transaction(function () use ($lia, $ben, $liaUser, $benUser, $D, $now, $today, $out, &$files): void {
        $client = Client::query()->firstOrNew(['client_code' => 'UAT34E']);
        $client->fill(['name' => 'UAT34E Pier Logistics', 'status' => ClientStatus::Active, 'city' => 'Pasig', 'country' => 'Philippines'])->save();
        $project = Project::query()->firstOrNew(['project_code' => 'UAT34E']);
        $project->fill([
            'name' => 'UAT34E Dock repairs', 'client_id' => $client->id, 'status' => ProjectStatus::Active,
            'start_date' => $D(-20), 'target_end_date' => $D(40), 'completed_date' => null,
        ])->save();
        foreach ([[$ben, ProjectMembershipRole::ProjectLead], [$lia, ProjectMembershipRole::Member]] as [$staff, $role]) {
            ProjectMembership::query()->firstOrNew(['project_id' => $project->id, 'staff_id' => $staff->id])->fill(['role' => $role])->save();
        }

        // Only "[UAT34E]" reports are replaced, with their history and files.
        $old = ServiceReport::query()->where('work_performed', 'like', T34E.'%')->pluck('id');
        $oldIncidents = IncidentReport::query()->where('description', 'like', T34E.'%')->pluck('id');
        $attachments = Attachment::query()->whereIn('service_report_id', $old)->orWhereIn('incident_report_id', $oldIncidents)->get();
        $files = $attachments->pluck('storage_path')->all();
        Attachment::query()->whereKey($attachments->modelKeys())->delete();
        DB::table('service_report_actions')->whereIn('service_report_id', $old)->delete();
        DB::table('service_report_participants')->whereIn('service_report_id', $old)->delete();
        ServiceReport::query()->whereKey($old)->delete();
        DB::table('incident_report_actions')->whereIn('incident_report_id', $oldIncidents)->delete();
        DB::table('incident_report_participants')->whereIn('incident_report_id', $oldIncidents)->delete();
        IncidentReport::query()->whereKey($oldIncidents)->delete();

        // A rejected service report of Lia's, with Ben's reason.
        $report = new ServiceReport([
            'client_id' => $client->id, 'project_id' => $project->id, 'creator_staff_id' => $lia->id, 'created_by_user_id' => $liaUser->id,
            'service_date' => $D(-3), 'work_performed' => T34E."Replaced two rotten dock boards.\nSecured the railing.",
            'findings' => 'The rest of the boards are sound.', 'status' => ServiceReportStatus::Rejected,
        ]);
        $report->created_at = $now->copy()->subDays(3);
        $report->save();
        foreach ([
            [ServiceReportActionType::Submitted, $liaUser, null, $now->copy()->subDays(3)->addHour()],
            [ServiceReportActionType::Rejected, $benUser, 'Add the board count and a photo, please.', $now->copy()->subDays(2)],
        ] as [$action, $by, $note, $at]) {
            $a = $report->actions()->make(['action' => $action, 'acted_by_user_id' => $by->id, 'note' => $note]);
            $a->created_at = $at;
            $a->updated_at = $at;
            $a->save();
        }

        // A resolved incident of Lia's, assigned to Ben.
        $incident = new IncidentReport([
            'reporter_staff_id' => $lia->id, 'assigned_to_staff_id' => $ben->id, 'created_by_user_id' => $liaUser->id,
            'occurred_at' => $now->copy()->subDays(5), 'location' => 'Pier 3', 'incident_type' => IncidentReportType::WorkplaceSafety,
            'severity' => IncidentSeverity::High, 'description' => T34E.'A loose board tipped when stepped on.',
            'immediate_action_taken' => 'Taped off the area.', 'root_cause' => 'Rotten board.', 'corrective_action' => 'Board replaced.',
            'resolution' => 'Board replaced and the area reopened.', 'status' => IncidentReportStatus::Resolved,
        ]);
        $incident->created_at = $now->copy()->subDays(5);
        $incident->save();
        foreach ([
            [IncidentReportActionType::Reported, $liaUser, null, $now->copy()->subDays(5)],
            [IncidentReportActionType::Assigned, $benUser, null, $now->copy()->subDays(5)->addHour()],
            [IncidentReportActionType::Resolved, $benUser, 'Board replaced.', $now->copy()->subDays(4)],
        ] as [$action, $by, $note, $at]) {
            $a = $incident->actions()->make(['action' => $action, 'acted_by_user_id' => $by->id, 'note' => $note]);
            $a->created_at = $at;
            $a->updated_at = $at;
            $a->save();
        }

        $out("seed: UAT34E client and project (Lia member), [UAT34E] rejected service report and resolved incident for Lia, set for company day {$today}");
    });
    foreach ($files as $path) {
        AttachmentDisk::filesystem()->delete($path);
    }
    $out('seed: done ('.count($files).' old file(s) removed)');
    exit(0);
}

if ($stage === 'verify') {
    $need();
    $lia = $users['staff'];

    [$s, $b] = $call($lia, fn ($r) => app(ServiceReportController::class)->index($r), ['per_page' => 100]);
    $mine = collect($b['data'] ?? [])->filter(fn ($x) => str_starts_with($x['work_performed'], T34E));
    $out("Lia service reports: {$s}; [UAT34E] ".$mine->map(fn ($x) => "{$x['client']['name']} {$x['service_date']} {$x['status']}")->implode(', '));
    [$s, $b] = $call($lia, fn ($r) => app(IncidentReportController::class)->index($r), ['per_page' => 100]);
    $inc = collect($b['data'] ?? [])->filter(fn ($x) => str_starts_with($x['description'], T34E));
    $out("Lia incidents: {$s}; [UAT34E] ".$inc->map(fn ($x) => "{$x['incident_type']} {$x['severity']} {$x['status']}")->implode(', '));
    $project = Project::query()->where('project_code', 'UAT34E')->first();
    $out('UAT34E project: '.($project ? $project->name.', Lia member: '.(ProjectMembership::query()->where('project_id', $project->id)->where('staff_id', $lia->staff->id)->exists() ? 'yes' : 'NO — UNEXPECTED') : 'MISSING — UNEXPECTED'));
    $out('attachments limit: '.config('attachments.max_size_kb').' KB; upload_max_filesize='.ini_get('upload_max_filesize').', post_max_size='.ini_get('post_max_size'));
    exit(0);
}

$out("unknown stage: {$stage}");
exit(1);
```
