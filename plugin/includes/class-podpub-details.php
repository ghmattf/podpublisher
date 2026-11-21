<?php
/**
 * A podcast's details (the show-level fields of its feed): the allowed values,
 * cleaning and checking of what the form sends, and the show art checks.
 *
 * @package PodPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PodPub_Details {

	const META            = '_podpub_details';
	const MAX_DESCRIPTION = 4000;
	const MAX_IMAGE_BYTES = 10485760; // 10 MB.

	/**
	 * Languages offered (RSS language codes).
	 *
	 * @return array code => name
	 */
	public static function languages() {
		return array(
			'en' => 'English', 'en-au' => 'English (Australia)', 'en-ca' => 'English (Canada)', 'en-gb' => 'English (United Kingdom)', 'en-us' => 'English (United States)',
			'ar' => 'Arabic', 'bg' => 'Bulgarian', 'zh' => 'Chinese', 'cs' => 'Czech', 'da' => 'Danish', 'nl' => 'Dutch', 'fi' => 'Finnish',
			'fr' => 'French', 'de' => 'German', 'el' => 'Greek', 'he' => 'Hebrew', 'hi' => 'Hindi', 'hu' => 'Hungarian', 'id' => 'Indonesian',
			'it' => 'Italian', 'ja' => 'Japanese', 'ko' => 'Korean', 'no' => 'Norwegian', 'pl' => 'Polish', 'pt' => 'Portuguese',
			'ro' => 'Romanian', 'ru' => 'Russian', 'es' => 'Spanish', 'sv' => 'Swedish', 'th' => 'Thai', 'tr' => 'Turkish', 'uk' => 'Ukrainian', 'vi' => 'Vietnamese',
		);
	}

	/**
	 * Top-level Apple Podcasts categories (the list most podcast apps and directories use).
	 * Sub-categories are not offered yet.
	 *
	 * @return string[]
	 */
	public static function categories() {
		return array(
			'Arts', 'Business', 'Comedy', 'Education', 'Fiction', 'Government', 'Health & Fitness', 'History', 'Kids & Family',
			'Leisure', 'Music', 'News', 'Religion & Spirituality', 'Science', 'Society & Culture', 'Sports', 'Technology', 'True Crime', 'TV & Film',
		);
	}

	/**
	 * Values for a podcast with no details saved yet.
	 *
	 * @return array
	 */
	public static function defaults() {
		$locale = function_exists( 'get_locale' ) ? strtolower( str_replace( '_', '-', (string) get_locale() ) ) : 'en';
		$lang   = isset( self::languages()[ $locale ] ) ? $locale : substr( $locale, 0, 2 );
		if ( ! isset( self::languages()[ $lang ] ) ) {
			$lang = 'en';
		}
		return array(
			'description' => '',
			'language'    => $lang,
			'category'    => '',
			'explicit'    => 0,
			'type'        => 'episodic',
			'author'      => '',
			'link'        => '',
			'owner_name'  => '',
			'owner_email' => '',
			'copyright'   => '',
			'image'       => '', // File name inside the podcast folder (cover.jpg or cover.png), or empty.
			'guid'        => '', // podcast:guid, made once when the podcast is created and never changed.
		);
	}

	/**
	 * Remove characters XML cannot hold, and control characters.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function xml_clean( $s ) {
		$s = (string) $s;
		$c = @preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s );
		return null === $c ? '' : $c;
	}

	/**
	 * Saved details for a podcast (with defaults filled in).
	 *
	 * @param int $id Record id.
	 * @return array
	 */
	public static function get( $id ) {
		$saved = get_post_meta( (int) $id, self::META, true );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * @param int   $id   Record id.
	 * @param array $data Cleaned details.
	 */
	public static function save( $id, $data ) {
		update_post_meta( (int) $id, self::META, $data );
	}

	/**
	 * Clean and check what the form sent. Fields not on the form (image, guid)
	 * are kept from $current. Nothing is saved here.
	 *
	 * @param array $in      Raw form values (already unslashed).
	 * @param array $current The saved details.
	 * @return array { data: array, title: string, errors: string[] }
	 */
	public static function sanitize( $in, $current ) {
		$in     = is_array( $in ) ? $in : array();
		$errors = array();
		$get    = function ( $k ) use ( $in ) {
			return isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) ? (string) $in[ $k ] : '';
		};

		$data = $current;
		$text = function ( $k, $max ) use ( $get ) {
			return mb_substr( trim( self::xml_clean( sanitize_text_field( $get( $k ) ) ) ), 0, $max );
		};

		$title = $text( 'title', 255 );
		if ( '' === $title ) {
			$errors[] = 'Please enter a title for the podcast.';
		}

		$desc = trim( self::xml_clean( sanitize_textarea_field( $get( 'description' ) ) ) );
		if ( '' === $desc ) {
			$errors[] = 'Please enter a description.';
		} elseif ( mb_strlen( $desc ) > self::MAX_DESCRIPTION ) {
			$errors[] = 'The description is too long (the limit is ' . self::MAX_DESCRIPTION . ' characters).';
		}
		$data['description'] = $desc;

		$lang = strtolower( $get( 'language' ) );
		if ( ! isset( self::languages()[ $lang ] ) ) {
			$errors[] = 'Please choose a language from the list.';
		} else {
			$data['language'] = $lang;
		}

		$cat = $get( 'category' );
		if ( '' !== $cat && ! in_array( $cat, self::categories(), true ) ) {
			$errors[] = 'Please choose a category from the list.';
		} else {
			$data['category'] = $cat;
		}

		$data['explicit'] = empty( $in['explicit'] ) ? 0 : 1;
		$data['type']     = 'serial' === $get( 'type' ) ? 'serial' : 'episodic';
		$data['author']   = $text( 'author', 255 );
		$data['copyright'] = $text( 'copyright', 255 );

		$link = trim( $get( 'link' ) );
		if ( '' !== $link ) {
			$link = esc_url_raw( $link );
			$p    = parse_url( $link );
			if ( ! $p || empty( $p['host'] ) || ! in_array( isset( $p['scheme'] ) ? $p['scheme'] : '', array( 'http', 'https' ), true ) ) {
				$errors[] = 'The website address must start with http:// or https://.';
				$link     = '';
			}
		}
		$data['link'] = $link;

		$data['owner_name'] = $text( 'owner_name', 255 );
		$email              = trim( $get( 'owner_email' ) );
		if ( '' !== $email && ! is_email( $email ) ) {
			$errors[] = 'The owner email address does not look right.';
			$email    = '';
		}
		$data['owner_email'] = $email;

		return array(
			'data'   => $data,
			'title'  => $title,
			'errors' => $errors,
		);
	}

	/**
	 * Check a show-art file. JPEG, PNG or GIF, up to 10 MB. A picture that is not
	 * square or not 1400 to 3000 pixels is accepted with a warning (many podcast
	 * apps and directories want that size). A GIF is accepted with a warning that
	 * some directories (such as Apple Podcasts and Spotify) do not take it.
	 *
	 * @param string $path Path of the file to check.
	 * @return array|WP_Error { ext: string, width: int, height: int, warnings: string[] }
	 */
	public static function inspect_image( $path ) {
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'podpub_image', 'The image file was not received.' );
		}
		if ( filesize( $path ) > self::MAX_IMAGE_BYTES ) {
			return new WP_Error( 'podpub_image', 'The image is too large (the limit is 10 MB).' );
		}
		$info = @getimagesize( $path );
		if ( ! $info ) {
			return new WP_Error( 'podpub_image', 'The file is not a picture. Use a JPEG, PNG or GIF image.' );
		}
		$types = array(
			IMAGETYPE_JPEG => 'jpg',
			IMAGETYPE_PNG  => 'png',
			IMAGETYPE_GIF  => 'gif',
		);
		if ( ! isset( $types[ $info[2] ] ) ) {
			return new WP_Error( 'podpub_image', 'The picture must be a JPEG, PNG or GIF image.' );
		}
		$warnings = array();
		if ( IMAGETYPE_GIF === $info[2] ) {
			$warnings[] = 'The show art is a GIF. Podcasting 2.0 apps can show it, but some directories and apps (for example Apple Podcasts and Spotify) only take JPEG or PNG.';
		}
		if ( $info[0] !== $info[1] ) {
			$warnings[] = 'The show art is ' . $info[0] . ' x ' . $info[1] . ' pixels. Podcast apps expect a square picture.';
		} elseif ( $info[0] < 1400 || $info[0] > 3000 ) {
			$warnings[] = 'The show art is ' . $info[0] . ' x ' . $info[1] . ' pixels. Podcast directories expect between 1400 x 1400 and 3000 x 3000.';
		}
		return array(
			'ext'      => $types[ $info[2] ],
			'width'    => (int) $info[0],
			'height'   => (int) $info[1],
			'warnings' => $warnings,
		);
	}

	/**
	 * Put a checked show-art file into the podcast folder as cover.jpg / cover.png /
	 * cover.gif (the uploaded file's own name is never used). An old cover of
	 * another type is removed.
	 *
	 * @param string $tmp     Path of the checked file.
	 * @param string $dir     Full path of the podcast folder.
	 * @param string $ext     jpg, png or gif (from inspect_image).
	 * @return string|WP_Error File name.
	 */
	public static function store_image( $tmp, $dir, $ext ) {
		if ( ! in_array( $ext, array( 'jpg', 'png', 'gif' ), true ) || ! is_dir( $dir ) ) {
			return new WP_Error( 'podpub_image', 'The image could not be saved.' );
		}
		$name  = 'cover.' . $ext;
		$stage = $dir . '/.cover_upload_' . getmypid();
		if ( ! @copy( $tmp, $stage ) ) {
			return new WP_Error( 'podpub_image', 'The image could not be saved. Check that the podcast folder is writable.' );
		}
		if ( ! @rename( $stage, $dir . '/' . $name ) ) {
			@unlink( $stage );
			return new WP_Error( 'podpub_image', 'The image could not be saved.' );
		}
		foreach ( array( 'jpg', 'png', 'gif' ) as $other_ext ) {
			if ( $other_ext !== $ext && is_file( $dir . '/cover.' . $other_ext ) ) {
				@unlink( $dir . '/cover.' . $other_ext );
			}
		}
		return $name;
	}
}
