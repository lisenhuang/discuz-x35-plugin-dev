<?php
/**
 * Shared helpers for the "buycode" plugin — sell Discuz built-in invite codes via Stripe.
 *
 * Test and live run INDEPENDENTLY and side by side: the target environment is chosen per request by
 * the ?env= URL param (env=test => test; anything else => live, which keeps the clean public URL).
 * Each env has its own enable flag, secret key, webhook secret/id, and base URL (e.g. live = your
 * production domain, test = a Cloudflare Tunnel domain). Codes go to the core common_invite table.
 *
 * Required by buycode.inc.php (buy), return.inc.php (success), notify.inc.php (webhook), admincp.inc.php.
 */
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

// 32-char unambiguous alphabet — excludes I, O, 0, 1 (look-alikes).
define('BUYCODE_ALPHABET', '23456789ABCDEFGHJKLMNPQRSTUVWXYZ');

/** Settings (stored serialized in common_setting['buycode']) merged with defaults. */
function buycode_config() {
	global $_G;
	$defaults = array(
		// --- test environment ---
		'test_enabled'        => 0,
		'test_secret_key'     => '',
		'test_webhook_secret' => '',
		'test_webhook_id'     => '',
		'test_base'           => '',   // public base URL override for test (e.g. a Cloudflare Tunnel domain)
		// --- live environment ---
		'live_enabled'        => 0,
		'live_secret_key'     => '',
		'live_webhook_secret' => '',
		'live_webhook_id'     => '',
		'live_base'           => '',   // public base URL override for live (usually blank = auto-detect)
		// --- shared ---
		'unit_amount'         => 500,  // smallest currency unit (e.g. cents)
		'currency'            => 'usd',
		'product_label'       => '论坛邀请码',
		'max_qty'             => 10,
		'code_length'         => 6,
		'expiry_days'         => 0,     // 0 = never
		'redirect_url'        => 'member.php?mod=register',
	);
	$cfg = isset($_G['setting']['buycode']) ? $_G['setting']['buycode'] : '';
	if(is_string($cfg)) {
		$cfg = dunserialize($cfg);
	}
	if(!is_array($cfg)) {
		$cfg = array();
	}
	return array_merge($defaults, $cfg);
}

/** Target Stripe environment for this request: 'test' if ?env=test, else 'live' (the clean default). */
function buycode_env() {
	return getgpc('env') === 'test' ? 'test' : 'live';
}

/** URL query suffix that keeps a request in the given env ('&env=test' for test, '' for live). */
function buycode_env_qs($env) {
	return $env === 'test' ? '&env=test' : '';
}

/** Normalize a base URL/domain: assume https:// if no scheme, strip trailing slash. '' stays ''. */
function buycode_norm_base($url) {
	$url = trim((string)$url);
	if($url === '') {
		return '';
	}
	if(!preg_match('#^https?://#i', $url)) {
		$url = 'https://'.$url; // tunnels are https; assume https when no scheme is given
	}
	return rtrim($url, '/');
}

/**
 * Absolute base URL for the given env: a per-env override (e.g. a Cloudflare Tunnel domain for test,
 * handy when the forum is only reachable at http://localhost during dev) or auto-detect from the request.
 */
function buycode_base_url($env = null) {
	global $_G;
	if($env === null) {
		$env = buycode_env();
	}
	$cfg = isset($_G['setting']['buycode']) ? $_G['setting']['buycode'] : '';
	if(is_string($cfg)) {
		$cfg = dunserialize($cfg);
	}
	$override = (is_array($cfg) && !empty($cfg[$env.'_base'])) ? buycode_norm_base($cfg[$env.'_base']) : '';
	if($override !== '') {
		return $override;
	}
	$scheme = !empty($_G['scheme']) ? $_G['scheme'] : 'http';
	$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
	return $scheme.'://'.$host;
}

/** Build an absolute register link (in the given env's base) with the invite code pre-attached. */
function buycode_register_link($redirect_url, $code, $env) {
	$base = buycode_base_url($env);
	$url = preg_match('#^https?://#i', $redirect_url) ? $redirect_url : $base.'/'.ltrim($redirect_url, '/');
	$sep = strpos($url, '?') !== false ? '&' : '?';
	return $url.$sep.'invitecode='.rawurlencode($code);
}

/** Human-readable price from a Stripe amount (smallest unit) + currency. */
function buycode_format_price($amount, $currency) {
	$currency = strtolower($currency);
	$zero = array('jpy','krw','vnd','clp','bif','djf','gnf','kmf','mga','pyg','rwf','ugx','vuv','xaf','xof','xpf');
	$symbols = array('usd'=>'$','cny'=>'¥','jpy'=>'¥','eur'=>'€','gbp'=>'£','hkd'=>'HK$','aud'=>'A$','cad'=>'C$','sgd'=>'S$');
	$sym = isset($symbols[$currency]) ? $symbols[$currency] : (strtoupper($currency).' ');
	return in_array($currency, $zero) ? $sym.intval($amount) : $sym.number_format($amount / 100, 2);
}

