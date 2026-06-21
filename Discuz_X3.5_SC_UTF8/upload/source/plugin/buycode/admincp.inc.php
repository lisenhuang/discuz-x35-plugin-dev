<?php
if(!defined('IN_DISCUZ') || !defined('IN_ADMINCP')) { exit('Access Denied'); }

/**
 * Settings (module type 3) for the buycode plugin, organized into tabs (?bctab=):
 *   test   — 🧪 test environment: enable, keys, webhook auto-register
 *   live   — 🚀 live environment: enable, keys, webhook auto-register
 *   shared — ⚙ price, label, qty, code length, expiry, redirect URL
 *   orders — 🧾 recent orders
 * Each tab saves only its own fields (merged onto the stored config) so saving one env never disturbs
 * the other. Config is stored in common_setting['buycode'].
 */
require_once DISCUZ_ROOT.'./source/plugin/buycode/function_buycode.php';

$selfurl = 'plugins&operation=config&do='.$pluginid.'&identifier=buycode&pmod=admincp';
$cpu = function($t) use ($selfurl) { return 'action='.$selfurl.'&bctab='.$t; }; // cpmsg target for a tab

// --- save: TEST / LIVE basic settings (enable + secret key; webhook secret is managed separately) ---
foreach(array('test', 'live') as $senv) {
	if(!submitcheck('bcsubmit_'.$senv)) {
		continue;
	}
	$raw = (array)dunserialize(C::t('common_setting')->fetch_setting('buycode'));
	$raw[$senv.'_enabled']    = intval(getgpc($senv.'_enabled'));
	$raw[$senv.'_secret_key'] = trim((string)getgpc($senv.'_secret_key'));
	C::t('common_setting')->update_setting('buycode', $raw);
	updatecache('setting');
	cpmsg(($senv === 'test' ? '测试' : '正式').'设置已保存 / Settings saved.', $cpu($senv), 'succeed');
}
// --- save: manual webhook signing secret (only for the Dashboard-managed path) ----------------------
foreach(array('test', 'live') as $senv) {
	if(!submitcheck('setsecret_'.$senv)) {
		continue;
	}
	$raw = (array)dunserialize(C::t('common_setting')->fetch_setting('buycode'));
	$raw[$senv.'_webhook_secret'] = trim((string)getgpc($senv.'_webhook_secret'));
	C::t('common_setting')->update_setting('buycode', $raw);
	updatecache('setting');
	cpmsg('签名密钥已保存 / Signing secret saved.', $cpu($senv), 'succeed');
}
// --- save: SHARED tab ---------------------------------------------------------
if(submitcheck('bcsubmit_shared')) {
	$cur = strtolower(preg_replace('/[^a-zA-Z]/', '', (string)getgpc('currency')));
	$raw = (array)dunserialize(C::t('common_setting')->fetch_setting('buycode'));
	$raw['unit_amount']   = max(1, intval(getgpc('unit_amount')));
	$raw['currency']      = $cur !== '' ? $cur : 'usd';
	$raw['product_label'] = trim((string)getgpc('product_label'));
	$raw['max_qty']       = max(1, min(999, intval(getgpc('max_qty'))));
	$raw['code_length']   = max(4, min(20, intval(getgpc('code_length'))));
	$raw['expiry_days']   = max(0, intval(getgpc('expiry_days')));
	$raw['redirect_url']  = trim((string)getgpc('redirect_url')) ?: 'member.php?mod=register';
	C::t('common_setting')->update_setting('buycode', $raw);
	updatecache('setting');
	cpmsg('通用设置已保存 / Shared settings saved.', $cpu('shared'), 'succeed');
}

