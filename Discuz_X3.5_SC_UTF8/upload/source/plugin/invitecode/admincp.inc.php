<?php
if(!defined('IN_DISCUZ') || !defined('IN_ADMINCP')) { exit('Access Denied'); }

/**
 * Generates invitation codes for Discuz's BUILT-IN invite system (pre_common_invite).
 * Codes are admin-generated generic gate codes (uid=0, fuid=0). Discuz's own
 * registration (regstatus=2) validates them via getinvite() and sets fuid on success.
 */
$tbl     = DB::table('common_invite');
$selfurl = 'plugins&operation=config&do='.$pluginid.'&identifier=invitecode&pmod=admincp'; // showformheader
$cpurl   = 'action='.$selfurl;          // cpmsg (it prepends ADMINSCRIPT.'?')
$linkurl = ADMINSCRIPT.'?'.$cpurl;      // <a href> links

// --- delete one unused admin code --------------------------------------------
if($_GET['op'] == 'delete' && !empty($_GET['id'])) {
    DB::query('DELETE FROM '.$tbl.' WHERE id=%d AND uid=0 AND fuid=0', array(intval($_GET['id'])));
    cpmsg('邀请码已删除 / Code deleted.', $cpurl, 'succeed');
}

// --- generate codes -----------------------------------------------------------
if(submitcheck('gensubmit')) {
    $count   = max(1, min(500, intval($_GET['count'])));
    $days    = max(0, intval($_GET['days']));
    $endtime = $days ? (TIMESTAMP + $days * 86400) : 0;
    $made = 0;
    for($i = 0; $i < $count; $i++) {
        $code = strtoupper(random(4).'-'.random(4).'-'.random(4)); // 14 chars, fits char(20)
        DB::query('INSERT INTO '.$tbl.' (uid, code, fuid, fusername, type, email, inviteip, dateline, endtime, status) '
                 .'VALUES (0, %s, 0, %s, 0, %s, %s, %d, %d, 1)',
                 array($code, '', '', '', TIMESTAMP, $endtime), true);
        $made += DB::affected_rows();
    }
    cpmsg('已生成 '.$made.' 个邀请码 / Generated '.$made.' code(s) for the built-in invite system.', $cpurl, 'succeed');
}

// --- generate form ------------------------------------------------------------
showtableheader('生成邀请码 / Generate invitation codes (built-in)');
showformheader($selfurl, 'gensubmit');
showtablerow('', '', '数量 / Count: <input type="text" name="count" value="1" class="txt" /> &nbsp;(1–500)');
showtablerow('', '', '有效期(天) / Expiry days: <input type="text" name="days" value="0" class="txt" /> &nbsp;(0 = never)');
showsubmit('gensubmit', '生成 / Generate');
showtablefooter();
showformfooter();

// --- list admin-generated codes (uid=0) ---------------------------------------
$total  = intval(DB::result_first('SELECT COUNT(*) FROM '.$tbl.' WHERE uid=0'));
$unused = intval(DB::result_first('SELECT COUNT(*) FROM '.$tbl.' WHERE uid=0 AND fuid=0'));
showtableheader('邀请码列表 / Codes &nbsp; ('.$unused.' unused / '.$total.' total)');
echo '<tr class="header"><th>Code</th><th>Status</th><th>Used by</th><th>Created</th><th>Expires</th><th>&nbsp;</th></tr>';
foreach(DB::fetch_all('SELECT * FROM '.$tbl.' WHERE uid=0 ORDER BY fuid ASC, dateline DESC LIMIT 500') as $r) {
    $used    = !empty($r['fuid']);
    $status  = $used ? '<span style="color:#999">used</span>' : '<b style="color:#090">unused</b>';
    $usedby  = $used ? htmlspecialchars($r['fusername']).' (uid '.intval($r['fuid']).')' : '&nbsp;';
    $expires = $r['endtime'] ? dgmdate($r['endtime']) : '∞';
    $del     = $used ? '&nbsp;' : '<a href="'.$linkurl.'&op=delete&id='.intval($r['id']).'">['.$lang['delete'].']</a>';
    echo '<tr>'
       . '<td><strong>'.htmlspecialchars($r['code']).'</strong></td>'
       . '<td>'.$status.'</td><td>'.$usedby.'</td>'
       . '<td>'.dgmdate($r['dateline']).'</td><td>'.$expires.'</td><td>'.$del.'</td>'
       . '</tr>';
}
showtablefooter();
