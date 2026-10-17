# Phase 37B — UAT Preparation (Web UI/UX Improvement — Deployment and UAT)

*Operator runbook. No AI session has run anything in this file against the staging VPS, because this AI session can't reach it. Every step marked **[Operator]** is pending until its output is recorded.*

**Status:** UAT-37B-01…08 are `NOT RUN`.
- **No APK, no migration, no script.** The Flutter app is paused (DEC-067).
- **No new account is created.** The scenarios use the existing UAT accounts:
  - Lia `uat30b.staff` and Ben `uat30b.manager`;
  - Nina `uat36a.nina@company-app.test` from 36A;
  - your Administrator.

  Their passwords are in your password manager.

**How each operator step is written:** as in `PHASE_37_UAT_PREPARATION.md`:
- **Where:** the machine, the user and the directory.
- **Run:** the exact commands.
- **Expected:** what a good result looks like, and when to **STOP**.
- **Paste back:** what to send to the AI session. **Never paste** a password, anything from your password manager, `.env.staging`, or anything containing `MYSQL_ROOT_PASSWORD`.

## 1. Baselines

| Item | SHA | Meaning |
|---|---|---|
| **UAT source baseline (deploy)** | `c72cfefea340faf4c707b8d57426948b4c702e9a` | PR #139 merge into `main`: Phase 37B. |
| Running staging implementation (before §2) | `bbe729172bae423dc535e582a6ce3ded8c78689a` | Phase 37 (confirmed by the 37 deploy). |

**What changes between them:**
- **The web app:**
  - shared button and field styles, and field borders with more contrast;
  - saving and loading spinners, and Clear filters;
  - focus after page changes and failed saves;
  - Chat opening at the latest message;
  - the Backoffice dashboard's Needs attention;
  - section labels; password Show/Hide;
  - the Messages badge and sortable report columns.

  The CSS and JS file names change, so **both images are rebuilt** (nginx serves the built assets).
- **The API:**
  - a new read-only `GET /api/v1/conversations/unread-count`;
  - `?sort=` on the seven reports and their exports.
- **No migration.** `composer.lock`, `package-lock.json`, the Dockerfiles, the compose file, the nginx config and the environment variables are unchanged. The commits between the two SHAs outside 37B are documentation only.

## 2. Staging deployment — **[Operator, VPS]**

**Where:** the VPS, as `deploy`, in `/home/deploy/company-app/company-app-build/company-app`. Run each block separately.

**Block 1: get the code**
```sh
cd /home/deploy/company-app/company-app-build/company-app
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
git fetch origin
git status --porcelain
git merge --ff-only c72cfefea340faf4c707b8d57426948b4c702e9a
git rev-parse HEAD
```
**Expected:**
- `git status --porcelain` prints nothing;
- then `Updating bbe7291..c72cfef` and `Fast-forward`;
- the last line is `c72cfefea340faf4c707b8d57426948b4c702e9a`.

**If the `Updating` line starts from anything other than `bbe7291`: STOP** and paste it.

**Block 2: back up the database** (a precaution; nothing changes the schema)
```sh
$C exec mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' > /home/deploy/backups/company-app-$(date +%Y%m%d-%H%M%S).sql
ls -lt /home/deploy/backups/company-app-*.sql | head -2
tail -c 200 "$(ls -t /home/deploy/backups/company-app-*.sql | head -1)" | grep -- '-- Dump completed'
```
**Expected:** the newest file is from now and isn't empty, and a `-- Dump completed on …` line appears. **Without it: STOP.**

*Tip: paste the first line (`$C exec mysql …`) as **one line**, exactly as shown.*

**Block 3: build, on its own**
```sh
$C build app nginx && echo "BUILD OK"
```
**Expected:** the last line is `BUILD OK`. **On `ERROR` or `failed to solve`: STOP** and paste the error lines; the running site stays as it is.

**Block 4: restart, caches**
```sh
$C up -d --no-deps --force-recreate app nginx
$C exec app php artisan migrate:status | tail -2
$C exec app php artisan config:cache
$C exec app php artisan route:cache
$C exec app php artisan view:cache
$C ps
```
**Expected:**
- the last migration is still `2026_10_16_000000_add_must_change_password_to_users_table … Ran` (nothing pending);
- three `… cached successfully` lines;
- `company-app-api` and `company-app-nginx` created just now and `Up`;
- `company-app-mysql` `healthy`.

**Block 5: smoke tests** (read-only)
```sh
B=https://company-staging.storm-ark.com
for p in app app/tasks admin; do curl -s -o /dev/null -w "$p %{http_code} -> %{redirect_url}\n" $B/$p; done
curl -s -o /dev/null -w "api unread-count %{http_code}\n" -H "Accept: application/json" $B/api/v1/conversations/unread-count
curl -s -o /dev/null -w "api report sort %{http_code}\n" -H "Accept: application/json" "$B/api/v1/reports/tasks?sort=-title"
curl -s $B/login | grep -c 'aria-controls="password"'
CSS=$($C exec -T nginx sh -c 'basename $(ls /var/www/html/public/build/assets/*.css | head -1)')
echo "css file: $CSS"
curl -s -H "Accept-Encoding: identity" http://127.0.0.1:8012/build/assets/$CSS | grep -o "\.btn-primary{" | head -1
$C exec app php artisan route:list --path=conversations/unread-count | grep -c unread-count
```
**Expected:**
- **The three pages:** `app`, `app/tasks` and `admin` each show `302 -> https://company-staging.storm-ark.com/login`.
- `api unread-count 401` and `api report sort 401`: the new routes exist and need sign-in. A `404` means the code didn't deploy.
- The count line is `1`, from the sign-in password's Show button.
- `css file: app-….css`, and then `.btn-primary{`, showing the new styles are built.
- The route count line is `1`.

