<?php
/**
 * Shared helpers for the "aiagent" plugin — chat with an AI (OpenRouter) inside the admin panel that
 * can read and (with explicit approval) modify the forum database.
 *
 * Required by admincp.inc.php (settings + chat UI) and chat.inc.php (the JSON agent endpoint).
 *
 * Security model: the chat endpoint lives at plugin.php?id=aiagent:chat (so it can return clean JSON,
 * unlike admin.php which wraps output in admin chrome). plugin.php does NOT enforce admin for it, so
 * every request is gated here by aiagent_verify_request(): founder-only + a plugin-context formhash
 * (NOT the IN_ADMINCP-salted one) + same-host referer + POST. The AI never touches the DB directly —
 * reads go through aiagent_run_select() (read-only, capped) and writes go through a propose -> human
 * Approve -> aiagent_exec_write() flow, with every action written to the pre_aiagent_log audit table.
 */
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

/** Settings (stored serialized in common_setting['aiagent']) merged with defaults. */
function aiagent_config() {
	global $_G;
	$defaults = array(
		'enabled'          => 1,
		'api_key'          => '',
		'model'            => 'meta-llama/llama-3.3-70b-instruct:free',
		'base_url'         => 'https://openrouter.ai/api/v1',
		'write_mode'       => 'off',   // 'off' = read-only | 'confirm' = writes allowed with human Approve
		'max_rows'         => 50,      // cap rows returned to the model per SELECT
		'max_result_bytes' => 12000,   // cap JSON bytes of a tool result (token control)
		'max_iters'        => 6,       // cap tool-calling round trips per turn
		'http_timeout'     => 45,      // seconds for an OpenRouter request
	);
	$cfg = isset($_G['setting']['aiagent']) ? $_G['setting']['aiagent'] : '';
	if(is_string($cfg)) {
		$cfg = dunserialize($cfg);
	}
	if(!is_array($cfg)) {
		$cfg = array();
	}
	return array_merge($defaults, $cfg);
}

/** Absolute base URL (scheme://host) for this request — used for the optional HTTP-Referer header. */
function aiagent_site_url() {
	global $_G;
	$scheme = !empty($_G['scheme']) ? $_G['scheme'] : 'http';
	$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
	return $scheme.'://'.$host;
}

/**
 * The plugin-context formhash (the same value formhash() returns OUTSIDE the admin CP). We compute it
 * explicitly because the chat UI is printed inside admin.php (where formhash() is salted with an
 * admin-only string) but verified inside plugin.php (where it is NOT) — so the two would never match
 * unless we pin the unsalted form here. See formhash() in source/function/function_core.php.
 */
function aiagent_plugin_formhash() {
	global $_G;
	return substr(md5(substr($_G['timestamp'], 0, -7).$_G['username'].$_G['uid'].$_G['authkey']), 8, 8);
}

/** True only for the forum founder (uid + groupid==1 + adminid==1, honoring the founder whitelist). */
function aiagent_is_founder() {
	global $_G;
	if(empty($_G['uid']) || $_G['groupid'] != 1 || $_G['adminid'] != 1) {
		return false;
	}
	$founders = isset($_G['config']['admincp']['founder']) ? trim((string)$_G['config']['admincp']['founder']) : '';
	if($founders !== '') {
		$ok = false;
		foreach(explode(',', $founders) as $one) {
			$one = trim($one);
			if($one !== '' && ((string)$one === (string)$_G['uid'] || strcasecmp($one, (string)$_G['username']) === 0)) {
				$ok = true;
				break;
			}
		}
		if(!$ok) {
			return false;
		}
	}
	return true;
}

