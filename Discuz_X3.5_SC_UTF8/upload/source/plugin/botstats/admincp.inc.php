<?php
if(!defined('IN_DISCUZ') || !defined('IN_ADMINCP')) { exit('Access Denied'); }

/**
 * 后台模块（类型 3）：机器人流量统计面板。
 * 由 admincp_plugins.php 在 cpheader()/nav 之后 include，因此本文件不再调用 cpheader()。
 * 数据在页面加载时由 PHP 直接计算并以 JSON 内嵌（避免 AJAX 受 admin 页面外壳污染）：
 *   - 切换图表类型（走线图/饼图）与分类多选 → 纯前端重绘，无需刷新；
 *   - 切换时间范围 → 以 GET 参数重新加载本页，PHP 重新内嵌数据。
 * 走线图 = 每个所选分类一条时间序列；饼图 = 区间内各分类合计占比。
 */
require_once DISCUZ_ROOT.'./source/plugin/botstats/function_botstats.php';

$selfurl = 'plugins&operation=config&do='.$pluginid.'&identifier=botstats&pmod=admincp';
$e = function($v) { return htmlspecialchars((string)$v, ENT_QUOTES); };

// ============================ 处理表单提交 ============================
// 保存设置
if(submitcheck('bs_settings')) {
	$raw = (array)dunserialize(C::t('common_setting')->fetch_setting('botstats'));
	$raw['enabled']       = intval(getgpc('enabled'));
	$raw['exclude_admin'] = intval(getgpc('exclude_admin'));
	$raw['require_cf']    = intval(getgpc('require_cf'));
	C::t('common_setting')->update_setting('botstats', array_merge(botstats_defaults(), $raw));
	updatecache('setting');
	cpmsg('设置已保存。', 'action='.$selfurl, 'succeed');
}
// 插入示例数据
if(submitcheck('bs_mock')) {
	botstats_insert_mock(30);
	cpmsg('已插入最近 30 天的示例数据。', 'action='.$selfurl, 'succeed');
}
// 清空全部数据
if(submitcheck('bs_clear')) {
	botstats_clear();
	cpmsg('已清空全部统计数据。', 'action='.$selfurl, 'succeed');
}

// ============================ 计算时间范围 ============================
$now    = TIMESTAMP;
$range  = (string)getgpc('range');
if(!in_array($range, array('24h', '7d', '30d', '90d', 'custom'), true)) {
	$range = '7d';
}
$offset = isset($_G['setting']['timeoffset']) ? floatval($_G['setting']['timeoffset']) : 0;
$ofs    = (int)round($offset * 3600);

$fromDateDefault = dgmdate($now - 7 * 86400, 'Y-m-d');
$toDateDefault   = dgmdate($now, 'Y-m-d');
$fromDate = trim((string)getgpc('from')) ?: $fromDateDefault;
$toDate   = trim((string)getgpc('to'))   ?: $toDateDefault;

if($range === 'custom') {
	$f = strtotime($fromDate.' UTC');
	$t = strtotime($toDate.' UTC');
	if($f === false || $t === false) {           // 解析失败 → 回退近 7 天
		$from = $now - 7 * 86400; $to = $now; $range = '7d';
	} else {
		$from = $f - $ofs;                       // 论坛本地零点
		$to   = $t - $ofs + 86400;               // 含当天，到次日本地零点
	}
} else {
	$spanmap = array('24h' => 86400, '7d' => 7 * 86400, '30d' => 30 * 86400, '90d' => 90 * 86400);
	$from = $now - $spanmap[$range];
	$to   = $now;
}
if($to <= $from) { $to = $from + 3600; }
if($to - $from > 366 * 86400) { $from = $to - 366 * 86400; } // 上限一年

// ============================ 取数据 ============================
$payload = botstats_query_series($from, $to);

$metaJs = array();
foreach(botstats_category_meta() as $k => $v) {
	$metaJs[$k] = array('label' => $v[0], 'color' => $v[1]);
}
$jsonFlags  = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$payloadJs  = json_encode($payload, $jsonFlags);
$metaJson   = json_encode($metaJs, $jsonFlags);

$cfg = botstats_config();

// 自检：当前这次后台请求自己看到的头
$selfCat = isset($_SERVER['HTTP_X_VERIFIED_BOT_CATEGORY']) ? trim($_SERVER['HTTP_X_VERIFIED_BOT_CATEGORY']) : '';
$selfRay = !empty($_SERVER['HTTP_CF_RAY']);
$totalRows = botstats_row_count();

