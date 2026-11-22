<?php
// Delete podcast: records, episodes and (only when asked) the folder, on a real temporary folder.
// Not a real WordPress: the few WordPress functions used are stood in for.
define( 'ABSPATH', __DIR__ );
define( 'PODPUB_VERSION', 'test' );
$tmp = sys_get_temp_dir() . '/podpub_dp_' . getmypid();
$GLOBALS['opts']  = array();
$GLOBALS['meta']  = array();
$GLOBALS['next']  = 100;
$GLOBALS['posts'] = array();

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
function wp_read_audio_metadata( $p ) { return array( 'length' => 61.4 ); }
$GLOBALS['probe_calls'] = 0;
$GLOBALS['probe'] = array( 'code' => 200, 'headers' => array( 'content-length' => '5555', 'content-type' => 'audio/mpeg' ) );
function wp_safe_remote_head( $u, $a = array() ) { $GLOBALS['probe_calls']++; return $GLOBALS['probe'] ? array( 'code' => $GLOBALS['probe']['code'], 'headers' => $GLOBALS['probe']['headers'] ) : new WP_Error( 'x', 'unreachable' ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_header( $r, $h ) { return isset( $r['headers'][ $h ] ) ? $r['headers'][ $h ] : ''; }
class WP_Error { public $m; function __construct( $c, $m ) { $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }

foreach ( array( 'settings', 'storage', 'p20', 'images', 'details', 'episodes', 'feedxml', 'feeds' ) as $c ) { require __DIR__ . "/../plugin/includes/class-podpub-$c.php"; }

$fail = 0;
function check( $name, $ok ) { global $fail; echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n"; if ( ! $ok ) { $fail = 1; } }
function put( $path, $bytes ) { file_put_contents( $path, $bytes ); return $path; }
function up( $path, $name ) { return array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'name' => $name ); }
function feed_dom( $file ) { $d = new DOMDocument(); $d->load( $file ); $x = new DOMXPath( $d ); foreach ( array( 'itunes' => 'http://www.itunes.com/dtds/podcast-1.0.dtd', 'podcast' => 'https://podcastindex.org/namespace/1.0', 'content' => 'http://purl.org/rss/1.0/modules/content/' ) as $p => $u ) { $x->registerNamespace( $p, $u ); } return $x; }
mkdir( "$tmp/in", 0755, true );
$MP3  = "ID3\x04\x00\x00\x00\x00\x00\x00" . str_repeat( "\0", 100 );
$det  = array( 'title' => 'x', 'description' => 'About', 'language' => 'en', 'explicit' => 'no' );
$ep   = array( 'title' => 'First', 'description' => 'Hello', 'published' => '2026-01-15T09:30', 'duration' => '5:00', 'status' => 'published' );
$base = "$tmp/uploads/podpublisher";

// A podcast with details, a feed file and two episodes (one with audio), and a second podcast that must never be touched.
function make_podcast( $title ) {
	global $det, $ep, $MP3, $tmp;
	$id = PodPub_Feeds::create( $title );
	PodPub_Feeds::save_details( $id, array_merge( $det, array( 'title' => $title ) ) );
	$a = PodPub_Episodes::save( $id, 0, $ep, up( put( "$tmp/in/a.mp3", $MP3 ), 'a.mp3' ) );
	$b = PodPub_Episodes::save( $id, 0, array_merge( $ep, array( 'title' => 'Second', 'status' => 'draft' ) ) );
	return array( $id, $a['id'], $b['id'] );
}
list( $keep, $keep_e1, $keep_e2 ) = make_podcast( 'Keeper' );
list( $p1, $p1_e1, $p1_e2 )       = make_podcast( 'Doomed One' );
list( $p2, $p2_e1, $p2_e2 )       = make_podcast( 'Doomed Two' );
check( 'setup: three podcasts with feed files and episode folders', is_file( "$base/Keeper/Keeper.xml" ) && is_file( "$base/Doomed_One/Doomed_One.xml" ) && is_dir( "$base/Doomed_Two/First" ) && $p1_e1 > 0 && $p1_e2 > 0 );

// Delete without the files: records go, files and feed address stay.
$r = PodPub_Feeds::delete( $p1, false );
check( 'no files: no errors, two episodes counted, files not deleted', $r['errors'] === array() && $r['episodes'] === 2 && $r['files_deleted'] === false && $r['warnings'] === array() );
check( 'no files: podcast record and both episode records are gone', PodPub_Feeds::get( $p1 ) === null && get_post( $p1_e1 ) === null && get_post( $p1_e2 ) === null && PodPub_Episodes::all( $p1 ) === array() );
check( 'no files: the folder, feed file and audio stay (feed address keeps working)', is_file( "$base/Doomed_One/Doomed_One.xml" ) && is_file( "$base/Doomed_One/First/First.mp3" ) );
check( 'no files: the folder path is reported', $r['folder'] === "$base/Doomed_One" );
check( 'no files: the podcast is no longer in the list', ! in_array( $p1, array_map( function ( $f ) { return $f->ID; }, PodPub_Feeds::all() ), true ) );
check( 'no files: the other podcast and its episodes are untouched', PodPub_Feeds::get( $keep ) !== null && PodPub_Episodes::get( $keep_e1, $keep ) !== null && PodPub_Episodes::get( $keep_e2, $keep ) !== null && is_file( "$base/Keeper/Keeper.xml" ) );
check( 'deleting the same podcast again is refused', count( PodPub_Feeds::delete( $p1, false )['errors'] ) === 1 );

// Delete with the files: the whole folder goes; a link inside it is not followed.
mkdir( "$tmp/outside", 0755, true );
put( "$tmp/outside/keep.txt", 'keep me' );
symlink( "$tmp/outside", "$base/Doomed_Two/First/link_out" );
$r = PodPub_Feeds::delete( $p2, true );
check( 'with files: no errors, no warnings, folder deleted', $r['errors'] === array() && $r['warnings'] === array() && $r['files_deleted'] === true && $r['episodes'] === 2 );
check( 'with files: folder, feed file and episode folders are gone', ! file_exists( "$base/Doomed_Two" ) && PodPub_Feeds::get( $p2 ) === null );
check( 'with files: a file behind a symbolic link was not touched', is_file( "$tmp/outside/keep.txt" ) );
check( 'with files: the other podcast and the Podcast folder itself are untouched', is_file( "$base/Keeper/Keeper.xml" ) && is_file( "$base/Keeper/First/First.mp3" ) && is_dir( $base ) );

// Things that must not be trusted.
check( 'unknown podcast refused', count( PodPub_Feeds::delete( 99999, true )['errors'] ) === 1 );
check( 'an episode id is not a podcast: refused, nothing deleted', count( PodPub_Feeds::delete( $keep_e1, true )['errors'] ) === 1 && PodPub_Episodes::get( $keep_e1, $keep ) !== null && is_dir( "$base/Keeper/First" ) );

list( $p3, $p3_e1 ) = make_podcast( 'Odd Name' );
put( "$tmp/uploads/sentinel.txt", 'do not delete' );
update_post_meta( $p3, '_podpub_dir', '../' );
$r = PodPub_Feeds::delete( $p3, true );
check( 'unsafe folder name: files not deleted, warning given, records still removed', $r['files_deleted'] === false && count( $r['warnings'] ) === 1 && is_file( "$tmp/uploads/sentinel.txt" ) && PodPub_Feeds::get( $p3 ) === null );

list( $p4 ) = make_podcast( 'Gone Folder' );
PodPub_Storage::remove_tree( "$base/Gone_Folder" );
$r = PodPub_Feeds::delete( $p4, true );
check( 'folder already missing: warning, records removed', $r['files_deleted'] === false && count( $r['warnings'] ) === 1 && $r['errors'] === array() && PodPub_Feeds::get( $p4 ) === null );

list( $p5 ) = make_podcast( 'Linked' );
PodPub_Storage::remove_tree( "$base/Linked" );
symlink( "$tmp/outside", "$base/Linked" );
$r = PodPub_Feeds::delete( $p5, true );
check( 'podcast folder is a symbolic link: not followed, target untouched, warning given', $r['files_deleted'] === false && count( $r['warnings'] ) === 1 && is_file( "$tmp/outside/keep.txt" ) );

list( $p6 ) = make_podcast( 'No Tick' );
$r = PodPub_Feeds::delete( $p6, false );
check( 'files box not ticked: nothing on disk is touched', is_dir( "$base/No_Tick" ) && $r['files_deleted'] === false );

// Clean up.
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { ( $f->isDir() && ! $f->isLink() ) ? rmdir( $f ) : unlink( $f ); }
rmdir( $tmp );
exit( $fail );
