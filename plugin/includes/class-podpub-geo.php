<?php
/**
 * Location search: finds a place by name (OpenStreetMap's Nominatim, or a compatible
 * service) and turns the answer into the fields of a podcast:location row.
 *
 * Search is made by the WordPress server, never the browser, one request per click
 * (no search-as-you-type), at most one request per second for the whole site, results
 * kept for 30 days, an identifying User-Agent, and the address comes from Settings.
 * Any failure gives one plain sentence and never blocks saving a form.
 *
 * @package PodPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PodPub_Geo {

	const DEFAULT_URL = 'https://nominatim.openstreetmap.org';
	const MIN_CHARS   = 3;
	const MAX_CHARS   = 120;
	const MAX_RESULTS = 5;
	const CACHE_DAYS  = 30;
	const GAP_SECONDS = 1.0;
	const LOCK_KEY    = 'podpub_geo_last';

	/**
	 * Is search switched on? (Settings, default on.)
	 *
	 * @return bool
	 */
	public static function enabled() {
		$v = PodPub_Settings::get( 'geo_enabled' );
		return null === $v || ! empty( $v );
	}

	/**
	 * Address of the search service, without a trailing slash. Empty setting = the public Nominatim.
	 *
	 * @return string
	 */
	public static function base_url() {
		$u = rtrim( trim( (string) PodPub_Settings::get( 'geo_url' ) ), '/' );
		return '' === $u ? self::DEFAULT_URL : $u;
	}

	/**
	 * Is this an acceptable service address? https only, no spaces, quotes, query or fragment.
	 *
	 * @param string $url Address.
	 * @return bool
	 */
	public static function valid_url( $url ) {
		return (bool) preg_match( '#^https://[A-Za-z0-9.-]+(?::[0-9]{1,5})?(?:/[A-Za-z0-9._~/-]*)?$#', $url );
	}

	/**
	 * Search for a place.
	 *
	 * @param string $q What the person typed.
	 * @return array { ok: bool, message: string, results: array[], cached: bool }
	 */
	public static function search( $q ) {
		$out = array( 'ok' => false, 'message' => '', 'results' => array(), 'cached' => false );
		if ( ! self::enabled() ) {
			$out['message'] = 'Location search is switched off in PodPublisher settings. Fill in the fields by hand.';
			return $out;
		}
		$q = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $q ) ) );
		$n = mb_strlen( $q );
		if ( $n < self::MIN_CHARS || $n > self::MAX_CHARS ) {
			$out['message'] = 'Type between ' . self::MIN_CHARS . ' and ' . self::MAX_CHARS . ' characters to search for.';
			return $out;
		}

		$base = self::base_url();
		$key  = 'podpub_geo_' . md5( $base . '|' . mb_strtolower( $q ) );
		$hit  = get_transient( $key );
		if ( is_array( $hit ) ) {
			$out['ok']      = true;
			$out['cached']  = true;
			$out['results'] = $hit;
			$out['message'] = $hit ? '' : 'No places were found. Try a different or shorter name.';
			return $out;
		}

		// One request per second for the whole site (the public service's rule).
		$last = get_transient( self::LOCK_KEY );
		$now  = microtime( true );
		if ( is_numeric( $last ) && ( $now - (float) $last ) < self::GAP_SECONDS ) {
			$out['message'] = 'Another search was just made. Wait a second and try again.';
			return $out;
		}
		set_transient( self::LOCK_KEY, (string) $now, 60 );

		if ( ! self::valid_url( $base ) ) {
			$out['message'] = 'The location search address in PodPublisher settings is not valid. Fill in the fields by hand.';
			return $out;
		}
		$url = $base . '/search?' . http_build_query( array( 'q' => $q, 'format' => 'jsonv2', 'addressdetails' => 1, 'limit' => self::MAX_RESULTS ) );
		$r   = wp_safe_remote_get( $url, array(
			'timeout'     => 8,
			'redirection' => 2,
			'user-agent'  => 'PodPublisher/' . ( defined( 'PODPUB_VERSION' ) ? PODPUB_VERSION : '0' ) . ' (' . home_url() . ')',
			'headers'     => array( 'Accept' => 'application/json' ),
		) );
		if ( is_wp_error( $r ) ) {
			$out['message'] = 'The place search could not be reached. Fill in the fields by hand, or try again later.';
			return $out;
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( 429 === $code || 403 === $code ) {
			$out['message'] = 'The place search service is refusing requests for now (too many). Try again later or fill in the fields by hand.';
			return $out;
		}
		if ( $code < 200 || $code >= 300 ) {
			$out['message'] = 'The place search failed (error ' . $code . '). Fill in the fields by hand, or try again later.';
			return $out;
		}
		$list = self::parse( (string) wp_remote_retrieve_body( $r ) );
		if ( null === $list ) {
			$out['message'] = 'The place search gave an answer that could not be read. Fill in the fields by hand.';
			return $out;
		}
		set_transient( $key, $list, self::CACHE_DAYS * DAY_IN_SECONDS );
		$out['ok']      = true;
		$out['results'] = $list;
		$out['message'] = $list ? '' : 'No places were found. Try a different or shorter name.';
		return $out;
	}

	/**
	 * Turn Nominatim's JSON (jsonv2 with addressdetails) into location rows.
	 * Anything that does not look right is skipped; the text is only ever used as text.
	 *
	 * @param string $json Response body.
	 * @return array[]|null Rows { name, geo, osm, country, label } or null when it is not a list.
	 */
	public static function parse( $json ) {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || ( $data && array_keys( $data ) !== range( 0, count( $data ) - 1 ) ) ) {
			return null;
		}
		$out = array();
		foreach ( array_slice( $data, 0, self::MAX_RESULTS ) as $p ) {
			if ( ! is_array( $p ) || ! isset( $p['lat'], $p['lon'] ) || ! is_numeric( $p['lat'] ) || ! is_numeric( $p['lon'] ) ) {
				continue;
			}
			$lat = (float) $p['lat'];
			$lon = (float) $p['lon'];
			if ( abs( $lat ) > 90 || abs( $lon ) > 180 ) {
				continue;
			}
			$osm = '';
			if ( isset( $p['osm_type'], $p['osm_id'] ) && is_string( $p['osm_type'] ) && ( is_int( $p['osm_id'] ) || ( is_string( $p['osm_id'] ) && ctype_digit( $p['osm_id'] ) ) ) && (int) $p['osm_id'] > 0 && strlen( (string) $p['osm_id'] ) <= 12 ) {
				$t = array( 'node' => 'N', 'way' => 'W', 'relation' => 'R' );
				if ( isset( $t[ $p['osm_type'] ] ) ) {
					$osm = $t[ $p['osm_type'] ] . (int) $p['osm_id'];
				}
			}
			$addr = isset( $p['address'] ) && is_array( $p['address'] ) ? $p['address'] : array();
			$cc   = isset( $addr['country_code'] ) && is_string( $addr['country_code'] ) && preg_match( '/^[A-Za-z]{2}$/', $addr['country_code'] ) ? strtoupper( $addr['country_code'] ) : '';
			$full = isset( $p['display_name'] ) && is_string( $p['display_name'] ) ? self::clean( $p['display_name'] ) : '';
			$name = self::short_name( $p, $addr, $full );
			if ( '' === $name ) {
				continue;
			}
			$out[] = array(
				'name'    => $name,
				'geo'     => rtrim( rtrim( number_format( $lat, 6, '.', '' ), '0' ), '.' ) . ',' . rtrim( rtrim( number_format( $lon, 6, '.', '' ), '0' ), '.' ),
				'osm'     => $osm,
				'country' => $cc,
				'label'   => '' === $full ? $name : $full,
			);
		}
		return $out;
	}

	/**
	 * Place, region and country (the owner's default), no repeats, up to 128 characters.
	 */
	private static function short_name( $p, $addr, $full ) {
		$first = isset( $p['name'] ) && is_string( $p['name'] ) ? self::clean( $p['name'] ) : '';
		if ( '' === $first && '' !== $full ) {
			$first = trim( explode( ',', $full )[0] );
		}
		$region  = '';
		foreach ( array( 'state', 'region', 'province', 'state_district', 'county' ) as $k ) {
			if ( isset( $addr[ $k ] ) && is_string( $addr[ $k ] ) && '' !== trim( $addr[ $k ] ) ) {
				$region = self::clean( $addr[ $k ] );
				break;
			}
		}
		$country = isset( $addr['country'] ) && is_string( $addr['country'] ) ? self::clean( $addr['country'] ) : '';
		$parts   = array();
		foreach ( array( $first, $region, $country ) as $x ) {
			if ( '' !== $x && ! in_array( mb_strtolower( $x ), array_map( 'mb_strtolower', $parts ), true ) ) {
				$parts[] = $x;
			}
		}
		$name = implode( ', ', $parts );
		return mb_strlen( $name ) > 128 ? mb_substr( $name, 0, 127 ) . '…' : $name;
	}

	private static function clean( $s ) {
		return trim( preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', wp_strip_all_tags( (string) $s ) ) );
	}
}
