<?php
/**
 * Shared helpers for the "tgnotify" plugin — push every new thread / reply to a Telegram channel.
 *
 * Discuz X3.5 has NO plugin hook in the post-submit path, so instead of hooking the write we DETECT
 * new content out-of-band: a cheap, throttled, lock-protected DRAIN runs on the global_footer hook
 * (fires on virtually every full page render). It walks a single monotonic cursor (forum_post.pid)
 * over the post table joined to forum_thread — first=1 rows are new threads, first=0 rows are replies —
 * applies the admin's filters (selected forums, read-permission ceiling), runs the configurable
 * message-cleanup pipeline, and POSTs to the Telegram Bot API. Effectively immediate (the poster's own
 * post-redirect lands on a page that fires the drain) and never double-sends (cursor + process lock).
 *
 * Required by tgnotify.class.php (hook) and admincp.inc.php (settings UI).
 */
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

/** Settings (stored serialized in common_setting['tgnotify']) merged with defaults. */
function tgnotify_config() {
	global $_G;
	$defaults = array(
		// --- connection ---
		'enabled'          => 0,
		'bot_token'        => '',
		'channel_id'       => '',     // -100xxxxxxxxxx or @channelusername
		'domain'           => '',     // '' => auto-detect from $_G['siteurl']
		'api_base'         => '',     // '' => https://api.telegram.org; override with a reverse-proxy reachable from your network
		'send_retries'     => 3,      // attempts per message (rides through intermittent TLS/network drops)
		'drain_interval'   => 3,      // seconds between scans (throttle)
		'batch_size'       => 10,     // max posts examined per scan
		'retry_max'        => 3,      // transient-failure retries before skipping a stuck message
		// --- routing / filtering ---
		'fids'             => array(),// selected forum ids (default none -> nothing is sent)
		'send_thread'      => 1,
		'send_reply'       => 1,
		'max_readperm'     => 1,      // skip when thread.readperm >= this (0 = no ceiling, send all)
		'disable_preview'  => 1,      // Telegram disable_web_page_preview
		// --- message rule toggles (encode the reference rules; all on by default) ---
		'rule_quote'       => 1,      // [quote]..[/quote] -> 「..」
		'rule_url'         => 1,      // external links -> 🔗 (internal thread links keep their label)
		'rule_at'          => 1,      // @ -> "@ " (defang Telegram mentions)
		'rule_hide'        => 1,      // [hide]..[/hide] -> 【隐藏内容】
		'rule_attach'      => 1,      // [attach]N[/attach] / [img].. -> 🖼️
		'rule_stripbbcode' => 1,      // strip any remaining [..] tags
		'rule_collapse'    => 1,      // collapse whitespace/newlines to single spaces
		'truncate_length'  => 128,    // 0 = no truncation
		'custom_rules'     => '',     // extra regex rules, one "pattern => replacement" per line
		// --- presentation ---
		'anon_name'        => '匿名',
		'btn_thread'       => '查看新贴',
		'btn_reply'        => '查看回复',
		// --- diagnostics ---
		'debug_log'        => 0,      // 1 = write payload to data/log/tgnotify.log instead of sending
	);
	$cfg = isset($_G['setting']['tgnotify']) ? $_G['setting']['tgnotify'] : '';
	if(is_string($cfg)) {
		$cfg = dunserialize($cfg);
	}
	if(!is_array($cfg)) {
		$cfg = array();
	}
	$cfg = array_merge($defaults, $cfg);
	if(!is_array($cfg['fids'])) {
		$cfg['fids'] = array();
	}
	return $cfg;
}

/** Normalize a base URL/domain: assume https:// when no scheme, strip trailing slash. '' stays ''. */
function tgnotify_norm_base($url) {
	$url = trim((string)$url);
	if($url === '') {
		return '';
	}
	if(!preg_match('#^https?://#i', $url)) {
		$url = 'https://'.$url;
	}
	return rtrim($url, '/');
}

