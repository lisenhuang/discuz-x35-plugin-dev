<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

// Drop the audit log table. The serialized settings in common_setting['aiagent'] are left in place
// (harmless) so reinstalling restores the previous configuration.
DB::query("DROP TABLE IF EXISTS ".DB::table('aiagent_log'), array(), true);

$finish = TRUE;
