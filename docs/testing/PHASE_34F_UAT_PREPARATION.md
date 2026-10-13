# Phase 34F — UAT Preparation (Staff Web Portal: Portal as an app — Deployment and UAT)

*Operator runbook. No AI session has run anything in this file against the staging VPS; this AI session cannot reach it. Every step marked **[Operator]** is pending until its output is recorded.*

**Status:** UAT-34F-01…07 are `NOT RUN`.
- **No APK is needed:** 34F changes no mobile code.
- **No account or password is created.** The scenarios use the UAT30B accounts already in your password manager.
- **No new data script.** One optional step (§3) re-runs the existing 34E script to bring back its rejected report, for the Return to draft dialog.

**How each operator step is written:**
- **Where:** the machine, the user and the directory.
- **Run:** the exact commands, one block at a time.
- **Expected:** what a good result looks like, and when to **STOP**.
- **Paste back:** what to send to the AI session. **Never paste** a password, anything from your password manager, `.env.staging`, or anything containing `MYSQL_ROOT_PASSWORD`.

## 1. Baselines

| Item | SHA | Meaning |
|---|---|---|
| **UAT source baseline (deploy)** | `b682935c42e5c2ef2365797ba3df79ecb3e7330e` | PR #111 merge into `main`: Phase 34F. |
| Running staging implementation (before §2) | `bcdd6f577895c4f45ec83dd870f1e9f0854b63ca` | Phase 34E. |

**What changes between them:**
- **Web look and navigation only:**
  - the portal layout (tabs, sidebar, app bar), the More page and the portal views;
  - the CSS and JavaScript;
  - `public/manifest.json` and `public/icons/`;
  - one Livewire setting (the progress bar colour).