/** Absolute site base used to build thread/reply links: the configured override, else $_G['siteurl']. */
function tgnotify_base_url($cfg = null) {
	global $_G;
	if($cfg === null) {
		$cfg = tgnotify_config();
	}
	$override = tgnotify_norm_base($cfg['domain']);
	if($override !== '') {
		return $override;
	}
	if(!empty($_G['siteurl'])) {
		return rtrim($_G['siteurl'], '/');
	}
	$scheme = !empty($_G['scheme']) ? $_G['scheme'] : 'http';
	$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
	return $scheme.'://'.$host;
}

/**
 * Whether a base URL is a real PUBLIC http(s) URL usable as a Telegram inline-button link. Telegram
 * rejects buttons that point at localhost / loopback / private IPs / hostnames without a dot, so when
 * the (auto-detected) domain isn't public we simply omit the button rather than fail the whole message.
 */
function tgnotify_is_public_url($url) {
	if(!preg_match('#^https?://#i', $url)) {
		return false;
	}
	$host = strtolower((string)parse_url($url, PHP_URL_HOST));
	if($host === '' || $host === 'localhost' || $host === '::1' || strpos($host, '.') === false) {
		return false;
	}
	if(preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $host)) {
		return false;
	}
	return true;
}

function tgnotify_thread_url($base, $tid) {
	return $base.'/thread-'.intval($tid).'-1-1.html';
}

function tgnotify_reply_url($base, $tid, $pid) {
	return $base.'/forum.php?mod=redirect&goto=findpost&ptid='.intval($tid).'&pid='.intval($pid);
}

/** Name of the post table new posts are written to (handles X3.5 post-table partitioning). */
function tgnotify_posttable() {
	global $_G;
	$tableid = isset($_G['setting']['posttableid']) ? intval($_G['setting']['posttableid']) : 0;
	if(class_exists('table_forum_post') && method_exists('table_forum_post', 'getposttable')) {
		$t = @table_forum_post::getposttable($tableid);
		if(is_string($t) && $t !== '') {
			return $t;
		}
	}
	return 'forum_post';
}

/** Highest existing pid right now — used to seed the cursor so history isn't blasted on first enable. */
function tgnotify_maxpid() {
	return intval(DB::result_first('SELECT MAX(pid) FROM '.DB::table(tgnotify_posttable())));
}

/** Read the single-row cursor/stats record, self-healing if the row is missing. */
function tgnotify_state() {
	$tbl = DB::table('tgnotify_state');
	$row = DB::fetch_first('SELECT * FROM '.$tbl.' WHERE id=1');
	if(!$row) {
		DB::query('INSERT IGNORE INTO '.$tbl.' (id, last_pid, last_scan) VALUES (1, %d, %d)', array(tgnotify_maxpid(), 0), true);
		$row = DB::fetch_first('SELECT * FROM '.$tbl.' WHERE id=1');
	}
	return is_array($row) ? $row : array('last_pid'=>0,'last_scan'=>0,'fail_pid'=>0,'fail_count'=>0,'sent'=>0,'failed'=>0,'lasterror'=>'','lastsend'=>0);
}

function tgnotify_state_update($fields) {
	DB::update('tgnotify_state', $fields, 'id=1');
}

/**
 * Apply the admin's custom regex rules (one "pattern => replacement" per line; '#'-comments and blanks
 * ignored). Each pattern must be a full PCRE with delimiters, e.g. /foo/i. Invalid lines are skipped.
 */
function tgnotify_apply_custom_rules($msg, $rulestext) {
	$lines = preg_split('/\r\n|\r|\n/', (string)$rulestext);
	foreach($lines as $line) {
		$line = trim($line);
		if($line === '' || $line[0] === '#') {
			continue;
		}
		$pos = strpos($line, '=>');
		if($pos === false) {
			continue;
		}
		$pattern = trim(substr($line, 0, $pos));
		$replace = trim(substr($line, $pos + 2));
		if($pattern === '') {
			continue;
		}
		$out = @preg_replace($pattern, $replace, $msg);
		if($out !== null) {       // null => bad regex / runtime error: skip this rule, keep the text
			$msg = $out;
		}
	}
	return $msg;
}

