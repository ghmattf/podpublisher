<?php
/**
 * Smaller copies of uploaded pictures, for <podcast:images srcset="...">: apps pick
 * the size that fits the screen instead of loading the full picture.
 *
 * @package PodPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PodPub_Images {

	// Widths made (only those smaller than the original are made).
	const WIDTHS = array( 300, 600, 1000, 1500 );

	/**
	 * Make the smaller copies next to a stored picture: {base}-{width}.{ext}.
	 * A GIF is not resized (an animated one would stop moving), so it gets none.
	 *
	 * @param string $dir   Folder.
	 * @param string $file  File name of the stored picture (for example cover.jpg).
	 * @param int    $width Width in pixels of the stored picture.
	 * @return array List of { file, width }, smallest first. Empty when none were made.
	 */
	public static function build( $dir, $file, $width ) {
		$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		$base = pathinfo( $file, PATHINFO_FILENAME );
		self::remove_sizes( $dir, $base );
		$made = array();
		if ( ! in_array( $ext, array( 'jpg', 'png' ), true ) || ! is_file( $dir . '/' . $file ) ) {
			return $made;
		}
		foreach ( self::WIDTHS as $w ) {
			if ( $w >= (int) $width ) {
				continue;
			}
			$name = $base . '-' . $w . '.' . $ext;
			if ( self::resize( $dir . '/' . $file, $dir . '/' . $name, $w, $ext ) ) {
				$made[] = array( 'file' => $name, 'width' => $w );
			}
		}
		return $made;
	}

	/**
	 * Delete the smaller copies of a picture (not the picture itself).
	 *
	 * @param string $dir  Folder.
	 * @param string $base File name without extension (cover, image).
	 */
	public static function remove_sizes( $dir, $base ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $f ) {
			if ( preg_match( '/^' . preg_quote( $base, '/' ) . '-\d{2,5}\.(jpg|png|gif)$/', $f ) && is_file( $dir . '/' . $f ) && ! is_link( $dir . '/' . $f ) ) {
				@unlink( $dir . '/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}

	/**
	 * The srcset text: "address 300w, address 600w, ..." with the original last.
	 * Empty when there are no smaller copies.
	 *
	 * @param string $url_dir Public address of the folder (no trailing slash).
	 * @param string $file    Stored picture file name.
	 * @param int     $width  Its width.
	 * @param array   $sizes  From build().
	 * @return string
	 */
	public static function srcset( $url_dir, $file, $width, $sizes ) {
		if ( '' === $url_dir || '' === $file || empty( $sizes ) || (int) $width < 1 ) {
			return '';
		}
		$parts = array();
		foreach ( $sizes as $s ) {
			$parts[] = $url_dir . '/' . $s['file'] . ' ' . (int) $s['width'] . 'w';
		}
		$parts[] = $url_dir . '/' . $file . ' ' . (int) $width . 'w';
		return implode( ', ', $parts );
	}

	/**
	 * One resized copy, using WordPress's own image editor when there is one
	 * (GD or Imagick) and PHP's GD otherwise.
	 */
	private static function resize( $src, $dest, $w, $ext ) {
		$stage = $dest . '.tmp' . getmypid();
		$ok    = false;
		if ( function_exists( 'wp_get_image_editor' ) ) {
			$ed = wp_get_image_editor( $src );
			if ( ! is_wp_error( $ed ) ) {
				$ed->set_quality( 85 );
				if ( ! is_wp_error( $ed->resize( $w, null, false ) ) ) {
					$saved = $ed->save( $stage, 'jpg' === $ext ? 'image/jpeg' : 'image/png' );
					$ok    = ! is_wp_error( $saved ) && is_file( $stage );
				}
			}
		} elseif ( function_exists( 'imagecreatetruecolor' ) ) {
			$info = @getimagesize( $src ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$img  = $info ? ( 'jpg' === $ext ? @imagecreatefromjpeg( $src ) : @imagecreatefrompng( $src ) ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $img ) {
				$h   = max( 1, (int) round( $info[1] * $w / $info[0] ) );
				$out = imagecreatetruecolor( $w, $h );
				if ( 'png' === $ext ) {
					imagealphablending( $out, false );
					imagesavealpha( $out, true );
				}
				imagecopyresampled( $out, $img, 0, 0, 0, 0, $w, $h, $info[0], $info[1] );
				$ok = 'jpg' === $ext ? imagejpeg( $out, $stage, 85 ) : imagepng( $out, $stage );
				imagedestroy( $out );
				imagedestroy( $img );
			}
		}
		if ( $ok && @rename( $stage, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return true;
		}
		@unlink( $stage ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return false;
	}
}
