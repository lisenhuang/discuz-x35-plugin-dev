<?php
if(!defined('IN_DISCUZ') || !defined('IN_ADMINCP')) { exit('Access Denied'); }

/**
 * Admin module (type 3) for the "aiagent" plugin — three tabs (?aitab=):
 *   chat     — 💬 the AI chat panel (talks to plugin.php?id=aiagent:chat)
 *   settings — ⚙ API key, model, base URL, write mode, row cap
 *   log      — 🧾 audit trail of every read/write the assistant performed
 * Config is stored serialized in common_setting['aiagent'].
 */
require_once DISCUZ_ROOT.'./source/plugin/aiagent/function_aiagent.php';

$selfurl = 'plugins&operation=config&do='.$pluginid.'&identifier=aiagent&pmod=admincp';
$cpu = function($t) use ($selfurl) { return 'action='.$selfurl.'&aitab='.$t; };
$e = function($v) { return htmlspecialchars((string)$v, ENT_QUOTES); };

// --- save: Connection form (enable, key, model, base URL) ---------------------
if(submitcheck('aiagent_conn')) {
	$raw = (array)dunserialize(C::t('common_setting')->fetch_setting('aiagent'));
	$raw['enabled']  = intval(getgpc('enabled'));
	$raw['model']    = trim((string)getgpc('model'));
	$base            = trim((string)getgpc('base_url'));
	$raw['base_url'] = $base !== '' ? $base : 'https://openrouter.ai/api/v1';
	// API key: only overwrite when a fresh, non-masked value is submitted (blank keeps the stored one).
	$newkey = trim((string)getgpc('api_key'));
	if($newkey !== '' && strpos($newkey, "\xe2\x80\xa2") === false) {
		$raw['api_key'] = $newkey;
	}
	C::t('common_setting')->update_setting('aiagent', $raw);
	updatecache('setting');
	cpmsg('Settings saved / 设置已保存', $cpu('settings'), 'succeed');
}
// --- save: Database access form (write mode, row cap) -------------------------
if(submitcheck('aiagent_db')) {
	$raw = (array)dunserialize(C::t('common_setting')->fetch_setting('aiagent'));
	$wm  = (string)getgpc('write_mode');
	$raw['write_mode'] = in_array($wm, array('off', 'confirm'), true) ? $wm : 'off';
	$raw['max_rows']   = max(1, min(500, intval(getgpc('max_rows'))));
	C::t('common_setting')->update_setting('aiagent', $raw);
	updatecache('setting');
	cpmsg('Settings saved / 设置已保存', $cpu('settings'), 'succeed');
}

$cfg = aiagent_config();

$tab = (string)getgpc('aitab');
if(!in_array($tab, array('chat', 'settings', 'log'), true)) {
	$tab = 'chat';
}

// --- tab bar ------------------------------------------------------------------
$tablabels = array('chat' => '💬 Chat', 'settings' => '⚙ Settings', 'log' => '🧾 Activity log');
$nav = '<div style="margin:12px 0 0;border-bottom:2px solid #d8dce1">';
foreach($tablabels as $k => $label) {
	$on = ($k === $tab);
	$nav .= '<a href="'.ADMINSCRIPT.'?action='.$e($selfurl).'&aitab='.$k.'" style="display:inline-block;padding:8px 18px;margin-right:4px;text-decoration:none;border:1px solid #d8dce1;border-bottom:none;border-radius:7px 7px 0 0;'
		.($on ? 'background:#fff;font-weight:700;color:#222;position:relative;top:2px;' : 'background:#eef1f4;color:#666;').'">'.$label.'</a>';
}
$nav .= '</div>';
echo $nav;

/* ============================================================================
 * CHAT TAB
 * ========================================================================== */
