<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

/**
 * Audit log: one row per database action the AI assistant performed (read, write, or a blocked
 * attempt). Lets the founder review exactly what the assistant did and undo if needed.
 */
DB::query("CREATE TABLE IF NOT EXISTS ".DB::table('aiagent_log')." (
  logid int unsigned NOT NULL AUTO_INCREMENT,
  uid mediumint(8) unsigned NOT NULL DEFAULT '0',
  username varchar(64) NOT NULL DEFAULT '',
  action char(16) NOT NULL DEFAULT '',
  sql_text mediumtext NOT NULL,
  rowcount int(10) unsigned NOT NULL DEFAULT '0',
  affected int(10) unsigned NOT NULL DEFAULT '0',
  status tinyint(1) NOT NULL DEFAULT '0',
  error varchar(255) NOT NULL DEFAULT '',
  dateline int(10) unsigned NOT NULL DEFAULT '0',
  ip varchar(46) NOT NULL DEFAULT '',
  PRIMARY KEY (logid),
  KEY dateline (dateline)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", array(), true);

// Seed/backfill settings. Idempotent (runs on install AND every re-import): array_merge keeps any
// values already configured (existing wins) while adding any new default keys.
$defaults = array(
	'enabled'          => 1,
	'api_key'          => '',
	'model'            => 'meta-llama/llama-3.3-70b-instruct:free',
	'base_url'         => 'https://openrouter.ai/api/v1',
	'write_mode'       => 'off',
	'max_rows'         => 50,
	'max_result_bytes' => 12000,
	'max_iters'        => 6,
	'http_timeout'     => 45,
);
$cur = C::t('common_setting')->fetch_setting('aiagent');
$cur = $cur ? (array)dunserialize($cur) : array();
C::t('common_setting')->update_setting('aiagent', array_merge($defaults, $cur));
if(!function_exists('updatecache')) {
	require_once DISCUZ_ROOT.'./source/function/function_cache.php';
}
updatecache('setting');

$finish = TRUE;
