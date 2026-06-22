<?php
if(!defined('IN_DISCUZ') || !defined('IN_ADMINCP')) { exit('Access Denied'); }

/**
 * Settings (module type 3) for the tgnotify plugin, organized into tabs (?tgtab=):
 *   conn   — 连接 Connection: master switch, bot token, channel id, domain, cadence, test send
 *   forums — 板块 Forums:     checkbox tree of every forum (default none) + read-permission ceiling
 *   rules  — 消息规则 Rules:   per-transform toggles, truncate length, custom regex rules, presentation
 *   status — 状态 Status:     cursor, last send, success/fail counters, last error
 * Each tab saves only its own fields (merged onto common_setting['tgnotify']).
 */
require_once DISCUZ_ROOT.'./source/plugin/tgnotify/function_tgnotify.php';

$selfurl = 'plugins&operation=config&do='.$pluginid.'&identifier=tgnotify&pmod=admincp';
$cpu = function($t) use ($selfurl) { return 'action='.$selfurl.'&tgtab='.$t; };
$e   = function($v) { return htmlspecialchars((string)$v, ENT_QUOTES); };

// ---- save: CONNECTION ---------------------------------------------------------
if(submitcheck('tg_conn')) {
	$raw = (array)dunserialize(C::t('common_setting')->fetch_setting('tgnotify'));
	$raw['enabled']         = intval(getgpc('enabled'));
	$raw['bot_token']       = trim((string)getgpc('bot_token'));
	$raw['channel_id']      = trim((string)getgpc('channel_id'));
	$raw['domain']          = trim((string)getgpc('domain'));
	$raw['api_base']        = trim((string)getgpc('api_base'));
	$raw['send_retries']    = max(1, min(6, intval(getgpc('send_retries'))));
	$raw['drain_interval']  = max(1, min(3600, intval(getgpc('drain_interval'))));
	$raw['batch_size']      = max(1, min(50, intval(getgpc('batch_size'))));
	$raw['send_thread']     = intval(getgpc('send_thread'));
	$raw['send_reply']      = intval(getgpc('send_reply'));
	$raw['disable_preview'] = intval(getgpc('disable_preview'));
	$raw['debug_log']       = intval(getgpc('debug_log'));
	C::t('common_setting')->update_setting('tgnotify', $raw);
	updatecache('setting');
	cpmsg('连接设置已保存 / Connection settings saved.', $cpu('conn'), 'succeed');
}

// ---- send a test message ------------------------------------------------------
if(submitcheck('tg_test')) {
	$cfg = tgnotify_config();
	if(trim($cfg['bot_token']) === '' || trim($cfg['channel_id']) === '') {
		cpmsg('请先填写并保存 Bot Token 与频道 ID / Save the bot token and channel id first.', $cpu('conn'), 'error');
	}
	$sample = array(
		'first' => 1, 'tid' => 1, 'pid' => 1, 'anonymous' => 0,
		'subject' => '测试消息 / Test from tgnotify',
		'author'  => $_G['username'],
		'message' => '这是一条来自 tgnotify 的测试消息。 A test message. [url=https://example.com]链接[/url] @everyone [hide]secret[/hide]',
	);
	$res = tgnotify_send($cfg, tgnotify_build($cfg, $sample, tgnotify_base_url($cfg)));
	if($res['ok']) {
		cpmsg('✅ 测试消息已发送 / Test message sent: '.$e($res['desc']), $cpu('conn'), 'succeed');
	} else {
		cpmsg('❌ 发送失败 / Send failed: '.$e($res['desc']), $cpu('conn'), 'error');
	}
}

// ---- save: FORUMS + read-permission ceiling -----------------------------------
if(submitcheck('tg_forums')) {
	$fids = getgpc('fids');
	$fids = is_array($fids) ? array_values(array_unique(array_filter(array_map('intval', $fids)))) : array();
	$raw = (array)dunserialize(C::t('common_setting')->fetch_setting('tgnotify'));
	$raw['fids']         = $fids;
	$raw['max_readperm'] = max(0, min(255, intval(getgpc('max_readperm'))));
	C::t('common_setting')->update_setting('tgnotify', $raw);
	updatecache('setting');
	cpmsg('板块与权限设置已保存（已选 '.count($fids).' 个板块）/ Forum &amp; permission settings saved.', $cpu('forums'), 'succeed');
}

