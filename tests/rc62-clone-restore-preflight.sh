#!/usr/bin/env bash
set -euo pipefail

# CI-only backup/restore and read-only preflight over the synthetic MariaDB fixture.
fail() { printf 'FAIL_CI %s\n' "$*" >&2; exit 1; }
pass() { printf 'PASS_CI %s\n' "$*"; }
[[ "$GITHUB_ACTIONS" == "true" ]] || fail "CI_ONLY"
[[ "$VTA_TEST_DSN" == "mysql:host=127.0.0.1;port=3306;dbname=vta_p12_clone_ci;charset=utf8mb4" ]] || fail "SOURCE_DSN_PIN_REQUIRED"
[[ "$VTA_TEST_ROOT_PASSWORD" == "ci_root_password" ]] || fail "SYNTHETIC_ROOT_CREDENTIAL_REQUIRED"
[[ -d "$RUNNER_TEMP" ]] || fail "RUNNER_TEMP_REQUIRED"

SOURCE_DB="vta_p12_clone_ci"
CLONE_DB="vta_rc62_restore_clone_ci"
RO_USER="vta_rc62_preflight_ci"
RO_PASSWORD="vta_rc62_ro_fixture_password"
export MYSQL_PWD="$VTA_TEST_ROOT_PASSWORD"
root_sql() { mariadb --protocol=tcp --host=127.0.0.1 --port=3306 --user=root --batch --skip-column-names --execute="$1"; }
PRIVATE_DIR="$(mktemp -d "$RUNNER_TEMP/vta-rc62-restore.XXXXXXXX")"
chmod 700 "$PRIVATE_DIR"
CLONE_CREATED=0
USER_CREATED=0
cleanup() {
  rc=$?
  set +e
  if [[ "$USER_CREATED" == "1" ]]; then root_sql "DROP USER IF EXISTS '$RO_USER'@'%'" >/dev/null 2>&1; fi
  if [[ "$CLONE_CREATED" == "1" ]]; then root_sql "DROP DATABASE IF EXISTS $CLONE_DB" >/dev/null 2>&1; fi
  rm -rf -- "$PRIVATE_DIR"
  exit "$rc"
}
trap cleanup EXIT

command -v mariadb-dump >/dev/null || fail "MARIADB_DUMP_REQUIRED"
existing="$(root_sql "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$CLONE_DB'")"
[[ "$existing" == "0" ]] || fail "RESTORE_TARGET_MUST_BE_NEW"
tables="$(root_sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$SOURCE_DB' AND TABLE_TYPE='BASE TABLE'")"
[[ "$tables" -gt 0 ]] || fail "MIGRATED_SOURCE_FIXTURE_REQUIRED"

python3 deploy/build-integrated-package.py --output "$PRIVATE_DIR/package" >"$PRIVATE_DIR/package.log"
find "$PRIVATE_DIR/package" -maxdepth 1 -type f -name 'vta-rc62-manifest-*.json' -print >"$PRIVATE_DIR/manifest-paths"
[[ "$(wc -l <"$PRIVATE_DIR/manifest-paths" | tr -d ' ')" == "1" ]] || fail "EXACT_SHA_MANIFEST_REQUIRED"
cp -- "$(cat "$PRIVATE_DIR/manifest-paths")" "$PRIVATE_DIR/manifest.json"
chmod 600 "$PRIVATE_DIR/manifest.json"
EXPECTED_SHA="$(git rev-parse HEAD)"
EXPECTED_MIGRATIONS="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1],encoding="utf-8"))["migration_count"])' "$PRIVATE_DIR/manifest.json")"

mariadb-dump --protocol=tcp --host=127.0.0.1 --port=3306 --user=root --single-transaction --routines --triggers --events --hex-blob --skip-comments "$SOURCE_DB" >"$PRIVATE_DIR/source.sql"
chmod 600 "$PRIVATE_DIR/source.sql"
root_sql "CREATE DATABASE $CLONE_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
CLONE_CREATED=1
mariadb --protocol=tcp --host=127.0.0.1 --port=3306 --user=root --database="$CLONE_DB" <"$PRIVATE_DIR/source.sql"

