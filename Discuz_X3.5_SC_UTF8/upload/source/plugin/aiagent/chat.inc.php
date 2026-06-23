<?php
/**
 * JSON agent endpoint — plugin.php?id=aiagent:chat (dispatched by plugin.php's directory fallback;
 * no XML module entry needed). The chat UI in admincp.inc.php POSTs here with a JSON body.
 *
 * Why plugin.php and not admin.php: admin.php wraps module output in admin nav chrome, so it can't
 * return clean JSON. plugin.php can. plugin.php does NOT enforce admin for this module, so we gate
 * every request here (founder + plugin-context formhash + same-host referer + POST).
 *
 * Request body (application/json): { mode, token, messages?, sql? }
 *   mode 'chat'          -> run the agentic tool-calling loop, return { reply, proposals[] }
 *   mode 'confirm_write' -> execute a human-approved write, return { affected }
 *   mode 'run_sql'       -> run a read-only query (the no-tools fallback's Run button), return { result }
 */
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

require_once DISCUZ_ROOT.'./source/plugin/aiagent/function_aiagent.php';

function aiagent_json_out($arr) {
	@header('Content-Type: application/json; charset=utf-8');
	echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit();
}

// Read the raw JSON body (NOT $_POST — Discuz would addslashes form fields and corrupt the JSON).
$body = json_decode((string)@file_get_contents('php://input'), true);
if(!is_array($body)) {
	$body = array();
}
$mode  = isset($body['mode']) ? (string)$body['mode'] : 'chat';
$token = isset($body['token']) ? (string)$body['token'] : '';

$gate = aiagent_verify_request($token);
if($gate !== '') {
	@header('HTTP/1.1 403 Forbidden');
	aiagent_json_out(array('ok' => false, 'error' => $gate));
}

$cfg = aiagent_config();

/* --- mode: models (populate the settings model picker; works even while disabled) --- */
if($mode === 'models') {
	$res = aiagent_fetch_models($cfg);
	if(isset($res['_error'])) {
		aiagent_json_out(array('ok' => false, 'error' => $res['_error']));
	}
	aiagent_json_out(array('ok' => true, 'models' => $res['models'], 'count' => $res['count']));
}

if(empty($cfg['enabled'])) {
	aiagent_json_out(array('ok' => false, 'error' => 'The AI assistant is disabled in the plugin settings.'));
}

/* --- mode: confirm_write (execute an approved proposal) --------------------- */
if($mode === 'confirm_write') {
	$werr = '';
	$res = aiagent_exec_write(isset($body['sql']) ? (string)$body['sql'] : '', $cfg, $werr);
	if($res === false) {
		aiagent_json_out(array('ok' => false, 'error' => $werr !== '' ? $werr : 'Write failed'));
	}
	aiagent_json_out(array('ok' => true, 'affected' => $res['affected']));
}

/* --- mode: run_sql (the no-tools fallback's one-click Run) ------------------ */
if($mode === 'run_sql') {
	$res = aiagent_run_select(isset($body['sql']) ? (string)$body['sql'] : '', $cfg);
	if(isset($res['error'])) {
		aiagent_json_out(array('ok' => false, 'error' => $res['error']));
	}
	aiagent_json_out(array('ok' => true, 'result' => $res));
}

/* --- mode: chat (the agentic tool-calling loop) ---------------------------- */
if(trim((string)$cfg['api_key']) === '') {
	aiagent_json_out(array('ok' => false, 'error' => 'No OpenRouter API key configured. Open the Settings tab to add one.'));
}

