#!/usr/bin/env sh
set -eu
DATE="${1:-$(date +%F)}"
FILE="writable/logs/tpms-performance-${DATE}.jsonl"
if [ ! -f "$FILE" ]; then
  echo "Performance log not found: $FILE" >&2
  exit 1
fi
tail -f "$FILE"
