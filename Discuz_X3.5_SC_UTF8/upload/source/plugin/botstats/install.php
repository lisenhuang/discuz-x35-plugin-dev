<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

/**
 * 安装 / 重新导入（受支持的升级路径）。必须幂等：
 *  - 缺表则建表（按小时 + 分类 的访问次数，主键 (hourts, category)，不存 IP）；
 *  - 回填新增的默认设置（已配置的值优先）；
 *  - 表为空时自动灌入约 30 天模拟数据，方便首次安装即可预览图表（已有数据则不重复灌）。
 */
require_once DISCUZ_ROOT.'./source/plugin/botstats/function_botstats.php';

// 聚合表：图表读取的「每小时每分类访问次数」。
DB::query("CREATE TABLE IF NOT EXISTS ".DB::table('botstats_hourly')." (
  hourts   int(10) unsigned NOT NULL DEFAULT '0',
  category varchar(64)      NOT NULL DEFAULT '',
  hits     int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (hourts, category),
  KEY category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", array(), true);

// 回填默认设置（已有值优先）。
$cur = C::t('common_setting')->fetch_setting('botstats');
$cur = $cur ? (array)dunserialize($cur) : array();
C::t('common_setting')->update_setting('botstats', array_merge(botstats_defaults(), $cur));
if(!function_exists('updatecache')) {
	require_once DISCUZ_ROOT.'./source/function/function_cache.php';
}
updatecache('setting');

// 表为空 → 自动灌入示例数据（保证重新导入时不会重复累加）。
if(botstats_row_count() === 0) {
	botstats_insert_mock(30);
}

$finish = TRUE;