/** One invite code from the unambiguous alphabet using the CSPRNG. */
function buycode_gencode($len = 6) {
	$len = max(4, min(20, intval($len)));
	$max = strlen(BUYCODE_ALPHABET) - 1;
	$code = '';
	for($i = 0; $i < $len; $i++) {
		$code .= BUYCODE_ALPHABET[random_int(0, $max)];
	}
	return $code;
}

/**
 * Native cURL call to the Stripe REST API with an explicit (env-specific) secret key. Returns the
 * decoded JSON array (with an extra '_http' key), or array('_error'=>..., '_http'=>0) on transport error.
 */
function buycode_stripe($path, $post = null, $method = 'POST', $secret = '') {
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1'.$path);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 30);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
	$headers = array('Authorization: Bearer '.$secret);
	if(strtoupper($method) === 'POST') {
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : '');
		$headers[] = 'Content-Type: application/x-www-form-urlencoded';
	}
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	$body  = curl_exec($ch);
	$errno = curl_errno($ch);
	$http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if($errno) {
		return array('_error' => 'curl:'.$errno, '_http' => 0);
	}
	$data = json_decode($body, true);
	if(!is_array($data)) {
		$data = array();
	}
	$data['_http'] = $http;
	return $data;
}

/**
 * Generate $quantity invite codes into the core common_invite table (uid=0, unused), retrying
 * on the rare alphabet collision. Returns the array of issued codes.
 */
function buycode_make_invites($quantity, $expiry_days, $code_length, $email) {
	global $_G;
	$tbl = DB::table('common_invite');
	$endtime = $expiry_days ? (TIMESTAMP + intval($expiry_days) * 86400) : 0;
	$ip = isset($_G['clientip']) ? $_G['clientip'] : '';
	$codes = array();
	for($i = 0; $i < $quantity; $i++) {
		for($try = 0; $try < 12; $try++) {
			$code = buycode_gencode($code_length);
			if(DB::result_first('SELECT id FROM '.$tbl.' WHERE code=%s', array($code))) {
				continue; // collision — pick another
			}
			DB::query('INSERT INTO '.$tbl.' (uid, code, fuid, fusername, type, email, inviteip, dateline, endtime, status) '
				.'VALUES (0, %s, 0, %s, 0, %s, %s, %d, %d, 1)',
				array($code, '', $email, $ip, TIMESTAMP, $endtime), true);
			if(DB::affected_rows() > 0) {
				$codes[] = $code;
				break;
			}
		}
	}
	return $codes;
}

/**
 * Idempotently fulfill a paid Stripe Checkout session: atomically claim the pending order, issue the
 * codes, email them. Safe to call from both the webhook and the return page (no double-issue). The
 * email's register link is built in the order's own env. Returns the issued codes.
 */
function buycode_fulfill($sessionid) {
	$sessionid = trim($sessionid);
	if($sessionid === '') {
		return array();
	}
	$tbl = DB::table('buycode_order');
	$order = DB::fetch_first('SELECT * FROM '.$tbl.' WHERE sessionid=%s', array($sessionid));
	if(!$order) {
		return array();
	}
	if($order['status'] == 1) {
		return $order['codes'] !== '' ? explode(',', $order['codes']) : array();
	}
	// Atomically claim the order — only one concurrent caller wins this UPDATE.
	DB::query('UPDATE '.$tbl.' SET status=1, paydateline=%d WHERE orderid=%d AND status=0',
		array(TIMESTAMP, $order['orderid']));
	if(DB::affected_rows() == 0) {
		$row = DB::fetch_first('SELECT codes FROM '.$tbl.' WHERE orderid=%d', array($order['orderid']));
		return ($row && $row['codes'] !== '') ? explode(',', $row['codes']) : array();
	}
	$cfg = buycode_config();
	$env = $order['mode'] === 'live' ? 'live' : 'test';
	$codes = buycode_make_invites($order['quantity'], $cfg['expiry_days'], $cfg['code_length'], $order['email']);
	DB::query('UPDATE '.$tbl.' SET codes=%s WHERE orderid=%d', array(implode(',', $codes), $order['orderid']));
	if($codes) {
		buycode_mail($order['email'], $codes, $cfg['redirect_url'], $env);
	}
	return $codes;
}

