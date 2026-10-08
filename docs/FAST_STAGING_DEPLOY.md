# Fast staging deployment

## Scope and current checkpoint

Repository: `Vietnam202/Tour-Operator-` (public). Only branch
`codex/Vietnam/rc6.2-testing` deploys to
`https://v2quote.vietnamtraveladvisor.com.vn`.

VS2.4 checkpoint `9de9f6c951df1e1a1ad6231bb02d402e2420109c` and migration
035 have already passed the staging deployment checks. The private result is
`/home/v2quote.vietnamtraveladvisor.com.vn/vta_private/vs24-final-complete.json`.
The last complete pre-VS2.4 backup is
`/home/v2quote.vietnamtraveladvisor.com.vn/backup/vs24-before-9de9f6c951df.tar.gz`
(SHA-256 `611b63e297da759eea4a96818f353b14c73f870fbea56771e1a45906b829ba60`).
The subsequent quotation-confirmation/booking/handover browser smoke is incomplete;
a deliberately below-policy quote correctly returned `MARGIN_REVIEW_REQUIRED`.
Do not mark the owner-test checkpoint complete solely from deployment health.

**Fast deployment activation has not yet been confirmed.** The controller has local
safety tests; no persistent server deployment trigger has been installed yet.
Do not describe this mechanism as operational until the adoption, no-op deployment,
rollback rehearsal and server checks below have passed.

## Confirmed server paths

| Item | Confirmed value |
| --- | --- |
| Staging owner | `vquot8508` |
| Document root | `/home/v2quote.vietnamtraveladvisor.com.vn/public_html` |
| Private config | `/home/v2quote.vietnamtraveladvisor.com.vn/vta_private/config.php` |
| PHP | `/usr/local/lsws/lsphp83/bin/php` |
| Database | `127.0.0.1`, `v2qu_v2qu_vtaos` |
| Controller directory to create | `/home/v2quote.vietnamtraveladvisor.com.vn/vta_private/fast-deploy` |
| Isolated source cache to create | `.../fast-deploy/source` |

CyberPanel Git Manager was inspected at
`https://server1.vietnamtraveladvisor.vn:8090/websites/v2quote.vietnamtraveladvisor.com.vn/manageGIT`.
Its observed folder selector offered only `public_html`. It was not initialized.
Git must stay outside the public root and customer data must stay outside the source
cache. Use the private Git pull trigger below unless Git Manager can demonstrably
invoke the same reviewed private controller without resetting the public tree.

## Initial activation (staging owner only)

Perform this once through the authenticated staging hosting session. No ZIP, PAT,
new public endpoint or production access is required. The repository is public.
Run `command -v git flock readlink` and check PHP curl/PDO MySQL support first.
The shell syntax check must also pass on the server before installing cron.

The following commands create new private paths. Stop if `fast-deploy` already
exists; inspect it instead of overwriting an unknown setup. Replace
`REVIEWED_DEPLOY_COMMIT` with the exact committed and reviewed controller SHA.
Do not substitute a branch name for that pin.

```sh
# Run as vquot8508, not root or another website user.
test "$(id -un)" = vquot8508 || exit 1
CONTROL=/home/v2quote.vietnamtraveladvisor.com.vn/vta_private/fast-deploy
test ! -e "$CONTROL" || exit 1
mkdir -m 700 "$CONTROL"
git clone --single-branch --branch codex/Vietnam/rc6.2-testing \
  https://github.com/Vietnam202/Tour-Operator-.git "$CONTROL/source"
test "$(git -C "$CONTROL/source" rev-parse HEAD)" = REVIEWED_DEPLOY_COMMIT || exit 1
/bin/sh -n "$CONTROL/source/deploy/deploy-staging.sh"
/usr/local/lsws/lsphp83/bin/php -d opcache.enable_cli=0 -l "$CONTROL/source/deploy/staging-release.php"
install -m 700 "$CONTROL/source/deploy/deploy-staging.sh" "$CONTROL/deploy-staging.sh"
install -m 600 "$CONTROL/source/deploy/staging-release.php" "$CONTROL/staging-release.php"
/bin/sh "$CONTROL/deploy-staging.sh" --adopt 9de9f6c951df1e1a1ad6231bb02d402e2420109c
/bin/sh "$CONTROL/deploy-staging.sh" --check
```

