<?php
// OP3 download counting (0.9.1): the prefix, when it is and is not added, and the feed.
// Not a real WordPress: the few WordPress functions used are stood in for.
define( 'ABSPATH', __DIR__ );
define( 'PODPUB_VERSION', 'test' );
$tmp = sys_get_temp_dir() . '/podpub_op3_' . getmypid();
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
function delete_post_meta( $id, $k ) { unset( $GLOBALS['meta'][ $id ][ $k ] ); }
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

foreach ( array( 'settings', 'storage', 'p20', 'images', 'details', 'posts', 'chunks', 'episodes', 'feedxml', 'feeds' ) as $c ) { require __DIR__ . "/../plugin/includes/class-podpub-$c.php"; }

$fail = 0;

$fail = 0;
function check( $name, $ok ) { global $fail; echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n"; if ( ! $ok ) { $fail = 1; } }
function esave() { $a = func_get_args(); $r = call_user_func_array( array( 'PodPub_Episodes', 'save' ), $a ); if ( ! $r['errors'] ) { $w = PodPub_FeedXml::write( $a[0] ); $r['written'] = ! is_wp_error( $w ); } return $r; } // stands in for an episode save followed by the podcast-level Save and write feed
function wp_safe_remote_head() { return new WP_Error( 'x', 'offline' ); }
function feed_dom( $file ) { $d = new DOMDocument(); $d->load( $file ); $x = new DOMXPath( $d ); $x->registerNamespace( 'podcast', 'https://podcastindex.org/namespace/1.0' ); $x->registerNamespace( 'itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd' ); return $x; }
function ev( $x, $q ) { return $x->evaluate( $q ); }
mkdir( "$tmp/in", 0755, true );
$jpg = "$tmp/in/big.jpg"; $im = imagecreatetruecolor( 1600, 1600 ); imagefill( $im, 0, 0, imagecolorallocate( $im, 200, 80, 20 ) ); imagejpeg( $im, $jpg ); imagedestroy( $im );
$png = "$tmp/in/small.png"; $im = imagecreatetruecolor( 500, 500 ); imagepng( $im, $png ); imagedestroy( $im );
$gif = "$tmp/in/a.gif"; $im = imagecreatetruecolor( 800, 800 ); imagegif( $im, $gif ); imagedestroy( $im );
$up = function ( $path, $name ) { return array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'name' => $name ); };



$g = '11111111-2222-4333-8444-555555555555';
check( 'op3: https address loses https:// and gets the prefix with the podcast guid', PodPub_FeedXml::op3_url( 'https://cdn.example/a/b.mp3', $g ) === "https://op3.dev/e,pg=$g/cdn.example/a/b.mp3" );
check( 'op3: no guid, no pg parameter', PodPub_FeedXml::op3_url( 'https://cdn.example/b.mp3', '' ) === 'https://op3.dev/e/cdn.example/b.mp3' );
check( 'op3: http address keeps its http://', PodPub_FeedXml::op3_url( 'http://cdn.example/b.mp3', '' ) === 'https://op3.dev/e/http://cdn.example/b.mp3' );
check( 'op3: not added twice', PodPub_FeedXml::op3_url( 'https://op3.dev/e/cdn.example/b.mp3', $g ) === 'https://op3.dev/e/cdn.example/b.mp3' && PodPub_FeedXml::op3_url( "https://op3.dev/e,pg=$g/cdn.example/b.mp3", $g ) === "https://op3.dev/e,pg=$g/cdn.example/b.mp3" );
check( 'op3: other kinds of address untouched', PodPub_FeedXml::op3_url( 'ftp://x/y.mp3', $g ) === 'ftp://x/y.mp3' && PodPub_FeedXml::op3_url( '', $g ) === '' );
check( 'op3: bad guid is left out', PodPub_FeedXml::op3_url( 'https://c.example/b.mp3', 'zzz' ) === 'https://op3.dev/e/c.example/b.mp3' );

$pid = PodPub_Feeds::create( 'Show O' );
$pdir = PodPub_Feeds::dir_name( $pid );
$base = array( 'title' => 'Show O', 'description' => 'About', 'language' => 'en', 'explicit' => 'no' );
PodPub_Feeds::save_details( $pid, $base );
check( 'op3: off by default', PodPub_Details::get( $pid )['op3'] === 0 );
$ep = array( 'title' => 'One', 'description' => 'D', 'explicit' => 'no', 'published' => '2026-01-10T09:00', 'status' => 'published', 'audio_source' => 'external', 'audio_url' => 'https://cdn.example.net/one.mp3', 'audio_size' => '1000', 'duration' => '10:00' );
$r = esave( $pid, 0, array_merge( $ep, array( 'enclosures' => array( array( 'uri' => 'https://cdn.example.net/one.mp4', 'type' => 'video/mp4', 'length' => '99' ) ) ) ) );
$folder = "$tmp/uploads/podpublisher/$pdir";
$x = feed_dom( "$folder/$pdir.xml" );
check( 'op3 off: enclosure is the plain address', ev( $x, 'string(//item/enclosure/@url)' ) === 'https://cdn.example.net/one.mp3' );
PodPub_Feeds::save_details( $pid, array_merge( $base, array( 'op3' => '1' ) ) );
$guid = PodPub_Details::get( $pid )['guid'];
$x = feed_dom( "$folder/$pdir.xml" );
check( 'op3 on: enclosure gets the prefix with the podcast guid', PodPub_Details::get( $pid )['op3'] === 1 && ev( $x, 'string(//item/enclosure/@url)' ) === "https://op3.dev/e,pg=$guid/cdn.example.net/one.mp3" );
check( 'op3 on: the alternate file is not prefixed', ev( $x, 'string(//item/podcast:alternateEnclosure/podcast:source/@uri)' ) === 'https://cdn.example.net/one.mp4' );
check( 'op3 on: the address stored for the episode is unchanged', PodPub_Episodes::details( $r['id'] )['external_url'] === 'https://cdn.example.net/one.mp3' );
PodPub_Feeds::save_details( $pid, $base );
$x = feed_dom( "$folder/$pdir.xml" );
check( 'op3 unticked: plain address again', PodPub_Details::get( $pid )['op3'] === 0 && ev( $x, 'string(//item/enclosure/@url)' ) === 'https://cdn.example.net/one.mp3' );

$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { ( $f->isDir() && ! $f->isLink() ) ? rmdir( $f ) : unlink( $f ); }
rmdir( $tmp );
exit( $fail );