/** Email the issued codes (Simplified Chinese) with a one-click, code-prefilled register link in $env's base. */
function buycode_mail($to, $codes, $redirect_url, $env) {
	global $_G;
	if(!$to || !$codes) {
		return false;
	}
	$sitename = $_G['setting']['bbname'];
	$reglink  = buycode_register_link($redirect_url, $codes[0], $env);
	$codelist = '';
	foreach($codes as $c) {
		$codelist .= '<div style="font-size:20px;font-weight:bold;letter-spacing:2px;margin:4px 0">'.htmlspecialchars($c).'</div>';
	}
	$subject = '您在'.$sitename.'购买的邀请码';
	$message = '<p>感谢您的购买！以下是您的邀请码：</p>'
		.$codelist
		.'<p style="margin-top:16px">点击下面的链接立即注册（邀请码会自动填入注册页面）：</p>'
		.'<p><a href="'.$reglink.'">'.$reglink.'</a></p>'
		.'<p style="color:#888;font-size:13px">每个邀请码仅可使用一次。</p>';
	return sendmail($to, $subject, $message);
}

/**
 * Verify a Stripe webhook signature against any of the supplied webhook secrets (so both test and
 * live endpoints validate). $secrets is an array of candidate signing secrets.
 */
function buycode_verify_signature($payload, $sigheader, $secrets) {
	if(!$sigheader || !$secrets) {
		return false;
	}
	$t = null; $v1 = array();
	foreach(explode(',', $sigheader) as $part) {
		$kv = explode('=', trim($part), 2);
		if(count($kv) != 2) {
			continue;
		}
		if($kv[0] === 't') {
			$t = $kv[1];
		} elseif($kv[0] === 'v1') {
			$v1[] = $kv[1];
		}
	}
	if($t === null || !$v1) {
		return false;
	}
	if(abs(TIMESTAMP - intval($t)) > 300) { // 5-minute tolerance
		return false;
	}
	$signed = $t.'.'.$payload;
	foreach($secrets as $secret) {
		if($secret === '') {
			continue;
		}
		$expected = hash_hmac('sha256', $signed, $secret);
		foreach($v1 as $candidate) {
			if(hash_equals($expected, $candidate)) {
				return true;
			}
		}
	}
	return false;
}

/** Render a self-contained, Simplified-Chinese styled page (no template-cache dependency) and exit. */
function buycode_render_page($title, $bodyhtml) {
	global $_G;
	$sitename = htmlspecialchars($_G['setting']['bbname']);
	$home = buycode_base_url().'/';
	@header('Content-Type: text/html; charset=utf-8');
	echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
		.'<meta name="viewport" content="width=device-width,initial-scale=1">'
		.'<title>'.htmlspecialchars($title).' - '.$sitename.'</title><style>'
		.'body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif;background:#f5f6f8;color:#2b2f33;}'
		.'.wrap{max-width:480px;margin:48px auto;padding:0 16px;}'
		.'.card{background:#fff;border-radius:14px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:28px;}'
		.'h1{font-size:20px;margin:0 0 14px;}'
		.'.price{font-size:30px;font-weight:700;color:#0a8f6a;margin:6px 0 4px;}'
		.'label{display:block;margin:16px 0 6px;font-size:14px;color:#555;}'
		.'input,select{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid #dcdfe3;border-radius:9px;font-size:15px;}'
		.'.btn{display:block;width:100%;box-sizing:border-box;margin-top:20px;padding:13px;border:0;border-radius:9px;background:#635bff;color:#fff;font-size:16px;font-weight:600;cursor:pointer;text-align:center;text-decoration:none;}'
		.'.btn:hover{background:#524af0;}'
		.'.code{font-size:24px;font-weight:700;letter-spacing:3px;background:#f0f7ff;border:1px dashed #9cc6ff;border-radius:9px;padding:13px;text-align:center;margin:8px 0;color:#1a56db;}'
		.'.muted{color:#8a9099;font-size:13px;line-height:1.6;}'
		.'.tag{display:inline-block;font-size:12px;font-weight:600;padding:2px 8px;border-radius:6px;background:#fff4e5;color:#b25f00;margin-left:6px;vertical-align:middle;}'
		.'.err{background:#fff1f1;border:1px solid #f3c0c0;color:#c33;padding:10px 12px;border-radius:9px;margin-bottom:12px;font-size:14px;}'
		.'.foot{text-align:center;margin-top:16px;}.foot a{color:#9aa0a6;font-size:12px;text-decoration:none;}'
		.'</style></head><body><div class="wrap"><div class="card">'
		.$bodyhtml
		.'</div><div class="foot"><a href="'.$home.'">&larr; 返回 '.$sitename.'</a></div></div></body></html>';
	exit();
}
