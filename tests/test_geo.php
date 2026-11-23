<?php
// Location search (0.11.0): parsing the geocoder's answer, caching, the one-per-second lock,
// bad input, the settings, and the OpenStreetMap address in the OSM id field.
// Not a real WordPress: the few WordPress functions used are stood in for, and the HTTP answer is stubbed.
define( 'ABSPATH', __DIR__ );
define( 'PODPUB_VERSION', '0.11.0' );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['opts']  = array();
$GLOBALS['trans'] = array();
$GLOBALS['http']  = array(); // Calls made: url + args.
$GLOBALS['reply'] = null;    // What the stub returns.

function get_option( $k, $d = false ) { return isset( $GLOBALS['opts'][ $k ] ) ? $GLOBALS['opts'][ $k ] : $d; }
function get_transient( $k ) { return isset( $GLOBALS['trans'][ $k ] ) ? $GLOBALS['trans'][ $k ] : false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['trans'][ $k ] = $v; return true; }
function home_url() { return 'https://example.com'; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
class WP_Error { public $m; function __construct( $c, $m ) { $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_safe_remote_get( $url, $args = array() ) { $GLOBALS['http'][] = array( $url, $args ); return $GLOBALS['reply']; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function reply( $code, $body ) { $GLOBALS['reply'] = array( 'code' => $code, 'body' => $body ); }

foreach ( array( 'settings', 'details', 'p20', 'geo' ) as $c ) { require __DIR__ . "/../plugin/includes/class-podpub-$c.php"; }

$fail = 0;
function check( $name, $ok ) { global $fail; echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n"; if ( ! $ok ) { $fail = 1; } }
function fresh() { $GLOBALS['trans'] = array(); $GLOBALS['http'] = array(); }

$melb = json_encode( array(
	array( 'osm_type' => 'relation', 'osm_id' => 4246124, 'lat' => '-37.8142176', 'lon' => '144.9631608', 'name' => 'Melbourne', 'display_name' => 'Melbourne, City of Melbourne, Victoria, Australia', 'address' => array( 'city' => 'Melbourne', 'state' => 'Victoria', 'country' => 'Australia', 'country_code' => 'au' ) ),
	array( 'osm_type' => 'node', 'osm_id' => '123', 'lat' => '28.0836', 'lon' => '-80.6081', 'name' => 'Melbourne', 'display_name' => 'Melbourne, Brevard County, Florida, United States', 'address' => array( 'state' => 'Florida', 'country' => 'United States', 'country_code' => 'us' ) ),
) );

// ---- Parsing ----
$p = PodPub_Geo::parse( $melb );
check( 'parse: two places', is_array( $p ) && count( $p ) === 2 );
check( 'parse: name is place, region, country', $p[0]['name'] === 'Melbourne, Victoria, Australia' && $p[1]['name'] === 'Melbourne, Florida, United States' );
check( 'parse: coordinates to 6 decimals, trailing zeros trimmed', $p[0]['geo'] === '-37.814218,144.963161' && $p[1]['geo'] === '28.0836,-80.6081' );
check( 'parse: OSM id is N, W or R plus the number', $p[0]['osm'] === 'R4246124' && $p[1]['osm'] === 'N123' );
check( 'parse: country in capitals', $p[0]['country'] === 'AU' && $p[1]['country'] === 'US' );
check( 'parse: label is the full display name', $p[0]['label'] === 'Melbourne, City of Melbourne, Victoria, Australia' );
check( 'parse: every geo value passes the plugin\'s own location check', ( function () use ( $p ) { $e = array(); $in = array( 'locations' => array_map( function ( $r ) { return array_intersect_key( $r, array_flip( array( 'name', 'geo', 'osm', 'country' ) ) ); }, $p ) ); $o = PodPub_P20::locations( $in, $e ); return $e === array() && count( $o ) === 2; } )() );
check( 'parse: an answer that is not a list is refused', PodPub_Geo::parse( '{"error":"x"}' ) === null && PodPub_Geo::parse( 'nope' ) === null );
check( 'parse: an empty list is fine', PodPub_Geo::parse( '[]' ) === array() );
$bad = json_encode( array(
	array( 'lat' => '95', 'lon' => '0', 'name' => 'Too far north' ),
	array( 'lat' => 'x', 'lon' => '0', 'name' => 'Not a number' ),
	array( 'lat' => '1', 'name' => 'No lon' ),
	array( 'lat' => '1', 'lon' => '181', 'name' => 'Too far east' ),
	array( 'lat' => '10', 'lon' => '20', 'name' => 'Odd <b>ids</b>', 'osm_type' => 'planet', 'osm_id' => 'abc', 'address' => array( 'country_code' => 'AUS', 'country' => 'Nowhere' ) ),
	'string',
	array( 'lat' => '10', 'lon' => '20', 'name' => '', 'display_name' => '' ),
) );
$b = PodPub_Geo::parse( $bad );
check( 'parse: impossible or incomplete places are skipped; odd id and country are blanked, tags stripped', count( $b ) === 1 && $b[0]['name'] === 'Odd ids, Nowhere' && $b[0]['osm'] === '' && $b[0]['country'] === '' );
$long = json_encode( array( array( 'lat' => '1', 'lon' => '2', 'name' => str_repeat( 'ab', 100 ), 'address' => array() ) ) );
$l = PodPub_Geo::parse( $long );
check( 'parse: name never over 128 characters', mb_strlen( $l[0]['name'] ) === 128 );
$dup = json_encode( array( array( 'lat' => '1', 'lon' => '2', 'name' => 'Singapore', 'address' => array( 'state' => 'Singapore', 'country' => 'Singapore' ) ) ) );
check( 'parse: no repeated parts', PodPub_Geo::parse( $dup )[0]['name'] === 'Singapore' );
$six = json_encode( array_fill( 0, 9, array( 'lat' => '1', 'lon' => '2', 'name' => 'X' ) ) );
check( 'parse: at most 5 results', count( PodPub_Geo::parse( $six ) ) === 5 );

// ---- Search ----
fresh(); reply( 200, $melb );
$r = PodPub_Geo::search( '  Melbourne  ' );
check( 'search: ok with results', $r['ok'] && count( $r['results'] ) === 2 && ! $r['cached'] && $r['message'] === '' );
$u = $GLOBALS['http'][0][0]; $a = $GLOBALS['http'][0][1];
check( 'search: public Nominatim by default, https, format, address details and limit asked for', 0 === strpos( $u, 'https://nominatim.openstreetmap.org/search?' ) && false !== strpos( $u, 'q=Melbourne' ) && false !== strpos( $u, 'format=jsonv2' ) && false !== strpos( $u, 'addressdetails=1' ) && false !== strpos( $u, 'limit=5' ) );
check( 'search: identifying User-Agent, 8 second timeout', $a['user-agent'] === 'PodPublisher/0.11.0 (https://example.com)' && $a['timeout'] === 8 );
$r2 = PodPub_Geo::search( 'melbourne' );
check( 'search: the same question again is served from the cache with no new request', $r2['ok'] && $r2['cached'] && count( $r2['results'] ) === 2 && count( $GLOBALS['http'] ) === 1 );

fresh(); reply( 200, $melb );
$GLOBALS['trans'][ PodPub_Geo::LOCK_KEY ] = (string) ( microtime( true ) - 0.2 );
$r = PodPub_Geo::search( 'Sydney' );
check( 'lock: a search within a second of the last one is refused and no request is made', ! $r['ok'] && false !== strpos( $r['message'], 'Wait a second' ) && $GLOBALS['http'] === array() );
$GLOBALS['trans'][ PodPub_Geo::LOCK_KEY ] = (string) ( microtime( true ) - 1.5 );
$r = PodPub_Geo::search( 'Sydney' );
check( 'lock: after a second it works, and the lock is set again', $r['ok'] && count( $GLOBALS['http'] ) === 1 && ( microtime( true ) - (float) $GLOBALS['trans'][ PodPub_Geo::LOCK_KEY ] ) < 1 );
$r = PodPub_Geo::search( 'Brisbane' );
check( 'lock: two different searches back to back: the second waits', ! $r['ok'] && count( $GLOBALS['http'] ) === 1 );
$GLOBALS['trans'][ PodPub_Geo::LOCK_KEY ] = (string) ( microtime( true ) - 0.2 );
check( 'lock: a cached answer is still given inside the second', PodPub_Geo::search( 'Sydney' )['cached'] === true );

fresh();
foreach ( array( '', 'ab', '  a  ', str_repeat( 'x', 121 ), '<b></b>' ) as $q ) {
	$r = PodPub_Geo::search( $q );
	check( 'input: "' . substr( $q, 0, 12 ) . '" refused without a request', ! $r['ok'] && $GLOBALS['http'] === array() );
}
fresh(); reply( 200, '[]' );
$r = PodPub_Geo::search( 'Zzzzzz' );
check( 'no places: ok, empty, says so', $r['ok'] && $r['results'] === array() && false !== strpos( $r['message'], 'No places' ) );
$r = PodPub_Geo::search( 'Zzzzzz' );
check( 'no places: also cached (an empty list is a valid answer)', $r['cached'] === true && count( $GLOBALS['http'] ) === 1 );

fresh(); $GLOBALS['reply'] = new WP_Error( 'x', 'cURL error 28' );
$r = PodPub_Geo::search( 'Melbourne' );
check( 'failure: unreachable gives one plain sentence, nothing cached', ! $r['ok'] && false !== strpos( $r['message'], 'could not be reached' ) && ! isset( $GLOBALS['trans'][ 'podpub_geo_' . md5( PodPub_Geo::DEFAULT_URL . '|melbourne' ) ] ) );
fresh(); reply( 429, 'Too many' );
check( 'failure: 429 says the service is refusing for now', false !== strpos( PodPub_Geo::search( 'Melbourne' )['message'], 'refusing' ) );
fresh(); reply( 500, 'oops' );
check( 'failure: 500 gives the error number', false !== strpos( PodPub_Geo::search( 'Melbourne' )['message'], 'error 500' ) );
fresh(); reply( 200, '<html>blocked</html>' );
$r = PodPub_Geo::search( 'Melbourne' );
check( 'failure: an answer that is not JSON is refused and not cached', ! $r['ok'] && false !== strpos( $r['message'], 'could not be read' ) && count( array_filter( array_keys( $GLOBALS['trans'] ), function ( $k ) { return 0 === strpos( $k, 'podpub_geo_' ) && $k !== PodPub_Geo::LOCK_KEY; } ) ) === 0 );

// ---- Settings ----
fresh(); reply( 200, $melb );
$GLOBALS['opts']['podpub_settings'] = array( 'geo_enabled' => 0 );
$r = PodPub_Geo::search( 'Melbourne' );
check( 'off: search switched off makes no request and says so', ! PodPub_Geo::enabled() && ! $r['ok'] && $GLOBALS['http'] === array() && false !== strpos( $r['message'], 'switched off' ) );
$GLOBALS['opts']['podpub_settings'] = array( 'geo_enabled' => 1, 'geo_url' => 'https://geo.example.org/nominatim/' );
$r = PodPub_Geo::search( 'Melbourne' );
check( 'own service: the address from Settings is used (trailing slash ignored)', PodPub_Geo::base_url() === 'https://geo.example.org/nominatim' && 0 === strpos( $GLOBALS['http'][0][0], 'https://geo.example.org/nominatim/search?' ) );
$GLOBALS['opts'] = array();
check( 'defaults: on, public Nominatim', PodPub_Geo::enabled() && PodPub_Geo::base_url() === 'https://nominatim.openstreetmap.org' );
fresh(); $GLOBALS['opts']['podpub_settings'] = array( 'geo_url' => 'http://insecure.example.org' );
$r = PodPub_Geo::search( 'Melbourne' );
check( 'rule 3: a plain http:// service address is never called', ! $r['ok'] && $GLOBALS['http'] === array() );
$GLOBALS['opts'] = array();
foreach ( array( 'https://a.example.org' => true, 'https://a.example.org:8443/geo' => true, 'http://a.example.org' => false, 'https://a b.example.org' => false, 'https://a.example.org/?x=1' => false, 'https://a.example.org/#f' => false, 'ftp://a.example.org' => false, 'https://a.example.org/"x' => false ) as $u => $ok ) {
	check( 'valid_url: ' . $u, PodPub_Geo::valid_url( $u ) === $ok );
}

// ---- OpenStreetMap address in the OSM id field ----
foreach ( array(
	'https://www.openstreetmap.org/relation/113314'      => 'R113314',
	'http://openstreetmap.org/node/42'                   => 'N42',
	'https://www.openstreetmap.org/way/987654/'          => 'W987654',
	'https://www.openstreetmap.org/relation/113314#map=5/1/2' => 'R113314',
	'https://www.openstreetmap.org/relation/113314?x=1'  => 'R113314',
	'r113314'                                            => 'R113314',
	' n42 '                                              => 'N42',
	'https://evil.example/relation/113314'               => 'HTTPS://EVIL.EXAMPLE/RELATION/113314',
	'https://www.openstreetmap.org/relation/abc'         => 'HTTPS://WWW.OPENSTREETMAP.ORG/RELATION/ABC',
) as $in => $want ) {
	check( 'osm_id: "' . $in . '" becomes ' . $want, PodPub_P20::osm_id( $in ) === $want );
}
$e = array();
$o = PodPub_P20::locations( array( 'locations' => array( array( 'name' => 'Melbourne', 'osm' => 'https://www.openstreetmap.org/relation/4246124' ) ) ), $e );
check( 'locations: an openstreetmap.org address pasted into the OSM id is saved as the id', $e === array() && $o[0]['osm'] === 'R4246124' );
$e = array();
PodPub_P20::locations( array( 'locations' => array( array( 'name' => 'Melbourne', 'osm' => 'https://evil.example/relation/1' ) ) ), $e );
check( 'locations: any other address is refused with the usual message', count( $e ) === 1 && false !== strpos( $e[0], 'OpenStreetMap id' ) );

exit( $fail );
