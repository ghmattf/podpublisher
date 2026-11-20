<?php
// Checks the admin menu structure with a tiny stand-in for the WordPress functions it calls.
// Not a real WordPress: it proves the order and slugs of the menu, nothing about how WordPress draws it.
define( 'ABSPATH', __DIR__ );
$GLOBALS['calls'] = array();
$GLOBALS['posts'] = array();

function add_action() {}
function add_menu_page( $t, $m, $cap, $slug ) { $GLOBALS['calls'][] = array( 'top', $m, $slug ); }
function add_submenu_page( $parent, $t, $m, $cap, $slug ) { $GLOBALS['calls'][] = array( 'sub', $m, $slug ); }
function get_posts() { return $GLOBALS['posts']; }
function register_setting() {}
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
class WP_Error { function __construct( $c, $m ) { $this->m = $m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }

require __DIR__ . '/../plugin/includes/class-podpub-settings.php';
require __DIR__ . '/../plugin/includes/class-podpub-feeds.php';
require __DIR__ . '/../plugin/includes/class-podpub-admin.php';

$fail = 0;
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
check( 'settings: unticked is 0', PodPub_Admin::sanitize_settings( array() ) === array( 'delete_data_on_uninstall' => 0 ) );
check( 'settings: ticked is 1', PodPub_Admin::sanitize_settings( array( 'delete_data_on_uninstall' => '1', 'junk' => 'x' ) ) === array( 'delete_data_on_uninstall' => 1 ) );
check( 'settings: non-array is safe', PodPub_Admin::sanitize_settings( 'x' ) === array( 'delete_data_on_uninstall' => 0 ) );

// Empty title is refused.
check( 'create: empty title refused', is_wp_error( PodPub_Feeds::create( '   ' ) ) );

exit( $fail );