// --- one-click webhook auto-register, per environment -------------------------
foreach(array('test', 'live') as $renv) {
	if(!submitcheck('regwebhook_'.$renv)) {
		continue;
	}
	$cfg = buycode_config();
	$secret = $cfg[$renv.'_secret_key'];
	if($secret === '') {
		cpmsg('请先填写并保存「'.$renv.'」模式的密钥 / Save the '.$renv.' secret key first.', $cpu($renv), 'error');
	}
	$raw = (array)dunserialize(C::t('common_setting')->fetch_setting('buycode'));
	$domain = buycode_norm_base(getgpc($renv.'_base'));
	if($domain !== '') {
		$raw[$renv.'_base'] = $domain;
		$bbase = $domain;
	} else {
		$bbase = buycode_base_url($renv);
	}
	$whurl = $bbase.'/plugin.php?id=buycode:notify'.buycode_env_qs($renv);
	$exid  = isset($raw[$renv.'_webhook_id']) ? $raw[$renv.'_webhook_id'] : '';
	$res = array(); $done = false; $note = '';
	if($exid) {
		$res = buycode_stripe('/webhook_endpoints/'.rawurlencode($exid),
			array('url' => $whurl, 'enabled_events' => array('checkout.session.completed')), 'POST', $secret);
		if(!empty($res['id'])) {
			$done = true;
			$note = 'Webhook 已更新 / updated（'.$res['id'].'）';
		}
	}
	if(!$done) {
		$res = buycode_stripe('/webhook_endpoints',
			array('url' => $whurl, 'enabled_events' => array('checkout.session.completed'),
				'description' => 'buycode plugin ('.$renv.')'), 'POST', $secret);
		if(!empty($res['id'])) {
			$raw[$renv.'_webhook_id'] = $res['id'];
			if(!empty($res['secret'])) { $raw[$renv.'_webhook_secret'] = $res['secret']; }
			$done = true;
			$note = 'Webhook 已创建 / created（'.$res['id'].'），签名密钥已自动保存';
		}
	}
	if($done) {
		C::t('common_setting')->update_setting('buycode', $raw);
		updatecache('setting');
		cpmsg($note.'<br>URL: '.htmlspecialchars($whurl), $cpu($renv), 'succeed');
	} else {
		$emsg = isset($res['error']['message']) ? $res['error']['message'] : '请稍后再试 / try again';
		cpmsg('Webhook 注册失败 / failed: '.htmlspecialchars($emsg), $cpu($renv), 'error');
	}
}

$cfg = buycode_config();
$e   = function($v) { return htmlspecialchars((string)$v, ENT_QUOTES); };
$onoff = function($name, $val) {
	return '<select name="'.$name.'"><option value="1"'.($val ? ' selected' : '').'>是 Yes</option>'
		.'<option value="0"'.(!$val ? ' selected' : '').'>否 No</option></select>';
};

$tab = (string)getgpc('bctab');
if(!in_array($tab, array('test', 'live', 'shared', 'orders'), true)) {
	$tab = 'test';
}

// --- tab bar ------------------------------------------------------------------
$tablabels = array('test' => '🧪 测试 Test', 'live' => '🚀 正式 Live', 'shared' => '⚙ 通用 Shared', 'orders' => '🧾 订单 Orders');
$nav = '<div style="margin:12px 0 0;border-bottom:2px solid #d8dce1">';
foreach($tablabels as $k => $label) {
	$on = ($k === $tab);
	$nav .= '<a href="'.ADMINSCRIPT.'?action='.$e($selfurl).'&bctab='.$k.'" style="display:inline-block;padding:8px 18px;margin-right:4px;text-decoration:none;border:1px solid #d8dce1;border-bottom:none;border-radius:7px 7px 0 0;'
		.($on ? 'background:#fff;font-weight:700;color:#222;position:relative;top:2px;' : 'background:#eef1f4;color:#666;').'">'.$label.'</a>';
}
$nav .= '</div>';
echo $nav;

