#!/bin/sh
# Discuz web container entrypoint:
#  - ensure writable runtime dirs exist (ephemeral, in-container)
#  - wait for the database
#  - if a seed config exists (mounted at /seed), inject it -> turnkey (no installer)
set -e
ROOT=/var/www/html
DB_HOST="${DZ_DB_HOST:-db}"

# Writable dirs Discuz/UCenter need at runtime (these stay in the container = ephemeral)
for d in data data/cache data/template data/threadcache data/attachment data/avatar \
         data/plugindata data/log data/addonmd5 data/download config \
         uc_client/data uc_client/data/cache uc_server/data uc_server/data/cache \
         uc_server/data/avatar uc_server/data/backup uc_server/data/logs uc_server/data/tmp; do
  mkdir -p "$ROOT/$d"
done
chown -R www-data:www-data "$ROOT/data" "$ROOT/config" "$ROOT/uc_client/data" "$ROOT/uc_server/data" 2>/dev/null || true
chmod -R 0777 "$ROOT/data" "$ROOT/config" "$ROOT/uc_client/data" "$ROOT/uc_server/data" 2>/dev/null || true

# Wait for the DB to accept connections (compose healthcheck already gates this; short & non-fatal).
# Note: the MariaDB client defaults to requiring TLS, so we pass --skip-ssl.
echo "[entrypoint] waiting for db at $DB_HOST ..."
i=0; while [ "$i" -lt 30 ]; do
  if mysqladmin ping -h"$DB_HOST" -uroot -proot --skip-ssl --silent >/dev/null 2>&1; then echo "[entrypoint] db is up"; break; fi
  i=$((i+1)); sleep 1
done

# Turnkey vs installer mode
if [ -f /seed/config/config_global.php ]; then
  cp -f /seed/config/config_global.php "$ROOT/config/config_global.php"
  cp -f /seed/config/config_ucenter.php "$ROOT/config/config_ucenter.php"
  [ -f /seed/config/uc_server_config.inc.php ] && cp -f /seed/config/uc_server_config.inc.php "$ROOT/uc_server/data/config.inc.php"
  if [ -f /seed/config/install.lock ]; then cp -f /seed/config/install.lock "$ROOT/data/install.lock"; else : > "$ROOT/data/install.lock"; fi
  chown -R www-data:www-data "$ROOT/config" "$ROOT/uc_server/data" 2>/dev/null || true
  echo "[entrypoint] seed config injected -> TURNKEY (installer skipped)"
  # data/ is ephemeral, so rebuild the style/CSS cache now (Discuz won't regenerate
  # data/cache/style_*.css on a normal request -> pages would load unstyled otherwise).
  php /usr/local/bin/build-cache.php 2>/dev/null && echo "[entrypoint] CSS/style cache rebuilt" || echo "[entrypoint] build-cache skipped/failed (non-fatal)"
  chown -R www-data:www-data "$ROOT/data" 2>/dev/null || true
else
  echo "[entrypoint] no seed config -> INSTALLER MODE (open /install/ in the browser)"
fi

exec "$@"
