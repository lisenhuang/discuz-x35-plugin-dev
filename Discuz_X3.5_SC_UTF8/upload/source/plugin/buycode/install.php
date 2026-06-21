<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

/**
 * Order table: maps a Stripe Checkout Session to the invite codes it issued. The codes
 * themselves live in the core common_invite table (no schema change there).
 */
DB::query("CREATE TABLE IF NOT EXISTS ".DB::table('buycode_order')." (
  orderid int unsigned NOT NULL AUTO_INCREMENT,
  sessionid char(80) NOT NULL DEFAULT '',
  uid mediumint(8) unsigned NOT NULL DEFAULT '0',
  email varchar(255) NOT NULL DEFAULT '',
  quantity smallint(6) unsigned NOT NULL DEFAULT '1',
  amount int(10) unsigned NOT NULL DEFAULT '0',
  currency char(10) NOT NULL DEFAULT '',
  codes text NOT NULL,
  mode char(4) NOT NULL DEFAULT 'test',
  status tinyint(1) NOT NULL DEFAULT '0',
  dateline int(10) unsigned NOT NULL DEFAULT '0',
  paydateline int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (orderid),
  UNIQUE KEY sessionid (sessionid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", array(), true);

// Seed/backfill settings. This runs on install AND on every re-import (the supported upgrade path),
// so it must be idempotent: fresh install -> create the config; upgrade -> backfill any NEW default
// keys while PRESERVING everything already configured (existing values win via array_merge). An
// existing install therefore never loses its keys / price / webhooks when you upgrade the plugin.
$defaults = array(
	'test_enabled'        => 0,
	'test_secret_key'     => '',
	'test_webhook_secret' => '',
	'test_webhook_id'     => '',
	'test_base'           => '',
	'live_enabled'        => 0,
	'live_secret_key'     => '',
	'live_webhook_secret' => '',
	'live_webhook_id'     => '',
	'live_base'           => '',
	'unit_amount'         => 500,
	'currency'            => 'usd',
	'product_label'       => '论坛邀请码',
	'max_qty'             => 10,
	'code_length'         => 6,
	'expiry_days'         => 0,
	'redirect_url'        => 'member.php?mod=register',
	'contact_email'       => '',
);
$cur = C::t('common_setting')->fetch_setting('buycode');
$cur = $cur ? (array)dunserialize($cur) : array();
C::t('common_setting')->update_setting('buycode', array_merge($defaults, $cur));
if(!function_exists('updatecache')) {
	require_once DISCUZ_ROOT.'./source/function/function_cache.php';
}
updatecache('setting');

$finish = TRUE;
