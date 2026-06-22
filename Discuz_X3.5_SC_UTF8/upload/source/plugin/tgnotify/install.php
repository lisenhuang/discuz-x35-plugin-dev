<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

/**
 * Install / re-import (the supported upgrade path). Must be idempotent:
 *  - create the single-row cursor/stats table if missing;
 *  - seed the cursor to the CURRENT max pid so existing history is never blasted on first enable;
 *  - backfill any NEW default settings while preserving everything already configured.
 */
require_once DISCUZ_ROOT.'./source/plugin/tgnotify/function_tgnotify.php';

DB::query("CREATE TABLE IF NOT EXISTS ".DB::table('tgnotify_state')." (
  id tinyint(1) unsigned NOT NULL DEFAULT '1',
  last_pid int(10) unsigned NOT NULL DEFAULT '0',
  last_scan int(10) unsigned NOT NULL DEFAULT '0',
  fail_pid int(10) unsigned NOT NULL DEFAULT '0',
  fail_count int(10) unsigned NOT NULL DEFAULT '0',
  sent int(10) unsigned NOT NULL DEFAULT '0',
  failed int(10) unsigned NOT NULL DEFAULT '0',
  lastsend int(10) unsigned NOT NULL DEFAULT '0',
  lasterror varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", array(), true);

// Seed the cursor row only if absent (don't reset it on re-import).
DB::query("INSERT IGNORE INTO ".DB::table('tgnotify_state')." (id, last_pid, last_scan) VALUES (1, %d, 0)",
	array(tgnotify_maxpid()), true);

// Backfill default settings (existing values win).
$defaults = array(
	'enabled'          => 0,
	'bot_token'        => '',
	'channel_id'       => '',
	'domain'           => '',
	'api_base'         => '',
	'send_retries'     => 3,
	'drain_interval'   => 3,
	'batch_size'       => 10,
	'retry_max'        => 3,
	'fids'             => array(),
	'send_thread'      => 1,
	'send_reply'       => 1,
	'max_readperm'     => 1,
	'disable_preview'  => 1,
	'rule_quote'       => 1,
	'rule_url'         => 1,
	'rule_at'          => 1,
	'rule_hide'        => 1,
	'rule_attach'      => 1,
	'rule_stripbbcode' => 1,
	'rule_collapse'    => 1,
	'truncate_length'  => 128,
	'custom_rules'     => '',
	'anon_name'        => '匿名',
	'btn_thread'       => '查看新贴',
	'btn_reply'        => '查看回复',
	'debug_log'        => 0,
);
$cur = C::t('common_setting')->fetch_setting('tgnotify');
$cur = $cur ? (array)dunserialize($cur) : array();
C::t('common_setting')->update_setting('tgnotify', array_merge($defaults, $cur));
if(!function_exists('updatecache')) {
	require_once DISCUZ_ROOT.'./source/function/function_cache.php';
}
updatecache('setting');

$finish = TRUE;
