<?php
// Podcasting 2.0 tags (funding, location, person, license, value + time splits, block, podroll):
// checking the form, the XML written, and saving for a podcast and for an episode on a real temporary folder.
// Not a real WordPress: the few WordPress functions used are stood in for.
define( 'ABSPATH', __DIR__ );
define( 'PODPUB_VERSION', 'test' );
$tmp = sys_get_temp_dir() . '/podpub_p20_' . getmypid();
$GLOBALS['opts']  = array();
$GLOBALS['meta']  = array();
$GLOBALS['next']  = 100;
$GLOBALS['posts'] = array( 5 => (object) array( 'ID' => 5, 'post_type' => 'podpub_feed', 'post_status' => 'publish', 'post_title' => 'My Show', 'post_parent' => 0 ) );

function wp_upload_dir() { global $tmp; return array( 'basedir' => $tmp . '/uploads', 'baseurl' => 'https://example.com/wp-content/uploads' ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0755, true ); }
function get_option( $k, $d = false ) { return isset( $GLOBALS['opts'][ $k ] ) ? $GLOBALS['opts'][ $k ] : $d; }
function get_locale() { return 'en_AU'; }
function wp_timezone() { return new DateTimeZone( 'Australia/Melbourne' ); }
function get_post( $id ) { return isset( $GLOBALS['posts'][ $id ] ) ? $GLOBALS['posts'][ $id ] : null; }
function get_posts( $a ) { $o = array(); foreach ( $GLOBALS['posts'] as $p ) { if ( $p->post_type === $a['post_type'] && ( ! isset( $a['post_parent'] ) || (int) $p->post_parent === (int) $a['post_parent'] ) ) { $o[] = $p; } } return $o; }
function get_post_meta( $id, $k ) { return isset( $GLOBALS['meta'][ $id ][ $k ] ) ? $GLOBALS['meta'][ $id ][ $k ] : ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; }
function wp_insert_post( $a ) { $id = $GLOBALS['next']++; $GLOBALS['posts'][ $id ] = (object) array_merge( array( 'ID' => $id, 'post_parent' => 0 ), $a ); return $id; }
function wp_update_post( $a ) { $GLOBALS['posts'][ $a['ID'] ]->post_title = $a['post_title']; }
function wp_delete_post( $id ) { unset( $GLOBALS['posts'][ $id ], $GLOBALS['meta'][ $id ] ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_kses( $h, $allowed ) { return strip_tags( $h, '<' . implode( '><', array_keys( $allowed ) ) . '>' ); }
function esc_url_raw( $u ) { return $u; }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function size_format( $b ) { return $b . ' B'; }
function wp_max_upload_size() { return 67108864; }
class WP_Error { public $m; function __construct( $c, $m ) { $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }

foreach ( array( 'settings', 'storage', 'p20', 'images', 'details', 'episodes', 'feedxml', 'feeds' ) as $c ) { require __DIR__ . "/../plugin/includes/class-podpub-$c.php"; }

$fail = 0;
function check( $name, $ok ) { global $fail; echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n"; if ( ! $ok ) { $fail = 1; } }
function feed_dom( $file ) { $d = new DOMDocument(); $d->load( $file ); $x = new DOMXPath( $d ); foreach ( array( 'itunes' => 'http://www.itunes.com/dtds/podcast-1.0.dtd', 'podcast' => 'https://podcastindex.org/namespace/1.0' ) as $p => $u ) { $x->registerNamespace( $p, $u ); } return $x; }
function n( $v ) { return (float) $v; }
$G1 = '917393e3-1b1e-5cef-ace4-edaa54e1f810';
$NODE = '02' . str_repeat( 'ab', 32 ) . 'cd'; // 66 hex characters.
$NODE = substr( '02' . str_repeat( 'ab', 33 ), 0, 66 );

// ---- funding ----
$e = array();
$r = PodPub_P20::funding( array( 'funding' => array( array( 'url' => 'https://example.com/donate', 'message' => 'Support & "more"' ), array( 'url' => '', 'message' => '' ) ) ), $e );
check( 'funding: good row kept, blank row dropped', $e === array() && count( $r ) === 1 && $r[0]['message'] === 'Support & "more"' );
$e = array(); PodPub_P20::funding( array( 'funding' => array( array( 'url' => 'javascript:alert(1)', 'message' => 'x' ) ) ), $e );
check( 'funding: javascript: address refused', count( $e ) === 1 );
$e = array(); $f = PodPub_P20::funding( array( 'funding' => array( array( 'url' => 'https://example.com', 'message' => '' ) ) ), $e );
check( 'funding: missing message becomes the default, Support the show!', $e === array() && $f[0]['message'] === 'Support the show!' );
$e = array(); PodPub_P20::funding( array( 'funding' => array( array( 'url' => 'https://example.com', 'message' => str_repeat( 'a', 129 ) ) ) ), $e );
check( 'funding: message over 128 refused', count( $e ) === 1 );
$e = array(); PodPub_P20::funding( array( 'funding' => array_fill( 0, 11, array( 'url' => 'https://example.com', 'message' => 'x' ) ) ), $e );
check( 'funding: more than 10 refused', count( $e ) === 1 );
$e = array(); PodPub_P20::funding( array( 'funding' => array( array( 'url' => 'https://exa mple.com', 'message' => 'x' ) ) ), $e );
check( 'funding: address with a space refused', count( $e ) === 1 );

// ---- location ----
function L( $rows ) { return array( 'locations' => $rows ); }
$e = array();
$r = PodPub_P20::locations( L( array( array( 'name' => 'Melbourne, Australia', 'rel' => 'creator', 'geo' => 'geo: -37.8136, 144.9631', 'osm' => 'r2316741', 'country' => 'au' ), array( 'name' => '', 'rel' => '', 'geo' => '', 'osm' => '', 'country' => '' ) ) ), $e );
check( 'location: normalised (geo prefix, osm and country upper-case), blank row dropped', $e === array() && $r === array( array( 'name' => 'Melbourne, Australia', 'rel' => 'creator', 'geo' => '-37.8136,144.9631', 'osm' => 'R2316741', 'country' => 'AU' ) ) );
$e = array(); $r = PodPub_P20::locations( L( array( array( 'name' => 'Marlow', 'rel' => 'creator', 'country' => 'GB' ), array( 'name' => 'Dreamworld', 'rel' => '', 'country' => 'AU' ) ) ), $e );
check( 'location: two places kept (spec allows several)', $e === array() && count( $r ) === 2 && $r[1]['name'] === 'Dreamworld' );
$e = array(); $r = PodPub_P20::locations( L( array( array( 'name' => 'Somewhere' ) ) ), $e );
check( 'location: name alone is fine', $e === array() && $r[0]['name'] === 'Somewhere' && $r[0]['geo'] === '' );
foreach ( array(
	'latitude over 90' => array( 'name' => 'X', 'geo' => '91,10' ),
	'longitude over 180' => array( 'name' => 'X', 'geo' => '10,181' ),
	'junk coordinates' => array( 'name' => 'X', 'geo' => 'here' ),
	'bad osm id' => array( 'name' => 'X', 'osm' => 'Q12' ),
	'coordinates without a name' => array( 'geo' => '10,10' ),
	'name over 128' => array( 'name' => str_repeat( 'a', 129 ) ),
	'bad rel' => array( 'name' => 'X', 'rel' => 'about' ),
	'country of three letters' => array( 'name' => 'X', 'country' => 'AUS' ),
	'country with a digit' => array( 'name' => 'X', 'country' => 'A1' ),
) as $label => $row ) {
	$e = array(); $r = PodPub_P20::locations( L( array( $row ) ), $e );
	check( 'location: ' . $label . ' refused', count( $e ) === 1 && $r === array() );
}
$e = array(); $r = PodPub_P20::locations( array(), $e );
check( 'location: nothing entered is empty, no error', $e === array() && $r === array() );
$e = array(); $rows = array(); for ( $i = 0; $i < 11; $i++ ) { $rows[] = array( 'name' => 'P' . $i ); }
PodPub_P20::locations( L( $rows ), $e );
check( 'location: more than 10 refused', count( $e ) === 1 );
$e = array(); PodPub_P20::funding( array( 'funding' => array( array( 'url' => 'http://example.com/donate', 'message' => 'x' ) ) ), $e );
check( 'https only: an http:// funding address is refused', count( $e ) === 1 );

// ---- person ----
$e = array();
$r = PodPub_P20::persons( array( 'persons' => array( array( 'name' => 'Jo "J" Smith', 'role' => 'Guest', 'group' => 'Cast', 'img' => 'https://example.com/jo.jpg', 'href' => 'https://example.com/jo' ), array( 'name' => 'Host Two', 'role' => '', 'group' => '', 'img' => '', 'href' => '' ) ) ), $e );
check( 'person: kept, role and group lower-cased, optional fields may be empty', $e === array() && count( $r ) === 2 && $r[0]['role'] === 'guest' && $r[0]['group'] === 'cast' && $r[1]['role'] === '' );
$e = array(); PodPub_P20::persons( array( 'persons' => array( array( 'name' => '', 'role' => 'host' ) ) ), $e );
check( 'person: name required', count( $e ) === 1 );
$e = array(); PodPub_P20::persons( array( 'persons' => array( array( 'name' => 'A', 'role' => 'ho$st' ) ) ), $e );
check( 'person: role with symbols refused', count( $e ) === 1 );
$e = array(); PodPub_P20::persons( array( 'persons' => array( array( 'name' => 'A', 'href' => 'ftp://x.example' ) ) ), $e );
check( 'person: link must be http or https', count( $e ) === 1 );
$e = array(); PodPub_P20::persons( array( 'persons' => array_fill( 0, 31, array( 'name' => 'A' ) ) ), $e );
check( 'person: more than 30 refused', count( $e ) === 1 );

// ---- license ----
$e = array(); $r = PodPub_P20::license( array( 'license_name' => 'cc-by-4.0', 'license_url' => 'https://creativecommons.org/licenses/by/4.0/' ), $e );
check( 'license: name and address kept', $e === array() && $r['name'] === 'cc-by-4.0' );
$e = array(); PodPub_P20::license( array( 'license_url' => 'https://example.com' ), $e );
check( 'license: address without a name refused', count( $e ) === 1 );
$e = array(); PodPub_P20::license( array( 'license_name' => 'x', 'license_url' => 'nope' ), $e );
check( 'license: bad address refused', count( $e ) === 1 );

// ---- value ----
$rec = function ( $o = array() ) { return array_merge( array( 'name' => 'Host', 'address' => 'host@example.com', 'split' => '99', 'fee' => '' ), $o ); };
$e = array();
$r = PodPub_P20::value( array( 'value_suggested' => '0.00000015000', 'recipients' => array( $rec(), $rec( array( 'name' => 'Fee', 'address' => 'fee@example.org', 'split' => '1', 'fee' => '1' ) ) ) ), $e );
check( 'value: two Lightning address recipients, fee flag kept, method is lnaddress', $e === array() && $r['method'] === 'lnaddress' && count( $r['recipients'] ) === 2 && $r['recipients'][1]['fee'] === true && $r['recipients'][0]['fee'] === false && $r['recipients'][0]['type'] === 'lnaddress' );
$bad = function ( $o, $in = array() ) use ( $rec ) { $e = array(); PodPub_P20::value( array_merge( array( 'recipients' => array( $rec( $o ) ) ), $in ), $e ); return count( $e ) === 1; };
check( 'value: address must look like name@example.com (a node key is refused)', $bad( array( 'address' => 'not an address' ) ) && $bad( array( 'address' => substr( '02' . str_repeat( 'ab', 33 ), 0, 66 ) ) ) && $bad( array( 'address' => '' ) ) );
check( 'value: split must be above 0 and a number', $bad( array( 'split' => '0' ) ) && $bad( array( 'split' => 'x' ) ) && $bad( array( 'split' => '' ) ) );
check( 'value: bad suggested amount', $bad( array(), array( 'value_suggested' => 'lots' ) ) );
$e = array(); $r = PodPub_P20::value( array(), $e );
check( 'value: no recipients is simply no value block', $e === array() && $r['recipients'] === array() && $r['method'] === '' );
$e = array(); PodPub_P20::value( array( 'value_suggested' => '0.1' ), $e );
check( 'value: a suggested amount with no recipients is refused', count( $e ) === 1 );
$e = array(); $r = PodPub_P20::value( array( 'value_method' => 'keysend', 'recipients' => array( array( 'name' => 'X', 'type' => 'node', 'custom_key' => '696969', 'address' => 'x@example.com', 'split' => '1' ) ) ), $e );
check( 'value: old keysend fields in a form are ignored, nothing keysend is kept', $e === array() && $r['method'] === 'lnaddress' && $r['recipients'][0]['type'] === 'lnaddress' && ! isset( $r['recipients'][0]['custom_key'] ) );

// ---- time splits ----
$good_value = array( 'method' => 'lnaddress', 'suggested' => '', 'recipients' => array( array( 'name' => 'Host', 'type' => 'lnaddress', 'address' => 'host@example.com', 'split' => '99', 'fee' => false ) ) );
$ts = function ( $o = array() ) use ( $G1 ) { return array_merge( array( 'start' => '1:00', 'duration' => '237', 'remote_start' => '', 'remote_percent' => '', 'feed_guid' => $G1, 'item_guid' => '', 'feed_url' => '' ), $o ); };
$e = array(); $r = PodPub_P20::timesplits( array( 'timesplits' => array( $ts( array( 'remote_start' => '0:01', 'remote_percent' => '95', 'item_guid' => 'ep-7' ) ), $ts( array( 'start' => '', 'duration' => '' , 'feed_guid' => '' ) ) ) ), $good_value, $e );
check( 'timesplit: times parsed, percent kept, blank row dropped', $e === array() && count( $r ) === 1 && $r[0]['start'] === 60 && $r[0]['duration'] === 237 && $r[0]['remote_start'] === 1 && $r[0]['remote_percent'] === 95 && $r[0]['item_guid'] === 'ep-7' );
$e = array(); $r = PodPub_P20::timesplits( array( 'timesplits' => array( $ts() ) ), $good_value, $e );
check( 'timesplit: percent empty means 100', $r[0]['remote_percent'] === 100 );
$e = array(); PodPub_P20::timesplits( array( 'timesplits' => array( $ts() ) ), array( 'recipients' => array() ), $e );
check( 'timesplit: refused when the episode has no payments', count( $e ) === 1 );
foreach ( array( 'start' => 'x', 'duration' => '0', 'remote_percent' => '101', 'feed_guid' => 'nope', 'feed_url' => 'ftp://x.example', 'remote_start' => '1:99' ) as $k => $v ) {
	$e = array(); PodPub_P20::timesplits( array( 'timesplits' => array( $ts( array( $k => $v ) ) ) ), $good_value, $e );
	check( "timesplit: bad $k refused", count( $e ) === 1 );
}
$e = array(); PodPub_P20::timesplits( array( 'timesplits' => array_fill( 0, 31, $ts() ) ), $good_value, $e );
check( 'timesplit: more than 30 refused', count( $e ) >= 1 );

// ---- block, podroll ----
$e = array(); $r = PodPub_P20::block( array( 'block' => array( array( 'id' => '', 'value' => 'yes' ), array( 'id' => 'Google', 'value' => 'no' ), array( 'id' => '', 'value' => '' ) ) ), $e );
check( 'block: rows kept, id lower-cased, blank dropped', $e === array() && count( $r ) === 2 && $r[1]['id'] === 'google' );
$e = array(); PodPub_P20::block( array( 'block' => array( array( 'id' => 'x', 'value' => '' ) ) ), $e );
check( 'block: yes or no required', count( $e ) === 1 );
$e = array(); PodPub_P20::block( array( 'block' => array( array( 'id' => 'bad id!', 'value' => 'yes' ) ) ), $e );
check( 'block: odd service id refused', count( $e ) === 1 );
$e = array(); $r = PodPub_P20::podroll( array( 'podroll' => array( array( 'feed_guid' => strtoupper( $G1 ), 'feed_url' => 'https://example.com/feed.xml', 'title' => 'A Friend' ) ) ), $e );
check( 'podroll: entry kept, guid lower-cased', $e === array() && $r[0]['feed_guid'] === $G1 );
$e = array(); PodPub_P20::podroll( array( 'podroll' => array( array( 'feed_guid' => 'x' ) ) ), $e );
check( 'podroll: guid required', count( $e ) === 1 );
$e = array(); PodPub_P20::podroll( array( 'podroll' => array( array( 'feed_guid' => $G1, 'feed_url' => 'javascript:x' ) ) ), $e );
check( 'podroll: bad address refused', count( $e ) === 1 );

// ---- keys kept ----
$e = array(); $r = PodPub_P20::sanitize_channel( array(), array( 'description' => 'keep me', 'image' => 'cover.png' ), $e );
check( 'channel: other details are untouched', $r['description'] === 'keep me' && $r['image'] === 'cover.png' && $r['funding'] === array() );

// ---- saving for a podcast, and the feed ----
$pdir = PodPub_Storage::create_podcast_dir( 'My Show' );
update_post_meta( 5, '_podpub_dir', $pdir );
$pf = "$tmp/uploads/podpublisher/$pdir";
$r = PodPub_Feeds::save_p20( 5, array( 'funding' => array( array( 'url' => 'https://example.com/donate', 'message' => 'Fund us' ) ) ) );
check( 'podcast: tags can be saved before the details (feed written later)', $r['errors'] === array() && count( $r['warnings'] ) === 1 && ! $r['written'] );
PodPub_Feeds::save_details( 5, array( 'title' => 'My Show', 'description' => 'About', 'language' => 'en', 'explicit' => 'no' ) );
check( 'podcast: saving the main details keeps the tags', PodPub_Details::get( 5 )['funding'][0]['message'] === 'Fund us' );
$in = array(
	'funding'  => array( array( 'url' => 'https://example.com/donate?a=1&b=2', 'message' => 'Fish & "Chips"' ), array( 'url' => 'https://example.com/patreon', 'message' => 'Patreon' ) ),
	'locations' => array( array( 'name' => 'Melbourne', 'rel' => 'creator', 'geo' => '-37.8136,144.9631', 'osm' => 'R2316741', 'country' => 'AU' ), array( 'name' => 'Dreamworld', 'country' => 'AU' ) ),
	'persons'  => array( array( 'name' => 'Jo', 'role' => 'host', 'group' => 'cast', 'img' => 'https://example.com/jo.jpg', 'href' => 'https://example.com/jo' ) ),
	'license_name' => 'cc-by-4.0', 'license_url' => 'https://creativecommons.org/licenses/by/4.0/',
	'value_suggested' => '0.00000015000',
	'recipients' => array( $rec( array( 'name' => 'Host', 'split' => '99' ) ), $rec( array( 'name' => 'Index', 'address' => 'index@example.org', 'split' => '1', 'fee' => '1' ) ) ),
	'block'    => array( array( 'id' => '', 'value' => 'no' ), array( 'id' => 'google', 'value' => 'yes' ) ),
	'podroll'  => array( array( 'feed_guid' => $G1, 'feed_url' => 'https://example.com/feed.xml', 'title' => 'Friends' ) ),
);
$r = PodPub_Feeds::save_p20( 5, $in );
check( 'podcast: all tags saved and the feed written', $r['errors'] === array() && $r['written'] );
$x = feed_dom( "$pf/$pdir.xml" );
$dom = new DOMDocument(); check( 'feed: still well-formed with all tags', $dom->load( "$pf/$pdir.xml" ) );
check( 'feed: two funding links, with & and quotes escaped correctly', n( $x->evaluate( 'count(/rss/channel/podcast:funding)' ) ) === 2.0 && $x->evaluate( 'string(/rss/channel/podcast:funding[1]/@url)' ) === 'https://example.com/donate?a=1&b=2' && $x->evaluate( 'string(/rss/channel/podcast:funding[1])' ) === 'Fish & "Chips"' );
check( 'feed: location with rel, geo, osm and country', $x->evaluate( 'string(/rss/channel/podcast:location[1])' ) === 'Melbourne' && $x->evaluate( 'string(/rss/channel/podcast:location[1]/@rel)' ) === 'creator' && $x->evaluate( 'string(/rss/channel/podcast:location[1]/@geo)' ) === 'geo:-37.8136,144.9631' && $x->evaluate( 'string(/rss/channel/podcast:location[1]/@osm)' ) === 'R2316741' && $x->evaluate( 'string(/rss/channel/podcast:location[1]/@country)' ) === 'AU' );
check( 'feed: second location, rel defaults to subject, no empty attributes', $x->evaluate( 'string(/rss/channel/podcast:location[2])' ) === 'Dreamworld' && $x->evaluate( 'string(/rss/channel/podcast:location[2]/@rel)' ) === 'subject' && $x->evaluate( 'count(/rss/channel/podcast:location[2]/@geo | /rss/channel/podcast:location[2]/@osm)' ) == 0 );
check( 'feed: person', $x->evaluate( 'string(/rss/channel/podcast:person)' ) === 'Jo' && $x->evaluate( 'string(/rss/channel/podcast:person/@role)' ) === 'host' && $x->evaluate( 'string(/rss/channel/podcast:person/@group)' ) === 'cast' && $x->evaluate( 'string(/rss/channel/podcast:person/@img)' ) === 'https://example.com/jo.jpg' && $x->evaluate( 'string(/rss/channel/podcast:person/@href)' ) === 'https://example.com/jo' );
check( 'feed: license', $x->evaluate( 'string(/rss/channel/podcast:license)' ) === 'cc-by-4.0' && $x->evaluate( 'string(/rss/channel/podcast:license/@url)' ) === 'https://creativecommons.org/licenses/by/4.0/' );
check( 'feed: value block attributes', $x->evaluate( 'string(/rss/channel/podcast:value/@type)' ) === 'lightning' && $x->evaluate( 'string(/rss/channel/podcast:value/@method)' ) === 'lnaddress' && $x->evaluate( 'string(/rss/channel/podcast:value/@suggested)' ) === '0.00000015000' );
check( 'feed: recipients', n( $x->evaluate( 'count(/rss/channel/podcast:value/podcast:valueRecipient)' ) ) === 2.0 && $x->evaluate( 'string(/rss/channel/podcast:value/podcast:valueRecipient[1]/@address)' ) === 'host@example.com' && $x->evaluate( 'string(/rss/channel/podcast:value/podcast:valueRecipient[1]/@type)' ) === 'lnaddress' && $x->evaluate( 'string(/rss/channel/podcast:value/podcast:valueRecipient[1]/@split)' ) === '99' );
check( 'feed: fee only where set, no keysend custom record attributes', $x->evaluate( 'string(/rss/channel/podcast:value/podcast:valueRecipient[2]/@fee)' ) === 'true' && ! $x->evaluate( 'boolean(/rss/channel/podcast:value/podcast:valueRecipient[1]/@fee)' ) && n( $x->evaluate( 'count(//@customKey | //@customValue)' ) ) === 0.0 );
check( 'feed: block, with and without a service id', $x->evaluate( 'string(/rss/channel/podcast:block[1])' ) === 'no' && ! $x->evaluate( 'boolean(/rss/channel/podcast:block[1]/@id)' ) && $x->evaluate( 'string(/rss/channel/podcast:block[2]/@id)' ) === 'google' && $x->evaluate( 'string(/rss/channel/podcast:block[2])' ) === 'yes' );
check( 'feed: podroll', $x->evaluate( 'string(/rss/channel/podcast:podroll/podcast:remoteItem/@feedGuid)' ) === $G1 && $x->evaluate( 'string(/rss/channel/podcast:podroll/podcast:remoteItem/@feedUrl)' ) === 'https://example.com/feed.xml' && $x->evaluate( 'string(/rss/channel/podcast:podroll/podcast:remoteItem/@title)' ) === 'Friends' );

$before = PodPub_Details::get( 5 );
$r = PodPub_Feeds::save_p20( 5, array_merge( $in, array( 'locations' => array( array( 'name' => 'Bad', 'geo' => '999,999' ) ) ) ) );
check( 'podcast: one bad field saves nothing at all', count( $r['errors'] ) === 1 && PodPub_Details::get( 5 ) === $before );
$r = PodPub_Feeds::save_p20( 5, array() );
$x = feed_dom( "$pf/$pdir.xml" );
check( 'podcast: clearing the form removes every tag from the feed', $r['errors'] === array() && n( $x->evaluate( 'count(/rss/channel/podcast:funding | /rss/channel/podcast:location | /rss/channel/podcast:person | /rss/channel/podcast:license | /rss/channel/podcast:value | /rss/channel/podcast:block | /rss/channel/podcast:podroll)' ) ) === 0.0 );
check( 'podcast: the guid and medium are still there', $x->evaluate( 'string(/rss/channel/podcast:medium)' ) === 'podcast' && $x->evaluate( 'string-length(/rss/channel/podcast:guid)' ) > 30 );
$legacy = PodPub_Details::get( 5 );
$legacy['value'] = array( 'method' => 'keysend', 'suggested' => '', 'recipients' => array( array( 'name' => 'Old', 'type' => 'node', 'address' => str_repeat( 'a', 66 ), 'custom_key' => '1', 'custom_value' => 'v', 'split' => '100', 'fee' => false ) ) );
PodPub_Details::save( 5, $legacy );
PodPub_FeedXml::write( 5 );
$x = feed_dom( "$pf/$pdir.xml" );
check( 'podcast: a keysend value block saved by 0.7.0 is left out of the feed', n( $x->evaluate( 'count(/rss/channel/podcast:value)' ) ) === 0.0 );
check( 'podcast: unknown podcast refused', count( PodPub_Feeds::save_p20( 99, $in )['errors'] ) === 1 );

// ---- saving for an episode ----
$ep = array( 'title' => 'Ep One', 'description' => 'D', 'published' => '2026-01-15T09:30', 'status' => 'published', 'audio_source' => 'external', 'audio_url' => 'https://cdn.example.net/one.mp3', 'audio_size' => '1000', 'duration' => '10:00' );
$GLOBALS['probe_unused'] = true;
function wp_safe_remote_head() { return new WP_Error( 'x', 'offline' ); }
$epin = array_merge( $ep, array(
	'funding' => array( array( 'url' => 'https://example.com/ep', 'message' => 'Support this episode' ) ),
	'locations' => array( array( 'name' => 'Sydney' ) ),
	'persons' => array( array( 'name' => 'Guest Gary', 'role' => 'guest', 'group' => 'cast' ) ),
	'license_name' => 'cc-by-sa-4.0',
	'value_method' => 'lnaddress', 'recipients' => array( $rec( array( 'type' => 'lnaddress', 'address' => 'gary@example.com', 'split' => '100' ) ) ),
	'timesplits' => array( $ts( array( 'remote_start' => '0:30', 'remote_percent' => '90', 'item_guid' => 'abc-1' ) ), $ts( array( 'start' => '5:00', 'duration' => '60' ) ) ),
) );
$posts_before = count( $GLOBALS['posts'] );
$r = PodPub_Episodes::save( 5, 0, array_merge( $epin, array( 'timesplits' => array( $ts( array( 'feed_guid' => 'bad' ) ) ) ) ) );
check( 'episode: a bad tag saves nothing and creates no record or folder', count( $r['errors'] ) === 1 && count( $GLOBALS['posts'] ) === $posts_before && ! is_dir( "$pf/Ep_One" ) );
$r = PodPub_Episodes::save( 5, 0, $epin );
$eid = $r['id'];
check( 'episode: saved with all tags', $r['errors'] === array() && $r['written'] && $eid > 0 );
$x = feed_dom( "$pf/$pdir.xml" );
$dom = new DOMDocument(); check( 'episode feed: well-formed', $dom->load( "$pf/$pdir.xml" ) );
check( 'episode feed: funding, location, person, license inside the item', $x->evaluate( 'string(//item/podcast:funding)' ) === 'Support this episode' && $x->evaluate( 'string(//item/podcast:location)' ) === 'Sydney' && $x->evaluate( 'string(//item/podcast:person)' ) === 'Guest Gary' && $x->evaluate( 'string(//item/podcast:license)' ) === 'cc-by-sa-4.0' );
check( 'episode feed: nothing leaks to the channel', n( $x->evaluate( 'count(/rss/channel/podcast:funding)' ) ) === 0.0 && n( $x->evaluate( 'count(/rss/channel/podcast:value)' ) ) === 0.0 );
check( 'episode feed: value block with a lightning address recipient', $x->evaluate( 'string(//item/podcast:value/@method)' ) === 'lnaddress' && $x->evaluate( 'string(//item/podcast:value/podcast:valueRecipient/@type)' ) === 'lnaddress' && $x->evaluate( 'string(//item/podcast:value/podcast:valueRecipient/@address)' ) === 'gary@example.com' );
check( 'episode feed: two time splits inside the value block', n( $x->evaluate( 'count(//item/podcast:value/podcast:valueTimeSplit)' ) ) === 2.0 );
check( 'episode feed: time split 1 has its remote start, percentage and episode', $x->evaluate( 'string(//podcast:valueTimeSplit[1]/@startTime)' ) === '60' && $x->evaluate( 'string(//podcast:valueTimeSplit[1]/@duration)' ) === '237' && $x->evaluate( 'string(//podcast:valueTimeSplit[1]/@remoteStartTime)' ) === '30' && $x->evaluate( 'string(//podcast:valueTimeSplit[1]/@remotePercentage)' ) === '90' && $x->evaluate( 'string(//podcast:valueTimeSplit[1]/podcast:remoteItem/@feedGuid)' ) === $G1 && $x->evaluate( 'string(//podcast:valueTimeSplit[1]/podcast:remoteItem/@itemGuid)' ) === 'abc-1' );
check( 'episode feed: defaults left out (remote start 0, percentage 100, no item guid)', ! $x->evaluate( 'boolean(//podcast:valueTimeSplit[2]/@remoteStartTime)' ) && ! $x->evaluate( 'boolean(//podcast:valueTimeSplit[2]/@remotePercentage)' ) && ! $x->evaluate( 'boolean(//podcast:valueTimeSplit[2]/podcast:remoteItem/@itemGuid)' ) );
check( 'episode feed: the channel-only tags are not written in the item', n( $x->evaluate( 'count(//item/podcast:block | //item/podcast:podroll)' ) ) === 0.0 );
$r = PodPub_Episodes::save( 5, $eid, array_merge( $epin, array( 'value_method' => '', 'recipients' => array() ) ) );
check( 'episode: time splits without payments are refused on edit', count( $r['errors'] ) === 1 );
$r = PodPub_Episodes::save( 5, $eid, array_merge( $ep, array() ) );
$x = feed_dom( "$pf/$pdir.xml" );
check( 'episode: clearing the tag fields removes them from the item', $r['errors'] === array() && n( $x->evaluate( 'count(//item/podcast:funding | //item/podcast:location | //item/podcast:person | //item/podcast:license | //item/podcast:value)' ) ) === 0.0 );

// Clean up.
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { ( $f->isDir() && ! $f->isLink() ) ? rmdir( $f ) : unlink( $f ); }
rmdir( $tmp );
exit( $fail );
