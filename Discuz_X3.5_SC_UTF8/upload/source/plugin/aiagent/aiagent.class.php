<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

/**
 * Front-end hook class for the "aiagent" plugin (module type 11).
 *
 * This plugin is admin-only: the entire experience lives in the Admin CP (admincp.inc.php) and the
 * JSON agent endpoint (chat.inc.php). There is no front-end behaviour, so this hook is a no-op — it
 * exists only so the plugin enables cleanly. (The type-11 module is declared in the manifest.)
 */
class plugin_aiagent {
	public function global_footer() {
		return '';
	}
}