Adoption verifies the actual VS2.4 completion record, staging configuration,
all managed source bytes against the immutable VS2.4 Git tree, every applied SQL
checksum, database connectivity and HTTP/API/assets. It may then deploy a newer
Document Editor target through the normal guarded delta plan. Unexpected hosting
edits, migration changes and untracked source collisions still stop deployment. A non-SQL file with the same content but CRLF/LF representation
can be adopted; those server bytes remain intact until that file actually changes.
It never rebases/replaces a migration checksum. It does not alter source or database
while recording the initial baseline.

A controller-only release creates a rollback journal with zero application file
changes. An adoption targeting Document Editor creates the normal source-change
backup and rollback journal before installing the editor. Rehearse rollback using the exact `backup` identifier in the private
`last-result.json`, verify `--check`, then `--resume` and run the controller once.
Read-only inspection should confirm that private config, uploads/documents and
historical snapshots are intact. The replay must advance to the reviewed testing
commit and the next invocation must print `UNCHANGED`.

## PHP CLI workaround and existing-controller update

On the VPS, ordinary PHP 8.3.30 CLI lint exited 139 while the same lint with
-d opcache.enable_cli=0 exited 0. Every wrapper entry and child lint/health command
uses this CLI-only override. Do not use -n for server deployment: it drops normal
INI loading and can hide PDO MySQL/curl. Do not edit php.ini, .user.ini or live
website OPcache settings.

These commands update an already-cloned private checkout and controller.
Run as vquot8508 with the canonical fast-deploy/source checkout; replace
REVIEWED_DEPLOY_COMMIT with the exact published patch SHA. Stop on any failed
guard. Do not change ownership of public/customer directories to bypass errors.

~~~sh
set -eu
umask 077
test "$(id -un)" = vquot8508
CONTROL=/home/v2quote.vietnamtraveladvisor.com.vn/vta_private/fast-deploy
SOURCE="$CONTROL/source"
PHP=/usr/local/lsws/lsphp83/bin/php
BRANCH=codex/Vietnam/rc6.2-testing
RELEASE=REVIEWED_DEPLOY_COMMIT
test "$(readlink -f "$CONTROL")" = "$CONTROL"
test "$(readlink -f "$SOURCE")" = "$SOURCE"
test "$(git -C "$SOURCE" remote get-url origin)" = https://github.com/Vietnam202/Tour-Operator-.git
test "$(git -C "$SOURCE" branch --show-current)" = "$BRANCH"
exec 9>"$CONTROL/deploy.lock"; flock -n 9
exec 8>"$CONTROL/release.lock"; flock -n 8
test -z "$(git -C "$SOURCE" status --porcelain --untracked-files=all)"
git -C "$SOURCE" -c http.sslVerify=true fetch --no-tags origin "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"
test "$(git -C "$SOURCE" rev-parse "origin/$BRANCH")" = "$RELEASE"
git -C "$SOURCE" merge --ff-only "$RELEASE"
test "$(git -C "$SOURCE" rev-parse HEAD)" = "$RELEASE"
test "$(stat -c %U "$CONTROL")" = vquot8508
git -C "$SOURCE" diff --exit-code 9de9f6c951df1e1a1ad6231bb02d402e2420109c "$RELEASE" -- api/migrations .user.ini
"$PHP" -d opcache.enable_cli=0 -r 'foreach (["pdo_mysql","curl"] as $e) {if (!extension_loaded($e)) {fwrite(STDERR,"MISSING ".$e."\n");exit(1);}} if (!in_array("mysql",PDO::getAvailableDrivers(),true)) exit(1); echo "PASS PDO MySQL/curl; CLI OPcache=".(ini_get("opcache.enable_cli")?:0)."\n";'
/bin/sh -n "$SOURCE/deploy/deploy-staging.sh"
"$PHP" -d opcache.enable_cli=0 -l "$SOURCE/deploy/staging-release.php"
BACKUP="$CONTROL/controller-backups/$(date -u +%Y%m%dT%H%M%SZ)-$(printf %.12s "$RELEASE")"
test ! -e "$BACKUP"; mkdir -m 700 -p "$BACKUP"
for FILE in deploy-staging.sh staging-release.php; do
  test ! -L "$CONTROL/$FILE"
  if test -e "$CONTROL/$FILE"; then
    cp -p "$CONTROL/$FILE" "$BACKUP/$FILE"
    cmp -s "$CONTROL/$FILE" "$BACKUP/$FILE"
  fi