/** Gate a chat.inc.php request. Returns '' if allowed, else a human-readable reason. */
function aiagent_verify_request($token) {
	if(!isset($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
		return 'POST required';
	}
	if(!aiagent_is_founder()) {
		return 'Forbidden: founder admin only';
	}
	if(!hash_equals(aiagent_plugin_formhash(), (string)$token)) {
		return 'Invalid or expired session token — reload the admin page';
	}
	// Same-host referer check (mirrors core submitcheck()).
	$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
	if($referer !== '') {
		$rhost = parse_url($referer, PHP_URL_HOST);
		$host  = preg_replace('/:\d+$/', '', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');
		if($rhost !== null && $host !== '' && strcasecmp($rhost, $host) !== 0) {
			return 'Cross-site request blocked';
		}
	}
	return '';
}

/* ----------------------------------------------------------------------------
 * OpenRouter call
 * ------------------------------------------------------------------------- */

/**
 * Native cURL POST to the OpenRouter (OpenAI-compatible) chat completions API. Returns the decoded
 * response array, or array('_error' => '...') on any transport/API failure.
 */
function aiagent_openrouter($cfg, $messages, $tools = array()) {
	if(!function_exists('curl_init')) {
		return array('_error' => 'PHP cURL extension is not available on the server.');
	}
	$payload = array(
		'model'       => $cfg['model'],
		'messages'    => $messages,
		'temperature' => 0.2,
	);
	if(!empty($tools)) {
		$payload['tools'] = $tools;
		$payload['tool_choice'] = 'auto';
	}
	$base = trim((string)$cfg['base_url']);
	$base = $base !== '' ? rtrim($base, '/') : 'https://openrouter.ai/api/v1';

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $base.'/chat/completions');
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(
		'Authorization: Bearer '.$cfg['api_key'],
		'Content-Type: application/json',
		'HTTP-Referer: '.aiagent_site_url(),
		'X-Title: Discuz AI Agent',
	));
	curl_setopt($ch, CURLOPT_TIMEOUT, max(15, intval($cfg['http_timeout'])));
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
	$body  = curl_exec($ch);
	$errno = curl_errno($ch);
	$errstr= curl_error($ch);
	$http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	if($errno) {
		return array('_error' => 'Network error (curl#'.$errno.'): '.$errstr);
	}
	$data = json_decode((string)$body, true);
	if(!is_array($data)) {
		return array('_error' => 'Invalid response from the API (HTTP '.$http.').');
	}
	if(isset($data['error'])) {
		$em = is_array($data['error']) && isset($data['error']['message']) ? $data['error']['message']
			: (is_string($data['error']) ? $data['error'] : 'unknown API error');
		return array('_error' => 'API error: '.$em);
	}
	$data['_http'] = $http;
	return $data;
}

/**
 * Fetch the catalog from OpenRouter's (public) /models endpoint and return only the FREE models,
 * each flagged with whether it supports tool/function calling. Used to populate the model picker in
 * settings. Returns array('models'=>[...], 'count'=>N) or array('_error'=>'...').
 */
function aiagent_fetch_models($cfg) {
	if(!function_exists('curl_init')) {
		return array('_error' => 'PHP cURL extension is not available on the server.');
	}
	$base = trim((string)$cfg['base_url']);
	$base = $base !== '' ? rtrim($base, '/') : 'https://openrouter.ai/api/v1';

	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $base.'/models');
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	$headers = array('Content-Type: application/json');
	if(trim((string)$cfg['api_key']) !== '') {
		$headers[] = 'Authorization: Bearer '.$cfg['api_key']; // optional — /models is public
	}
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($ch, CURLOPT_TIMEOUT, max(15, intval($cfg['http_timeout'])));
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
	$body  = curl_exec($ch);
	$errno = curl_errno($ch);
	$errstr= curl_error($ch);
	$http  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	if($errno) {
		return array('_error' => 'Network error (curl#'.$errno.'): '.$errstr);
	}
	$data = json_decode((string)$body, true);
	if(!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
		return array('_error' => 'Unexpected response from the models API (HTTP '.$http.').');
	}
	$out = array();
	foreach($data['data'] as $m) {
		if(empty($m['id'])) {
			continue;
		}
		$pricing    = isset($m['pricing']) && is_array($m['pricing']) ? $m['pricing'] : array();
		$prompt     = isset($pricing['prompt']) ? (float)$pricing['prompt'] : 0.0;
		$completion = isset($pricing['completion']) ? (float)$pricing['completion'] : 0.0;
		if($prompt != 0.0 || $completion != 0.0) {
			continue; // free models only (both prompt and completion priced at 0)
		}
		$sp = isset($m['supported_parameters']) && is_array($m['supported_parameters']) ? $m['supported_parameters'] : array();
		$out[] = array(
			'id'    => (string)$m['id'],
			'name'  => isset($m['name']) ? (string)$m['name'] : (string)$m['id'],
			'tools' => (in_array('tools', $sp, true) || in_array('tool_choice', $sp, true)),
			'ctx'   => isset($m['context_length']) ? intval($m['context_length']) : 0,
		);
	}
	// Tool-capable first (recommended for DB Q&A), then alphabetical by name.
	usort($out, function($a, $b) {
		if($a['tools'] !== $b['tools']) { return $a['tools'] ? -1 : 1; }
		return strcasecmp($a['name'], $b['name']);
	});
	return array('models' => $out, 'count' => count($out));
}

