<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

/**
 * 前端钩子类（模块类型 11）：在每个页面渲染时（global_footer）读取 Cloudflare 注入的
 * X-Verified-Bot-Category 请求头，按「当前整点小时 + 分类」统计独立 IP 数
 * （同一 IP 在同一小时内只计一次）。空头 = 非机器人（真人）。
 * 记录逻辑集中在 botstats_record()，便于与去重集逻辑共用。
 */
class plugin_botstats {

	public function global_footer() {
		require_once DISCUZ_ROOT.'./source/plugin/botstats/function_botstats.php';
		botstats_record();   // 内部按设置短路；任何失败均静默，绝不影响出页
		return '';
	}
}
