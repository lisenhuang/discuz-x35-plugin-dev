<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

// 卸载：删本插件的表与设置，不触碰任何核心数据。
DB::query("DROP TABLE IF EXISTS ".DB::table('botstats_hourly'), array(), true);
DB::query("DELETE FROM ".DB::table('common_setting')." WHERE skey='botstats'", array(), true);
if(!function_exists('updatecache')) {
	require_once DISCUZ_ROOT.'./source/function/function_cache.php';
}
updatecache('setting');

$finish = TRUE;
