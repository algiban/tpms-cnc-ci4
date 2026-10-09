#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-http://127.0.0.1}"
BASE_URL="${BASE_URL%/}"

printf 'TPMS production smoke check: %s\n' "$BASE_URL"
printf '\n[1/3] Liveness\n'
curl --fail --silent --show-error "$BASE_URL/health/live"
printf '\n\n[2/3] Readiness\n'
curl --fail --silent --show-error "$BASE_URL/health/ready"
printf '\n\n[3/3] Monitoring HTTP status\n'
curl --fail --silent --show-error --output /dev/null --write-out 'HTTP %{http_code} - %{time_total}s\n' "$BASE_URL/api/monitoring"
printf '\nPASS\n'