// --- helper: an env (test/live) settings + auto-register tab ------------------
$render_env_tab = function($env, $label) use ($cfg, $e, $onoff, $selfurl) {
	$buyurl  = buycode_base_url($env).'/plugin.php?id=buycode'.buycode_env_qs($env);
	$prefill = $cfg[$env.'_base'] !== '' ? $cfg[$env.'_base'] : buycode_base_url($env);
	$whurl   = buycode_norm_base($prefill).'/plugin.php?id=buycode:notify'.buycode_env_qs($env);
	$whid    = $cfg[$env.'_webhook_id'];
	$ph      = $env === 'test' ? 'https://xxx.trycloudflare.com' : 'https://your-forum.com';
	$skhint  = $env === 'test' ? 'sk_test_…' : 'sk_live_…';
	$keyurl  = $env === 'test' ? 'https://dashboard.stripe.com/test/apikeys' : 'https://dashboard.stripe.com/apikeys';
	$keylink = ' &nbsp;<a href="'.$keyurl.'" target="_blank" rel="noopener">获取密钥 / Get from Stripe ↗</a>';

	$secset = $cfg[$env.'_webhook_secret'] !== '';

	// settings form (enable + secret key only — the webhook signing secret is handled automatically below)
	showtableheader($label.' — 基本设置 / settings');
	showformheader($selfurl, '', 'bc'.$env);
	showtablerow('', '', '启用 / Enabled：'.$onoff($env.'_enabled', $cfg[$env.'_enabled']));
	showtablerow('', '', '密钥 / Secret key <span class="smalltxt">('.$skhint.')</span>'.$keylink.'：<br /><input type="text" name="'.$env.'_secret_key" value="'.$e($cfg[$env.'_secret_key']).'" class="txt" style="width:440px" />');
	showsubmit('bcsubmit_'.$env, '保存 / Save');
	showtablefooter();
	showformfooter();

	// auto-register webhook (handles the signing secret for you — no need to see or type it)
	showtableheader($label.' — Webhook（自动）/ auto-register');
	showformheader($selfurl, '', 'reg'.$env);
	showtablerow('', '', 'Webhook 域名 / Domain：<input type="text" name="'.$env.'_base" value="'.$e($prefill).'" class="txt" style="width:380px" placeholder="'.$ph.'" /><br /><span class="smalltxt">'
		.($env === 'test'
			? '本地开发：填用 Cloudflare Tunnel 解析到本机的 https 域名。'
			: '正式：填你的真实站点域名；若已用真实域名访问后台可留空自动识别。')
		.'点击注册会用此域名创建/更新 Webhook，并<b>自动保存签名密钥</b>（你无需查看或填写）。/ The signing secret is fetched &amp; stored automatically.</span>');
	showtablerow('', '', '目标 / Target：<code>'.$e($whurl).'</code>');
	showtablerow('', '', '状态 / Status：'.($whid ? '✅ 已注册 registered' : '⚪ 未注册 not registered')
		.' &nbsp;|&nbsp; 签名密钥 / Signing secret：'.($secset ? '✅ 已自动配置 auto-configured' : '⚪ 未配置 not set'));
	showsubmit('regwebhook_'.$env, '自动注册 / 更新 Webhook');
	showtablefooter();
	showformfooter();

	// optional manual entry — only for people who created the webhook themselves in the Stripe Dashboard
	showtableheader($label.' — 手动填写签名密钥（可选）/ manual signing secret (optional)');
	showformheader($selfurl, '', 'sec'.$env);
	showtablerow('', '', '<span class="smalltxt">大多数人不需要这一步。只有当你<b>在 Stripe 后台手动创建 Webhook</b>（而非上面的自动注册）时，才需要把签名密钥粘贴到这里。/ Most people can ignore this — only needed if you created the webhook manually in the Stripe Dashboard.</span><br /><input type="text" name="'.$env.'_webhook_secret" value="" class="txt" style="width:380px" placeholder="whsec_…" autocomplete="off" />');
	showsubmit('setsecret_'.$env, '保存密钥 / Save secret');
	showtablefooter();
	showformfooter();

	// integration URLs for this env
	showtableheader($label.' — 对接网址 / URLs');
	showtablerow('', '', '购买页 / Buy page：<a href="'.$e($buyurl).'" target="_blank">'.$e($buyurl).'</a>');
	showtablerow('', '', 'Webhook URL：<code>'.$e($whurl).'</code> <span class="smalltxt">事件 Event：checkout.session.completed</span>');
	showtablefooter();
};

