#!/usr/bin/env bash
# CI ONLY: ephemeral self-signed TLS + nginx -> PHP-FPM -> disposable MariaDB.
# This script NEVER connects to external hosts and never deploys to CyberPanel.
set -euo pipefail
PRIVATE_DIR="$1"
ROOT="$(pwd -P)"
test "$GITHUB_ACTIONS" = true
test "$VTA_V5_HTTP_URL" = 'http://127.0.0.1:18869'
test "$VTA_V5_HTTP_PORT" = '18869'
test -n "$VTA_TEST_DB_PASSWORD"
test -n "$VTA_V5_TEST_PASSWORD"
test "$VTA_V5_HTTP_CONFIG" = "$PRIVATE_DIR/config.php"
test -f "$PRIVATE_DIR/stage-config.php"
test -d "$PRIVATE_DIR/staging-documents"
test "$(realpath "$PRIVATE_DIR")" != "$ROOT"
case "$(realpath "$PRIVATE_DIR")" in "$ROOT"/*) exit 2;; esac
test "$VTA_RC62_STAGING_HOST_PIN" = staging-fixture.example.invalid
[[ "$VTA_RC62_STAGING_DB_PIN" =~ ^vta_test_[0-9a-f]{10}$ ]]
test "$VTA_RC62_STAGING_STORAGE_PIN" = "$(realpath "$PRIVATE_DIR/staging-documents")"
command -v nginx >/dev/null
command -v php-fpm8.3 >/dev/null
command -v openssl >/dev/null
umask 077
openssl req -x509 -nodes -newkey rsa:2048 -sha256 -days 1 \
  -keyout "$PRIVATE_DIR/tls.key" -out "$PRIVATE_DIR/tls.crt" \
  -subj '/CN=staging-fixture.example.invalid' \
  -addext 'subjectAltName=DNS:staging-fixture.example.invalid' \
  -addext 'extendedKeyUsage=serverAuth' >/dev/null 2>&1
mkdir -p "$PRIVATE_DIR/sessions"
cat >"$PRIVATE_DIR/php-fpm.conf" <<EOF
[global]
daemonize = no
error_log = $PRIVATE_DIR/php-fpm.log
[ci]
listen = 127.0.0.1:19090
pm = static
pm.max_children = 2
clear_env = no
php_admin_value[session.save_path] = $PRIVATE_DIR/sessions
php_admin_value[log_errors] = 1
php_admin_value[error_log] = $PRIVATE_DIR/php-errors.log
EOF
cat >"$PRIVATE_DIR/nginx.conf" <<EOF
pid $PRIVATE_DIR/nginx.pid;
worker_processes 1;
error_log $PRIVATE_DIR/nginx-errors.log warn;
events { worker_connections 128; }
http {
  access_log off;
  server {
    listen 127.0.0.1:18443 ssl default_server;
    server_name staging-fixture.example.invalid;
    ssl_certificate $PRIVATE_DIR/tls.crt;
    ssl_certificate_key $PRIVATE_DIR/tls.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    add_header Cache-Control no-store always;
    location = /api/index.php {
      include /etc/nginx/fastcgi_params;
      fastcgi_pass 127.0.0.1:19090;
      fastcgi_param SCRIPT_FILENAME $ROOT/api/index.php;
      fastcgi_param SCRIPT_NAME /api/index.php;
      fastcgi_param HTTPS on;
      fastcgi_param HTTP_HOST \$http_host;
      fastcgi_param VTA_CONFIG_FILE $PRIVATE_DIR/stage-config.php;
      fastcgi_param VTA_RC62_STAGING_HOST_PIN staging-fixture.example.invalid;
      fastcgi_param VTA_RC62_STAGING_DB_PIN $VTA_RC62_STAGING_DB_PIN;
      fastcgi_param VTA_RC62_STAGING_STORAGE_PIN $VTA_RC62_STAGING_STORAGE_PIN;
    }
    location / { return 404; }
  }
  server {
    listen 127.0.0.1:18444 ssl;
    server_name staging-fixture.example.invalid;
    ssl_certificate $PRIVATE_DIR/tls.crt;
    ssl_certificate_key $PRIVATE_DIR/tls.key;
    location = /api/index.php {
      include /etc/nginx/fastcgi_params;
      fastcgi_pass 127.0.0.1:19090;
      fastcgi_param SCRIPT_FILENAME $ROOT/api/index.php;
      fastcgi_param SCRIPT_NAME /api/index.php;
      fastcgi_param HTTPS on;
      fastcgi_param HTTP_HOST \$http_host;
      fastcgi_param VTA_CONFIG_FILE $PRIVATE_DIR/stage-config.php;
      fastcgi_param VTA_RC62_STAGING_HOST_PIN staging-fixture.example.invalid;
      fastcgi_param VTA_RC62_STAGING_DB_PIN "";
      fastcgi_param VTA_RC62_STAGING_STORAGE_PIN $VTA_RC62_STAGING_STORAGE_PIN;
    }
    location / { return 404; }
  }
}
EOF
php-fpm8.3 --fpm-config "$PRIVATE_DIR/php-fpm.conf" -t >/dev/null 2>&1
nginx -t -c "$PRIVATE_DIR/nginx.conf" -p "$PRIVATE_DIR/" >/dev/null 2>&1
php-fpm8.3 --fpm-config "$PRIVATE_DIR/php-fpm.conf" -F >/dev/null 2>&1 &
FPM_PID=$!
nginx -c "$PRIVATE_DIR/nginx.conf" -p "$PRIVATE_DIR/" -g 'daemon off;' >/dev/null 2>&1 &
NGINX_PID=$!
cleanup() {
  kill "$NGINX_PID" "$FPM_PID" 2>/dev/null || :
  wait "$NGINX_PID" "$FPM_PID" 2>/dev/null || :
}
trap cleanup EXIT
export VTA_RC62_TLS_CA="$PRIVATE_DIR/tls.crt"
export VTA_RC62_TLS_TARGET='staging-fixture.example.invalid'
node tests/rc62-stage-real-tls-fpm.cjs