// 时间范围按钮
$ranges = array('24h' => '近 24 小时', '7d' => '近 7 天', '30d' => '近 30 天', '90d' => '近 90 天', 'custom' => '自定义');
$rangeNav = '';
foreach($ranges as $rk => $rlabel) {
	$on = ($rk === $range);
	$rangeNav .= '<a class="bs-chip'.($on ? ' on' : '').'" href="'.ADMINSCRIPT.'?action='.$e($selfurl).'&range='.$rk.'">'.$e($rlabel).'</a>';
}
$echartsSrc = STATICURL.'js/echarts/echarts.common.min.js';
?>
<style>
.bs-wrap{font-size:14px;color:#1f2329;line-height:1.6}
.bs-hero{background:linear-gradient(135deg,#3b82f6,#6366f1);color:#fff;border-radius:14px;padding:18px 22px;margin:14px 0;box-shadow:0 6px 20px rgba(79,70,229,.25)}
.bs-hero h2{margin:0 0 4px;font-size:22px;font-weight:800;color:#fff}
.bs-hero p{margin:0;opacity:.92;font-size:13px}
.bs-card{background:#fff;border:1px solid #e6e8eb;border-radius:14px;padding:16px 18px;margin:14px 0;box-shadow:0 2px 10px rgba(20,23,28,.05)}
.bs-card h3{margin:0 0 12px;font-size:16px;font-weight:700;display:flex;align-items:center;gap:8px}
.bs-self{display:flex;flex-wrap:wrap;gap:10px;margin:10px 0}
.bs-pill{background:#f2f4f7;border-radius:999px;padding:6px 14px;font-size:13px}
.bs-pill b{color:#111}
.bs-ok{color:#0a8f5b;font-weight:700}.bs-warn{color:#c2410c;font-weight:700}.bs-bad{color:#dc2626;font-weight:700}
.bs-toolbar{display:flex;flex-wrap:wrap;gap:18px;align-items:center}
.bs-toolgrp{display:flex;align-items:center;gap:8px}
.bs-toolgrp .lab{color:#6b7280;font-size:13px;margin-right:2px}
.bs-chip{display:inline-block;padding:6px 14px;margin-right:6px;border-radius:999px;border:1px solid #d7dbe0;background:#fff;color:#374151;text-decoration:none;font-size:13px;cursor:pointer}
.bs-chip:hover{border-color:#6366f1;color:#4f46e5}
.bs-chip.on{background:#4f46e5;border-color:#4f46e5;color:#fff;font-weight:700}
.bs-date{border:1px solid #d7dbe0;border-radius:8px;padding:5px 8px;font-size:13px}
.bs-btn{border:1px solid #4f46e5;background:#4f46e5;color:#fff;border-radius:8px;padding:6px 14px;font-size:13px;cursor:pointer}
.bs-btn.gray{background:#fff;color:#374151;border-color:#d7dbe0}
.bs-btn.danger{background:#fff;color:#dc2626;border-color:#f0a8a8}
.bs-cats{display:flex;flex-wrap:wrap;gap:6px 14px;margin-top:12px;padding-top:12px;border-top:1px dashed #e6e8eb}
.bs-cats label{display:inline-flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;padding:2px 0}
.bs-dot{width:11px;height:11px;border-radius:3px;display:inline-block}
.bs-cats .mini{color:#6b7280;font-size:12px}
.bs-steps{margin:0;padding-left:20px}
.bs-steps li{margin:6px 0}
.bs-kbd{background:#f2f4f7;border:1px solid #e0e3e7;border-radius:5px;padding:1px 7px;font-family:ui-monospace,Consolas,monospace;font-size:12px}
.bs-note{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:10px;padding:10px 14px;margin:10px 0;font-size:13px}
</style>

<div class="bs-wrap">

  <div class="bs-hero">
    <h2>🤖 机器人流量统计</h2>
    <p>按小时统计 Cloudflare 验证机器人各分类（含「非机器人 / 真人」）的<b>访问次数</b> · 不记录任何 IP</p>
  </div>

  <!-- 自检 -->
  <div class="bs-card">
    <h3>📡 接入自检</h3>
    <div class="bs-self">
      <span class="bs-pill">统计开关：<?php echo $cfg['enabled'] ? '<span class="bs-ok">✅ 已开启</span>' : '<span class="bs-bad">⛔ 已关闭</span>'; ?></span>
      <span class="bs-pill">本次请求的 <code>X-Verified-Bot-Category</code>：
        <?php echo $selfCat !== '' ? '<b>'.$e($selfCat).'</b>' : '<span class="bs-warn">（空 → 记为非机器人/真人）</span>'; ?>
      </span>
      <span class="bs-pill">CF-Ray：<?php echo $selfRay ? '<span class="bs-ok">已检测到（经 Cloudflare）</span>' : '<span class="bs-warn">未检测到</span>'; ?></span>
      <span class="bs-pill">累计数据行：<b><?php echo $totalRows; ?></b></span>
    </div>
    <div style="color:#6b7280;font-size:12px">提示：你用浏览器访问后台通常没有该请求头，会被记为「非机器人」，这是正常现象。配置好下方 Cloudflare 规则后，机器人访问才会带上分类。</div>
  </div>

  <!-- 工具栏 + 图表 -->
  <div class="bs-card">
    <div class="bs-toolbar">
      <div class="bs-toolgrp">
        <span class="lab">时间范围</span>
        <?php echo $rangeNav; ?>
      </div>
      <form class="bs-toolgrp" method="get" action="<?php echo ADMINSCRIPT; ?>">
        <input type="hidden" name="action" value="plugins" />
        <input type="hidden" name="operation" value="config" />
        <input type="hidden" name="do" value="<?php echo $e($pluginid); ?>" />
        <input type="hidden" name="identifier" value="botstats" />
        <input type="hidden" name="pmod" value="admincp" />
        <input type="hidden" name="range" value="custom" />
        <input class="bs-date" type="date" name="from" value="<?php echo $e($fromDate); ?>" />
        <span style="color:#9ca3af">→</span>
        <input class="bs-date" type="date" name="to" value="<?php echo $e($toDate); ?>" />
        <button class="bs-btn gray" type="submit">应用</button>
      </form>
      <div class="bs-toolgrp">
        <span class="lab">图表类型</span>
        <span class="bs-chip on" id="bs-type-line" onclick="bsSetType('line')">📈 走线图</span>
        <span class="bs-chip" id="bs-type-pie" onclick="bsSetType('pie')">🥧 饼图（占比）</span>
      </div>
    </div>

    <div id="bschart" style="width:100%;height:460px;margin-top:14px"></div>
    <div id="bschart-empty" style="display:none;color:#9ca3af;text-align:center;padding:40px 0">所选范围内暂无数据。试试更大的时间范围，或点最下方「插入示例数据」。</div>

    <div class="bs-cats" id="bscats">
      <span class="mini">分类（可勾选/取消）：</span>
    </div>
    <div style="color:#6b7280;font-size:12px;margin-top:6px">
      数值为<b>访问次数</b>（每次页面请求计 1，不按 IP 去重——机器人常以同一 IP 代理大量请求，按次数更能反映其活跃度）。<br>
      想在饼图里看清各机器人占比？取消勾选「非机器人（真人）」即可——真人通常远多于机器人，会盖过其它分类。
    </div>
  </div>

  <!-- Cloudflare 设置说明 -->
  <div class="bs-card">
    <h3>☁️ 如何让 Cloudflare 把机器人分类传给本站</h3>
    <div class="bs-note">
      ⚠️ 必须用「<b>请求</b>头改写规则（Modify Request Header）」——它会把头加到「转发给源站」的请求上，PHP 才读得到。
      「响应头改写规则（Response Header）」只改返回给浏览器的响应，<b>源站收不到</b>，统计会一直为空。
    </div>
    <ol class="bs-steps">
      <li>登录 Cloudflare 控制台 → 选择你的域名。</li>
      <li>进入 <b>Rules（规则）→ Transform Rules（转换规则）→ Modify Request Header（修改请求头）</b>。</li>
      <li>点击 <b>Create rule（创建规则）</b>，命名如 <span class="bs-kbd">Send verified bot category to origin</span>。</li>
      <li><b>When incoming requests match（匹配条件）</b>：选 <b>All incoming requests（所有传入请求）</b>。</li>
      <li><b>Then（动作）→ Set dynamic（设置动态值）</b>：
        <ul>
          <li>Header name（头名称）：<span class="bs-kbd">X-Verified-Bot-Category</span></li>
          <li>Value（表达式）：<span class="bs-kbd">cf.verified_bot_category</span></li>
        </ul>
      </li>
      <li><b>Deploy（部署）</b>。完成后回到本页「接入自检」，机器人访问即会带上分类。</li>
    </ol>
    <div style="color:#6b7280;font-size:12px">
      其它说明：① 若曾建过「响应头」版本的同名规则，请删除；② 命中 Cloudflare 缓存、未回源的请求不会被 PHP 统计，属正常；
      ③ 直连源站（绕过 CF）的访客可伪造该头，生产环境可在下方设置里开启「仅统计经 Cloudflare 的请求」。
      分类含义见 <a href="https://developers.cloudflare.com/bots/concepts/bot/verified-bots/#categories" target="_blank" rel="noopener">Cloudflare 文档 ↗</a>。
    </div>
  </div>
<?php
// ---- 设置表单（管理界面风格）----
$onoff = function($name, $val) {
	return '<select name="'.$name.'" class="bs-date">'
		.'<option value="1"'.($val ? ' selected' : '').'>开启 On</option>'
		.'<option value="0"'.(!$val ? ' selected' : '').'>关闭 Off</option></select>';
};
showtableheader('⚙ 设置');
showformheader($selfurl, '', 'bsset');
showtablerow('', '', '<b>统计开关</b> <span class="smalltxt">关闭后不再记录任何访问</span> &nbsp; '.$onoff('enabled', $cfg['enabled']));
showtablerow('', '', '<b>排除管理员浏览</b> <span class="smalltxt">不统计已登录管理员自己的页面访问，避免污染「真人」基线</span> &nbsp; '.$onoff('exclude_admin', $cfg['exclude_admin']));
showtablerow('', '', '<b>仅统计经 Cloudflare 的请求</b> <span class="smalltxt">仅当请求带 CF-Ray 头时才计数（防直连伪造）。本地/无 CF 时请保持关闭</span> &nbsp; '.$onoff('require_cf', $cfg['require_cf']));
showsubmit('bs_settings', '保存设置');
showtablefooter();
showformfooter();

// ---- 数据管理 ----
showtableheader('🧪 数据管理');
showformheader($selfurl, '', 'bsmock');
showtablerow('', '', '<b>插入示例数据</b> <span class="smalltxt">生成最近 30 天、各分类拟真的演示流量（按桶累加，可重复点击）。仅用于预览图表效果。</span>');
showsubmit('bs_mock', '插入示例数据（最近 30 天）');
showtablefooter();
showformfooter();
// 清空：手写表单以便加二次确认（提交后 submitcheck('bs_clear') 校验 formhash）
echo '<table class="tb tb2"><tr><th class="partition">⚠️ 清空数据</th></tr>'
	.'<tr><td style="padding:12px"><b>清空全部统计数据</b> <span class="smalltxt">删除所有记录（含真实与示例数据），不可恢复。</span><br /><br />'
	.'<form method="post" action="'.ADMINSCRIPT.'?action='.$e($selfurl).'" onsubmit="return confirm(\'确定清空全部统计数据？此操作不可恢复。\');" style="display:inline">'
	.'<input type="hidden" name="formhash" value="'.FORMHASH.'" />'
	.'<input type="hidden" name="bs_clear" value="1" />'
	.'<button class="bs-btn danger" type="submit">🗑️ 清空全部数据</button>'
	.'</form></td></tr></table>';
?>

</div><!-- /bs-wrap -->

<script src="<?php echo $e($echartsSrc); ?>"></script>
<script type="text/javascript">
(function(){
	var BS   = <?php echo $payloadJs; ?>;
	var META = <?php echo $metaJson; ?>;
	var PALETTE = ['#5470c6','#91cc75','#fac858','#ee6666','#73c0de','#3ba272','#fc8452','#9a60b4','#ea7ccc','#48b3bd','#f4a259','#b08968'];
	var chartType = 'line';
	var chart = null, selected = {};

	function catLabel(c){ return (META[c] && META[c].label) ? META[c].label : (c === '__none__' ? '非机器人（真人）' : c); }
	var palIdx = 0, palMap = {};
	function catColor(c){
		if(META[c] && META[c].color) return META[c].color;
		if(!palMap[c]){ palMap[c] = PALETTE[palIdx % PALETTE.length]; palIdx++; }
		return palMap[c];
	}

	// 按 META 顺序排列出现在数据里的分类（未知分类追加在后）
	function presentCats(){
		var present = BS.series ? Object.keys(BS.series) : [];
		var ordered = [], known = Object.keys(META), seen = {};
		for(var i=0;i<known.length;i++){ if(present.indexOf(known[i])>=0){ ordered.push(known[i]); seen[known[i]]=1; } }
		for(var j=0;j<present.length;j++){ if(!seen[present[j]]) ordered.push(present[j]); }
		return ordered;
	}

	function buildCats(){
		var box = document.getElementById('bscats');
		var cats = presentCats();
		var html = '<span class="mini">分类（可勾选/取消）：</span>';
		if(!cats.length){
			box.innerHTML = html + '<span class="mini">（暂无数据）</span>';
			return;
		}
		for(var i=0;i<cats.length;i++){
			var c = cats[i];
			selected[c] = true; // 默认全选
			var total = (BS.totals && BS.totals[c]) ? BS.totals[c] : 0;
			html += '<label><input type="checkbox" data-cat="'+encodeURIComponent(c)+'" checked /> '
				+ '<span class="bs-dot" style="background:'+catColor(c)+'"></span>'
				+ catLabel(c) + ' <span class="mini">('+total+')</span></label>';
		}
		html += '&nbsp;&nbsp;<a class="bs-chip" onclick="bsAll(1)">全选</a><a class="bs-chip" onclick="bsAll(0)">清空</a>';
		box.innerHTML = html;
		var cbs = box.querySelectorAll('input[type=checkbox]');
		for(var k=0;k<cbs.length;k++){
			cbs[k].addEventListener('change', function(){
				selected[decodeURIComponent(this.getAttribute('data-cat'))] = this.checked;
				render();
			});
		}
	}

	window.bsAll = function(on){
		var cbs = document.getElementById('bscats').querySelectorAll('input[type=checkbox]');
		for(var i=0;i<cbs.length;i++){ cbs[i].checked = !!on; selected[decodeURIComponent(cbs[i].getAttribute('data-cat'))] = !!on; }
		render();
	};

	window.bsSetType = function(t){
		chartType = t;
		document.getElementById('bs-type-line').className = 'bs-chip' + (t==='line'?' on':'');
		document.getElementById('bs-type-pie').className  = 'bs-chip' + (t==='pie'?' on':'');
		render();
	};

	function chosen(){
		var cats = presentCats(), out = [];
		for(var i=0;i<cats.length;i++){ if(selected[cats[i]]) out.push(cats[i]); }
		return out;
	}

	function render(){
		if(!chart) return;
		var cats = chosen();
		var emptyEl = document.getElementById('bschart-empty');
		var hasData = cats.length > 0 && BS.labels && BS.labels.length > 0;
		document.getElementById('bschart').style.display = hasData ? '' : 'none';
		emptyEl.style.display = hasData ? 'none' : '';
		if(!hasData){ chart.clear(); return; }

		var opt;
		if(chartType === 'line'){
			var series = [], legend = [];
			for(var i=0;i<cats.length;i++){
				var c = cats[i];
				legend.push(catLabel(c));
				series.push({ name:catLabel(c), type:'line', smooth:true, showSymbol:false,
					itemStyle:{color:catColor(c)}, lineStyle:{color:catColor(c),width:2},
					data: BS.series[c] });
			}
			opt = {
				color: cats.map(catColor),
				tooltip:{ trigger:'axis' },
				legend:{ type:'scroll', data:legend, bottom:0 },
				grid:{ left:50, right:24, top:20, bottom: BS.labels.length>48 ? 70 : 50 },
				xAxis:{ type:'category', boundaryGap:false, data: BS.labels },
				yAxis:{ type:'value', name:'访问次数', minInterval:1 },
				series: series
			};
			if(BS.labels.length > 48){ opt.dataZoom = [{type:'inside'},{type:'slider',height:18,bottom:36}]; }
		} else {
			var pdata = [];
			for(var j=0;j<cats.length;j++){
				var cc = cats[j], val = (BS.totals && BS.totals[cc]) ? BS.totals[cc] : 0;
				if(val > 0) pdata.push({ name:catLabel(cc), value:val, itemStyle:{color:catColor(cc)} });
			}
			opt = {
				tooltip:{ trigger:'item', formatter:'{b}: {c} 次 ({d}%)' },
				legend:{ type:'scroll', orient:'vertical', right:10, top:20 },
				series:[{ type:'pie', radius:['38%','66%'], center:['42%','52%'],
					avoidLabelOverlap:true,
					label:{ formatter:'{b}\n{d}%' },
					data: pdata }]
			};
		}
		chart.setOption(opt, true);
	}

	function init(){
		if(typeof echarts === 'undefined'){
			document.getElementById('bschart').innerHTML =
				'<div style="color:#dc2626;padding:30px;text-align:center">ECharts 加载失败，请检查 static/js/echarts/echarts.common.min.js 是否存在。</div>';
			return;
		}
		chart = echarts.init(document.getElementById('bschart'));
		buildCats();
		render();
		window.addEventListener('resize', function(){ if(chart) chart.resize(); });
	}

	if(document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', init); }
	else { init(); }
})();
</script>
