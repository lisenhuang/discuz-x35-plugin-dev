<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

DB::query("DROP TABLE IF EXISTS ".DB::table('tgnotify_state'), array(), true);
DB::query("DELETE FROM ".DB::table('common_setting')." WHERE skey='tgnotify'", array(), true);
if(!function_exists('updatecache')) {
	require_once DISCUZ_ROOT.'./source/function/function_cache.php';
}
updatecache('setting');

$finish = TRUE;
