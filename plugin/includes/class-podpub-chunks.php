<?php
/**
 * Chunked audio upload: the browser sends a big file in pieces (each below the server's upload
 * limit), the pieces are put back together in a staging folder, and the episode's Save picks the
 * finished file up from there. The upload is therefore separate from Save.
 *
 * Staging folder: a sibling of the podcasts folder (never inside it, so it is not mistaken for a
 * podcast), with a deny rule and an empty index. Files not finished or used within a day are removed.
 *
 * @package PodPublisher
 */

defined( 'ABSPATH' ) || exit;

class PodPub_Chunks {

	const MAX_AGE = 86400; // Seconds a staged file is kept.

	/** The most bytes one upload may total (a filter lets a site raise or lower it). */
	public static function max_bytes() {
		$max = (int) apply_filters( 'podpub_max_upload_bytes', 2 * 1024 * 1024 * 1024 );
		return max( 1048576, $max );
	}

	/** Size of each piece the browser should send: under the server limit with room for the form fields. */
	public static function chunk_size() {
		$limit = (int) wp_max_upload_size();
		return (int) max( 262144, min( 8 * 1048576, $limit - 262144 ) );
	}

	public static function dir() {
		return dirname( rtrim( PodPub_Storage::base_path(), '/' ) ) . '/podpublisher-incoming';
	}

