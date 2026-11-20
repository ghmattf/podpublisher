<?php
/**
 * The [podpublisher] shortcode (placeholder until features exist).
 *
 * @package PodPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PodPub_Shortcode {

	public static function init() {
		add_shortcode( 'podpublisher', array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * @return string
	 */
	public static function render() {
		return '<div class="podpub-app"></div>';
	}
}
