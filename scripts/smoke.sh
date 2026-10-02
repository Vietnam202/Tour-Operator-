#!/bin/sh
set -eu
BASE_URL="${1:-${NEXT_PUBLIC_SITE_URL:-}}"
if [ -z "$BASE_URL" ]; then
  echo "Usage: npm run smoke -- https://staging.example.com"
  exit 2
fi
BASE_URL="${BASE_URL%/}"
curl -fsS "$BASE_URL/api/live" >/dev/null
curl -fsS "$BASE_URL/api/health" >/dev/null
echo "Smoke checks passed."