for dbname in "$SOURCE_DB" "$CLONE_DB"; do
  root_sql "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$dbname' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME" >"$PRIVATE_DIR/$dbname.tables"
  root_sql "SELECT version, applied_at FROM $dbname.schema_migrations ORDER BY version" >"$PRIVATE_DIR/$dbname.schema-ledger"
  root_sql "SELECT version, sha256, status FROM $dbname.migration_checksums ORDER BY version" >"$PRIVATE_DIR/$dbname.checksum-ledger"
  : >"$PRIVATE_DIR/$dbname.count-queries"
  while IFS= read -r table; do
    [[ "$table" =~ ^[A-Za-z0-9_]+$ ]] || fail "UNSAFE_FIXTURE_TABLE_NAME"
    printf "SELECT '%s', COUNT(*) FROM %s.%s;\n" "$table" "$dbname" "$table" >>"$PRIVATE_DIR/$dbname.count-queries"
  done <"$PRIVATE_DIR/$dbname.tables"
  mariadb --protocol=tcp --host=127.0.0.1 --port=3306 --user=root <"$PRIVATE_DIR/$dbname.count-queries" >"$PRIVATE_DIR/$dbname.counts"
done
diff -u "$PRIVATE_DIR/$SOURCE_DB.tables" "$PRIVATE_DIR/$CLONE_DB.tables" >/dev/null || fail "RESTORE_TABLE_INVENTORY_MISMATCH"
diff -u "$PRIVATE_DIR/$SOURCE_DB.counts" "$PRIVATE_DIR/$CLONE_DB.counts" >/dev/null || fail "RESTORE_ROW_COUNTS_MISMATCH"
diff -u "$PRIVATE_DIR/$SOURCE_DB.schema-ledger" "$PRIVATE_DIR/$CLONE_DB.schema-ledger" >/dev/null || fail "RESTORE_SCHEMA_LEDGER_MISMATCH"
diff -u "$PRIVATE_DIR/$SOURCE_DB.checksum-ledger" "$PRIVATE_DIR/$CLONE_DB.checksum-ledger" >/dev/null || fail "RESTORE_CHECKSUM_LEDGER_MISMATCH"
[[ "$(wc -l <"$PRIVATE_DIR/$CLONE_DB.checksum-ledger" | tr -d ' ')" == "$EXPECTED_MIGRATIONS" ]] || fail "RESTORE_MIGRATION_COUNT_MISMATCH"
for migration in api/migrations/*.sql; do
  version="$(basename "$migration" .sql)"
  expected_hash="$(sha256sum "$migration" | awk '{print $1}')"
  recorded="$(awk -v version="$version" '$1 == version { print $2, $3 }' "$PRIVATE_DIR/$CLONE_DB.checksum-ledger")"
  applied="$(awk -v version="$version" '$1 == version { print "yes" }' "$PRIVATE_DIR/$CLONE_DB.schema-ledger")"
  if [[ -n "$applied" && "$recorded" != "$expected_hash APPLIED" ]]; then
    printf 'BLOCKING_LEDGER_ENTRY version=%s applied=yes checksum_matches=%s status=%s\n' "$version" "$([[ "$recorded" == "$expected_hash APPLIED" ]] && echo yes || echo no)" "$(printf '%s' "$recorded" | awk '{print $2}')"
  fi
done
pass "synthetic backup/restore clone reconciles table row counts and migration ledgers"

root_sql "CREATE USER '$RO_USER'@'%' IDENTIFIED BY '$RO_PASSWORD'"
USER_CREATED=1
root_sql "GRANT SELECT ON $CLONE_DB.* TO '$RO_USER'@'%'"
mkdir -m 700 "$PRIVATE_DIR/documents"
export VTA_CI_CONFIG_DIR="$PRIVATE_DIR"
export VTA_CI_DB="$CLONE_DB"
export VTA_CI_USER="$RO_USER"
export VTA_CI_PASSWORD="$RO_PASSWORD"
php -r '$d=getenv("VTA_CI_CONFIG_DIR"); $c=["app"=>["env"=>"testing","base_url"=>"http://127.0.0.1:18869","timezone"=>"Asia/Ho_Chi_Minh"],"db"=>["host"=>"127.0.0.1","port"=>3306,"database"=>getenv("VTA_CI_DB"),"username"=>getenv("VTA_CI_USER"),"password"=>getenv("VTA_CI_PASSWORD")],"storage"=>["driver"=>"local","local_path"=>$d."/documents"],"security"=>["allowed_origin"=>"http://127.0.0.1:18869"]]; file_put_contents($d."/config.php","<?php\nreturn ".var_export($c,true).";\n"); chmod($d."/config.php",0600);'
export VTA_CONFIG_FILE="$PRIVATE_DIR/config.php"

run_preflight() {
  php api/bin/rc62-release-readiness.php "--manifest=$PRIVATE_DIR/manifest.json" "--expected-sha=$1" "--expected-db=$2"
}
if run_preflight "$EXPECTED_SHA" "$CLONE_DB" >"$PRIVATE_DIR/pass.json" 2>&1; then
  :
else
  cat "$PRIVATE_DIR/pass.json" >&2
  php -r '
  require "api/lib/Migrations.php";
  require "api/bin/rc62-release-readiness.php";
  $config=require getenv("VTA_CONFIG_FILE");
  $cfg=$config["db"];
  $expected=[];
  foreach (Migrations::orderedFiles(getcwd()."/api/migrations") as $file) {
    $expected[basename($file,".sql")]=hash_file("sha256",$file);
  }
  $db=new PDO(sprintf("mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4",$cfg["host"],$cfg["port"],$cfg["database"]),$cfg["username"],$cfg["password"]);
  $versions=array_map("strval",$db->query("SELECT version FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN));
  $ledger=$db->query("SELECT version,sha256,status FROM migration_checksums")->fetchAll(PDO::FETCH_ASSOC);
  $checks=[];
  foreach($ledger as $row)$checks[$row["version"]]=$row;
  $applied=array_fill_keys($versions,true);
  foreach($expected as $version=>$hash) {
    if(isset($applied[$version]) && (!isset($checks[$version]) || $checks[$version]["status"]!=="APPLIED" || !hash_equals($hash,(string)$checks[$version]["sha256"])))
      fwrite(STDERR,"DIAG_LEDGER_MISMATCH version=".$version." status=".($checks[$version]["status"]??"MISSING")." hash_match=".(isset($checks[$version])&&hash_equals($hash,(string)$checks[$version]["sha256"])?"yes":"no")."\\n");
  }
  try { RC62ReleaseReadiness::ledger($expected,$versions,$ledger); fwrite(STDERR,"DIAG_PDO_LEDGER_ACCEPTED\\n"); }
  catch(Throwable $e) { fwrite(STDERR,"DIAG_PDO_LEDGER_BLOCKED reason=".RC62ReleaseReadiness::safeFailure($e)."\\n"); }
  ' >&2
  fail "EXACT_SHA_READ_ONLY_PREFLIGHT_FAILED"
fi
grep -F '"status":"READ_ONLY_PREFLIGHT_PASS"' "$PRIVATE_DIR/pass.json" >/dev/null || fail "READ_ONLY_PREFLIGHT_STATUS_MISSING"
grep -F '"release_authorized":false' "$PRIVATE_DIR/pass.json" >/dev/null || fail "CI_MUST_NOT_AUTHORIZE_RELEASE"
grep -F '"migrations_checked":' "$PRIVATE_DIR/pass.json" >/dev/null || fail "MIGRATION_LEDGER_NOT_CHECKED"
pass "exact-SHA preflight accepts the restored clone through a read-only MariaDB account and keeps release NO-GO"

expect_block() {
  reason="$1"
  sha="$2"
  database="$3"
  if run_preflight "$sha" "$database" >"$PRIVATE_DIR/rejected.json" 2>&1; then
    fail "PREFLIGHT_SHOULD_BLOCK_$reason"
  else
    rc=$?
  fi
  [[ "$rc" == "2" ]] || fail "PREFLIGHT_WRONG_EXIT_$reason"
  grep -F "\"reason\":\"$reason\"" "$PRIVATE_DIR/rejected.json" >/dev/null || fail "PREFLIGHT_WRONG_REASON_$reason"
  pass "fail-closed preflight rejects $reason"
}
expect_block "RELEASE_SHA_MISMATCH" "0000000000000000000000000000000000000000" "$CLONE_DB"
expect_block "CLONE_DATABASE_MISMATCH" "$EXPECTED_SHA" "vta_rc62_other_clone_ci"
# Enforce actual SQL write denial, not only a PHP-side GRANT regex.
# A successful UPDATE would be an immediate CI failure (and release blocker).
if MYSQL_PWD="$RO_PASSWORD" mariadb --protocol=tcp --host=127.0.0.1 --port=3306 \
    --user="$RO_USER" --database="$CLONE_DB" --execute="UPDATE users SET full_name='unsafe-write-fixture' WHERE id=1" \
    >"$PRIVATE_DIR/unexpected-write.log" 2>&1; then
  fail "SELECT_ONLY_USER_COULD_MODIFY_CLONE"
fi
pass "dedicated preflight MariaDB account rejects UPDATE at the actual SQL authorization boundary"

# A simple table-count comparison misses overwritten cells. Deliberately alter
# a real synthetic fixture row ONLY in the restored disposable clone, then
# destroy and reconstruct the clone from the original dump and prove exact
# value, inventory, row counts and ledger are identical again. Never touch
# the source schema or keep SQL/PII in CI artifacts.
USER_ID="$(root_sql "SELECT MIN(id) FROM $CLONE_DB.users")"
[[ "$USER_ID" =~ ^[0-9]+$ && "$USER_ID" -gt 0 ]] || fail "CLONE_FIXTURE_USER_REQUIRED"
user_digest() {
  root_sql "SELECT SHA2(CONCAT_WS('|',id,full_name,email),256) FROM $1.users WHERE id=$USER_ID"
}
SOURCE_USER_DIGEST="$(user_digest "$SOURCE_DB")"
RESTORED_USER_DIGEST="$(user_digest "$CLONE_DB")"
[[ "$SOURCE_USER_DIGEST" == "$RESTORED_USER_DIGEST" ]] || fail "SOURCE_AND_CLONE_BASELINE_VALUE_MISMATCH"
root_sql "UPDATE $CLONE_DB.users SET full_name=CONCAT(full_name,'-rollback-probe') WHERE id=$USER_ID" >/dev/null
CHANGED_USER_DIGEST="$(user_digest "$CLONE_DB")"
[[ "$CHANGED_USER_DIGEST" != "$SOURCE_USER_DIGEST" ]] || fail "ROLLBACK_TEST_FAILED_TO_MUTATE_CLONE"
[[ "$(user_digest "$SOURCE_DB")" == "$SOURCE_USER_DIGEST" ]] || fail "ROLLBACK_TEST_MUTATED_SOURCE"
pass "synthetic clone value mutation is detected; source MariaDB remains byte-value unchanged"

root_sql "DROP DATABASE $CLONE_DB"
CLONE_CREATED=0
root_sql "CREATE DATABASE $CLONE_DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
CLONE_CREATED=1
mariadb --protocol=tcp --host=127.0.0.1 --port=3306 --user=root --database="$CLONE_DB" <"$PRIVATE_DIR/source.sql"
[[ "$(user_digest "$CLONE_DB")" == "$SOURCE_USER_DIGEST" ]] || fail "ROLLBACK_RESTORE_VALUE_MISMATCH"
[[ "$(user_digest "$SOURCE_DB")" == "$SOURCE_USER_DIGEST" ]] || fail "ROLLBACK_CHANGED_SOURCE"
root_sql "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$CLONE_DB' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME" >"$PRIVATE_DIR/after-restore.tables"
diff -u "$PRIVATE_DIR/$SOURCE_DB.tables" "$PRIVATE_DIR/after-restore.tables" >/dev/null || fail "ROLLBACK_TABLE_INVENTORY_MISMATCH"
: >"$PRIVATE_DIR/after-restore.count-queries"
while IFS= read -r table; do
  [[ "$table" =~ ^[A-Za-z0-9_]+$ ]] || fail "UNSAFE_RESTORED_TABLE"
  printf "SELECT '%s', COUNT(*) FROM %s.%s;\n" "$table" "$CLONE_DB" "$table" >>"$PRIVATE_DIR/after-restore.count-queries"
done <"$PRIVATE_DIR/after-restore.tables"
mariadb --protocol=tcp --host=127.0.0.1 --port=3306 --user=root <"$PRIVATE_DIR/after-restore.count-queries" >"$PRIVATE_DIR/after-restore.counts"
diff -u "$PRIVATE_DIR/$SOURCE_DB.counts" "$PRIVATE_DIR/after-restore.counts" >/dev/null || fail "ROLLBACK_ROW_COUNTS_MISMATCH"
root_sql "SELECT version, applied_at FROM $CLONE_DB.schema_migrations ORDER BY version" >"$PRIVATE_DIR/after-restore.schema-ledger"
root_sql "SELECT version, sha256, status FROM $CLONE_DB.migration_checksums ORDER BY version" >"$PRIVATE_DIR/after-restore.checksum-ledger"
diff -u "$PRIVATE_DIR/$SOURCE_DB.schema-ledger" "$PRIVATE_DIR/after-restore.schema-ledger" >/dev/null || fail "ROLLBACK_SCHEMA_LEDGER_MISMATCH"
diff -u "$PRIVATE_DIR/$SOURCE_DB.checksum-ledger" "$PRIVATE_DIR/after-restore.checksum-ledger" >/dev/null || fail "ROLLBACK_CHECKSUM_LEDGER_MISMATCH"
run_preflight "$EXPECTED_SHA" "$CLONE_DB" >"$PRIVATE_DIR/after-restore-readiness.json" 2>&1 || fail "ROLLBACK_PREFLIGHT_FAILED"
grep -F '"status":"READ_ONLY_PREFLIGHT_PASS"' "$PRIVATE_DIR/after-restore-readiness.json" >/dev/null || fail "ROLLBACK_PREFLIGHT_NOT_PASS"
grep -F '"release_authorized":false' "$PRIVATE_DIR/after-restore-readiness.json" >/dev/null || fail "ROLLBACK_MUST_NOT_AUTHORIZE_RELEASE"
pass "mutation -> cloned DB dropped -> backup restored -> row values, counts, schema/checksum ledgers and RO preflight recover"
pass "real staging restore, authenticated UAT and operator signoff remain required"