/* ----------------------------------------------------------------------------
 * Tool definitions + system prompt
 * ------------------------------------------------------------------------- */

/** OpenAI-style function/tool specs offered to the model (propose_write only when writes are enabled). */
function aiagent_tools_spec($cfg) {
	$noargs = new stdClass(); // encodes to {} so "properties" is a valid (empty) object
	$tools = array(
		array('type' => 'function', 'function' => array(
			'name' => 'list_tables',
			'description' => 'List all database table names (each already includes the pre_ prefix).',
			'parameters' => array('type' => 'object', 'properties' => $noargs),
		)),
		array('type' => 'function', 'function' => array(
			'name' => 'describe_table',
			'description' => 'Return the columns (name, type, key) of one table. Call this before querying a table whose columns you are unsure about.',
			'parameters' => array('type' => 'object', 'properties' => array(
				'table' => array('type' => 'string', 'description' => 'Table name including prefix, e.g. pre_common_member'),
			), 'required' => array('table')),
		)),
		array('type' => 'function', 'function' => array(
			'name' => 'run_select',
			'description' => 'Run ONE read-only SQL query (SELECT / SHOW / DESCRIBE / EXPLAIN) and get rows back. Use the pre_ prefix. A LIMIT is enforced automatically.',
			'parameters' => array('type' => 'object', 'properties' => array(
				'sql' => array('type' => 'string', 'description' => 'A single read-only SQL statement.'),
			), 'required' => array('sql')),
		)),
	);
	if($cfg['write_mode'] === 'confirm') {
		$tools[] = array('type' => 'function', 'function' => array(
			'name' => 'propose_write',
			'description' => 'Propose ONE data-changing statement (INSERT/UPDATE/DELETE/REPLACE) for the human admin to approve. It is NOT executed until the admin clicks Approve. DDL (DROP/ALTER/TRUNCATE/CREATE) is forbidden. UPDATE/DELETE must include a WHERE clause.',
			'parameters' => array('type' => 'object', 'properties' => array(
				'sql' => array('type' => 'string', 'description' => 'A single INSERT/UPDATE/DELETE/REPLACE statement.'),
				'rationale' => array('type' => 'string', 'description' => 'One short sentence: what this changes and why.'),
			), 'required' => array('sql', 'rationale')),
		));
	}
	return $tools;
}

