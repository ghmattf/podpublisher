<?php
// Chunk D tags: chapters (editor writes chapters.json), transcripts, soundbites, alternate enclosures,
// cross-app comments, and the channel complete / locked tags.
// Not a real WordPress: the few WordPress functions used are stood in for.
define( 'ABSPATH', __DIR__ );
define( 'PODPUB_VERSION', 'test' );
$tmp = sys_get_temp_dir() . '/podpub_pd_' . getmypid();
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

function wp_safe_remote_head() { return new WP_Error( 'x', 'offline' ); }
function feed_dom( $file ) { $d = new DOMDocument(); $d->load( $file ); $x = new DOMXPath( $d ); $x->registerNamespace( 'podcast', 'https://podcastindex.org/namespace/1.0' ); return $x; }
function ev( $x, $q ) { return $x->evaluate( $q ); }

// ---- transcripts ----
$e = array(); $r = PodPub_P20::transcripts( array( 'transcripts' => array( array( 'url' => 'https://x.example/t.vtt', 'type' => 'text/vtt', 'language' => 'pt-BR', 'rel' => 'Captions' ), array( 'url' => '' ) ) ), $e );
check( 'transcript: good row kept, rel lower-cased, blank dropped', $e === array() && count( $r ) === 1 && $r[0]['rel'] === 'captions' );
foreach ( array( 'http address' => array( 'url' => 'http://x.example/t.txt', 'type' => 'text/plain' ), 'no type' => array( 'url' => 'https://x.example/t' ), 'odd type' => array( 'url' => 'https://x.example/t', 'type' => 'application/pdf' ), 'bad language' => array( 'url' => 'https://x.example/t', 'type' => 'text/plain', 'language' => 'english!' ), 'bad rel' => array( 'url' => 'https://x.example/t', 'type' => 'text/plain', 'rel' => 'x' ) ) as $l => $row ) {
	$e = array(); PodPub_P20::transcripts( array( 'transcripts' => array( $row ) ), $e ); check( "transcript: $l refused", count( $e ) === 1 );
}

// ---- soundbites ----
$e = array(); $r = PodPub_P20::soundbites( array( 'soundbites' => array( array( 'start' => '73', 'duration' => '60.5', 'title' => 'Why' ), array( 'start' => '1234.5', 'duration' => '42' ) ) ), $e );
check( 'soundbite: seconds with decimals and optional title', $e === array() && count( $r ) === 2 && $r[0]['duration'] === '60.5' && $r[1]['title'] === '' );
foreach ( array( 'minutes format' => array( 'start' => '1:13', 'duration' => '60' ), 'zero length' => array( 'start' => '5', 'duration' => '0' ), 'missing length' => array( 'start' => '5' ), 'negative' => array( 'start' => '-5', 'duration' => '10' ) ) as $l => $row ) {
	$e = array(); PodPub_P20::soundbites( array( 'soundbites' => array( $row ) ), $e ); check( "soundbite: $l refused", count( $e ) === 1 );
}

// ---- alternate enclosures ----
$e = array(); $r = PodPub_P20::enclosures( array( 'enclosures' => array( array( 'uri' => 'https://cdn.example/v.mp4', 'type' => 'video/mp4', 'length' => '12345', 'height' => '720', 'default' => '1' ) ) ), $e );
check( 'alternate file: good row kept', $e === array() && $r[0]['type'] === 'video/mp4' && $r[0]['default'] === '1' );
foreach ( array( 'http address' => array( 'uri' => 'http://c.example/v.mp4', 'type' => 'video/mp4' ), 'bad type' => array( 'uri' => 'https://c.example/v', 'type' => 'video' ), 'size not a number' => array( 'uri' => 'https://c.example/v', 'type' => 'video/mp4', 'length' => '12MB' ) ) as $l => $row ) {
	$e = array(); PodPub_P20::enclosures( array( 'enclosures' => array( $row ) ), $e ); check( "alternate file: $l refused", count( $e ) === 1 );
}

// ---- comments ----
$e = array(); $r = PodPub_P20::social( array( 'social' => array( array( 'protocol' => 'ActivityPub', 'uri' => 'https://social.example/@a/1', 'account_id' => '@a@social.example', 'priority' => '1' ), array( 'protocol' => 'disabled', 'uri' => 'https://ignored.example' ) ) ), $e );
check( 'comments: protocol lower-cased; disabled drops everything else', $e === array() && $r[0]['protocol'] === 'activitypub' && $r[1] === array( 'protocol' => 'disabled', 'uri' => '', 'account_id' => '', 'account_url' => '', 'priority' => '' ) );
$e = array(); PodPub_P20::social( array( 'social' => array( array( 'protocol' => 'activitypub' ) ) ), $e ); check( 'comments: address required unless disabled', count( $e ) === 1 );
$e = array(); PodPub_P20::social( array( 'social' => array( array( 'protocol' => 'activitypub', 'uri' => 'https://a.example/1', 'priority' => 'first' ) ) ), $e ); check( 'comments: priority must be a number', count( $e ) === 1 );