$client = isset($body['messages']) && is_array($body['messages']) ? $body['messages'] : array();
$messages = array(array('role' => 'system', 'content' => aiagent_system_prompt($cfg)));
foreach($client as $m) {
	if(!is_array($m) || !isset($m['role']) || !isset($m['content'])) {
		continue;
	}
	$content = (string)$m['content'];
	if($content === '') {
		continue;
	}
	$messages[] = array('role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $content);
}
if(count($messages) < 2) {
	aiagent_json_out(array('ok' => false, 'error' => 'Empty message'));
}

$tools     = aiagent_tools_spec($cfg);
$maxiters  = max(1, intval($cfg['max_iters']));
$proposals = array();
$reply     = '';
$toolused  = false;
$iter      = 0;

for(; $iter < $maxiters; $iter++) {
	$resp = aiagent_openrouter($cfg, $messages, $tools);
	if(isset($resp['_error'])) {
		$emsg = $resp['_error'];
		// Provider/transient failures survived the retries — guide the admin toward a fix.
		if(stripos($emsg, 'provider returned error') !== false || stripos($emsg, 'after ') !== false || stripos($emsg, 'overload') !== false || stripos($emsg, 'rate') !== false) {
			$emsg .= "\n\n_The selected free model/provider looks busy or rate-limited. Try again, or switch to another 🔧 tool-capable model in **Settings**._";
		}
		aiagent_json_out(array('ok' => false, 'error' => $emsg));
	}
	if(empty($resp['choices'][0]['message'])) {
		// Surface the raw response so the admin can see why (finish_reason, content filter, etc.).
		$snip = json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$snip = function_exists('mb_substr') ? mb_substr($snip, 0, 1200, 'UTF-8') : substr($snip, 0, 1200);
		aiagent_json_out(array('ok' => false, 'error' => "The model returned no message.\n\n```\n".$snip."\n```"));
	}
	$msg = $resp['choices'][0]['message'];

	// Re-append a sanitized copy of the assistant turn (role + content [+ tool_calls]).
	$am = array('role' => 'assistant', 'content' => isset($msg['content']) ? $msg['content'] : '');
	if(!empty($msg['tool_calls'])) {
		$am['tool_calls'] = $msg['tool_calls'];
	}
	$messages[] = $am;

	if(empty($msg['tool_calls'])) {
		$reply = isset($msg['content']) ? (string)$msg['content'] : '';
		break;
	}

	$toolused = true;
	foreach($msg['tool_calls'] as $tc) {
		$fn     = isset($tc['function']['name']) ? $tc['function']['name'] : '';
		$argraw = isset($tc['function']['arguments']) ? $tc['function']['arguments'] : '';
		$args   = is_array($argraw) ? $argraw : json_decode((string)$argraw, true);
		if(!is_array($args)) {
			$args = array();
		}

		if($fn === 'list_tables') {
			$result = aiagent_tool_list_tables($cfg);
		} elseif($fn === 'describe_table') {
			$result = aiagent_tool_describe_table(isset($args['table']) ? $args['table'] : '', $cfg);
		} elseif($fn === 'run_select') {
			$result = aiagent_run_select(isset($args['sql']) ? $args['sql'] : '', $cfg);
		} elseif($fn === 'propose_write') {
			$sql  = isset($args['sql']) ? (string)$args['sql'] : '';
			$why  = isset($args['rationale']) ? (string)$args['rationale'] : '';
			$verr = '';
			if($cfg['write_mode'] !== 'confirm') {
				$result = array('error' => 'Writes are disabled (read-only mode). Tell the admin to enable writes in settings.');
			} elseif(!aiagent_validate_write($sql, $verr)) {
				$result = array('error' => $verr);
			} else {
				$pid = 'w'.$iter.'_'.count($proposals);
				$impact = aiagent_write_impact($sql);
				$proposals[] = array('id' => $pid, 'sql' => aiagent_strip_sql($sql), 'rationale' => $why, 'impact' => $impact);
				$result = array('status' => 'pending_confirmation', 'proposal_id' => $pid, 'impact' => $impact,
					'note' => 'Proposed only. Awaiting the human admin Approve click — do not assume it ran.');
			}
		} else {
			$result = array('error' => 'Unknown tool: '.$fn);
		}

		$messages[] = array(
			'role' => 'tool',
			'tool_call_id' => isset($tc['id']) ? $tc['id'] : '',
			'content' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		);
	}
}

if($reply === '') {
	$reply = $iter >= $maxiters
		? '_(Reached the tool-call limit for this turn. Ask me to continue if you need more.)_'
		: '';
}

aiagent_json_out(array('ok' => true, 'reply' => $reply, 'proposals' => $proposals, 'tool_used' => $toolused));