/** The system prompt that anchors the model: schema hints, the safety contract, and the fallback rule. */
function aiagent_system_prompt($cfg) {
	$writes = $cfg['write_mode'] === 'confirm';
	$p  = "You are an AI assistant embedded in the admin control panel of a Discuz! X3.5 forum. "
		. "You help the site founder inspect and manage the forum's MySQL/MariaDB database.\n\n";
	$p .= "DATABASE\n"
		. "- Every table name uses the prefix `pre_` (e.g. pre_common_member). Always include the prefix.\n"
		. "- Handy core tables: pre_common_member (uid, username, email, regdate, groupid, status), "
		. "pre_common_member_count (uid, posts, threads, digestposts), pre_forum_thread (tid, fid, subject, authorid, author, views, replies, dateline, displayorder), "
		. "pre_forum_post / pre_forum_post_N (pid, tid, author, message, dateline), pre_forum_forum (fid, name, threads, posts), "
		. "pre_common_setting (skey, svalue), pre_aiagent_log (this plugin's audit trail).\n"
		. "- Use describe_table before querying a table whose columns you don't know.\n\n";
	$p .= "TOOLS\n"
		. "- list_tables, describe_table and run_select run immediately and return real data.\n";
	if($writes) {
		$p .= "- propose_write proposes ONE data-changing statement. It does NOT run — the human admin must click Approve. "
			. "UPDATE/DELETE must include a WHERE clause. Never claim a change succeeded until you receive a tool result confirming it ran.\n";
	} else {
		$p .= "- Writing to the database is DISABLED (read-only mode). Do not attempt to modify data. If asked to change something, "
			. "explain that read-only mode is on and the admin can enable writes in the plugin settings.\n";
	}
	$p .= "\nGUIDELINES\n"
		. "- Use the tools to fetch real data instead of guessing. Keep SELECTs targeted; a LIMIT is enforced.\n"
		. "- If function/tool calling is unavailable to you, instead reply with the exact SQL you want in a single ```sql code block and ask the admin to run it.\n"
		. "- Be concise. Use small Markdown tables for result sets. Reply in the same language the admin writes in.";
	return $p;
}

/* ----------------------------------------------------------------------------
 * SQL guardrails
 * ------------------------------------------------------------------------- */

/** Trim whitespace and a single trailing semicolon. */
function aiagent_strip_sql($sql) {
	return rtrim(trim((string)$sql), "; \t\n\r");
}

/** First SQL keyword (uppercased), skipping leading block/line comments. */
function aiagent_first_keyword($sql) {
	$s = ltrim((string)$sql);
	while($s !== '') {
		if(substr($s, 0, 2) === '/*') {
			$end = strpos($s, '*/');
			if($end === false) { break; }
			$s = ltrim(substr($s, $end + 2));
		} elseif(substr($s, 0, 2) === '--') {
			$nl = strpos($s, "\n");
			if($nl === false) { $s = ''; break; }
			$s = ltrim(substr($s, $nl + 1));
		} else {
			break;
		}
	}
	return preg_match('/^([a-zA-Z]+)/', $s, $m) ? strtoupper($m[1]) : '';
}

/** True if more than one statement is present (a ';' outside quotes, after the trailing one is stripped). */
function aiagent_has_multiple_statements($sql) {
	$sql = aiagent_strip_sql($sql);
	$len = strlen($sql);
	$q = '';
	for($i = 0; $i < $len; $i++) {
		$c = $sql[$i];
		if($q !== '') {
			if($c === $q && ($i === 0 || $sql[$i - 1] !== '\\')) { $q = ''; }
		} elseif($c === "'" || $c === '"' || $c === '`') {
			$q = $c;
		} elseif($c === ';') {
			return true;
		}
	}
	return false;
}

/** Classify a statement: array('class' => read|write|ddl|other, 'kw' => KEYWORD). */
function aiagent_classify_sql($sql) {
	$kw = aiagent_first_keyword($sql);
	if(in_array($kw, array('SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'), true)) { return array('class' => 'read', 'kw' => $kw); }
	if(in_array($kw, array('INSERT', 'UPDATE', 'DELETE', 'REPLACE'), true))         { return array('class' => 'write', 'kw' => $kw); }
	if(in_array($kw, array('DROP', 'ALTER', 'TRUNCATE', 'CREATE', 'RENAME', 'GRANT', 'REVOKE'), true)) { return array('class' => 'ddl', 'kw' => $kw); }
	return array('class' => 'other', 'kw' => $kw);
}

/** Validate a read-only statement. Returns true, or false with $err set. */
function aiagent_validate_select($sql, &$err) {
	$sql = aiagent_strip_sql($sql);
	if($sql === '') { $err = 'Empty query'; return false; }
	if(aiagent_has_multiple_statements($sql)) { $err = 'Multiple statements are not allowed'; return false; }
	if(aiagent_classify_sql($sql)['class'] !== 'read') { $err = 'Only read-only SELECT/SHOW/DESCRIBE/EXPLAIN is allowed here'; return false; }
	if(preg_match('/\binto\s+(out|dump)file\b/i', $sql)) { $err = 'File export (INTO OUTFILE/DUMPFILE) is not allowed'; return false; }
	$err = '';
	return true;
}

