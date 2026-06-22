<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

/**
 * Install / re-import (the supported upgrade path). Idempotent: backfill any
 * new default settings while preserving everything already configured. No
 * custom tables — config lives in common_setting['avatarpost'].
 */
require_once DISCUZ_ROOT.'./source/plugin/avatarpost/function_avatarpost.php';

$cur = C::t('common_setting')->fetch_setting('avatarpost');
$cur = $cur ? (array)dunserialize($cur) : array();
C::t('common_setting')->update_setting('avatarpost', array_merge(avatarpost_defaults(), $cur));

if(!function_exists('updatecache')) {
	require_once DISCUZ_ROOT.'./source/function/function_cache.php';
}
updatecache('setting');

$finish = TRUE;
