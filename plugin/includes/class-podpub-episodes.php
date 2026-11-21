<?php
/**
 * Episodes of a podcast. Each episode is a record of a private post type (child
 * of its podcast's record) and has its own folder inside the podcast folder,
 * holding its audio file as {folder}/{folder}.{ext}.
 *
 * @package PodPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PodPub_Episodes {

	const POST_TYPE       = 'podpub_episode';
	const META            = '_podpub_ep';
	const MAX_DESCRIPTION = 4000;
	const MAX_SHOW_NOTES  = 50000;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'        => 'Episodes',
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
	 * Audio types accepted: extension => MIME type for the feed's enclosure.
	 *
	 * @return array
	 */
	public static function audio_types() {
		return array(
			'mp3'  => 'audio/mpeg',
			'm4a'  => 'audio/mp4',
			'ogg'  => 'audio/ogg',
			'opus' => 'audio/opus',
			'wav'  => 'audio/wav',
			'flac' => 'audio/flac',
		);
	}

	/**
	 * @return array
	 */
	public static function defaults() {
		return array_merge( array(
			'description' => '',
			'show_notes'  => '',
			'status'      => 'draft', // draft | published.
			'published'   => 0,       // Unix time shown as the episode's date.
			'season'      => 0,
			'episode'     => 0,
			'type'        => 'full',  // full | trailer | bonus.
			'duration'    => 0,       // Seconds.
			'audio_source' => '',     // upload | folder | external ('' = no audio yet).
			'audio'       => '',      // File name inside the episode folder (upload or folder).
			'external_url' => '',     // https address when the audio is hosted elsewhere.
			'audio_type'  => '',
			'audio_size'  => 0,
			'guid'        => '',      // Made once, never changes.
		), PodPub_P20::defaults( true ) );
	}

	/**
	 * Episodes of a podcast, newest first.
	 *
	 * @param int $podcast_id Podcast record id.
	 * @return WP_Post[]
	 */
	public static function all( $podcast_id ) {
		$posts = get_posts(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_parent' => (int) $podcast_id,
				'numberposts' => -1,
			)
		);
		usort(
			$posts,
			function ( $a, $b ) {
				return self::details( $b->ID )['published'] <=> self::details( $a->ID )['published'];
			}
		);
		return $posts;
	}

	/**
	 * One episode of this podcast, or null (an episode of another podcast is not returned).
	 *
	 * @param int $id         Episode record id.
	 * @param int $podcast_id Podcast record id.
	 * @return WP_Post|null
	 */
	public static function get( $id, $podcast_id ) {
		$post = get_post( (int) $id );
		if ( $post && self::POST_TYPE === $post->post_type && 'publish' === $post->post_status && (int) $post->post_parent === (int) $podcast_id ) {
			return $post;
		}
		return null;
	}

	public static function details( $id ) {
		$saved = get_post_meta( (int) $id, self::META, true );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function dir_name( $id ) {
		return (string) get_post_meta( (int) $id, '_podpub_dir', true );
	}

	/**
	 * Parse a duration: seconds ("754"), m:ss or h:mm:ss. Empty is 0.
	 *
	 * @param string $text Text.
	 * @return int|null Seconds, or null when it is not a duration.
	 */
	public static function parse_duration( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return 0;
		}
		if ( ! preg_match( '/^\d{1,6}(:[0-5]?\d){0,2}$/', $text ) ) {
			return null;
		}
		$parts = array_map( 'intval', explode( ':', $text ) );
		$s     = 0;
		foreach ( $parts as $p ) {
			$s = $s * 60 + $p;
		}
		return $s;
	}

	public static function format_duration( $seconds ) {
		$seconds = (int) $seconds;
		return sprintf( '%d:%02d:%02d', intdiv( $seconds, 3600 ), intdiv( $seconds % 3600, 60 ), $seconds % 60 );
	}

	/**
	 * Parse the date field (Y-m-d\TH:i, in the site's time zone) to Unix time.
	 *
	 * @param string $text Text.
	 * @return int|null
	 */
	public static function parse_date( $text ) {
		$d = DateTime::createFromFormat( 'Y-m-d\TH:i', trim( (string) $text ), wp_timezone() );
		$e = DateTime::getLastErrors();
		if ( ! $d || ( $e && ( $e['warning_count'] || $e['error_count'] ) ) ) {
			return null;
		}
		return $d->getTimestamp();
	}

	public static function format_date( $ts ) {
		$d = new DateTime( '@' . (int) $ts );
		$d->setTimezone( wp_timezone() );
		return $d->format( 'Y-m-d\TH:i' );
	}

	/**
	 * Show notes: a small set of formatting tags only, no scripts or styles.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	public static function clean_show_notes( $html ) {
		$allowed = array(
			'a' => array( 'href' => true, 'title' => true ), 'b' => array(), 'strong' => array(), 'i' => array(), 'em' => array(), 'br' => array(), 'p' => array(),
			'ul' => array(), 'ol' => array(), 'li' => array(), 'h2' => array(), 'h3' => array(), 'h4' => array(), 'blockquote' => array(),
		);
		$html = wp_kses( (string) $html, $allowed, array( 'http', 'https', 'mailto' ) );
		return trim( PodPub_Details::xml_clean( $html ) );
	}

	/**
	 * Clean and check the episode form. Nothing is saved here.
	 *
	 * @param array $in      Form values (unslashed).
	 * @param array $current Saved details (audio fields are kept from here).
	 * @param bool  $has_audio_after Whether the episode will have an audio file once saved (existing or just uploaded).
	 * @return array { data: array, title: string, errors: string[] }
	 */
	public static function sanitize( $in, $current, $has_audio_after ) {
		$in     = is_array( $in ) ? $in : array();
		$errors = array();
		$get    = function ( $k ) use ( $in ) {
			return isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) ? (string) $in[ $k ] : '';
		};
		$data = $current;

		$title = mb_substr( trim( PodPub_Details::xml_clean( sanitize_text_field( $get( 'title' ) ) ) ), 0, 255 );
		if ( '' === $title ) {
			$errors[] = 'Please enter a title for the episode.';
		}
		$desc = trim( PodPub_Details::xml_clean( sanitize_textarea_field( $get( 'description' ) ) ) );
		if ( '' === $desc ) {
			$errors[] = 'Please enter a description.';
		} elseif ( mb_strlen( $desc ) > self::MAX_DESCRIPTION ) {
			$errors[] = 'The description is too long (the limit is ' . self::MAX_DESCRIPTION . ' characters).';
		}
		$data['description'] = $desc;

		$notes = self::clean_show_notes( $get( 'show_notes' ) );
		if ( mb_strlen( $notes ) > self::MAX_SHOW_NOTES ) {
			$errors[] = 'The show notes are too long (the limit is ' . self::MAX_SHOW_NOTES . ' characters).';
		}
		$data['show_notes'] = $notes;

		$ts = self::parse_date( $get( 'published' ) );
		if ( null === $ts ) {
			$errors[] = 'Please enter the date and time as year-month-day and hour:minute.';
		} else {
			$data['published'] = $ts;
		}

		foreach ( array( 'season' => 'season number', 'episode' => 'episode number' ) as $k => $label ) {
			$v = trim( $get( $k ) );
			if ( '' === $v ) {
				$data[ $k ] = 0;
			} elseif ( ! preg_match( '/^\d{1,5}$/', $v ) || (int) $v < 1 ) {
				$errors[]   = 'The ' . $label . ' must be a whole number from 1, or empty.';
			} else {
				$data[ $k ] = (int) $v;
			}
		}
		$type         = $get( 'type' );
		$data['type'] = in_array( $type, array( 'full', 'trailer', 'bonus' ), true ) ? $type : 'full';

		$dur = self::parse_duration( $get( 'duration' ) );
		if ( null === $dur ) {
			$errors[] = 'The duration must be seconds, minutes:seconds or hours:minutes:seconds.';
		} else {
			$data['duration'] = $dur;
		}

		$data['status'] = 'published' === $get( 'status' ) ? 'published' : 'draft';
		if ( 'published' === $data['status'] && ! $has_audio_after ) {
			$errors[] = 'Upload the audio file before publishing, or save the episode as a draft.';
		}
		$data = PodPub_P20::sanitize_item( $in, $data, $errors );
		return array( 'data' => $data, 'title' => $title, 'errors' => $errors );
	}

	/**
	 * Check an uploaded audio file by its name and its first bytes (not what the
	 * browser says it is).
	 *
	 * @param string $path          Path of the uploaded file.
	 * @param string $original_name The name it was uploaded with (only its extension is used).
	 * @return array|WP_Error { ext, type, size }
	 */
	public static function inspect_audio( $path, $original_name ) {
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'podpub_audio', 'The audio file was not received.' );
		}
		$ext   = strtolower( pathinfo( (string) $original_name, PATHINFO_EXTENSION ) );
		$types = self::audio_types();
		if ( ! isset( $types[ $ext ] ) ) {
			return new WP_Error( 'podpub_audio', 'The audio file must be one of: ' . implode( ', ', array_keys( $types ) ) . '.' );
		}
		$size = filesize( $path );
		if ( ! $size ) {
			return new WP_Error( 'podpub_audio', 'The audio file is empty.' );
		}
		$h  = (string) file_get_contents( $path, false, null, 0, 16 );
		$ok = false;
		switch ( $ext ) {
			case 'mp3':
				$ok = 'ID3' === substr( $h, 0, 3 ) || ( strlen( $h ) > 1 && "\xFF" === $h[0] && ( ord( $h[1] ) & 0xE0 ) === 0xE0 );
				break;
			case 'm4a':
				$ok = 'ftyp' === substr( $h, 4, 4 );
				break;
			case 'ogg':
			case 'opus':
				$ok = 'OggS' === substr( $h, 0, 4 );
				break;
			case 'wav':
				$ok = 'RIFF' === substr( $h, 0, 4 ) && 'WAVE' === substr( $h, 8, 4 );
				break;
			case 'flac':
				$ok = 'fLaC' === substr( $h, 0, 4 );
				break;
		}
		if ( ! $ok ) {
			return new WP_Error( 'podpub_audio', 'The file does not look like a real .' . $ext . ' audio file.' );
		}
		return array( 'ext' => $ext, 'type' => $types[ $ext ], 'size' => (int) $size );
	}

	/**
	 * Put the audio in the episode folder as {folder}/{folder}.{ext}; audio of
	 * another type is removed.
	 *
	 * @param string $tmp        Checked file.
	 * @param string $folder     Full path of the episode folder.
	 * @param string $folder_name Episode folder name.
	 * @param string $ext        Extension from inspect_audio.
	 * @return string|WP_Error File name.
	 */
	public static function store_audio( $tmp, $folder, $folder_name, $ext ) {
		if ( ! isset( self::audio_types()[ $ext ] ) || ! is_dir( $folder ) ) {
			return new WP_Error( 'podpub_audio', 'The audio could not be saved.' );
		}
		$name  = $folder_name . '.' . $ext;
		$stage = $folder . '/.audio_upload_' . getmypid();
		if ( ! @copy( $tmp, $stage ) ) {
			return new WP_Error( 'podpub_audio', 'The audio could not be saved. Check that the folder is writable.' );
		}
		if ( ! @rename( $stage, $folder . '/' . $name ) ) {
			@unlink( $stage );
			return new WP_Error( 'podpub_audio', 'The audio could not be saved.' );
		}
		foreach ( array_keys( self::audio_types() ) as $other ) {
			if ( $other !== $ext && is_file( $folder . '/' . $folder_name . '.' . $other ) ) {
				@unlink( $folder . '/' . $folder_name . '.' . $other );
			}
		}
		return $name;
	}


	/**
	 * Does this episode have audio (a file here or an address elsewhere)?
	 *
	 * @param array $d Episode details.
	 * @return bool
	 */
	public static function has_audio( $d ) {
		return '' !== $d['audio'] || '' !== $d['external_url'];
	}

	/**
	 * Audio files in an episode's folder (for picking after copying them in by FTP).
	 *
	 * @param int $podcast_id Podcast record id.
	 * @param int $id         Episode record id.
	 * @return string[] File names.
	 */
	public static function list_folder_audio( $podcast_id, $id ) {
		$ep_dir = self::dir_name( $id );
		$pdir   = PodPub_Feeds::dir_name( $podcast_id );
		$dir    = PodPub_Storage::base_path() . '/' . $pdir . '/' . $ep_dir;
		$out    = array();
		if ( '' === $ep_dir || '' === $pdir || ! is_dir( $dir ) ) {
			return $out;
		}
		foreach ( scandir( $dir ) as $f ) {
			if ( '.' === $f[0] || is_link( $dir . '/' . $f ) || ! is_file( $dir . '/' . $f ) ) {
				continue;
			}
			if ( isset( self::audio_types()[ strtolower( pathinfo( $f, PATHINFO_EXTENSION ) ) ] ) ) {
				$out[] = $f;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Is this a file name that needs no URL encoding?
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	public static function safe_file_name( $name ) {
		return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name ) && false === strpos( $name, '..' );
	}

	/**
	 * Ask the server hosting an audio file for its size and type (a HEAD request
	 * through WordPress's safe HTTP function: https only, no private addresses).
	 *
	 * @param string $url https address.
	 * @return array|null { size: int, type: string } or null if it could not be checked.
	 */
	public static function probe( $url ) {
		$r = wp_safe_remote_head( $url, array( 'timeout' => 10, 'redirection' => 3 ) );
		if ( is_wp_error( $r ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( $code < 200 || $code >= 300 ) {
			return null;
		}
		$len  = (string) wp_remote_retrieve_header( $r, 'content-length' );
		$ct   = strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $r, 'content-type' ) )[0] ) );
		return array(
			'size' => ctype_digit( $len ) ? (int) $len : 0,
			'type' => 0 === strpos( $ct, 'audio/' ) ? $ct : '',
		);
	}

	/**
	 * Check an address for audio hosted elsewhere.
	 *
	 * @param string $url     Address typed by the user.
	 * @param string $size_in Size in bytes typed by the user ('' = unknown).
	 * @param string $type_in Chosen type ('' = work it out; otherwise an extension from audio_types()).
	 * @param array  $cur     Current episode details.
	 * @return array|WP_Error { url, type, size, warnings }
	 */
	public static function check_external( $url, $size_in, $type_in, $cur ) {
		$url = trim( (string) $url );
		if ( strlen( $url ) > 2000 || preg_match( '/[\s<>"\'\\\\]/', $url ) ) {
			return new WP_Error( 'podpub_audio', 'The audio address is not valid.' );
		}
		$p = parse_url( $url );
		if ( ! $p || empty( $p['host'] ) || 'https' !== strtolower( isset( $p['scheme'] ) ? $p['scheme'] : '' ) ) {
			return new WP_Error( 'podpub_audio', 'The audio address must start with https://.' );
		}
		$size_in = trim( (string) $size_in );
		if ( '' !== $size_in && ! ctype_digit( $size_in ) ) {
			return new WP_Error( 'podpub_audio', 'The audio file size must be a whole number of bytes, or empty.' );
		}
		$types = self::audio_types();
		$type  = '';
		if ( '' !== $type_in ) {
			if ( ! isset( $types[ $type_in ] ) ) {
				return new WP_Error( 'podpub_audio', 'Choose the audio type from the list.' );
			}
			$type = $types[ $type_in ];
		} else {
			$ext = strtolower( pathinfo( isset( $p['path'] ) ? $p['path'] : '', PATHINFO_EXTENSION ) );
			if ( isset( $types[ $ext ] ) ) {
				$type = $types[ $ext ];
			}
		}
		$size     = '' !== $size_in ? (int) $size_in : 0;
		$warnings = array();
		if ( $url !== $cur['external_url'] || '' === $type || 0 === $size ) {
			$probe = self::probe( $url );
			if ( $probe ) {
				if ( 0 === $size && $probe['size'] ) {
					$size = $probe['size'];
				}
				if ( '' === $type && $probe['type'] ) {
					$type = $probe['type'];
				}
			} else {
				$warnings[] = 'The audio address could not be checked from this server, so it was saved as entered. Make sure it works in a browser.';
			}
		}
		if ( '' === $type ) {
			return new WP_Error( 'podpub_audio', 'Choose the audio type: the address does not end in a known audio file type and the server did not say what it is.' );
		}
		if ( 0 === $size ) {
			$warnings[] = 'The audio file size is not known. Enter it in bytes if you can; podcast apps use it.';
		}
		return array( 'url' => $url, 'type' => $type, 'size' => $size, 'warnings' => $warnings );
	}

	/**
	 * Work out, without changing anything, what audio change the form asks for.
	 *
	 * @param int        $id    Episode record id (0 if new).
	 * @param array      $input Form values.
	 * @param array|null $file  Upload.
	 * @param array      $cur   Current details.
	 * @param string[]   $errors Errors (added to).
	 * @param string[]   $warnings Warnings (added to).
	 * @return array|null Plan: kind upload | folder | external, plus its fields; null = no change.
	 */
	private static function plan_audio( $podcast_id, $id, $input, $file, $cur, &$errors, &$warnings ) {
		$get = function ( $k ) use ( $input ) {
			return isset( $input[ $k ] ) && is_scalar( $input[ $k ] ) ? (string) $input[ $k ] : '';
		};
		$src = $get( 'audio_source' );
		if ( ! in_array( $src, array( 'upload', 'folder', 'external' ), true ) ) {
			$src = 'upload';
		}
		if ( 'upload' === $src ) {
			if ( ! is_array( $file ) || ! isset( $file['error'] ) || UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
				return null;
			}
			if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
				$errors[] = 'The audio upload failed. The file may be larger than the server allows (' . size_format( wp_max_upload_size() ) . '). For a large file, copy it into the episode folder and pick it from the list, or host it elsewhere.';
				return null;
			}
			$a = self::inspect_audio( $file['tmp_name'], isset( $file['name'] ) ? $file['name'] : '' );
			if ( is_wp_error( $a ) ) {
				$errors[] = $a->get_error_message();
				return null;
			}
			return array_merge( array( 'kind' => 'upload', 'tmp' => $file['tmp_name'] ), $a );
		}
		if ( 'folder' === $src ) {
			$pick = $get( 'audio_pick' );
			if ( '' === $pick ) {
				return null;
			}
			$ep_dir = $id ? self::dir_name( $id ) : '';
			if ( '' === $ep_dir ) {
				$errors[] = 'Save the episode first, then copy the audio file into its folder and pick it here.';
				return null;
			}
			$path = PodPub_Storage::base_path() . '/' . PodPub_Feeds::dir_name( $podcast_id ) . '/' . $ep_dir . '/' . $pick;
			if ( $pick !== basename( $pick ) || '.' === $pick[0] || is_link( $path ) || ! is_file( $path ) ) {
				$errors[] = 'That file is not in the episode folder.';
				return null;
			}
			$a = self::inspect_audio( $path, $pick );
			if ( is_wp_error( $a ) ) {
				$errors[] = $a->get_error_message();
				return null;
			}
			return array_merge( array( 'kind' => 'folder', 'name' => $pick ), $a );
		}
		if ( '' === trim( $get( 'audio_url' ) ) ) {
			return null;
		}
		$x = self::check_external( $get( 'audio_url' ), $get( 'audio_size' ), $get( 'audio_ext_type' ), $cur );
		if ( is_wp_error( $x ) ) {
			$errors[] = $x->get_error_message();
			return null;
		}
		$warnings = array_merge( $warnings, $x['warnings'] );
		return array( 'kind' => 'external', 'url' => $x['url'], 'type' => $x['type'], 'size' => $x['size'] );
	}

	/**
	 * Create an episode (and its folder) or update one.
	 *
	 * @param int        $podcast_id Podcast record id.
	 * @param int        $id         Episode record id, or 0 for a new episode.
	 * @param array      $input      Form values (unslashed).
	 * @param array|null $file       $_FILES entry for the audio (already confirmed to be an upload), or null.
	 * @return array { errors, warnings, written, id }
	 */
	public static function save( $podcast_id, $id, $input, $file = null ) {
		$out = array( 'errors' => array(), 'warnings' => array(), 'written' => false, 'id' => (int) $id );
		if ( ! PodPub_Feeds::get( $podcast_id ) || '' === PodPub_Feeds::dir_name( $podcast_id ) ) {
			$out['errors'][] = 'This podcast was not found.';
			return $out;
		}
		$post = $id ? self::get( $id, $podcast_id ) : null;
		if ( $id && ! $post ) {
			$out['errors'][] = 'This episode was not found.';
			return $out;
		}
		$cur = $post ? self::details( $id ) : self::defaults();

		$plan = self::plan_audio( $podcast_id, $id, $input, $file, $cur, $out['errors'], $out['warnings'] );
		$r = self::sanitize( $input, $cur, $plan || self::has_audio( $cur ) );
		$out['errors'] = array_merge( $out['errors'], $r['errors'] );
		if ( $out['errors'] ) {
			return $out;
		}
		$data = $r['data'];

		if ( ! $post ) {
			$ep_dir = PodPub_Storage::create_episode_dir( PodPub_Feeds::dir_name( $podcast_id ), $r['title'] );
			if ( is_wp_error( $ep_dir ) ) {
				$out['errors'][] = $ep_dir->get_error_message();
				return $out;
			}
			$new = wp_insert_post( array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'post_parent' => (int) $podcast_id, 'post_title' => $r['title'] ), true );
			if ( is_wp_error( $new ) ) {
				$out['errors'][] = 'The episode could not be created.';
				return $out;
			}
			$id = (int) $new;
			update_post_meta( $id, '_podpub_dir', $ep_dir );
			$data['guid'] = PodPub_FeedXml::random_guid();
		} elseif ( $r['title'] !== $post->post_title ) {
			wp_update_post( array( 'ID' => (int) $id, 'post_title' => $r['title'] ) );
		}
		$ep_dir = self::dir_name( $id );
		$folder = PodPub_Storage::base_path() . '/' . PodPub_Feeds::dir_name( $podcast_id ) . '/' . $ep_dir;

		if ( $plan ) {
			$ok = true;
			if ( 'upload' === $plan['kind'] ) {
				$name = self::store_audio( $plan['tmp'], $folder, $ep_dir, $plan['ext'] );
			} elseif ( 'folder' === $plan['kind'] ) {
				$name = $plan['name'];
				if ( ! self::safe_file_name( $name ) ) {
					// A name with spaces or special characters is renamed to a safe one.
					$base = PodPub_Storage::slug( pathinfo( $name, PATHINFO_FILENAME ), 'audio' );
					$new  = $base . '.' . $plan['ext'];
					for ( $n = 2; file_exists( $folder . '/' . $new ); $n++ ) {
						$new = $base . '_' . $n . '.' . $plan['ext'];
					}
					$name = @rename( $folder . '/' . $name, $folder . '/' . $new ) ? $new : new WP_Error( 'podpub_audio', 'The file could not be renamed to a safe name.' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
			} else {
				$name = '';
			}
			if ( is_wp_error( $name ) ) {
				$out['errors'][] = $name->get_error_message();
				// A new episode was created before the file failed: keep it as a draft with no audio.
				$data['status'] = 'draft';
			} else {
				$data['audio_source'] = $plan['kind'];
				$data['audio']        = $name;
				$data['external_url'] = 'external' === $plan['kind'] ? $plan['url'] : '';
				$data['audio_type']   = $plan['type'];
				$data['audio_size']   = $plan['size'];
				if ( '' !== $name && 0 === $data['duration'] && function_exists( 'wp_read_audio_metadata' ) ) {
					$meta = @wp_read_audio_metadata( $folder . '/' . $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					if ( is_array( $meta ) && ! empty( $meta['length'] ) ) {
						$data['duration'] = (int) round( $meta['length'] );
					}
				}
			}
		}
		update_post_meta( $id, self::META, $data );
		$out['id'] = $id;

		// The chapter list is written as chapters.json in the episode folder (removed when the list is emptied).
		$chfile = $folder . '/chapters.json';
		if ( $data['chapters'] ) {
			$stage = $folder . '/.chapters_' . getmypid() . '.tmp';
			if ( false === @file_put_contents( $stage, PodPub_P20::chapters_json( $data['chapters'] ) ) || ! @rename( $stage, $chfile ) ) { // phpcs:ignore
				@unlink( $stage ); // phpcs:ignore
				$out['warnings'][] = 'The chapters file could not be written to the episode folder.';
			}
		} elseif ( is_file( $chfile ) && ! is_link( $chfile ) ) {
			@unlink( $chfile ); // phpcs:ignore
		}

		if ( 'published' === $data['status'] && $data['published'] > time() ) {
			$out['warnings'][] = 'This episode is dated in the future, so it is left out of the feed until the feed is next written after that time. Automatic scheduling is not built yet.';
		}
		$written = PodPub_FeedXml::write( $podcast_id );
		if ( is_wp_error( $written ) ) {
			$out['warnings'][] = 'Episode saved, but ' . lcfirst( $written->get_error_message() );
		} else {
			$out['written'] = true;
		}
		return $out;
	}

	/**
	 * Delete an episode: its record, its folder and everything in it, then rewrite the feed.
	 *
	 * @param int $podcast_id Podcast record id.
	 * @param int $id         Episode record id.
	 * @return array { errors, warnings, written }
	 */
	public static function delete( $podcast_id, $id ) {
		$out  = array( 'errors' => array(), 'warnings' => array(), 'written' => false );
		$post = self::get( $id, $podcast_id );
		if ( ! $post ) {
			$out['errors'][] = 'This episode was not found.';
			return $out;
		}
		$ep_dir = self::dir_name( $id );
		$pdir   = PodPub_Feeds::dir_name( $podcast_id );
		// Only a folder whose name is exactly a safe name, inside this podcast's folder.
		if ( '' !== $ep_dir && $ep_dir === PodPub_Storage::slug( $ep_dir, '' ) && '' !== $pdir ) {
			PodPub_Storage::remove_tree( PodPub_Storage::base_path() . '/' . $pdir . '/' . $ep_dir );
		}
		wp_delete_post( (int) $id, true );
		$written = PodPub_FeedXml::write( $podcast_id );
		if ( is_wp_error( $written ) ) {
			$out['warnings'][] = 'Episode deleted, but ' . lcfirst( $written->get_error_message() );
		} else {
			$out['written'] = true;
		}
		return $out;
	}

	/**
	 * Items for the feed: published episodes with audio, dated now or earlier,
	 * newest first, with their public addresses.
	 *
	 * @param int    $podcast_id Podcast record id.
	 * @param string $base_url   Public address of the podcast folder's parent ('' = unknown: no items).
	 * @param int    $now        Unix time.
	 * @return array
	 */
	public static function feed_items( $podcast_id, $base_url, $now ) {
		$items = array();
		$pdir  = PodPub_Feeds::dir_name( $podcast_id );
		if ( '' === $base_url || '' === $pdir ) {
			return $items;
		}
		foreach ( self::all( $podcast_id ) as $post ) {
			$d      = self::details( $post->ID );
			$ep_dir = self::dir_name( $post->ID );
			if ( 'published' !== $d['status'] || ! self::has_audio( $d ) || '' === $ep_dir || $d['published'] > $now ) {
				continue;
			}
			$items[] = array_merge(
				$d,
				array(
					'chapters_href' => $d['chapters'] ? $base_url . '/' . $pdir . '/' . $ep_dir . '/chapters.json' : $d['chapters_url'],
					'title'     => $post->post_title,
					'audio_url' => '' !== $d['external_url'] ? $d['external_url'] : $base_url . '/' . $pdir . '/' . $ep_dir . '/' . $d['audio'],
				)
			);
		}
		return $items;
	}
}
