<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 * Podcasts and settings are only removed if "Delete podcasts and settings" was ticked.
 *
 * @package PodPublisher
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

define( 'PODPUB_DIR', plugin_dir_path( __FILE__ ) );
require_once PODPUB_DIR . 'includes/class-podpub-settings.php';
require_once PODPUB_DIR . 'includes/class-podpub-feeds.php';
require_once PODPUB_DIR . 'includes/class-podpub-episodes.php';

if ( PodPub_Settings::get( 'delete_data_on_uninstall' ) ) {
	$podpub_ids = get_posts(
		array(
			'post_type'   => array( PodPub_Feeds::POST_TYPE, PodPub_Episodes::POST_TYPE ),
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);
	foreach ( $podpub_ids as $podpub_id ) {
		wp_delete_post( $podpub_id, true );
	}
	delete_option( PodPub_Settings::OPTION );
}
