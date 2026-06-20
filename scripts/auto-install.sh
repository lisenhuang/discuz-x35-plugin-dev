#!/usr/bin/env bash
# Drive the Discuz! X3.5 web installer automatically (standalone UCenter), so the
# one-time install needs no browser clicks. Run while the stack is up in installer mode.
set -euo pipefail
cd "$(dirname "$0")/.."

[ -f .env ] && . ./.env
PORT="${DZ_PORT:-34728}"
BASE="http://localhost:${PORT}/install/index.php"
MISC="http://localhost:${PORT}/misc.php"
JAR="$(mktemp)"
trap 'rm -f "$JAR"' EXIT

# DB + admin params (must match docker-compose.yml env)
DBHOST=db DBNAME=discuz DBUSER=discuz DBPW=discuz TABLEPRE=pre_
ADMIN_USER=admin ADMIN_PASS=admin888 ADMIN_EMAIL=admin@admin.com

echo "[auto-install] waiting for installer at $BASE ..."
for i in $(seq 1 60); do
  if curl -fsS -o /dev/null "http://localhost:${PORT}/install/" 2>/dev/null; then break; fi
  sleep 2
done

# Already installed?
if curl -fsS "http://localhost:${PORT}/forum.php" 2>/dev/null | grep -qiE 'discuz_uid|Powered by|寮哄埗|portal'; then
  if ! curl -fsS "http://localhost:${PORT}/install/" 2>/dev/null | grep -qi 'install'; then :; fi
fi

echo "[auto-install] step 1/5: db_init (writes config_global.php, returns allinfo)"
RESP="$(curl -fsS -c "$JAR" -b "$JAR" "$BASE" \
  --data-urlencode 'install_ucenter=standalone' \
  --data-urlencode 'step=3' \
  --data-urlencode 'method=db_init' \
  --data-urlencode "dbinfo[dbhost]=${DBHOST}" \
  --data-urlencode "dbinfo[dbname]=${DBNAME}" \
  --data-urlencode "dbinfo[dbuser]=${DBUSER}" \
  --data-urlencode "dbinfo[dbpw]=${DBPW}" \
  --data-urlencode "dbinfo[tablepre]=${TABLEPRE}" \
  --data-urlencode "dbinfo[adminemail]=${ADMIN_EMAIL}" \
  --data-urlencode "admininfo[username]=${ADMIN_USER}" \
  --data-urlencode "admininfo[password]=${ADMIN_PASS}" \
  --data-urlencode "admininfo[password2]=${ADMIN_PASS}" \
  --data-urlencode "admininfo[email]=${ADMIN_EMAIL}")"

ALLINFO="$(printf '%s' "$RESP" | grep -oE 'allinfo=[A-Za-z0-9%._-]+' | head -1 | sed 's/^allinfo=//')"
if [ -z "$ALLINFO" ]; then
  echo "[auto-install] ERROR: could not obtain allinfo from db_init response." >&2
  echo "----- response (head) -----" >&2
  printf '%s\n' "$RESP" | head -40 >&2
  exit 1
fi
echo "[auto-install] allinfo acquired."

echo "[auto-install] step 2/5: do_db_init (schema)"
curl -fsS -c "$JAR" -b "$JAR" "${BASE}?method=do_db_init&allinfo=${ALLINFO}" | tail -2 || true

echo "[auto-install] step 3/5: do_db_data_init (data + admin)"
curl -fsS -c "$JAR" -b "$JAR" "${BASE}?method=do_db_data_init&allinfo=${ALLINFO}" | tail -2 || true

echo "[auto-install] step 4/5: initsys (build caches)"
curl -fsS -c "$JAR" -b "$JAR" "${MISC}?mod=initsys" -o /dev/null || true

echo "[auto-install] step 5/5: ext_info (write install.lock)"
curl -fsS -c "$JAR" -b "$JAR" "${BASE}?method=ext_info" -o /dev/null || true

# Verify
if docker compose exec -T web test -f /var/www/html/data/install.lock \
   && docker compose exec -T web test -f /var/www/html/config/config_global.php; then
  echo "[auto-install] OK: install.lock + config_global.php present."
else
  echo "[auto-install] WARNING: lock/config not found; check installer output above." >&2
  exit 1
fi

echo "[auto-install] DONE. Forum: http://localhost:${PORT}/  admin: ${ADMIN_USER}/${ADMIN_PASS}"
