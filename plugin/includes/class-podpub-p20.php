<?php
/**
 * Podcasting 2.0 tags for a podcast (channel) and for an episode (item):
 * funding, location, person (cast), license, value (payments) with time splits,
 * block and podroll. Cleans and checks what the forms send, and writes the tags.
 *
 * Form values arrive as flat keys plus lists of rows, for example
 *   funding[0][url], funding[0][message], locations[0][name], recipients[0][address] ...
 *
 * @package PodPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PodPub_P20 {

	const MAX_FUNDING    = 10;
	const MAX_LOCATIONS  = 10;
	const MAX_TRANSCRIPTS = 10;
	const MAX_SOUNDBITES = 20;
	const MAX_ENCLOSURES = 10;
	const MAX_SOCIAL     = 5;
	const MAX_CHAPTERS   = 200;
	const MAX_TXT        = 10;
	const MAX_PERSONS    = 30;
	const MAX_RECIPIENTS = 20;
	const MAX_SPLITS     = 30;
	const MAX_BLOCK      = 10;
	const MAX_PODROLL    = 30;

	/**
	 * Empty values for every tag, merged into the podcast and episode defaults.
	 *
	 * @param bool $item True for an episode (adds time splits, drops block and podroll).
	 * @return array
	 */
	public static function defaults( $item ) {
		$d = array(
			'funding'  => array(),
			'locations' => array(),
			'persons'  => array(),
			'license'  => array( 'name' => '', 'url' => '' ),
			'value'    => array( 'method' => '', 'suggested' => '', 'recipients' => array() ),
			'social'   => array(),
		);
		if ( $item ) {
			$d['timesplits']   = array();
			$d['transcripts']  = array();
			$d['soundbites']   = array();
			$d['enclosures']   = array();
			$d['chapters']     = array();
			$d['chapters_url'] = '';
		} else {
			$d['block']        = array();
			$d['podroll']      = array();
			$d['complete']     = 0;
			$d['locked']       = '';
			$d['locked_owner'] = '';
			$d['txt']          = array();
		}
		return $d;
	}

	/* ---------- helpers ---------- */

	private static function scalar( $in, $key ) {
		return isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? trim( PodPub_Details::xml_clean( sanitize_text_field( (string) $in[ $key ] ) ) ) : '';
	}

	/**
	 * https address (the Podcasting 2.0 spec requires https for these), no spaces or quote characters, not too long.
	 */
	public static function valid_http( $u ) {
		if ( strlen( $u ) > 2000 || preg_match( '/[\s<>"\'\\\\]/', $u ) ) {
			return false;
		}
		$p = parse_url( $u );
		return $p && ! empty( $p['host'] ) && in_array( isset( $p['scheme'] ) ? strtolower( $p['scheme'] ) : '', array( 'https' ), true );
	}

	public static function valid_uuid( $u ) {
		return (bool) preg_match( '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $u );
	}

	/**
	 * Rows of a list from the form: blank rows are dropped, every value is
	 * cleaned. Keys are the row's number as shown in the form (1, 2, ...).
	 *
	 * @param array    $in     Form values.
	 * @param string   $key    List name.
	 * @param string[] $fields Field names.
	 * @param int      $max    Most rows allowed.
	 * @param string[] $errors Errors (added to).
	 * @param string   $label  Plural name for the error message.
	 * @return array
	 */
	private static function rows( $in, $key, $fields, $max, &$errors, $label ) {
		$list = isset( $in[ $key ] ) && is_array( $in[ $key ] ) ? array_values( $in[ $key ] ) : array();
		$out  = array();
		foreach ( $list as $i => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean = array();
			$any   = false;
			foreach ( $fields as $f ) {
				$clean[ $f ] = self::scalar( $row, $f );
				if ( '' !== $clean[ $f ] ) {
					$any = true;
				}
			}
			if ( $any ) {
				$out[ $i + 1 ] = $clean;
			}
		}
		if ( count( $out ) > $max ) {
			$errors[] = 'There are too many ' . $label . ' (the limit is ' . $max . ').';
			$out      = array_slice( $out, 0, $max, true );
		}
		return $out;
	}

	/* ---------- checking the form ---------- */

	public static function funding( $in, &$errors ) {
		$out = array();
		foreach ( self::rows( $in, 'funding', array( 'url', 'message' ), self::MAX_FUNDING, $errors, 'funding links' ) as $n => $r ) {
			$p = 'Funding link ' . $n . ': ';
			if ( ! self::valid_http( $r['url'] ) ) {
				$errors[] = $p . 'enter a web address starting with https://.';
			} elseif ( '' === $r['message'] || mb_strlen( $r['message'] ) > 128 ) {
				$errors[] = $p . 'enter a short message of up to 128 characters.';
			} else {
				$out[] = $r;
			}
		}
		return $out;
	}

	/**
	 * Locations (the Podcast namespace allows several): a name (required, up to 128
	 * characters) and the recommended rel (subject or creator), geo, osm and country.
	 */
	public static function locations( $in, &$errors ) {
		$out = array();
		foreach ( self::rows( $in, 'locations', array( 'name', 'rel', 'geo', 'osm', 'country' ), self::MAX_LOCATIONS, $errors, 'locations' ) as $n => $r ) {
			$p   = 'Location ' . $n . ': ';
			$geo = str_ireplace( array( 'geo:', ' ' ), '', $r['geo'] );
			$osm = strtoupper( $r['osm'] );
			$cc  = strtoupper( $r['country'] );
			$rel = strtolower( $r['rel'] );
			if ( '' === $r['name'] || mb_strlen( $r['name'] ) > 128 ) {
				$errors[] = $p . 'enter a place name of up to 128 characters.';
				continue;
			}
			if ( '' !== $rel && ! in_array( $rel, array( 'subject', 'creator' ), true ) ) {
				$errors[] = $p . 'choose subject or creator.';
				continue;
			}
			if ( '' !== $geo && ( ! preg_match( '/^(-?\d{1,2}(?:\.\d{1,6})?),(-?\d{1,3}(?:\.\d{1,6})?)(?:,(-?\d{1,5}(?:\.\d{1,3})?))?$/', $geo, $m ) || abs( (float) $m[1] ) > 90 || abs( (float) $m[2] ) > 180 ) ) {
				$errors[] = $p . 'the coordinates must be latitude,longitude such as -37.8136,144.9631 (latitude -90 to 90, longitude -180 to 180).';
				continue;
			}
			if ( '' !== $osm && ! preg_match( '/^[NWR][0-9]{1,12}$/', $osm ) ) {
				$errors[] = $p . 'the OpenStreetMap id must be N, W or R followed by digits, such as R113314.';
				continue;
			}
			if ( '' !== $cc && ! preg_match( '/^[A-Z]{2}$/', $cc ) ) {
				$errors[] = $p . 'the country must be a two-letter code such as AU, US or GB.';
				continue;
			}
			$out[] = array( 'name' => $r['name'], 'rel' => $rel, 'geo' => $geo, 'osm' => $osm, 'country' => $cc );
		}
		return $out;
	}

	public static function persons( $in, &$errors ) {
		$out = array();
		foreach ( self::rows( $in, 'persons', array( 'name', 'role', 'group', 'img', 'href' ), self::MAX_PERSONS, $errors, 'people' ) as $n => $r ) {
			$p = 'Person ' . $n . ': ';
			if ( '' === $r['name'] || mb_strlen( $r['name'] ) > 128 ) {
				$errors[] = $p . 'enter a name of up to 128 characters.';
				continue;
			}
			foreach ( array( 'role', 'group' ) as $f ) {
				$r[ $f ] = strtolower( $r[ $f ] );
				if ( '' !== $r[ $f ] && ! preg_match( '/^[a-z0-9][a-z0-9 _-]{0,39}$/', $r[ $f ] ) ) {
					$errors[] = $p . 'the ' . $f . ' may only use letters, numbers, spaces, - and _ (up to 40 characters).';
					continue 2;
				}
			}
			foreach ( array( 'img' => 'picture', 'href' => 'link' ) as $f => $label ) {
				if ( '' !== $r[ $f ] && ! self::valid_http( $r[ $f ] ) ) {
					$errors[] = $p . 'the ' . $label . ' must be a web address starting with https://.';
					continue 2;
				}
			}
			$out[] = $r;
		}
		return $out;
	}

	public static function license( $in, &$errors ) {
		$name = self::scalar( $in, 'license_name' );
		$url  = self::scalar( $in, 'license_url' );
		if ( '' === $name && '' === $url ) {
			return array( 'name' => '', 'url' => '' );
		}
		if ( '' === $name || mb_strlen( $name ) > 128 ) {
			$errors[] = 'License: enter the license name (for example cc-by-4.0), up to 128 characters.';
			return array( 'name' => '', 'url' => '' );
		}
		if ( '' !== $url && ! self::valid_http( $url ) ) {
			$errors[] = 'License: the web address must start with https://.';
			$url      = '';
		}
		return array( 'name' => $name, 'url' => $url );
	}

	/**
	 * The value block: suggested amount and who gets paid. Recipients are Lightning
	 * addresses (name@example.com); the feed always says method="lnaddress".
	 */
	public static function value( $in, &$errors ) {
		$empty     = array( 'method' => '', 'suggested' => '', 'recipients' => array() );
		$suggested = self::scalar( $in, 'value_suggested' );
		$rows      = self::rows( $in, 'recipients', array( 'name', 'address', 'split', 'fee' ), self::MAX_RECIPIENTS, $errors, 'payment recipients' );
		if ( ! $rows ) {
			if ( '' !== $suggested ) {
				$errors[] = 'Payments: add at least one recipient, or clear the suggested amount.';
			}
			return $empty;
		}
		if ( '' !== $suggested && ! preg_match( '/^\d{1,3}(?:\.\d{1,12})?$/', $suggested ) ) {
			$errors[] = 'Payments: the suggested amount must be a number such as 0.00000015000.';
			$suggested = '';
		}
		$out = array();
		foreach ( $rows as $n => $r ) {
			$p = 'Recipient ' . $n . ': ';
			if ( strlen( $r['address'] ) > 200 || ! preg_match( '/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $r['address'] ) ) {
				$errors[] = $p . 'a Lightning address looks like name@example.com.';
			} elseif ( mb_strlen( $r['name'] ) > 128 ) {
				$errors[] = $p . 'the name is too long (up to 128 characters).';
			} elseif ( ! preg_match( '/^\d{1,7}(?:\.\d{1,6})?$/', $r['split'] ) || (float) $r['split'] <= 0 ) {
				$errors[] = $p . 'the split must be a number above 0.';
			} else {
				$out[] = array( 'name' => $r['name'], 'type' => 'lnaddress', 'address' => $r['address'], 'split' => $r['split'], 'fee' => '' !== $r['fee'] );
			}
		}
		return array( 'method' => 'lnaddress', 'suggested' => $suggested, 'recipients' => $out );
	}

	/**
	 * Time splits (episodes): for part of the episode, send a share of the payment to another feed.
	 *
	 * @param array $in      Form values.
	 * @param array $value   The checked value block (splits need payments).
	 * @param array $errors  Errors (added to).
	 */
	public static function timesplits( $in, $value, &$errors ) {
		$out  = array();
		$rows = self::rows( $in, 'timesplits', array( 'start', 'duration', 'remote_start', 'remote_percent', 'feed_guid', 'item_guid', 'feed_url' ), self::MAX_SPLITS, $errors, 'time splits' );
		if ( $rows && empty( $value['recipients'] ) ) {
			$errors[] = 'Time splits need payments: add at least one payment recipient.';
			return $out;
		}
		foreach ( $rows as $n => $r ) {
			$p     = 'Time split ' . $n . ': ';
			$start = PodPub_Episodes::parse_duration( $r['start'] );
			$dur   = PodPub_Episodes::parse_duration( $r['duration'] );
			$rs    = PodPub_Episodes::parse_duration( $r['remote_start'] );
			if ( '' === $r['start'] || null === $start ) {
				$errors[] = $p . 'enter the start as seconds, minutes:seconds or hours:minutes:seconds.';
			} elseif ( ! $dur ) {
				$errors[] = $p . 'enter how long it lasts (more than 0 seconds).';
			} elseif ( null === $rs ) {
				$errors[] = $p . 'the start in the other episode must be a time, or empty.';
			} elseif ( '' !== $r['remote_percent'] && ( ! ctype_digit( $r['remote_percent'] ) || (int) $r['remote_percent'] > 100 ) ) {
				$errors[] = $p . 'the percentage must be a whole number from 0 to 100, or empty for 100.';
			} elseif ( ! self::valid_uuid( $r['feed_guid'] ) ) {
				$errors[] = $p . 'enter the other podcast\'s feed GUID (it looks like 917393e3-1b1e-5cef-ace4-edaa54e1f810).';
			} elseif ( mb_strlen( $r['item_guid'] ) > 200 ) {
				$errors[] = $p . 'the other episode\'s GUID is too long.';
			} elseif ( '' !== $r['feed_url'] && ! self::valid_http( $r['feed_url'] ) ) {
				$errors[] = $p . 'the other feed\'s address must start with https://.';
			} else {
				$out[] = array(
					'start'          => $start,
					'duration'       => $dur,
					'remote_start'   => $rs,
					'remote_percent' => '' === $r['remote_percent'] ? 100 : (int) $r['remote_percent'],
					'feed_guid'      => strtolower( $r['feed_guid'] ),
					'item_guid'      => $r['item_guid'],
					'feed_url'       => $r['feed_url'],
				);
			}
		}
		return $out;
	}

	public static function block( $in, &$errors ) {
		$out = array();
		foreach ( self::rows( $in, 'block', array( 'id', 'value' ), self::MAX_BLOCK, $errors, 'block rows' ) as $n => $r ) {
			$p = 'Block ' . $n . ': ';
			$r['id'] = strtolower( $r['id'] );
			if ( ! in_array( $r['value'], array( 'yes', 'no' ), true ) ) {
				$errors[] = $p . 'choose yes or no.';
			} elseif ( '' !== $r['id'] && ! preg_match( '/^[a-z0-9-]{1,40}$/', $r['id'] ) ) {
				$errors[] = $p . 'the service id may only use lower-case letters, numbers and -.';
			} else {
				$out[] = $r;
			}
		}
		return $out;
	}

	public static function podroll( $in, &$errors ) {
		$out = array();
		foreach ( self::rows( $in, 'podroll', array( 'feed_guid', 'feed_url', 'title' ), self::MAX_PODROLL, $errors, 'podroll entries' ) as $n => $r ) {
			$p = 'Podroll ' . $n . ': ';
			if ( ! self::valid_uuid( $r['feed_guid'] ) ) {
				$errors[] = $p . 'enter the feed GUID (it looks like 917393e3-1b1e-5cef-ace4-edaa54e1f810).';
			} elseif ( '' !== $r['feed_url'] && ! self::valid_http( $r['feed_url'] ) ) {
				$errors[] = $p . 'the feed address must start with https://.';
			} elseif ( mb_strlen( $r['title'] ) > 128 ) {
				$errors[] = $p . 'the title is too long (up to 128 characters).';
			} else {
				$r['feed_guid'] = strtolower( $r['feed_guid'] );
				$out[]          = $r;
			}
		}
		return $out;
	}

	/* ---------- chunk D tags ---------- */

	public static function transcript_types() {
		return array(
			'text/plain'            => 'Plain text (.txt)',
			'text/html'             => 'Web page (.html)',
			'text/vtt'              => 'WebVTT (.vtt)',
			'application/x-subrip'  => 'SubRip (.srt)',
			'application/json'      => 'JSON (.json)',
		);
	}

	/** Seconds as a number with up to three decimals ("73", "73.5"), or null. */
	private static function seconds( $t ) {
		return preg_match( '/^\d{1,6}(?:\.\d{1,3})?$/', $t ) ? $t : null;
	}

	public static function transcripts( $in, &$errors ) {
		$out = array();
		foreach ( self::rows( $in, 'transcripts', array( 'url', 'type', 'language', 'rel' ), self::MAX_TRANSCRIPTS, $errors, 'transcripts' ) as $n => $r ) {
			$p = 'Transcript ' . $n . ': ';
			if ( ! self::valid_http( $r['url'] ) ) {
				$errors[] = $p . 'enter a web address starting with https://.';
			} elseif ( ! isset( self::transcript_types()[ $r['type'] ] ) ) {
				$errors[] = $p . 'choose the file type.';
			} elseif ( '' !== $r['language'] && ! preg_match( '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8}){0,3}$/', $r['language'] ) ) {
				$errors[] = $p . 'the language must be a code such as en or pt-BR.';
			} elseif ( '' !== $r['rel'] && 'captions' !== strtolower( $r['rel'] ) ) {
				$errors[] = $p . 'the only value for "rel" is captions (leave it empty otherwise).';
			} else {
				$r['rel'] = strtolower( $r['rel'] );
				$out[]    = $r;
			}
		}
		return $out;
	}

	public static function soundbites( $in, &$errors ) {
		$out = array();
		foreach ( self::rows( $in, 'soundbites', array( 'start', 'duration', 'title' ), self::MAX_SOUNDBITES, $errors, 'soundbites' ) as $n => $r ) {
			$p     = 'Soundbite ' . $n . ': ';
			$start = self::seconds( $r['start'] );
			$dur   = self::seconds( $r['duration'] );
			if ( null === $start || null === $dur || (float) $dur <= 0 ) {
				$errors[] = $p . 'enter the start and the length in seconds (for example 73 and 60).';
			} elseif ( mb_strlen( $r['title'] ) > 128 ) {
				$errors[] = $p . 'the title must be 128 characters or fewer.';
			} else {
				$out[] = array( 'start' => $start, 'duration' => $dur, 'title' => $r['title'] );
			}
		}
		return $out;
	}

	public static function enclosures( $in, &$errors ) {
		$out = array();
		foreach ( self::rows( $in, 'enclosures', array( 'uri', 'type', 'length', 'bitrate', 'height', 'lang', 'title', 'rel', 'codecs', 'default' ), self::MAX_ENCLOSURES, $errors, 'alternate files' ) as $n => $r ) {
			$p = 'Alternate file ' . $n . ': ';
			if ( ! self::valid_http( $r['uri'] ) ) {
				$errors[] = $p . 'enter a web address starting with https://.';
			} elseif ( ! preg_match( '#^[a-z]+/[a-z0-9][a-z0-9.+_-]*$#i', $r['type'] ) ) {
				$errors[] = $p . 'enter the file type, such as video/mp4 or audio/mpeg.';
			} elseif ( ! preg_match( '/^\d*$/', $r['length'] ) || ! preg_match( '/^\d*$/', $r['bitrate'] ) || ! preg_match( '/^\d*$/', $r['height'] ) ) {
				$errors[] = $p . 'size, bitrate and height are whole numbers.';
			} elseif ( '' !== $r['lang'] && ! preg_match( '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8}){0,3}$/', $r['lang'] ) ) {
				$errors[] = $p . 'the language must be a code such as en or pt-BR.';
			} elseif ( mb_strlen( $r['title'] ) > 128 || mb_strlen( $r['rel'] ) > 64 || mb_strlen( $r['codecs'] ) > 128 ) {
				$errors[] = $p . 'the title, group or codecs text is too long.';
			} else {
				$r['default'] = '' === $r['default'] ? '' : '1';
				$out[]        = $r;
			}
		}
		return $out;
	}

	/**
	 * Cross-app comments. "disabled" carries nothing else.
	 */
	public static function social( $in, &$errors ) {
		$out = array();
		foreach ( self::rows( $in, 'social', array( 'protocol', 'uri', 'account_id', 'account_url', 'priority' ), self::MAX_SOCIAL, $errors, 'comment links' ) as $n => $r ) {
			$p        = 'Comments ' . $n . ': ';
			$protocol = strtolower( $r['protocol'] );
			if ( ! preg_match( '/^[a-z][a-z0-9_-]{1,29}$/', $protocol ) ) {
				$errors[] = $p . 'enter the protocol, such as activitypub, or "disabled".';
			} elseif ( 'disabled' === $protocol ) {
				$out[] = array( 'protocol' => 'disabled', 'uri' => '', 'account_id' => '', 'account_url' => '', 'priority' => '' );
			} elseif ( '' === $r['uri'] || strlen( $r['uri'] ) > 2000 || preg_match( '/[\s<>"\'\\\\]/', $r['uri'] ) ) {
				$errors[] = $p . 'enter the address of the post that holds the comments.';
			} elseif ( '' !== $r['account_url'] && ! self::valid_http( $r['account_url'] ) ) {
				$errors[] = $p . 'the account address must start with https://.';
			} elseif ( '' !== $r['priority'] && ! preg_match( '/^\d{1,4}$/', $r['priority'] ) ) {
				$errors[] = $p . 'the priority is a whole number (1 is first).';
			} else {
				$out[] = array( 'protocol' => $protocol, 'uri' => $r['uri'], 'account_id' => $r['account_id'], 'account_url' => $r['account_url'], 'priority' => $r['priority'] );
			}
		}
		return $out;
	}

	/**
	 * Chapters typed into the editor (kept sorted by start time), or one address of
	 * a chapters file hosted elsewhere. Not both.
	 *
	 * @return array { rows: array, url: string }
	 */
	public static function chapters( $in, &$errors ) {
		$rows = array();
		foreach ( self::rows( $in, 'chapters', array( 'start', 'title', 'img', 'url' ), self::MAX_CHAPTERS, $errors, 'chapters' ) as $n => $r ) {
			$p     = 'Chapter ' . $n . ': ';
			$start = PodPub_Episodes::parse_duration( $r['start'] );
			if ( null === $start || '' === $r['start'] ) {
				$errors[] = $p . 'enter the start time as seconds, m:ss or h:mm:ss.';
			} elseif ( '' === $r['title'] || mb_strlen( $r['title'] ) > 255 ) {
				$errors[] = $p . 'enter a title of up to 255 characters.';
			} elseif ( '' !== $r['img'] && ! self::valid_http( $r['img'] ) ) {
				$errors[] = $p . 'the picture address must start with https://.';
			} elseif ( '' !== $r['url'] && ! self::valid_http( $r['url'] ) ) {
				$errors[] = $p . 'the link must start with https://.';
			} else {
				$rows[] = array( 'start' => $start, 'title' => $r['title'], 'img' => $r['img'], 'url' => $r['url'] );
			}
		}
		usort( $rows, function ( $a, $b ) {
			return $a['start'] - $b['start'];
		} );
		$url = self::scalar( $in, 'chapters_url' );
		if ( '' !== $url && ! self::valid_http( $url ) ) {
			$errors[] = 'Chapters: the web address must start with https://.';
			$url      = '';
		}
		if ( $rows && '' !== $url ) {
			$errors[] = 'Chapters: use either the chapter list or the web address of a chapters file hosted elsewhere, not both.';
		}
		return array( 'rows' => $rows, 'url' => $url );
	}

	/** The chapters file (JSON chapters 1.2.0). */
	public static function chapters_json( $rows ) {
		$list = array();
		foreach ( $rows as $r ) {
			$c = array( 'startTime' => (int) $r['start'], 'title' => $r['title'] );
			if ( '' !== $r['img'] ) {
				$c['img'] = $r['img'];
			}
			if ( '' !== $r['url'] ) {
				$c['url'] = $r['url'];
			}
			$list[] = $c;
		}
		return json_encode( array( 'version' => '1.2.0', 'chapters' => $list ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n";
	}

	/** Channel: complete, locked (with owner email), txt. */
	public static function channel_flags( $in, $cur, &$errors ) {
		$cur['complete'] = empty( $in['complete'] ) ? 0 : 1;
		$locked          = strtolower( self::scalar( $in, 'locked' ) );
		if ( ! in_array( $locked, array( '', 'yes', 'no' ), true ) ) {
			$errors[] = 'Locked: choose yes, no or leave it unset.';
			$locked   = '';
		}
		$owner = self::scalar( $in, 'locked_owner' );
		if ( '' !== $owner && ! is_email( $owner ) ) {
			$errors[] = 'Locked: the owner email address does not look right.';
			$owner    = '';
		}
		$cur['locked']       = $locked;
		$cur['locked_owner'] = $owner;
		$txt                 = array();
		foreach ( self::rows( $in, 'txt', array( 'purpose', 'text' ), self::MAX_TXT, $errors, 'text records' ) as $n => $r ) {
			if ( '' === $r['text'] || mb_strlen( $r['text'] ) > 4000 || mb_strlen( $r['purpose'] ) > 128 ) {
				$errors[] = 'Text record ' . $n . ': enter the text (up to 4000 characters); the purpose is up to 128.';
			} else {
				$txt[] = $r;
			}
		}
		$cur['txt'] = $txt;
		return $cur;
	}

	/**
	 * Check the podcast-level tags.
	 *
	 * @param array    $in     Form values.
	 * @param array    $cur    Current details (keys other than these tags are kept).
	 * @param string[] $errors Errors (added to).
	 * @return array Details with the tags replaced.
	 */
	public static function sanitize_channel( $in, $cur, &$errors ) {
		$in                = is_array( $in ) ? $in : array();
		$cur['funding']    = self::funding( $in, $errors );
		$cur['locations']  = self::locations( $in, $errors );
		$cur['persons']    = self::persons( $in, $errors );
		$cur['license']    = self::license( $in, $errors );
		$cur['value']      = self::value( $in, $errors );
		$cur['block']      = self::block( $in, $errors );
		$cur['podroll']    = self::podroll( $in, $errors );
		$cur['social']     = self::social( $in, $errors );
		$cur                = self::channel_flags( $in, $cur, $errors );
		return $cur;
	}

	/**
	 * Check the episode-level tags.
	 */
	public static function sanitize_item( $in, $cur, &$errors ) {
		$in                = is_array( $in ) ? $in : array();
		$cur['funding']    = self::funding( $in, $errors );
		$cur['locations']  = self::locations( $in, $errors );
		$cur['persons']    = self::persons( $in, $errors );
		$cur['license']    = self::license( $in, $errors );
		$cur['value']      = self::value( $in, $errors );
		$cur['timesplits'] = self::timesplits( $in, $cur['value'], $errors );
		$cur['social']     = self::social( $in, $errors );
		$cur['transcripts'] = self::transcripts( $in, $errors );
		$cur['soundbites'] = self::soundbites( $in, $errors );
		$cur['enclosures'] = self::enclosures( $in, $errors );
		$ch                = self::chapters( $in, $errors );
		$cur['chapters']   = $ch['rows'];
		$cur['chapters_url'] = $ch['url'];
		return $cur;
	}

	/* ---------- writing the tags ---------- */

	private static function el( $w, $name, $attrs, $text = null ) {
		$w->startElement( $name );
		foreach ( $attrs as $k => $v ) {
			if ( '' !== (string) $v ) {
				$w->writeAttribute( $k, (string) $v );
			}
		}
		if ( null !== $text ) {
			$w->text( $text );
		}
		$w->endElement();
	}

	private static function write_value( $w, $v, $splits ) {
		// Only Lightning-address recipients are written (an older keysend recipient is left out).
		$recipients = array_values( array_filter( $v['recipients'], function ( $r ) {
			return isset( $r['type'] ) && 'lnaddress' === $r['type'];
		} ) );
		if ( ! $recipients ) {
			return;
		}
		$w->startElement( 'podcast:value' );
		$w->writeAttribute( 'type', 'lightning' );
		$w->writeAttribute( 'method', 'lnaddress' );
		if ( '' !== $v['suggested'] ) {
			$w->writeAttribute( 'suggested', $v['suggested'] );
		}
		foreach ( $recipients as $r ) {
			self::el(
				$w,
				'podcast:valueRecipient',
				array(
					'name'    => $r['name'],
					'type'    => 'lnaddress',
					'address' => $r['address'],
					'split'   => $r['split'],
					'fee'     => ! empty( $r['fee'] ) ? 'true' : '',
				)
			);
		}
		foreach ( $splits as $s ) {
			$w->startElement( 'podcast:valueTimeSplit' );
			$w->writeAttribute( 'startTime', (string) (int) $s['start'] );
			$w->writeAttribute( 'duration', (string) (int) $s['duration'] );
			if ( (int) $s['remote_start'] > 0 ) {
				$w->writeAttribute( 'remoteStartTime', (string) (int) $s['remote_start'] );
			}
			if ( (int) $s['remote_percent'] < 100 ) {
				$w->writeAttribute( 'remotePercentage', (string) (int) $s['remote_percent'] );
			}
			self::el( $w, 'podcast:remoteItem', array( 'feedGuid' => $s['feed_guid'], 'feedUrl' => $s['feed_url'], 'itemGuid' => $s['item_guid'] ) );
			$w->endElement();
		}
		$w->endElement();
	}

	private static function write_common( $w, $d, $splits ) {
		foreach ( $d['funding'] as $f ) {
			self::el( $w, 'podcast:funding', array( 'url' => $f['url'] ), $f['message'] );
		}
		foreach ( $d['locations'] as $l ) {
			self::el( $w, 'podcast:location', array( 'rel' => '' === $l['rel'] ? 'subject' : $l['rel'], 'geo' => '' === $l['geo'] ? '' : 'geo:' . $l['geo'], 'osm' => $l['osm'], 'country' => $l['country'] ), $l['name'] );
		}
		foreach ( $d['persons'] as $p ) {
			self::el( $w, 'podcast:person', array( 'role' => $p['role'], 'group' => $p['group'], 'img' => $p['img'], 'href' => $p['href'] ), $p['name'] );
		}
		if ( '' !== $d['license']['name'] ) {
			self::el( $w, 'podcast:license', array( 'url' => $d['license']['url'] ), $d['license']['name'] );
		}
		self::write_value( $w, $d['value'], $splits );
		foreach ( $d['social'] as $c ) {
			if ( 'disabled' === $c['protocol'] ) {
				self::el( $w, 'podcast:socialInteract', array( 'protocol' => 'disabled' ) );
			} else {
				self::el( $w, 'podcast:socialInteract', array( 'protocol' => $c['protocol'], 'uri' => $c['uri'], 'accountId' => $c['account_id'], 'accountUrl' => $c['account_url'], 'priority' => $c['priority'] ) );
			}
		}
	}

	/**
	 * Channel-level tags (inside <channel>).
	 */
	public static function write_channel( $w, $d ) {
		if ( ! empty( $d['complete'] ) ) {
			self::el( $w, 'podcast:complete', array(), 'yes' );
		}
		if ( '' !== $d['locked'] ) {
			self::el( $w, 'podcast:locked', array( 'owner' => $d['locked_owner'] ), $d['locked'] );
		}
		foreach ( $d['txt'] as $t ) {
			self::el( $w, 'podcast:txt', array( 'purpose' => $t['purpose'] ), $t['text'] );
		}
		self::write_common( $w, $d, array() );
		foreach ( $d['block'] as $b ) {
			self::el( $w, 'podcast:block', array( 'id' => $b['id'] ), $b['value'] );
		}
		if ( $d['podroll'] ) {
			$w->startElement( 'podcast:podroll' );
			foreach ( $d['podroll'] as $r ) {
				self::el( $w, 'podcast:remoteItem', array( 'feedGuid' => $r['feed_guid'], 'feedUrl' => $r['feed_url'], 'title' => $r['title'] ) );
			}
			$w->endElement();
		}
	}

	/**
	 * Episode-level tags (inside <item>).
	 */
	public static function write_item( $w, $e ) {
		self::write_common( $w, $e, isset( $e['timesplits'] ) ? $e['timesplits'] : array() );
		if ( ! empty( $e['chapters_href'] ) ) {
			self::el( $w, 'podcast:chapters', array( 'url' => $e['chapters_href'], 'type' => 'application/json+chapters' ) );
		}
		foreach ( $e['transcripts'] as $t ) {
			self::el( $w, 'podcast:transcript', array( 'url' => $t['url'], 'type' => $t['type'], 'language' => $t['language'], 'rel' => $t['rel'] ) );
		}
		foreach ( $e['soundbites'] as $b ) {
			self::el( $w, 'podcast:soundbite', array( 'startTime' => $b['start'], 'duration' => $b['duration'] ), '' === $b['title'] ? null : $b['title'] );
		}
		foreach ( $e['enclosures'] as $a ) {
			$w->startElement( 'podcast:alternateEnclosure' );
			foreach ( array( 'type' => 'type', 'length' => 'length', 'bitrate' => 'bitrate', 'height' => 'height', 'lang' => 'lang', 'title' => 'title', 'rel' => 'rel', 'codecs' => 'codecs' ) as $attr => $k ) {
				if ( '' !== (string) $a[ $k ] ) {
					$w->writeAttribute( $attr, (string) $a[ $k ] );
				}
			}
			if ( ! empty( $a['default'] ) ) {
				$w->writeAttribute( 'default', 'true' );
			}
			self::el( $w, 'podcast:source', array( 'uri' => $a['uri'] ) );
			$w->endElement();
		}
	}
}
