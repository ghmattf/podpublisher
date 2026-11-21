<?php
/**
 * Plugin Name:       PodPublisher
 * Description:       Manage Podcasting 2.0 feeds and files. Use the [podpublisher] shortcode.
 * Version:           0.4.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Phafe
 * License:           GPL-2.0-or-later
 * Text Domain:       podpublisher
 *
 * @package PodPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PODPUB_VERSION', '0.4.0' );
define( 'PODPUB_FILE', __FILE__ );
define( 'PODPUB_DIR', plugin_dir_path( __FILE__ ) );
define( 'PODPUB_URL', plugin_dir_url( __FILE__ ) );

require_once PODPUB_DIR . 'includes/class-podpub-settings.php';
require_once PODPUB_DIR . 'includes/class-podpub-storage.php';
require_once PODPUB_DIR . 'includes/class-podpub-details.php';
require_once PODPUB_DIR . 'includes/class-podpub-feedxml.php';
require_once PODPUB_DIR . 'includes/class-podpub-feeds.php';
require_once PODPUB_DIR . 'includes/class-podpub-shortcode.php';
require_once PODPUB_DIR . 'includes/class-podpub-admin.php';

/**
 * Bootstrap.
 */
function podpub_init() {
	PodPub_Feeds::init();
	PodPub_Shortcode::init();
	if ( is_admin() ) {
		PodPub_Admin::init();
	}
}
add_action( 'plugins_loaded', 'podpub_init' );