/**
 * The configurable message-cleanup pipeline. Built-in steps are toggle-gated and mirror the reference
 * rules; custom rules run after the built-ins; truncation is always last.
 */
function tgnotify_transform($msg, $cfg = null) {
	if($cfg === null) {
		$cfg = tgnotify_config();
	}
	$msg = str_replace("\0", '', (string)$msg);   // special threads append NUL bytes to the body
	$msg = trim($msg);

	if(!empty($cfg['rule_quote'])) {
		$msg = str_replace(array('[quote]', '[/quote]'), array('「', '」'), $msg);
	}
	if(!empty($cfg['rule_url'])) {
		// [url]/[url=..] links: external -> 🔗, internal thread links -> keep the label text.
		$msg = preg_replace_callback('/\[url(?:=([^\]]*))?\](.*?)\[\/url\]/is', function($m) {
			$target = ($m[1] !== '') ? $m[1] : $m[2];
			if(stripos($target, 'mod=viewthread') !== false || preg_match('/thread-\d+/i', $target)) {
				return $m[2];
			}
			return '🔗';
		}, $msg);
		// bare URLs left in the text
		$msg = preg_replace('#\bhttps?://[^\s<>\[\]"\']+#i', '🔗', $msg);
	}
	if(!empty($cfg['rule_at'])) {
		$msg = str_replace('@', '@ ', $msg);
	}
	if(!empty($cfg['rule_hide'])) {
		$msg = preg_replace('/\[hide(?:=[^\]]+)?\].*?\[\/hide\]/is', '【隐藏内容】', $msg);
	}
	if(!empty($cfg['rule_attach'])) {
		$msg = preg_replace('/\[attach(?:img)?\]\d+\[\/attach(?:img)?\]/i', '🖼️', $msg);
		$msg = preg_replace('/\[img[^\]]*\].*?\[\/img\]/is', '🖼️', $msg);
	}

	if($cfg['custom_rules'] !== '') {
		$msg = tgnotify_apply_custom_rules($msg, $cfg['custom_rules']);
	}

	if(!empty($cfg['rule_stripbbcode'])) {
		$msg = preg_replace('/\[[^\]]+\]/', '', $msg);
	}
	if(!empty($cfg['rule_collapse'])) {
		$msg = trim(preg_replace('/\s+/u', ' ', $msg));
	}

	$len = intval($cfg['truncate_length']);
	if($len > 0 && mb_strlen($msg, 'UTF-8') > $len) {
		$msg = mb_substr($msg, 0, $len, 'UTF-8').'...';
	}
	return $msg;
}

