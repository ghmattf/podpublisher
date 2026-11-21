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
	 * Save the details form: check everything, and only if it is all fine save the
	 * details, the show art and the title, then write the feed file. Nothing is
	 * changed when there are errors. The folder name never changes, even if the
	 * title does (the feed address must stay the same).
	 *
	 * @param int        $id    Record id.
	 * @param array      $input Form values (unslashed).
	 * @param array|null $file  One entry of $_FILES for the show art (already confirmed to be an upload), or null.
	 * @return array { errors: string[], warnings: string[], written: bool }
	 */
	public static function save_details( $id, $input, $file = null ) {
		$out  = array( 'errors' => array(), 'warnings' => array(), 'written' => false );
		$post = self::get( $id );
		$dir  = self::dir_name( $id );
		if ( ! $post || '' === $dir ) {
			$out['errors'][] = 'This podcast was not found.';
			return $out;
		}
		$r    = PodPub_Details::sanitize( $input, PodPub_Details::get( $id ) );
		$data = $r['data'];
		$out['errors'] = $r['errors'];

		$image = null;
		if ( is_array( $file ) && isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $file['error'] ) {
			if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
				$out['errors'][] = 'The show art upload failed. Try a smaller picture.';
			} else {
				$image = PodPub_Details::inspect_image( $file['tmp_name'] );
				if ( is_wp_error( $image ) ) {
					$out['errors'][] = $image->get_error_message();
					$image           = null;
				} else {
					$out['warnings'] = array_merge( $out['warnings'], $image['warnings'] );
				}
			}
		}
		if ( $out['errors'] ) {
			$out['warnings'] = array();
			return $out;
		}

		$folder = PodPub_Storage::base_path() . '/' . $dir;
		if ( $image ) {
			$name = PodPub_Details::store_image( $file['tmp_name'], $folder, $image['ext'] );
			if ( is_wp_error( $name ) ) {
				$out['errors'][] = $name->get_error_message();
				$out['warnings'] = array();
				return $out;
			}
			$data['image'] = $name;
		}
		if ( '' === $data['guid'] ) {
			$base         = PodPub_Storage::base_url();
			$data['guid'] = '' === $base ? PodPub_FeedXml::random_guid() : PodPub_FeedXml::guid_for_url( $base . '/' . $dir . '/' . $dir . '.xml' );
		}
		PodPub_Details::save( $id, $data );
		if ( $r['title'] !== $post->post_title ) {
			wp_update_post( array( 'ID' => (int) $id, 'post_title' => $r['title'] ) );
		}
		$written = PodPub_FeedXml::write( $id );
		if ( is_wp_error( $written ) ) {
			$out['warnings'][] = 'Details saved, but ' . lcfirst( $written->get_error_message() );
		} else {
			$out['written'] = true;
		}
		return $out;
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
