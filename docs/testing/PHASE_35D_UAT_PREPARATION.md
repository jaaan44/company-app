# Phase 35D — UAT Preparation (Add, Edit and Delete in the Staff Portal — Deployment and UAT)

*Operator runbook. No AI session has run anything in this file against the staging VPS; this AI session cannot reach it. Every step marked **[Operator]** is pending until its output is recorded.*

**Status:** UAT-35D-01…08 are `NOT RUN`.
- **No APK, no migration, no data script, and no account or password is created.**
- **Accounts:** an Administrator, `uat30b.staff` (Lia) and `uat30b.manager` (Ben), all in your password manager.
- **This deploy also brings** the Roles & permissions note (PR #121).

**How each operator step is written:**
- **Where:** the machine, the user and the directory.
- **Run:** the exact commands, one block at a time.
- **Expected:** what a good result looks like, and when to **STOP**.
- **Paste back:** what to send to the AI session. **Never paste** a password, anything from your password manager, `.env.staging`, or anything containing `MYSQL_ROOT_PASSWORD`.

## 1. Baselines

| Item | SHA | Meaning |
|---|---|---|
| **UAT source baseline (deploy)** | `2ca60921c257ca06e9f46acf74fbf076fdc3becd` | PR #122 merge into `main`: Phase 35D. |
| Running staging implementation (before §2) | `680444e31093f3aa5b9820ce88642be8a49d4c65` | Phases 35B + 35C. |

**What changes between them:**
- **Web only:**
  - the portal's add, edit and delete screens, with their routes;
  - the Roles screen note;
  - new CSS and JS.
- **No migration** (still 46). No API, dependency, Docker or environment change.

## 2. Staging deployment — **[Operator, VPS]**

**Where:** the VPS, as `deploy`, in `/home/deploy/company-app/company-app-build/company-app`. Run each block separately.

**Block 1: get the code**
```sh
cd /home/deploy/company-app/company-app-build/company-app
git status --porcelain
git fetch origin
git merge --ff-only 2ca60921c257ca06e9f46acf74fbf076fdc3becd
git rev-parse HEAD
```
**Expected:**
- `git status --porcelain` prints nothing;
- then `Updating 680444e..2ca6092` and `Fast-forward`;
- the last line is `2ca60921c257ca06e9f46acf74fbf076fdc3becd`.

**If the `Updating` line starts from anything other than `680444e`: STOP** and paste it.

**Block 2: back up the database**
```sh
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
$C exec mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  > /home/deploy/backups/company-app-$(date +%Y%m%d-%H%M%S).sql
ls -lt /home/deploy/backups/company-app-*.sql | head -3
tail -c 200 "$(ls -t /home/deploy/backups/company-app-*.sql | head -1)" | grep -- '-- Dump completed'
```
**Expected:** the newest file is from now and not empty, and a `-- Dump completed on …` line appears.

**Block 3: build**
```sh
$C build app nginx && echo "BUILD OK"
```
**Expected:** the last line is `BUILD OK`.

**Block 4: restart and check**
```sh
$C up -d --no-deps --force-recreate app nginx
$C exec app php artisan migrate:status | tail -1
$C exec app php artisan config:cache
$C exec app php artisan route:cache
$C exec app php artisan view:cache
$C exec app sh -c 'ls public/build/assets'
$C exec nginx sh -c 'ls /var/www/html/public/build/assets'
$C ps
```
**Expected:**
- the last migration shows `Ran`;
- the same new `app-….css` and `app-….js` names in both containers;
- both containers created seconds ago.

**Block 5: smoke tests**
```sh
B=https://company-staging.storm-ark.com
for p in app/tasks/new app/projects/new app/clients/new app/announcements/manage admin; do
  curl -s -o /dev/null -w "$p %{http_code} -> %{redirect_url}\n" $B/$p
done
CSS=$(curl -s $B/login | grep -o '/build/assets/app-[A-Za-z0-9_-]*\.css' | head -1); curl -s -o /dev/null -w "css %{http_code} %{content_type}\n" "$B$CSS"
```
**Expected:**
- the five pages each show `302 -> https://company-staging.storm-ark.com/login`;
- `css 200 text/css`.

**Anything else: STOP.**

**Paste back:** the output of Blocks 1–5. For the build, its last few lines are enough.

## 3. UAT-time notes (for **execution**)

- **How to read these steps:**
  - "**Admin**" means a computer browser signed in as your Administrator, at `/admin/administration/roles` (Roles & permissions).
  - "**Lia**" means a second browser (or a private window, or a phone browser) signed in as `uat30b.staff`.
- **When a permission changes:** reload Lia's page to see the effect.
- **What stays on staging:** what you create in UAT. Names start with `UAT35D` so they're easy to recognise. Cleanup needs its own authorization.

**UAT-35D-01: Defaults show nothing new**
1. Admin: **Reset to defaults**, then **Save changes → Save**. If Save is greyed out, the defaults are already in place.
2. Lia, in the portal:
   - **Tasks** has no "+" and no Mine/All;
   - a task's page has no Edit task or Delete task;
   - **More → Projects** has no "+";
   - **More → Clients** has no "+", and a client has no Edit client;
   - **More → Announcements** has no Manage announcements.
3. As `uat30b.manager` (Ben), there is no "+" anywhere either. Ben **does** see Mine/All on Tasks and Projects, because Managers already may view all of them.

**UAT-35D-02: Tasks**
1. Admin, **Tasks** card: tick **Staff** on Add tasks, Edit any task and Delete tasks. View all tasks ticks itself. Then **Save changes → Save**.
2. Lia, **Tasks**: a "+" (New task) and **Mine / All** appear. **All** lists colleagues' tasks, with who they're assigned to.
3. Click "+":
   - **Add task** with no title shows "Give the task a title.".
   - Fill in Title `UAT35D task`, Project **UAT34E Dock repairs**, Assigned to (search `ben`, choose **Ben**), Priority **High**, then **Add task**.
   - The task opens with **"Task added."**
4. **Edit task:**
   - the project is fixed ("A task stays in its project");
   - change the title to `UAT35D task (edited)` and **Save changes**: **"Changes saved."**
5. **Delete task**, then **Delete** in the dialog: you're back on Tasks with **"Task deleted."**

**UAT-35D-03: A project lead without the task permissions**
1. Admin: **Reset to defaults**, then **Save**.
2. As your Administrator in the **portal** (`/app`), open **More → Projects**, then **All**, then **UAT34E Dock repairs**. On Lia's row in **Members**, click **Make lead**.
3. Lia:
   - **Tasks** shows the "+";
   - **New task** offers only **UAT34E Dock repairs** (and no "No project");
   - add `UAT35D lead task`. It opens with **Edit task** but no Delete task.
4. Lia opens a task in a project she does **not** lead: no Edit task.
5. Admin (portal, the same project): on Lia's row, click **Make member** to put things back.

**UAT-35D-04: Projects**
1. Admin, **Projects** card: tick **Staff** on all five, then **Save**.
2. Lia, **More → Projects**: "+" (New project), then:
   - Name `UAT35D project`;
   - Client **UAT34E Pier Logistics**;
   - Status **Active**;
   - **Add project**: **"Project added."**
3. On the project:
   - **Add a member:** search `ben`, choose Ben, Role **Project lead**, **Add member**. Then on Ben's row: **Make member**.
   - **New milestone:** `UAT35D milestone`, due next month, **Add milestone**. Then set its status to **Completed**.
4. **Delete project**, then **Delete**: a red message explains why it can't be deleted (members or milestones).
5. Remove Ben, and remove the milestone (each confirmed). Then **Delete project** again: you're back on Projects with **"Project deleted."**

**UAT-35D-05: Clients**
1. Admin, **Clients** card: tick **Staff** on Add, Edit and Delete clients (View ticks itself), then **Save**.
2. Lia, **More → Clients**: "+" (New client).
   - **Add client** with nothing filled in shows a message under Name.
   - Fill in `UAT35D client`, City `Cebu`, then **Add client**: **"Client added."**
3. **Add contact:**
   - Jane / Doe, Primary contact, **Add contact**: **"Contact added."**
   - **Edit** the contact, set the Job title, **Save changes**.
   - **Remove** the contact, then **Remove** in the dialog.
4. **Edit client:** set Status **Inactive**, then **Save**. Then **Delete client**, then **Delete**: **"Client deleted."**

**UAT-35D-06: Announcements**
1. Admin, **Announcements** card: tick **Staff** on all four, then **Save**.
2. Lia, **More → Announcements → Manage announcements**, then "+" (New announcement):
   - **Save draft** empty shows messages.
   - Choose **Selected departments or teams**, then **Save draft** with none ticked: "Choose at least one department or team."
   - Choose **Everyone**, Title `UAT35D notice`, a short text, then **Save draft**.
3. On the draft:
   - **Edit draft:** change the text, then save.
   - **Publish**, then **Publish** in the dialog: **"Announcement published."** The phone app (Lia, **More → Announcements**) shows it.
   - **Archive**, then **Archive** in the dialog.
4. Make another draft (`UAT35D draft`), then **Delete draft**, then **Delete**.

**UAT-35D-07: Taking permissions away**
1. Admin: **Reset to defaults**, then **Save**.
2. Lia: reload Tasks, Projects, Clients and Announcements. Every "+", Edit, Delete and Manage is gone.

**UAT-35D-08: Phone, dark mode, zoom, keyboard**
1. Repeat a few steps of 02–06 on a phone browser:
   - the forms fit with no sideways scrolling;
   - the "+" sits at the bottom right;
   - dialogs come up from the bottom.
2. Dark mode, and 200% zoom on a computer: everything is readable.
3. Keyboard only:
   - Tab reaches every field, the assignee and member search results, and the dialog buttons, with a visible outline;
   - Enter chooses;
   - Esc closes a dialog without acting.

**Also, UAT-35BC:** please report its results with these: 01–08, PASS or FAIL.

**Afterwards:** report each scenario as **PASS**, or **FAIL with what you saw**. Only you record a `PASS`.
