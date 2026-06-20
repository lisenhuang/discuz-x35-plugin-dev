<?php
/**
 * Best-effort CLI plugin registrar — run INSIDE the web container:
 *     php import-plugin.php <identifier>
 * Registers the plugin from its discuz_plugin_<id>.xml (available=1), runs its
 * install.php, and rebuilds the plugin/hook/setting caches. This is a convenience;
 * the canonical path is Admin CP > Apps > Plugins > import the XML > Enable.
 *
 * NOTE: Discuz's bootstrap clobbers global-scope variables (it uses names like
 * $row internally), so we parse the manifest BEFORE bootstrap and stash the result
 * in a constant, then rebuild it AFTER init().
 */
error_reporting(E_ERROR | E_PARSE);

$pid_arg = isset($argv[1]) ? preg_replace('/[^a-z0-9_]/', '', $argv[1]) : '';
if ($pid_arg === '') { fwrite(STDERR, "usage: php import-plugin.php <identifier>\n"); exit(2); }

$root_dir = '/var/www/html';
$xmlfile = "$root_dir/source/plugin/$pid_arg/discuz_plugin_$pid_arg.xml";
if (!is_file($xmlfile)) { fwrite(STDERR, "manifest not found: $xmlfile\n"); exit(3); }

// --- parse manifest BEFORE bootstrap ------------------------------------------
$x = simplexml_load_file($xmlfile);
if ($x === false) { fwrite(STDERR, "failed to parse manifest\n"); exit(4); }
$pblock = null;
foreach ($x->item as $it) {
    if ((string)$it['id'] === 'Data') {
        foreach ($it->item as $d) { if ((string)$d['id'] === 'plugin') { $pblock = $d; } }
    }
}
if (!$pblock) { fwrite(STDERR, "could not locate <plugin> block\n"); exit(4); }

$nv = function ($node, $id) {
    foreach ($node->item as $i) { if ((string)$i['id'] === $id) return (string)$i; }
    return '';
};
$mods = array();
foreach ($pblock->item as $i) {
    if ((string)$i['id'] === '__modules') {
        foreach ($i->item as $m) {
            $one = array();
            foreach ($m->item as $f) { $one[(string)$f['id']] = (string)$f; }
            $mods[] = $one;
        }
    }
}
$parsed = array(
    'available'   => 1,
    'adminid'     => intval($nv($pblock, 'adminid')),
    'name'        => $nv($pblock, 'name'),
    'identifier'  => $pid_arg,
    'description' => $nv($pblock, 'description'),
    'datatables'  => $nv($pblock, 'datatables'),
    'directory'   => $nv($pblock, 'directory') ?: ($pid_arg . '/'),
    'copyright'   => $nv($pblock, 'copyright'),
    'modules'     => serialize($mods),
    'version'     => $nv($pblock, 'version'),
);
// Stash in constants (survive Discuz bootstrap's global clobbering)
define('PLG_ID', $pid_arg);
define('PLG_ROOT', $root_dir);
define('PLG_ROW_B64', base64_encode(serialize($parsed)));

// --- bootstrap Discuz ----------------------------------------------------------
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['REQUEST_URI']    = '/admin.php';
$_SERVER['SCRIPT_NAME']    = '/admin.php';
$_GET = $_POST = $_COOKIE = array();

chdir($root_dir);
require $root_dir . '/source/class/class_core.php';
$discuz = C::app();
$discuz->init();

// --- rebuild data from constants (assigned AFTER init -> not clobbered) ---------
$plugin_row = unserialize(base64_decode(PLG_ROW_B64));

$found = DB::result_first("SELECT pluginid FROM " . DB::table('common_plugin') . " WHERE identifier=%s", array(PLG_ID));
if ($found) {
    DB::update('common_plugin', $plugin_row, "pluginid='" . intval($found) . "'");
    $new_pluginid = intval($found);
    echo "[import] updated existing plugin (pluginid=$new_pluginid)\n";
} else {
    $new_pluginid = DB::insert('common_plugin', $plugin_row, true);
    echo "[import] inserted plugin (pluginid=$new_pluginid)\n";
}

// run the plugin's own install.php
$installfile = PLG_ROOT . '/source/plugin/' . PLG_ID . '/install.php';
if (is_file($installfile)) { $finish = true; include $installfile; echo "[import] ran install.php\n"; }

// rebuild caches so Discuz sees the plugin + its hooks (same set admincp uses)
require_once PLG_ROOT . '/source/function/function_cache.php';
if (function_exists('updatecache')) { updatecache(array('plugin', 'setting', 'styles')); }
echo "[import] caches rebuilt; plugin '" . PLG_ID . "' registered & enabled (pluginid=$new_pluginid).\n";
