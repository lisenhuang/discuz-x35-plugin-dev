<?php
/**
 * Boot-time cache builder — run INSIDE the web container after the DB is up:
 *     php build-cache.php
 * Rebuilds the style/setting caches so the per-style CSS files
 * (data/cache/style_*.css) exist on a fresh, ephemeral boot. Without this the
 * first page loads unstyled (Discuz does NOT regenerate those .css on a normal
 * request — only updatecache('styles') writes them: see cache_styles.php).
 */
error_reporting(E_ERROR | E_PARSE);

$root = '/var/www/html';
if (!is_file($root . '/config/config_global.php')) {
    fwrite(STDERR, "[build-cache] no config yet (installer mode) — skipping.\n");
    exit(0);
}

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['REQUEST_URI']    = '/admin.php';
$_SERVER['SCRIPT_NAME']    = '/admin.php';
$_GET = $_POST = $_COOKIE = array();

chdir($root);
require $root . '/source/class/class_core.php';
$discuz = C::app();
$discuz->init();

// NOTE: Discuz init() clobbers global vars like $root — use the DISCUZ_ROOT constant now.
require_once DISCUZ_ROOT . './source/function/function_cache.php';
updatecache(array('setting', 'styles'));

echo "[build-cache] style/setting caches rebuilt (CSS files written).\n";
