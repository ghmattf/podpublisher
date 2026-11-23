<?php
// Checks the admin menu structure with a tiny stand-in for the WordPress functions it calls.
// Not a real WordPress: it proves the order and slugs of the menu, nothing about how WordPress draws it.
define( 'ABSPATH', __DIR__ );
$GLOBALS['calls'] = array();
$GLOBALS['posts'] = array();
$GLOBALS['opts']  = array();
$GLOBALS['errors'] = array();

function add_action() {}
function add_menu_page( $t, $m, $cap, $slug ) { $GLOBALS['calls'][] = array( 'top', $m, $slug ); }
function add_submenu_page( $parent, $t, $m, $cap, $slug ) { $GLOBALS['calls'][] = array( 'sub', $m, $slug ); }
function get_posts() { return $GLOBALS['posts']; }
function register_setting() {}
function get_option( $k, $d = false ) { return isset( $GLOBALS['opts'][ $k ] ) ? $GLOBALS['opts'][ $k ] : $d; }
function esc_url_raw( $u ) { return $u; }
function add_settings_error( $g, $c, $m, $t ) { $GLOBALS['errors'][] = $c; }
function wp_upload_dir() { return array( 'basedir' => '/tmp/podpub_menu_uploads', 'baseurl' => 'https://example.com/wp-content/uploads' ); }
function wp_mkdir_p( $d ) { return true; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
class WP_Error { function __construct( $c, $m ) { $this->m = $m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }

require __DIR__ . '/../plugin/includes/class-podpub-settings.php';
require __DIR__ . '/../plugin/includes/class-podpub-storage.php';
require __DIR__ . '/../plugin/includes/class-podpub-feeds.php';
require __DIR__ . '/../plugin/includes/class-podpub-admin.php';

$fail = 0;
function errs() { $e = array_values( array_diff( $GLOBALS['errors'], array( 'podpub_writable' ) ) ); $GLOBALS['errors'] = array(); return $e; }
function check( $name, $ok ) { global $fail; echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n"; if ( ! $ok ) { $fail = 1; } }

// No podcasts: top item, All podcasts, Settings.
PodPub_Admin::register_menu();
$labels = array_map( function ( $c ) { return $c[1]; }, $GLOBALS['calls'] );
check( 'empty: PodPublisher, All podcasts, Settings', $labels === array( 'PodPublisher', 'All podcasts', 'Settings' ) );

// Two podcasts: one sub-item each, between All podcasts and Settings, Settings last.
$GLOBALS['calls'] = array();
$GLOBALS['posts'] = array(
	(object) array( 'ID' => 7, 'post_title' => 'Alpha Show' ),
	(object) array( 'ID' => 9, 'post_title' => 'Beta Show' ),
);
PodPub_Admin::register_menu();
$labels = array_map( function ( $c ) { return $c[1]; }, $GLOBALS['calls'] );
$slugs  = array_map( function ( $c ) { return $c[2]; }, $GLOBALS['calls'] );
check( 'two feeds: order', $labels === array( 'PodPublisher', 'All podcasts', 'Alpha Show', 'Beta Show', 'Settings' ) );
check( 'two feeds: slugs', $slugs === array( 'podpublisher', 'podpublisher', 'podpublisher-feed-7', 'podpublisher-feed-9', 'podpublisher-settings' ) );

// Settings sanitising.
$base = array( 'delete_data_on_uninstall' => 0, 'storage_path' => '', 'storage_url' => '', 'geo_enabled' => 0, 'geo_url' => '' );
check( 'settings: defaults', PodPub_Admin::sanitize_settings( array() ) === $base );
check( 'settings: ticked is 1, junk dropped', PodPub_Admin::sanitize_settings( array( 'delete_data_on_uninstall' => '1', 'junk' => 'x' ) ) === array_merge( $base, array( 'delete_data_on_uninstall' => 1 ) ) );
check( 'settings: non-array is safe', PodPub_Admin::sanitize_settings( 'x' ) === $base );
$r = PodPub_Admin::sanitize_settings( array( 'storage_path' => '/tmp/podpub_menu_uploads/my_pods/' ) );
check( 'settings: folder inside uploads kept, slash trimmed', $r['storage_path'] === '/tmp/podpub_menu_uploads/my_pods' && errs() === array() );
$r = PodPub_Admin::sanitize_settings( array( 'storage_path' => '/tmp/podpub_menu_uploads/my pods' ) );
check( 'settings: folder with a space refused', $r['storage_path'] === '' && errs() === array( 'podpub_path' ) );
$r = PodPub_Admin::sanitize_settings( array( 'storage_path' => '/srv/pods' ) );
check( 'settings: folder outside uploads needs an address', $r['storage_path'] === '' && errs() === array( 'podpub_url_needed' ) );
$r = PodPub_Admin::sanitize_settings( array( 'storage_path' => '/srv/pods', 'storage_url' => 'https://cdn.example.com/pods/' ) );
check( 'settings: folder outside uploads ok with an address', $r['storage_path'] === '/srv/pods' && $r['storage_url'] === 'https://cdn.example.com/pods' && errs() === array() );
$r = PodPub_Admin::sanitize_settings( array( 'storage_url' => 'https://example.com/bad path' ) );
check( 'settings: address with a space refused', $r['storage_url'] === '' && errs() === array( 'podpub_url' ) );

// A valid but not-yet-writable folder is saved, with a warning.
$GLOBALS['errors'] = array();
$r = PodPub_Admin::sanitize_settings( array( 'storage_path' => '/tmp/podpub_menu_uploads/not_there_yet' ) );
check( 'settings: unwritable folder saved with a warning', $r['storage_path'] === '/tmp/podpub_menu_uploads/not_there_yet' && $GLOBALS['errors'] === array( 'podpub_writable' ) );

// Empty title is refused.
check( 'create: empty title refused', is_wp_error( PodPub_Feeds::create( '   ' ) ) );

exit( $fail );
