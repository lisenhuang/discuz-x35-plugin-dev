<?php
if(!defined('IN_DISCUZ') || !defined('IN_ADMINCP')) { exit('Access Denied'); }

/**
 * Settings (module type 3) for the buycode plugin. Saves config into common_setting['buycode'].
 * Reached via: Admin CP > Apps > Plugins > Buy Invite Code (Stripe) > Settings.
 */
require_once DISCUZ_ROOT.'./source/plugin/buycode/function_buycode.php';

$selfurl = 'plugins&operation=config&do='.$pluginid.'&identifier=buycode&pmod=admincp';
$cpurl   = 'action='.$selfurl;

if(submitcheck('bcsubmit')) {
	$cur = strtolower(preg_replace('/[^a-zA-Z]/', '', (string)getgpc('currency')));
	$new = array(
		'enabled'             => intval(getgpc('enabled')),
		'mode'                => getgpc('mode') === 'live' ? 'live' : 'test',
		'test_secret_key'     => trim((string)getgpc('test_secret_key')),
		'test_webhook_secret' => trim((string)getgpc('test_webhook_secret')),
		'live_secret_key'     => trim((string)getgpc('live_secret_key')),
		'live_webhook_secret' => trim((string)getgpc('live_webhook_secret')),
		'unit_amount'         => max(1, intval(getgpc('unit_amount'))),
		'currency'            => $cur !== '' ? $cur : 'usd',
		'product_label'       => trim((string)getgpc('product_label')),
		'max_qty'             => max(1, min(999, intval(getgpc('max_qty')))),
		'code_length'         => max(4, min(20, intval(getgpc('code_length')))),
		'expiry_days'         => max(0, intval(getgpc('expiry_days'))),
		'redirect_url'        => trim((string)getgpc('redirect_url')) ?: 'member.php?mod=register',
	);
	C::t('common_setting')->update_setting('buycode', $new);
	updatecache('setting');
	cpmsg('设置已保存 / Settings saved.', $cpurl, 'succeed');
}

$cfg        = buycode_config();
$base       = buycode_base_url();
$webhookurl = $base.'/plugin.php?id=buycode:notify';
$buyurl     = $base.'/plugin.php?id=buycode';
$e = function($v) { return htmlspecialchars((string)$v, ENT_QUOTES); };

$sel = function($a, $b) { return $a === $b ? ' selected' : ''; };

showtableheader('Stripe 邀请码购买 / Buy-Code settings');
showformheader($selfurl, '', 'bcform');

showtablerow('', '', '启用 / Enabled：<select name="enabled"><option value="1"'.($cfg['enabled'] ? ' selected' : '').'>是 Yes</option><option value="0"'.(!$cfg['enabled'] ? ' selected' : '').'>否 No</option></select>');
showtablerow('', '', '模式 / Mode：<select name="mode"><option value="test"'.$sel($cfg['mode'], 'test').'>测试 Test (dev)</option><option value="live"'.$sel($cfg['mode'], 'live').'>正式 Live (prod)</option></select>');

showtablerow('', '', '测试密钥 / Test secret key <span class="smalltxt">(sk_test_…)</span>：<br /><input type="text" name="test_secret_key" value="'.$e($cfg['test_secret_key']).'" class="txt" style="width:420px" />');
showtablerow('', '', '测试 Webhook 签名密钥 / Test webhook secret <span class="smalltxt">(whsec_…)</span>：<br /><input type="text" name="test_webhook_secret" value="'.$e($cfg['test_webhook_secret']).'" class="txt" style="width:420px" />');
showtablerow('', '', '正式密钥 / Live secret key <span class="smalltxt">(sk_live_…)</span>：<br /><input type="text" name="live_secret_key" value="'.$e($cfg['live_secret_key']).'" class="txt" style="width:420px" />');
showtablerow('', '', '正式 Webhook 签名密钥 / Live webhook secret <span class="smalltxt">(whsec_…)</span>：<br /><input type="text" name="live_webhook_secret" value="'.$e($cfg['live_webhook_secret']).'" class="txt" style="width:420px" />');

showtablerow('', '', '单价 / Unit amount <span class="smalltxt">(最小货币单位，如美分 cents — 500 = $5.00)</span>：<input type="text" name="unit_amount" value="'.$e($cfg['unit_amount']).'" class="txt" style="width:90px" /> &nbsp; 货币 / Currency：<input type="text" name="currency" value="'.$e($cfg['currency']).'" class="txt" style="width:70px" /> &nbsp; <span class="smalltxt">当前 = <b>'.$e(buycode_format_price($cfg['unit_amount'], $cfg['currency'])).'</b></span>');
showtablerow('', '', '商品名称 / Product label：<input type="text" name="product_label" value="'.$e($cfg['product_label']).'" class="txt" style="width:320px" />');
showtablerow('', '', '每单最大数量 / Max qty per order：<input type="text" name="max_qty" value="'.$e($cfg['max_qty']).'" class="txt" style="width:90px" />');
showtablerow('', '', '邀请码长度 / Code length：<input type="text" name="code_length" value="'.$e($cfg['code_length']).'" class="txt" style="width:90px" /> <span class="smalltxt">(默认 6；字符集排除 I/O/0/1)</span>');
showtablerow('', '', '邀请码有效期(天) / Code expiry days：<input type="text" name="expiry_days" value="'.$e($cfg['expiry_days']).'" class="txt" style="width:90px" /> <span class="smalltxt">(0 = 永久 never)</span>');
showtablerow('', '', '支付后跳转地址 / Post-payment redirect URL：<br /><input type="text" name="redirect_url" value="'.$e($cfg['redirect_url']).'" class="txt" style="width:420px" /> <br /><span class="smalltxt">默认注册页；邀请码会自动附加为 &amp;invitecode=…，在注册页自动填入。</span>');

showsubmit('bcsubmit', '保存 / Save');
showtablefooter();
showformfooter();

showtableheader('对接信息 / Integration URLs');
showtablerow('', '', '<b>购买页 / Buy page：</b> <a href="'.$e($buyurl).'" target="_blank">'.$e($buyurl).'</a>');
showtablerow('', '', '<b>Webhook URL（填入 Stripe Dashboard）：</b><br /><code style="font-size:13px">'.$e($webhookurl).'</code><br /><span class="smalltxt">事件 / Event：<code>checkout.session.completed</code>。本地测试可用 Cloudflare Tunnel 暴露该地址（见 docs/plugins/buycode.md）。地址由当前访问的域名/端口自动推断（80/443 端口会省略）。</span>');
showtablefooter();

showtableheader('最近订单 / Recent orders');
echo '<tr class="header"><th>#</th><th>邮箱 Email</th><th>数量 Qty</th><th>金额 Amount</th><th>模式 Mode</th><th>状态 Status</th><th>邀请码 Codes</th><th>时间 Time</th></tr>';
$rows = DB::fetch_all('SELECT * FROM '.DB::table('buycode_order').' ORDER BY orderid DESC LIMIT 50');
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