// ---- save: MESSAGE RULES ------------------------------------------------------
if(submitcheck('tg_rules')) {
	$raw = (array)dunserialize(C::t('common_setting')->fetch_setting('tgnotify'));
	foreach(array('rule_quote','rule_url','rule_at','rule_hide','rule_attach','rule_stripbbcode','rule_collapse') as $k) {
		$raw[$k] = intval(getgpc($k));
	}
	$raw['truncate_length'] = max(0, min(4000, intval(getgpc('truncate_length'))));
	$raw['custom_rules']    = (string)getgpc('custom_rules');
	$raw['anon_name']       = trim((string)getgpc('anon_name'));
	$raw['btn_thread']      = trim((string)getgpc('btn_thread'));
	$raw['btn_reply']       = trim((string)getgpc('btn_reply'));
	C::t('common_setting')->update_setting('tgnotify', $raw);
	updatecache('setting');
	cpmsg('消息规则已保存 / Message rules saved.', $cpu('rules'), 'succeed');
}

$cfg = tgnotify_config();
$onoff = function($name, $val) {
	return '<select name="'.$name.'" style="font-size:14px;padding:5px 8px;border-radius:6px">'
		.'<option value="1"'.($val ? ' selected' : '').'>✅ 开启 On</option>'
		.'<option value="0"'.(!$val ? ' selected' : '').'>⛔ 关闭 Off</option></select>';
};

$tab = (string)getgpc('tgtab');
if(!in_array($tab, array('conn', 'forums', 'rules', 'status'), true)) {
	$tab = 'conn';
}

// ---- tab bar ------------------------------------------------------------------
$tablabels = array('conn' => '🔌 连接 Connection', 'forums' => '🗂 板块 Forums', 'rules' => '✂️ 消息规则 Rules', 'status' => '📊 状态 Status');
$nav = '<div style="margin:12px 0 0;border-bottom:2px solid #d8dce1">';
foreach($tablabels as $k => $label) {
	$on = ($k === $tab);
	$nav .= '<a href="'.ADMINSCRIPT.'?action='.$e($selfurl).'&tgtab='.$k.'" style="display:inline-block;padding:8px 18px;margin-right:4px;text-decoration:none;border:1px solid #d8dce1;border-bottom:none;border-radius:7px 7px 0 0;'
		.($on ? 'background:#fff;font-weight:700;color:#222;position:relative;top:2px;' : 'background:#eef1f4;color:#666;').'">'.$label.'</a>';
}
$nav .= '</div>';
echo $nav;

// banner: not enabled / not configured
$ready = $cfg['enabled'] && trim($cfg['bot_token']) !== '' && trim($cfg['channel_id']) !== '' && !empty($cfg['fids']);
if(!$ready) {
	$missing = array();
	if(empty($cfg['enabled']))             { $missing[] = '总开关未开启 (master switch off)'; }
	if(trim($cfg['bot_token']) === '')     { $missing[] = '缺少 Bot Token'; }
	if(trim($cfg['channel_id']) === '')    { $missing[] = '缺少频道 ID (channel id)'; }
	if(empty($cfg['fids']))                { $missing[] = '未选择任何板块 (no forums selected)'; }
	echo '<div style="background:#fff7e6;border:2px solid #f0a500;border-radius:10px;padding:12px 16px;margin:14px 0;color:#7a4f00;line-height:1.7">'
		.'⚠️ <b>插件尚未生效 / Not active yet</b> —— '.$e(implode('；', $missing)).'。补齐后即可开始推送。</div>';
} else {
	echo '<div style="background:#eafaf0;border:2px solid #2bb673;border-radius:10px;padding:12px 16px;margin:14px 0;color:#0a6b3b;line-height:1.7">'
		.'✅ <b>运行中 / Active</b> —— 已选 '.count($cfg['fids']).' 个板块，每 '.$e($cfg['drain_interval']).' 秒检查一次新内容并推送到 Telegram。</div>';
}

