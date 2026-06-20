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

// Discuz bakes ABSOLUTE url()s into data/cache/style_*.css by prefixing every CSS
// asset path with $_G['siteurl'] (see source/function/cache/cache_styles.php). That
// siteurl is derived from $_SERVER: host from HTTP_HOST, path from PHP_SELF/SCRIPT_NAME
// (discuz_application::_get_script_url()). In a bare CLI run PHP_SELF is this script's
// own path (/usr/local/bin/build-cache.php) and the host carries no port — so the icon
// font (and every CSS background) gets baked as http://localhost/usr/local/bin/static/...
// which 404s, and font glyphs render as empty squares. Spoof a real web hit on
// http://localhost:<DZ_PORT>/admin.php so the baked URLs match how the user reaches the site.
$port = getenv('DZ_PORT');
$port = ($port !== false && $port !== '') ? $port : '80';
$host = 'localhost'.($port === '80' ? '' : ':'.$port);

$_SERVER['HTTP_HOST']       = $host;
$_SERVER['SERVER_NAME']     = 'localhost';
$_SERVER['SERVER_PORT']     = $port;
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['DOCUMENT_ROOT']   = $root;
$_SERVER['SCRIPT_FILENAME'] = $root.'/admin.php';   // basename feeds _get_script_url()
$_SERVER['SCRIPT_NAME']     = '/admin.php';          // -> PHP_SELF=/admin.php -> sitepath=''
$_SERVER['PHP_SELF']        = '/admin.php';
$_SERVER['REQUEST_URI']     = '/admin.php';
$_GET = $_POST = $_COOKIE = array();

chdir($root);
require $root . '/source/class/class_core.php';
$discuz = C::app();
$discuz->init();

// NOTE: Discuz init() clobbers global vars like $root — use the DISCUZ_ROOT constant now.
require_once DISCUZ_ROOT . './source/function/function_cache.php';
updatecache(array('setting', 'styles'));

echo "[build-cache] style/setting caches rebuilt (CSS files written).\n";
