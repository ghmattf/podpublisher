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
	// An adopted feed is written back over its old file, at its old address (set by PodPub_Adopt).
	const META_FEED_FILE = '_podpub_feed_file';
	const META_FEED_URL  = '_podpub_feed_url';

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
			$data['image']       = $name;
			$data['image_w']     = $image['width'];
			$data['image_sizes'] = PodPub_Images::build( $folder, $name, $image['width'] );
			if ( 'gif' === $image['ext'] ) {
				$out['warnings'][] = 'Smaller copies of the show art were not made, because it is a GIF (resizing would stop it moving).';
			}
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
	 * Save the Podcasting 2.0 tags form for a podcast and rewrite the feed.
	 * Nothing is changed when anything is wrong.
	 *
	 * @param int   $id    Record id.
	 * @param array $input Form values (unslashed).
	 * @return array { errors: string[], warnings: string[], written: bool }
	 */
	public static function save_p20( $id, $input ) {
		$out = array( 'errors' => array(), 'warnings' => array(), 'written' => false );
		if ( ! self::get( $id ) || '' === self::dir_name( $id ) ) {
			$out['errors'][] = 'This podcast was not found.';
			return $out;
		}
		$errors = array();
		$data   = PodPub_P20::sanitize_channel( $input, PodPub_Details::get( $id ), $errors );
		if ( $errors ) {
			$out['errors'] = $errors;
			return $out;
		}
		PodPub_Details::save( $id, $data );
		$written = PodPub_FeedXml::write( $id );
		if ( is_wp_error( $written ) ) {
			$out['warnings'][] = 'Saved, but ' . lcfirst( $written->get_error_message() );
		} else {
			$out['written'] = true;
		}
		return $out;
	}

	/**
	 * Where a podcast's feed file is written and what its public address is. Normally
	 * {Podcast folder}/{name}/{name}.xml; an adopted feed keeps its old file and address.
	 *
	 * @param int $id Record id.
	 * @return array { file: string, url: string, custom: bool } file is '' when the podcast has no folder.
	 */
	public static function feed_target( $id ) {
		$file = (string) get_post_meta( (int) $id, self::META_FEED_FILE, true );
		$url  = (string) get_post_meta( (int) $id, self::META_FEED_URL, true );
		if ( '' !== $file && '' !== $url ) {
			return array( 'file' => $file, 'url' => $url, 'custom' => true );
		}
		$dir  = self::dir_name( $id );
		$base = PodPub_Storage::base_url();
		return array(
			'file'   => '' === $dir ? '' : PodPub_Storage::base_path() . '/' . $dir . '/' . $dir . '.xml',
			'url'    => ( '' === $dir || '' === $base ) ? '' : $base . '/' . $dir . '/' . $dir . '.xml',
			'custom' => false,
		);
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

	/**
	 * Delete a podcast: all its episodes and the podcast record, and, only when asked,
	 * its folder on disk (feed file, show art, every episode folder and audio file).
	 * Without the folder option the files stay where they are and the feed address
	 * keeps working. Only a folder whose name is exactly a safe name, inside the
	 * Podcast folder, is ever removed; symbolic links are removed, never followed.
	 *
	 * @param int  $id           Podcast record id.
	 * @param bool $delete_files Also delete the podcast's folder and everything in it.
	 * @return array { errors: string[], warnings: string[], episodes: int, files_deleted: bool, folder: string }
	 */
	public static function delete( $id, $delete_files ) {
		$out  = array( 'errors' => array(), 'warnings' => array(), 'episodes' => 0, 'files_deleted' => false, 'folder' => '' );
		$feed = self::get( $id );
		if ( ! $feed ) {
			$out['errors'][] = 'This podcast was not found.';
			return $out;
		}
		$dir  = self::dir_name( $id );
		$base = PodPub_Storage::base_path();
		$path = ( '' !== $dir && '' !== $base ) ? $base . '/' . $dir : '';
		$out['folder'] = $path;

		if ( $delete_files ) {
			if ( '' === $path || $dir !== PodPub_Storage::slug( $dir, '' ) ) {
				$out['warnings'][] = 'The podcast\'s folder name is not a safe name, so its files were not deleted.';
			} elseif ( ! is_dir( $path ) ) {
				$out['warnings'][] = 'The podcast\'s folder was not found in the Podcast folder, so there were no files to delete.';
			} elseif ( PodPub_Storage::remove_tree( $path ) ) {
				$out['files_deleted'] = true;
			} else {
				$out['warnings'][] = 'Some files in ' . $path . ' could not be deleted (the web server may not be allowed to). Delete that folder by hand.';
			}
		}

		$target = self::feed_target( $id );
		if ( $target['custom'] && $delete_files ) {
			$out['warnings'][] = 'The feed file ' . $target['file'] . ' was adopted from outside the podcast\'s folder, so it was not deleted. A copy of the feed from before it was adopted may be next to it (.bak).';
		}
		foreach ( PodPub_Episodes::all( $id ) as $episode ) {
			wp_delete_post( (int) $episode->ID, true );
			++$out['episodes'];
		}
		wp_delete_post( (int) $id, true );
		return $out;
	}
}
