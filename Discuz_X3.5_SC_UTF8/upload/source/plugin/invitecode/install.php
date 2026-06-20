<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

// This plugin now uses Discuz's BUILT-IN invite table (common_invite) — no custom
// table needed. Drop the old custom table from earlier versions if it exists.
DB::query("DROP TABLE IF EXISTS ".DB::table('invitecode'), array(), true);

$finish = TRUE;