done
test ! -e "$CONTROL/deploy-staging.sh.new"
test ! -e "$CONTROL/staging-release.php.new"
install -m 700 "$SOURCE/deploy/deploy-staging.sh" "$CONTROL/deploy-staging.sh.new"
install -m 600 "$SOURCE/deploy/staging-release.php" "$CONTROL/staging-release.php.new"
cmp -s "$SOURCE/deploy/deploy-staging.sh" "$CONTROL/deploy-staging.sh.new"
cmp -s "$SOURCE/deploy/staging-release.php" "$CONTROL/staging-release.php.new"
mv "$CONTROL/staging-release.php.new" "$CONTROL/staging-release.php"
mv "$CONTROL/deploy-staging.sh.new" "$CONTROL/deploy-staging.sh"
flock -u 8; flock -u 9
if test -e "$CONTROL/deployed.json"; then
  /bin/sh "$CONTROL/deploy-staging.sh" --check
  /bin/sh "$CONTROL/deploy-staging.sh"
else
  /bin/sh "$CONTROL/deploy-staging.sh" --adopt 9de9f6c951df1e1a1ad6231bb02d402e2420109c
fi
/bin/sh "$CONTROL/deploy-staging.sh" --check
"$PHP" -d opcache.enable_cli=0 -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);if(($r["result"]??"")!=="PASS"||($r["commit"]??"")!==$argv[2]||($r["database"]??"")!=="UNCHANGED")exit(1);echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";' "$CONTROL/last-result.json" "$RELEASE"
~~~

The extension check above verifies the actual VPS runtime; local tests do not
establish VPS extension availability. Keep the controller backup path and
deployment backup ID. If HALTED, baseline drift or a migration error occurs, stop;
do not delete state/markers, rewrite SQL, or restore an older database.
After PASS, log in to staging and run Document import → edit → save → refresh/reopen
→ DOCX/PDF export, then open existing 3★/4★/5★ costing. CLI health is not browser acceptance.

## Commit-triggered deployment

After successful activation, add one persistent entry in **the staging owner's**
CyberPanel cron list; preserve every other existing entry:

```cron
* * * * * /bin/sh /home/v2quote.vietnamtraveladvisor.com.vn/vta_private/fast-deploy/deploy-staging.sh >> /home/v2quote.vietnamtraveladvisor.com.vn/vta_private/fast-deploy/deploy.log 2>&1
```

This is a private pull trigger: it detects pushed testing commits within roughly
one minute. It uses normal verified HTTPS reads from GitHub and requires no webhook
secret or inbound deploy endpoint. `flock` prevents overlapping runs. The installed
controller is pinned outside the fetched checkout; repository changes cannot silently
replace the running deployment controller.

Daily changes: edit only the requested module, run focused tests/syntax checks,
commit and push normally to `codex/Vietnam/rc6.2-testing`, wait for the private result
SHA to match that commit, then perform the relevant browser smoke. Never force push,
rewrite main, upload whole source or deploy another branch. No npm/composer/build
step is required by this PHP/vanilla JavaScript application.

## Data and migration safeguards

- Git checkout/fetch occurs only in the new private source cache. No public-root
  Git repository, blanket website overwrite or `git clean` is used.
