<?php
/**
 * Import value time splits from pasted text or an uploaded file (TODO 19).
 *
 * The usual input is ready-made podcast:valueTimeSplit XML (as in a feed), but a few other
 * layouts are understood too: a whole value block or item with the splits inside it, XML that
 * uses another prefix, JSON, and comma, tab, semicolon, pipe or space separated lines with or
 * without a header row. Nothing is saved here: the rows come back as the form holds them and
 * go through the normal checks in PodPub_P20::timesplits().
 *
 * @package PodPublisher
 */

defined( 'ABSPATH' ) || exit;

class PodPub_SplitsImport {

	/** The most text read (1 MB). */
	const MAX_BYTES = 1048576;

	/** The fields of a row, in the order a column list without a header is read. */
	const FIELDS = array( 'start', 'duration', 'feed_guid', 'item_guid', 'remote_start', 'remote_percent', 'feed_url' );

	/**
	 * Read time splits out of text.
	 *
	 * @param string   $text     Pasted text or file contents.
	 * @param string[] $errors   Plain-language problems (added to). Nothing should be used when there are any.
	 * @param string[] $warnings What was left out or not kept (added to).
	 * @return array Rows: start, duration, remote_start, remote_percent, feed_guid, item_guid, feed_url (all strings).
	 */
	public static function parse( $text, &$errors, &$warnings ) {
		$text = (string) $text;
		if ( strlen( $text ) > self::MAX_BYTES ) {
			$errors[] = 'Time splits import: the text is over 1 MB. A list of time splits is normally a few kilobytes.';
			return array();
		}
		if ( ! preg_match( '//u', $text ) ) {
			$text = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $text, 'UTF-8', 'UTF-8, ISO-8859-1' ) : $text;
		}
		$text = trim( preg_replace( '/^\xEF\xBB\xBF/', '', $text ) );
		if ( '' === $text ) {
			$errors[] = 'Time splits import: there is nothing to import.';
			return array();
		}
		$first = $text[0];
		if ( '<' === $first ) {
			$rows = self::from_xml( $text, $errors, $warnings );
		} elseif ( '[' === $first || '{' === $first ) {
			$rows = self::from_json( $text, $errors, $warnings );
		} else {
			$rows = self::from_lines( $text, $errors, $warnings );
		}
		if ( ! $errors && ! $rows ) {
			$errors[] = 'Time splits import: no time splits were found in that text.';
		}
		return $rows;
	}

	/** A blank row. */
	private static function blank() {
		return array_fill_keys( array( 'start', 'duration', 'remote_start', 'remote_percent', 'feed_guid', 'item_guid', 'feed_url' ), '' );
	}

	// ---- XML ----

	private static function from_xml( $text, &$errors, &$warnings ) {
		if ( preg_match( '/<!(DOCTYPE|ENTITY)/i', $text ) ) {
			$errors[] = 'Time splits import: XML with a DOCTYPE or ENTITY is refused for safety. Paste just the valueTimeSplit tags.';
			return array();
		}
		$text = preg_replace( '/^\s*<\?xml[^>]*\?>/i', '', $text );
		$ns   = ' xmlns:podcast="https://podcastindex.org/namespace/1.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/"';
		$dom  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = $dom->loadXML( '<podpub-root' . $ns . '>' . $text . '</podpub-root>', LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			return self::from_tags( $text, $errors, $warnings );
		}
		$rows = array();
		$left = array();
		$skip = 0;
		$x    = new DOMXPath( $dom );
		foreach ( $x->query( "//*[local-name()='valueTimeSplit']" ) as $s ) {
			$row = self::blank();
			foreach ( $s->attributes as $a ) {
				$k = self::key( $a->localName );
				if ( 'start' === $k || 'duration' === $k || 'remote_start' === $k || 'remote_percent' === $k ) {
					$row[ $k ] = $a->value;
				} else {
					$left[ $a->localName ] = true;
				}
			}
			$ri = $x->query( ".//*[local-name()='remoteItem']", $s )->item( 0 );
			if ( $ri ) {
				foreach ( $ri->attributes as $a ) {
					$k = self::key( $a->localName );
					if ( 'feed_guid' === $k || 'item_guid' === $k || 'feed_url' === $k ) {
						$row[ $k ] = $a->value;
					} else {
						$left[ 'remoteItem ' . $a->localName ] = true;
					}
				}
			} elseif ( $x->query( ".//*[local-name()='valueRecipient']", $s )->length ) {
				++$skip;
				continue;
			}
			$rows[] = $row;
		}
		if ( ! $rows && ! $skip ) {
			// An undeclared prefix gives names the parser did not split; read the tags as text.
			return self::from_tags( $text, $errors, $warnings );
		}
		if ( $skip ) {
			$warnings[] = 'Time splits import: ' . $skip . ( 1 === $skip ? ' split has' : ' splits have' ) . ' its own payment recipients instead of another episode, which PodPublisher does not keep. ' . ( 1 === $skip ? 'It was' : 'They were' ) . ' left out.';
		}
		$outside = 0;
		foreach ( $x->query( "//*[local-name()='valueRecipient']" ) as $r ) {
			if ( ! self::inside_split( $r ) ) {
				++$outside;
			}
		}
		if ( $outside ) {
			$warnings[] = 'Time splits import: the ' . $outside . ' payment recipient(s) outside the time splits were not imported (use the payments table).';
		}
		if ( $left ) {
			$warnings[] = 'Time splits import: these attributes are not kept: ' . implode( ', ', array_keys( $left ) ) . '.';
		}
		return $rows;
	}

	private static function inside_split( $node ) {
		for ( $p = $node->parentNode; $p; $p = $p->parentNode ) {
			if ( 'valueTimeSplit' === $p->localName ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Tag soup that is not valid XML (an unknown prefix, a missing closing tag): read the tags with
	 * a pattern instead.
	 */
	private static function from_tags( $text, &$errors, &$warnings ) {
		if ( ! preg_match_all( '#<(?:[A-Za-z0-9_-]+:)?valueTimeSplit\b([^>]*?)/?(?:>|$)(.*?)(?=<(?:[A-Za-z0-9_-]+:)?valueTimeSplit\b|$)#is', $text, $m, PREG_SET_ORDER ) ) {
			$errors[] = 'Time splits import: that is not readable XML and no valueTimeSplit tags were found in it.';
			return array();
		}
		$rows = array();
		foreach ( $m as $one ) {
			$row   = self::blank();
			$attrs = self::attrs( $one[1] );
			foreach ( $attrs as $k => $v ) {
				if ( isset( $row[ self::key( $k ) ] ) ) {
					$row[ self::key( $k ) ] = $v;
				}
			}
			if ( preg_match( '#<(?:[A-Za-z0-9_-]+:)?remoteItem\b([^>]*?)/?(?:>|$)#i', $one[2], $ri ) ) {
				foreach ( self::attrs( $ri[1] ) as $k => $v ) {
					if ( isset( $row[ self::key( $k ) ] ) ) {
						$row[ self::key( $k ) ] = $v;
					}
				}
			}
			$rows[] = $row;
		}
		$warnings[] = 'Time splits import: the text was not valid XML, so the tags were read as plain text. Check the rows below before saving.';
		return $rows;
	}

	private static function attrs( $s ) {
		$out = array();
		if ( preg_match_all( '/([A-Za-z_:][\w:.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $s, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $a ) {
				$k         = strpos( $a[1], ':' ) !== false ? substr( $a[1], strrpos( $a[1], ':' ) + 1 ) : $a[1];
				$out[ $k ] = html_entity_decode( isset( $a[3] ) && '' !== $a[3] ? $a[3] : $a[2], ENT_QUOTES, 'UTF-8' );
			}
		}
		return $out;
	}

	// ---- JSON ----

	private static function from_json( $text, &$errors, &$warnings ) {
		$j = json_decode( $text, true );
		if ( ! is_array( $j ) ) {
			$errors[] = 'Time splits import: that looks like JSON but could not be read.';
			return array();
		}
		$list = $j;
		for ( $i = 0; $i < 4 && is_array( $list ) && ! self::is_list( $list ); $i++ ) {
			$next = null;
			foreach ( $list as $k => $v ) {
				if ( is_array( $v ) && in_array( self::flat( $k ), array( 'valuetimesplits', 'valuetimesplit', 'timesplits', 'splits', 'value', 'item', 'episode' ), true ) ) {
					$next = $v;
					break;
				}
			}
			if ( null === $next ) {
				$list = array( $list ); // A single split object.
				break;
			}
			$list = $next;
		}
		$rows = array();
		$left = array();
		foreach ( is_array( $list ) ? $list : array() as $o ) {
			if ( ! is_array( $o ) ) {
				continue;
			}
			$row = self::blank();
			foreach ( $o as $k => $v ) {
				if ( 'remoteitem' === self::flat( $k ) && is_array( $v ) ) {
					foreach ( $v as $k2 => $v2 ) {
						self::put( $row, $k2, $v2, $left );
					}
				} else {
					self::put( $row, $k, $v, $left );
				}
			}
			$rows[] = $row;
		}
		if ( $left ) {
			$warnings[] = 'Time splits import: these fields are not kept: ' . implode( ', ', array_keys( $left ) ) . '.';
		}
		return $rows;
	}

	private static function is_list( $a ) {
		return array_keys( $a ) === range( 0, count( $a ) - 1 );
	}

	private static function put( &$row, $k, $v, &$left ) {
		$f = self::key( $k );
		if ( isset( $row[ $f ] ) && is_scalar( $v ) ) {
			$row[ $f ] = (string) $v;
		} else {
			$left[ (string) $k ] = true;
		}
	}

	// ---- Lines (CSV, TSV, ...) ----

	private static function from_lines( $text, &$errors, &$warnings ) {
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $l ) {
			$l = trim( $l );
			if ( '' !== $l && '#' !== $l[0] && '//' !== substr( $l, 0, 2 ) ) {
				$lines[] = $l;
			}
		}
		if ( ! $lines ) {
			return array();
		}
		$delim = self::delimiter( $lines[0] );
		$split = function ( $l ) use ( $delim ) {
			$cells = null === $delim ? preg_split( '/\s+/', $l ) : str_getcsv( $l, $delim, '"', '' );
			return array_map( function ( $c ) {
				return trim( (string) $c, " \t\"'" );
			}, $cells );
		};
		$cols  = self::FIELDS;
		$first = $split( $lines[0] );
		$names = array();
		foreach ( $first as $c ) {
			$k = self::key( $c );
			if ( in_array( $k, self::FIELDS, true ) ) {
				$names[] = $k;
			} else {
				$names[] = null;
			}
		}
		if ( count( array_filter( $names ) ) >= 2 ) { // A header row.
			$cols = $names;
			array_shift( $lines );
		}
		$rows = array();
		$bad  = 0;
		foreach ( $lines as $l ) {
			$cells = $split( $l );
			$row   = self::blank();
			foreach ( $cells as $i => $c ) {
				if ( isset( $cols[ $i ] ) && null !== $cols[ $i ] ) {
					$row[ $cols[ $i ] ] = $c;
				}
			}
			if ( '' === $row['start'] && '' === $row['feed_guid'] ) {
				++$bad;
				continue;
			}
			$rows[] = $row;
		}
		if ( $bad ) {
			$warnings[] = 'Time splits import: ' . $bad . ' line(s) with no start time and no feed GUID were ignored.';
		}
		return $rows;
	}

	private static function delimiter( $line ) {
		$best = null;
		$max  = 0;
		foreach ( array( "\t", ',', ';', '|' ) as $d ) {
			$n = substr_count( $line, $d );
			if ( $n > $max ) {
				$max  = $n;
				$best = $d;
			}
		}
		return $best;
	}

	// ---- Names ----

	private static function flat( $k ) {
		return strtolower( preg_replace( '/[^A-Za-z0-9]/', '', (string) $k ) );
	}

	/**
	 * Map a field name from any of the layouts to PodPublisher's name for it (or '' if unknown).
	 */
	private static function key( $name ) {
		static $map = array(
			'start' => 'start', 'starttime' => 'start', 'startat' => 'start', 'begin' => 'start', 'time' => 'start',
			'duration' => 'duration', 'length' => 'duration', 'dur' => 'duration',
			'remotestart' => 'remote_start', 'remotestarttime' => 'remote_start', 'remoteoffset' => 'remote_start',
			'remotepercentage' => 'remote_percent', 'remotepercent' => 'remote_percent', 'percentage' => 'remote_percent', 'percent' => 'remote_percent', 'split' => 'remote_percent',
			'feedguid' => 'feed_guid', 'podcastguid' => 'feed_guid', 'showguid' => 'feed_guid',
			'itemguid' => 'item_guid', 'episodeguid' => 'item_guid',
			'feedurl' => 'feed_url', 'url' => 'feed_url',
		);
		$f = self::flat( $name );
		return isset( $map[ $f ] ) ? $map[ $f ] : '';
	}
}
