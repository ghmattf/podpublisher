<?php
// Renders the podcast page, the episode list and the episode form with stand-in WordPress functions.
// Fails on any PHP warning or notice. It proves the pages run and hold the right parts; it does not show how WordPress styles them.
define( 'ABSPATH', __DIR__ );
define( 'PODPUB_VERSION', 'test' );
set_error_handler( function ( $no, $str, $file, $line ) { echo "FAIL PHP error: $str ($file:$line)\n"; exit( 1 ); } );
$tmp = sys_get_temp_dir() . '/podpub_ar_' . getmypid();
$GLOBALS['opts']  = array();
$GLOBALS['meta']  = array();
$GLOBALS['trans'] = array();
$GLOBALS['next']  = 100;
$GLOBALS['posts'] = array( 5 => (object) array( 'ID' => 5, 'post_type' => 'podpub_feed', 'post_status' => 'publish', 'post_title' => 'My <Show>', 'post_parent' => 0 ) );

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
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_kses( $h, $allowed ) { return strip_tags( $h, '<' . implode( '><', array_keys( $allowed ) ) . '>' ); }
function esc_url_raw( $u ) { return $u; }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function size_format( $b ) { return $b . ' B'; }
function wp_max_upload_size() { return 67108864; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s ) { return $s; }
function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . $p; }
function add_query_arg( $a, $u ) { return $u . ( false === strpos( $u, '?' ) ? '?' : '&' ) . http_build_query( $a ); }
function wp_nonce_field() { echo '<input type="hidden" name="_wpnonce" value="n">'; }
function submit_button( $t = 'Save', $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button class="' . $type . '">' . esc_html( $t ) . '</button>'; }
function selected( $a, $b, $e = true ) { return (string) $a === (string) $b ? ' selected' : ''; }
function checked( $a, $b, $e = true ) { return (string) $a === (string) $b ? ' checked' : ''; }
function current_user_can() { return true; }
function get_current_user_id() { return 1; }
function get_transient( $k ) { return isset( $GLOBALS['trans'][ $k ] ) ? $GLOBALS['trans'][ $k ] : false; }
function set_transient( $k, $v ) { $GLOBALS['trans'][ $k ] = $v; }
function delete_transient( $k ) { unset( $GLOBALS['trans'][ $k ] ); }
function wp_unslash( $v ) { return $v; }
function add_action() {}
class WP_Error { public $m; function __construct( $c, $m ) { $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }

foreach ( array( 'settings', 'storage', 'details', 'episodes', 'feedxml', 'feeds', 'admin' ) as $c ) { require __DIR__ . "/../plugin/includes/class-podpub-$c.php"; }

$fail = 0;
function check( $name, $ok ) { global $fail; echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n"; if ( ! $ok ) { $fail = 1; } }
function page( $id ) { ob_start(); PodPub_Admin::render_feed( $id ); return ob_get_clean(); }

$pdir = PodPub_Storage::create_podcast_dir( 'My Show' );
update_post_meta( 5, '_podpub_dir', $pdir );

$h = page( 5 );
check( 'podcast page: title is escaped', false !== strpos( $h, '<h1>My &lt;Show&gt;</h1>' ) );
check( 'podcast page: details form, upload enabled, nonce and action', false !== strpos( $h, 'enctype="multipart/form-data"' ) && false !== strpos( $h, 'value="podpub_save_details"' ) && false !== strpos( $h, '_wpnonce' ) && false !== strpos( $h, 'name="podpub_image"' ) );
check( 'podcast page: GIF offered for show art', false !== strpos( $h, 'image/gif' ) );
check( 'podcast page: feed address shown', false !== strpos( $h, "https://example.com/wp-content/uploads/podpublisher/$pdir/$pdir.xml" ) && false !== strpos( $h, 'not written yet' ) );
check( 'podcast page: episodes section with Add episode', false !== strpos( $h, '<h2>Episodes' ) && false !== strpos( $h, 'episode=new' ) && false !== strpos( $h, 'No episodes yet' ) );

PodPub_Feeds::save_details( 5, array( 'title' => 'My Show', 'description' => 'About', 'language' => 'en' ) );
$_GET['episode'] = 'new';
$h = page( 5 );
check( 'new episode form: fields, upload, status, no delete button', false !== strpos( $h, 'value="podpub_save_episode"' ) && false !== strpos( $h, 'name="podpub_audio"' ) && false !== strpos( $h, 'name="podpub_ep[published]"' ) && false !== strpos( $h, 'datetime-local' ) && false === strpos( $h, 'podpub_delete_episode' ) );
check( 'new episode form: shows the server upload limit and the allowed types', false !== strpos( $h, '67108864 B' ) && false !== strpos( $h, 'MP3, M4A, OGG, OPUS, WAV, FLAC' ) );

$r = PodPub_Episodes::save( 5, 0, array( 'title' => 'First <b>One</b>', 'description' => 'D', 'published' => '2026-01-15T09:30', 'status' => 'draft', 'duration' => '12:34', 'season' => '1', 'episode' => '3' ) );
$eid = $r['id'];
unset( $_GET['episode'] );
$h = page( 5 );
check( 'podcast page: episode listed with date and status', false !== strpos( $h, 'First One' ) && false !== strpos( $h, '2026-01-15T09:30' ) && false !== strpos( $h, 'Draft' ) && false !== strpos( $h, "episode=$eid" ) );
$_GET['episode'] = (string) $eid;
$h = page( 5 );
check( 'edit episode form: values filled in, delete button with confirm', false !== strpos( $h, 'value="First One"' ) && false !== strpos( $h, 'value="0:12:34"' ) && false !== strpos( $h, 'podpub_delete_episode' ) && false !== strpos( $h, 'confirm(' ) );
$_GET['episode'] = '99999';
check( 'edit episode form: unknown episode says not found', false !== strpos( page( 5 ), 'Episode not found' ) );
$_GET['episode'] = 'abc';
check( 'bad episode value falls back to the podcast page', false !== strpos( page( 5 ), 'Podcast details' ) );

// Audio source controls on the episode form.
$_GET['episode'] = (string) $eid;
$h = page( 5 );
check( 'audio: source selector with all three ways', false !== strpos( $h, 'name="podpub_ep[audio_source]"' ) && false !== strpos( $h, 'Upload a file' ) && false !== strpos( $h, 'Pick a file I copied' ) && false !== strpos( $h, 'Use a file hosted elsewhere' ) );
check( 'audio: shows the episode folder to copy files into, and that none are there yet', false !== strpos( $h, "podpublisher/$pdir/First_One</code>" ) && false !== strpos( $h, 'No audio files found' ) );
check( 'audio: hosted-elsewhere fields', false !== strpos( $h, 'name="podpub_ep[audio_url]"' ) && false !== strpos( $h, 'name="podpub_ep[audio_size]"' ) && false !== strpos( $h, 'name="podpub_ep[audio_ext_type]"' ) );
check( 'audio: one group each for upload, folder and external', 1 === substr_count( $h, '<div data-podpub-src="upload">' ) && 1 === substr_count( $h, '<div data-podpub-src="folder">' ) && 1 === substr_count( $h, '<div data-podpub-src="external">' ) );
file_put_contents( "$tmp/uploads/podpublisher/$pdir/First_One/Raw Take.mp3", "ID3" . str_repeat( "\0", 30 ) );
$h = page( 5 );
check( 'audio: a copied-in file is listed, with a note when it will be renamed', false !== strpos( $h, 'value="Raw Take.mp3"' ) && false !== strpos( $h, 'will be renamed' ) && false === strpos( $h, 'No audio files found' ) );
$_GET['episode'] = 'new';
$h = page( 5 );
check( 'audio: a new episode explains it must be saved before picking a file', false !== strpos( $h, 'Save the episode first' ) );

// After an error the form is refilled with what was typed, and the error is shown.
$_GET['episode'] = 'new';
set_transient( 'podpub_notice_1', array( 'errors' => array( 'Please enter a description.' ), 'warnings' => array(), 'written' => false, 'input' => array( 'title' => 'Typed "title"', 'description' => '' ) ) );
$h = page( 5 );
check( 'after an error: message shown and typed values kept', false !== strpos( $h, 'Please enter a description.' ) && false !== strpos( $h, 'value="Typed &quot;title&quot;"' ) );
check( 'notice is shown once only', false === strpos( page( 5 ), 'Please enter a description.' ) );
unset( $_GET['episode'] );
set_transient( 'podpub_notice_1', array( 'errors' => array(), 'warnings' => array( 'Careful.' ), 'written' => true ) );
$h = page( 5 );
check( 'success notice and warning shown', false !== strpos( $h, 'The feed file was written' ) && false !== strpos( $h, 'Careful.' ) );
$h = page( 77 );
check( 'unknown podcast says not found', false !== strpos( $h, 'Podcast not found' ) );

$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { $f->isDir() ? rmdir( $f ) : unlink( $f ); }
rmdir( $tmp );
exit( $fail );
