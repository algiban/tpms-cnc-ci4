#!/usr/bin/env bash
set -euo pipefail

INTERVAL="${1:-5}"
OUTPUT="${2:-writable/stress/capacity-watch.csv}"
FPM_STATUS_URL="${TPMS_FPM_STATUS_URL:-http://127.0.0.1/fpm-status}"

mkdir -p "$(dirname "$OUTPUT")"
if [[ ! -f "$OUTPUT" ]]; then
  echo "timestamp,load1,load5,mem_used_mb,mem_available_mb,fpm_active,fpm_idle,fpm_total" > "$OUTPUT"
fi

os_name="$(uname -s 2>/dev/null || echo unknown)"

echo "Writing host/FPM capacity samples to $OUTPUT every ${INTERVAL}s"
echo "OS detected: $os_name"
echo "Ctrl+C to stop. MySQL counters should be captured separately with: php spark tpms:capacity-snapshot"

iso_timestamp() {
  # BSD date (macOS) does not support GNU `date -Iseconds`.
  date '+%Y-%m-%dT%H:%M:%S%z'
}

linux_metrics() {
  load1="$(awk '{print $1}' /proc/loadavg 2>/dev/null || true)"
  load5="$(awk '{print $2}' /proc/loadavg 2>/dev/null || true)"
  mem_used="$(free -m 2>/dev/null | awk '/^Mem:/ {print $3}' || true)"
  mem_avail="$(free -m 2>/dev/null | awk '/^Mem:/ {print $7}' || true)"
}

mac_metrics() {
  # vm.loadavg format: { 1.23 1.10 0.95 }
  loads="$(sysctl -n vm.loadavg 2>/dev/null | tr -d '{}')"
  load1="$(printf '%s\n' "$loads" | awk '{print $1}')"
  load5="$(printf '%s\n' "$loads" | awk '{print $2}')"

  total_bytes="$(sysctl -n hw.memsize 2>/dev/null || echo 0)"
  page_size="$(vm_stat 2>/dev/null | awk '/page size of/ {gsub(/[^0-9]/,"",$8); print $8; exit}')"
  if [[ -z "${page_size:-}" ]]; then
    page_size=4096
  fi

  vm="$(vm_stat 2>/dev/null || true)"
  free_pages="$(printf '%s\n' "$vm" | awk -F: '/Pages free/ {gsub(/[^0-9]/,"",$2); print $2; exit}')"
  inactive_pages="$(printf '%s\n' "$vm" | awk -F: '/Pages inactive/ {gsub(/[^0-9]/,"",$2); print $2; exit}')"
  speculative_pages="$(printf '%s\n' "$vm" | awk -F: '/Pages speculative/ {gsub(/[^0-9]/,"",$2); print $2; exit}')"

  free_pages="${free_pages:-0}"
  inactive_pages="${inactive_pages:-0}"
  speculative_pages="${speculative_pages:-0}"

  available_bytes=$(( (free_pages + inactive_pages + speculative_pages) * page_size ))
  if (( available_bytes > total_bytes )); then
    available_bytes="$total_bytes"
  fi
  used_bytes=$(( total_bytes - available_bytes ))
  mem_used=$(( used_bytes / 1024 / 1024 ))
  mem_avail=$(( available_bytes / 1024 / 1024 ))
}

while true; do
  ts="$(iso_timestamp)"
  load1=""
  load5=""
  mem_used=""
  mem_avail=""

  case "$os_name" in
    Darwin)
      mac_metrics
      ;;
    Linux)
      linux_metrics
      ;;
    *)
      ;;
  esac

  fpm="$(curl -fsS --max-time 1 "$FPM_STATUS_URL" 2>/dev/null || true)"
  fpm_active="$(awk -F: '/^active processes:/ {gsub(/ /,"",$2);print $2}' <<<"$fpm")"
  fpm_idle="$(awk -F: '/^idle processes:/ {gsub(/ /,"",$2);print $2}' <<<"$fpm")"
  fpm_total="$(awk -F: '/^total processes:/ {gsub(/ /,"",$2);print $2}' <<<"$fpm")"

  echo "$ts,$load1,$load5,$mem_used,$mem_avail,$fpm_active,$fpm_idle,$fpm_total" >> "$OUTPUT"
  sleep "$INTERVAL"
done
