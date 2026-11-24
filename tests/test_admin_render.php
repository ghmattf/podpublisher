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
function wp_safe_remote_head() { return new WP_Error( "x", "offline" ); }
function wp_max_upload_size() { return 67108864; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html__( $s ) { return $s; }
function admin_url( $p = '' ) { return 'https://example.com/wp-admin/' . $p; }
function add_query_arg( $a, $u, $v = null ) { if ( ! is_array( $a ) ) { $a = array( $a => $u ); $u = $v; } return $u . ( false === strpos( $u, '?' ) ? '?' : '&' ) . http_build_query( $a ); }
function wp_nonce_field() { echo '<input type="hidden" name="_wpnonce" value="n">'; }
function submit_button( $t = 'Save', $type = 'primary', $name = 'submit', $wrap = true ) { echo '<button class="' . $type . '">' . esc_html( $t ) . '</button>'; }
function selected( $a, $b, $e = true ) { return (string) $a === (string) $b ? ' selected' : ''; }
function checked( $a, $b, $e = true ) { return (string) $a === (string) $b ? ' checked' : ''; }
function current_user_can() { return ! isset( $GLOBALS['can'] ) || $GLOBALS['can']; }
class Stop extends Exception { public $p; }
function wp_send_json_success( $d ) { $e = new Stop( 'ok' ); $e->p = array( 'success' => true, 'data' => $d ); throw $e; }
function wp_send_json_error( $d, $c = 200 ) { $e = new Stop( 'err' ); $e->p = array( 'success' => false, 'data' => $d, 'code' => $c ); throw $e; }
function check_ajax_referer( $a, $k ) { if ( ! empty( $GLOBALS['bad_nonce'] ) ) { $e = new Stop( 'nonce' ); $e->p = array( 'success' => false, 'nonce' => true ); throw $e; } return true; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function home_url() { return 'https://example.com'; }
function wp_safe_remote_get( $u, $a = array() ) { $GLOBALS['http'][] = $u; return array( 'code' => 200, 'body' => '[{"lat":"1.5","lon":"2.5","name":"Testville","osm_type":"node","osm_id":7,"address":{"country":"Testland","country_code":"tl"}}]' ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
function get_current_user_id() { return 1; }
function get_transient( $k ) { return isset( $GLOBALS['trans'][ $k ] ) ? $GLOBALS['trans'][ $k ] : false; }
function set_transient( $k, $v ) { $GLOBALS['trans'][ $k ] = $v; }
function delete_transient( $k ) { unset( $GLOBALS['trans'][ $k ] ); }
function wp_unslash( $v ) { return $v; }
function settings_errors() {}
function settings_fields() {}
function add_settings_error( $a, $b, $m, $t = '' ) { $GLOBALS['setting_errors'][] = $m; }
function wp_create_nonce( $a = '' ) { return 'nonce_' . $a; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function add_action() {}
class WP_Error { public $m; function __construct( $c, $m ) { $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }

foreach ( array( 'settings', 'storage', 'p20', 'images', 'geo', 'details', 'episodes', 'feedxml', 'feeds', 'admin' ) as $c ) { require __DIR__ . "/../plugin/includes/class-podpub-$c.php"; }

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
check( 'podcast page: delete podcast form, files box not ticked by default, confirmation text', false !== strpos( $h, 'value="podpub_delete_podcast"' ) && false !== strpos( $h, 'name="podpub_delete_files" value="1">' ) && false === strpos( $h, 'podpub_delete_files" value="1" checked' ) && false !== strpos( $h, 'onsubmit="return confirm(' ) && false !== strpos( $h, 'This cannot be undone' ) );

PodPub_Feeds::save_details( 5, array( 'title' => 'My Show', 'description' => 'About', 'language' => 'en', 'explicit' => 'no' ) );
$_GET['episode'] = 'new';
$h = page( 5 );
check( 'new episode form: fields, upload, status, no delete button', false !== strpos( $h, 'value="podpub_save_episode"' ) && false !== strpos( $h, 'name="podpub_audio"' ) && false !== strpos( $h, 'name="podpub_ep[published]"' ) && false !== strpos( $h, 'datetime-local' ) && false === strpos( $h, 'podpub_delete_episode' ) );
check( 'new episode form: shows the server upload limit and the allowed types', false !== strpos( $h, '67108864 B' ) && false !== strpos( $h, 'MP3, M4A, OGG, OPUS, WAV, FLAC' ) );

$r = PodPub_Episodes::save( 5, 0, array( 'title' => 'First <b>One</b>', 'description' => 'D', 'published' => '2026-01-15T09:30', 'status' => 'draft', 'duration' => '12:34', 'season' => '1', 'episode' => '3' ) );
$eid = $r['id'];
unset( $_GET['episode'] );
$h = page( 5 );
check( 'podcast page: episode listed with date and status', false !== strpos( $h, 'First One' ) && false !== strpos( $h, '2026-01-15T09:30' ) && false !== strpos( $h, 'Draft' ) && false !== strpos( $h, "episode=$eid" ) );
$r2 = PodPub_Episodes::save( 5, 0, array( 'title' => 'Hosted Elsewhere', 'description' => 'D', 'published' => '2026-01-16T09:30', 'status' => 'published', 'audio_source' => 'external', 'audio_url' => 'https://cdn.example.net/x.mp3', 'audio_size' => '1000', 'duration' => '1:00' ) );
$h = page( 5 );
check( 'podcast page: published episode with an https audio address is not labelled "no audio"', $r2['errors'] === array() && false !== strpos( $h, 'Hosted Elsewhere' ) && false === strpos( $h, 'Published (no audio)' ) );
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
$h = page( 5 );
check( 'episode form: chapters, transcripts, soundbites, alternate files and comments sections', false !== strpos( $h, 'name="podpub_ep[chapters][0][start]"' ) && false !== strpos( $h, 'name="podpub_ep[chapters_url]"' ) && false !== strpos( $h, 'name="podpub_ep[transcripts][0][type]"' ) && false !== strpos( $h, 'text/vtt' ) && false !== strpos( $h, 'name="podpub_ep[soundbites][0][duration]"' ) && false !== strpos( $h, 'name="podpub_ep[enclosures][0][default]"' ) && false !== strpos( $h, 'name="podpub_ep[social][0][protocol]"' ) );
check( 'episode form: subtitle, summary, keywords, explicit select, picture and transcript upload', false !== strpos( $h, 'name="podpub_ep[subtitle]"' ) && false !== strpos( $h, 'name="podpub_ep[summary]"' ) && false !== strpos( $h, 'name="podpub_ep[keywords]"' ) && false !== strpos( $h, '<option value="" selected>Same as the podcast</option>' ) && false !== strpos( $h, 'name="podpub_ep_image"' ) && false !== strpos( $h, 'name="podpub_transcript"' ) && false !== strpos( $h, 'name="podpub_chapters_file"' ) && false !== strpos( $h, 'name="podpub_ep[enclosures][0][integrity_type]"' ) );
check( 'episode form: people role and group are drop-down lists from the taxonomy', false !== strpos( $h, '<select name="podpub_ep[persons][0][role]">' ) && false !== strpos( $h, '<option value="host">Host</option>' ) && false !== strpos( $h, '<option value="cover art designer">Cover Art Designer</option>' ) && false !== strpos( $h, '<select name="podpub_ep[persons][0][group]"><option value="" selected>Automatic (from the role)</option>' ) && false !== strpos( $h, '<option value="audio post-production">Audio Post-Production</option>' ) && false === strpos( $h, 'name="podpub_ep[persons][0][role]" value' ) );
unset( $_GET['episode'] );
$h = page( 5 );
check( 'podcast page: complete, locked and comments sections, no text records', false !== strpos( $h, 'name="podpub_p20[complete]"' ) && false !== strpos( $h, 'name="podpub_p20[locked]"' ) && false !== strpos( $h, 'name="podpub_p20[locked_owner]"' ) && false === strpos( $h, 'podpub_p20[txt]' ) && false !== strpos( $h, 'name="podpub_p20[social][0][uri]"' ) && false === strpos( $h, 'podpub_p20[chapters]' ) );
$blank = PodPub_Feeds::create( 'Blank Show' );
$hb    = page( $blank );
check( 'podcast page: OP3 tick box present and off, no statistics link yet', false !== strpos( $h, 'name="podpub[op3]"' ) && false === strpos( $h, 'checked=\'checked\'> Count downloads' ) && false === strpos( $h, 'op3.dev/show/' ) && false !== strpos( $h, 'Count downloads with OP3' ) );
check( 'podcast page: explicit is a required yes/no select; a new podcast starts on Choose, a saved one shows its answer', false !== strpos( $hb, '<select id="pp_explicit" name="podpub[explicit]" required><option value="" selected>Choose&hellip;</option><option value="yes">Yes</option><option value="no">No</option>' ) && false !== strpos( $h, '<option value="no" selected>No</option>' ) && false !== strpos( $h, 'This feed contains explicit content' ) && false !== strpos( $h, 'name="podpub[keywords]"' ) && false !== strpos( $h, 'managing editor' ) );
check( 'podcast page: trailers and live episodes with date pickers and a status list', false !== strpos( $h, 'name="podpub_p20[trailers][0][pubdate]"' ) && false !== strpos( $h, '<input type="datetime-local" name="podpub_p20[live][0][start]"' ) && false !== strpos( $h, '<select name="podpub_p20[live][0][status]">' ) && false !== strpos( $h, '<option value="pending">Pending</option>' ) );
check( 'podcast page: add-episode link, and the copy link once there is an episode', false !== strpos( $h, 'class="page-title-action">Add episode</a>' ) && false !== strpos( $h, 'Add episode, copying details from the latest' ) && false !== strpos( $h, 'copy=1' ) );
$_GET['episode'] = (string) $eid;
$h = page( 5 );
check( 'audio: shows the episode folder to copy files into, and that none are there yet', false !== strpos( $h, "podpublisher/$pdir/First_One</code>" ) && false !== strpos( $h, 'No audio files found' ) );
check( 'audio: hosted-elsewhere fields', false !== strpos( $h, 'name="podpub_ep[audio_url]"' ) && false !== strpos( $h, 'name="podpub_ep[audio_size]"' ) && false !== strpos( $h, 'name="podpub_ep[audio_ext_type]"' ) );
check( 'audio: one group each for upload, folder and external', 1 === substr_count( $h, '<div data-podpub-src="upload">' ) && 1 === substr_count( $h, '<div data-podpub-src="folder">' ) && 1 === substr_count( $h, '<div data-podpub-src="external">' ) );
file_put_contents( "$tmp/uploads/podpublisher/$pdir/First_One/Raw Take.mp3", "ID3" . str_repeat( "\0", 30 ) );
$h = page( 5 );
check( 'audio: a copied-in file is listed, with a note when it will be renamed', false !== strpos( $h, 'value="Raw Take.mp3"' ) && false !== strpos( $h, 'will be renamed' ) && false === strpos( $h, 'No audio files found' ) );
check( 'audio: a warning lists the old and new names and a Rename it box must be ticked', false !== strpos( $h, 'These file names have spaces or special characters' ) && false !== strpos( $h, 'Raw_Take.mp3' ) && false !== strpos( $h, 'name="podpub_ep[rename_ok]"' ) && false !== strpos( $h, 'anything linking to it will break' ) );
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
// Podcasting 2.0 forms.
unset( $_GET['episode'] );
PodPub_Feeds::save_p20( 5, array(
	'funding' => array( array( 'url' => 'https://example.com/donate', 'message' => 'Fund "us"' ) ),
	'locations' => array( array( 'name' => 'Melbourne', 'rel' => 'creator' ) ), 'persons' => array( array( 'name' => 'Jo', 'role' => 'host' ) ),
	'recipients' => array( array( 'name' => 'Me', 'address' => 'me@example.com', 'split' => '100', 'fee' => '1' ) ),
	'block' => array( array( 'id' => 'google', 'value' => 'yes' ) ), 'podroll' => array( array( 'feed_guid' => '917393e3-1b1e-5cef-ace4-edaa54e1f810' ) ),
) );
$h = page( 5 );
check( 'p20 form on the podcast page: own form, action and nonce', false !== strpos( $h, 'value="podpub_save_p20"' ) && false !== strpos( $h, '<h2>Podcasting 2.0</h2>' ) );
check( 'p20 form: every podcast-level section', false !== strpos( $h, '<h3>Funding</h3>' ) && false !== strpos( $h, '<h3>Location</h3>' ) && false !== strpos( $h, '<h3>People</h3>' ) && false !== strpos( $h, '<h3>License</h3>' ) && false !== strpos( $h, '<h3>Payments (value)</h3>' ) && false !== strpos( $h, '<h3>Block</h3>' ) && false !== strpos( $h, '<h3>Podroll</h3>' ) );
check( 'p20 form: podcast page has no time splits', false === strpos( $h, 'Value time splits' ) );
check( 'p20 form: saved values shown, quote escaped', false !== strpos( $h, 'name="podpub_p20[funding][0][url]" value="https://example.com/donate"' ) && false !== strpos( $h, 'value="Fund &quot;us&quot;"' ) && false !== strpos( $h, 'name="podpub_p20[locations][0][name]" value="Melbourne"' ) );
check( 'p20 form: three spare empty rows after the saved one', false !== strpos( $h, 'name="podpub_p20[funding][3][url]"' ) && false === strpos( $h, 'name="podpub_p20[funding][4][url]"' ) );
check( 'p20 form: Lightning address recipient with fee ticked, and no keysend, node or custom record fields', false !== strpos( $h, 'name="podpub_p20[recipients][0][fee]" value="1" checked' ) && false !== strpos( $h, 'name="podpub_p20[recipients][0][address]" value="me@example.com"' ) && false === stripos( $h, 'keysend' ) && false === strpos( $h, 'value_method' ) && false === strpos( $h, '[custom_key]' ) && false === strpos( $h, '<option value="node"' ) );
check( 'p20 form: block and podroll rows', false !== strpos( $h, 'name="podpub_p20[block][0][id]" value="google"' ) && false !== strpos( $h, 'name="podpub_p20[podroll][0][feed_guid]" value="917393e3-1b1e-5cef-ace4-edaa54e1f810"' ) );

$_GET['episode'] = (string) $eid;
$h = page( 5 );
check( 'p20 on the episode form: same sections plus time splits, no block or podroll', false !== strpos( $h, '<h3>Funding</h3>' ) && false !== strpos( $h, '<h3>People</h3>' ) && false !== strpos( $h, 'Value time splits' ) && false !== strpos( $h, 'name="podpub_ep[timesplits][0][feed_guid]"' ) && false === strpos( $h, '<h3>Block</h3>' ) && false === strpos( $h, '<h3>Podroll</h3>' ) );
check( 'p20 on the episode form: the time splits import box (paste or file, replace or add) is there', false !== strpos( $h, 'name="podpub_ep[splits_import]"' ) && false !== strpos( $h, 'name="podpub_splits_file"' ) && false !== strpos( $h, 'name="podpub_ep[splits_mode]"' ) && false !== strpos( $h, 'Import time splits' ) );
check( 'p20 on the episode form: is part of the episode form, using its own field names', false !== strpos( $h, 'name="podpub_ep[funding][0][url]"' ) && false !== strpos( $h, 'name="podpub_ep[value_suggested]"' ) && false !== strpos( $h, 'Leave this empty to use the podcast' ) );

// After an error the Podcasting 2.0 form is refilled with what was typed.
unset( $_GET['episode'] );
set_transient( 'podpub_notice_1', array( 'errors' => array( 'Location: the coordinates must be latitude,longitude' ), 'warnings' => array(), 'written' => false, 'form' => 'p20', 'input' => array( 'locations' => array( array( 'name' => 'Typed place', 'geo' => '999,999' ) ), 'funding' => array( array( 'url' => 'https://typed.example', 'message' => 'Typed msg' ) ) ) ) );
$h = page( 5 );
check( 'p20 error: message shown, typed values kept instead of the saved ones', false !== strpos( $h, 'Location: the coordinates must be' ) && false !== strpos( $h, 'value="Typed place"' ) && false !== strpos( $h, 'value="999,999"' ) && false !== strpos( $h, 'value="Typed msg"' ) && false === strpos( $h, 'value="Melbourne"' ) );
set_transient( 'podpub_notice_1', array( 'errors' => array( 'Something else' ), 'warnings' => array(), 'written' => false, 'input' => array( 'title' => 'x' ) ) );
check( 'a different form\'s error does not refill the p20 form', false !== strpos( page( 5 ), 'value="Melbourne"' ) );

// New episode: starting fresh carries the season and number on; copying also keeps people, funding and so on.
PodPub_Episodes::save( 5, $eid, array( 'title' => 'First <b>One</b>', 'description' => 'D', 'published' => '2026-03-01T09:30', 'status' => 'draft', 'duration' => '12:34', 'season' => '1', 'episode' => '3', 'explicit' => 'yes', 'persons' => array( array( 'name' => 'Pat', 'role' => 'guest' ) ) ) );
$_GET['episode'] = 'new';
$h = page( 5 );
check( 'new episode, fresh: says so, season carried on and the next number filled in, nothing copied', false !== strpos( $h, 'Starting empty' ) && false !== strpos( $h, 'name="podpub_ep[season]" value="1"' ) && false !== strpos( $h, 'name="podpub_ep[episode]" value="4"' ) && false === strpos( $h, 'value="Pat"' ) );
$_GET['copy'] = '1';
$h = page( 5 );
check( 'new episode, copied: says so, keeps the title, season and people, next number filled in, explicit left blank', false !== strpos( $h, 'Started from the latest episode' ) && false !== strpos( $h, 'name="podpub_ep[season]" value="1"' ) && false !== strpos( $h, 'name="podpub_ep[episode]" value="4"' ) && false !== strpos( $h, 'value="Pat"' ) && false === strpos( $h, '<option value="yes" selected>' ) && false !== strpos( $h, 'name="podpub_ep[title]" value="First One"' ) );
unset( $_GET['copy'], $_GET['episode'] );

$h = page( 77 );
check( 'unknown podcast says not found', false !== strpos( $h, 'Podcast not found' ) );


// List page after a podcast was deleted: says what happened, once.
$GLOBALS['trans']['podpub_notice_1'] = array( 'errors' => array(), 'warnings' => array( 'Some files could not be deleted.' ), 'episodes' => 3, 'files_deleted' => false, 'folder' => '/x/My_Show', 'deleted_podcast' => true );
ob_start(); PodPub_Admin::render_list(); $h = ob_get_clean();
check( 'list page: delete notice (files left, feed keeps working) and warning', false !== strpos( $h, 'Podcast deleted (3 episodes removed)' ) && false !== strpos( $h, 'files were left on the server in /x/My_Show' ) && false !== strpos( $h, 'Some files could not be deleted.' ) );
ob_start(); PodPub_Admin::render_list(); $h = ob_get_clean();
check( 'list page: delete notice is shown only once', false === strpos( $h, 'Podcast deleted' ) );
$GLOBALS['trans']['podpub_notice_1'] = array( 'errors' => array(), 'warnings' => array(), 'episodes' => 1, 'files_deleted' => true, 'folder' => '/x/My_Show', 'deleted_podcast' => true );
ob_start(); PodPub_Admin::render_list(); $h = ob_get_clean();
check( 'list page: delete notice (files deleted, one episode)', false !== strpos( $h, 'Podcast deleted (1 episode removed)' ) && false !== strpos( $h, 'folder and files were deleted too' ) );

// Location search box (0.11.0): on the podcast page and the episode form, switchable off, escaped.
ob_start(); PodPub_Admin::render_feed( 5 ); $h = ob_get_clean();
check( 'location search: box above the Location rows with the rows name, ajax address and nonce', false !== strpos( $h, 'class="podpub-geo" data-rows="podpub_p20[locations]"' ) && false !== strpos( $h, 'data-ajax="https://example.com/wp-admin/admin-ajax.php"' ) && false !== strpos( $h, 'data-nonce="nonce_podpub_geo"' ) );
check( 'location search: Search button, 120 character limit, OpenStreetMap credit, script posts the right action', false !== strpos( $h, 'podpub-geo-go">Search</button>' ) && false !== strpos( $h, 'maxlength="120"' ) && false !== strpos( $h, 'OpenStreetMap contributors' ) && false !== strpos( $h, 'podpub_geo_search' ) );
check( 'location search: results go on the page as text, never as HTML', false === strpos( $h, 'innerHTML' ) && false !== strpos( $h, 'textContent' ) );
check( 'location search: the OSM id box says an openstreetmap.org address can be pasted', false !== strpos( $h, 'R2316741 or an openstreetmap.org address' ) );
$GLOBALS['opts']['podpub_settings'] = array( 'geo_enabled' => 0 );
ob_start(); PodPub_Admin::render_feed( 5 ); $h = ob_get_clean();
check( 'location search: switched off in Settings, no box and no script (rows still there)', false === strpos( $h, 'class="podpub-geo"' ) && false === strpos( $h, 'podpub_geo_search' ) && false !== strpos( $h, 'podpub_p20[locations][0][name]' ) );
$GLOBALS['opts']['podpub_settings'] = array();
ob_start(); PodPub_Admin::render_settings(); $h = ob_get_clean();
check( 'settings: location search tick box (on by default) and service address with the public default shown', false !== strpos( $h, 'name="podpub_settings[geo_enabled]" value="1" checked' ) && false !== strpos( $h, 'name="podpub_settings[geo_url]"' ) && false !== strpos( $h, 'placeholder="https://nominatim.openstreetmap.org"' ) && false !== strpos( $h, 'one request per second' ) );
$s = PodPub_Admin::sanitize_settings( array( 'geo_enabled' => '1', 'geo_url' => 'https://geo.example.org/' ) );
check( 'settings: a valid https service address is kept (trailing slash dropped)', $s['geo_url'] === 'https://geo.example.org' && $s['geo_enabled'] === 1 );
$GLOBALS['opts']['podpub_settings'] = array( 'geo_url' => 'https://old.example.org' );
$s = PodPub_Admin::sanitize_settings( array( 'geo_enabled' => '1', 'geo_url' => 'http://plain.example.org' ) );
check( 'settings: an http:// service address is refused and the old one kept', $s['geo_url'] === 'https://old.example.org' );
$s = PodPub_Admin::sanitize_settings( array( 'geo_url' => '' ) );
check( 'settings: empty address = public default; unticked box = off', $s['geo_url'] === '' && $s['geo_enabled'] === 0 );
$GLOBALS['opts']['podpub_settings'] = array();

// Location search handler: administrators only, nonce checked, answer as JSON.
function geo_call( $q ) { $_POST = array( 'q' => $q ); try { PodPub_Admin::handle_geo_search(); } catch ( Stop $e ) { return $e->p; } return null; }
$GLOBALS['trans'] = array(); $GLOBALS['http'] = array();
$GLOBALS['can'] = false;
$r = geo_call( 'Testville' );
check( 'search handler: a user who cannot manage podcasts is refused (403) and nothing is fetched', $r['success'] === false && $r['code'] === 403 && $GLOBALS['http'] === array() );
$GLOBALS['can'] = true; $GLOBALS['bad_nonce'] = true;
$r = geo_call( 'Testville' );
check( 'search handler: a bad or missing nonce is refused and nothing is fetched', ! empty( $r['nonce'] ) && $GLOBALS['http'] === array() );
$GLOBALS['bad_nonce'] = false;
$r = geo_call( 'Testville' );
check( 'search handler: success returns the rows to fill in', $r['success'] === true && $r['data']['results'][0]['name'] === 'Testville, Testland' && $r['data']['results'][0]['geo'] === '1.5,2.5' && $r['data']['results'][0]['osm'] === 'N7' && $r['data']['results'][0]['country'] === 'TL' );
$GLOBALS['trans'] = array(); $GLOBALS['http'] = array();
$r = geo_call( 'ab' );
check( 'search handler: too short gives a message, no fetch', $r['success'] === false && false !== strpos( $r['data']['message'], 'between 3 and 120' ) && $GLOBALS['http'] === array() );
$_POST = array( 'q' => array( 'x' ) );
try { PodPub_Admin::handle_geo_search(); $r = null; } catch ( Stop $e ) { $r = $e->p; }
check( 'search handler: an array instead of text is treated as empty', $r['success'] === false );

$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { $f->isDir() ? rmdir( $f ) : unlink( $f ); }
rmdir( $tmp );
exit( $fail );
