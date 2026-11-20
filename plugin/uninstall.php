<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 * Settings are only removed if "Delete data on uninstall" was ticked.
 *
 * @package PodPublisher
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

define( 'PODPUB_DIR', plugin_dir_path( __FILE__ ) );
require_once PODPUB_DIR . 'includes/class-podpub-settings.php';

if ( PodPub_Settings::get( 'delete_data_on_uninstall' ) ) {
	delete_option( PodPub_Settings::OPTION );
}