// --- render the active tab ----------------------------------------------------
if($tab === 'test') {
	$render_env_tab('test', '🧪 测试 / TEST');

} elseif($tab === 'live') {
	$render_env_tab('live', '🚀 正式 / LIVE');

} elseif($tab === 'shared') {
	showtableheader('⚙ 通用设置 / Shared settings（test 与 live 共用）');
	showformheader($selfurl, '', 'bcshared');
	showtablerow('', '', '单价 / Unit amount <span class="smalltxt">(最小货币单位，如美分 — 500 = $5.00)</span>：<input type="text" name="unit_amount" value="'.$e($cfg['unit_amount']).'" class="txt" style="width:100px" /> &nbsp; 货币 / Currency：<input type="text" name="currency" value="'.$e($cfg['currency']).'" class="txt" style="width:70px" /> &nbsp; <span class="smalltxt">当前 = <b>'.$e(buycode_format_price($cfg['unit_amount'], $cfg['currency'])).'</b></span>');
	showtablerow('', '', '商品名称 / Product label：<input type="text" name="product_label" value="'.$e($cfg['product_label']).'" class="txt" style="width:340px" />');
	showtablerow('', '', '每单最大数量 / Max qty per order：<input type="text" name="max_qty" value="'.$e($cfg['max_qty']).'" class="txt" style="width:100px" />');
	showtablerow('', '', '邀请码长度 / Code length：<input type="text" name="code_length" value="'.$e($cfg['code_length']).'" class="txt" style="width:100px" /> <span class="smalltxt">(默认 6；排除 I/O/0/1)</span>');
	showtablerow('', '', '邀请码有效期(天) / Code expiry days：<input type="text" name="expiry_days" value="'.$e($cfg['expiry_days']).'" class="txt" style="width:100px" /> <span class="smalltxt">(0 = 永久 never)</span>');
	showtablerow('', '', '支付后跳转地址 / Post-payment redirect URL：<br /><input type="text" name="redirect_url" value="'.$e($cfg['redirect_url']).'" class="txt" style="width:440px" /><br /><span class="smalltxt">默认注册页；邀请码会自动附加为 &amp;invitecode=…，在注册页自动填入。</span>');
	showsubmit('bcsubmit_shared', '保存 / Save');
	showtablefooter();
	showformfooter();

} else { // orders
	showtableheader('🧾 最近订单 / Recent orders');
	echo '<tr class="header"><th>#</th><th>邮箱 Email</th><th>数量 Qty</th><th>金额 Amount</th><th>环境 Env</th><th>状态 Status</th><th>邀请码 Codes</th><th>时间 Time</th></tr>';
	$rows = DB::fetch_all('SELECT * FROM '.DB::table('buycode_order').' ORDER BY orderid DESC LIMIT 100');
	if($rows) {
		foreach($rows as $o) {
			$st = $o['status'] == 1 ? '<b style="color:#090">已支付 paid</b>' : '<span style="color:#999">待支付 pending</span>';
			echo '<tr>'
				.'<td>'.intval($o['orderid']).'</td>'
				.'<td>'.$e($o['email']).'</td>'
				.'<td>'.intval($o['quantity']).'</td>'
				.'<td>'.$e(buycode_format_price($o['amount'], $o['currency'])).'</td>'
				.'<td>'.$e($o['mode']).'</td>'
				.'<td>'.$st.'</td>'
				.'<td>'.$e($o['codes']).'</td>'
				.'<td>'.dgmdate($o['dateline']).'</td>'
				.'</tr>';
		}
	} else {
		echo '<tr><td colspan="8" style="color:#999">暂无订单 / No orders yet.</td></tr>';
	}
	showtablefooter();
}
