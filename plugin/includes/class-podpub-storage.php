<?php
/**
 * Where podcast files live on disk, and the rules for folder and file names.
 *
 * Layout:  {base}/{podcast_dir}/            feed xml, cover image, other podcast files
 *          {base}/{podcast_dir}/{episode_dir}/   one folder per episode
 *
 * Every folder name this class makes uses only A-Z a-z 0-9 _ - (spaces become _),
 * so nothing needs URL encoding.
 *
 * @package PodPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PodPub_Storage {

	const MAX_NAME = 60;

	/**
	 * Turn any text into a safe folder/file name: spaces become _, accents are
	 * removed, "&" becomes "and", everything else outside A-Z a-z 0-9 _ - is dropped.
	 * Case is kept as typed.
	 *
	 * @param string $text   Text such as a podcast or episode title.
	 * @param string $fallback Used when nothing usable is left.
	 * @return string
	 */
	public static function slug( $text, $fallback = 'untitled' ) {
		$text = (string) $text;
		if ( function_exists( 'remove_accents' ) ) {
			$text = remove_accents( $text );
		} elseif ( function_exists( 'iconv' ) ) {
			$converted = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $text );
			if ( false !== $converted ) {
				$text = $converted;
			}
		}
		$text = str_replace( '&', ' and ', $text );
		$text = preg_replace( '/\s+/u', '_', trim( $text ) );
		$text = preg_replace( '/[^A-Za-z0-9_-]/', '', $text );
		$text = preg_replace( '/_{2,}/', '_', $text );
		$text = trim( $text, '_-' );
		$text = substr( $text, 0, self::MAX_NAME );
		$text = rtrim( $text, '_-' );
		return '' === $text ? $fallback : $text;
	}

	/**
	 * Is this an acceptable absolute folder path? Absolute, no "..", and every
	 * folder in it only A-Z a-z 0-9 . _ - (a drive letter such as C: is allowed).
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function valid_path( $path ) {
		$path = (string) $path;
		if ( ! preg_match( '#^(/|[A-Za-z]:[\\\\/])#', $path ) ) {
			return false;
		}
		$parts = preg_split( '#[\\\\/]+#', $path, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $parts as $i => $part ) {
			if ( '.' === $part || '..' === $part ) {
				return false;
			}
			if ( 0 === $i && preg_match( '/^[A-Za-z]:$/', $part ) ) {
				continue;
			}
			if ( ! preg_match( '/^[A-Za-z0-9._-]+$/', $part ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Is this an acceptable public address for the folder? http(s) with a path
	 * made only of A-Z a-z 0-9 . _ - ~ and slashes.
	 *
	 * @param string $url Address.
	 * @return bool
	 */
	public static function valid_url( $url ) {
		$p = parse_url( (string) $url );
		if ( ! $p || empty( $p['scheme'] ) || empty( $p['host'] ) || ! in_array( $p['scheme'], array( 'http', 'https' ), true ) ) {
			return false;
		}
		if ( isset( $p['query'] ) || isset( $p['fragment'] ) ) {
			return false;
		}
		$path = isset( $p['path'] ) ? $p['path'] : '';
		return (bool) preg_match( '#^[A-Za-z0-9._~/-]*$#', $path ) && false === strpos( $path, '..' );
	}

	/**
	 * Default folder: "podpublisher" inside the WordPress uploads folder.
	 *
	 * @return string
	 */
	public static function default_path() {
		$u = wp_upload_dir( null, false );
		return rtrim( str_replace( '\\', '/', $u['basedir'] ), '/' ) . '/podpublisher';
	}

	/**
	 * The folder in use: the setting, or the default.
	 *
	 * @return string
	 */
	public static function base_path() {
		$set = trim( (string) PodPub_Settings::get( 'storage_path' ) );
		return rtrim( '' !== $set ? $set : self::default_path(), '/\\' );
	}

	/**
	 * Public address that goes with a folder, if it can be worked out (it is
	 * inside the uploads folder). Empty string if not.
	 *
	 * @param string $path Folder path.
	 * @return string
	 */
	public static function derive_url( $path ) {
		$u    = wp_upload_dir( null, false );
		$base = rtrim( str_replace( '\\', '/', $u['basedir'] ), '/' );
		$path = rtrim( str_replace( '\\', '/', $path ), '/' );
		if ( $path === $base || 0 === strpos( $path, $base . '/' ) ) {
			return rtrim( $u['baseurl'], '/' ) . substr( $path, strlen( $base ) );
		}
		return '';
	}

	/**
	 * Public address of the folder in use.
	 *
	 * @return string
	 */
	public static function base_url() {
		$set = trim( (string) PodPub_Settings::get( 'storage_url' ) );
		if ( '' !== $set ) {
			return rtrim( $set, '/' );
		}
		return self::derive_url( self::base_path() );
	}

	/**
	 * Make a folder (and a blank index.html so servers do not list it).
	 *
	 * @param string $dir Folder.
	 * @return bool
	 */
	public static function ensure_dir( $dir ) {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		if ( ! is_file( $dir . '/index.html' ) ) {
			@file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
		return is_dir( $dir ) && is_writable( $dir );
	}

	/**
	 * Pick a folder name for a new item inside $parent that is not taken:
	 * "My_Show", then "My_Show_2", "My_Show_3", ...
	 *
	 * @param string $parent Existing parent folder.
	 * @param string $title  Title to base the name on.
	 * @param string $fallback Name if the title has nothing usable.
	 * @return string
	 */
	public static function unique_name( $parent, $title, $fallback = 'untitled' ) {
		$base = self::slug( $title, $fallback );
		$name = $base;
		$n    = 2;
		while ( file_exists( rtrim( $parent, '/\\' ) . '/' . $name ) ) {
			$suffix = '_' . $n;
			$name   = substr( $base, 0, self::MAX_NAME - strlen( $suffix ) ) . $suffix;
			$n++;
		}
		return $name;
	}

	/**
	 * Delete a folder and everything in it. Symbolic links are removed, never
	 * followed. Callers must pass only a folder they built from a checked name.
	 *
	 * @param string $dir Folder.
	 * @return bool
	 */
	public static function remove_tree( $dir ) {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return false;
		}
		foreach ( scandir( $dir ) as $f ) {
			if ( '.' === $f || '..' === $f ) {
				continue;
			}
			$p = $dir . '/' . $f;
			if ( is_dir( $p ) && ! is_link( $p ) ) {
				self::remove_tree( $p );
			} else {
				@unlink( $p ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		return @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Create the folder for a new podcast.
	 *
	 * @param string $title Podcast title.
	 * @return string|WP_Error Folder name (not the full path).
	 */
	public static function create_podcast_dir( $title ) {
		$base = self::base_path();
		if ( ! self::ensure_dir( $base ) ) {
			return new WP_Error( 'podpub_storage', 'The podcast folder cannot be created or written to. Check the folder in PodPublisher > Settings.' );
		}
		$name = self::unique_name( $base, $title, 'podcast' );
		if ( ! self::ensure_dir( $base . '/' . $name ) ) {
			return new WP_Error( 'podpub_storage', 'The folder for this podcast could not be created.' );
		}
		return $name;
	}

	/**
	 * Create the folder for a new episode inside a podcast's folder.
	 *
	 * @param string $podcast_dir Podcast folder name (from create_podcast_dir).
	 * @param string $title       Episode title.
	 * @return string|WP_Error Folder name (not the full path).
	 */
	public static function create_episode_dir( $podcast_dir, $title ) {
		if ( $podcast_dir !== self::slug( $podcast_dir, '' ) || '' === $podcast_dir ) {
			return new WP_Error( 'podpub_storage', 'Invalid podcast folder.' );
		}
		$parent = self::base_path() . '/' . $podcast_dir;
		if ( ! is_dir( $parent ) ) {
			return new WP_Error( 'podpub_storage', 'The podcast folder does not exist.' );
		}
		$name = self::unique_name( $parent, $title, 'episode' );
		if ( ! self::ensure_dir( $parent . '/' . $name ) ) {
			return new WP_Error( 'podpub_storage', 'The folder for this episode could not be created.' );
		}
		return $name;
	}
}
