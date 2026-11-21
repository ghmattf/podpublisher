<?php
/**
 * Builds a podcast's RSS feed (with the Podcasting 2.0 namespace) and writes it
 * to the podcast's folder as {folder}/{folder}.xml.
 *
 * @package PodPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PodPub_FeedXml {

	// Podcast namespace GUID namespace, from the podcast namespace specification.
	const GUID_NAMESPACE = 'ead4c236-bf58-58c6-a2c6-a6b28d128cb6';

	/**
	 * podcast:guid for a feed URL (UUID version 5): the URL without its scheme and
	 * trailing slashes is hashed with the podcast namespace.
	 *
	 * @param string $feed_url Feed address.
	 * @return string
	 */
	public static function guid_for_url( $feed_url ) {
		$name = preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', trim( (string) $feed_url ) );
		$name = rtrim( $name, '/' );
		$ns   = hex2bin( str_replace( '-', '', self::GUID_NAMESPACE ) );
		$h    = sha1( $ns . $name );
		$h    = substr( $h, 0, 32 );
		$h[12] = '5';
		$h[16] = dechex( ( hexdec( $h[16] ) & 0x3 ) | 0x8 );
		return substr( $h, 0, 8 ) . '-' . substr( $h, 8, 4 ) . '-' . substr( $h, 12, 4 ) . '-' . substr( $h, 16, 4 ) . '-' . substr( $h, 20, 12 );
	}

	/**
	 * A random UUID (version 4), for a podcast whose public address is not known yet.
	 *
	 * @return string
	 */
	public static function random_guid() {
		$b    = random_bytes( 16 );
		$b[6] = chr( ( ord( $b[6] ) & 0x0f ) | 0x40 );
		$b[8] = chr( ( ord( $b[8] ) & 0x3f ) | 0x80 );
		$h    = bin2hex( $b );
		return substr( $h, 0, 8 ) . '-' . substr( $h, 8, 4 ) . '-' . substr( $h, 12, 4 ) . '-' . substr( $h, 16, 4 ) . '-' . substr( $h, 20, 12 );
	}

	/**
	 * Build the feed.
	 *
	 * @param array $p {
	 *     @type string $title     Podcast title.
	 *     @type array  $details   From PodPub_Details::get().
	 *     @type string $feed_url  Public address of this feed file ('' if unknown).
	 *     @type string $image_url Public address of the show art ('' if none).
	 *     @type int    $now       Time for lastBuildDate (default: now).
	 * }
	 * @return string XML.
	 */
	public static function build( $p ) {
		$d    = $p['details'];
		$now  = isset( $p['now'] ) ? (int) $p['now'] : time();
		$feed = isset( $p['feed_url'] ) ? $p['feed_url'] : '';
		$img  = isset( $p['image_url'] ) ? $p['image_url'] : '';

		$w = new XMLWriter();
		$w->openMemory();
		$w->setIndent( true );
		$w->setIndentString( '  ' );
		$w->startDocument( '1.0', 'UTF-8' );
		$w->startElement( 'rss' );
		$w->writeAttribute( 'version', '2.0' );
		$w->writeAttribute( 'xmlns:atom', 'http://www.w3.org/2005/Atom' );
		$w->writeAttribute( 'xmlns:content', 'http://purl.org/rss/1.0/modules/content/' );
		$w->writeAttribute( 'xmlns:itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd' );
		$w->writeAttribute( 'xmlns:podcast', 'https://podcastindex.org/namespace/1.0' );
		$w->startElement( 'channel' );

		$w->writeElement( 'title', $p['title'] );
		if ( '' !== $d['link'] ) {
			$w->writeElement( 'link', $d['link'] );
		}
		$w->writeElement( 'description', $d['description'] );
		$w->writeElement( 'language', $d['language'] );
		if ( '' !== $d['copyright'] ) {
			$w->writeElement( 'copyright', $d['copyright'] );
		}
		$w->writeElement( 'lastBuildDate', gmdate( 'D, d M Y H:i:s', $now ) . ' +0000' );
		$w->writeElement( 'generator', 'PodPublisher ' . ( defined( 'PODPUB_VERSION' ) ? PODPUB_VERSION : '' ) );

		if ( '' !== $feed ) {
			$w->startElement( 'atom:link' );
			$w->writeAttribute( 'href', $feed );
			$w->writeAttribute( 'rel', 'self' );
			$w->writeAttribute( 'type', 'application/rss+xml' );
			$w->endElement();
		}

		if ( '' !== $d['author'] ) {
			$w->writeElement( 'itunes:author', $d['author'] );
		}
		if ( '' !== $d['owner_name'] || '' !== $d['owner_email'] ) {
			$w->startElement( 'itunes:owner' );
			if ( '' !== $d['owner_name'] ) {
				$w->writeElement( 'itunes:name', $d['owner_name'] );
			}
			if ( '' !== $d['owner_email'] ) {
				$w->writeElement( 'itunes:email', $d['owner_email'] );
			}
			$w->endElement();
		}
		if ( '' !== $img ) {
			$w->startElement( 'itunes:image' );
			$w->writeAttribute( 'href', $img );
			$w->endElement();
			$w->startElement( 'image' );
			$w->writeElement( 'url', $img );
			$w->writeElement( 'title', $p['title'] );
			if ( '' !== $d['link'] ) {
				$w->writeElement( 'link', $d['link'] );
			}
			$w->endElement();
		}
		if ( '' !== $d['category'] ) {
			$w->startElement( 'itunes:category' );
			$w->writeAttribute( 'text', $d['category'] );
			$w->endElement();
		}
		$w->writeElement( 'itunes:explicit', $d['explicit'] ? 'true' : 'false' );
		$w->writeElement( 'itunes:type', $d['type'] );
		if ( '' !== $d['guid'] ) {
			$w->writeElement( 'podcast:guid', $d['guid'] );
		}
		$w->writeElement( 'podcast:medium', 'podcast' );

		foreach ( isset( $p['episodes'] ) ? $p['episodes'] : array() as $e ) {
			self::write_item( $w, $e );
		}

		$w->endElement(); // channel.
		$w->endElement(); // rss.
		$w->endDocument();
		return $w->outputMemory();
	}

	/**
	 * One <item>.
	 *
	 * @param XMLWriter $w XML writer.
	 * @param array     $e Episode (details plus title and audio_url).
	 */
	private static function write_item( $w, $e ) {
		$w->startElement( 'item' );
		$w->writeElement( 'title', $e['title'] );
		$w->writeElement( 'description', $e['description'] );
		$w->writeElement( 'pubDate', gmdate( 'D, d M Y H:i:s', (int) $e['published'] ) . ' +0000' );
		$w->startElement( 'guid' );
		$w->writeAttribute( 'isPermaLink', 'false' );
		$w->text( $e['guid'] );
		$w->endElement();
		$w->startElement( 'enclosure' );
		$w->writeAttribute( 'url', $e['audio_url'] );
		$w->writeAttribute( 'length', (string) (int) $e['audio_size'] );
		$w->writeAttribute( 'type', $e['audio_type'] );
		$w->endElement();
		if ( (int) $e['duration'] > 0 ) {
			$w->writeElement( 'itunes:duration', PodPub_Episodes::format_duration( $e['duration'] ) );
		}
		if ( (int) $e['season'] > 0 ) {
			$w->writeElement( 'podcast:season', (string) (int) $e['season'] );
			$w->writeElement( 'itunes:season', (string) (int) $e['season'] );
		}
		if ( (int) $e['episode'] > 0 ) {
			$w->writeElement( 'podcast:episode', (string) (int) $e['episode'] );
			$w->writeElement( 'itunes:episode', (string) (int) $e['episode'] );
		}
		$w->writeElement( 'itunes:episodeType', $e['type'] );
		if ( '' !== trim( $e['show_notes'] ) ) {
			$w->startElement( 'content:encoded' );
			$w->writeCdata( str_replace( ']]>', ']]]]><![CDATA[>', $e['show_notes'] ) );
			$w->endElement();
		}
		$w->endElement();
	}

	/**
	 * Build a podcast's feed from its saved details and write it into its folder.
	 * Does nothing (returns an error) until the required details are saved.
	 *
	 * @param int $id Record id.
	 * @return string|WP_Error Full path of the file written.
	 */
	public static function write( $id ) {
		$post = PodPub_Feeds::get( $id );
		$dir  = PodPub_Feeds::dir_name( $id );
		if ( ! $post || '' === $dir ) {
			return new WP_Error( 'podpub_feed', 'This podcast has no folder.' );
		}
		$d = PodPub_Details::get( $id );
		if ( '' === trim( $d['description'] ) ) {
			return new WP_Error( 'podpub_feed', 'Save the podcast details (description is required) before the feed can be written.' );
		}
		$folder = PodPub_Storage::base_path() . '/' . $dir;
		if ( ! PodPub_Storage::ensure_dir( $folder ) ) {
			return new WP_Error( 'podpub_feed', 'The podcast folder cannot be written to.' );
		}
		$base = PodPub_Storage::base_url();
		$xml  = self::build(
			array(
				'title'     => $post->post_title,
				'details'   => $d,
				'feed_url'  => '' === $base ? '' : $base . '/' . $dir . '/' . $dir . '.xml',
				'image_url' => ( '' === $base || '' === $d['image'] ) ? '' : $base . '/' . $dir . '/' . $d['image'],
				'episodes'  => PodPub_Episodes::feed_items( $id, $base, time() ),
			)
		);
		$file  = $folder . '/' . $dir . '.xml';
		$stage = $folder . '/.feed_' . getmypid() . '.tmp';
		if ( false === @file_put_contents( $stage, $xml ) || ! @rename( $stage, $file ) ) { // phpcs:ignore
			@unlink( $stage ); // phpcs:ignore
			return new WP_Error( 'podpub_feed', 'The feed file could not be written.' );
		}
		return $file;
	}
}
