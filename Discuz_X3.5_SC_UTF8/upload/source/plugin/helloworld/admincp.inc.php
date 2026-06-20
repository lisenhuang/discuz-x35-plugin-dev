<?php
if(!defined('IN_DISCUZ')) { exit('Access Denied'); }

/**
 * Admin settings module (module type 3) for "helloworld".
 * Reached via: Admin CP > Apps > Plugins > Hello World > Settings.
 */
cpheader();
showtableheader('Hello World');
showtablerow('', array(), array('Edit this file (source/plugin/helloworld/admincp.inc.php) to build your settings UI.'));
showtablefooter();
