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

// Seed only ASCII settings into the DB. The Chinese prompt text is deliberately
// NOT persisted — it lives as a PHP literal in avatarpost_defaults() and is echoed
// straight to the page, so it can never be turned into "????" by a mis-configured
// database charset. We also drop any previously stored 'message' on every (re)import
// so an already-mangled value gets purged; an admin can re-enter a custom one later.
unset($cur['message']);
$seed = array('enabled' => 1, 'exemptadmin' => 1);
C::t('common_setting')->update_setting('avatarpost', array_merge($seed, $cur));

if(!function_exists('updatecache')) {
	require_once DISCUZ_ROOT.'./source/function/function_cache.php';
}
updatecache('setting');

$finish = TRUE;
