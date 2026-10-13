# Combined UAT Preparation — Phases 30B, 31, 32 and 33 (Deployment, Host Upload Limit, APK, Staging Data)

*Operator runbook (DEC-059: one combined round). No AI session has run anything in this file against the staging VPS or on the Windows build machine; this AI session can reach neither. Every step marked **[Operator]** is pending until its output is recorded. Both data scripts were rehearsed on a disposable scratch SQLite database only (see `docs/testing/TEST_STATUS.md`, "Combined UAT preparation").*

**Status:** UAT-30B-01…08, UAT-31-01…08, UAT-32-01…08 and UAT-33-01…08 are `NOT RUN`. This runbook prepares one deployment, one APK and the data; it runs no scenario. **It replaces §2 and §3 of `docs/testing/PHASE_30B_UAT_PREPARATION.md`**, whose deploy target (`4a85918`) is superseded. 30B's data script and its §6 scenario notes still apply, and are used from here.

**How each operator step is written.** Every step says:
- **Where:** the machine, the user and the directory.
- **Run:** the exact commands, to copy as they are, one block at a time.
- **What it does.**
- **Expected:** what a good result looks like, and when to **STOP**.
- **Paste back:** what to send to the AI session. **Never paste** anything from a credentials file, a password, a password manager, `.env.staging`, or anything containing `MYSQL_ROOT_PASSWORD`.

**Lessons carried over from Phases 27–30:**
- **No password ever leaves the VPS terminal**, and none is ever typed or pasted into a shell. Passwords go to a `0600` file, then into a password manager, and the file is shredded. Paste a password **only into the app's sign-in field**.
- **Run the stages in order** and stop at the first unexpected output.
- **Extract the scripts from merged `origin/main`** and check their SHA-256 before running them.
- **Backup check:** look for `-- Dump completed` and a non-zero size. Never compare sizes between dumps.
- **Always run the APK build** and show its output, even if an earlier build exists.
- **Cleanup of UAT data needs its own authorization.** Nothing here deletes UAT data other than the scripts' own re-seeding of their prefixed records.

**New in this round:**
- **§3 changes the shared host nginx** (one line, with a backup and a rollback). Photo uploads need a body limit above nginx's 1 MB default.
- **§4's permission gate** now also proves the photo dependency added no permission (Phase 33).
- **§6 runs a second data script**, `uat3133_data.php`, after 30B's. It reuses the four UAT30B accounts, so **no new password is created**.

## 1. Baselines

