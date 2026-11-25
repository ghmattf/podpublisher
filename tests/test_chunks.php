<?php
// Chunked audio upload: pieces, order, retries, limits, ownership, expiry, and Save using the staged file.
// Not a real WordPress: the few WordPress functions used are stood in for.
define( 'ABSPATH', __DIR__ );
define( 'PODPUB_VERSION', 'test' );
$tmp = sys_get_temp_dir() . '/podpub_posts_' . getmypid();
$GLOBALS['opts']  = array();
$GLOBALS['meta']  = array();
$GLOBALS['next']  = 100;
$GLOBALS['posts'] = array( 5 => (object) array( 'ID' => 5, 'post_type' => 'podpub_feed', 'post_status' => 'publish', 'post_title' => 'My Show', 'post_parent' => 0 ),
                           6 => (object) array( 'ID' => 6, 'post_type' => 'podpub_feed', 'post_status' => 'publish', 'post_title' => 'Other Show', 'post_parent' => 0 ) );

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
function wp_update_post( $a ) { foreach ( $a as $k => $v ) { $GLOBALS['posts'][ $a['ID'] ]->$k = $v; } return $a['ID']; }
function wp_trash_post( $id ) { $GLOBALS['posts'][ $id ]->post_status = 'trash'; $GLOBALS['trashed'][] = $id; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['meta'][ $id ][ $k ] ); }
function get_permalink( $id ) { return 'https://example.com/?p=' . $id; }
function wp_date( $f, $t ) { return gmdate( $f, $t ); }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_url( $s ) { return $s; }
function get_users( $a ) { return array( (object) array( 'ID' => 3, 'display_name' => 'Ann' ), (object) array( 'ID' => 4, 'display_name' => 'Bob' ) ); }
function get_categories( $a ) { return array( (object) array( 'term_id' => 7, 'name' => 'News' ), (object) array( 'term_id' => 8, 'name' => 'Extra' ) ); }
function current_user_can( $c ) { return ! in_array( $c, $GLOBALS['deny'], true ); }
$GLOBALS['deny'] = array(); $GLOBALS['trashed'] = array();
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


function apply_filters( $t, $v ) { return $v; }
function wp_json_encode( $v ) { return json_encode( $v ); }

