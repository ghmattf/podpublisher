<?php
/**
 * Adopt a podcast feed that already exists (a file on this server or an https address):
 * read it into a PodPublisher podcast and episodes, keep the podcast:guid and every
 * episode guid, and say exactly what would be lost before anything is written.
 *
 * How "what would be lost" is found: the old feed is read into PodPublisher's own
 * fields, a new feed is built from those fields in memory (the same builder that
 * writes real feeds), and the two are compared element by element and attribute by
 * attribute. Anything in the old feed that the new one does not have is listed, so
 * the list stays right whatever the old feed contains. Text that PodPublisher changes
 * (HTML taken out of descriptions, show notes simplified, texts shortened) is listed too.
 *
 * Nothing here writes until PodPub_Adopt::commit() is called after the owner confirmed.
 *
 * @package PodPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PodPub_Adopt {

	const MAX_BYTES = 10485760; // 10 MB of feed.
	const NS_ITUNES = 'http://www.itunes.com/dtds/podcast-1.0.dtd';
	const NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';
	const NS_ATOM   = 'http://www.w3.org/2005/Atom';

	/** The podcast namespace has been written with more than one address. */
	private static function podcast_ns() {
		return array(
			'https://podcastindex.org/namespace/1.0',
			'http://podcastindex.org/namespace/1.0',
			'https://github.com/Podcastindex-org/podcast-namespace/blob/main/docs/1.0.md',
			'http://github.com/Podcastindex-org/podcast-namespace/blob/main/docs/1.0.md',
		);
	}

	/* ------------------------------------------------------------------ */
	/* Loading                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Fetch and parse a feed.
	 *
	 * @param string $source An https:// address, or the full path of a feed file on this server.
	 * @return array|WP_Error { dom: DOMDocument, source: string, url: string, file: string, bytes: int }
	 *                        url and file are the feed's own address and file when they can be worked out
	 *                        (so the feed can be written back in the same place); '' when not.
	 */
	public static function load( $source ) {
		$source = trim( (string) $source );
		if ( '' === $source ) {
			return new WP_Error( 'podpub_adopt', 'Enter the address of the feed (https://...) or the full path of the feed file on this server.' );
		}
		$url  = '';
		$file = '';
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $source ) ) {
			if ( ! PodPub_P20::valid_http( $source ) ) {
				return new WP_Error( 'podpub_adopt', 'The feed address must start with https:// and have no spaces or quote characters.' );
			}
			$r = wp_safe_remote_get( $source, array(
				'timeout'             => 15,
				'redirection'         => 3,
				'limit_response_size' => self::MAX_BYTES + 1,
				'user-agent'          => 'PodPublisher/' . ( defined( 'PODPUB_VERSION' ) ? PODPUB_VERSION : '0' ) . ' (' . home_url() . ')',
				'headers'             => array( 'Accept' => 'application/rss+xml, application/xml, text/xml, */*' ),
			) );
			if ( is_wp_error( $r ) ) {
				return new WP_Error( 'podpub_adopt', 'The feed could not be fetched: ' . $r->get_error_message() );
			}
			$code = (int) wp_remote_retrieve_response_code( $r );
			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error( 'podpub_adopt', 'The feed could not be fetched (the server answered ' . $code . ').' );
			}
			$body = (string) wp_remote_retrieve_body( $r );
			$url  = $source;
			$file = self::url_to_path( $source );
		} else {
			$real = realpath( $source );
			if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) {
				return new WP_Error( 'podpub_adopt', 'That file was not found or cannot be read: ' . $source );
			}
			if ( ! self::in_allowed_roots( $real ) ) {
				return new WP_Error( 'podpub_adopt', 'For safety, only a feed file inside the WordPress folder, the uploads folder or the Podcast folder can be adopted.' );
			}
			if ( filesize( $real ) > self::MAX_BYTES ) {
				return new WP_Error( 'podpub_adopt', 'The feed file is too large (the limit is 10 MB).' );
			}
			$body = (string) file_get_contents( $real );
			$file = $real;
			$url  = self::path_to_url( $real );
		}
		if ( strlen( $body ) > self::MAX_BYTES ) {
			return new WP_Error( 'podpub_adopt', 'The feed is too large (the limit is 10 MB).' );
		}
		$dom = self::parse( $body );
		if ( is_wp_error( $dom ) ) {
			return $dom;
		}
		return array( 'dom' => $dom, 'source' => $source, 'url' => $url, 'file' => $file, 'bytes' => strlen( $body ) );
	}

	/**
	 * Parse feed text. A DOCTYPE is refused (it is how XML bombs and file-reading tricks are done),
	 * nothing is fetched while parsing, and only an RSS feed with a channel is accepted.
	 *
	 * @param string $xml Feed text.
	 * @return DOMDocument|WP_Error
	 */
	public static function parse( $xml ) {
		$xml = (string) $xml;
		if ( preg_match( '/<!DOCTYPE|<!ENTITY/i', substr( $xml, 0, 20000 ) ) ) {
			return new WP_Error( 'podpub_adopt', 'This feed has a DOCTYPE or entity definition, which is refused for safety.' );
		}
		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$ok   = $dom->loadXML( $xml, LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok || ! $dom->documentElement ) {
			return new WP_Error( 'podpub_adopt', 'That is not a readable XML feed.' );
		}
		$root = $dom->documentElement;
		if ( 'rss' !== $root->localName || ! $root->getElementsByTagName( 'channel' )->length ) {
			return new WP_Error( 'podpub_adopt', 'That is not an RSS feed with a channel. Only RSS 2.0 podcast feeds can be adopted.' );
		}
		return $dom;
	}

	/** Folders a feed file may be adopted from, as pairs: public address, path. */
	private static function roots() {
		$roots = array();
		$base  = PodPub_Storage::base_path();
		if ( '' !== $base ) {
			$roots[] = array( PodPub_Storage::base_url(), $base );
		}
		$up = wp_upload_dir();
		if ( ! empty( $up['basedir'] ) ) {
			$roots[] = array( isset( $up['baseurl'] ) ? $up['baseurl'] : '', $up['basedir'] );
		}
		$roots[] = array( function_exists( 'site_url' ) ? site_url() : '', rtrim( ABSPATH, '/\\' ) );
		return $roots;
	}

	private static function in_allowed_roots( $real ) {
		foreach ( self::roots() as $r ) {
			$root = realpath( $r[1] );
			if ( $root && ( $real === $root || 0 === strpos( $real, $root . DIRECTORY_SEPARATOR ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The file behind a public address, when it is inside a known folder and exists.
	 *
	 * @param string $url Public address of a feed.
	 * @return string Path, or ''.
	 */
	public static function url_to_path( $url ) {
		$url = (string) $url;
		if ( false !== strpbrk( $url, '?#' ) ) {
			return '';
		}
		foreach ( self::roots() as $r ) {
			$u = rtrim( (string) $r[0], '/' );
			if ( '' === $u || 0 !== strpos( $url, $u . '/' ) ) {
				continue;
			}
			$rel = substr( $url, strlen( $u ) + 1 );
			if ( '' === $rel || ! preg_match( '#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$#', $rel ) || false !== strpos( $rel, '..' ) ) {
				continue;
			}
			$path = realpath( rtrim( $r[1], '/\\' ) . '/' . $rel );
			if ( $path && is_file( $path ) && self::in_allowed_roots( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * The public address of a file inside a known folder, or ''.
	 *
	 * @param string $real Real path of the file.
	 * @return string
	 */
	public static function path_to_url( $real ) {
		foreach ( self::roots() as $r ) {
			$root = realpath( $r[1] );
			$u    = rtrim( (string) $r[0], '/' );
			if ( $root && '' !== $u && 0 === strpos( $real, $root . DIRECTORY_SEPARATOR ) ) {
				$rel = str_replace( DIRECTORY_SEPARATOR, '/', substr( $real, strlen( $root ) + 1 ) );
				if ( preg_match( '#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$#', $rel ) ) {
					return $u . '/' . $rel;
				}
			}
		}
		return '';
	}

	/* ------------------------------------------------------------------ */
	/* Reading the feed                                                    */
	/* ------------------------------------------------------------------ */

	private static function ns_match( $node, $ns ) {
		$u = (string) $node->namespaceURI;
		switch ( $ns ) {
			case 'rss':
				return '' === $u;
			case 'itunes':
				return self::NS_ITUNES === $u;
			case 'content':
				return self::NS_CONTENT === $u;
			case 'atom':
				return self::NS_ATOM === $u;
			case 'podcast':
				return in_array( $u, self::podcast_ns(), true );
		}
		return false;
	}

	/** Child elements of a given namespace group and local name. */
	private static function kids( $el, $ns, $local ) {
		$out = array();
		if ( ! $el ) {
			return $out;
		}
		foreach ( $el->childNodes as $n ) {
			if ( $n instanceof DOMElement && $n->localName === $local && self::ns_match( $n, $ns ) ) {
				$out[] = $n;
			}
		}
		return $out;
	}

	private static function first( $el, $ns, $local ) {
		$k = self::kids( $el, $ns, $local );
		return $k ? $k[0] : null;
	}

	private static function txt( $el ) {
		return $el ? trim( str_replace( "\r", '', (string) $el->textContent ) ) : '';
	}

	private static function at( $el, $name ) {
		return ( $el && $el->hasAttribute( $name ) ) ? trim( $el->getAttribute( $name ) ) : '';
	}

	private static function ftxt( $el, $ns, $local ) {
		return self::txt( self::first( $el, $ns, $local ) );
	}

	/** HTML to plain text (tags removed, line breaks kept). Text with no tags is left alone. */
	private static function plain( $s ) {
		$s = (string) $s;
		if ( ! preg_match( '/<[a-z!\/][^>]*>/i', $s ) ) {
			return trim( $s );
		}
		$s = preg_replace( '#<\s*(br\s*/?|/p|/li|/h[1-6]|/div)\s*>#i', "\n", $s );
		$s = wp_strip_all_tags( $s );
		return trim( preg_replace( "/\n{3,}/", "\n\n", html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	private static function squash( $s ) {
		return trim( preg_replace( '/\s+/u', ' ', (string) $s ) );
	}

	/** A date from a feed (RFC 822 or ISO 8601) as the form text, or ''. */
	private static function when_text( $s ) {
		$s = trim( (string) $s );
		if ( '' === $s ) {
			return '';
		}
		$t = strtotime( $s );
		return false === $t ? '' : PodPub_Episodes::format_date( $t );
	}

	private static function yes( $s ) {
		return in_array( strtolower( trim( (string) $s ) ), array( 'yes', 'true', 'explicit' ), true );
	}

	/** An explicit flag as yes, no or '' (not given). */
	private static function explicit_of( $s ) {
		$s = strtolower( trim( (string) $s ) );
		if ( in_array( $s, array( 'yes', 'true', 'explicit' ), true ) ) {
			return 'yes';
		}
		if ( in_array( $s, array( 'no', 'false', 'clean' ), true ) ) {
			return 'no';
		}
		return '';
	}

	/** Note a problem that belongs to one item among many (counted and exemplified in the report). */
	private static function bump( &$agg, $key, $label, $example ) {
		if ( ! isset( $agg[ $key ] ) ) {
			$agg[ $key ] = array( 'label' => $label, 'n' => 0, 'example' => $example );
		}
		++$agg[ $key ]['n'];
	}

	/**
	 * The tags both a podcast and an episode can have, as form-style rows.
	 *
	 * @param DOMElement $el Channel or item.
	 * @return array Keys as the Podcasting 2.0 form fields.
	 */
	private static function common_in( $el ) {
		$in = array();
		foreach ( self::kids( $el, 'podcast', 'funding' ) as $f ) {
			$in['funding'][] = array( 'url' => self::at( $f, 'url' ), 'message' => self::txt( $f ) );
		}
		foreach ( self::kids( $el, 'podcast', 'location' ) as $l ) {
			$in['locations'][] = array( 'name' => self::txt( $l ), 'rel' => strtolower( self::at( $l, 'rel' ) ), 'geo' => self::at( $l, 'geo' ), 'osm' => self::at( $l, 'osm' ), 'country' => self::at( $l, 'country' ) );
		}
		foreach ( self::kids( $el, 'podcast', 'person' ) as $p ) {
			$in['persons'][] = array( 'name' => self::txt( $p ), 'role' => strtolower( self::at( $p, 'role' ) ), 'group' => strtolower( self::at( $p, 'group' ) ), 'img' => self::at( $p, 'img' ), 'href' => self::at( $p, 'href' ) );
		}
		$lic = self::first( $el, 'podcast', 'license' );
		if ( $lic ) {
			$in['license_name'] = self::txt( $lic );
			$in['license_url']  = self::at( $lic, 'url' );
		}
		foreach ( self::kids( $el, 'podcast', 'value' ) as $v ) {
			if ( 'lightning' !== strtolower( self::at( $v, 'type' ) ) ) {
				continue;
			}
			$in['value_suggested'] = self::at( $v, 'suggested' );
			foreach ( self::kids( $v, 'podcast', 'valueRecipient' ) as $r ) {
				if ( 'node' === strtolower( self::at( $r, 'type' ) ) ) {
					continue; // Keysend (decision 47): not kept.
				}
				$in['recipients'][] = array( 'name' => self::at( $r, 'name' ), 'address' => self::at( $r, 'address' ), 'split' => self::at( $r, 'split' ), 'fee' => self::yes( self::at( $r, 'fee' ) ) ? '1' : '' );
			}
			foreach ( self::kids( $v, 'podcast', 'valueTimeSplit' ) as $s ) {
				$ri                = self::first( $s, 'podcast', 'remoteItem' );
				$in['timesplits'][] = array(
					'start'          => self::at( $s, 'startTime' ),
					'duration'       => self::at( $s, 'duration' ),
					'remote_start'   => self::at( $s, 'remoteStartTime' ),
					'remote_percent' => self::at( $s, 'remotePercentage' ),
					'feed_guid'      => self::at( $ri, 'feedGuid' ),
					'item_guid'      => self::at( $ri, 'itemGuid' ),
					'feed_url'       => self::at( $ri, 'feedUrl' ),
				);
			}
			break; // One value block.
		}
		foreach ( self::kids( $el, 'podcast', 'socialInteract' ) as $s ) {
			$in['social'][] = array( 'protocol' => strtolower( self::at( $s, 'protocol' ) ), 'uri' => self::at( $s, 'uri' ), 'account_id' => self::at( $s, 'accountId' ), 'account_url' => self::at( $s, 'accountUrl' ), 'priority' => self::at( $s, 'priority' ) );
		}
		return $in;
	}

	/**
	 * Read the whole feed into PodPublisher's fields. Nothing is checked or saved here.
	 *
	 * @param DOMDocument $dom Parsed feed.
	 * @return array {
	 *     details_in: array, p20_in: array, title: string, image_url: string, guid: string, op3: bool,
	 *     medium: string, episodes: array[], problems: string[]
	 * }
	 */
	public static function model( $dom ) {
		$agg  = array();
		$prob = array();
		$ch   = $dom->getElementsByTagName( 'channel' )->item( 0 );
		$m    = array( 'problems' => array() );

		$title = self::plain( self::ftxt( $ch, 'rss', 'title' ) );
		$desc  = self::ftxt( $ch, 'rss', 'description' );
		if ( '' === $desc ) {
			$desc = self::ftxt( $ch, 'itunes', 'summary' );
		}
		$dplain = self::plain( $desc );
		if ( $dplain !== trim( $desc ) ) {
			$prob[] = 'The podcast description has HTML; it will become plain text.';
		}
		$lang = strtolower( str_replace( '_', '-', self::ftxt( $ch, 'rss', 'language' ) ) );
		$langs = PodPub_Details::languages();
		if ( ! isset( $langs[ $lang ] ) ) {
			$base = explode( '-', $lang )[0];
			if ( '' !== $lang && isset( $langs[ $base ] ) ) {
				$prob[] = 'The language "' . $lang . '" is not in the list; "' . $base . '" will be used.';
				$lang   = $base;
			} else {
				$prob[] = '' === $lang ? 'The feed has no language; English will be used.' : 'The language "' . $lang . '" is not in the list; English will be used.';
				$lang   = 'en';
			}
		}
		$cat = '';
		$ic  = self::first( $ch, 'itunes', 'category' );
		if ( $ic ) {
			foreach ( PodPub_Details::categories() as $c ) {
				if ( 0 === strcasecmp( $c, self::at( $ic, 'text' ) ) ) {
					$cat = $c;
					break;
				}
			}
			if ( '' === $cat ) {
				$prob[] = 'The category "' . self::at( $ic, 'text' ) . '" is not one of the 19 categories PodPublisher offers; it will be left empty.';
			}
		}
		$expl = self::explicit_of( self::ftxt( $ch, 'itunes', 'explicit' ) );
		if ( '' === $expl ) {
			$prob[] = 'The feed does not say whether it is explicit; it will be set to No (PodPublisher needs a yes or no).';
			$expl   = 'no';
		}
		$link = self::ftxt( $ch, 'rss', 'link' );
		if ( '' !== $link && ! preg_match( '#^https?://[^\s]+$#i', $link ) ) {
			$prob[] = 'The website address "' . $link . '" does not start with http:// or https://; it will be left out.';
			$link   = '';
		}
		$own   = self::first( $ch, 'itunes', 'owner' );
		$oname = self::ftxt( $own, 'itunes', 'name' );
		$omail = self::ftxt( $own, 'itunes', 'email' );
		$me    = self::ftxt( $ch, 'rss', 'managingEditor' );
		if ( '' === $omail && '' !== $me && preg_match( '/^([^\s()]+@[^\s()]+)(?:\s*\((.*)\))?$/', $me, $mm ) ) {
			$omail = $mm[1];
			$oname = '' !== $oname ? $oname : ( isset( $mm[2] ) ? trim( $mm[2] ) : '' );
		}
		if ( '' !== $omail && ! is_email( $omail ) ) {
			$prob[] = 'The owner email address "' . $omail . '" does not look right; it will be left out.';
			$omail  = '';
		}
		$img = self::at( self::first( $ch, 'itunes', 'image' ), 'href' );
		if ( '' === $img ) {
			$img = self::ftxt( self::first( $ch, 'rss', 'image' ), 'rss', 'url' );
		}
		$guid = self::ftxt( $ch, 'podcast', 'guid' );
		if ( '' !== $guid && ! PodPub_P20::valid_uuid( $guid ) ) {
			$prob[] = 'The podcast:guid "' . $guid . '" is not a valid UUID; a new one will be made from the feed address.';
			$guid   = '';
		}
		$medium = strtolower( self::ftxt( $ch, 'podcast', 'medium' ) );
		if ( '' !== $medium && 'podcast' !== $medium ) {
			$prob[] = 'The feed\'s podcast:medium is "' . $medium . '"; PodPublisher writes "podcast".';
		}
		$m['title']      = self::squash( $title );
		$m['image_url']  = $img;
		$m['guid']       = $guid;
		$m['medium']     = $medium;
		$m['details_in'] = array(
			'title'       => $m['title'],
			'description' => $dplain,
			'language'    => $lang,
			'category'    => $cat,
			'explicit'    => $expl,
			'keywords'    => self::ftxt( $ch, 'itunes', 'keywords' ),
			'type'        => 'serial' === strtolower( self::ftxt( $ch, 'itunes', 'type' ) ) ? 'serial' : 'episodic',
			'author'      => self::ftxt( $ch, 'itunes', 'author' ),
			'copyright'   => self::ftxt( $ch, 'rss', 'copyright' ),
			'link'        => $link,
			'owner_name'  => $oname,
			'owner_email' => $omail,
		);

		$p20 = self::common_in( $ch );
		foreach ( self::kids( $ch, 'podcast', 'block' ) as $b ) {
			$p20['block'][] = array( 'id' => self::at( $b, 'id' ), 'value' => strtolower( self::txt( $b ) ) );
		}
		$roll = self::first( $ch, 'podcast', 'podroll' );
		foreach ( self::kids( $roll, 'podcast', 'remoteItem' ) as $r ) {
			$p20['podroll'][] = array( 'feed_guid' => self::at( $r, 'feedGuid' ), 'feed_url' => self::at( $r, 'feedUrl' ), 'title' => self::at( $r, 'title' ) );
		}
		$p20['complete']     = self::yes( self::ftxt( $ch, 'podcast', 'complete' ) ) ? 1 : 0;
		$lk                  = self::first( $ch, 'podcast', 'locked' );
		$p20['locked']       = $lk ? ( self::yes( self::txt( $lk ) ) ? 'yes' : ( in_array( strtolower( self::txt( $lk ) ), array( 'no', 'false' ), true ) ? 'no' : '' ) ) : '';
		$p20['locked_owner'] = self::at( $lk, 'owner' );
		foreach ( self::kids( $ch, 'podcast', 'trailer' ) as $t ) {
			$p20['trailers'][] = array( 'title' => self::txt( $t ), 'url' => self::at( $t, 'url' ), 'pubdate' => self::when_text( self::at( $t, 'pubdate' ) ), 'length' => self::at( $t, 'length' ), 'type' => self::at( $t, 'type' ), 'season' => self::at( $t, 'season' ) );
		}
		foreach ( self::kids( $ch, 'podcast', 'liveItem' ) as $l ) {
			$enc               = self::first( $l, 'rss', 'enclosure' );
			$p20['live'][]     = array(
				'title'  => self::ftxt( $l, 'rss', 'title' ),
				'status' => strtolower( self::at( $l, 'status' ) ),
				'start'  => self::when_text( self::at( $l, 'start' ) ),
				'end'    => self::when_text( self::at( $l, 'end' ) ),
				'url'    => self::at( $enc, 'url' ),
				'type'   => self::at( $enc, 'type' ),
				'link'   => self::at( self::first( $l, 'podcast', 'contentLink' ), 'href' ),
			);
		}
		$m['p20_in'] = $p20;

		// Episodes.
		$op3_n = 0;
		$eps   = array();
		$i     = 0;
		foreach ( $ch->childNodes as $item ) {
			if ( ! ( $item instanceof DOMElement ) || 'item' !== $item->localName || ! self::ns_match( $item, 'rss' ) ) {
				continue;
			}
			++$i;
			$eps[] = self::item_model( $item, $i, $agg, $op3_n );
		}
		$m['episodes'] = $eps;
		$m['op3']      = $op3_n > 0;
		if ( $op3_n > 0 && $op3_n < count( $eps ) ) {
			$prob[] = 'Only ' . $op3_n . ' of ' . count( $eps ) . ' episodes use OP3 download counting; PodPublisher counts all or none, so counting will be switched on for all (the addresses of the others get the OP3 prefix in the feed).';
		}
		foreach ( $agg as $a ) {
			$prob[] = $a['label'] . ': ' . $a['n'] . ( 1 === $a['n'] ? ' episode' : ' episodes' ) . ( '' !== $a['example'] ? ' (for example "' . $a['example'] . '")' : '' ) . '.';
		}
		$m['problems'] = $prob;
		return $m;
	}

	/** One <item> as PodPublisher fields. */
	private static function item_model( $item, $n, &$agg, &$op3_n ) {
		$title = self::squash( self::plain( self::ftxt( $item, 'rss', 'title' ) ) );
		if ( '' === $title ) {
			$title = 'Episode ' . $n;
			self::bump( $agg, 'notitle', 'Episodes with no title (named "Episode N")', $title );
		}
		$desc = self::ftxt( $item, 'rss', 'description' );
		$sum  = self::ftxt( $item, 'itunes', 'summary' );
		if ( '' === trim( $desc ) ) {
			$desc = '' !== $sum ? $sum : $title;
			self::bump( $agg, 'nodesc', 'Episodes with no description (the summary or the title is used)', $title );
		}
		$dplain = self::plain( $desc );
		if ( $dplain !== trim( $desc ) ) {
			self::bump( $agg, 'deschtml', 'HTML removed from the description (plain text only)', $title );
		}
		if ( mb_strlen( $dplain ) > PodPub_Episodes::MAX_DESCRIPTION ) {
			$dplain = mb_substr( $dplain, 0, PodPub_Episodes::MAX_DESCRIPTION );
			self::bump( $agg, 'desclong', 'Description shortened to ' . PodPub_Episodes::MAX_DESCRIPTION . ' characters', $title );
		}
		$splain = self::plain( $sum );
		if ( mb_strlen( $splain ) > PodPub_Episodes::MAX_DESCRIPTION ) {
			$splain = mb_substr( $splain, 0, PodPub_Episodes::MAX_DESCRIPTION );
			self::bump( $agg, 'sumlong', 'Summary shortened to ' . PodPub_Episodes::MAX_DESCRIPTION . ' characters', $title );
		}
		$notes = self::txt( self::first( $item, 'content', 'encoded' ) );
		if ( '' !== $notes ) {
			$clean = PodPub_Episodes::clean_show_notes( $notes );
			if ( self::squash( $clean ) !== self::squash( $notes ) ) {
				self::bump( $agg, 'notes', 'Show notes simplified (PodPublisher keeps only links, bold, italic, paragraphs, lists, headings and quotes)', $title );
			}
			if ( mb_strlen( $clean ) > PodPub_Episodes::MAX_SHOW_NOTES ) {
				self::bump( $agg, 'noteslong', 'Show notes too long to keep (limit ' . PodPub_Episodes::MAX_SHOW_NOTES . ' characters; they will be left empty)', $title );
				$notes = '';
			}
		}

		$ts   = strtotime( self::ftxt( $item, 'rss', 'pubDate' ) );
		$pub  = false === $ts ? '' : PodPub_Episodes::format_date( $ts );
		$nodate = '' === $pub;
		if ( $nodate ) {
			$pub = PodPub_Episodes::format_date( time() );
			self::bump( $agg, 'nodate', 'Episodes with no readable publish date (set to the time of adoption, and kept as drafts)', $title );
		}
		$dur_text = self::ftxt( $item, 'itunes', 'duration' );
		$dur      = PodPub_Episodes::parse_duration( $dur_text );
		if ( null === $dur ) {
			$dur_text = '';
			self::bump( $agg, 'dur', 'Duration not understood (left empty)', $title );
		}
		$nums = array();
		foreach ( array( 'season', 'episode' ) as $k ) {
			$v = self::ftxt( $item, 'podcast', $k );
			if ( '' === $v ) {
				$v = self::ftxt( $item, 'itunes', $k );
			}
			if ( '' !== $v && ( ! preg_match( '/^\d{1,5}$/', $v ) || (int) $v < 1 ) ) {
				self::bump( $agg, 'num' . $k, 'The ' . $k . ' number is not a whole number from 1 (left empty)', $title );
				$v = '';
			}
			$nums[ $k ] = $v;
		}
		$etype = strtolower( self::ftxt( $item, 'itunes', 'episodeType' ) );
		if ( ! in_array( $etype, array( 'full', 'trailer', 'bonus' ), true ) ) {
			$etype = 'full';
		}

		// Audio.
		$enc  = self::first( $item, 'rss', 'enclosure' );
		$url  = self::at( $enc, 'url' );
		$op3  = false;
		if ( preg_match( '#^https://op3\.dev/e(?:,[^/]*)?/(.+)$#i', $url, $om ) ) {
			$rest = $om[1];
			$url  = preg_match( '#^https?://#i', $rest ) ? $rest : 'https://' . $rest;
			$op3  = true;
			++$op3_n;
		}
		$mime = strtolower( trim( explode( ';', self::at( $enc, 'type' ) )[0] ) );
		$audio = array( 'url' => '', 'type' => '', 'size' => 0, 'op3' => $op3 );
		if ( '' === $url ) {
			self::bump( $agg, 'noenc', 'Items with no audio file (kept as drafts, not in the feed)', $title );
		} elseif ( ! PodPub_P20::valid_http( $url ) ) {
			self::bump( $agg, 'httpenc', 'The audio address is not an https:// address (kept as drafts, not in the feed)', $title );
		} else {
			if ( ! preg_match( '#^audio/[a-z0-9][a-z0-9.+_-]*$#', $mime ) ) {
				$ext   = strtolower( pathinfo( (string) parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
				$types = PodPub_Episodes::audio_types();
				$mime  = isset( $types[ $ext ] ) ? $types[ $ext ] : '';
			}
			if ( '' === $mime ) {
				self::bump( $agg, 'badtype', 'The audio file type is not known (kept as drafts, not in the feed)', $title );
			} else {
				$len   = self::at( $enc, 'length' );
				$audio = array( 'url' => $url, 'type' => $mime, 'size' => ctype_digit( $len ) ? (int) $len : 0, 'op3' => $op3 );
			}
		}

		$in = array(
			'title'       => $title,
			'description' => $dplain,
			'show_notes'  => $notes,
			'published'   => $pub,
			'season'      => $nums['season'],
			'episode'     => $nums['episode'],
			'type'        => $etype,
			'duration'    => $dur_text,
			'explicit'    => self::explicit_of( self::ftxt( $item, 'itunes', 'explicit' ) ),
			'subtitle'    => self::ftxt( $item, 'itunes', 'subtitle' ),
			'summary'     => $splain,
			'keywords'    => self::ftxt( $item, 'itunes', 'keywords' ),
			'status'      => ( '' !== $audio['url'] && ! $nodate ) ? 'published' : 'draft',
		);

		$p20 = self::common_in( $item );
		foreach ( self::kids( $item, 'podcast', 'transcript' ) as $t ) {
			$p20['transcripts'][] = array( 'url' => self::at( $t, 'url' ), 'type' => strtolower( self::at( $t, 'type' ) ), 'language' => self::at( $t, 'language' ), 'rel' => self::at( $t, 'rel' ) );
		}
		foreach ( self::kids( $item, 'podcast', 'soundbite' ) as $s ) {
			$p20['soundbites'][] = array( 'start' => self::at( $s, 'startTime' ), 'duration' => self::at( $s, 'duration' ), 'title' => self::txt( $s ) );
		}
		foreach ( self::kids( $item, 'podcast', 'alternateEnclosure' ) as $a ) {
			$src = self::first( $a, 'podcast', 'source' );
			$int = self::first( $a, 'podcast', 'integrity' );
			$p20['enclosures'][] = array(
				'uri'             => self::at( $src, 'uri' ),
				'type'            => self::at( $a, 'type' ),
				'length'          => self::at( $a, 'length' ),
				'bitrate'         => self::at( $a, 'bitrate' ),
				'height'          => self::at( $a, 'height' ),
				'lang'            => self::at( $a, 'lang' ),
				'title'           => self::at( $a, 'title' ),
				'rel'             => self::at( $a, 'rel' ),
				'codecs'          => self::at( $a, 'codecs' ),
				'default'         => self::yes( self::at( $a, 'default' ) ) ? '1' : '',
				'integrity_type'  => strtolower( self::at( $int, 'type' ) ),
				'integrity_value' => self::at( $int, 'value' ),
			);
		}
		$p20['chapters_url'] = self::at( self::first( $item, 'podcast', 'chapters' ), 'url' );

		return array(
			'title'     => $title,
			'in'        => $in,
			'p20_in'    => $p20,
			'guid'      => self::ftxt( $item, 'rss', 'guid' ),
			'audio'     => $audio,
			'image_url' => self::at( self::first( $item, 'itunes', 'image' ), 'href' ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Checking                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Run each Podcasting 2.0 group through PodPublisher's own check on its own, so one
	 * bad group is left out (and reported) instead of stopping everything.
	 *
	 * @param array    $in       Form-style values.
	 * @param bool     $item     True for an episode.
	 * @param string   $label    Who this is for (used in messages).
	 * @param string[] $problems Problems (added to).
	 * @return array The tag fields, as PodPub_P20::defaults() has them.
	 */
	private static function p20_clean( $in, $item, $label, &$problems ) {
		$d = PodPub_P20::defaults( $item );
		// name => { what to call it, the check, the key of its rows, the most rows allowed }.
		$groups = array(
			'funding'   => array( 'funding links', function ( $x, &$e ) { return PodPub_P20::funding( $x, $e ); }, 'funding', PodPub_P20::MAX_FUNDING ),
			'locations' => array( 'locations', function ( $x, &$e ) { return PodPub_P20::locations( $x, $e ); }, 'locations', PodPub_P20::MAX_LOCATIONS ),
			'persons'   => array( 'people', function ( $x, &$e ) { return PodPub_P20::persons( $x, $e ); }, 'persons', PodPub_P20::MAX_PERSONS ),
			'license'   => array( 'license', function ( $x, &$e ) { return PodPub_P20::license( $x, $e ); }, null, 0 ),
			'value'     => array( 'payments', function ( $x, &$e ) { return PodPub_P20::value( $x, $e ); }, null, 0 ),
			'social'    => array( 'comment links', function ( $x, &$e ) { return PodPub_P20::social( $x, $e ); }, 'social', PodPub_P20::MAX_SOCIAL ),
		);
		if ( $item ) {
			$groups['transcripts'] = array( 'transcripts', function ( $x, &$e ) { return PodPub_P20::transcripts( $x, $e ); }, 'transcripts', PodPub_P20::MAX_TRANSCRIPTS );
			$groups['soundbites']  = array( 'soundbites', function ( $x, &$e ) { return PodPub_P20::soundbites( $x, $e ); }, 'soundbites', PodPub_P20::MAX_SOUNDBITES );
			$groups['enclosures']  = array( 'alternate files', function ( $x, &$e ) { return PodPub_P20::enclosures( $x, $e ); }, 'enclosures', PodPub_P20::MAX_ENCLOSURES );
		} else {
			$groups['block']    = array( 'block', function ( $x, &$e ) { return PodPub_P20::block( $x, $e ); }, 'block', PodPub_P20::MAX_BLOCK );
			$groups['podroll']  = array( 'podroll', function ( $x, &$e ) { return PodPub_P20::podroll( $x, $e ); }, 'podroll', PodPub_P20::MAX_PODROLL );
			$groups['trailers'] = array( 'trailers', function ( $x, &$e ) { return PodPub_P20::trailers( $x, $e ); }, 'trailers', PodPub_P20::MAX_TRAILERS );
			$groups['live']     = array( 'live episodes', function ( $x, &$e ) { return PodPub_P20::live( $x, $e ); }, 'live', PodPub_P20::MAX_LIVE );
		}
		foreach ( $groups as $key => $g ) {
			$e = array();
			$r = $g[1]( $in, $e );
			if ( ! $e ) {
				$d[ $key ] = $r;
				continue;
			}
			// A group with a bad row: keep the rows that pass on their own, report the others.
			if ( null !== $g[2] && isset( $in[ $g[2] ] ) && is_array( $in[ $g[2] ] ) && count( $in[ $g[2] ] ) > 1 ) {
				$kept = array();
				foreach ( array_values( $in[ $g[2] ] ) as $n => $row ) {
					$sub          = $in;
					$sub[ $g[2] ] = array( $row );
					$re           = array();
					$rr           = $g[1]( $sub, $re );
					if ( $re ) {
						$problems[] = $label . ': ' . $g[0] . ' row ' . ( $n + 1 ) . ' left out: ' . implode( ' ', $re );
					} elseif ( is_array( $rr ) ) {
						$kept = array_merge( $kept, $rr );
					}
				}
				$d[ $key ] = array_slice( $kept, 0, $g[3] );
				continue;
			}
			$problems[] = $label . ': ' . $g[0] . ' left out: ' . implode( ' ', $e );
		}
		if ( $item ) {
			$val = $d['value'];
			$e   = array();
			$r   = PodPub_P20::timesplits( $in, $val, $e );
			if ( $e ) {
				$problems[] = $label . ': time splits left out: ' . implode( ' ', $e );
			} else {
				$d['timesplits'] = $r;
			}
			if ( isset( $in['chapters_url'] ) && '' !== $in['chapters_url'] ) {
				$e = array();
				$r = PodPub_P20::chapters( array( 'chapters_url' => $in['chapters_url'] ), $e );
				if ( $e ) {
					$problems[] = $label . ': chapters address left out: ' . implode( ' ', $e );
				} else {
					$d['chapters_url'] = $r['url'];
				}
			}
		} else {
			$e = array();
			$r = PodPub_P20::channel_flags( $in, $d, $e );
			if ( $e ) {
				$problems[] = $label . ': complete and locked left out: ' . implode( ' ', $e );
			} else {
				$d = $r;
			}
		}
		return $d;
	}

	/**
	 * Where the adopted feed will be written: back over the old file (and at the old
	 * address) when it is on this server and can be written, otherwise in the new
	 * podcast's own folder.
	 *
	 * @param array $loaded Result of load().
	 * @return array { in_place: bool, file: string, url: string }
	 */
	public static function target( $loaded ) {
		$file = $loaded['file'];
		if ( '' !== $file && '' !== $loaded['url'] && is_writable( $file ) && is_writable( dirname( $file ) ) ) {
			return array( 'in_place' => true, 'file' => $file, 'url' => $loaded['url'] );
		}
		return array( 'in_place' => false, 'file' => '', 'url' => '' );
	}

	/**
	 * Read the feed and work out, without writing anything, what adopting it would do.
	 *
	 * @param array $loaded Result of load().
	 * @return array|WP_Error { report: array, final: array } or an error when the feed cannot be adopted.
	 */
	public static function plan( $loaded ) {
		$m        = self::model( $loaded['dom'] );
		$problems = $m['problems'];
		$target   = self::target( $loaded );
		if ( ! $target['in_place'] && '' === PodPub_Storage::base_url() ) {
			return new WP_Error( 'podpub_adopt', 'Set the public address of the files in PodPublisher Settings first: the feed needs it to write audio and picture addresses.' );
		}

		$r = PodPub_Details::sanitize( $m['details_in'], PodPub_Details::defaults() );
		if ( $r['errors'] ) {
			return new WP_Error( 'podpub_adopt', 'This feed cannot be adopted: ' . implode( ' ', $r['errors'] ) );
		}
		$details = array_merge( $r['data'], self::p20_clean( $m['p20_in'], false, 'The podcast', $problems ) );
		if ( '' === $m['guid'] ) {
			$m['guid'] = '' !== $target['url'] ? PodPub_FeedXml::guid_for_url( $target['url'] ) : PodPub_FeedXml::random_guid();
		}
		$details['guid'] = $m['guid'];
		$details['op3']  = $m['op3'] ? 1 : 0;

		$episodes = array();
		$skipped  = 0;
		foreach ( $m['episodes'] as $e ) {
			$has = '' !== $e['audio']['url'];
			$s   = PodPub_Episodes::sanitize( $e['in'], PodPub_Episodes::defaults(), $has );
			if ( $s['errors'] ) {
				$problems[] = 'Episode "' . $e['title'] . '" cannot be adopted and is left out: ' . implode( ' ', $s['errors'] );
				++$skipped;
				continue;
			}
			$d = array_merge( $s['data'], self::p20_clean( $e['p20_in'], true, 'Episode "' . $e['title'] . '"', $problems ) );
			if ( $has ) {
				$d['audio_source'] = 'external';
				$d['external_url'] = $e['audio']['url'];
				$d['audio_type']   = $e['audio']['type'];
				$d['audio_size']   = $e['audio']['size'];
			}
			$d['guid']  = $e['guid'];
			$episodes[] = array( 'title' => $s['title'], 'data' => $d, 'image_url' => $e['image_url'] );
		}

		// Build the feed PodPublisher would write, and compare it with the old one.
		$now   = time();
		$items = array();
		$pub   = 0;
		$draft = 0;
		foreach ( $episodes as $e ) {
			$d = $e['data'];
			if ( 'published' !== $d['status'] ) {
				++$draft;
				continue;
			}
			if ( $d['published'] > $now ) {
				$problems[] = 'Episode "' . $e['title'] . '" is dated in the future, so it stays out of the feed until the feed is next written after that time (automatic scheduling is not built).';
				continue;
			}
			++$pub;
			$items[] = array_merge( $d, array(
				'image_url'     => $e['image_url'],
				'images_srcset' => '',
				'chapters_href' => $d['chapters_url'],
				'title'         => $e['title'],
				'audio_url'     => $d['external_url'],
				'guid'          => '' !== $d['guid'] ? $d['guid'] : 'placeholder',
			) );
		}
		$xml = PodPub_FeedXml::build( array(
			'title'     => $m['title'],
			'details'   => $details,
			'feed_url'  => $target['url'],
			'image_url' => $m['image_url'],
			'episodes'  => $items,
			'now'       => $now,
		) );
		$new  = self::parse( $xml );
		$lost = is_wp_error( $new ) ? array() : self::lost( $loaded['dom'], $new );

		$sig    = array( $problems, array_map( function ( $l ) { return array( $l['label'], $l['old'], $l['new'] ); }, $lost ), $target['in_place'], count( $episodes ), $pub, $draft );
		$report = array(
			'title'     => $m['title'],
			'episodes'  => count( $m['episodes'] ),
			'published' => $pub,
			'drafts'    => $draft,
			'skipped'   => $skipped,
			'problems'  => $problems,
			'lost'      => $lost,
			'target'    => $target,
			'guid'      => $m['guid'],
			'hash'      => substr( sha1( json_encode( $sig ) ), 0, 20 ),
		);
		return array(
			'report' => $report,
			'final'  => array( 'title' => $m['title'], 'details' => $details, 'image_url' => $m['image_url'], 'episodes' => $episodes, 'target' => $target ),
		);
	}

	/* ---- the comparison ---- */

	private static function qname( $node ) {
		$u = (string) $node->namespaceURI;
		if ( '' === $u ) {
			return $node->localName;
		}
		if ( self::NS_ITUNES === $u ) {
			return 'itunes:' . $node->localName;
		}
		if ( in_array( $u, self::podcast_ns(), true ) ) {
			return 'podcast:' . $node->localName;
		}
		if ( self::NS_CONTENT === $u ) {
			return 'content:' . $node->localName;
		}
		if ( self::NS_ATOM === $u ) {
			return 'atom:' . $node->localName;
		}
		return '{' . $u . '}' . $node->localName;
	}

	/**
	 * Every element and attribute under the channel, counted by where it is.
	 *
	 * @param DOMDocument $dom Feed.
	 * @return array { counts: array<string,int>, show: array<string,string>, sample: array<string,string> }
	 */
	private static function inventory( $dom ) {
		$out = array( 'counts' => array(), 'show' => array(), 'sample' => array() );
		$ch  = $dom->getElementsByTagName( 'channel' )->item( 0 );
		$add = function ( $key, $show, $sample ) use ( &$out ) {
			$out['counts'][ $key ] = isset( $out['counts'][ $key ] ) ? $out['counts'][ $key ] + 1 : 1;
			if ( ! isset( $out['show'][ $key ] ) ) {
				$out['show'][ $key ]   = $show;
				$out['sample'][ $key ] = $sample;
			}
		};
		$walk = function ( $el, $path, $show ) use ( &$walk, $add, $dom ) {
			foreach ( $el->childNodes as $n ) {
				if ( ! ( $n instanceof DOMElement ) ) {
					continue;
				}
				$q = self::qname( $n );
				// What PodPublisher always writes afresh.
				if ( '' === $path && in_array( $q, array( 'lastBuildDate', 'generator' ), true ) ) {
					continue;
				}
				if ( '' === $path && 'atom:link' === $q && 'self' === strtolower( $n->getAttribute( 'rel' ) ) ) {
					continue;
				}
				$p  = '' === $path ? $q : $path . '/' . $q;
				$sh = '' === $show ? $n->nodeName : $show . '/' . $n->nodeName;
				$sample = preg_replace( '/\s+/', ' ', trim( (string) $dom->saveXML( $n ) ) );
				$sample = mb_strlen( $sample ) > 160 ? mb_substr( $sample, 0, 157 ) . '...' : $sample;
				$add( $p, $sh, $sample );
				foreach ( $n->attributes as $a ) {
					$add( $p . '@' . self::qname( $a ), $sh . '@' . $a->nodeName, $sample );
				}
				$walk( $n, $p, $sh );
			}
		};
		$walk( $ch, '', '' );
		return $out;
	}

	/**
	 * What the old feed has that the new one does not (by element, attribute and how many).
	 *
	 * @param DOMDocument $old Old feed.
	 * @param DOMDocument $new The feed PodPublisher would write.
	 * @return array[] Rows { label, old, new, sample }, in feed order.
	 */
	public static function lost( $old, $new ) {
		$a    = self::inventory( $old );
		$b    = self::inventory( $new );
		$rows = array();
		foreach ( $a['counts'] as $key => $n ) {
			$m = isset( $b['counts'][ $key ] ) ? $b['counts'][ $key ] : 0;
			if ( $m < $n ) {
				$rows[] = array( 'label' => $a['show'][ $key ], 'old' => $n, 'new' => $m, 'sample' => $a['sample'][ $key ] );
			}
		}
		return $rows;
	}

	/* ------------------------------------------------------------------ */
	/* Adopting                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Fetch a picture (https only, 10 MB at most), check it like an uploaded one and store it.
	 *
	 * @param string   $url      Picture address.
	 * @param string   $folder   Full path of the folder to put it in.
	 * @param string   $base     cover (show art) or image (episode picture).
	 * @param string[] $warnings Warnings (added to).
	 * @param string   $label    Who it is for.
	 * @return array|null { name, width, sizes }
	 */
	private static function fetch_image( $url, $folder, $base, &$warnings, $label ) {
		if ( '' === $url ) {
			return null;
		}
		if ( ! PodPub_P20::valid_http( $url ) ) {
			$warnings[] = $label . ': the picture address is not an https:// address, so the picture was not kept.';
			return null;
		}
		$r = wp_safe_remote_get( $url, array( 'timeout' => 15, 'redirection' => 3, 'limit_response_size' => PodPub_Details::MAX_IMAGE_BYTES + 1 ) );
		if ( is_wp_error( $r ) || (int) wp_remote_retrieve_response_code( $r ) < 200 || (int) wp_remote_retrieve_response_code( $r ) >= 300 ) {
			$warnings[] = $label . ': the picture could not be fetched, so it was not kept (' . $url . ').';
			return null;
		}
		$tmp = tempnam( sys_get_temp_dir(), 'podpub' );
		if ( false === $tmp || false === @file_put_contents( $tmp, (string) wp_remote_retrieve_body( $r ) ) ) { // phpcs:ignore
			$warnings[] = $label . ': the picture could not be saved.';
			return null;
		}
		$info = PodPub_Details::inspect_image( $tmp );
		if ( is_wp_error( $info ) ) {
			$warnings[] = $label . ': ' . lcfirst( $info->get_error_message() ) . ' It was not kept.';
			@unlink( $tmp ); // phpcs:ignore
			return null;
		}
		$name = PodPub_Details::store_image( $tmp, $folder, $info['ext'], $base );
		@unlink( $tmp ); // phpcs:ignore
		if ( is_wp_error( $name ) ) {
			$warnings[] = $label . ': ' . lcfirst( $name->get_error_message() );
			return null;
		}
		foreach ( $info['warnings'] as $w ) {
			$warnings[] = $label . ': ' . $w;
		}
		return array( 'name' => $name, 'width' => $info['width'], 'sizes' => 'gif' === $info['ext'] ? array() : PodPub_Images::build( $folder, $name, $info['width'] ) );
	}

	/**
	 * Adopt the feed: make the podcast and its episodes, keep the guids, download the
	 * pictures, and write the feed (over the old file when it is on this server, after
	 * keeping a copy of it). Only runs when the report the owner confirmed is still what
	 * the feed would give.
	 *
	 * @param array  $loaded        Result of load().
	 * @param string $expected_hash The report hash the owner confirmed.
	 * @return array|WP_Error { id: int, episodes: int, warnings: string[], backup: string, written: bool }
	 */
	public static function commit( $loaded, $expected_hash ) {
		$plan = self::plan( $loaded );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( ! hash_equals( $plan['report']['hash'], (string) $expected_hash ) ) {
			return new WP_Error( 'podpub_adopt', 'The feed is not the same as when it was checked. Nothing was changed. Check it again.' );
		}
		$f        = $plan['final'];
		$warnings = array();
		$id       = PodPub_Feeds::create( $f['title'] );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$folder = PodPub_Storage::base_path() . '/' . PodPub_Feeds::dir_name( $id );
		$details = $f['details'];
		$img     = self::fetch_image( $f['image_url'], $folder, 'cover', $warnings, 'Show art' );
		if ( $img ) {
			$details['image']       = $img['name'];
			$details['image_w']     = $img['width'];
			$details['image_sizes'] = $img['sizes'];
		}
		PodPub_Details::save( $id, $details );
		if ( $f['target']['in_place'] ) {
			update_post_meta( $id, PodPub_Feeds::META_FEED_FILE, $f['target']['file'] );
			update_post_meta( $id, PodPub_Feeds::META_FEED_URL, $f['target']['url'] );
		}
		$n = 0;
		foreach ( $f['episodes'] as $e ) {
			$eid = PodPub_Episodes::import_record( $id, $e['title'], $e['data'] );
			if ( is_wp_error( $eid ) ) {
				$warnings[] = 'Episode "' . $e['title'] . '" was not created: ' . $eid->get_error_message();
				continue;
			}
			++$n;
			if ( '' !== $e['image_url'] ) {
				$ef  = $folder . '/' . PodPub_Episodes::dir_name( $eid );
				$pic = self::fetch_image( $e['image_url'], $ef, 'image', $warnings, 'Episode "' . $e['title'] . '"' );
				if ( $pic ) {
					$d                = PodPub_Episodes::details( $eid );
					$d['image']       = $pic['name'];
					$d['image_w']     = $pic['width'];
					$d['image_sizes'] = $pic['sizes'];
					update_post_meta( $eid, PodPub_Episodes::META, $d );
				}
			}
		}

		$backup = '';
		if ( $f['target']['in_place'] ) {
			$file   = $f['target']['file'];
			$backup = PodPub_FeedXml::backup( $file );
			if ( '' === $backup ) {
				$warnings[] = 'The old feed file could not be copied, so it was NOT replaced. The podcast was adopted but its feed has not been written; fix the folder permissions and save the podcast to write it.';
				update_post_meta( $id, PodPub_Feeds::META_FEED_FILE, '' );
				update_post_meta( $id, PodPub_Feeds::META_FEED_URL, '' );
				$backup = '';
			}
		}
		$written = PodPub_FeedXml::write( $id, true, false );
		if ( is_wp_error( $written ) ) {
			$warnings[] = 'The podcast was adopted, but ' . lcfirst( $written->get_error_message() );
		}
		return array( 'id' => (int) $id, 'episodes' => $n, 'warnings' => $warnings, 'backup' => $backup, 'written' => ! is_wp_error( $written ) );
	}
}
