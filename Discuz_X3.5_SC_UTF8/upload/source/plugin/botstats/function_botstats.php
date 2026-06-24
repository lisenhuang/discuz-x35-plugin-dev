<?php
/**
 * Shared helpers for the "botstats" plugin — Cloudflare 验证机器人按小时流量统计.
 *
 * 思路：前端 global_footer 钩子在每个页面渲染时读取 Cloudflare 通过「请求头改写规则」注入的
 * X-Verified-Bot-Category 请求头，按「整点小时 + 分类」累加一行计数（不记录任何 IP）。空头视为
 * 非机器人（真人）。后台 admincp.inc.php 据此用 ECharts 画走线图 / 饼图。
 *
 * 统计口径：每小时、每分类的「访问次数」（请求计数）。对机器人而言，同一 IP 往往代理大量请求，
 * 因此按访问次数计而非按 IP 去重——不记录任何 IP。
 * 表结构（install.php 建表）：pre_botstats_hourly(hourts, category, hits)，主键 (hourts, category)。
 * hourts = 向下取整到整点的 UTC unix 秒（TIMESTAMP - TIMESTAMP%3600），便于范围查询，
 * 显示时用 dgmdate() 自动套用论坛时区。category 原样存储（向前兼容新分类），空值规范为 '__none__'。
 *
 * 被 botstats.class.php（钩子）与 admincp.inc.php（后台）共用。
 */
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

/** 设置默认值；存于 common_setting['botstats']（序列化），运行时从 $_G['setting']['botstats'] 读取。 */
function botstats_defaults() {
	return array(
		'enabled'       => 1,   // 总开关
		'exclude_admin' => 1,   // 排除已登录管理员自己的浏览，避免污染「真人」基线
		'require_cf'    => 0,    // 仅统计经 Cloudflare 的请求（存在 CF-Ray 头）；本地/无 CF 时关掉
	);
}

/** 读取设置（保存值覆盖默认值），按请求缓存。 */
function botstats_config() {
	static $cfg = null;
	if($cfg !== null) {
		return $cfg;
	}
	global $_G;
	$raw = isset($_G['setting']['botstats']) ? $_G['setting']['botstats'] : '';
	if(is_string($raw)) {
		$raw = dunserialize($raw);
	}
	if(!is_array($raw)) {
		$raw = array();
	}
	$cfg = array_merge(botstats_defaults(), $raw);
	return $cfg;
}

/**
 * 记录一次访问：按「当前整点小时 + 分类」的访问次数 +1（不记录 IP）。
 * 在 global_footer 钩子里调用，统计失败一律静默，绝不影响出页。
 */
