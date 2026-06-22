<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

require_once DISCUZ_ROOT.'./source/plugin/avatarpost/function_avatarpost.php';

/**
 * Front-end hook class for the "avatarpost" plugin (module type 11).
 *
 * The class name suffix "_forum" registers its methods under the *forum* hook
 * script (cache_setting.php), and a method whose first underscore-segment is
 * "post" is dispatched by runhooks() -> hookscript('post','forum',...) at
 * forum.php:63 — which runs BEFORE 'require .../forum_post.php', i.e. before the
 * thread/reply is ever written to the database. Calling showmessage() here
 * therefore halts the whole request and nothing is saved.
 *
 * Goal: a user who has not uploaded a custom avatar may not start a new thread
 * or post a reply.
 */
class plugin_avatarpost_forum {

	/**
	 * Fires for every forum.php?mod=post request (new-thread + reply, form view
	 * and submit). @param array $param  hook payload (unused).
	 */
	public function post_avatarcheck($param = array()) {
		global $_G;

		// Only gate brand-new threads and replies. Editing/deleting an existing
		// post (action=edit / delete / etc.) is intentionally left untouched.
		$action = getgpc('action');
		if($action !== 'newthread' && $action !== 'reply') {
			return;
		}

		// Guests have no avatarstatus key; Discuz's own login gate already stops
		// them from posting, so leave that path alone.
		if(empty($_G['uid'])) {
			return;
		}

		$cfg = avatarpost_config();
		if(empty($cfg['enabled'])) {
			return;
		}

		// Management groups (founder/admin/super-mod, adminid>0) bypass the gate
		// when enabled, so an admin without an avatar is never locked out.
		if(!empty($cfg['exemptadmin']) && !empty($_G['adminid'])) {
			return;
		}

		// avatarstatus == 1 only after a real custom avatar exists — this mirrors
		// core's own check (function_core.php checkperm). Non-empty => allowed.
		if(!empty($_G['member']['avatarstatus'])) {
			return;
		}

		// No avatar -> stop before forum_post.php runs. 5th arg (1) prints the
		// message verbatim (Simplified Chinese, no lang-key lookup), then dexit().
		showmessage($this->build_message($cfg), '', array(), array(), 1);
	}

	/**
	 * Build the blocking notice: the admin-configured text plus a prominent
	 * link to the avatar-upload page and a "go back" link, so the user always
	 * has a clear next step regardless of the showmessage template.
	 */
	private function build_message($cfg) {
		$text = trim((string)$cfg['message']);
		if($text === '') {
			$text = '您还没有设置头像，请先上传头像后再发帖或回复。';
		}
		return '<div style="line-height:1.9;font-size:14px">'
			. nl2br(htmlspecialchars($text, ENT_QUOTES))
			. '<div style="margin-top:16px">'
			. '<a href="home.php?mod=spacecp&amp;ac=avatar" style="display:inline-block;padding:8px 20px;margin-right:10px;background:#2b7fff;color:#fff;border-radius:6px;text-decoration:none">立即设置头像 &raquo;</a>'
			. '<a href="javascript:history.back()" style="display:inline-block;padding:8px 16px;color:#666;text-decoration:none">返回上一页</a>'
			. '</div></div>';
	}
}