foreach ( array( 'settings', 'storage', 'p20', 'images', 'details', 'posts', 'chunks', 'episodes', 'feedxml', 'feeds' ) as $c ) { require __DIR__ . "/../plugin/includes/class-podpub-$c.php"; }
$fail = 0;
function check( $name, $ok ) { global $fail; echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n"; if ( ! $ok ) { $fail = 1; } }
function piece( $dir, $n, $bytes ) { $p = "$dir/piece$n"; file_put_contents( $p, $bytes ); return $p; }
mkdir( "$tmp/in", 0755, true );
$MP3  = "ID3\x04\x00\x00\x00\x00\x00\x00" . str_repeat( 'abcdefghij', 5000 ); // 50010 bytes
$id   = str_repeat( 'a1', 16 );
$size = strlen( $MP3 );
$cs   = 20000;
$parts = str_split( $MP3, $cs );
$n    = count( $parts );

check( 'chunk size is under the server limit with room for form fields', PodPub_Chunks::chunk_size() <= wp_max_upload_size() - 262144 + 1 || PodPub_Chunks::chunk_size() === 262144 );
check( 'staging folder is not inside the podcasts folder', 0 !== strpos( PodPub_Chunks::dir(), rtrim( PodPub_Storage::base_path(), '/' ) . '/' ) );

// Normal upload in pieces.
$last = null;
foreach ( $parts as $i => $b ) { $last = PodPub_Chunks::receive( $id, $i, $n, 'My Show Ep1.mp3', $size, piece( "$tmp/in", $i, $b ), 7 ); }
check( 'pieces assemble: last piece reports done', is_array( $last ) && $last['done'] === true );
$f = PodPub_Chunks::file( $id, 7 );
check( 'finished file is byte-for-byte the original', $f['error'] === UPLOAD_ERR_OK && file_get_contents( $f['tmp_name'] ) === $MP3 && $f['name'] === 'My Show Ep1.mp3' && $f['size'] === $size );
check( 'staging folder is protected (index and deny rule)', is_file( PodPub_Chunks::dir() . '/index.html' ) && is_file( PodPub_Chunks::dir() . '/.htaccess' ) );
check( 'info shows name and size to its owner only', PodPub_Chunks::info( $id, 7 )['size'] === $size && null === PodPub_Chunks::info( $id, 8 ) );
check( 'another user cannot use the staged file', PodPub_Chunks::file( $id, 8 )['error'] !== UPLOAD_ERR_OK );

// Save uses the staged file.
$pdir = PodPub_Storage::create_podcast_dir( 'My Show' );
PodPub_Feeds::save_details( 5, array( 'title' => 'My Show', 'description' => 'About', 'language' => 'en', 'explicit' => 'no' ) );
update_post_meta( 5, '_podpub_dir', $pdir );
$r = PodPub_Episodes::save( 5, 0, array( 'title' => 'Ep1', 'description' => 'D', 'explicit' => 'no', 'published' => '2026-01-15T09:30', 'status' => 'published', 'audio_source' => 'upload' ), $f );
check( 'Save with the staged file stores it as the episode audio', $r['errors'] === array() && '' !== PodPub_Episodes::details( $r['id'] )['audio'] && filesize( PodPub_Storage::base_path() . "/$pdir/" . PodPub_Episodes::dir_name( $r['id'] ) . '/' . PodPub_Episodes::details( $r['id'] )['audio'] ) === $size );
PodPub_Chunks::discard( $id );
check( 'discard removes the staged files', ! is_file( $f['tmp_name'] ) && null === PodPub_Chunks::info( $id, 7 ) );
check( 'a staged file that is gone gives a clear error on Save', ( function () use ( $pdir ) {
	$gone = PodPub_Chunks::file( str_repeat( 'b2', 16 ), 7 );
	$r = PodPub_Episodes::save( 5, 0, array( 'title' => 'Ep2', 'description' => 'D', 'explicit' => 'no', 'published' => '2026-01-16T09:30', 'status' => 'published', 'audio_source' => 'upload' ), $gone );
	return count( $r['errors'] ) >= 1 && false !== strpos( $r['errors'][0], 'Upload it again' );
} )() );

// Refusals.
$id2 = str_repeat( 'c3', 16 );
check( 'bad upload id refused', is_wp_error( PodPub_Chunks::receive( 'nope', 0, 2, 'a.mp3', 100, piece( "$tmp/in", 90, 'x' ), 7 ) ) );
check( 'wrong extension refused on the first piece', is_wp_error( PodPub_Chunks::receive( $id2, 0, 2, 'a.exe', 100, piece( "$tmp/in", 91, 'x' ), 7 ) ) );
check( 'missing piece (not received) refused with the limit named', ( function () use ( $id2 ) { $e = PodPub_Chunks::receive( $id2, 0, 2, 'a.mp3', 100, '/nonexistent', 7 ); return is_wp_error( $e ) && false !== strpos( $e->get_error_message(), 'limit' ); } )() );
check( 'a piece without a start is refused', is_wp_error( PodPub_Chunks::receive( $id2, 1, 2, 'a.mp3', 100, piece( "$tmp/in", 92, 'x' ), 7 ) ) );
PodPub_Chunks::receive( $id2, 0, 3, 'a.mp3', 90, piece( "$tmp/in", 93, str_repeat( 'x', 30 ) ), 7 );
check( 'skipping a piece is refused', is_wp_error( PodPub_Chunks::receive( $id2, 2, 3, 'a.mp3', 90, piece( "$tmp/in", 94, str_repeat( 'x', 30 ) ), 7 ) ) );
$dup = PodPub_Chunks::receive( $id2, 0, 3, 'a.mp3', 90, piece( "$tmp/in", 95, str_repeat( 'x', 30 ) ), 7 );
check( 'a piece sent again is accepted without being added twice', is_array( $dup ) && $dup['next'] === 1 && filesize( PodPub_Chunks::dir() . "/$id2.part" ) === 30 );
PodPub_Chunks::receive( $id2, 1, 3, 'a.mp3', 90, piece( "$tmp/in", 96, str_repeat( 'x', 30 ) ), 7 );
$wrong = PodPub_Chunks::receive( $id2, 2, 3, 'a.mp3', 90, piece( "$tmp/in", 97, str_repeat( 'x', 10 ) ), 7 );
check( 'final size not matching the announced size is refused and cleaned up', is_wp_error( $wrong ) && ! is_file( PodPub_Chunks::dir() . "/$id2.part" ) );
check( 'someone else cannot continue my upload', ( function () use ( $id2, $tmp ) { PodPub_Chunks::receive( $id2, 0, 2, 'a.mp3', 60, piece( "$tmp/in", 98, str_repeat( 'x', 30 ) ), 7 ); return is_wp_error( PodPub_Chunks::receive( $id2, 1, 2, 'a.mp3', 60, piece( "$tmp/in", 99, str_repeat( 'x', 30 ) ), 9 ) ); } )() );
check( 'a piece that makes the file bigger than announced is refused', ( function () use ( $tmp ) { $i = str_repeat( 'd4', 16 ); PodPub_Chunks::receive( $i, 0, 2, 'a.mp3', 40, piece( "$tmp/in", 100, str_repeat( 'x', 30 ) ), 7 ); return is_wp_error( PodPub_Chunks::receive( $i, 1, 2, 'a.mp3', 40, piece( "$tmp/in", 101, str_repeat( 'x', 30 ) ), 7 ) ); } )() );

// Expiry.
$old = str_repeat( 'e5', 16 );
PodPub_Chunks::receive( $old, 0, 2, 'a.mp3', 60, piece( "$tmp/in", 102, str_repeat( 'x', 30 ) ), 7 );
touch( PodPub_Chunks::dir() . "/$old.part", time() - 90000 ); touch( PodPub_Chunks::dir() . "/$old.json", time() - 90000 );
PodPub_Chunks::receive( str_repeat( 'f6', 16 ), 0, 2, 'a.mp3', 60, piece( "$tmp/in", 103, str_repeat( 'x', 30 ) ), 7 );
check( 'uploads older than a day are removed', ! is_file( PodPub_Chunks::dir() . "/$old.part" ) && ! is_file( PodPub_Chunks::dir() . "/$old.json" ) );
echo $fail ? "\nSOME FAILED\n" : "\nAll passed\n";
exit( $fail );
