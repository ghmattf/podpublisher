<?php
// Adopt a feed screen and confirm handler (0.12.0), with stand-in WordPress functions.
// Not a real WordPress: the few WordPress functions used are stood in for.
define( 'ABSPATH', __DIR__ );
define( 'PODPUB_VERSION', '0.12.0' );
$tmp = sys_get_temp_dir() . '/podpub_ad_' . getmypid();
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

foreach ( array( 'settings', 'storage', 'p20', 'images', 'details', 'episodes', 'feedxml', 'feeds', 'geo', 'adopt', 'admin' ) as $c ) { require __DIR__ . "/../plugin/includes/class-podpub-$c.php"; }

$fail = 0;
function check( $name, $ok ) { global $fail; echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n"; if ( ! $ok ) { $fail = 1; } }
function put( $path, $bytes ) { file_put_contents( $path, $bytes ); return $path; }
function up( $path, $name ) { return array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'name' => $name ); }

function home_url() { return 'https://example.com'; }
function site_url() { return 'https://example.com'; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
$GLOBALS['web'] = array(); // address => body (or WP_Error / array code)
$GLOBALS['fetched'] = array();
function wp_safe_remote_get( $u, $a = array() ) { $GLOBALS['fetched'][] = $u; $w = isset( $GLOBALS['web'][ $u ] ) ? $GLOBALS['web'][ $u ] : array( 'code' => 404, 'body' => '' ); return $w; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }


set_error_handler( function ( $no, $str, $file, $line ) { echo "FAIL PHP error: $str ($file:$line)\n"; exit( 1 ); } );
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s ) { return $s; }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function selected( $a, $b, $e = true ) { return (string) $a === (string) $b ? ' selected' : ''; }
function checked( $a, $b, $e = true ) { return (string) $a === (string) $b ? ' checked' : ''; }
function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . $p; }
function add_query_arg( $a, $u, $v = null ) { if ( ! is_array( $a ) ) { $a = array( $a => $u ); $u = $v; } return $u . ( false === strpos( $u, '?' ) ? '?' : '&' ) . http_build_query( $a ); }
function wp_nonce_field() { echo '<input type="hidden" name="_wpnonce" value="n">'; }
function submit_button( $t = 'Save', $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button class="' . $type . '">' . esc_html( $t ) . '</button>'; }
function current_user_can() { return ! isset( $GLOBALS['can'] ) || $GLOBALS['can']; }
function get_current_user_id() { return 1; }
function get_transient( $k ) { return isset( $GLOBALS['trans'][ $k ] ) ? $GLOBALS['trans'][ $k ] : false; }
function set_transient( $k, $v ) { $GLOBALS['trans'][ $k ] = $v; }
function delete_transient( $k ) { unset( $GLOBALS['trans'][ $k ] ); }
function wp_unslash( $v ) { return $v; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function check_admin_referer( $a ) { if ( ! empty( $GLOBALS['bad_nonce'] ) ) { throw new Exception( 'nonce' ); } return true; }
function wp_die( $m = '', $c = 0 ) { throw new Exception( 'die ' . $c ); }
class Redirect extends Exception { public $to; }
function wp_safe_redirect( $u ) { $e = new Redirect( 'redirect' ); $e->to = $u; throw $e; }
function add_action() {}
function wp_create_nonce( $a = '' ) { return 'n'; }
function wp_json_encode( $v ) { return json_encode( $v ); }
$GLOBALS['trans'] = array();
function screen( $src ) { $_GET = array( 'page' => 'podpublisher', 'action' => 'import' ); if ( '' !== $src ) { $_GET['podpub_source'] = $src; $_GET['_wpnonce'] = 'n'; } ob_start(); PodPub_Admin::render_list(); return ob_get_clean(); }
function confirm( $post ) { $_POST = $post; try { PodPub_Admin::handle_adopt_confirm(); } catch ( Redirect $r ) { return $r->to; } return 'no redirect'; }

mkdir( "$tmp/uploads/old", 0755, true );
$FIX = file_get_contents( __DIR__ . '/fixtures/adopt_feed.xml' );
$OLD = "$tmp/uploads/old/show.xml";
$OLDURL = 'https://example.com/wp-content/uploads/old/show.xml';
file_put_contents( $OLD, $FIX );

$h = screen( '' );
check( 'screen: the button and heading say Adopt a feed, not Import', false !== strpos( $h, 'Adopt a feed' ) && false === strpos( $h, 'Import podcast' ) );
check( 'screen: has a source box and a Check feed button, and no report yet', false !== strpos( $h, 'name="podpub_source"' ) && false !== strpos( $h, 'Check feed' ) && false === strpos( $h, 'Adopt this feed' ) );

$h = screen( $OLD );
check( 'report: shows the title, counts and the in-place note with the address', false !== strpos( $h, 'Adopt this feed' ) && false !== strpos( $h, 'in place' ) && false !== strpos( $h, esc_html( $OLDURL ) ) && false !== strpos( $h, 'episodes found' ) );
check( 'report: the lost-tags table is shown and the checkbox is required', false !== strpos( $h, 'would be lost' ) && false !== strpos( $h, 'name="podpub_confirm" value="1" required' ) );
check( 'report: hidden source and hash are in the confirm form', false !== strpos( $h, 'name="podpub_source" value="' . esc_attr( $OLD ) . '"' ) && 1 === preg_match( '/name="podpub_hash" value="[0-9a-f]{20}"/', $h ) );
check( 'report: nothing was created by only checking', array() === get_posts( array( 'post_type' => 'podpub_feed' ) ) );

$h = screen( "$tmp/nope.xml" );
check( 'screen: a bad source shows a plain error and no confirm form', false !== strpos( $h, 'notice-error' ) && false === strpos( $h, 'Adopt this feed' ) );
$h = screen( '<script>alert(1)</script>' );
check( 'screen: the source is escaped when shown back', false === strpos( $h, '<script>alert' ) );
$GLOBALS['bad_nonce'] = true;
$threw = false; try { screen( $OLD ); } catch ( Exception $e ) { $threw = true; ob_end_clean(); }
check( 'screen: checking a feed needs a valid nonce', $threw );
$GLOBALS['bad_nonce'] = false;

// ---- Confirm handler ----
$loaded = PodPub_Adopt::load( $OLD ); $plan = PodPub_Adopt::plan( $loaded ); $hash = $plan['report']['hash'];
$GLOBALS['can'] = false;
$threw = false; try { confirm( array( 'podpub_source' => $OLD, 'podpub_hash' => $hash, 'podpub_confirm' => '1' ) ); } catch ( Exception $e ) { $threw = ( 'die 403' === $e->getMessage() ); }
check( 'confirm: refused without the capability', $threw && array() === get_posts( array( 'post_type' => 'podpub_feed' ) ) );
$GLOBALS['can'] = true;
$GLOBALS['bad_nonce'] = true;
$threw = false; try { confirm( array( 'podpub_source' => $OLD, 'podpub_hash' => $hash, 'podpub_confirm' => '1' ) ); } catch ( Exception $e ) { $threw = true; }
check( 'confirm: refused with a bad nonce', $threw && array() === get_posts( array( 'post_type' => 'podpub_feed' ) ) );
$GLOBALS['bad_nonce'] = false;
$to = confirm( array( 'podpub_source' => $OLD, 'podpub_hash' => $hash ) );
$n  = get_transient( 'podpub_notice_1' );
check( 'confirm: without the tick nothing is adopted, and the owner is told', false !== strpos( $to, 'action=import' ) && ! empty( $n['adopt_error'] ) && array() === get_posts( array( 'post_type' => 'podpub_feed' ) ) );
delete_transient( 'podpub_notice_1' );
$to = confirm( array( 'podpub_source' => $OLD, 'podpub_hash' => 'deadbeef', 'podpub_confirm' => '1' ) );
$n  = get_transient( 'podpub_notice_1' );
check( 'confirm: a wrong hash adopts nothing and says to check again', ! empty( $n['adopt_error'] ) && false !== strpos( $n['adopt_error'], 'Nothing was changed' ) && array() === get_posts( array( 'post_type' => 'podpub_feed' ) ) );
delete_transient( 'podpub_notice_1' );
$to = confirm( array( 'podpub_source' => $OLD, 'podpub_hash' => $hash, 'podpub_confirm' => '1' ) );
$n  = get_transient( 'podpub_notice_1' );
$pods = get_posts( array( 'post_type' => 'podpub_feed' ) );
check( 'confirm: with the right hash and tick the podcast is created and the owner goes to its page', 1 === count( $pods ) && false !== strpos( $to, 'podpublisher-feed-' . $pods[0]->ID ) && ! empty( $n['adopted'] ) && $n['adopted']['written'] );
check( 'confirm: the old feed is now written by PodPublisher at the same address, with a backup', file_exists( $OLD . '.bak' ) && false !== strpos( file_get_contents( $OLD ), 'PodPublisher' ) );

$pid = $pods[0]->ID;
$h = ''; ob_start(); PodPub_Admin::render_feed( $pid ); $h = ob_get_clean();
check( 'podcast page: shows the adopted notice and the kept feed address', false !== strpos( $h, 'Feed adopted' ) && false !== strpos( $h, esc_html( $OLDURL ) ) );

// ---- Write feed anyway ----
file_put_contents( $OLD, str_replace( '</channel>', '<podcast:mystery foo="bar"/></channel>', file_get_contents( $OLD ) ) );
ob_start(); PodPub_Admin::render_feed( $pid ); $h = ob_get_clean();
check( 'held: just opening the podcast page (no save) shows the warning and a Write feed anyway button', false !== strpos( $h, 'Check before saving' ) && false !== strpos( $h, 'mystery' ) && false !== strpos( $h, 'value="podpub_write_feed_anyway"' ) && false !== strpos( $h, 'Write feed anyway</button>' ) );
$GLOBALS['can'] = false;
$threw = false; try { $_POST = array( 'podpub_id' => $pid ); PodPub_Admin::handle_write_feed_anyway(); } catch ( Exception $e ) { $threw = 'die 403' === $e->getMessage(); }
$GLOBALS['can'] = true;
check( 'held: Write feed anyway needs the capability', $threw && false !== strpos( file_get_contents( $OLD ), 'mystery' ) );
$_POST = array( 'podpub_id' => $pid );
try { PodPub_Admin::handle_write_feed_anyway(); } catch ( Redirect $r ) { $to = $r->to; }
check( 'held: Write feed anyway backs up (the old file is rotated into .bak), writes, and returns to the podcast page', false === strpos( file_get_contents( $OLD ), 'mystery' ) && file_exists( $OLD . '.bak2' ) && false !== strpos( $to, 'podpublisher-feed-' . $pid ) && ! empty( get_transient( 'podpub_notice_1' )['written'] ) );
ob_start(); PodPub_Admin::render_feed( $pid ); $h = ob_get_clean();
check( 'held: the warning is gone afterwards', false === strpos( $h, 'Write feed anyway</button>' ) );

// ---- Pending notice and episode buttons (TODO 24) ----
$dd = PodPub_Details::get( $pid ); $dd['description'] = 'Stored but not yet written.'; PodPub_Details::save( $pid, $dd );
ob_start(); PodPub_Admin::render_feed( $pid ); $h = ob_get_clean();
check( 'pending: opening the podcast shows that stored data is not in the feed yet', false !== strpos( $h, 'notice-info' ) && false !== strpos( $h, 'not in the feed file yet' ) );
$_GET = array( 'episode' => 'new' );
ob_start(); PodPub_Admin::render_feed( $pid ); $h = ob_get_clean();
check( 'episode form: the button just says Save episode, and the pending notice links to the podcast page', false !== strpos( $h, 'Save episode</button>' ) && false === strpos( $h, 'Save episode and write feed' ) && false !== strpos( $h, 'Go to the podcast page' ) );
$_GET = array();
ob_start(); PodPub_Admin::render_feed( $pid ); $h = ob_get_clean();
check( 'podcast forms: Save and write feed is still there', false !== strpos( $h, 'Save and write feed</button>' ) );
PodPub_FeedXml::write( $pid );
ob_start(); PodPub_Admin::render_feed( $pid ); $h = ob_get_clean();
check( 'pending: gone once the feed is written', false === strpos( $h, 'not in the feed file yet' ) );

echo $fail ? "\nSOME FAILED\n" : "\nall ok\n";
exec( 'rm -rf ' . escapeshellarg( $tmp ) );
exit( $fail );
