<?php


$_config = array();

// ----------------------------  CONFIG DB  ----------------------------- //
$_config['db'][1]['dbhost'] = 'db';
$_config['db'][1]['dbuser'] = 'discuz';
$_config['db'][1]['dbpw'] = 'discuz';
$_config['db'][1]['dbcharset'] = 'utf8mb4';
$_config['db'][1]['pconnect'] = 0;
$_config['db'][1]['dbname'] = 'discuz';
$_config['db'][1]['tablepre'] = 'pre_';
$_config['db']['slave'] = '';
$_config['db']['common']['slave_except_table'] = '';
$_config['db']['common']['engine'] = 'innodb';

// --------------------------  CONFIG MEMORY  --------------------------- //
$_config['memory']['prefix'] = 'YvezXU_';
$_config['memory']['redis']['server'] = '';
$_config['memory']['redis']['port'] = 6379;
$_config['memory']['redis']['pconnect'] = 1;
$_config['memory']['redis']['timeout'] = 0;
$_config['memory']['redis']['requirepass'] = '';
$_config['memory']['redis']['db'] = 0;
$_config['memory']['memcache']['server'] = '';
$_config['memory']['memcache']['port'] = 11211;
$_config['memory']['memcache']['pconnect'] = 1;
$_config['memory']['memcache']['timeout'] = 1;
$_config['memory']['memcached']['server'] = '';
$_config['memory']['memcached']['port'] = 11211;
$_config['memory']['apc'] = 0;
$_config['memory']['apcu'] = 0;
$_config['memory']['xcache'] = 0;
$_config['memory']['eaccelerator'] = 0;
$_config['memory']['wincache'] = 0;
$_config['memory']['yac'] = 0;
$_config['memory']['file']['server'] = '';

// --------------------------  CONFIG SERVER  --------------------------- //
$_config['server']['id'] = 1;

// -------------------------  CONFIG DOWNLOAD  -------------------------- //
$_config['download']['readmod'] = 2;
$_config['download']['xsendfile']['type'] = 0;
$_config['download']['xsendfile']['dir'] = '/down/';

// --------------------------  CONFIG OUTPUT  --------------------------- //
$_config['output']['charset'] = 'utf-8';
$_config['output']['forceheader'] = 1;
$_config['output']['gzip'] = 0;
$_config['output']['tplrefresh'] = 1;
$_config['output']['language'] = 'zh_cn';
$_config['output']['staticurl'] = 'static/';
$_config['output']['ajaxvalidate'] = 0;
$_config['output']['upgradeinsecure'] = 0;
$_config['output']['css4legacyie'] = 1;

// --------------------------  CONFIG COOKIE  --------------------------- //
$_config['cookie']['cookiepre'] = 'nVi6_';
$_config['cookie']['cookiedomain'] = '';
$_config['cookie']['cookiepath'] = '/';

// -------------------------  CONFIG SECURITY  -------------------------- //
$_config['security']['authkey'] = '579ea68c8bf98dcb3cf77121b33e1234vCPord9EM1vTuMpRXn3aY7dDLgqFCfoJ';
$_config['security']['urlxssdefend'] = 1;
$_config['security']['attackevasive'] = 0;
$_config['security']['onlyremoteaddr'] = 1;
$_config['security']['useipban'] = 1;
$_config['security']['querysafe']['status'] = 1;
$_config['security']['querysafe']['dfunction'][0] = 'load_file';
$_config['security']['querysafe']['dfunction'][1] = 'hex';
$_config['security']['querysafe']['dfunction'][2] = 'substring';
$_config['security']['querysafe']['dfunction'][3] = 'if';
$_config['security']['querysafe']['dfunction'][4] = 'ord';
$_config['security']['querysafe']['dfunction'][5] = 'char';
$_config['security']['querysafe']['daction'][0] = '@';
$_config['security']['querysafe']['daction'][1] = 'intooutfile';
$_config['security']['querysafe']['daction'][2] = 'intodumpfile';
$_config['security']['querysafe']['daction'][3] = 'unionselect';
$_config['security']['querysafe']['daction'][4] = '(select';
$_config['security']['querysafe']['daction'][5] = 'unionall';
$_config['security']['querysafe']['daction'][6] = 'uniondistinct';
$_config['security']['querysafe']['dnote'][0] = '/*';
$_config['security']['querysafe']['dnote'][1] = '*/';
$_config['security']['querysafe']['dnote'][2] = '#';
$_config['security']['querysafe']['dnote'][3] = '--';
$_config['security']['querysafe']['dnote'][4] = '"';
$_config['security']['querysafe']['dlikehex'] = 1;
$_config['security']['querysafe']['afullnote'] = 0;
$_config['security']['creditsafe']['second'] = 0;
$_config['security']['creditsafe']['times'] = 10;
$_config['security']['fsockopensafe']['port'][0] = 80;
$_config['security']['fsockopensafe']['port'][1] = 443;
$_config['security']['fsockopensafe']['ipversion'][0] = 'ipv6';
$_config['security']['fsockopensafe']['ipversion'][1] = 'ipv4';
$_config['security']['fsockopensafe']['verifypeer'] = '';
$_config['security']['error']['showerror'] = '1';
$_config['security']['error']['guessplugin'] = '1';

// --------------------------  CONFIG ADMINCP  -------------------------- //
// -------- Founders: $_config['admincp']['founder'] = '1,2,3'; --------- //
$_config['admincp']['founder'] = '1';
$_config['admincp']['forcesecques'] = 0;
$_config['admincp']['checkip'] = 1;
$_config['admincp']['runquery'] = 0;
$_config['admincp']['dbimport'] = 1;
$_config['admincp']['mustlogin'] = 1;

// --------------------------  CONFIG REMOTE  --------------------------- //
$_config['remote']['on'] = 0;
$_config['remote']['dir'] = 'remote';
$_config['remote']['appkey'] = '62cf0b3c3e6a4c9468e7216839721d8e';
$_config['remote']['cron'] = 0;

// ---------------------------  CONFIG INPUT  --------------------------- //
$_config['input']['compatible'] = 0;

// ---------------------------  CONFIG IPDB  ---------------------------- //
$_config['ipdb']['setting']['fullstack'] = '';
$_config['ipdb']['setting']['default'] = '';
$_config['ipdb']['setting']['ipv4'] = 'tiny';
$_config['ipdb']['setting']['ipv6'] = 'v6wry';

// -------------------------  CONFIG IPGETTER  -------------------------- //
$_config['ipgetter']['setting'] = 'header';
$_config['ipgetter']['header']['header'] = 'HTTP_X_FORWARDED_FOR';
$_config['ipgetter']['iplist']['header'] = 'HTTP_X_FORWARDED_FOR';
$_config['ipgetter']['iplist']['list'][0] = '127.0.0.1';
$_config['ipgetter']['dnslist']['header'] = 'HTTP_X_FORWARDED_FOR';
$_config['ipgetter']['dnslist']['list'][0] = 'comsenz.com';


// -------------------  THE END  -------------------- //

?>