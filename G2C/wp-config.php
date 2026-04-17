<?php
# Database Configuration
define( 'DB_NAME', 'wp_hamonym1stg' );
define( 'DB_USER', 'hamonym1stg' );
define( 'DB_PASSWORD', 'y-i7wxmtT7Ws8xv-QVeq' );
define( 'DB_HOST', '127.0.0.1:3306' );
define( 'DB_HOST_SLAVE', '127.0.0.1:3306' );
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', 'utf8_unicode_ci');
$table_prefix = 'wp_';

# Security Salts, Keys, Etc
define('AUTH_KEY',         '^dXk1:m;j~hW)yz)ye>;CFtJYD,h4/&Ia~B:%&hEP;!R]y,Xk! W:gk<hR{u{*!P');
define('SECURE_AUTH_KEY',  'z7V`F$ucoe>R1uo)P|KMc07xF=N?K%1SiTSn&Z;-i+OPr&(>|@axCTy+7C%1}C`M');
define('LOGGED_IN_KEY',    'H;+-3C2Y^?B!`5%u=QfC=.rixJ&6571oK-k:/iuY(k,qEOGj=,JTBVppc]!7IZHj');
define('NONCE_KEY',        'wghlz},/:T{7Dxq!B]o%}@fvSH8_/Uh9(7o?YqX 3;3MR%{mVoSWyF1ImG*Y5L]<');
define('AUTH_SALT',        'Zp-n?V4dCf#$?xR SWrkZKuS5C~@r3$~^qf;UOrtU.fMGh`?!H:5Z[(iZ~4`fHo<');
define('SECURE_AUTH_SALT', 'FJeaZ#Nan|~}Y8Ekt!T/(69&~ 6[CmN_6`74@3]D9{rKUb#B#AtT+5$$dqb|;t`=');
define('LOGGED_IN_SALT',   ')OK=_|nW73i$/nU[&JTo,.^/Jk[^|kAV8pv+z_ 1^HLXc 7t0GnRGo<A 1xf8r9:');
define('NONCE_SALT',       'J$<S4/r_Xv?AgkQsqvIOL5;d5R&]7qQ*0`x/%RLuqWx5|+0wjrHFyh+=X]j[|> :');


# Localized Language Stuff

define( 'WP_CACHE', TRUE );

define( 'WP_AUTO_UPDATE_CORE', false );

define( 'PWP_NAME', 'hamonym1stg' );

define( 'FS_METHOD', 'direct' );

define( 'FS_CHMOD_DIR', 0775 );

define( 'FS_CHMOD_FILE', 0664 );

define( 'PWP_ROOT_DIR', '/nas/wp' );

define( 'WPE_APIKEY', '039d09e3d6a78b72ef8bbf53ce41e34244ca5248' );

define( 'WPE_CLUSTER_ID', '217066' );

define( 'WPE_CLUSTER_TYPE', 'pod' );

define( 'WPE_ISP', true );

define( 'WPE_BPOD', false );

define( 'WPE_RO_FILESYSTEM', false );

define( 'WPE_LARGEFS_BUCKET', 'largefs.wpengine' );

define( 'WPE_SFTP_PORT', 2222 );

define( 'WPE_LBMASTER_IP', '' );

define( 'WPE_CDN_DISABLE_ALLOWED', true );

define( 'DISALLOW_FILE_MODS', FALSE );

define( 'DISALLOW_FILE_EDIT', FALSE );

define( 'DISABLE_WP_CRON', false );

define( 'WPE_FORCE_SSL_LOGIN', false );

define( 'FORCE_SSL_LOGIN', false );

/*SSLSTART*/ if ( isset($_SERVER['HTTP_X_WPE_SSL']) && $_SERVER['HTTP_X_WPE_SSL'] ) $_SERVER['HTTPS'] = 'on'; /*SSLEND*/

define( 'WPE_EXTERNAL_URL', false );

define( 'WP_POST_REVISIONS', FALSE );

define( 'WPE_WHITELABEL', 'wpengine' );

define( 'WP_TURN_OFF_ADMIN_BAR', false );

define( 'WPE_BETA_TESTER', false );

umask(0002);

$wpe_cdn_uris=array ( );

$wpe_no_cdn_uris=array ( );

$wpe_content_regexs=array ( );

$wpe_all_domains=array ( 0 => 'hamonym1stg.wpengine.com', 1 => 'hamonym1stg.wpenginepowered.com', );

$wpe_varnish_servers=array ( 0 => '127.0.0.1', );

$wpe_special_ips=array ( 0 => '35.197.219.23', 1 => 'pod-217066-utility.pod-217066.svc.cluster.local', );

$wpe_netdna_domains=array ( );

$wpe_netdna_domains_secure=array ( );

$wpe_netdna_push_domains=array ( );

$wpe_domain_mappings=array ( );

$memcached_servers=array ( 'default' =>  array ( 0 => 'unix:///tmp/memcached.sock', ), );

define( 'WPE_SFTP_ENDPOINT', '35.197.248.197' );
define('WPLANG','');

# WP Engine ID


# WP Engine Settings

// oauth detials for mida rest API
define('MIDA_BASE_URL', 'https://mida.org.il');
define('MIDA_CONSUMER_KEY', 'Fbm21Ebs00KI');
define('MIDA_CONSUMER_SECRET', '3JKMIRSWKIN8YtBlpueOYtRgQO1yXrTCOtwaT9GOHIlCWo04');
define('MIDA_OAUTH_TOKEN', 'LvDzOvydQkYBIT35L7viAklh');
define('MIDA_OAUTH_SECRET', 'bnZFrDSS8FEuLcNjaWHF0MYOGUwtRk6EJTxajrjnGDOfFYeh');




# That's It. Pencils down
if ( !defined('ABSPATH') )
	define('ABSPATH', __DIR__ . '/');
require_once(ABSPATH . 'wp-settings.php');