if($tab === 'chat') {
	$configured = trim((string)$cfg['api_key']) !== '';
	$modeBadge  = $cfg['write_mode'] === 'confirm'
		? '<span class="ai-badge ai-badge-warn">✏️ Writes need approval</span>'
		: '<span class="ai-badge ai-badge-ok">🔒 Read-only</span>';
	$modelBadge = '<span class="ai-badge">'.$e($cfg['model']).'</span>';

	// scoped styles
	echo <<<'CSS'
<style>
#aiagent-app{--ai-accent:#6366f1;--ai-accent2:#8b5cf6;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif;color:#1f2430;margin:14px 0;}
#aiagent-app *{box-sizing:border-box;}
#aiagent-app .ai-card{background:#fff;border:1px solid #e3e7ee;border-radius:16px;overflow:hidden;box-shadow:0 8px 30px rgba(40,50,80,.08);}
#aiagent-app .ai-head{display:flex;align-items:center;gap:12px;padding:14px 18px;background:linear-gradient(120deg,var(--ai-accent),var(--ai-accent2));color:#fff;}
#aiagent-app .ai-head .ai-logo{width:34px;height:34px;border-radius:10px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:18px;}
#aiagent-app .ai-head h2{margin:0;font-size:16px;font-weight:700;line-height:1.2;}
#aiagent-app .ai-head .ai-sub{font-size:12px;opacity:.85;margin-top:1px;}
#aiagent-app .ai-head .ai-spacer{flex:1;}
#aiagent-app .ai-badge{display:inline-block;font-size:11px;font-weight:600;padding:3px 9px;border-radius:20px;background:rgba(255,255,255,.2);color:#fff;margin-left:6px;vertical-align:middle;white-space:nowrap;}
#aiagent-app .ai-badge-ok{background:rgba(16,185,129,.35);}
#aiagent-app .ai-badge-warn{background:rgba(245,158,11,.4);}
#aiagent-app .ai-clear{background:rgba(255,255,255,.15);border:0;color:#fff;font-size:12px;padding:6px 12px;border-radius:8px;cursor:pointer;}
#aiagent-app .ai-clear:hover{background:rgba(255,255,255,.28);}
#aiagent-app .ai-msgs{height:480px;overflow-y:auto;padding:20px;background:#f6f7fb;}
#aiagent-app .ai-row{display:flex;gap:10px;margin-bottom:16px;align-items:flex-start;}
#aiagent-app .ai-row.ai-user{flex-direction:row-reverse;}
#aiagent-app .ai-av{width:32px;height:32px;border-radius:50%;flex:0 0 32px;display:flex;align-items:center;justify-content:center;font-size:17px;background:#fff;border:1px solid #e3e7ee;}
#aiagent-app .ai-user .ai-av{background:linear-gradient(120deg,var(--ai-accent),var(--ai-accent2));border:0;}
#aiagent-app .ai-bub{max-width:78%;padding:11px 15px;border-radius:14px;background:#fff;border:1px solid #e6e9f0;line-height:1.6;font-size:14px;box-shadow:0 1px 2px rgba(0,0,0,.03);}
#aiagent-app .ai-user .ai-bub{background:linear-gradient(120deg,var(--ai-accent),var(--ai-accent2));color:#fff;border:0;}
#aiagent-app .ai-bub p{margin:0 0 8px;}
#aiagent-app .ai-bub p:last-child{margin-bottom:0;}
#aiagent-app .ai-bub .ai-ic{background:rgba(99,102,241,.1);color:#4338ca;padding:1px 6px;border-radius:5px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;}
#aiagent-app .ai-user .ai-bub .ai-ic{background:rgba(255,255,255,.25);color:#fff;}
#aiagent-app .ai-code{background:#0f172a;color:#e2e8f0;padding:12px 14px;border-radius:10px;overflow-x:auto;margin:8px 0;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;line-height:1.5;}
#aiagent-app .ai-code code{background:none;color:inherit;padding:0;font-family:inherit;}
#aiagent-app .ai-tbl{border-collapse:collapse;width:100%;margin:8px 0;font-size:13px;background:#fff;}
#aiagent-app .ai-tbl th,#aiagent-app .ai-tbl td{border:1px solid #e3e7ee;padding:6px 10px;text-align:left;color:#1f2430;}
#aiagent-app .ai-tbl th{background:#f1f3f9;font-weight:600;}
#aiagent-app .ai-tbl tr:nth-child(even) td{background:#fafbff;}
#aiagent-app .ai-ul{margin:6px 0;padding-left:20px;}
#aiagent-app .ai-result{margin-top:10px;border-top:1px dashed #d8dce1;padding-top:8px;}
#aiagent-app .ai-action{margin-top:8px;}
#aiagent-app .ai-muted{color:#8a90a2;font-size:12px;}
#aiagent-app .ai-err{color:#dc2626;}
#aiagent-app .ai-ok{color:#059669;font-weight:600;}
#aiagent-app .ai-btn{border:0;border-radius:9px;padding:8px 14px;font-size:13px;font-weight:600;cursor:pointer;background:linear-gradient(120deg,var(--ai-accent),var(--ai-accent2));color:#fff;}
#aiagent-app .ai-btn:hover{filter:brightness(1.06);}
#aiagent-app .ai-btn:disabled{opacity:.5;cursor:default;filter:none;}
#aiagent-app .ai-ghost{background:#eef0f6;color:#555;}
#aiagent-app .ai-prop{border:1px solid #f0c98a;background:#fffaf0;max-width:88%;}
#aiagent-app .ai-prop-h{font-weight:700;color:#b45309;margin-bottom:4px;}
#aiagent-app .ai-impact{font-weight:600;font-size:12px;color:#92400e;background:#fde68a;padding:2px 8px;border-radius:12px;margin-left:6px;}
#aiagent-app .ai-prop-r{color:#5b4a2a;font-size:13px;margin-bottom:4px;}
#aiagent-app .ai-prop-btns{display:flex;gap:8px;margin-top:8px;}
#aiagent-app .ai-prop-status{margin-top:6px;}
#aiagent-app .ai-foot{display:flex;gap:10px;padding:14px;border-top:1px solid #eceef4;background:#fff;align-items:flex-end;}
#aiagent-app textarea.ai-input{flex:1;resize:none;border:1px solid #d8dce1;border-radius:12px;padding:11px 14px;font-size:14px;font-family:inherit;line-height:1.5;max-height:160px;outline:none;}
#aiagent-app textarea.ai-input:focus{border-color:var(--ai-accent);box-shadow:0 0 0 3px rgba(99,102,241,.15);}
#aiagent-app .ai-send{flex:0 0 auto;align-self:stretch;padding:0 20px;border:0;border-radius:12px;background:linear-gradient(120deg,var(--ai-accent),var(--ai-accent2));color:#fff;font-size:14px;font-weight:700;cursor:pointer;}
#aiagent-app .ai-send:disabled{opacity:.5;cursor:default;}
#aiagent-app .ai-welcome{text-align:center;padding:40px 20px;color:#6b7185;}
#aiagent-app .ai-welcome-i{font-size:42px;}
#aiagent-app .ai-welcome-t{font-size:17px;font-weight:700;color:#2b3040;margin-top:8px;}
#aiagent-app .ai-welcome-s{font-size:13px;margin-top:6px;max-width:460px;margin-left:auto;margin-right:auto;line-height:1.6;}
#aiagent-app .ai-chips{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-top:18px;}
#aiagent-app .ai-chip{background:#fff;border:1px solid #dfe3ec;border-radius:20px;padding:8px 14px;font-size:13px;color:#444;cursor:pointer;}
#aiagent-app .ai-chip:hover{border-color:var(--ai-accent);color:var(--ai-accent);}
#aiagent-app .ai-dots i{display:inline-block;width:7px;height:7px;margin:0 2px;border-radius:50%;background:#b7bcd0;animation:aiblink 1.2s infinite both;}
#aiagent-app .ai-dots i:nth-child(2){animation-delay:.2s;}
#aiagent-app .ai-dots i:nth-child(3){animation-delay:.4s;}
@keyframes aiblink{0%,80%,100%{opacity:.3;}40%{opacity:1;}}
#aiagent-app .ai-warn-banner{margin:0;padding:12px 18px;background:#fff7ed;border-bottom:1px solid #fed7aa;color:#9a3412;font-size:13px;}
#aiagent-app .ai-warn-banner a{color:#b45309;font-weight:600;}
</style>
CSS;

	// dynamic config for the JS
	echo '<script>window.AIAGENT='.json_encode(array(
		'endpoint'   => 'plugin.php?id=aiagent:chat',
		'token'      => aiagent_plugin_formhash(),
		'writeMode'  => $cfg['write_mode'],
		'model'      => $cfg['model'],
		'configured' => $configured,
	), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).';</script>';

	// shell
	$banner = $configured ? '' :
		'<div class="ai-warn-banner">⚠️ No OpenRouter API key configured yet. Open the <a href="'.ADMINSCRIPT.'?action='.$e($selfurl).'&aitab=settings">Settings</a> tab to add one and choose a free model.</div>';

	echo '<div id="aiagent-app"><div class="ai-card">'
		.'<div class="ai-head">'
			.'<div class="ai-logo">🤖</div>'
			.'<div><h2>AI Database Assistant</h2><div class="ai-sub">Ask in plain language · '.$modelBadge.$modeBadge.'</div></div>'
			.'<div class="ai-spacer"></div>'
			.'<button id="ai-clear" class="ai-clear">Clear chat</button>'
		.'</div>'
		.$banner
		.'<div id="ai-msgs" class="ai-msgs"></div>'
		.'<div class="ai-foot">'
			.'<textarea id="ai-input" class="ai-input" rows="1" placeholder="Ask about members, threads, posts, settings…  (Enter to send, Shift+Enter for a new line)"></textarea>'
			.'<button id="ai-send" class="ai-send">Send</button>'
		.'</div>'
	.'</div></div>';

	// behaviour
	echo <<<'JS'
<script>
(function(){
	var CFG = window.AIAGENT || {};
	var elMsgs = document.getElementById('ai-msgs');
	var elInput = document.getElementById('ai-input');
	var elSend = document.getElementById('ai-send');
	var history = [];
	var busy = false;
	var MAP = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'};
	function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return MAP[c];}); }

	function splitRow(s){ return s.trim().replace(/^\|/,'').replace(/\|$/,'').split('|').map(function(c){return c.trim();}); }
	function isSep(s){ return /^\s*\|?[\s:|-]+\|?\s*$/.test(s) && s.indexOf('-')>=0; }

	function render(md){
		var sql=[], store=[];
		md = String(md==null?'':md).replace(/```(\w*)\n?([\s\S]*?)```/g, function(_,lang,code){
			code = code.replace(/\n$/,'');
			var isSql = (lang||'').toLowerCase()==='sql' || (!lang && /^\s*(select|insert|update|delete|replace|show|describe|with)\b/i.test(code));
			if(isSql) sql.push(code.trim());
			var i = store.length; store.push('<pre class="ai-code"><code>'+esc(code)+'</code></pre>');
			return ' C'+i+' ';
		});
		md = esc(md);
		md = md.replace(/`([^`]+)`/g,'<code class="ai-ic">$1</code>');
		md = md.replace(/\*\*([^*]+)\*\*/g,'<strong>$1</strong>');
		var lines = md.split('\n'), out=[], i=0;
		while(i<lines.length){
			var ln = lines[i];
			if(ln.indexOf('|')>=0 && i+1<lines.length && isSep(lines[i+1])){
				var head=splitRow(lines[i]); i+=2; var rows=[];
				while(i<lines.length && lines[i].indexOf('|')>=0 && lines[i].trim()!==''){ rows.push(splitRow(lines[i])); i++; }
				var t='<table class="ai-tbl"><thead><tr>'+head.map(function(h){return '<th>'+h+'</th>';}).join('')+'</tr></thead><tbody>';
				rows.forEach(function(r){ t+='<tr>'+r.map(function(c){return '<td>'+c+'</td>';}).join('')+'</tr>'; });
				out.push(t+'</tbody></table>'); continue;
			}
			if(/^\s*[-*]\s+/.test(ln)){
				var items=[]; while(i<lines.length && /^\s*[-*]\s+/.test(lines[i])){ items.push('<li>'+lines[i].replace(/^\s*[-*]\s+/,'')+'</li>'); i++; }
				out.push('<ul class="ai-ul">'+items.join('')+'</ul>'); continue;
			}
			if(ln.trim()===''){ i++; continue; }
			var para=[ln]; i++;
			while(i<lines.length && lines[i].trim()!=='' && !/^\s*[-*]\s+/.test(lines[i]) && lines[i].indexOf('|')<0){ para.push(lines[i]); i++; }
			out.push('<p>'+para.join('<br>')+'</p>');
		}
		var html = out.join('').replace(/ C(\d+) /g,function(_,n){return store[n];});
		return { html:html, sql:sql };
	}

	function bubble(role, html){
		var w=document.createElement('div'); w.className='ai-row ai-'+role;
		w.innerHTML='<div class="ai-av">'+(role==='user'?'🧑':'🤖')+'</div><div class="ai-bub">'+html+'</div>';
		elMsgs.appendChild(w); scroll(); return w.querySelector('.ai-bub');
	}
	function scroll(){ elMsgs.scrollTop=elMsgs.scrollHeight; }

	function typing(on){
		var ex=document.getElementById('ai-typing');
		if(on){ if(ex)return; var w=document.createElement('div'); w.id='ai-typing'; w.className='ai-row ai-assistant';
			w.innerHTML='<div class="ai-av">🤖</div><div class="ai-bub"><span class="ai-dots"><i></i><i></i><i></i></span></div>';
			elMsgs.appendChild(w); scroll();
		} else if(ex){ ex.remove(); }
	}

	function post(p){ p.token=CFG.token;
		return fetch(CFG.endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(p)})
			.then(function(r){ return r.json().catch(function(){ return {ok:false,error:'Bad server response'}; }); })
			.catch(function(){ return {ok:false,error:'Network error'}; });
	}

	function classify(sql){
		var k=(sql.replace(/^\s*(\/\*[\s\S]*?\*\/|--[^\n]*\n)/,'').trim().match(/^[a-zA-Z]+/)||[''])[0].toUpperCase();
		if(['SELECT','SHOW','DESCRIBE','DESC','EXPLAIN'].indexOf(k)>=0)return 'read';
		if(['INSERT','UPDATE','DELETE','REPLACE'].indexOf(k)>=0)return 'write';
		return 'other';
	}

	function resultTable(r){
		var box=document.createElement('div'); box.className='ai-result';
		if(!r.rows||!r.rows.length){ box.innerHTML='<span class="ai-muted">No rows.</span>'; return box; }
		var cols=Object.keys(r.rows[0]);
		var t='<table class="ai-tbl"><thead><tr>'+cols.map(function(c){return '<th>'+esc(c)+'</th>';}).join('')+'</tr></thead><tbody>';
		r.rows.forEach(function(row){ t+='<tr>'+cols.map(function(c){return '<td>'+esc(row[c]===null?'NULL':row[c])+'</td>';}).join('')+'</tr>'; });
		box.innerHTML=t+'</tbody></table><div class="ai-muted" style="margin-top:6px">'+r.rowcount+' row(s)'+(r.truncated?' · truncated':'')+'</div>';
		return box;
	}

	function attachSql(bub, sql){
		var cls=classify(sql);
		if(cls==='read'){
			var card=document.createElement('div'); card.className='ai-action';
			card.innerHTML='<button class="ai-btn">▶ Run query</button>'; bub.appendChild(card);
			card.querySelector('button').onclick=function(){
				card.innerHTML='<span class="ai-muted">Running…</span>';
				post({mode:'run_sql',sql:sql}).then(function(res){
					if(!res||!res.ok){ card.innerHTML='<span class="ai-err">⚠️ '+esc(res&&res.error||'failed')+'</span>'; return; }
					card.innerHTML=''; card.appendChild(resultTable(res.result));
					history.push({role:'user',content:'(System: ran the query, '+res.result.rowcount+' row(s): '+JSON.stringify(res.result.rows).slice(0,1500)+')'});
				});
			};
		} else if(cls==='write' && CFG.writeMode==='confirm'){
			proposal({sql:sql,rationale:'',impact:null});
		}
	}

	function proposal(p){
		var card=document.createElement('div'); card.className='ai-row ai-assistant';
		var imp=(p.impact&&typeof p.impact.rows!=='undefined')?'<span class="ai-impact">~'+p.impact.rows+' row'+(p.impact.rows==1?'':'s')+'</span>':'';
		card.innerHTML='<div class="ai-av">⚠️</div><div class="ai-bub ai-prop">'
			+'<div class="ai-prop-h">Proposed database change '+imp+'</div>'
			+(p.rationale?'<div class="ai-prop-r">'+esc(p.rationale)+'</div>':'')
			+'<pre class="ai-code"><code>'+esc(p.sql)+'</code></pre>'
			+'<div class="ai-prop-btns"><button class="ai-btn ai-approve">✓ Approve &amp; run</button><button class="ai-btn ai-ghost ai-reject">Reject</button></div>'
			+'<div class="ai-prop-status"></div></div>';
		elMsgs.appendChild(card); scroll();
		var st=card.querySelector('.ai-prop-status'), ap=card.querySelector('.ai-approve'), rj=card.querySelector('.ai-reject');
		ap.onclick=function(){ ap.disabled=true; rj.disabled=true; st.innerHTML='<span class="ai-muted">Running…</span>';
			post({mode:'confirm_write',sql:p.sql}).then(function(res){
				if(!res||!res.ok){ st.innerHTML='<span class="ai-err">⚠️ '+esc(res&&res.error||'failed')+'</span>'; ap.disabled=false; rj.disabled=false; return; }
				st.innerHTML='<span class="ai-ok">✓ Executed — '+res.affected+' row(s) affected.</span>';
				history.push({role:'user',content:'(System: I approved the change; it executed — '+res.affected+' row(s) affected.)'});
			});
		};
		rj.onclick=function(){ ap.disabled=true; rj.disabled=true; st.innerHTML='<span class="ai-muted">Rejected.</span>';
			history.push({role:'user',content:'(System: I rejected the proposed change. Do not run it.)'});
		};
	}

	function send(){
		if(busy) return;
		var text=elInput.value.trim(); if(text==='') return;
		var wel=elMsgs.querySelector('.ai-welcome'); if(wel) wel.remove();
		if(!CFG.configured){ bubble('assistant','<p class="ai-err">⚠️ No API key configured. Open the Settings tab first.</p>'); return; }
		elInput.value=''; grow();
		bubble('user','<p>'+esc(text).replace(/\n/g,'<br>')+'</p>');
		history.push({role:'user',content:text});
		busy=true; elSend.disabled=true; typing(true);
		post({mode:'chat',messages:history}).then(function(res){
			typing(false); busy=false; elSend.disabled=false;
			if(!res||!res.ok){ bubble('assistant','<p class="ai-err">⚠️ '+esc(res&&res.error||'Request failed')+'</p>'); return; }
			var reply=res.reply||'';
			if(reply.trim()!==''){
				var r=render(reply); var bub=bubble('assistant',r.html);
				history.push({role:'assistant',content:reply});
				r.sql.forEach(function(s){ attachSql(bub,s); });
			}
			(res.proposals||[]).forEach(function(p){ proposal(p); });
			elInput.focus();
		});
	}

	function grow(){ elInput.style.height='auto'; elInput.style.height=Math.min(160,elInput.scrollHeight)+'px'; }
	function welcome(){
		var w=document.createElement('div'); w.className='ai-welcome';
		var sub = CFG.writeMode==='confirm' ? 'I can also propose changes for you to approve before they run.' : "Read-only mode — I won't change anything.";
		w.innerHTML='<div class="ai-welcome-i">🤖</div><div class="ai-welcome-t">Ask me about your forum database</div>'
			+'<div class="ai-welcome-s">I can query members, threads, posts, settings and more. '+sub+'</div><div class="ai-chips"></div>';
		elMsgs.appendChild(w);
		['How many members are registered?','Show the 5 newest threads','Which forum has the most posts?','Top 5 users by post count'].forEach(function(t){
			var b=document.createElement('button'); b.className='ai-chip'; b.textContent=t;
			b.onclick=function(){ elInput.value=t; send(); }; w.querySelector('.ai-chips').appendChild(b);
		});
	}

	elInput.addEventListener('input', grow);
	elInput.addEventListener('keydown', function(ev){ if(ev.key==='Enter' && !ev.shiftKey){ ev.preventDefault(); send(); } });
	elSend.addEventListener('click', send);
	document.getElementById('ai-clear').onclick=function(){ history=[]; elMsgs.innerHTML=''; welcome(); };
	welcome(); elInput.focus();
})();
</script>
JS;

/* ============================================================================
 * SETTINGS TAB
 * ========================================================================== */
} elseif($tab === 'settings') {
	$keyset = trim((string)$cfg['api_key']) !== '';
	$enabledsel = '<select name="enabled" style="font-size:14px;padding:5px 8px;border-radius:6px">'
		.'<option value="1"'.($cfg['enabled'] ? ' selected' : '').'>✅ Enabled</option>'
		.'<option value="0"'.(!$cfg['enabled'] ? ' selected' : '').'>⛔ Disabled</option></select>';
	$wmsel = '<select name="write_mode" style="font-size:14px;padding:5px 8px;border-radius:6px">'
		.'<option value="off"'.($cfg['write_mode'] === 'off' ? ' selected' : '').'>🔒 Read-only (AI cannot modify data)</option>'
		.'<option value="confirm"'.($cfg['write_mode'] === 'confirm' ? ' selected' : '').'>✏️ Allow writes — with manual Approve</option>'
		.'</select>';

	showtableheader('🔌 Connection');
	showformheader($selfurl, '', 'aiform');
	showtablerow('', '', 'Status / 状态：'.$enabledsel);
	showtablerow('', '', 'OpenRouter API key：<br /><input type="password" name="api_key" value="" autocomplete="new-password" class="txt" style="width:460px" placeholder="'
		.($keyset ? '•••••••• (saved — leave blank to keep)' : 'sk-or-v1-...').'" /> '
		.'<a href="https://openrouter.ai/keys" target="_blank" rel="noopener">Get a key ↗</a>'
		.'<br /><span class="smalltxt">Stored in the database (plaintext, like other plugin secrets). Founder-only. Never shown back in the browser.</span>');
	showtablerow('', '', 'Model / 模型：<br />'
		.'<input type="text" name="model" id="ai-model-input" value="'.$e($cfg['model']).'" class="txt" style="width:460px" /> '
		.'<a href="https://openrouter.ai/models?max_price=0" target="_blank" rel="noopener">Browse ↗</a>'
		.'<div style="margin-top:7px"><select id="ai-model-picker" style="min-width:460px;max-width:100%;padding:6px 8px;border-radius:6px;border:1px solid #d8dce1">'
		.'<option value="">— loading free models… —</option></select> '
		.'<input type="button" id="ai-model-refresh" value="↻ Refresh" style="padding:6px 12px;border-radius:6px;cursor:pointer" /> '
		.'<span id="ai-model-status" class="smalltxt"></span></div>'
		.'<span class="smalltxt">Pick from your account\'s <b>free</b> models, fetched live from OpenRouter. 🔧 = supports <b>tool calling</b> (needed for database Q&amp;A). You can also type any model id in the box above.</span>');
	showtablerow('', '', 'API base URL：<input type="text" name="base_url" value="'.$e($cfg['base_url']).'" class="txt" style="width:340px" /> <span class="smalltxt">(default: https://openrouter.ai/api/v1)</span>');
	showsubmit('aiagent_conn', 'Save / 保存');
	showtablefooter();
	showformfooter();

	showtableheader('🛡️ Database access');
	showformheader($selfurl, '', 'aiform2');
	showtablerow('', '', 'Write mode / 写入模式：'.$wmsel
		.'<br /><span class="smalltxt">In <b>Allow writes</b> mode the AI can <b>propose</b> INSERT/UPDATE/DELETE statements, but nothing runs until you click <b>Approve</b>. '
		.'Schema changes (DROP/ALTER/TRUNCATE) are always blocked, and UPDATE/DELETE must include a WHERE clause. Every action is logged.</span>');
	showtablerow('', '', 'Max rows per query / 单次最大行数：<input type="text" name="max_rows" value="'.$e($cfg['max_rows']).'" class="txt" style="width:90px" /> <span class="smalltxt">(1–500; caps how many rows a SELECT returns to the AI, to control token usage)</span>');
	// keep the connection fields when saving this form too (they post together)
	showtablerow('', '', '<span class="smalltxt">Tip: open the <b>💬 Chat</b> tab to start talking to the assistant. The <b>🧾 Activity log</b> tab shows every read/write it performed.</span>');
	showsubmit('aiagent_db', 'Save / 保存');
	showtablefooter();
	showformfooter();

	// live model picker: fetch the account's free models from OpenRouter and let the admin choose one
	echo '<script>window.AIAGENT_S='.json_encode(array(
		'endpoint' => 'plugin.php?id=aiagent:chat',
		'token'    => aiagent_plugin_formhash(),
		'current'  => $cfg['model'],
	), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).';</script>';
	echo <<<'JS'
<script>
(function(){
	var S = window.AIAGENT_S || {};
	var input = document.getElementById('ai-model-input');
	var picker = document.getElementById('ai-model-picker');
	var status = document.getElementById('ai-model-status');
	var refresh = document.getElementById('ai-model-refresh');
	if(!input || !picker) return;
	function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
	function opt(m){
		var ctx = m.ctx ? ' · '+Math.round(m.ctx/1000)+'k ctx' : '';
		return '<option value="'+esc(m.id)+'"'+(m.id===S.current?' selected':'')+'>'+(m.tools?'🔧 ':'')+esc(m.name)+' — '+esc(m.id)+ctx+'</option>';
	}
	function load(){
		status.textContent = 'Loading…'; picker.innerHTML='<option value="">— loading… —</option>';
		fetch(S.endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({mode:'models',token:S.token})})
			.then(function(r){return r.json();})
			.then(function(res){
				if(!res||!res.ok){ status.textContent='⚠️ '+((res&&res.error)||'Could not load models'); picker.innerHTML='<option value="">— unavailable (type an id above) —</option>'; return; }
				var ms = res.models||[];
				var withTools = ms.filter(function(m){return m.tools;});
				var without = ms.filter(function(m){return !m.tools;});
				var html = '<option value="">— '+ms.length+' free models — pick one —</option>';
				// keep the current value selectable even if it is a custom / non-free id
				if(S.current && !ms.some(function(m){return m.id===S.current;})){
					html += '<option value="'+esc(S.current)+'" selected>(current) '+esc(S.current)+'</option>';
				}
				if(withTools.length){ html += '<optgroup label="🔧 Supports tools (recommended for DB Q&amp;A)">'+withTools.map(opt).join('')+'</optgroup>'; }
				if(without.length){ html += '<optgroup label="Other free models (no tool calling)">'+without.map(opt).join('')+'</optgroup>'; }
				picker.innerHTML = html;
				status.textContent = ms.length+' free models ('+withTools.length+' with tools)';
			})
			.catch(function(){ status.textContent='⚠️ Network error'; });
	}
	picker.addEventListener('change', function(){ if(picker.value){ input.value = picker.value; } });
	if(refresh) refresh.addEventListener('click', load);
	load();
})();
</script>
JS;

/* ============================================================================
 * ACTIVITY LOG TAB
 * ========================================================================== */
} else {
	$badges = array(
		'select'        => '<span style="color:#2563eb">🔍 read</span>',
		'select_denied' => '<span style="color:#b45309">🚫 read blocked</span>',
		'write'         => '<span style="color:#059669">✏️ write</span>',
		'write_denied'  => '<span style="color:#dc2626">🚫 write blocked</span>',
	);
	showtableheader('🧾 Activity log — the assistant\'s database actions (newest first)');
	echo '<tr class="header"><th style="width:140px">Time</th><th style="width:90px">User</th><th style="width:110px">Action</th><th style="width:70px">Rows</th><th>SQL</th><th style="width:60px">OK</th></tr>';
	$rows = array();
	try {
		$rows = DB::fetch_all('SELECT * FROM '.DB::table('aiagent_log').' ORDER BY logid DESC LIMIT 200', array(), '', true);
	} catch(\Throwable $ex) {}
	if($rows) {
		foreach($rows as $r) {
			$act = isset($badges[$r['action']]) ? $badges[$r['action']] : $e($r['action']);
			$cnt = $r['action'] === 'write' ? intval($r['affected']) : intval($r['rowcount']);
			$ok  = $r['status'] ? '✅' : ($r['error'] !== '' ? '<span title="'.$e($r['error']).'">⚠️</span>' : '—');
			echo '<tr>'
				.'<td>'.dgmdate($r['dateline']).'</td>'
				.'<td>'.$e($r['username']).'</td>'
				.'<td>'.$act.'</td>'
				.'<td>'.$cnt.'</td>'
				.'<td><code style="font-size:12px;word-break:break-all">'.$e($r['sql_text']).'</code>'.($r['error'] !== '' ? '<br /><span style="color:#dc2626;font-size:12px">'.$e($r['error']).'</span>' : '').'</td>'
				.'<td>'.$ok.'</td>'
			.'</tr>';
		}
	} else {
		echo '<tr><td colspan="6" style="color:#999">No activity yet. Open the Chat tab and ask a question.</td></tr>';
	}
	showtablefooter();
}
