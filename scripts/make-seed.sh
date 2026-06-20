#!/usr/bin/env bash
# Snapshot the running install into ./seed so every future boot is turnkey:
#   - dump the DB (Discuz + UCenter tables) -> seed/db/01-discuz.sql
#   - export config files + install.lock      -> seed/config/
# Neutralizes host-specific siteurl so the seed works on any port.
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p seed/db seed/config

echo "[seed] neutralizing siteurl (auto-detected at runtime)..."
docker compose exec -T db sh -lc \
  'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" discuz -e "UPDATE pre_common_setting SET svalue=\"\" WHERE skey=\"siteurl\";"' || true

echo "[seed] dumping database -> seed/db/01-discuz.sql"
docker compose exec -T db sh -lc \
  'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --databases discuz --add-drop-table --single-transaction --no-tablespaces --default-character-set=utf8mb4' \
  > seed/db/01-discuz.sql
rm -f seed/db/.gitkeep

echo "[seed] exporting config + install.lock -> seed/config/"
docker compose exec -T web cat /var/www/html/config/config_global.php  > seed/config/config_global.php
docker compose exec -T web cat /var/www/html/config/config_ucenter.php > seed/config/config_ucenter.php
docker compose exec -T web sh -lc 'cat /var/www/html/uc_server/data/config.inc.php 2>/dev/null || true' > seed/config/uc_server_config.inc.php
docker compose exec -T web sh -lc 'cat /var/www/html/data/install.lock 2>/dev/null || echo installed' > seed/config/install.lock
rm -f seed/config/.gitkeep

LINES=$(wc -l < seed/db/01-discuz.sql | tr -d ' ')
echo "[seed] done. dump=${LINES} lines, config files exported."
echo "[seed] commit ./seed to make this the permanent turnkey baseline."
