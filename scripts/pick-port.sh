#!/usr/bin/env bash
# Pick a free host port for the web container and write DZ_PORT=<port> to .env.
# Default base 34728; if taken, try +1 until free. Reuses an existing .env value.
set -euo pipefail
cd "$(dirname "$0")/.."

BASE="${DZ_PORT_BASE:-34728}"
MAX=$((BASE + 200))

is_free() {
  local p="$1"
  if command -v lsof >/dev/null 2>&1; then
    ! lsof -nP -iTCP:"$p" -sTCP:LISTEN >/dev/null 2>&1
  elif command -v nc >/dev/null 2>&1; then
    ! nc -z localhost "$p" >/dev/null 2>&1
  else
    return 0
  fi
}

# Keep an already-chosen port (so a running stack stays on its port).
if [ -f .env ] && grep -qE '^DZ_PORT=[0-9]+' .env; then
  echo "$(grep -E '^DZ_PORT=' .env) (kept; delete .env to re-pick)"
  exit 0
fi

port="$BASE"
while [ "$port" -le "$MAX" ]; do
  if is_free "$port"; then
    printf 'DZ_PORT=%s\n' "$port" > .env
    echo "DZ_PORT=$port"
    exit 0
  fi
  port=$((port + 1))
done
echo "No free port found in ${BASE}-${MAX}" >&2
exit 1