/** Validate a single data-changing statement for the propose/confirm flow. */
function aiagent_validate_write($sql, &$err) {
	$sql = aiagent_strip_sql($sql);
	if($sql === '') { $err = 'Empty statement'; return false; }
	if(aiagent_has_multiple_statements($sql)) { $err = 'Multiple statements are not allowed'; return false; }
	$c = aiagent_classify_sql($sql);
	if($c['class'] === 'ddl')   { $err = 'Schema changes (DROP/ALTER/TRUNCATE/CREATE/RENAME/GRANT) are not allowed'; return false; }
	if($c['class'] !== 'write') { $err = 'Only INSERT/UPDATE/DELETE/REPLACE statements can be proposed'; return false; }
	if(($c['kw'] === 'UPDATE' || $c['kw'] === 'DELETE') && !preg_match('/\bwhere\b/i', $sql)) {
		$err = 'UPDATE/DELETE must include a WHERE clause (refusing an unbounded change)';
		return false;
	}
	$err = '';
	return true;
}

/** Append a LIMIT to a bare SELECT (SHOW/DESCRIBE/EXPLAIN are left alone). */
function aiagent_apply_limit($sql, $max) {
	$sql = aiagent_strip_sql($sql);
	if(aiagent_first_keyword($sql) === 'SELECT' && !preg_match('/\blimit\s+\d/i', $sql)) {
		$sql .= ' LIMIT '.intval($max);
	}
	return $sql;
}

/* ----------------------------------------------------------------------------
 * Schema + read/write executors
 * ------------------------------------------------------------------------- */

/** All live table names (cached for the request). */
function aiagent_live_tables() {
	static $tables = null;
	if($tables !== null) {
		return $tables;
	}
	$tables = array();
	try {
		$q = DB::query('SHOW TABLES', array(), true);
		while($r = DB::fetch($q)) {
			$tables[] = reset($r);
		}
	} catch(\Throwable $ex) {}
	return $tables;
}

function aiagent_tool_list_tables($cfg) {
	$tables = aiagent_live_tables();
	return array('tables' => $tables, 'count' => count($tables));
}

function aiagent_tool_describe_table($table, $cfg) {
	$table = trim((string)$table);
	if($table === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
		return array('error' => 'Invalid table name');
	}
	$live = aiagent_live_tables();
	if(!in_array($table, $live, true)) {
		$pref = DB::table($table);
		if(in_array($pref, $live, true)) {
			$table = $pref;
		} else {
			return array('error' => 'Unknown table: '.$table);
		}
	}
	try {
		$cols = DB::fetch_all('SHOW COLUMNS FROM `'.$table.'`', array(), '', true);
	} catch(\Throwable $ex) {
		return array('error' => 'describe failed: '.$ex->getMessage());
	}
	$out = array();
	foreach($cols as $c) {
		$out[] = array(
			'field'   => isset($c['Field']) ? $c['Field'] : '',
			'type'    => isset($c['Type']) ? $c['Type'] : '',
			'null'    => isset($c['Null']) ? $c['Null'] : '',
			'key'     => isset($c['Key']) ? $c['Key'] : '',
			'default' => isset($c['Default']) ? $c['Default'] : null,
			'extra'   => isset($c['Extra']) ? $c['Extra'] : '',
		);
	}
	return array('table' => $table, 'columns' => $out);
}

