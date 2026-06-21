<?php
/**
 * Public buy page — plugin.php?id=buycode (live) or plugin.php?id=buycode&env=test (test).
 * Guest-accessible via plugin.php default routing. Picks the env's enable flag + secret key, creates a
 * Stripe Checkout Session, and redirects to it. UI text in Simplified Chinese.
 */
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

require_once DISCUZ_ROOT.'./source/plugin/buycode/function_buycode.php';

$env    = buycode_env();
$cfg    = buycode_config();
$base   = buycode_base_url($env);
$qs     = buycode_env_qs($env);
$secret = $cfg[$env.'_secret_key'];

if(!$cfg[$env.'_enabled'] || $secret === '') {
	buycode_render_page('暂未开放', '<h1>邀请码购买暂未开放</h1><p class="muted">请稍后再试，或联系管理员。</p>');
}

$err   = '';
$email = '';
if($_SERVER['REQUEST_METHOD'] === 'POST') {
	$email = trim((string)getgpc('email'));
	$maxq  = max(1, intval($cfg['max_qty']));
	$qty   = min($maxq, max(1, intval(getgpc('qty'))));
	if(!preg_match('/^[\w.+-]+@[\w-]+\.[\w.-]+$/', $email)) {
		$err = '请输入有效的邮箱地址。';
	} else {
		$post = array(
			'mode'        => 'payment',
			'success_url' => $base.'/plugin.php?id=buycode:return'.$qs.'&session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'  => $base.'/plugin.php?id=buycode'.$qs,
			'customer_email'      => $email,
			'client_reference_id' => 'buycode_'.$env.'_'.intval($_G['uid']).'_'.TIMESTAMP,
			'line_items[0][quantity]'                          => $qty,
			'line_items[0][price_data][currency]'              => $cfg['currency'],
			'line_items[0][price_data][unit_amount]'           => intval($cfg['unit_amount']),
			'line_items[0][price_data][product_data][name]'    => $cfg['product_label'],
			'metadata[email]'    => $email,
			'metadata[quantity]' => $qty,
			'metadata[env]'      => $env,
		);
		$sess = buycode_stripe('/checkout/sessions', $post, 'POST', $secret);
		if(!empty($sess['id']) && !empty($sess['url'])) {
			DB::query('INSERT INTO '.DB::table('buycode_order')
				.' (sessionid, uid, email, quantity, amount, currency, codes, mode, status, dateline, paydateline) '
				.'VALUES (%s, %d, %s, %d, %d, %s, %s, %s, 0, %d, 0)',
				array($sess['id'], intval($_G['uid']), $email, $qty,
					intval($cfg['unit_amount']) * $qty, $cfg['currency'], '', $env, TIMESTAMP), true);
			header('Location: '.$sess['url']);
			exit();
		}
		$err = '下单失败：'.(isset($sess['error']['message']) ? htmlspecialchars($sess['error']['message']) : '请稍后再试或联系管理员。');
	}
}

$maxq  = max(1, intval($cfg['max_qty']));
$qopts = '';
for($i = 1; $i <= $maxq; $i++) {
	$qopts .= '<option value="'.$i.'">'.$i.'</option>';
}
$price   = buycode_format_price($cfg['unit_amount'], $cfg['currency']);
$testtag = $env === 'test' ? '<span class="tag">测试模式 TEST</span>' : '';

$html = '<h1>购买邀请码'.$testtag.'</h1>'
	.'<p class="muted">'.htmlspecialchars($cfg['product_label']).'</p>'
	.'<div class="price">'.$price.' <span class="muted" style="font-size:14px">/ 个</span></div>'
	.($err ? '<div class="err">'.$err.'</div>' : '')
	.'<form method="post" action="'.$base.'/plugin.php?id=buycode'.$qs.'">'
	.'<label>数量</label><select name="qty">'.$qopts.'</select>'
	.'<label>接收邮箱（邀请码将发送到此邮箱）</label>'
	.'<input type="email" name="email" required placeholder="you@example.com" value="'.htmlspecialchars($email, ENT_QUOTES).'">'
	.'<button class="btn" type="submit">立即购买并支付</button>'
	.'</form>'
	.'<p class="muted" style="margin-top:14px">支付由 Stripe 安全处理。付款成功后，邀请码会立即显示并发送到您的邮箱。</p>';

buycode_render_page('购买邀请码', $html);