| Item | SHA | Meaning |
|---|---|---|
| **UAT source baseline (deploy and APK)** | `d6dd29a4d10fd39f6e72a998c9aa0880926e4010` | Phases 30B, 31, 32 and 33 (PR #81, merged as `1d4f437`), plus the `.dockerignore` fix that lets the staging app image include `uploads.ini` (PR #83). Revision 2 of this runbook. |
| Running staging implementation (before §2) | `0e7fce6afd243ecb27cec3123214cd8fbb8e1a20` | Phase 30A (PR #73). |

**What changes between them:**
- **`apps/api`:** controllers, one form-request concern, one resource, `App\Support\CompanyDay`, and one new route (`GET /me/team-leave-requests`). **No migration** (still 45 Ran); `composer.lock` and the environment variables are unchanged.
- **Docker images:** `docker/nginx/default.conf` gains `client_max_body_size 12m`, and the PHP image gains `docker/php/conf.d/uploads.ini` (`upload_max_filesize 12M`, `post_max_size 13M`). §2 rebuilds both.
- **`.dockerignore`:** excludes only `docker/php/conf.d/local-dev.ini`, no longer the whole directory. At `1d4f437` the app image build failed with `"/docker/php/conf.d/uploads.ini": not found` (found by the operator in §2 on 2026-10-13).
- The rest is mobile code and docs (PRs #74–#83). The mobile app is identical at `1d4f437` and at the baseline.

## 2. Staging deployment — **[Operator, VPS]**

**Where:** the VPS, as the `deploy` user, in the staging checkout.

**What it does:**
- moves the checkout to `d6dd29a` (fast-forward only, from `0e7fce6` or from `1d4f437`);
- takes a MySQL backup;
- rebuilds and restarts only `app` and `nginx` (MySQL and its data, and the attachment volume, are not touched);
- confirms no migration is pending, rebuilds the Laravel caches (the new route needs `route:cache`), and checks the new upload limits inside the containers.

This follows `docs/DEPLOYMENT_STAGING.md` §9.

**Run:**

```sh
cd /home/deploy/company-app/company-app-build/company-app
git status --porcelain                  # expect: nothing
git fetch origin
git merge --ff-only d6dd29a4d10fd39f6e72a998c9aa0880926e4010
git rev-parse HEAD                      # must print d6dd29a4d10fd39f6e72a998c9aa0880926e4010
```

```sh
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
$C exec mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
  > /home/deploy/backups/company-app-$(date +%Y%m%d-%H%M%S).sql
ls -lt /home/deploy/backups/company-app-*.sql | head -3     # newest first
tail -c 200 "$(ls -t /home/deploy/backups/company-app-*.sql | head -1)" | grep -- '-- Dump completed'
```

Build both images. **Run this block on its own**, and go on only if it ends with `BUILD OK`:

```sh
$C build app nginx && echo "BUILD OK"
```

```sh
$C up -d --no-deps app nginx            # mysql untouched
$C exec app php artisan migrate:status  # expect all 45 Ran, none pending
$C exec app php artisan config:cache
$C exec app php artisan route:cache
$C exec app php artisan view:cache
$C exec app php artisan config:show scheduling.company_timezone   # Asia/Manila
$C exec app php -r 'echo ini_get("upload_max_filesize"), " ", ini_get("post_max_size"), PHP_EOL;'   # 12M 13M
$C exec nginx nginx -T 2>/dev/null | grep client_max_body_size                                     # client_max_body_size 12m;
ss -ltnp | grep 8012                    # only 127.0.0.1:8012
$C ps                                   # mysql "healthy", app and nginx "Up"
```

**Expected:**
- **Checkout:** `git status --porcelain` prints nothing (if it lists a file, **STOP** and paste its name; if the name looks like a password, paste nothing and say so). `git merge` prints `Updating <current>..d6dd29a` and `Fast-forward`.
- **Backup:** the first `ls -lt` line is today's file with a non-zero size, and `grep` prints `-- Dump completed on …`. Otherwise **STOP**.
- **Build:** the last line is `BUILD OK`. Any `ERROR` or `failed to solve`: **STOP** and paste the error lines. Don't run the next block: the old containers keep serving.
- **Migrations:** 45, all `Ran`. **Timezone:** ends in `Asia/Manila`.
- **Upload limits:** `12M 13M`, then `client_max_body_size 12m;`. Anything else means the images did not rebuild: **STOP**.
- **`$C ps`:** `app` and `nginx` both show `CREATED` a few seconds or minutes ago. An `app` created hours ago is still the old image: **STOP**.

**Smoke tests** (same VPS, any directory):

```sh
B=https://company-staging.storm-ark.com
for p in me/home me/status me/leave-requests me/team-leave-requests conversations me/notifications me/announcements service-reports incident-reports; do
  curl -s -o /dev/null -w "$p %{http_code}\n" -H 'Accept: application/json' $B/api/v1/$p
done
curl -s -o /dev/null -w 'up %{http_code}\n' $B/up
```

**Expected:** `401` for each of the nine API paths, then `up 200`. A `404` or `500`: **STOP** (a `404` on `me/team-leave-requests` means the `app` container still runs old code: check the build and `$C ps`).

**Paste back:** the `git` lines, the three `ls -lt` lines and the `-- Dump completed` line, the `BUILD OK` line, the `migrate:status` summary, the timezone line, the two upload-limit lines, the ten smoke-test lines, the `ss` line and the `$C ps` table.

## 3. Host nginx upload limit — **[Operator, VPS, needs `sudo`]**

**Why:** the shared host nginx in front of the containers (Phase 26) still has nginx's **1 MB** default body limit, so a phone photo of 1–10 MB would be refused with `413` before it reaches the app. This adds **one line** to the Company App site only. The Bicycle Workshop site is not touched.

**Where:** the VPS, as a user with `sudo`.

**Step 3a. Look first (read-only).**

```sh
F=$(sudo grep -l 'server_name company-staging.storm-ark.com;' /etc/nginx/sites-available/* | head -1); echo "$F"
sudo grep -n 'server_name\|client_max_body_size\|listen' "$F"
```

**Expected:** `F` is the Company App site file (for example `/etc/nginx/sites-available/company-staging.storm-ark.com`), and the `grep` shows its `listen` and `server_name company-staging.storm-ark.com;` lines and **no** `client_max_body_size`.
- If `F` is empty, or names the Bicycle Workshop file: **STOP**.
- If a `client_max_body_size` line already exists: **STOP** and paste it. Don't add a second one.

**Step 3b. Add the line, test, reload.**

```sh
sudo cp "$F" "$F.bak-uat3133"
sudo sed -i '/server_name company-staging\.storm-ark\.com;/a\    client_max_body_size 12m;' "$F"
sudo grep -n 'server_name\|client_max_body_size' "$F"
sudo nginx -t && sudo systemctl reload nginx; echo "reload exit=$?"
```

**Expected:** one `client_max_body_size 12m;` line directly under each `server_name company-staging.storm-ark.com;` line, `nginx -t` reports `syntax is ok` and `test is successful`, and `reload exit=0`.
- **If `nginx -t` fails:** restore the original and stop:

  ```sh
  sudo cp "$F.bak-uat3133" "$F" && sudo nginx -t && sudo systemctl reload nginx
  ```

**Step 3c. Prove it end to end (no account needed).**

```sh
B=https://company-staging.storm-ark.com
head -c 3000000 /dev/urandom > /tmp/uat3133-3mb.bin
curl -s -o /dev/null -w 'upload-3MB %{http_code}\n' -H 'Accept: application/json' -F file=@/tmp/uat3133-3mb.bin $B/api/v1/service-reports/x/attachments
rm /tmp/uat3133-3mb.bin
curl -s -o /dev/null -w 'bikeworkshop %{http_code}\n' https://bikeworkshop.storm-ark.com/
```

**Expected:** `upload-3MB 401`: the 3 MB request passed both nginx layers and reached Laravel, which refused it only for having no sign-in. **`413` means a layer still limits the size: STOP.** The `bikeworkshop` line prints the same code it did before this step (normally `200`).

**Rollback, at any time:** `sudo cp "$F.bak-uat3133" "$F" && sudo nginx -t && sudo systemctl reload nginx`.

**Paste back:** the `F` line, both `grep` outputs, the `nginx -t` output, `reload exit=…`, and the two `curl` lines.

## 4. Final UAT APK — **[Operator, Windows build machine]**

**Where:** PowerShell, in the repository root on the Windows build machine.

**What it does:**
- checks out exactly `d6dd29a` and runs the same Flutter checks CI runs;
- **builds** the release APK against staging (it downloads `image_picker`'s Android parts the first time);
- **proves the permission list** (hard gate), then installs the APK on the phone.

**Run:**

```powershell
git fetch origin
git checkout --detach d6dd29a4d10fd39f6e72a998c9aa0880926e4010
git rev-parse HEAD                 # must print d6dd29a4d10fd39f6e72a998c9aa0880926e4010
git status --porcelain             # must print nothing
cd apps/mobile

flutter --version
dart --version
java -version

flutter pub get
dart format --output=none --set-exit-if-changed .
flutter analyze
flutter test                            # expect +748 "All tests passed!"
flutter test test/features/my_status    # expect +64
flutter test test/features/leave        # expect +79
flutter test test/features/inbox        # expect +47
flutter test test/features/reports      # expect +37
flutter test test/core                  # expect +36

# ALWAYS run this step, even if an APK already exists.
flutter build apk --release --dart-define=API_BASE_URL=https://company-staging.storm-ark.com/api/v1

git -C ../.. status --porcelain     # still nothing
$apk = "build\app\outputs\flutter-apk\app-release.apk"
Get-Item $apk | Select-Object FullName, Length, LastWriteTime
Get-FileHash -Algorithm SHA256 $apk
$bt = "C:\Android\android-sdk\build-tools\36.0.0"    # where 30A's build found them
& "$bt\aapt2.exe" dump badging $apk | Select-String "package:|compileSdkVersion|sdkVersion|targetSdkVersion|uses-permission"
& "$bt\aapt2.exe" dump xmltree $apk --file AndroidManifest.xml | Select-String "GeolocatorLocationService|BACKGROUND_LOCATION|FOREGROUND_SERVICE|CAMERA|READ_MEDIA|READ_EXTERNAL_STORAGE|WRITE_EXTERNAL_STORAGE"
& "$bt\aapt2.exe" dump xmltree $apk --file AndroidManifest.xml | Select-String "ImagePickerFileProvider"
& "$bt\apksigner.bat" verify --print-certs $apk

adb devices                        # the phone's serial, state "device"
adb install -r $apk                # expect "Performing Streamed Install" then "Success"
adb shell getprop ro.build.version.release    # Android version: 12 or higher
```

**Expected:**
- **Checkout:** `git rev-parse HEAD` prints the full `d6dd29a…` SHA, and both `git status --porcelain` print nothing. Otherwise **STOP**.
- **Checks:** `dart format` changes nothing; `flutter analyze` says `No issues found!`; the test counts above, exactly. A different count means a different checkout: **STOP**.
- **Build:** ends with `√ Built build\app\outputs\flutter-apk\app-release.apk`, and `LastWriteTime` is **now**.
- **Permission gate (hard):** the `uses-permission` lines are **exactly these four**, the same as 30B, no more:
  - `android.permission.INTERNET`
  - `android.permission.ACCESS_COARSE_LOCATION`
  - `android.permission.ACCESS_FINE_LOCATION`
  - `com.companyapp.mobile.DYNAMIC_RECEIVER_NOT_EXPORTED_PERMISSION`
- **Manifest check 1:** the first `xmltree` line prints **nothing**: no background location, no foreground service, no camera or storage permission.
- **Manifest check 2:** the second prints the `ImagePickerFileProvider` provider line (the photo plugin's private file sharing, expected).
- **If the permission list or manifest check 1 shows anything else: STOP.** Don't install, and paste the output.
- **Signer and install:** a debug signer (known, deferred to Phase 38); `adb install` ends with `Success`; Android **12 or higher**.

**Paste back:** all of the output above (it contains no secrets). This APK replaces the Phase 30A APK on the test device.

## 5. UAT data plan

**Accounts:** the four UAT30B accounts from `PHASE_30B_UAT_PREPARATION.md` §4, and nothing else. Passwords are made once by 30B's `seed`; `uat3133_data.php` creates no account and prints no password.

| Key | Email | Role | Staff profile | Used in 31–33 for |
|---|---|---|---|---|
| staff | `uat30b.staff@company-app.test` | staff | Lia UAT30B-Ramos (reports to Ben) | almost everything |
| manager | `uat30b.manager@company-app.test` | manager | Ben UAT30B-Cruz | Team requests, the second chat party, report decisions |
| colleague | `uat30b.colleague@company-app.test` | staff | Kai UAT30B-Tan (reports to Ben) | his two pending leave requests; the group chat |
| noprofile | `uat30b.noprofile@company-app.test` | staff | none | the no-profile states |

**Phases 31–33 data (`D` = the company day of the last `seed`; replaced on every `seed`; anything made in the app is kept):**
- **Leave (31):**
  - two types, **UAT31 Annual Leave** (paid, 15 days for Lia this year) and **UAT31 Unpaid Leave**;
  - Lia's requests: pending D+3…D+5; approved D+7; approved **today** (started, so no Cancel); rejected D−10 ("Release week, sorry."); cancelled D−20…D−19;
  - Kai's: pending D+9 and D+14…D+15;
  - Ben's Team requests: 3 pending, soonest first (Lia D+3, Kai D+9, Kai D+14). Balance "10 days left of 15", 2 used, 3 pending.
- **Communication (32):**
  - **UAT32 Department** for Lia, Ben and Kai;
  - two announcements to it: "[UAT32] Office closed Friday" (new, two lines) and "[UAT32] New parking rules" (acknowledged);
  - a Lia–Ben direct chat of 37 messages, the last two from Ben unread; a "UAT32 Site team" group chat (Ben, Lia, Kai) with 3 messages;
  - Lia has 3 unread notifications (one announcement, two "New message").
- **Reports (33):**
  - **UAT33 Client** with **UAT33 Project** (Ben lead, Lia and Kai members);
  - Lia's service reports: a draft with a PDF ("UAT33 signed form.pdf"), submitted, rejected ("Add the pressure readings."), reviewed;
  - Lia's incidents: reported ("Wet floor"), under investigation by Ben ("Forklift").

**The leave dates assume `D` is before 1 December.** `plan` warns otherwise. After that, run `seed` again after 1 January.

## 6. Staging data preparation — **[Operator, VPS]**

All steps: **Where:** the VPS, as `deploy`, in the staging checkout, after §2.

**Step 0. Get both scripts and check them.**

**What it does:** copies the reviewed PHP blocks, as merged on `origin/main`, into your home directory. It does not change the deployed checkout. The SHA-256 values prove each file is exactly the reviewed and rehearsed text.

```sh
cd /home/deploy/company-app/company-app-build/company-app
git fetch origin main
git show origin/main:docs/testing/PHASE_30B_UAT_PREPARATION.md | awk '/^```php$/{f=1;next} /^```$/{f=0} f' > "$HOME/uat30b_data.php"
git show origin/main:docs/testing/COMBINED_UAT_PREPARATION.md | awk '/^```php$/{f=1;next} /^```$/{f=0} f' > "$HOME/uat3133_data.php"
sha256sum "$HOME/uat30b_data.php" "$HOME/uat3133_data.php"
C="docker compose -p company-app -f docker-compose.staging.yml --env-file .env.staging"
U="$HOME/uat30b_data.php"
V="$HOME/uat3133_data.php"
$C exec -T app php -- plan < "$U"
$C exec -T app php -- plan < "$V"
```

**Expected:**
- `uat30b_data.php`: `b020d7171a826f0de1243ec474aecfa9bd7d50676834973bea37ff8ced4e0f6f`;
- `uat3133_data.php`: the SHA in §8 of this file;
- **any mismatch: STOP.**
- Then 30B's `plan`, as in its runbook §5 step 0 (four accounts `absent` the first time).
- Then this script's `plan`: four `ABSENT (run uat30b_data.php seed first)` lines if 30B's seed has never run (`exists` once it has), `company year: <YYYY>` (**STOP** on `WARNING`), and zero UAT31/32/33 counts (`UAT31 leave types: 0 of 2`).

`C`, `U` and `V` must be set again in every new shell.

**Paste back:** the two `sha256sum` lines and both `plan` outputs.

**Step 1. Seed 30B (the only step that makes passwords).** Exactly as `PHASE_30B_UAT_PREPARATION.md` §5 step 1:

```sh
CRED="$HOME/uat30b-credentials.txt"
test ! -e "$CRED" && (umask 077; set -o noclobber; $C exec -T app php -- seed < "$U" > "$CRED"); echo "seed exit=$?"
stat -c '%a %U' "$CRED"      # must be: 600 deploy
wc -l < "$CRED"              # must be: 4
```

**Expected:** as there: `seed exit=0`, `600 deploy`, `4`.
- **Non-zero exit:** **STOP** and paste the screen output, never the file.
- **Otherwise:**
  1. Run `less "$CRED"` (press `q` to leave).
  2. Copy all four passwords into your password manager.
  3. Run `shred -u "$CRED"`, then `ls "$CRED"`. Expect `No such file or directory`.

If the accounts already exist from an earlier 30B preparation, `seed` prints `already existed (password unchanged)` ×4 and the file is empty: use the passwords already in your password manager, and still shred the file.

**Paste back:** the screen output and the `ls` line. **Never paste** the file's contents.

**Step 2. Seed and verify 31–33.**

**What it does:** in one database transaction, sets the §5 Phases 31–33 data. It prints no password. Then a read-only `verify` runs the real controllers as Lia, Ben and the no-profile account. This is also the **MySQL check** of the new list orders and company day.

```sh
$C exec -T app php -- seed < "$V"; echo "seed exit=$?"
$C exec -T app php -- verify < "$V"
$C exec -T app php -- verify < "$U"
$C exec -T app php -- exposure < "$U"
```

**Expected** (`D` is today's company date; dates in `YYYY-MM-DD`):
```
stage=seed utc_now=<UTC time> company_day=<D> (Asia/Manila)
seed: UAT31 leave (balance 15 for <YYYY>, 5 requests for Lia, 2 pending for Kai), UAT32 (2 announcements, 2 chats, 4 notifications), UAT33 (4 service reports, 1 PDF, 2 incidents) set for company day <D>
seed: done
seed exit=0
stage=verify utc_now=<UTC time> company_day=<D> (Asia/Manila)
31 Lia balances: 200; UAT31 Annual 10 left of 15 (2 used, 3 pending), year <YYYY>
31 Lia requests: 200; <D+7> approved, <D+3> pending, <D> approved, <D-10> rejected, <D-20> cancelled; company_day <D>
31 Ben team: 200; can_review=true reports=2 pending=3; Lia UAT30B-Ramos <D+3>, Kai UAT30B-Tan <D+9>, Kai UAT30B-Tan <D+14>
31 Lia team: 200; can_review=false (no Team requests)
31 noprofile /me/leave-requests: 403 (no-profile state: correct)
32 Lia chats: 200; direct unread=2 last="[UAT32] Bring the meter reading, please...."; UAT32 Site team unread=0 last="[UAT32] Same here."
32 Lia unread notifications: 200; 3
32 Lia announcements: 200; [UAT32] Office closed Friday (new), [UAT32] New parking rules (acknowledged)
32 noprofile notifications: 200 (works without a profile)
33 Lia service reports: 200; [UAT33] Serviced the chiller (dr... draft att=1; [UAT33] Replaced the filters submitted att=0; [UAT33] Inspected the pumps rejected att=0; [UAT33] Annual maintenance reviewed att=0
33 Lia incidents: 200; [UAT33] Wet floor, a near slip... reported <Ddd dd Mon HH:MM> att=0; [UAT33] Forklift scraped a rac... under_investigation <Ddd dd Mon HH:MM> att=0
33 Ben sees Lia's service reports: 200; 4
```
Then 30B's `verify` and `exposure` as in its runbook §5 step 2. **STOP** on any `UNEXPECTED`, any `MISSING`, a non-zero exit, or a line that differs from the above other than the dates and times.

**Paste back:** all four outputs (no secrets).

**Step 3. On the UAT day, if it is not the seeding day, or to start a scenario again.** Both seeds are idempotent and make no password:

```sh
$C exec -T app php -- seed < "$U" > /dev/null; echo "30b seed exit=$?"
$C exec -T app php -- seed < "$V"; echo "3133 seed exit=$?"
$C exec -T app php -- verify < "$V"
```

## 7. UAT-time notes (for **execution**, not preparation)

- **Device:** an Android 12+ phone with the §4 APK. Sign in by pasting the password from the password manager into the app's sign-in field. Switch accounts with **Log out** (Home app bar).
- **30B:** follow `PHASE_30B_UAT_PREPARATION.md` §6 exactly (UAT-30B-01…08). Its `verify`, `revoke` and `exposure` stages use `$U`.

**Phase 31 (sign in as `uat30b.staff`, Lia):**
- **UAT-31-01:** More → **Leave**.
  - "Balances <YYYY>": UAT31 Annual Leave "10 days left of 15", "2 used · 3 pending"; UAT31 Unpaid Leave "Unpaid — no balance".
  - My requests, newest first: Approved (D+7), Pending (D+3…D+5), Approved (today), Rejected, Cancelled, each with its chip.
- **UAT-31-02:** **Request leave** → type UAT31 Annual Leave ("10 days left in <YYYY>") → first day D+20, last day D+21 → "2 calendar days (weekends included)" → reason "UAT31 test" → **Send request**: "Leave requested." The new Pending row is on top; the balance now shows 5 pending.
- **UAT-31-03:**
  - Last day before first day → refused on the field.
  - First day 31 December, last 2 January → "A request must stay within one calendar year…".
  - Reason "ab" → refused.
  - Dates overlapping D+3 → the server's message at the top.
  - UAT31 Annual Leave for 20 days → the server's balance message.
  - Nothing new appears in the list.
- **UAT-31-04:**
  - Open the D+3 Pending → **Cancel request** with a reason → "Request cancelled."; the history shows it.
  - Open the D+7 Approved → Cancel (no reason) works.
  - Open **today's** Approved → **no** Cancel button.
- **UAT-31-05:** log out; sign in as `uat30b.manager` (Ben). More → Leave → **Team requests** ("2 pending" or more):
  1. Open Kai's D+9 → **Approve** with a note → "Request approved."
  2. Back, then open Kai's D+14 → **Reject** with "UAT31 reject test" → "Request rejected."
  3. Log out and sign in as Lia: her own requests are unchanged.
  4. **Schedule** shows Lia's approved leave on its day. Kai's decisions can be checked by running `verify` (Ben's team list no longer lists them as pending).
- **UAT-31-06:**
  - As Lia (staff), More → Leave shows **no** Team requests.
  - Sign in as **your own administrator account**: with a staff profile that manages nobody, no Team requests; without a profile, "No staff profile is linked to this account."
  - As `uat30b.noprofile`: only the no-profile message.
- **UAT-31-07:** airplane mode → open Leave → "Couldn't load your leave…"; send a request → "Couldn't send the request…", and nothing is created (check after reconnecting). For the token check, use 30B's `revoke` stage as in its UAT-30B-07.
- **UAT-31-08:** dark mode and the largest font on Leave, Request leave, a request, and (as Ben) a team request.

**Phase 32:**
- **UAT-32-01** (Lia): the **Messages** tab lists, newest activity first, **Ben UAT30B-Cruz** (direct chat, badge 2, last "Bring the meter reading, please.") and then **UAT32 Site team** (no badge, "You: Same here.").
- **UAT-32-02:**
  - Open the Ben chat → type "UAT32 hello" → Send → it appears on the right.
  - With the chat still open, on the VPS run `$C exec -T app php -- send < "$V"`: "[UAT32] Live message HH:MM:SS" appears on the left within about 15 s.
  - **New message** → pick **Kai UAT30B-Tan** → a new chat opens; send "UAT32 to Kai".
  - Log out and sign in as `uat30b.colleague` (Kai): his Messages shows the chat with a badge, and Home shows an unread notification. Sign back in as Lia.
- **UAT-32-03:** the Ben chat's badge has gone after opening it; Notifications shows its "New message" items as read (not bold); Home's Messages and Notifications counts dropped.
- **UAT-32-04:**
  - In the Ben chat scroll up → **Load earlier messages** → "[UAT32] Message 01…" appear with no repeats, under "Yesterday" and earlier day separators.
  - Airplane mode → send "offline" → "Couldn't send. Try again.", with the text kept. Reconnect → Send → it appears once.
- **UAT-32-05:** Home → Notifications tile: newest first, unread bold with a dot.
  - Tap "[UAT32] Office closed Friday" → its announcement opens.
  - Back → tap a "New message" → the Ben chat opens in the Messages tab.
  - More → Notifications → **Mark all read** (the tick icon) → "All marked read." and Home's count is 0.
- **UAT-32-06:**
  - More → **Announcements**: "[UAT32] Office closed Friday" bold with **New**.
  - Open it: two lines of text and "UAT30B… · <date> <time>".
  - **Acknowledge** → "Acknowledged on …"; back, and the New mark has gone.
  - Home's latest announcements: tap one → the same detail; **All announcements** → the feed.
- **UAT-32-07:** as `uat30b.noprofile`, Messages and Announcements show only the no-profile message, and Notifications opens (empty). Token check as in 30B.
- **UAT-32-08:** dark mode and the largest font on Messages, a chat (the Send button stays visible), Notifications and an announcement.

**Phase 33 (Lia):**
- **UAT-33-01:** More → **Reports**: Service reports with Draft, Submitted, Rejected and Reviewed chips; **Incidents** with High and Reported or Under investigation chips. As `uat30b.noprofile`: the no-profile message.
- **UAT-33-02:**
  - **+** (app bar) → client **UAT33 Client** → project **UAT33 Project** → date "(today)" → work performed "UAT33 phone test" → **Save draft**: "Draft saved."
  - The form refuses tomorrow: the picker stops at today. If staging's clock allows, also do this between 00:00 and 07:59 Manila.
- **UAT-33-03:** on that draft:
  1. **Take photo** → the camera opens with **no** permission prompt → the photo uploads.
  2. **Choose photo** → Android's photo picker, again with no permission prompt → it uploads.
  3. Tap a photo → full screen, zoomable.
  4. Remove one (trash icon, confirm).
  5. **Edit** → change a field → **Save changes**.
  6. **Submit** → confirm → "Report submitted."; Edit, Delete, Take photo and Choose photo are gone.
- **UAT-33-04:**
  - On the VPS run `$C exec -T app php -- reject < "$V"` (it rejects Lia's newest submitted report as Ben). Pull to refresh the report: Rejected, with "Add the meter reading, please." in the history.
  - **Return to draft** → Edit → Submit again.
  - Delete the seeded draft "Serviced the chiller (draft)": the PDF row first shows "… · Open it on the web" (UAT-33-07).
- **UAT-33-05:**
  - Incidents tab → **+** → type Client site, severity Critical, time now, description "UAT33 incident" → **Report incident**.
  - Add a photo, then Edit.
  - On the VPS run `$C exec -T app php -- investigate < "$V"` (Ben starts the investigation of Lia's newest reported incident). Pull to refresh: Under investigation, "Assigned to Ben UAT30B-Cruz", and no Edit, Take photo or Choose photo.
- **UAT-33-06:** airplane mode: Save draft, Submit and Take photo each show a clear message, and after reconnecting there is exactly one of each record (check the list). An image over 10 MB: the photo picker scales photos down, so this is normally not reachable from a phone; note it as not applicable if so.
- **UAT-33-07:** Settings → Apps → mobile → Permissions lists **only Location**. No camera or photos permission was ever requested. The PDF note is in UAT-33-04.
- **UAT-33-08:** dark mode and the largest font on both lists, both forms and both details.

**Afterwards:** run `$C exec -T app php -- exposure < "$U"` once more and paste it. All UAT data stays in place; any cleanup needs its own authorization. Then report each scenario's result to the AI session. Only you record a `PASS`.

## 8. Script — `uat3133_data.php`

**Revision 1 (2026-10-12), SHA-256 `4c3263ddd8bb7131a69843a5e94dea7aa3ac301f9a3c1ec4bcdc5554842d32cc`** (the exact text below, with a trailing newline). Rehearsed on a scratch SQLite database: every stage, a re-seed, and `send`, `reject` and `investigate`. It requires `uat30b_data.php`'s accounts (step 1 first).

```php
<?php

/*
 * Phases 31–33 UAT data — operator tool (NOT application code; never commit
 * to apps/api, never expose over HTTP).
 *
 * Uses the four UAT30B accounts made by uat30b_data.php (run its `seed`
 * first): Lia (staff, reports to Ben), Ben (manager), Kai (staff, reports to
 * Ben) and the no-profile account. It creates NO account and prints NO
 * password.
 *
 * Run inside the staging `app` container, reading this file from stdin:
 *
 *   $C exec -T app php -- <stage> < uat3133_data.php
 *
 * Stages:
 *   plan         read-only: the UAT30B accounts, the company day, and what
 *                UAT31/32/33 data already exists
 *   seed         idempotent; replaces only "[UAT31]", "[UAT32]", "[UAT33]"
 *                records (leave requests, messages, announcements,
 *                notifications, reports) and UAT31/32/33 reference records
 *                (two leave types, a department, a client, a project).
 *                Anything made in the app is kept.
 *   verify       read-only: runs the real controllers as Lia, Ben and the
 *                no-profile account and prints what the app sees
 *   send         UAT-32-02: Ben sends one message to Lia's direct chat
 *                through the real controller (as from a second phone)
 *   reject       UAT-33-04: Ben rejects Lia's newest submitted service
 *                report through the real controller
 *   investigate  UAT-33-05: Ben starts the investigation of Lia's newest
 *                reported incident (assigned to himself)
 *
 * Every line goes to STDERR or STDOUT as plain status; no secret is ever
 * printed. Any failure exits 1 and changes nothing (transactions).
 */

use App\Enums\AnnouncementAudienceType;
use App\Enums\AnnouncementStatus;
use App\Enums\AttachmentOwnerType;
use App\Enums\ClientStatus;
use App\Enums\ConversationType;
use App\Enums\IncidentReportActionType;
use App\Enums\IncidentReportStatus;
use App\Enums\IncidentReportType;
use App\Enums\IncidentSeverity;
use App\Enums\LeaveRequestActionType;
use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeStatus;
use App\Enums\NotificationSourceType;
use App\Enums\NotificationType;
use App\Enums\OrganizationStatus;
use App\Enums\ProjectMembershipRole;
use App\Enums\ProjectStatus;
use App\Enums\ServiceReportActionType;
use App\Enums\ServiceReportStatus;
use App\Http\Controllers\Api\V1\Announcements\MyAnnouncementController;
use App\Http\Controllers\Api\V1\IncidentReports\IncidentReportController;
use App\Http\Controllers\Api\V1\Leave\LeaveBalanceController;
use App\Http\Controllers\Api\V1\Leave\MyLeaveRequestController;
use App\Http\Controllers\Api\V1\Leave\MyTeamLeaveRequestController;
use App\Http\Controllers\Api\V1\Messaging\ConversationController;
use App\Http\Controllers\Api\V1\Messaging\MessageController;
use App\Http\Controllers\Api\V1\Notifications\NotificationController;
use App\Http\Controllers\Api\V1\ServiceReports\ServiceReportController;
use App\Http\Requests\IncidentReports\StartInvestigationIncidentReportRequest;
use App\Http\Requests\Messaging\StoreMessageRequest;
use App\Http\Requests\ServiceReports\RejectServiceReportRequest;
use App\Models\Announcement;
use App\Models\AnnouncementAcknowledgement;
use App\Models\Attachment;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Department;
use App\Models\IncidentReport;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ServiceReport;
use App\Models\Staff;
use App\Models\User;
use App\Support\Attachments\AttachmentDisk;
use App\Support\CompanyTimezone;
use App\Support\Reporting\OverdueTasks;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

const T31 = '[UAT31] ';
const T32 = '[UAT32] ';
const T33 = '[UAT33] ';

$emails = [
    'staff' => 'uat30b.staff@company-app.test',
    'manager' => 'uat30b.manager@company-app.test',
    'colleague' => 'uat30b.colleague@company-app.test',
    'noprofile' => 'uat30b.noprofile@company-app.test',
];

$tz = CompanyTimezone::value();
$today = OverdueTasks::todayInCompanyTimezone();
$D = fn (int $days) => Carbon::parse($today)->addDays($days)->toDateString();
$out("stage={$stage} utc_now=".now('UTC')->toIso8601String()." company_day={$today} ({$tz})");

$users = collect($emails)->map(fn ($e) => User::query()->where('email', $e)->first());
$need = function () use ($users, $out): void {
    if ($users->contains(null) || $users->except('noprofile')->contains(fn ($u) => $u->staff === null)) {
        $out('UAT30B accounts missing or without staff records: run uat30b_data.php seed first; nothing changed');
        exit(1);
    }
};

/** Calls a real controller action as $user; returns [status, body]. */
$call = function (User $user, callable $invoke, array $query = [], ?string $formClass = null, array $data = []): array {
    Auth::guard('web')->setUser($user);
    $base = Request::create('/uat', $formClass ? 'POST' : 'GET', $formClass ? $data : $query);
    if ($formClass !== null) {
        /** @var FormRequest $request */
        $request = $formClass::createFrom($base);
        $request->setContainer(app())->setRedirector(app('redirect'));
    } else {
        $request = $base;
    }
    $request->setUserResolver(fn () => $user);
    app()->instance('request', $request);
    try {
        if ($request instanceof FormRequest) {
            $request->validateResolved();
        }
        $result = $invoke($request);
    } catch (HttpException $e) {
        return [$e->getStatusCode(), ['message' => $e->getMessage()]];
    }
    $response = $result instanceof Responsable ? $result->toResponse($request) : $result;

    return [$response->getStatusCode(), json_decode($response->getContent(), true)];
};

$manila = fn (?string $iso) => $iso === null ? '-' : Carbon::parse($iso)->tz($tz)->format('D j M H:i');

if ($stage === 'plan') {
    foreach ($users as $key => $u) {
        $out(str_pad($key, 10).($u ? 'exists, staff='.($u->staff ? $u->staff->employee_number : 'none') : 'ABSENT (run uat30b_data.php seed first)'));
    }
    $out('company year: '.substr($today, 0, 4).($D(30) > substr($today, 0, 4).'-12-31' ? ' — WARNING: leave dates cross into next year; run after 1 January' : ''));
    $out('UAT31 leave types: '.LeaveType::query()->where('name', 'like', 'UAT31 %')->count().' of 2');
    $out('[UAT31] leave requests: '.LeaveRequest::query()->where('reason', 'like', T31.'%')->count());
    $out('[UAT32] messages: '.Message::query()->where('body', 'like', T32.'%')->count().', announcements: '.Announcement::query()->where('title', 'like', T32.'%')->count());
    $out('[UAT33] service reports: '.ServiceReport::query()->where('work_performed', 'like', T33.'%')->count().', incidents: '.IncidentReport::query()->where('description', 'like', T33.'%')->count());
    exit(0);
}

$need();
$lia = $users['staff']->staff;
$ben = $users['manager']->staff;
$kai = $users['colleague']->staff;
$liaUser = $users['staff'];
$benUser = $users['manager'];

if ($stage === 'seed') {
    if ($lia->manager_id !== $ben->id || $kai->manager_id !== $ben->id) {
        $out('seed: Lia and Kai must report to Ben (run uat30b_data.php seed); nothing changed');
        exit(1);
    }
    $now = now('UTC')->startOfMinute();
    $year = (int) substr($today, 0, 4);
    $pdfPaths = [];

    DB::transaction(function () use ($lia, $ben, $kai, $liaUser, $benUser, $users, $D, $now, $year, $today, $out, &$pdfPaths) {
        // --- Phase 31: leave -------------------------------------------------
        $annual = LeaveType::query()->firstOrNew(['name' => 'UAT31 Annual Leave']);
        $annual->fill(['code' => 'UAT31A', 'is_paid' => true, 'status' => LeaveTypeStatus::Active, 'sort_order' => 900])->save();
        $unpaid = LeaveType::query()->firstOrNew(['name' => 'UAT31 Unpaid Leave']);
        $unpaid->fill(['code' => 'UAT31U', 'is_paid' => false, 'status' => LeaveTypeStatus::Active, 'sort_order' => 901])->save();
        LeaveBalance::query()->updateOrCreate(
            ['staff_id' => $lia->id, 'leave_type_id' => $annual->id, 'year' => $year],
            ['allocated_days' => 15, 'notes' => 'UAT31 allocation', 'created_by_user_id' => $benUser->id],
        );

        $old = LeaveRequest::query()->where('reason', 'like', T31.'%')->whereIn('staff_id', [$lia->id, $kai->id])->pluck('id');
        DB::table('leave_request_actions')->whereIn('leave_request_id', $old)->delete();
        LeaveRequest::query()->whereIn('id', $old)->delete();

        $leave = function (Staff $staff, User $by, string $from, string $to, LeaveRequestStatus $status, string $reason, array $steps) use ($annual, $now) {
            $r = new LeaveRequest([
                'staff_id' => $staff->id, 'leave_type_id' => $annual->id, 'start_date' => $from, 'end_date' => $to,
                'total_days' => Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1,
                'reason' => T31.$reason, 'status' => $status, 'created_by_user_id' => $by->id,
            ]);
            $r->created_at = $now->copy()->subDays(3);
            $r->updated_at = $r->created_at;
            $r->save();
            foreach ($steps as $i => [$action, $actor, $note]) {
                $a = $r->actions()->make(['action' => $action, 'acted_by_user_id' => $actor->id, 'note' => $note]);
                $a->created_at = $now->copy()->subDays(3)->addHours($i);
                $a->updated_at = $a->created_at;
                $a->save();
            }
        };
        $S = LeaveRequestActionType::Submitted;
        $leave($lia, $liaUser, $D(3), $D(5), LeaveRequestStatus::Pending, 'Family trip', [[$S, $liaUser, null]]);
        $leave($lia, $liaUser, $D(7), $D(7), LeaveRequestStatus::Approved, 'Dentist', [[$S, $liaUser, null], [LeaveRequestActionType::Approved, $benUser, 'Fine.']]);
        $leave($lia, $liaUser, $today, $today, LeaveRequestStatus::Approved, 'Started today', [[$S, $liaUser, null], [LeaveRequestActionType::Approved, $benUser, null]]);
        $leave($lia, $liaUser, $D(-10), $D(-10), LeaveRequestStatus::Rejected, 'Busy week', [[$S, $liaUser, null], [LeaveRequestActionType::Rejected, $benUser, 'Release week, sorry.']]);
        $leave($lia, $liaUser, $D(-20), $D(-19), LeaveRequestStatus::Cancelled, 'Changed plans', [[$S, $liaUser, null], [LeaveRequestActionType::Cancelled, $liaUser, null]]);
        $kaiUser = $users['colleague'];
        $leave($kai, $kaiUser, $D(14), $D(15), LeaveRequestStatus::Pending, 'Wedding', [[$S, $kaiUser, null]]);
        $leave($kai, $kaiUser, $D(9), $D(9), LeaveRequestStatus::Pending, 'Appointment', [[$S, $kaiUser, null]]);

        // --- Phase 32: department, announcements, chats, notifications -------
        $dept = Department::query()->firstOrNew(['name' => 'UAT32 Department']);
        $dept->fill(['status' => OrganizationStatus::Active, 'sort_order' => 900])->save();
        Staff::query()->whereIn('id', [$lia->id, $ben->id, $kai->id])->update(['department_id' => $dept->id]);

        $oldAnn = Announcement::query()->where('title', 'like', T32.'%')->pluck('id');
        $oldAnnIds = Announcement::query()->whereIn('id', $oldAnn)->pluck('public_id');
        AnnouncementAcknowledgement::query()->whereIn('announcement_id', $oldAnn)->delete();
        DB::table('announcement_departments')->whereIn('announcement_id', $oldAnn)->delete();
        Announcement::query()->whereIn('id', $oldAnn)->delete();
        Notification::query()->whereIn('source_public_id', $oldAnnIds)->delete();

        $announce = function (string $title, string $body, Carbon $at, bool $acknowledged) use ($dept, $lia, $liaUser, $benUser) {
            $a = new Announcement([
                'title' => T32.$title, 'body' => $body, 'status' => AnnouncementStatus::Published,
                'audience_type' => AnnouncementAudienceType::Scoped, 'published_at' => $at,
                'created_by_user_id' => $benUser->id, 'published_by_user_id' => $benUser->id,
            ]);
            $a->save();
            $a->departments()->attach($dept->id);
            if ($acknowledged) {
                $ack = new AnnouncementAcknowledgement(['announcement_id' => $a->id, 'staff_id' => $lia->id]);
                $ack->created_at = $at->copy()->addHour();
                $ack->save();
            }
            $n = new Notification([
                'recipient_user_id' => $liaUser->id, 'type' => NotificationType::AnnouncementPublished,
                'title' => $a->title, 'message' => 'A new company announcement has been published.',
                'source_type' => NotificationSourceType::Announcement, 'source_public_id' => $a->public_id,
                'read_at' => $acknowledged ? $at->copy()->addHour() : null,
            ]);
            $n->created_at = $at;
            $n->save();
        };
        $announce('Office closed Friday', "The office is closed this Friday.\nEnjoy the long weekend — see you Monday.", $now->copy()->subHour(), false);
        $announce('New parking rules', 'Please park in the marked UAT bays only.', $now->copy()->subDays(3), true);

        $chat = function (ConversationType $type, ?string $name, array $members) {
            $ids = collect($members)->pluck('id')->sort()->values();
            $found = Conversation::query()->where('type', $type)->where('name', $name)
                ->whereHas('members', fn ($q) => $q->where('staff_id', $ids[0]))
                ->get()->first(fn ($c) => $c->members()->pluck('staff_id')->sort()->values()->all() === $ids->all());
            if ($found) {
                return $found;
            }
            $c = Conversation::create(['type' => $type, 'name' => $name, 'owner_staff_id' => $type === ConversationType::Group ? $members[0]->id : null]);
            foreach ($members as $m) {
                $c->members()->create(['staff_id' => $m->id]);
            }

            return $c;
        };
        $direct = $chat(ConversationType::Direct, null, [$lia, $ben]);
        $group = $chat(ConversationType::Group, 'UAT32 Site team', [$ben, $lia, $kai]);
        Notification::query()->where('recipient_user_id', $liaUser->id)->where('type', NotificationType::MessageReceived)
            ->whereIn('source_public_id', [$direct->public_id, $group->public_id])->delete();
        Message::query()->whereIn('conversation_id', [$direct->id, $group->id])->where('body', 'like', T32.'%')->delete();

        $post = function (Conversation $c, Staff $from, string $body, Carbon $at) {
            $m = new Message(['conversation_id' => $c->id, 'sender_staff_id' => $from->id, 'body' => T32.$body]);
            $m->created_at = $at;
            $m->updated_at = $at;
            $m->save();

            return $m;
        };
        // The list orders by newest message id, so the group (older activity) is written first.
        $post($group, $ben, 'Team: site visit moved to Thursday.', $now->copy()->subDays(1));
        $post($group, $kai, 'Noted, thanks Ben.', $now->copy()->subDays(1)->addMinutes(5));
        $gm = $post($group, $lia, 'Same here.', $now->copy()->subDays(1)->addMinutes(9));
        ConversationMember::query()->where('conversation_id', $group->id)->update(['last_read_message_id' => $gm->id]);
        // 35 messages over two days, alternating; the last two from Ben unread.
        $lastRead = null;
        for ($i = 0; $i < 35; $i++) {
            $from = $i % 2 === 0 ? $ben : $lia;
            $m = $post($direct, $from, sprintf('Message %02d', $i + 1), $now->copy()->subHours(50)->addMinutes($i * 80));
            if ($i === 34) {
                $lastRead = $m->id;
            }
        }
        $post($direct, $ben, 'Are you on site today?', $now->copy()->subMinutes(40));
        $post($direct, $ben, "Bring the meter reading, please.\nThanks!", $now->copy()->subMinutes(30));
        ConversationMember::query()->where('conversation_id', $direct->id)->where('staff_id', $lia->id)->update(['last_read_message_id' => $lastRead]);
        ConversationMember::query()->where('conversation_id', $direct->id)->where('staff_id', $ben->id)->update(['last_read_message_id' => Message::query()->where('conversation_id', $direct->id)->max('id')]);
        foreach (['Are you on site today?', 'Bring the meter reading'] as $k => $t) {
            $n = new Notification([
                'recipient_user_id' => $liaUser->id, 'type' => NotificationType::MessageReceived, 'title' => 'New message',
                'message' => 'You have a new message in one of your conversations.',
                'source_type' => NotificationSourceType::Conversation, 'source_public_id' => $direct->public_id,
            ]);
            $n->created_at = $now->copy()->subMinutes(40 - $k * 10);
            $n->save();
        }

        // --- Phase 33: client, project, reports --------------------------------
        $client = Client::query()->firstOrNew(['name' => 'UAT33 Client']);
        $client->fill(['client_code' => 'UAT33', 'status' => ClientStatus::Active])->save();
        $project = Project::query()->firstOrNew(['name' => 'UAT33 Project']);
        $project->fill(['client_id' => $client->id, 'status' => ProjectStatus::Active])->save();
        foreach ([[$ben, ProjectMembershipRole::ProjectLead], [$lia, ProjectMembershipRole::Member], [$kai, ProjectMembershipRole::Member]] as [$s, $role]) {
            ProjectMembership::query()->updateOrCreate(['project_id' => $project->id, 'staff_id' => $s->id], ['role' => $role]);
        }

        $oldSr = ServiceReport::query()->where('work_performed', 'like', T33.'%')->where('creator_staff_id', $lia->id)->pluck('id');
        $oldIr = IncidentReport::query()->where('description', 'like', T33.'%')->where('reporter_staff_id', $lia->id)->pluck('id');
        $pdfPaths = Attachment::query()->whereIn('service_report_id', $oldSr)->orWhereIn('incident_report_id', $oldIr)->pluck('storage_path')->all();
        Attachment::query()->whereIn('service_report_id', $oldSr)->orWhereIn('incident_report_id', $oldIr)->delete();
        DB::table('service_report_actions')->whereIn('service_report_id', $oldSr)->delete();
        DB::table('service_report_participants')->whereIn('service_report_id', $oldSr)->delete();
        ServiceReport::query()->whereIn('id', $oldSr)->delete();
        DB::table('incident_report_actions')->whereIn('incident_report_id', $oldIr)->delete();
        DB::table('incident_report_participants')->whereIn('incident_report_id', $oldIr)->delete();
        IncidentReport::query()->whereIn('id', $oldIr)->delete();

        $report = function (string $work, string $date, ServiceReportStatus $status, array $steps) use ($client, $project, $lia, $liaUser, $now) {
            $r = new ServiceReport([
                'client_id' => $client->id, 'project_id' => $project->id, 'creator_staff_id' => $lia->id,
                'created_by_user_id' => $liaUser->id, 'service_date' => $date, 'work_performed' => T33.$work,
                'findings' => 'Worn belt on unit 2.', 'site_representative_name' => 'Juan dela Cruz', 'status' => $status,
            ]);
            $r->save();
            foreach ($steps as $i => [$action, $actor, $note]) {
                $a = $r->actions()->make(['action' => $action, 'acted_by_user_id' => $actor->id, 'note' => $note]);
                $a->created_at = $now->copy()->subDays(2)->addHours($i);
                $a->save();
            }

            return $r;
        };
        $draft = $report('Serviced the chiller (draft)', $D(-1), ServiceReportStatus::Draft, []);
        $report('Replaced the filters', $D(-3), ServiceReportStatus::Submitted, [[ServiceReportActionType::Submitted, $liaUser, null]]);
        $report('Inspected the pumps', $D(-5), ServiceReportStatus::Rejected, [[ServiceReportActionType::Submitted, $liaUser, null], [ServiceReportActionType::Rejected, $benUser, 'Add the pressure readings.']]);
        $report('Annual maintenance', $D(-8), ServiceReportStatus::Reviewed, [[ServiceReportActionType::Submitted, $liaUser, null], [ServiceReportActionType::Reviewed, $benUser, null]]);

        // A PDF on the draft (UAT-33-07: "Open it on the web").
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
        $path = "service-reports/{$draft->public_id}/".Str::ulid().'.pdf';
        AttachmentDisk::filesystem()->put($path, $pdf);
        Attachment::query()->create([
            'owner_type' => AttachmentOwnerType::ServiceReport, 'service_report_id' => $draft->id,
            'original_filename' => 'UAT33 signed form.pdf', 'storage_disk' => AttachmentDisk::name(), 'storage_path' => $path,
            'mime_type' => 'application/pdf', 'size_bytes' => strlen($pdf), 'uploaded_by_user_id' => $liaUser->id,
        ]);

        $incident = function (string $text, IncidentReportStatus $status, ?Staff $assignee, array $steps, int $hoursAgo) use ($lia, $liaUser, $now) {
            $r = new IncidentReport([
                'reporter_staff_id' => $lia->id, 'assigned_to_staff_id' => $assignee?->id, 'created_by_user_id' => $liaUser->id,
                'occurred_at' => $now->copy()->subHours($hoursAgo), 'location' => 'Loading bay',
                'incident_type' => IncidentReportType::WorkplaceSafety, 'severity' => IncidentSeverity::High,
                'description' => T33.$text, 'immediate_action_taken' => 'Put up a warning sign.', 'status' => $status,
            ]);
            $r->save();
            foreach ($steps as $i => [$action, $actor, $note]) {
                $a = $r->actions()->make(['action' => $action, 'acted_by_user_id' => $actor->id, 'note' => $note]);
                $a->created_at = $now->copy()->subHours($hoursAgo)->addMinutes(10 + $i);
                $a->save();
            }
        };
        $incident('Wet floor, a near slip.', IncidentReportStatus::Reported, null, [[IncidentReportActionType::Reported, $liaUser, null]], 5);
        $incident('Forklift scraped a rack.', IncidentReportStatus::UnderInvestigation, $ben, [[IncidentReportActionType::Reported, $liaUser, null], [IncidentReportActionType::InvestigationStarted, $benUser, null]], 30);

        $out("seed: UAT31 leave (balance 15 for {$year}, 5 requests for Lia, 2 pending for Kai), UAT32 (2 announcements, 2 chats, 4 notifications), UAT33 (4 service reports, 1 PDF, 2 incidents) set for company day {$today}");
    });
    foreach ($pdfPaths as $p) {
        AttachmentDisk::filesystem()->delete($p);
    }
    $out('seed: done');
    exit(0);
}

if ($stage === 'verify') {
    [$s, $b] = $call($liaUser, fn ($r) => app(LeaveBalanceController::class)->myIndex($r));
    $uat = collect($b['data'] ?? [])->first(fn ($x) => $x['leave_type']['name'] === 'UAT31 Annual Leave');
    $out("31 Lia balances: {$s}; UAT31 Annual ".($uat ? "{$uat['remaining_days']} left of {$uat['allocated_days']} ({$uat['used_days']} used, {$uat['pending_days']} pending), year {$uat['year']}" : 'MISSING — UNEXPECTED'));
    [$s, $b] = $call($liaUser, fn ($r) => app(MyLeaveRequestController::class)->myIndex($r), ['per_page' => 20]);
    $out("31 Lia requests: {$s}; ".collect($b['data'])->map(fn ($x) => "{$x['start_date']} {$x['status']}")->implode(', ').'; company_day '.($b['meta']['company_day']['date'] ?? 'MISSING'));
    [$s, $b] = $call($benUser, fn ($r) => app(MyTeamLeaveRequestController::class)->index($r), ['per_page' => 20]);
    $out("31 Ben team: {$s}; can_review=".json_encode($b['meta']['can_review'] ?? null).' reports='.($b['meta']['direct_reports_count'] ?? '?').' pending='.($b['meta']['pending_count'] ?? '?').'; '.collect($b['data'] ?? [])->map(fn ($x) => "{$x['staff']['display_name']} {$x['start_date']}")->implode(', '));
    [$s, $b] = $call($liaUser, fn ($r) => app(MyTeamLeaveRequestController::class)->index($r));
    $out("31 Lia team: {$s}; can_review=".json_encode($b['meta']['can_review'] ?? null).' (no Team requests)');
    [$s] = $call($users['noprofile'], fn ($r) => app(MyLeaveRequestController::class)->myIndex($r));
    $out("31 noprofile /me/leave-requests: {$s}".($s === 403 ? ' (no-profile state: correct)' : ' — UNEXPECTED'));

    [$s, $b] = $call($liaUser, fn ($r) => app(ConversationController::class)->index($r), ['per_page' => 20]);
    $out("32 Lia chats: {$s}; ".collect($b['data'] ?? [])->map(fn ($c) => ($c['name'] ?? 'direct').' unread='.$c['unread_count'].' last="'.Str::limit($c['last_message']['body'] ?? '', 40).'"')->implode('; '));
    [$s, $b] = $call($liaUser, fn ($r) => app(NotificationController::class)->myUnreadCount($r));
    $out("32 Lia unread notifications: {$s}; ".($b['data']['unread_count'] ?? '?'));
    [$s, $b] = $call($liaUser, fn ($r) => app(MyAnnouncementController::class)->myIndex($r), ['per_page' => 20]);
    $out("32 Lia announcements: {$s}; ".collect($b['data'] ?? [])->filter(fn ($a) => str_starts_with($a['title'], T32))->map(fn ($a) => $a['title'].($a['acknowledged_at'] ? ' (acknowledged)' : ' (new)'))->implode(', '));
    [$s, $b] = $call($users['noprofile'], fn ($r) => app(NotificationController::class)->myUnreadCount($r));
    $out("32 noprofile notifications: {$s} (works without a profile)");

    [$s, $b] = $call($liaUser, fn ($r) => app(ServiceReportController::class)->index($r), ['per_page' => 20]);
    $out("33 Lia service reports: {$s}; ".collect($b['data'] ?? [])->map(fn ($x) => Str::limit($x['work_performed'], 32).' '.$x['status'].' att='.count($x['attachments'] ?? []))->implode('; '));
    [$s, $b] = $call($liaUser, fn ($r) => app(IncidentReportController::class)->index($r), ['per_page' => 20]);
    $out("33 Lia incidents: {$s}; ".collect($b['data'] ?? [])->map(fn ($x) => Str::limit($x['description'], 30).' '.$x['status'].' '.$manila($x['occurred_at']).' att='.count($x['attachments'] ?? []))->implode('; '));
    [$s, $b] = $call($benUser, fn ($r) => app(ServiceReportController::class)->index($r), ['per_page' => 50]);
    $out("33 Ben sees Lia's service reports: {$s}; ".collect($b['data'] ?? [])->where('creator.public_id', $lia->public_id)->count());
    exit(0);
}

if ($stage === 'send') {
    $direct = Conversation::query()->where('type', ConversationType::Direct)
        ->whereHas('members', fn ($q) => $q->where('staff_id', $lia->id))
        ->whereHas('members', fn ($q) => $q->where('staff_id', $ben->id))->first();
    if ($direct === null) {
        $out('send: no Lia–Ben chat (run seed); nothing sent');
        exit(1);
    }
    [$s, $b] = $call($benUser, fn ($r) => app(MessageController::class)->store($r, $direct), [], StoreMessageRequest::class,
        ['body' => T32.'Live message '.now()->tz($tz)->format('H:i:s')]);
    $out("send: {$s} \"".($b['data']['body'] ?? $b['message'] ?? '?').'"');
    exit($s === 201 ? 0 : 1);
}

if ($stage === 'reject') {
    $r = ServiceReport::query()->where('creator_staff_id', $lia->id)->where('status', ServiceReportStatus::Submitted)->latest('id')->first();
    if ($r === null) {
        $out('reject: Lia has no submitted service report; nothing changed');
        exit(1);
    }
    [$s, $b] = $call($benUser, fn ($q) => app(ServiceReportController::class)->reject($q, $r), [], RejectServiceReportRequest::class, ['reason' => 'Add the meter reading, please.']);
    $out("reject: {$s} \"".Str::limit($r->work_performed, 40).'" → '.($b['data']['status'] ?? $b['message'] ?? '?'));
    exit($s === 200 ? 0 : 1);
}

if ($stage === 'investigate') {
    $r = IncidentReport::query()->where('reporter_staff_id', $lia->id)->where('status', IncidentReportStatus::Reported)->latest('id')->first();
    if ($r === null) {
        $out('investigate: Lia has no reported incident; nothing changed');
        exit(1);
    }
    [$s, $b] = $call($benUser, fn ($q) => app(IncidentReportController::class)->startInvestigation($q, $r), [], StartInvestigationIncidentReportRequest::class, ['assigned_to_staff_id' => $ben->public_id]);
    $out("investigate: {$s} \"".Str::limit($r->description, 40).'" → '.($b['data']['status'] ?? $b['message'] ?? '?'));
    exit($s === 200 ? 0 : 1);
}

$out("unknown stage: {$stage}");
exit(1);
```
