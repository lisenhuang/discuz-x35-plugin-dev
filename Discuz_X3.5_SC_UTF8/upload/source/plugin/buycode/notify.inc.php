<?php
/**
 * Stripe webhook receiver — plugin.php?id=buycode:notify  (guest-accessible, no formhash/login).
 * Verifies the Stripe-Signature header against the configured webhook secret(s) and fulfills the
 * order on checkout.session.completed. Never renders the forum template; replies plain text.
 */
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

require_once DISCUZ_ROOT.'./source/plugin/buycode/function_buycode.php';

$payload = file_get_contents('php://input');
$sig     = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
$cfg     = buycode_config();

$secrets = array();
if($cfg['test_webhook_secret'] !== '') { $secrets[] = $cfg['test_webhook_secret']; }
if($cfg['live_webhook_secret'] !== '') { $secrets[] = $cfg['live_webhook_secret']; }

if(!buycode_verify_signature($payload, $sig, $secrets)) {
	@header('HTTP/1.1 400 Bad Request');
	exit('invalid signature');
}

$event = json_decode($payload, true);
if(is_array($event) && isset($event['type']) && $event['type'] === 'checkout.session.completed') {
	$session = isset($event['data']['object']) ? $event['data']['object'] : array();
	if(!empty($session['id']) && (!isset($session['payment_status']) || $session['payment_status'] === 'paid')) {
		buycode_fulfill($session['id']);
	}
}

@header('HTTP/1.1 200 OK');
exit('ok');