// ===============================================================================
if($tab === 'conn') {
	$autobase = tgnotify_base_url($cfg);

	showtableheader('🔌 连接设置 / Connection');
	showformheader($selfurl, '', 'tgconn');
	showtablerow('', '', '总开关 / Master switch：'.$onoff('enabled', $cfg['enabled']).' <span class="smalltxt">关闭后完全不推送。/ Off = nothing is pushed.</span>');
	showtablerow('', '', 'Bot Token <span class="smalltxt">(从 @BotFather 获取 / from @BotFather，形如 <code>123456:ABC-DEF...</code>)</span>：<br /><input type="text" name="bot_token" value="'.$e($cfg['bot_token']).'" class="txt" style="width:460px" autocomplete="off" />');
	showtablerow('', '', '频道 ID / Channel ID <span class="smalltxt">(<code>-100xxxxxxxxxx</code> 或 <code>@channelusername</code>；先把机器人设为频道管理员 / make the bot a channel admin)</span>：<br /><input type="text" name="channel_id" value="'.$e($cfg['channel_id']).'" class="txt" style="width:340px" />');
	showtablerow('', '', '站点域名 / Base domain <span class="smalltxt">(用于生成「查看新贴/回复」按钮链接；留空自动使用本站 URL / blank = auto)</span>：<br /><input type="text" name="domain" value="'.$e($cfg['domain']).'" class="txt" style="width:340px" placeholder="'.$e($autobase).'" /><br /><span class="smalltxt">当前生效 / Effective now：<code>'.$e($autobase).'</code></span>');
	showtablerow('', '', '推送内容 / Push：新主题 New threads '.$onoff('send_thread', $cfg['send_thread']).' &nbsp;&nbsp; 新回复 New replies '.$onoff('send_reply', $cfg['send_reply']));
	showtablerow('', '', '关闭链接预览 / Disable link preview：'.$onoff('disable_preview', $cfg['disable_preview']));
	showtablerow('', '', '检查间隔(秒) / Scan interval：<input type="text" name="drain_interval" value="'.$e($cfg['drain_interval']).'" class="txt" style="width:80px" /> <span class="smalltxt">越小越实时，越大越省资源（建议 3–10）。/ smaller = more real-time.</span>');
	showtablerow('', '', '每次最多处理 / Batch size：<input type="text" name="batch_size" value="'.$e($cfg['batch_size']).'" class="txt" style="width:80px" /> &nbsp; 发送重试次数 / Send retries：<input type="text" name="send_retries" value="'.$e($cfg['send_retries']).'" class="txt" style="width:60px" /> <span class="smalltxt">网络抖动时自动重试 (1–6)。/ retried on transient network drops.</span>');
	showtablerow('', '', '<b>🌐 网络 / Network</b> <span class="smalltxt">—— 若服务器无法直连 Telegram（如中国大陆被墙），可用下面的 API 地址覆盖：/ if the server can\'t reach Telegram directly (e.g. blocked in mainland China), use the API-base override below:</span>');
	showtablerow('', '', 'API 地址覆盖 / API base <span class="smalltxt">(可选 optional)</span>：<input type="text" name="api_base" value="'.$e($cfg['api_base']).'" class="txt" style="width:340px" placeholder="https://api.telegram.org" /> <span class="smalltxt">留空用官方地址；可填能访问到的反代/镜像。/ blank = official; or a reachable reverse-proxy mirror.</span>');
	showtablerow('', '', '调试模式 / Debug mode：'.$onoff('debug_log', $cfg['debug_log']).' <span class="smalltxt">开启后<b>不真正发送</b>，只把内容写入 <code>data/log/tgnotify.log</code>，便于排查。/ logs payload instead of sending.</span>');
	showsubmit('tg_conn', '保存 / Save');
	showtablefooter();
	showformfooter();

	showtableheader('📨 发送测试消息 / Send a test message');
	showformheader($selfurl, '', 'tgtest');
	showtablerow('', '', '使用<b>当前已保存</b>的 Token 与频道，向频道发送一条示例消息以验证配置。/ Sends a sample to the saved channel using the saved token.');
	showsubmit('tg_test', '发送测试 / Send test');
	showtablefooter();
	showformfooter();

	showtableheader('❓ 快速上手 / Quick start');
	showtablerow('', '', '1. 与 <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a> 对话创建机器人，拿到 <b>Bot Token</b>。');
	showtablerow('', '', '2. 把机器人加入你的频道并设为<b>管理员</b>（需有发消息权限）。');
	showtablerow('', '', '3. 获取频道数字 ID：把任意频道消息转发给 <a href="https://t.me/userinfobot" target="_blank" rel="noopener">@userinfobot</a>，或用 <code>getUpdates</code>；公开频道也可直接用 <code>@用户名</code>。');
	showtablerow('', '', '4. 在「板块 Forums」选择要推送的板块，回到这里打开<b>总开关</b>并保存，最后点上面的<b>发送测试</b>。');
	showtablefooter();

// ===============================================================================
} elseif($tab === 'forums') {
	showtableheader('🗂 选择要推送的板块 / Forums to push（默认全部不选 / default none）');
	showformheader($selfurl, '', 'tgforums');

	$all = DB::fetch_all('SELECT fid, name, type, fup, status FROM '.DB::table('forum_forum').' ORDER BY displayorder, fid');
	$groups = $forums = $subs = array();
	foreach($all as $f) {
		if($f['type'] === 'group')      { $groups[$f['fid']] = $f; }
		elseif($f['type'] === 'sub')    { $subs[$f['fup']][] = $f; }
		else                            { $forums[$f['fup']][] = $f; }
	}
	$selected = array_flip(array_map('intval', (array)$cfg['fids']));

	$render_cb = function($f, $pad) use ($e, $selected) {
		$fid = intval($f['fid']);
		$dis = ($f['status'] != 1) ? ' <span style="color:#c33">（已关闭 closed）</span>' : '';
		return '<div style="padding:3px 0 3px '.$pad.'px"><label style="cursor:pointer">'
			.'<input type="checkbox" name="fids[]" value="'.$fid.'" '.(isset($selected[$fid]) ? 'checked' : '').' /> '
			.$e($f['name']).' <span class="smalltxt">#'.$fid.'</span>'.$dis.'</label></div>';
	};

	echo '<tr><td colspan="15" style="padding:6px 0">'
		.'<a href="javascript:;" onclick="tgChk(true)">全选 Select all</a> &nbsp;|&nbsp; '
		.'<a href="javascript:;" onclick="tgChk(false)">全不选 None</a>'
		.'<script type="text/javascript">function tgChk(v){var f=document.getElementById("tgforums")||document,b=f.getElementsByTagName("input");for(var i=0;i<b.length;i++){if(b[i].name=="fids[]")b[i].checked=v;}}</script>'
		.'</td></tr>';

	$html = '<div style="max-height:460px;overflow:auto;border:1px solid #e3e6ea;border-radius:8px;padding:8px 12px;background:#fafbfc">';
	$any = false;
	foreach($groups as $gid => $g) {
		if(empty($forums[$gid])) { continue; }
		$any = true;
		$html .= '<div style="margin:10px 0 2px;font-weight:700;color:#36465d;border-bottom:1px dashed #d0d5dc;padding-bottom:3px">📁 '.$e($g['name']).'</div>';
		foreach($forums[$gid] as $f) {
			$html .= $render_cb($f, 18);
			if(!empty($subs[$f['fid']])) {
				foreach($subs[$f['fid']] as $s) {
					$html .= $render_cb($s, 40);
				}
			}
		}
	}
	// forums whose parent group is missing (orphans), so nothing is hidden from the admin
	$orphans = array();
	foreach($forums as $gid => $list) {
		if(!isset($groups[$gid])) { $orphans = array_merge($orphans, $list); }
	}
	if($orphans) {
		$any = true;
		$html .= '<div style="margin:10px 0 2px;font-weight:700;color:#36465d;border-bottom:1px dashed #d0d5dc;padding-bottom:3px">📁 其他 / Other</div>';
		foreach($orphans as $f) { $html .= $render_cb($f, 18); }
	}
	if(!$any) { $html .= '<div style="color:#999">未找到任何板块 / No forums found.</div>'; }
	$html .= '</div>';
	showtablerow('', '', $html);

	showtablerow('', '', '阅读权限上限 / Read-permission ceiling：<input type="text" name="max_readperm" value="'.$e($cfg['max_readperm']).'" class="txt" style="width:80px" /> '
		.'<span class="smalltxt">当主题的「阅读权限 readperm」 ≥ 此值时<b>不推送</b>（例如填 10 → readperm≥10 不推）；<b>填 0 = 不限制，全部推送</b>。默认 1 = 仅推送完全公开(readperm=0)的内容。</span>');
	showsubmit('tg_forums', '保存 / Save');
	showtablefooter();
	showformfooter();

// ===============================================================================
} elseif($tab === 'rules') {
	showtableheader('✂️ 消息清洗规则 / Message-cleanup rules');
	showformheader($selfurl, '', 'tgrules');
	showtablerow('', '', '帖子正文按下列顺序处理后再发往 Telegram。内置规则可逐项开关；自定义规则在内置规则之后执行；截断永远最后。/ Built-ins run in order, then custom rules, then truncation.');
	showtablerow('', '', '引用 / Quote：'.$onoff('rule_quote', $cfg['rule_quote']).' &nbsp; <span class="smalltxt"><code>[quote]…[/quote]</code> → 「…」</span>');
	showtablerow('', '', '链接 / URLs：'.$onoff('rule_url', $cfg['rule_url']).' &nbsp; <span class="smalltxt">站外链接 → 🔗（站内主题链接保留文字）/ external links → 🔗</span>');
	showtablerow('', '', '@ 提及 / Mentions：'.$onoff('rule_at', $cfg['rule_at']).' &nbsp; <span class="smalltxt"><code>@</code> → <code>@&nbsp;</code>（避免误触发 Telegram 提及）</span>');
	showtablerow('', '', '隐藏内容 / Hidden：'.$onoff('rule_hide', $cfg['rule_hide']).' &nbsp; <span class="smalltxt"><code>[hide]…[/hide]</code> → 【隐藏内容】（不泄露原文）</span>');
	showtablerow('', '', '附件/图片 / Attachments：'.$onoff('rule_attach', $cfg['rule_attach']).' &nbsp; <span class="smalltxt"><code>[attach]N[/attach]</code>、<code>[img]…[/img]</code> → 🖼️</span>');
	showtablerow('', '', '清除其余 BBCode / Strip bbcode：'.$onoff('rule_stripbbcode', $cfg['rule_stripbbcode']).' &nbsp; <span class="smalltxt">移除剩余 <code>[..]</code> 标签</span>');
	showtablerow('', '', '合并空白换行 / Collapse whitespace：'.$onoff('rule_collapse', $cfg['rule_collapse']).' &nbsp; <span class="smalltxt">多个空白/换行 → 单个空格</span>');
	showtablerow('', '', '截断长度 / Truncate length：<input type="text" name="truncate_length" value="'.$e($cfg['truncate_length']).'" class="txt" style="width:80px" /> <span class="smalltxt">超出则截断并加 <code>...</code>；填 0 = 不截断。</span>');
	showtablerow('', '', '自定义规则 / Custom rules <span class="smalltxt">每行一条 <code>正则 =&gt; 替换</code>；正则需带定界符（如 <code>/foo/i</code>）；<code>#</code> 开头为注释；非法行自动跳过。</span>：'
		.'<br /><textarea name="custom_rules" rows="5" class="txt" style="width:560px;font-family:monospace" placeholder="# 例 / examples:'."\n".'/\\{:[^}]+:\\}/ =&gt; 😊'."\n".'/\\[code\\].*?\\[\\/code\\]/is =&gt; 【代码】">'.$e($cfg['custom_rules']).'</textarea>');
	showtablerow('', '', '匿名显示名 / Anonymous label：<input type="text" name="anon_name" value="'.$e($cfg['anon_name']).'" class="txt" style="width:160px" /> &nbsp; 主题按钮 / Thread button：<input type="text" name="btn_thread" value="'.$e($cfg['btn_thread']).'" class="txt" style="width:160px" /> &nbsp; 回复按钮 / Reply button：<input type="text" name="btn_reply" value="'.$e($cfg['btn_reply']).'" class="txt" style="width:160px" />');
	showsubmit('tg_rules', '保存 / Save');
	showtablefooter();
	showformfooter();

	// live preview of the current pipeline on a representative sample
	$sample = '[quote]原帖引用[/quote] 大家好，看这个 [url=https://example.com]外链[/url] 和 https://test.com/page 还有 @张三 [attach]123[/attach] [hide]这里是隐藏内容不应外泄[/hide] [b]加粗[/b]'."\n\n".'第二行';
	showtableheader('👁 效果预览 / Preview（基于已保存的规则 / current saved rules）');
	showtablerow('', '', '<b>原文 / Raw：</b><br /><code style="white-space:pre-wrap;color:#666">'.$e($sample).'</code>');
	showtablerow('', '', '<b>结果 / Result：</b><br /><div style="background:#f0f7ff;border:1px solid #cfe2ff;border-radius:8px;padding:10px;white-space:pre-wrap">'.$e(tgnotify_transform($sample, $cfg)).'</div>');
	showtablefooter();

// ===============================================================================
} else { // status
	$st = tgnotify_state();
	$maxpid = tgnotify_maxpid();
	$behind = max(0, $maxpid - intval($st['last_pid']));
	showtableheader('📊 运行状态 / Status');
	showtablerow('', '', '游标位置 / Cursor (last pid)：<b>'.intval($st['last_pid']).'</b> &nbsp; 当前最大 pid / current max：<b>'.$maxpid.'</b> &nbsp; 待处理 / pending：<b style="color:'.($behind ? '#b25f00' : '#0a8f6a').'">'.$behind.'</b>');
	showtablerow('', '', '上次检查 / Last scan：'.(intval($st['last_scan']) ? dgmdate(intval($st['last_scan'])) : '—'));
	showtablerow('', '', '上次成功发送 / Last send：'.(intval($st['lastsend']) ? dgmdate(intval($st['lastsend'])) : '—'));
	showtablerow('', '', '累计成功 / Sent：<b style="color:#0a8f6a">'.intval($st['sent']).'</b> &nbsp; 累计失败 / Failed：<b style="color:'.(intval($st['failed']) ? '#c33' : '#999').'">'.intval($st['failed']).'</b>');
	if(intval($st['fail_count'])) {
		showtablerow('', '', '正在重试 / Retrying：pid '.intval($st['fail_pid']).'（已失败 '.intval($st['fail_count']).' 次 / attempts）');
	}
	showtablerow('', '', '最近错误 / Last error：'.($st['lasterror'] !== '' ? '<span style="color:#c33">'.$e($st['lasterror']).'</span>' : '—'));
	showtablefooter();

	showtableheader('ℹ️ 工作原理 / How it works');
	showtablerow('', '', 'Discuz 在发帖流程中没有插件钩子，因此本插件不修改核心：它在每次页面渲染时（global_footer）做一次<b>受限频、加锁</b>的扫描，沿 <code>pid</code> 游标发现新主题/回复并推送。发帖后用户会被跳转到帖子页，从而<b>近乎即时</b>触发。');
	showtablerow('', '', '注意 / Note：若发帖后<b>没有任何页面被访问</b>（如纯 API 发帖），消息会等到下一次页面渲染时才推送；<b>进入审核、之后才通过</b>的帖子不会补推（游标已越过）。');
	showtablefooter();
}