	private static function ready() {
		$d = self::dir();
		if ( ! is_dir( $d ) && ! @mkdir( $d, 0755, true ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}
		if ( ! is_file( $d . '/index.html' ) ) {
			@file_put_contents( $d . '/index.html', '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( ! is_file( $d . '/.htaccess' ) ) {
			@file_put_contents( $d . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return is_dir( $d ) && is_writable( $d );
	}

	public static function valid_id( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $id );
	}

	private static function sweep() {
		$d = self::dir();
		foreach ( (array) glob( $d . '/*' ) as $f ) {
			if ( is_file( $f ) && 'index.html' !== basename( $f ) && filemtime( $f ) < time() - self::MAX_AGE ) {
				@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}

	private static function meta( $id ) {
		$f = self::dir() . '/' . $id . '.json';
		$m = is_file( $f ) ? json_decode( (string) file_get_contents( $f ), true ) : null;
		return is_array( $m ) ? $m : null;
	}

	private static function put_meta( $id, $m ) {
		return false !== @file_put_contents( self::dir() . '/' . $id . '.json', wp_json_encode( $m ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Take one piece of an upload.
	 *
	 * @param string $id      Upload id chosen by the browser: 32 hex characters.
	 * @param int    $index   Piece number, from 0.
	 * @param int    $total   Number of pieces.
	 * @param string $name    The file's original name (only the extension is checked; the file is renamed on Save).
	 * @param int    $size    The whole file's size in bytes.
	 * @param string $tmp     Path of the piece just received.
	 * @param int    $user    Id of the signed-in user (a staged file is only usable by the person who sent it).
	 * @return array|WP_Error { done: bool, received: int, next: int }
	 */
	public static function receive( $id, $index, $total, $name, $size, $tmp, $user ) {
		if ( ! self::valid_id( $id ) || $index < 0 || $total < 1 || $index >= $total || $size < 1 ) {
			return new WP_Error( 'podpub_chunk', 'The upload details were not valid.' );
		}
		if ( $size > self::max_bytes() ) {
			return new WP_Error( 'podpub_chunk', 'The file is larger than the most this plugin accepts (' . size_format( self::max_bytes() ) . ').' );
		}
		$ext = strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );
		if ( ! isset( PodPub_Episodes::audio_types()[ $ext ] ) ) {
			return new WP_Error( 'podpub_chunk', 'The audio file must be one of: ' . implode( ', ', array_keys( PodPub_Episodes::audio_types() ) ) . '.' );
		}
		if ( ! is_file( $tmp ) || ! filesize( $tmp ) ) {
			return new WP_Error( 'podpub_chunk', 'That piece of the file was not received. The file may be too big for the server\'s limit of ' . size_format( wp_max_upload_size() ) . ' per piece.' );
		}
		if ( ! self::ready() ) {
			return new WP_Error( 'podpub_chunk', 'The server could not make a place to hold the upload (' . self::dir() . ').' );
		}
		self::sweep();
		$part = self::dir() . '/' . $id . '.part';
		$m    = self::meta( $id );
		if ( 0 === $index ) {
			@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@unlink( self::dir() . '/' . $id . '.bin' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$m = array( 'name' => (string) $name, 'size' => (int) $size, 'total' => (int) $total, 'next' => 0, 'user' => (int) $user, 'done' => false );
		} elseif ( ! $m || (int) $m['user'] !== (int) $user || (int) $m['total'] !== (int) $total || (int) $m['size'] !== (int) $size ) {
			return new WP_Error( 'podpub_chunk', 'This upload was not started, or has expired. Choose the file and upload it again.' );
		}
		if ( $index < $m['next'] ) { // A piece sent twice (a retry after a lost reply): already have it.
			return array( 'done' => (bool) $m['done'], 'received' => (int) @filesize( $part ), 'next' => (int) $m['next'] );
		}
		if ( $index > $m['next'] ) {
			return new WP_Error( 'podpub_chunk', 'A piece of the file went missing. Upload it again.' );
		}
		$in  = @fopen( $tmp, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$out = @fopen( $part, 'ab' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $in || ! $out ) {
			return new WP_Error( 'podpub_chunk', 'The upload could not be written. Check the folder is writable.' );
		}
		$ok = false !== stream_copy_to_stream( $in, $out );
		fclose( $in );
		fclose( $out );
		clearstatcache( true, $part );
		if ( ! $ok || (int) filesize( $part ) > (int) $size ) {
			@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'podpub_chunk', 'The upload could not be written or was larger than announced. Upload it again.' );
		}
		$m['next'] = $index + 1;
		if ( $m['next'] === $total ) {
			if ( (int) filesize( $part ) !== (int) $size ) {
				@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return new WP_Error( 'podpub_chunk', 'The file arrived with the wrong size. Upload it again.' );
			}
			if ( ! @rename( $part, self::dir() . '/' . $id . '.bin' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return new WP_Error( 'podpub_chunk', 'The finished upload could not be saved.' );
			}
			$m['done'] = true;
		}
		self::put_meta( $id, $m );
		return array( 'done' => (bool) $m['done'], 'received' => (int) $m['done'] ? (int) $size : (int) filesize( $part ), 'next' => (int) $m['next'] );
	}

	/**
	 * A finished upload as a file entry for Episodes::save, or a file entry with an error message.
	 *
	 * @param string $id   Upload id.
	 * @param int    $user Signed-in user.
	 * @return array
	 */
	public static function file( $id, $user ) {
		$m = self::valid_id( $id ) ? self::meta( $id ) : null;
		$f = self::dir() . '/' . $id . '.bin';
		if ( ! $m || (int) $m['user'] !== (int) $user ) {
			return array( 'error' => UPLOAD_ERR_NO_FILE, 'message' => 'The uploaded audio is no longer on the server (it is kept for a day). Upload it again.' );
		}
		if ( empty( $m['done'] ) || ! is_file( $f ) ) {
			return array( 'error' => UPLOAD_ERR_PARTIAL, 'message' => 'The audio upload did not finish. Upload it again.' );
		}
		return array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $f, 'name' => (string) $m['name'], 'size' => (int) $m['size'], 'staged' => true );
	}

	/** What to show about a finished or waiting upload: name and size, or null. */
	public static function info( $id, $user ) {
		$m = self::valid_id( $id ) ? self::meta( $id ) : null;
		if ( ! $m || (int) $m['user'] !== (int) $user || empty( $m['done'] ) ) {
			return null;
		}
		return array( 'name' => (string) $m['name'], 'size' => (int) $m['size'] );
	}

	/** Remove a staged upload (after it has been saved into an episode). */
	public static function discard( $id ) {
		if ( ! self::valid_id( $id ) ) {
			return;
		}
		foreach ( array( '.part', '.bin', '.json' ) as $x ) {
			@unlink( self::dir() . '/' . $id . $x ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}
}
