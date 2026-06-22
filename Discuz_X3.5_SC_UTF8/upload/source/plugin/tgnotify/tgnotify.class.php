<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

/**
 * Front-end hook class for the "tgnotify" plugin (module type 11).
 *
 * Discuz X3.5 exposes no plugin hook in the post-submit flow, so we piggyback on global_footer —
 * which fires on essentially every full page render — to run a throttled, lock-protected drain that
 * detects new threads/replies and pushes them to Telegram. The method returns nothing visible.
 *
 * See function_tgnotify.php (tgnotify_tick / tgnotify_drain) for the actual work.
 */
class plugin_tgnotify {
	public function global_footer() {
		require_once DISCUZ_ROOT.'./source/plugin/tgnotify/function_tgnotify.php';
		$cfg = tgnotify_config();
		if(empty($cfg['enabled'])) {
			return '';
		}
		tgnotify_tick($cfg);
		return '';
	}
}
