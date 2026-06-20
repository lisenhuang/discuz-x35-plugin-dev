#!/usr/bin/env bash
# Best-effort zero-click enable: register the plugin in the DB + run its install.php
# + rebuild caches, inside the running web container. If this is unreliable on your
# build, enable via Admin CP (Apps > Plugins > import the XML > Enable).
set -euo pipefail
cd "$(dirname "$0")/.."

ID="${1:-}"
[ -z "$ID" ] && { echo "usage: enable-plugin.sh <id>"; exit 1; }

if ! docker compose ps --status running web >/dev/null 2>&1; then
  echo "web container is not running. Start it with: make up"; exit 1
fi

docker compose cp scripts/import-plugin.php web:/tmp/import-plugin.php
docker compose exec -T web php /tmp/import-plugin.php "$ID"
echo "Done. Open the forum; the plugin should be enabled."
echo "If not, enable it in Admin CP > Apps > Plugins (import discuz_plugin_${ID}.xml > Enable)."