/** Build the Telegram sendMessage params for one post row. */
function tgnotify_build($cfg, $row, $base) {
	$subject = trim((string)$row['subject']);
	$author  = !empty($row['anonymous']) ? $cfg['anon_name'] : trim((string)$row['author']);
	if($author === '') {
		$author = $cfg['anon_name'];
	}
	$msg = tgnotify_transform($row['message'], $cfg);

	if(!empty($row['first'])) {
		$text = '《'.$subject.'》'."\n".'👤'.$author.'：'.$msg;
		$url  = tgnotify_thread_url($base, $row['tid']);
		$btn  = $cfg['btn_thread'] !== '' ? $cfg['btn_thread'] : '查看新贴';
	} else {
		$text = '《'.$subject.'》'."\n".'💬'.$author.'：'.$msg;
		$url  = tgnotify_reply_url($base, $row['tid'], $row['pid']);
		$btn  = $cfg['btn_reply'] !== '' ? $cfg['btn_reply'] : '查看回复';
	}

	$data = array(
		'chat_id' => (string)$cfg['channel_id'],
		'text'    => $text,
		'disable_web_page_preview' => !empty($cfg['disable_preview']) ? 'true' : 'false',
	);
	if($url !== '' && tgnotify_is_public_url($url)) {       // Telegram rejects non-public button URLs
		$data['reply_markup'] = json_encode(
			array('inline_keyboard' => array(array(array('text' => $btn, 'url' => $url)))),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}
	return $data;
}

/** Append a line to data/log/tgnotify.log (used by debug mode and skip diagnostics). */
function tgnotify_debug($line) {
	@file_put_contents(DISCUZ_ROOT.'./data/log/tgnotify.log',
		'['.dgmdate(TIMESTAMP, 'Y-m-d H:i:s').'] '.$line."\n", FILE_APPEND);
}

/**
 * POST one message to Telegram with native cURL (Discuz's dfsockopen wrapper silently swallows
 * failures and is fragile with larger payloads). Telegram accepts application/x-www-form-urlencoded,
 * so reply_markup travels as a JSON string field. Network errors (timeouts, the intermittent TLS
 * drops common on censored/throttled networks) are retried; an API-level error (e.g. bad chat id)
 * is returned immediately since retrying won't help. Honors an optional API-base override.
 * Returns array('ok'=>bool, 'desc'=>string). In debug mode the payload is logged and nothing is sent.
 */
function tgnotify_send($cfg, $data) {
	$token = trim((string)$cfg['bot_token']);
	if($token === '') {
		return array('ok' => false, 'desc' => '未配置 Bot Token / no bot token');
	}
	if(!empty($cfg['debug_log'])) {
		tgnotify_debug('SEND '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		return array('ok' => true, 'desc' => 'debug-logged (not sent)');
	}
	if(!function_exists('curl_init')) {
		return array('ok' => false, 'desc' => 'PHP cURL 扩展不可用 / cURL extension missing');
	}
	$apibase = trim((string)$cfg['api_base']);
	$apibase = $apibase !== '' ? rtrim($apibase, '/') : 'https://api.telegram.org';
	$url     = $apibase.'/bot'.$token.'/sendMessage';
	$tries   = max(1, min(6, intval($cfg['send_retries'])));
	$post    = http_build_query($data);

	$lasterr = 'send failed';
	for($i = 0; $i < $tries; $i++) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
		curl_setopt($ch, CURLOPT_TIMEOUT, 12);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
		curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
		$body  = curl_exec($ch);
		$errno = curl_errno($ch);
		$errstr = curl_error($ch);
		$http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if($errno) {                                   // transport error (timeout / TLS drop) — retry
			$lasterr = 'curl#'.$errno.' '.$errstr;
			usleep(300000);
			continue;
		}
		$json = @json_decode((string)$body, true);
		if(is_array($json) && array_key_exists('ok', $json)) {
			if($json['ok']) {
				return array('ok' => true, 'desc' => 'ok');
			}
			$d = isset($json['description']) ? $json['description'] : 'error';
			// Safety net: if Telegram rejects the inline-button URL, drop the button and resend once
			// (the message text still gets through even with a misconfigured / non-public domain).
			if(isset($data['reply_markup']) && stripos($d, 'button') !== false && stripos($d, 'url') !== false) {
				unset($data['reply_markup']);
				$post = http_build_query($data);
				$i = -1;          // restart the attempt loop without the button
				continue;
			}
			if(isset($json['error_code'])) {
				$d = '['.$json['error_code'].'] '.$d;
			}
			return array('ok' => false, 'desc' => $d);   // deterministic API error — don't retry
		}
		$lasterr = 'HTTP '.$http.($body === '' ? ' (empty response)' : ' (non-JSON response)');
		usleep(300000);
	}
	return array('ok' => false, 'desc' => $lasterr.'（已重试 '.$tries.' 次 / after '.$tries.' tries）');
}

/**
 * Throttle + concurrency gate around the drain. Called from the global_footer hook on every page.
 * A cheap single-row read enforces the drain interval; discuz_process gives mutual exclusion so
 * concurrent page renders can't double-drain.
 */
function tgnotify_tick($cfg = null) {
	global $_G;
	if($cfg === null) {
		$cfg = tgnotify_config();
	}
	if(empty($cfg['enabled']) || trim($cfg['bot_token']) === '' || trim($cfg['channel_id']) === '' || empty($cfg['fids'])) {
		return;
	}
	$interval = max(1, intval($cfg['drain_interval']));
	$state = tgnotify_state();
	if(TIMESTAMP - intval($state['last_scan']) < $interval) {
		return;
	}
	if(class_exists('discuz_process') && discuz_process::islocked('tgnotify_drain', 60, 1)) {
		return;     // another render is draining right now
	}
	tgnotify_state_update(array('last_scan' => TIMESTAMP));
	tgnotify_drain($cfg);
}

/**
 * Scan the post table from the stored cursor and push matching posts. The cursor always advances past
 * processed (sent or filtered-out) pids so progress is guaranteed; a transient send failure stops the
 * batch and is retried next tick, and a message that keeps failing is skipped after retry_max tries so
 * the queue can't get stuck. Posts held for moderation and approved later are not back-filled.
 */
function tgnotify_drain($cfg) {
	$state    = tgnotify_state();
	$cursor   = intval($state['last_pid']);
	$batch    = max(1, min(50, intval($cfg['batch_size'])));
	$fids     = array_map('intval', (array)$cfg['fids']);
	if(!$fids) {
		return;
	}
	$maxread  = intval($cfg['max_readperm']);
	$retrymax = max(1, intval($cfg['retry_max']));

	$ptable = DB::table(tgnotify_posttable());
	$ttable = DB::table('forum_thread');
	$rows = DB::fetch_all(
		"SELECT p.pid, p.tid, p.first, p.author, p.anonymous, p.invisible, p.message,
		        t.subject, t.fid, t.readperm, t.displayorder
		 FROM $ptable p LEFT JOIN $ttable t ON t.tid = p.tid
		 WHERE p.pid > %d ORDER BY p.pid ASC LIMIT %d",
		array($cursor, $batch)
	);
	if(!$rows) {
		return;
	}

	$base       = tgnotify_base_url($cfg);
	$newcursor  = $cursor;
	$fail_pid   = intval($state['fail_pid']);
	$fail_count = intval($state['fail_count']);
	$sent = 0; $failed = 0; $lasterror = '';

	foreach($rows as $r) {
		$pid = intval($r['pid']);

		$skip = $r['subject'] === null                                       // parent thread gone
			|| intval($r['invisible']) !== 0                                 // pending / deleted post
			|| intval($r['displayorder']) < 0                                // moderated / recycled thread
			|| !in_array(intval($r['fid']), $fids, true)                     // forum not selected
			|| ($maxread > 0 && intval($r['readperm']) >= $maxread)          // read-permission ceiling
			|| (!empty($r['first']) && empty($cfg['send_thread']))           // threads disabled
			|| (empty($r['first']) && empty($cfg['send_reply']));            // replies disabled

		if($skip) {
			$newcursor = $pid;
			continue;
		}

		$res = tgnotify_send($cfg, tgnotify_build($cfg, $r, $base));
		if($res['ok']) {
			$sent++;
			$newcursor  = $pid;
			$fail_pid   = 0;
			$fail_count = 0;
			continue;
		}

		// send failed
		$failed++;
		$lasterror  = $res['desc'];
		$fail_count = ($fail_pid === $pid) ? $fail_count + 1 : 1;
		$fail_pid   = $pid;
		if($fail_count >= $retrymax) {
			tgnotify_debug('SKIP pid '.$pid.' after '.$fail_count.' tries: '.$res['desc']);
			$lasterror  = '已跳过 pid '.$pid.'（'.$fail_count.' 次失败）: '.$res['desc'];
			$newcursor  = $pid;     // give up, move past so the queue isn't stuck
			$fail_pid   = 0;
			$fail_count = 0;
			continue;
		}
		break;     // transient: stop here, retry from this pid next tick
	}

	$upd = array(
		'last_pid'   => $newcursor,
		'fail_pid'   => $fail_pid,
		'fail_count' => $fail_count,
	);
	if($lasterror !== '') {
		$upd['lasterror'] = mb_substr($lasterror, 0, 250, 'UTF-8');
	}
	if($sent) {
		$upd['sent']     = intval($state['sent']) + $sent;
		$upd['lastsend'] = TIMESTAMP;
	}
	if($failed) {
		$upd['failed'] = intval($state['failed']) + $failed;
	}
	tgnotify_state_update($upd);
}
