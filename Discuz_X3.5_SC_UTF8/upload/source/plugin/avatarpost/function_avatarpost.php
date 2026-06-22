<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

/**
 * Shared settings helpers for the "avatarpost" plugin.
 *
 * Config is stored as a serialized array in common_setting['avatarpost']
 * (the same convention as the tgnotify / buycode plugins in this repo) and
 * read back through avatarpost_config(), which merges the saved values over
 * the defaults so a newly added key always has a sane fallback.
 */

/**
 * Factory defaults. Keep this the single source of truth for setting keys —
 * install.php, admincp.inc.php and the hook class all build on top of it.
 */
function avatarpost_defaults() {
	return array(
		// 总开关：1 启用 / 0 关闭
		'enabled'     => 1,
		// 管理员豁免：1 = 管理组用户（adminid>0）不受限制，避免管理员把自己锁在外面
		'exemptadmin' => 1,
		// 用户未设置头像时显示的提示文字（纯文本，可含简单 HTML）
		'message'     => '您还没有设置头像，请先上传头像后再发帖或回复。',
	);
}

/**
 * Read this plugin's config (saved values merged over defaults).
 * Cached per-request: the hook only runs on mod=post pages, so one read is plenty.
 */
function avatarpost_config() {
	static $cfg = null;
	if($cfg !== null) {
		return $cfg;
	}
	$raw = C::t('common_setting')->fetch_setting('avatarpost');
	$raw = $raw ? (array)dunserialize($raw) : array();
	$cfg = array_merge(avatarpost_defaults(), $raw);
	return $cfg;
}
