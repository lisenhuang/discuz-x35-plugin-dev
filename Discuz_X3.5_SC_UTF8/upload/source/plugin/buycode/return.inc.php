<?php
/**
 * Success page — plugin.php?id=buycode:return&session_id={CHECKOUT_SESSION_ID}
 * Verifies the session with Stripe and fulfills as a fallback if the webhook hasn't landed yet,
 * then shows the code(s) and a code-prefilled "立即注册" button. UI text in Simplified Chinese.
 */
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

require_once DISCUZ_ROOT.'./source/plugin/buycode/function_buycode.php';

$cfg  = buycode_config();
$base = buycode_base_url();

$sessionid = trim((string)getgpc('session_id'));
if($sessionid === '') {
	buycode_render_page('未找到订单',
		'<h1>未找到订单</h1><p class="muted">缺少订单信息。</p>'
		.'<a class="btn" href="'.$base.'/plugin.php?id=buycode">返回购买页</a>');
}

$codes = array();
// Primary path: confirm payment with Stripe, then fulfill (idempotent).
$sess = buycode_stripe('/checkout/sessions/'.rawurlencode($sessionid), null, 'GET');
if(!empty($sess['id']) && isset($sess['payment_status']) && $sess['payment_status'] === 'paid') {
	$codes = buycode_fulfill($sessionid);
} else {
	// Maybe the webhook already fulfilled it — read back the stored order.
	$order = DB::fetch_first('SELECT codes, status FROM '.DB::table('buycode_order').' WHERE sessionid=%s', array($sessionid));
	if($order && $order['status'] == 1 && $order['codes'] !== '') {
		$codes = explode(',', $order['codes']);
	}
}

if($codes) {
	$codehtml = '';
	foreach($codes as $c) {
		$codehtml .= '<div class="code">'.htmlspecialchars($c).'</div>';
	}
	$reglink = buycode_register_link($cfg['redirect_url'], $codes[0]);
	$html = '<h1>支付成功 🎉</h1>'
		.'<p class="muted">以下是您的邀请码（已发送至您的邮箱）：</p>'
		.$codehtml
		.'<a class="btn" href="'.htmlspecialchars($reglink, ENT_QUOTES).'">立即注册 →</a>'
		.'<p class="muted" style="margin-top:12px">点击上面的按钮，邀请码将自动填入注册页面。每个邀请码仅可使用一次。</p>';
	buycode_render_page('支付成功', $html);
} else {
	$html = '<h1>正在确认支付…</h1>'
		.'<p class="muted">您的支付正在处理中，邀请码生成后会立即发送到您的邮箱，请稍后查收。</p>'
		.'<a class="btn" href="'.$base.'/plugin.php?id=buycode:return&session_id='.rawurlencode($sessionid).'">刷新查看</a>';
	buycode_render_page('处理中', $html);
}