- Everything else in between is documentation and the CI image check.
- **No migration** (still 45 Ran). `composer.lock`, `package-lock.json`, the Dockerfiles, the nginx config and the environment variables are unchanged.
- **CI built both staging images** on this change, and checked that the nginx image holds `manifest.json` and an icon (the "Build staging images" check on PR #111).

## 2. Staging deployment — **[Operator, VPS]**

**Where:** the VPS, as `deploy`, in `/home/deploy/company-app/company-app-build/company-app`. Run each block separately.

**Block 1: get the code**
```sh
cd /home/deploy/company-app/company-app-build/company-app
git status --porcelain
git fetch origin
git merge --ff-only b682935c42e5c2ef2365797ba3df79ecb3e7330e
git rev-parse HEAD
```
**Expected:**
- `git status --porcelain` prints nothing;
- then `Updating bcdd6f5..b682935` and `Fast-forward`;
- the last line is `b682935c42e5c2ef2365797ba3df79ecb3e7330e`.

**If the `Updating` line starts from anything other than `bcdd6f5`: STOP** and paste it.

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
$C exec nginx sh -c 'ls /var/www/html/public/build/assets; ls /var/www/html/public/icons'
$C ps
```
**Expected:**
- the last migrations show `Ran`, with none pending;
- the same `app-….css` and `app-….js` names in both containers. They are **new names**, different from 34E's;
- the nginx container lists `apple-touch-icon.png  icon-192.png  icon-512.png  maskable-512.png`;
- in `$C ps`, `company-app-api` and `company-app-nginx` were both created seconds or minutes ago.

**Block 5: smoke tests**
```sh
B=https://company-staging.storm-ark.com
for p in app app/more app/reports; do
  curl -s -o /dev/null -w "$p %{http_code} -> %{redirect_url}\n" $B/$p
done
curl -s -o /dev/null -w "manifest %{http_code} %{content_type}\n" $B/manifest.json
curl -s $B/manifest.json | grep -o '"start_url": "[^"]*"'
for i in icon-192.png icon-512.png maskable-512.png apple-touch-icon.png; do
  curl -s -o /dev/null -w "icon $i %{http_code} %{content_type}\n" $B/icons/$i
done
curl -s $B/login | grep -o '<link rel="manifest" href="/manifest.json">'
CSS=$(curl -s $B/login | grep -o '/build/assets/app-[A-Za-z0-9_-]*\.css' | head -1); curl -s -o /dev/null -w "css %{http_code} %{content_type}\n" "$B$CSS"
curl -s -o /dev/null -w "api %{http_code}\n" -H 'Accept: application/json' $B/api/v1/service-reports
```
**Expected:**
- the three pages each show `302 -> https://company-staging.storm-ark.com/login`;
- `manifest 200 application/json`, then `"start_url": "/app"`;
- four `icon … 200 image/png` lines;
- the `<link rel="manifest" …>` line, printed once;
- `css 200 text/css`;
- `api 401`.

**Anything else: STOP** and paste the output.

**Paste back:** the output of Blocks 1–5. For the build, its last few lines are enough.

## 3. Optional: bring back the rejected report — **[Operator, VPS]**

UAT-34F-04 includes the **Return to draft** dialog, which needs a **rejected** service report. During UAT-34E-04 the seeded rejected report was returned to draft and then deleted. To bring it back, re-run the **existing** 34E script.
- It resets **only** the two `[UAT34E]` reports, with their history and files.
- It keeps every report you made yourself, and it creates no account.

Skip this section if you'd rather leave the Return to draft dialog out of UAT-34F-04.

**Where:** the VPS, as `deploy`, in the staging checkout, after §2.

```sh
cd /home/deploy/company-app/company-app-build/company-app
git show origin/main:docs/testing/PHASE_34E_UAT_PREPARATION.md | awk '/^```php$/{f=1;next} /^```$/{f=0} f' > "$HOME/uat34e_data.php"
sha256sum "$HOME/uat34e_data.php"
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
W="$HOME/uat34e_data.php"
$C exec -T app php -- seed < "$W"; echo "seed exit=$?"
$C exec -T app php -- verify < "$W"
```
**Expected:**
- **The SHA is `7b894d2a4682eac0f43c939d6d55ac386db311f1cd917013ec7aed0597326882`.** This is the same script as in 34E, unchanged. **Any mismatch: STOP.**
- `seed exit=0`.
- The `verify` lines are as in `PHASE_34E_UAT_PREPARATION.md` §4, including:
  - `[UAT34E] UAT34E Pier Logistics <D-3> rejected`
  - `[UAT34E] workplace_safety high resolved`

**STOP** on a non-zero exit, `ERROR`, `MISSING` or `UNEXPECTED`.

**Paste back:** the `sha256sum` line and both outputs.

## 4. UAT-time notes (for **execution**)

- **Devices:** your phone's browser (Chrome on Android, or Safari on iPhone) and a desktop browser, at `https://company-staging.storm-ark.com`.
- **Sign in as `uat30b.staff`** (Lia). Paste the password from your password manager into the sign-in page only.
- **Phone app:** keep it open beside you to compare the tabs and More.

**UAT-34F-01: Tabs and More** (phone browser)
1. After signing in you are on **Home**. The bottom of the screen has five tabs: **Home, Tasks, Schedule, Messages, More**, the same as the app. **Home** is highlighted.
2. Tap **Tasks**, **Schedule** and **Messages**. Each opens, and its tab becomes the highlighted one. Open a task from Tasks: **Tasks** stays highlighted.
3. Tap **More**. It lists, in this order: **My profile, My status, Announcements, Notifications, Reports, Leave, Staff directory, My work logs, Projects, Clients**, and then **Log out**. Compare it with the app's More: same items, same order. (There is no Admin row for Lia.)
4. Open any item from More: **More** stays highlighted.

**UAT-34F-02: Title bar and back arrow** (phone browser)
1. Every page shows its name in the bar at the top (for example **Leave**, **My work logs**).
2. On a page from More (for example **Leave**), the top bar has a **←** arrow. Tap it: you're back on **More**.
3. Open a sub-page: a task, a chat, a report, or **Log work**. The **←** goes back to its list (Tasks, Messages, Reports, My work logs).
4. On the five tab pages themselves there is **no ←**.

**UAT-34F-03: The look** (phone browser and computer, light and dark)
1. **Phone:**
   - indigo highlights;
   - white (or, in dark mode, dark grey) rounded cards;
   - each list row ends in a **›**;
   - on **My work logs**, **Leave**, **Messages** and **Reports** there is a round **+** button at the bottom right, above the tabs. Tap one: it opens Log work, Request leave, New message, or New service report / Report an incident.
2. **Computer:**
   - the same five tabs sit in a **sidebar** on the left, with Log out at the bottom;
   - the **+** buttons are normal buttons at the top of the list (for example **+ Log work**);
   - there is no bottom tab bar.
3. **Dark mode:** switch your phone or computer to dark mode. Everything is readable, and the colours match the app's dark mode.
4. **Administrator (computer):** sign out, and sign in as an administrator account. The sidebar and More now show **Admin**, and clicking it opens the Admin Backoffice. Sign back in as `uat30b.staff`.

**UAT-34F-04: Messages and dialogs** (phone browser)
1. **More → My work logs → +.** Fill in a project, **1** hour, **`UAT34F dialog test`**, and tap **Save**. You're back on My work logs, and a dark message **"Work logged."** appears above the tabs. It disappears by itself after a few seconds.
2. Open that log and tap **Delete**. A panel slides up from the bottom: **"Delete this work log?"**, "This can't be undone.", with **Cancel** and **Delete**.
   - Tap **Cancel**: the panel closes and the log is still there.
   - Tap **Delete** again, then **Delete** in the panel: the log is gone, with **"Work log deleted."**
3. **Reports → +** (New service report). Search **`UAT34E`** and choose **UAT34E Pier Logistics**, type **`UAT34F web report`** in Work performed, and tap **Save draft**: **"Draft saved."**
   - Tap **Submit**: a panel asks **"Submit this report?"**. Tap **Cancel** (nothing changes), then **Submit** again and **Submit** in the panel: **"Report submitted."**
4. Make a second draft the same way (`UAT34F delete test`).
   - Add a photo and tap **Remove** on it: **"Remove this file?"** with the file name. Confirm: **"File removed."**
   - Tap **Delete draft**, then confirm **"Delete this draft?"**: you're back on Reports with **"Draft deleted."**
5. *(If you ran §3.)* Open the **Rejected** UAT34E Pier Logistics report and tap **Return to draft**: **"Return to draft?"**. Confirm: **"Returned to draft."**
6. **On a computer:** the same panels open in the **middle** of the screen, and the **Esc** key closes them without doing anything.
7. At no point does the browser show its own grey "OK / Cancel" box.

**UAT-34F-05: Moving around** (phone browser and computer)
1. Tap between tabs and pages. A thin **indigo bar** runs across the top while a page loads, and the page changes without the whole screen flashing white.
2. The browser's own **Back** button still goes back a page.
3. On a report with a PDF, **Download** still downloads it, and **Open photo** opens the photo in a new tab.
4. As the administrator from 34F-03: **Admin** opens the Admin Backoffice normally.

**UAT-34F-06: Install on the phone**
1. **Android (Chrome):** menu **⋮ → Add to Home screen** (or **Install app**). **iPhone (Safari):** **Share → Add to Home Screen**.
2. A **Company App** icon (an indigo square with a white **C**) appears on the home screen.
3. Open it from the icon. It opens on **Home** (or the sign-in page, if you were signed out) **without the browser's address bar**, like an app.
4. Turn on flight mode and open it again. It shows the browser's "no connection" page. **This is expected: there is no offline mode.** Turn flight mode off.
5. *(Optional)* Remove the icon afterwards, the same way you remove any app.

**UAT-34F-07: Keyboard, zoom and narrow screens** (computer)
1. **Keyboard only:** press **Tab** from the top of a page. The first stop is **Skip to content**, then **Company App**, the sidebar tabs and Log out, then the page. Every button, including **Delete** and the buttons in its panel, can be reached with **Tab** and used with **Enter**. The highlighted item always has a visible outline.
2. **Zoom 200%:** the sidebar is replaced by the bottom tabs. Nothing scrolls sideways.
3. **Narrow window:** drag the browser window as narrow as it goes. Nothing scrolls sideways, and the last item on a long list (for example Clients) can be scrolled into view **above** the tab bar.

**Afterwards:** the records made during UAT stay on staging; cleanup needs its own authorization. Report each scenario as **PASS**, or **FAIL with what you saw**. Only you record a `PASS`.
