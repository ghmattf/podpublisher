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
		);
		if ( $item ) {
			$d['timesplits'] = array();
		} else {
			$d['block']   = array();
			$d['podroll'] = array();
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
	 * The value block: payment method, suggested amount and who gets paid.
	 */
	public static function value( $in, &$errors ) {
		$empty     = array( 'method' => '', 'suggested' => '', 'recipients' => array() );
		$method    = self::scalar( $in, 'value_method' );
		$suggested = self::scalar( $in, 'value_suggested' );
		$rows      = self::rows( $in, 'recipients', array( 'name', 'type', 'address', 'custom_key', 'custom_value', 'split', 'fee' ), self::MAX_RECIPIENTS, $errors, 'payment recipients' );
		if ( ! $rows ) {
			if ( '' !== $suggested ) {
				$errors[] = 'Payments: add at least one recipient, or clear the suggested amount.';
			}
			return $empty;
		}
		if ( ! in_array( $method, array( 'keysend', 'lnaddress' ), true ) ) {
			$errors[] = 'Payments: choose the payment method.';
			return $empty;
		}
		if ( '' !== $suggested && ! preg_match( '/^\d{1,3}(?:\.\d{1,12})?$/', $suggested ) ) {
			$errors[] = 'Payments: the suggested amount must be a number such as 0.00000015000.';
			$suggested = '';
		}
		$out = array();
		foreach ( $rows as $n => $r ) {
			$p = 'Recipient ' . $n . ': ';
			if ( ! in_array( $r['type'], array( 'node', 'lnaddress' ), true ) ) {
				$errors[] = $p . 'choose the type.';
			} elseif ( 'node' === $r['type'] && ! preg_match( '/^[0-9a-fA-F]{66}$/', $r['address'] ) ) {
				$errors[] = $p . 'a node address is the 66-character public key of the Lightning node.';
			} elseif ( 'lnaddress' === $r['type'] && ( strlen( $r['address'] ) > 200 || ! preg_match( '/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $r['address'] ) ) ) {
				$errors[] = $p . 'a Lightning address looks like name@example.com.';
			} elseif ( mb_strlen( $r['name'] ) > 128 ) {
				$errors[] = $p . 'the name is too long (up to 128 characters).';
			} elseif ( '' !== $r['custom_key'] && ! preg_match( '/^\d{1,20}$/', $r['custom_key'] ) ) {
				$errors[] = $p . 'the custom key must be a number.';
			} elseif ( '' !== $r['custom_value'] && ( '' === $r['custom_key'] || mb_strlen( $r['custom_value'] ) > 200 ) ) {
				$errors[] = $p . 'a custom value needs a custom key, and is up to 200 characters.';
			} elseif ( ! preg_match( '/^\d{1,7}(?:\.\d{1,6})?$/', $r['split'] ) || (float) $r['split'] <= 0 ) {
				$errors[] = $p . 'the split must be a number above 0.';
			} else {
				$r['fee'] = '' !== $r['fee'];
				$out[]    = $r;
			}
		}
		return array( 'method' => $method, 'suggested' => $suggested, 'recipients' => $out );
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
		if ( empty( $v['recipients'] ) ) {
			return;
		}
		$w->startElement( 'podcast:value' );
		$w->writeAttribute( 'type', 'lightning' );
		$w->writeAttribute( 'method', $v['method'] );
		if ( '' !== $v['suggested'] ) {
			$w->writeAttribute( 'suggested', $v['suggested'] );
		}
		foreach ( $v['recipients'] as $r ) {
			self::el(
				$w,
				'podcast:valueRecipient',
				array(
					'name'        => $r['name'],
					'type'        => $r['type'],
					'address'     => $r['address'],
					'customKey'   => $r['custom_key'],
					'customValue' => $r['custom_value'],
					'split'       => $r['split'],
					'fee'         => ! empty( $r['fee'] ) ? 'true' : '',
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
	}

	/**
	 * Channel-level tags (inside <channel>).
	 */
	public static function write_channel( $w, $d ) {
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
	}
}