// ---- chapters ----
$e = array(); $c = PodPub_P20::chapters( array( 'chapters' => array( array( 'start' => '1:00', 'title' => 'Second' ), array( 'start' => '0', 'title' => 'Intro', 'img' => 'https://i.example/a.jpg', 'url' => 'https://u.example' ) ) ), $e );
check( 'chapters: sorted by start time, m:ss understood', $e === array() && $c['rows'][0]['title'] === 'Intro' && $c['rows'][1]['start'] === 60 );
$j = json_decode( PodPub_P20::chapters_json( $c['rows'] ), true );
check( 'chapters: json file has version, startTime, title, and img/url only where set', $j['version'] === '1.2.0' && $j['chapters'][0] === array( 'startTime' => 0, 'title' => 'Intro', 'img' => 'https://i.example/a.jpg', 'url' => 'https://u.example' ) && $j['chapters'][1] === array( 'startTime' => 60, 'title' => 'Second' ) );
$e = array(); PodPub_P20::chapters( array( 'chapters' => array( array( 'start' => 'soon', 'title' => 'X' ) ) ), $e ); check( 'chapters: bad start time refused', count( $e ) === 1 );
$e = array(); PodPub_P20::chapters( array( 'chapters' => array( array( 'start' => '0', 'title' => '' ) ) ), $e ); check( 'chapters: a start with no title refused', count( $e ) === 1 );
$e = array(); PodPub_P20::chapters( array( 'chapters' => array( array( 'start' => '0', 'title' => 'A' ) ), 'chapters_url' => 'https://h.example/c.json' ), $e ); check( 'chapters: list and outside address together refused', count( $e ) === 1 );
$e = array(); $c = PodPub_P20::chapters( array( 'chapters_url' => 'http://h.example/c.json' ), $e ); check( 'chapters: http address refused', count( $e ) === 1 && $c['url'] === '' );

// ---- channel flags ----
$e = array(); $d = PodPub_P20::channel_flags( array( 'complete' => '1', 'locked' => 'YES', 'locked_owner' => 'me@example.com' ), PodPub_P20::defaults( false ), $e );
check( 'channel flags: complete, locked with owner kept', $e === array() && $d['complete'] === 1 && $d['locked'] === 'yes' && $d['locked_owner'] === 'me@example.com' );
$e = array(); PodPub_P20::channel_flags( array( 'locked' => 'maybe' ), PodPub_P20::defaults( false ), $e ); check( 'channel flags: locked must be yes, no or unset', count( $e ) === 1 );
$e = array(); PodPub_P20::channel_flags( array( 'locked_owner' => 'nope' ), PodPub_P20::defaults( false ), $e ); check( 'channel flags: bad owner email refused', count( $e ) === 1 );

// ---- saving and the feed ----
$pid = PodPub_Feeds::create( 'Show D' );
$pdir = PodPub_Feeds::dir_name( $pid );
PodPub_Feeds::save_details( $pid, array( 'title' => 'Show D', 'description' => 'About', 'language' => 'en', 'explicit' => 'no' ) );
$r = PodPub_Feeds::save_p20( $pid, array( 'complete' => '1', 'locked' => 'no', 'locked_owner' => 'me@example.com', 'social' => array( array( 'protocol' => 'disabled' ) ) ) );
$x = feed_dom( "$tmp/uploads/podpublisher/$pdir/$pdir.xml" );
check( 'podcast: complete, locked (with owner) and disabled comments in the channel', $r['errors'] === array() && ev( $x, 'string(/rss/channel/podcast:complete)' ) === 'yes' && ev( $x, 'string(/rss/channel/podcast:locked)' ) === 'no' && ev( $x, 'string(/rss/channel/podcast:locked/@owner)' ) === 'me@example.com' && ev( $x, 'string(/rss/channel/podcast:socialInteract/@protocol)' ) === 'disabled' && ev( $x, 'count(/rss/channel/podcast:socialInteract/@*)' ) == 1 );
$r = PodPub_Feeds::save_p20( $pid, array() );
$x = feed_dom( "$tmp/uploads/podpublisher/$pdir/$pdir.xml" );
check( 'podcast: clearing the form removes complete, locked and comments', $r['errors'] === array() && ev( $x, 'count(/rss/channel/podcast:complete | /rss/channel/podcast:locked | /rss/channel/podcast:socialInteract)' ) == 0 );

