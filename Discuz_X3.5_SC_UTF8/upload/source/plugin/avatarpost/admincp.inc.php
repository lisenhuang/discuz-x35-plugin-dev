<?php
if(!defined('IN_DISCUZ') || !defined('IN_ADMINCP')) { exit('Access Denied'); }

/**
 * Settings (module type 3) for the avatarpost plugin.
 * Admin CP > 应用 Apps > 插件 Plugins > 发帖头像限制 > 设置 Settings.
 *
 * Three fields stored in common_setting['avatarpost']:
 *   enabled     总开关
 *   exemptadmin 管理组豁免
 *   message     未设置头像时的提示文字
 */
require_once DISCUZ_ROOT.'./source/plugin/avatarpost/function_avatarpost.php';

$selfurl = 'plugins&operation=config&do='.$pluginid.'&identifier=avatarpost&pmod=admincp';

// ---- save -------------------------------------------------------------------
if(submitcheck('apsubmit')) {
	$raw = (array)dunserialize(C::t('common_setting')->fetch_setting('avatarpost'));
	$raw['enabled']     = intval(getgpc('enabled'));
	$raw['exemptadmin'] = intval(getgpc('exemptadmin'));
	$msg = trim((string)getgpc('message'));
	$def = avatarpost_defaults();
	// Only store a *custom* message. Empty, or left at the built-in default, means
	// "use the default" — so nothing Chinese is written to the DB and the prompt is
	// safe even on a site whose database charset would otherwise mangle it.
	if($msg === '' || $msg === $def['message']) {
		unset($raw['message']);
	} else {
		$raw['message'] = $msg;
	}
	C::t('common_setting')->update_setting('avatarpost', $raw);
	updatecache('setting');
	cpmsg('设置已保存。 / Settings saved.', 'action='.$selfurl, 'succeed');
}

$cfg = avatarpost_config();
$onoff = function($name, $val) {
	return '<select name="'.$name.'" class="ps">'
		.'<option value="1"'.($val ? ' selected' : '').'>开启 On</option>'
		.'<option value="0"'.(!$val ? ' selected' : '').'>关闭 Off</option></select>';
};

showtableheader('发帖头像限制 —— 未设置头像的用户禁止发帖、回复');
showformheader($selfurl, 'apsubmit');
showtablerow('', '', '<b>总开关</b><br /><span style="color:#888">启用后，未上传头像的会员将无法发布主题或回复。</span> &nbsp; '.$onoff('enabled', $cfg['enabled']));
showtablerow('', '', '<b>管理组豁免</b><br /><span style="color:#888">开启时，管理员 / 版主等管理组成员不受限制（推荐，避免管理员被锁在外面）。</span> &nbsp; '.$onoff('exemptadmin', $cfg['exemptadmin']));
showtablerow('', '', '<b>提示文字</b><br /><span style="color:#888">会员未设置头像、尝试发帖时显示的提示（系统会自动在下方附上「立即设置头像」按钮）。留空则使用内置默认文字（不写入数据库，避免编码问题）。</span><br />'
	.'<textarea name="message" rows="3" style="width:96%;margin-top:6px" class="px">'.htmlspecialchars((string)$cfg['message'], ENT_QUOTES).'</textarea>');
showsubmit('apsubmit', '保存 / Save');
showtablefooter();
showformfooter();
