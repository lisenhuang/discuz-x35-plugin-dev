#!/usr/bin/env bash
# Scaffold a Discuz! X3.5 plugin directly into the in-repo source tree:
#   Discuz_X3.5_SC_UTF8/upload/source/plugin/<id>/   (code + manifest)
#   Discuz_X3.5_SC_UTF8/upload/template/default/plugin/<id>/  (template)
# Usage: scripts/new-plugin.sh <id> ["Display Name"] ["Short description"]
set -euo pipefail
cd "$(dirname "$0")/.."

ID="${1:-}"
[ -z "$ID" ] && { echo "usage: new-plugin.sh <id> [\"Name\"] [\"Description\"]"; exit 1; }
case "$ID" in *[!a-z0-9_]*) echo "id must be lowercase letters/digits/underscore"; exit 1;; esac
NAME="${2:-$ID}"
DESC="${3:-A Discuz! X3.5 plugin scaffolded by new-plugin.sh.}"

SRC="Discuz_X3.5_SC_UTF8/upload/source/plugin/$ID"
TPL="Discuz_X3.5_SC_UTF8/upload/template/default/plugin/$ID"
[ -e "$SRC" ] && { echo "plugin already exists: $SRC"; exit 1; }
mkdir -p "$SRC" "$TPL"

cat > "$SRC/$ID.class.php" <<PHP
<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

/**
 * Front-end hook class for the "$ID" plugin (module type 11).
 * Public methods named after Discuz hooks run at those hook points.
 * See: admin CP > Tools > "View available hooks" for the full list.
 */
class plugin_$ID {
    // Appended to the bottom of every page (global_footer hook).
    public function global_footer() {
        return '<div style="text-align:center;padding:8px;color:#888;font-size:12px;">'
             . '[$NAME] plugin active'
             . '</div>';
    }
}
PHP

cat > "$SRC/admincp.inc.php" <<PHP
<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

/**
 * Admin settings module (module type 3) for "$ID".
 * Reached via: Admin CP > Apps > Plugins > $NAME > Settings.
 */
cpheader();
showtableheader('$NAME');
showtablerow('', array(), array('Edit this file (source/plugin/$ID/admincp.inc.php) to build your settings UI.'));
showtablefooter();
PHP

cat > "$SRC/install.php" <<PHP
<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }
// Runs once when the plugin is installed. Create custom tables here if needed.
\$finish = TRUE;
PHP

cat > "$SRC/uninstall.php" <<PHP
<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }
// Runs once when the plugin is uninstalled. Drop custom tables here if needed.
\$finish = TRUE;
PHP

cat > "$SRC/discuz_plugin_$ID.xml" <<XML
<?xml version="1.0" encoding="UTF-8"?>
<root>
	<item id="Title"><![CDATA[Discuz! Plugin]]></item>
	<item id="Data">
		<item id="plugin">
			<item id="available"><![CDATA[1]]></item>
			<item id="adminid"><![CDATA[1]]></item>
			<item id="name"><![CDATA[$NAME]]></item>
			<item id="identifier"><![CDATA[$ID]]></item>
			<item id="description"><![CDATA[$DESC]]></item>
			<item id="datatables"><![CDATA[]]></item>
			<item id="directory"><![CDATA[$ID/]]></item>
			<item id="copyright"><![CDATA[]]></item>
			<item id="version"><![CDATA[1.0]]></item>
			<item id="__modules">
				<item id="0">
					<item id="name"><![CDATA[$ID]]></item>
					<item id="menu"><![CDATA[]]></item>
					<item id="url"><![CDATA[]]></item>
					<item id="type"><![CDATA[11]]></item>
					<item id="adminid"><![CDATA[0]]></item>
					<item id="displayorder"><![CDATA[0]]></item>
					<item id="param"><![CDATA[]]></item>
				</item>
				<item id="1">
					<item id="name"><![CDATA[admincp]]></item>
					<item id="menu"><![CDATA[Settings]]></item>
					<item id="url"><![CDATA[]]></item>
					<item id="type"><![CDATA[3]]></item>
					<item id="adminid"><![CDATA[1]]></item>
					<item id="displayorder"><![CDATA[0]]></item>
					<item id="param"><![CDATA[]]></item>
				</item>
			</item>
		</item>
		<item id="version"><![CDATA[X3.5]]></item>
		<item id="installfile"><![CDATA[install.php]]></item>
		<item id="uninstallfile"><![CDATA[uninstall.php]]></item>
	</item>
</root>
XML

printf '' > "$TPL/index.htm"

mkdir -p docs/plugins
cat > "docs/plugins/$ID.md" <<MD
# $NAME (\`$ID\`)

$DESC

| | |
| --- | --- |
| 🆔 Identifier | \`$ID\` |
| 🧰 Requires | Discuz! X3.5 |
| 📁 Files | \`source/plugin/$ID/\` |

## 📥 Install on your own Discuz! forum

1. Copy the plugin folder into your forum, keeping the same path:
   \`\`\`
   source/plugin/$ID/   →   <your-forum>/source/plugin/$ID/
   \`\`\`
   (also copy \`template/default/plugin/$ID/\` if this plugin ships templates)
2. **Admin CP (管理中心) → 应用 Apps → 插件 Plugins** → find \`$ID\` → **安装 Install → 启用 Enable**.
   *(Alternative: 导入 Import \`discuz_plugin_$ID.xml\`.)*

## ▶️ Use

TODO: describe what users see and how to use **$NAME**.

## 🗑️ Uninstall

Admin CP → 应用 → 插件 → **卸载 Uninstall**, then delete \`source/plugin/$ID/\`.

---
<sub>Developing in this repo? Shortcut install: \`make enable-plugin id=$ID\`.</sub>
MD

echo "Scaffolded plugin '$ID':"
echo "  code: $SRC"
echo "  tpl:  $TPL"
echo "  docs: docs/plugins/$ID.md   (fill in the Use section)"
echo "Next: make enable-plugin id=$ID   (then 'make list-plugins' to update README)"
