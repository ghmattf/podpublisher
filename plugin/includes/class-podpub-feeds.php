<?php
/**
 * The podcasts being managed. Each one is a record of a private post type
 * (no front-end URL, no WordPress editor screen).
 *
 * @package PodPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PodPub_Feeds {

	const POST_TYPE = 'podpub_feed';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'        => 'Podcasts',
				'public'       => false,
				'show_ui'      => false,
				'show_in_rest' => false,
				'rewrite'      => false,
				'query_var'    => false,
				'supports'     => array( 'title' ),
			)
		);
	}

	/**
	 * All managed podcasts, A to Z.
	 *
	 * @return WP_Post[]
	 */
	public static function all() {
		return get_posts(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
	}

	/**
	 * One managed podcast, or null.
	 *
	 * @param int $id Record id.
	 * @return WP_Post|null
	 */
	public static function get( $id ) {
		$post = get_post( (int) $id );
		if ( $post && self::POST_TYPE === $post->post_type && 'publish' === $post->post_status ) {
			return $post;
		}
		return null;
	}

	/**
	 * Create a podcast record.
	 *
	 * @param string $title Podcast title.
	 * @return int|WP_Error New id.
	 */
	public static function create( $title ) {
		$title = trim( sanitize_text_field( $title ) );
		if ( '' === $title ) {
			return new WP_Error( 'podpub_no_title', 'Please enter a title for the podcast.' );
		}
		$dir = PodPub_Storage::create_podcast_dir( $title );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			@unlink( PodPub_Storage::base_path() . '/' . $dir . '/index.html' ); // phpcs:ignore
			@rmdir( PodPub_Storage::base_path() . '/' . $dir ); // phpcs:ignore
			return $id;
		}
		update_post_meta( $id, '_podpub_dir', $dir );
		return $id;
	}

	/**
	 * The folder name (not the full path) a podcast's files live in.
	 *
	 * @param int $id Record id.
	 * @return string
	 */
	public static function dir_name( $id ) {
		return (string) get_post_meta( (int) $id, '_podpub_dir', true );
	}
}