$ep = array( 'title' => 'Ep D', 'description' => 'D', 'published' => '2026-01-15T09:30', 'status' => 'published', 'audio_source' => 'external', 'audio_url' => 'https://cdn.example.net/one.mp3', 'audio_size' => '1000', 'duration' => '10:00',
	'chapters' => array( array( 'start' => '30', 'title' => 'Later' ), array( 'start' => '0', 'title' => 'Start' ) ),
	'transcripts' => array( array( 'url' => 'https://t.example/ep.srt', 'type' => 'application/x-subrip', 'language' => 'en' ) ),
	'soundbites' => array( array( 'start' => '73', 'duration' => '60', 'title' => 'Best bit' ), array( 'start' => '200', 'duration' => '30' ) ),
	'enclosures' => array( array( 'uri' => 'https://cdn.example.net/ep.mp4', 'type' => 'video/mp4', 'length' => '999', 'height' => '720', 'title' => 'Video', 'default' => '' ) ),
	'social' => array( array( 'protocol' => 'activitypub', 'uri' => 'https://social.example/@a/1', 'account_id' => '@a@social.example', 'priority' => '1' ) ),
);
$r = PodPub_Episodes::save( $pid, 0, $ep );
$eid = $r['id'];
$edir = PodPub_Episodes::dir_name( $eid );
$file = "$tmp/uploads/podpublisher/$pdir/$edir/chapters.json";
$x = feed_dom( "$tmp/uploads/podpublisher/$pdir/$pdir.xml" );
check( 'episode: saved with no errors', $r['errors'] === array() && $eid > 0 );
check( 'episode: chapters.json written in the episode folder, sorted', is_file( $file ) && json_decode( file_get_contents( $file ), true )['chapters'][0]['title'] === 'Start' );
check( 'episode feed: chapters tag points at the file with the chapters type', ev( $x, 'string(//item/podcast:chapters/@url)' ) === "https://example.com/wp-content/uploads/podpublisher/$pdir/$edir/chapters.json" && ev( $x, 'string(//item/podcast:chapters/@type)' ) === 'application/json+chapters' );
check( 'episode feed: transcript', ev( $x, 'string(//item/podcast:transcript/@url)' ) === 'https://t.example/ep.srt' && ev( $x, 'string(//item/podcast:transcript/@type)' ) === 'application/x-subrip' && ev( $x, 'string(//item/podcast:transcript/@language)' ) === 'en' && ev( $x, 'count(//item/podcast:transcript/@rel)' ) == 0 );
check( 'episode feed: soundbites, title only where given', ev( $x, 'count(//item/podcast:soundbite)' ) == 2 && ev( $x, 'string(//item/podcast:soundbite[1])' ) === 'Best bit' && ev( $x, 'string(//item/podcast:soundbite[1]/@startTime)' ) === '73' && ev( $x, 'string(//item/podcast:soundbite[2])' ) === '' );
check( 'episode feed: alternate enclosure with its source, no default attribute when unticked', ev( $x, 'string(//item/podcast:alternateEnclosure/@type)' ) === 'video/mp4' && ev( $x, 'string(//item/podcast:alternateEnclosure/@height)' ) === '720' && ev( $x, 'string(//item/podcast:alternateEnclosure/podcast:source/@uri)' ) === 'https://cdn.example.net/ep.mp4' && ev( $x, 'count(//item/podcast:alternateEnclosure/@default)' ) == 0 );
check( 'episode feed: comments link', ev( $x, 'string(//item/podcast:socialInteract/@protocol)' ) === 'activitypub' && ev( $x, 'string(//item/podcast:socialInteract/@accountId)' ) === '@a@social.example' && ev( $x, 'string(//item/podcast:socialInteract/@priority)' ) === '1' );

// A bad row saves nothing (the chapters file stays as it was).
$bad = array_merge( $ep, array( 'chapters' => array( array( 'start' => '0', 'title' => 'Changed' ) ), 'soundbites' => array( array( 'start' => 'x', 'duration' => '1' ) ) ) );
$r = PodPub_Episodes::save( $pid, $eid, $bad );
check( 'episode: a bad soundbite saves nothing, chapters file unchanged', count( $r['errors'] ) === 1 && json_decode( file_get_contents( $file ), true )['chapters'][0]['title'] === 'Start' );

// An outside chapters file replaces the list and removes the written file.
$r = PodPub_Episodes::save( $pid, $eid, array_merge( $ep, array( 'chapters' => array(), 'chapters_url' => 'https://h.example/ch.json' ) ) );
$x = feed_dom( "$tmp/uploads/podpublisher/$pdir/$pdir.xml" );
check( 'episode: outside chapters address used, local chapters.json removed', $r['errors'] === array() && ! is_file( $file ) && ev( $x, 'string(//item/podcast:chapters/@url)' ) === 'https://h.example/ch.json' );

// Clearing everything removes all the tags.
$r = PodPub_Episodes::save( $pid, $eid, array_diff_key( $ep, array_flip( array( 'chapters', 'transcripts', 'soundbites', 'enclosures', 'social' ) ) ) );
$x = feed_dom( "$tmp/uploads/podpublisher/$pdir/$pdir.xml" );
check( 'episode: clearing the fields removes every chunk D tag', $r['errors'] === array() && ev( $x, 'count(//item/podcast:chapters | //item/podcast:transcript | //item/podcast:soundbite | //item/podcast:alternateEnclosure | //item/podcast:socialInteract)' ) == 0 );

$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { ( $f->isDir() && ! $f->isLink() ) ? rmdir( $f ) : unlink( $f ); }
rmdir( $tmp );
exit( $fail );