**Anything else: STOP** and paste the output.

**Paste back:** the output of Blocks 1–5. For the build, its last few lines are enough.

## 3. If something goes wrong (rollback) — **[Operator, VPS]**, only if the AI session asks

```sh
git checkout --detach bbe729172bae423dc535e582a6ce3ded8c78689a
$C build app nginx && $C up -d --no-deps --force-recreate app nginx
$C exec app php artisan route:cache && $C exec app php artisan view:cache
```
Nothing in the database needs undoing.

## 4. UAT-time notes (for **execution**)

**What you need:**
- **your own iPhone (Safari)** and **your own Android phone (Chrome)**, each using the staff portal at `https://company-staging.storm-ark.com`;
- **a computer** with a desktop browser for the Administrator.

Sign in as **Lia** on the phones and as your **Administrator** on the computer. **Ben** is needed only to send messages in 37B-07. Paste passwords from your password manager into the sign-in fields only.

Switch each phone between **light and dark** mode at least once during the scenarios.

**Report** each scenario as **PASS**, or **FAIL with what you saw** (a screenshot helps). Only you record a `PASS`.

**UAT-37B-01: Fields and buttons look right** (both phones, light and dark)
1. On the sign-in page and on **More → Work logs → +**: every box (text, date, dropdown) has a **clearly visible border**, in light and in dark.
2. Buttons are **pills**: the main one is filled (**Save**, **Sign in**), Cancel is plain text, and **Delete…** is outlined in red.
3. On **Tasks** (Open / Done) the switch is a pill; on a task, the status choices are pills.
4. Nothing looks broken or out of place compared with before.

**UAT-37B-02: Saving and loading show** (a phone, then the computer)
1. On a phone, save any form (a work log): the button **dims and shows a small spinner** until it finishes.
2. **More → Staff directory**, type a name: a spinner shows at the **right end of the search box** while results load.
3. On the computer, as the Administrator, save something in the Backoffice (for example **HR → Leave types**, edit a type → Save): the button dims and spins.

**UAT-37B-03: Focus and Chat** (a phone)
1. **Tasks → +** (if Lia can add tasks; otherwise **Work logs → +**): leave the required field empty and tap Save. The cursor **lands in that field**, with its message under it.
2. **Messages** → open a conversation with many messages: it opens **at the newest message**. The message box stays at the bottom, above the tab bar, while you scroll up. Send one: you're taken back to the end.

**UAT-37B-04: Backoffice dashboard** (computer, Administrator)
1. **Admin** (Dashboard): **Needs attention** comes first, with **Leave**, **Open incidents** and **Overdue tasks**.
2. Click **Leave**: Leave requests opens (Pending). Back, click **Open incidents**: Incidents opens. Back, click **Overdue tasks**: the **Tasks report** opens with **Overdue only** ticked.
3. Below, **Overview** has the From/To form and the other figures.

**UAT-37B-05: Sections and Clear filters** (computer, Administrator)
1. **People** lists only **Staff records** and **Departments, teams and positions**.
2. **Clients** and **Work** (Projects, Tasks) and **Communication**: those items say **Opens in the staff portal**.
3. **People → Staff records**: choose Status **Inactive**: **Clear filters** appears. Click it: back to all. Do the same on **HR → Leave requests** (it returns to **Pending**), on **Operations → Incidents**, and on one **Report**.
4. **Administration → Audit log**: actions read as words (for example "Staff: updated"), with the technical name after them.

**UAT-37B-06: Sign-in and status chips** (a phone, then the computer)
1. On the sign-in page, type a password and tap **Show**: it's readable and the button says **Hide**. Tap **Hide**. Sign in: it works.
2. On the computer, **Administration → Users and roles**: a suspended account (if any) shows an **amber "Suspended"** chip; an account that must choose a password, an **amber "Must choose a password"** chip.

**UAT-37B-07: Messages badge** (a phone as Lia, the computer as Ben)
1. On the computer, sign in as **Ben** and send Lia two messages (`[UAT37B] one`, `[UAT37B] two`).
2. On Lia's phone, open any page (or reload): the **Messages** tab shows **2**.
3. Open the conversation, then tap another tab: the badge is **gone**.
4. On the computer, as your **Administrator** (no staff profile), the portal's Messages tab shows **no badge**, and pages still open normally.

**UAT-37B-08: Sortable reports** (computer, Administrator)
1. **Reports → Tasks**: click the **Task** header: rows sort **A–Z** (↑). Click again: **Z–A** (↓).
2. Click **Due**: rows sort by due date.
3. **Download CSV**: the rows are in the **same order** as on screen.
4. **Status** and **Priority** headers aren't clickable.
5. On **Reports → Work logs**, click **Time**: shortest first.

**Afterwards:** report each scenario. The `[UAT37B]` messages stay on staging; cleaning them up needs its own authorization.
