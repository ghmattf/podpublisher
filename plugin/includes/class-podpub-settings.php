<?php
/**
 * Plugin settings (single option row).
 *
 * @package PodPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PodPub_Settings {

	const OPTION = 'podpub_settings';

	/**
	 * Default values. Add a key here for every new setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'delete_data_on_uninstall' => 0,
			'storage_path'             => '', // Empty = default folder (PodPub_Storage::default_path()).
			'storage_url'              => '', // Empty = worked out from the folder when it is inside uploads.
			'geo_enabled'              => 1,  // Location search on or off.
			'geo_url'                  => '', // Empty = the public OpenStreetMap Nominatim (PodPub_Geo::DEFAULT_URL).
		);
	}

	/**
	 * Get one setting (or the default).
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$defaults = self::defaults();
		$saved    = get_option( self::OPTION, array() );
		if ( is_array( $saved ) && array_key_exists( $key, $saved ) ) {
			return $saved[ $key ];
		}
		return array_key_exists( $key, $defaults ) ? $defaults[ $key ] : null;
	}
}