- Only changed, managed root app files, PHP/SQL under `api`, and static assets are
  installed. Docs/tests/deploy controllers and example configuration are excluded.
- `.env`, private configuration, uploads, storage/runtime, logs, cache, backups,
  generated documents, keys and local database files are excluded. Tracked server
  data, symlinks, path escapes, unknown file collisions and hosting edits stop deploy.
- Existing committed verification logs are preserved unchanged and excluded from the release manifest. Only logs with the exact baseline Git blob are allowed; newly tracked or modified logs are rejected. They are historical test evidence, not hosting runtime logs.
- Source removals are limited to files previously recorded as managed source; data
  directories and untracked files are never recursively deleted.
- Every release saves and verifies private copies of affected source, `.htaccess`,
  prior state and configuration before changing application files. Configuration
  is backed up but never overwritten by deploy or rollback.
- Brief `.htaccess` maintenance protects cutover. Files are replaced atomically;
  a journal enables recovery of an interrupted partial deployment.
- The existing migration advisory lock prevents concurrent migration. Both migration
  ledgers must exactly match the current applied SQL checksums. No SQL migration,
  schema write, reset, seed, database creation or database restore is executed.
- New/changed/deleted migration files stop fast deploy with
  `MIGRATION_REVIEW_REQUIRED`. A future database task needs its own approved backup,
  migration/compatibility review and deliberate deployment-state adaptation. Do not
  bypass the check, edit old SQL or reinitialize the database to resume daily deploy.
- Rollback changes source only. It cannot undo unrelated business transactions or
  customer edits and does not restore an older database over current data.

## Verification, failures and rollback

The controller checks source hashes, changed PHP syntax, the existing CLI health
check (configuration, database/core table and storage), HTTP app page, API bootstrap,
anonymous auth rejection (`401 AUTH_REQUIRED`), and the exact bytes of quotation,
operations and costing JavaScript assets. It rechecks config and migration ledgers.
It does not log database credentials or HTTP login sessions. HTTPS verification is
always enabled and redirects to another host are rejected.

An installation/health failure returns a nonzero exit code, restores verified source
preimages, leaves a private `HALTED` marker and pauses later releases. Inspect
`last-result.json`/`deploy.log`, relevant CyberPanel PHP/server error logs, and run
`--check`. Browser console errors, actual login and workflow acceptance remain a
browser check, not a claim derived from the HTTP probes. If rollback itself fails,
the active journal stays private for recovery; do not push more changes to bypass it.

```sh
CONTROL=/home/v2quote.vietnamtraveladvisor.com.vn/vta_private/fast-deploy
/bin/sh "$CONTROL/deploy-staging.sh" --check
# Roll back only the current release using its exact backup ID from last-result.json.
/bin/sh "$CONTROL/deploy-staging.sh" --rollback BACKUP_ID
# After diagnosing the failure and committing a fix, resume deliberately.
/bin/sh "$CONTROL/deploy-staging.sh" --resume
/bin/sh "$CONTROL/deploy-staging.sh"
```

Do not remove the halt marker manually. Keep verified backups and the original
VS2.4 backup; no automatic backup/log retention deletion is configured. Controller
updates must be reviewed and installed explicitly, with the old private controllers
saved for rollback, rather than executing new deployment code fetched by cron.

## Local focused verification

```sh
php -d opcache.enable_cli=0 -l deploy/staging-release.php
php -d opcache.enable_cli=0 -l tests/staging_deploy_safety.php
/bin/sh -n deploy/deploy-staging.sh
php -d opcache.enable_cli=0 tests/staging_deploy_safety.php
```

The disposable fixture checks real cutover/rollback, interrupted writes, exact
migration-ledger failures, preserved runtime/config/customer files, source drift,
untracked collisions and path restrictions. It does not connect to staging or rerun
the application regression suite. Server activation and the VS2.4 end-to-end browser
smoke remain separately required.

Implementation references: [Git fetch](https://git-scm.com/docs/git-fetch) and
[PHP process invocation](https://www.php.net/manual/en/function.proc-open.php).
