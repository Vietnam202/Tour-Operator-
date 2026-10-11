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
pass "real staging restore, authenticated UAT and operator signoff remain required"