/** Run a guardrailed, row- and byte-capped read-only query. Returns rows or array('error'=>...). */
function aiagent_run_select($sql, $cfg) {
	$err = '';
	if(!aiagent_validate_select($sql, $err)) {
		aiagent_log('select_denied', $sql, 0, 0, 0, $err);
		return array('error' => $err);
	}
	$max   = max(1, intval($cfg['max_rows']));
	$final = aiagent_apply_limit($sql, $max);
	try {
		$rows = DB::fetch_all($final, array(), '', true);
	} catch(\Throwable $ex) {
		aiagent_log('select', $final, 0, 0, 0, $ex->getMessage());
		return array('error' => 'Query failed: '.$ex->getMessage());
	}
	if(!is_array($rows)) {
		$rows = array();
	}
	$truncated = false;
	if(count($rows) > $max) {
		$rows = array_slice($rows, 0, $max);
		$truncated = true;
	}
	$cap = max(2000, intval($cfg['max_result_bytes']));
	while(count($rows) > 1 && strlen(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > $cap) {
		array_pop($rows);
		$truncated = true;
	}
	aiagent_log('select', $final, count($rows), 0, 1, '');
	return array('sql' => $final, 'rowcount' => count($rows), 'rows' => $rows, 'truncated' => $truncated);
}

/** Best-effort "how many rows will this affect" for a proposed write (null if it can't be derived). */
function aiagent_write_impact($sql) {
	$sql = aiagent_strip_sql($sql);
	$kw  = aiagent_first_keyword($sql);
	if($kw === 'INSERT' || $kw === 'REPLACE') {
		return array('rows' => 1, 'note' => 'insert');
	}
	$countsql = '';
	if($kw === 'DELETE' && preg_match('/^\s*DELETE\s+FROM\s+(`?[A-Za-z0-9_\.]+`?)\s+WHERE\s+(.+)$/is', $sql, $m)) {
		$countsql = 'SELECT COUNT(*) FROM '.$m[1].' WHERE '.$m[2];
	} elseif($kw === 'UPDATE' && preg_match('/^\s*UPDATE\s+(`?[A-Za-z0-9_\.]+`?)\s+SET\s+.*\s+WHERE\s+(.+)$/is', $sql, $m)) {
		$countsql = 'SELECT COUNT(*) FROM '.$m[1].' WHERE '.$m[2];
	}
	if($countsql === '') {
		return null;
	}
	$countsql = preg_replace('/\s+ORDER\s+BY\s+.+$/is', '', $countsql);
	$countsql = preg_replace('/\s+LIMIT\s+.+$/is', '', $countsql);
	try {
		return array('rows' => intval(DB::result_first($countsql, array(), true)));
	} catch(\Throwable $ex) {
		return null;
	}
}

/** Execute a human-approved write (re-validated server-side). Returns array('affected'=>N) or false. */
function aiagent_exec_write($sql, $cfg, &$err) {
	$err = '';
	if($cfg['write_mode'] !== 'confirm') {
		$err = 'Writes are disabled (read-only mode)';
		return false;
	}
	if(!aiagent_validate_write($sql, $err)) {
		aiagent_log('write_denied', $sql, 0, 0, 0, $err);
		return false;
	}
	$final = aiagent_strip_sql($sql);
	try {
		DB::query($final, array(), true);
		$affected = DB::affected_rows();
	} catch(\Throwable $ex) {
		$err = $ex->getMessage();
		aiagent_log('write', $final, 0, 0, 0, $err);
		return false;
	}
	aiagent_log('write', $final, 0, intval($affected), 1, '');
	return array('affected' => intval($affected));
}

/** Write one audit row. Never throws (best-effort). */
function aiagent_log($action, $sql, $rowcount, $affected, $status, $error) {
	global $_G;
	try {
		DB::query('INSERT INTO '.DB::table('aiagent_log')
			.' (uid, username, action, sql_text, rowcount, affected, status, error, dateline, ip) '
			.'VALUES (%d, %s, %s, %s, %d, %d, %d, %s, %d, %s)',
			array(
				intval($_G['uid']),
				(string)$_G['username'],
				(string)$action,
				(string)$sql,
				intval($rowcount),
				intval($affected),
				intval($status),
				function_exists('mb_substr') ? mb_substr((string)$error, 0, 250, 'UTF-8') : substr((string)$error, 0, 250),
				TIMESTAMP,
				(string)(isset($_G['clientip']) ? $_G['clientip'] : ''),
			), true);
	} catch(\Throwable $ex) {}
}
