<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

// Drop our own order table and settings. NEVER touch common_invite — issued codes are core data
// and remain valid / recorded after uninstall.
DB::query("DROP TABLE IF EXISTS ".DB::table('buycode_order'), array(), true);
DB::query("DELETE FROM ".DB::table('common_setting')." WHERE skey='buycode'", array(), true);

if(!function_exists('updatecache')) {
	require_once DISCUZ_ROOT.'./source/function/function_cache.php';
}
updatecache('setting');

$finish = TRUE;
