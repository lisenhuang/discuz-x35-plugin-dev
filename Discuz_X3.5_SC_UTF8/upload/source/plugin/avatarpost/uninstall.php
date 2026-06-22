<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

/**
 * Uninstall: remove this plugin's setting row. No custom tables to drop.
 */
DB::query("DELETE FROM ".DB::table('common_setting')." WHERE skey='avatarpost'", array(), true);

if(!function_exists('updatecache')) {
	require_once DISCUZ_ROOT.'./source/function/function_cache.php';
}
updatecache('setting');

$finish = TRUE;