function botstats_record() {
	$cfg = botstats_config();
	if(empty($cfg['enabled'])) { return; }
	global $_G;
	if(!empty($cfg['exclude_admin']) && !empty($_G['adminid'])) { return; }            // 排除管理员浏览
	if(!empty($cfg['require_cf']) && empty($_SERVER['HTTP_CF_RAY'])) { return; }        // 仅统计经 CF 的请求

	$cat = isset($_SERVER['HTTP_X_VERIFIED_BOT_CATEGORY']) ? trim($_SERVER['HTTP_X_VERIFIED_BOT_CATEGORY']) : '';
	if($cat === '') {
		$cat = '__none__';                     // 空头 = 非机器人（真人）
	} elseif(strlen($cat) > 64) {
		$cat = substr($cat, 0, 64);            // 收紧到列宽；分类不做白名单校验（向前兼容新分类）
	}
	$cat = addslashes($cat);

	$hourts = TIMESTAMP - TIMESTAMP % 3600;
	DB::query("INSERT INTO ".DB::table('botstats_hourly')." (hourts, category, hits)
		VALUES ('$hourts', '$cat', 1)
		ON DUPLICATE KEY UPDATE hits = hits + 1", array(), true);
}

/**
 * 17 个 Cloudflare 验证机器人分类（cf.verified_bot_category 的取值）+ 非机器人 的「中文名 + 颜色」映射。
 * 仅用于后台图例与配色（纯展示）；存储永远用请求头原始英文字符串，未知分类自动回退到原文 + 自动配色。
 * 来源：https://developers.cloudflare.com/bots/concepts/bot/verified-bots/#categories
 */
function botstats_category_meta() {
	return array(
		'Search Engine Crawler'      => array('搜索引擎爬虫',   '#5470c6'),
		'Search Engine Optimization' => array('SEO 分析',       '#3ba272'),
		'Monitoring & Analytics'     => array('监控与分析',     '#fac858'),
		'Advertising & Marketing'    => array('广告与营销',     '#ee6666'),
		'Page Preview'               => array('页面预览',       '#73c0de'),
		'Academic Research'          => array('学术研究',       '#9a60b4'),
		'Security'                   => array('安全扫描',       '#ea7ccc'),
		'Accessibility'              => array('无障碍检测',     '#3fb1e3'),
		'Webhooks'                   => array('Webhook 回调',   '#6e7079'),
		'Feed Fetcher'               => array('订阅抓取',       '#f4a259'),
		'AI Crawler'                 => array('AI 训练爬虫',    '#fc8452'),
		'AI Assistant'               => array('AI 助手',        '#dd6b66'),
		'AI Search'                  => array('AI 搜索',        '#759aa0'),
		'Aggregator'                 => array('聚合器',         '#91cc75'),
		'Archiver'                   => array('网页存档',       '#8d98b3'),
		'Social Media Marketing'     => array('社媒营销',       '#e062ae'),
		'Other'                      => array('其他机器人',     '#c1232b'),
		'__none__'                   => array('非机器人（真人）', '#bfbfbf'),
	);
}

/** 所有「机器人」分类（不含 __none__），用于生成模拟数据与默认勾选。 */
function botstats_bot_categories() {
	$cats = array_keys(botstats_category_meta());
	return array_values(array_diff($cats, array('__none__')));
}

/**
 * 查询 [$from, $to) 区间内的按桶聚合序列，供 ECharts 使用。
 * 跨度 ≤ 3 天 → 按小时桶；> 3 天 → 按天桶（用 dgmdate 'Ymd' 分组，天界按论坛时区）。
 * 返回：labels(桶标签), series{分类=>[每桶计数]}, totals{分类=>区间合计}, granularity, from, to。
 * labels 对齐到「连续桶序列」（空桶补 0），所以走线图是连续的。
 */
function botstats_query_series($from, $to) {
	$from = (int)$from;
	$to   = (int)$to;
	if($to <= $from) {
		$to = $from + 3600;
	}
	$gran = ($to - $from) <= 3 * 86400 ? 'hour' : 'day';

	// 1) 取区间内的原始小时行
	$rows = DB::fetch_all('SELECT hourts, category, hits FROM '.DB::table('botstats_hourly').
		' WHERE hourts >= %d AND hourts < %d', array($from, $to));

	// 2) 生成连续桶序列（key + 标签）
	$keys = array();
	$labels = array();
	if($gran === 'hour') {
		$start = $from - $from % 3600;
		for($t = $start; $t < $to; $t += 3600) {
			$keys[] = $t;
			$labels[] = dgmdate($t, 'Y-m-d H:00');
		}
	} else {
		$seen = array();
		$endk = (int)dgmdate($to - 1, 'Ymd');
		for($t = $from; $t <= $to; $t += 86400) {
			$k = (int)dgmdate($t, 'Ymd');
			if($k > $endk) { break; }
			if(!isset($seen[$k])) {
				$seen[$k] = count($keys);
				$keys[] = $k;
				$labels[] = dgmdate($t, 'Y-m-d');
			}
		}
	}
	$pos = array_flip($keys); // bucketKey => index

	// 3) 累加到各分类的桶数组
	$series = array();
	$totals = array();
	$n = count($keys);
	foreach($rows as $r) {
		$ts  = (int)$r['hourts'];
		$cat = (string)$r['category'];
		$key = $gran === 'hour' ? $ts : (int)dgmdate($ts, 'Ymd');
		if(!isset($pos[$key])) {
			continue; // 越界（理论上不会发生）
		}
		if(!isset($series[$cat])) {
			$series[$cat] = array_fill(0, $n, 0);
			$totals[$cat] = 0;
		}
		$series[$cat][$pos[$key]] += (int)$r['hits'];
		$totals[$cat] += (int)$r['hits'];
	}

	return array(
		'labels'      => $labels,
		'series'      => $series,
		'totals'      => $totals,
		'granularity' => $gran,
		'from'        => $from,
		'to'          => $to,
	);
}

/** 表内出现过的全部分类（用于后台分类多选，含未知分类）。 */
function botstats_distinct_categories() {
	$out = array();
	foreach(DB::fetch_all('SELECT DISTINCT category FROM '.DB::table('botstats_hourly')) as $r) {
		$out[] = $r['category'];
	}
	return $out;
}

/** 区间内（或全部）总记录数，用于「是否已有数据」判断。 */
function botstats_row_count() {
	return (int)DB::result_first('SELECT COUNT(*) FROM '.DB::table('botstats_hourly'));
}

/** 清空全部统计数据。 */
function botstats_clear() {
	DB::query('DELETE FROM '.DB::table('botstats_hourly'), array(), true);
}

/**
 * 生成单个 (分类, 小时) 的模拟「访问次数」。形状力求“像真的”：
 *  - 非机器人：量大且有昼夜节律；
 *  - 搜索引擎/订阅/监控：中等且较稳；
 *  - AI 三类：偏小但随时间上升（增长趋势）；
 *  - 其余长尾：稀疏（常为 0）。
 */
function botstats_mock_count($cat, $hourOfDay, $dayIndex, $days) {
	// 昼夜系数 0.4~1.0，正午最高
	$diurnal = 0.4 + 0.6 * pow(sin(M_PI * $hourOfDay / 24), 2);
	switch($cat) {
		case '__none__':
			return (int)round(mt_rand(150, 420) * $diurnal);
		case 'Search Engine Crawler':
			return (int)round(mt_rand(18, 55) * (0.6 + 0.4 * $diurnal));
		case 'Feed Fetcher':
		case 'Monitoring & Analytics':
			return (int)round(mt_rand(6, 28) * (0.7 + 0.3 * $diurnal));
		case 'AI Crawler':
		case 'AI Search':
		case 'AI Assistant':
			$trend = 0.25 + 0.75 * ($days > 0 ? $dayIndex / $days : 1); // 随天数增长
			return (int)round(mt_rand(2, 14) * $trend * (0.6 + 0.4 * $diurnal));
		case 'Search Engine Optimization':
		case 'Page Preview':
		case 'Aggregator':
		case 'Security':
		case 'Advertising & Marketing':
		case 'Social Media Marketing':
		case 'Archiver':
			return mt_rand(0, 1) ? mt_rand(0, 7) : 0;
		default: // Academic Research / Accessibility / Webhooks / Other 及未知
			return mt_rand(0, 4) === 0 ? mt_rand(1, 3) : 0;
	}
}

/**
 * 写入最近 $days 天的模拟「访问次数」（默认 30）。用同样的 upsert，因此可重复调用（按桶累加）。
 * 批量多值 INSERT，避免上万条单独查询。
 */
function botstats_insert_mock($days = 30) {
	$days = max(1, min(120, (int)$days));
	$now  = TIMESTAMP;
	$end  = $now - $now % 3600;
	$start = $end - $days * 86400;
	$cats = array_keys(botstats_category_meta()); // 含 __none__
	$table = DB::table('botstats_hourly');
	$values = array();
	for($t = $start; $t <= $end; $t += 3600) {
		$h = (int)dgmdate($t, 'G');           // 0-23（论坛时区）
		$dayIndex = (int)floor(($t - $start) / 86400);
		foreach($cats as $cat) {
			$c = botstats_mock_count($cat, $h, $dayIndex, $days);
			if($c > 0) {
				$values[] = "('".$t."','".addslashes($cat)."','".$c."')";
			}
		}
		if(count($values) >= 500) {
			DB::query("INSERT INTO $table (hourts, category, hits) VALUES ".implode(',', $values).
				" ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)", array(), true);
			$values = array();
		}
	}
	if($values) {
		DB::query("INSERT INTO $table (hourts, category, hits) VALUES ".implode(',', $values).
			" ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)", array(), true);
	}
}
