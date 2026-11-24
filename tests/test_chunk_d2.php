<?php
// Chunk D, part 2 and the 0.9.0 batch: explicit select, managing editor, keywords, picture sizes
// (podcast:images), episode extras, trailer, live items, integrity, transcript files, automatic numbers, copy from latest, people drop-downs.
// Not a real WordPress: the few WordPress functions used are stood in for.
define( 'ABSPATH', __DIR__ );
define( 'PODPUB_VERSION', 'test' );
$tmp = sys_get_temp_dir() . '/podpub_d2_' . getmypid();
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

// ---- explicit is a required yes/no choice ----
$base = array( 'title' => 'Show Two', 'description' => 'About', 'language' => 'en', 'owner_name' => 'Jo B', 'owner_email' => 'jo@example.com', 'keywords' => 'music, v4v' );
$pid  = PodPub_Feeds::create( 'Show Two' );
$pdir = PodPub_Feeds::dir_name( $pid );
$r = PodPub_Feeds::save_details( $pid, $base );
check( 'explicit: blank is refused and nothing is saved', count( $r['errors'] ) === 1 && false !== strpos( $r['errors'][0], 'explicit' ) && PodPub_Details::get( $pid )['explicit'] === '' );
$r = PodPub_Feeds::save_details( $pid, array_merge( $base, array( 'explicit' => 'maybe' ) ) );
check( 'explicit: anything but yes or no refused', count( $r['errors'] ) === 1 );
check( 'explicit: default is blank', PodPub_Details::defaults()['explicit'] === '' );
$GLOBALS['meta'][ $pid ]['_podpub_details'] = array_merge( PodPub_Details::defaults(), array( 'explicit' => 1 ) );
check( 'explicit: an old tick box saved as 1 reads as yes, 0 as no', PodPub_Details::get( $pid )['explicit'] === 'yes' && ( $GLOBALS['meta'][ $pid ]['_podpub_details']['explicit'] = 0 ) === 0 && PodPub_Details::get( $pid )['explicit'] === 'no' );
unset( $GLOBALS['meta'][ $pid ]['_podpub_details'] );

// ---- show art: smaller copies, srcset, managing editor, keywords ----
$r = PodPub_Feeds::save_details( $pid, array_merge( $base, array( 'explicit' => 'yes' ) ), $up( $jpg, 'Big Pic.JPG' ) );
$folder = "$tmp/uploads/podpublisher/$pdir";
$d = PodPub_Details::get( $pid );
check( 'show art: saved with no errors', $r['errors'] === array() && $d['image'] === 'cover.jpg' && $d['image_w'] === 1600 );
check( 'show art: copies made at 300, 600 and 1000 wide (1500 too, as the picture is 1600)', array_column( $d['image_sizes'], 'width' ) === array( 300, 600, 1000, 1500 ) && is_file( "$folder/cover-300.jpg" ) && is_file( "$folder/cover-1500.jpg" ) && getimagesize( "$folder/cover-600.jpg" )[0] === 600 && getimagesize( "$folder/cover-600.jpg" )[1] === 600 );
$x = feed_dom( "$folder/$pdir.xml" );
$srcset = ev( $x, 'string(/rss/channel/podcast:images/@srcset)' );
check( 'show art: podcast:images srcset lists every copy and the original last', $srcset === "https://example.com/wp-content/uploads/podpublisher/$pdir/cover-300.jpg 300w, https://example.com/wp-content/uploads/podpublisher/$pdir/cover-600.jpg 600w, https://example.com/wp-content/uploads/podpublisher/$pdir/cover-1000.jpg 1000w, https://example.com/wp-content/uploads/podpublisher/$pdir/cover-1500.jpg 1500w, https://example.com/wp-content/uploads/podpublisher/$pdir/cover.jpg 1600w" );
check( 'channel: explicit true, keywords, managing editor as "email (Name)"', ev( $x, 'string(/rss/channel/itunes:explicit)' ) === 'true' && ev( $x, 'string(/rss/channel/itunes:keywords)' ) === 'music, v4v' && ev( $x, 'string(/rss/channel/managingEditor)' ) === 'jo@example.com (Jo B)' );
$r = PodPub_Feeds::save_details( $pid, array_merge( $base, array( 'explicit' => 'no', 'owner_name' => '' ) ) );
$x = feed_dom( "$folder/$pdir.xml" );
check( 'channel: explicit false; managing editor is just the email without a name', ev( $x, 'string(/rss/channel/itunes:explicit)' ) === 'false' && ev( $x, 'string(/rss/channel/managingEditor)' ) === 'jo@example.com' && is_file( "$folder/cover-300.jpg" ) );
$r = PodPub_Feeds::save_details( $pid, array_merge( $base, array( 'explicit' => 'no', 'owner_email' => '' ) ) );
$x = feed_dom( "$folder/$pdir.xml" );
check( 'channel: no owner email, no managing editor', ev( $x, 'count(/rss/channel/managingEditor)' ) == 0 );
$r = PodPub_Feeds::save_details( $pid, array_merge( $base, array( 'explicit' => 'no' ) ), $up( $png, 'p.png' ) );
$x = feed_dom( "$folder/$pdir.xml" );
check( 'show art: a 500 pixel PNG gets a 300 copy only; the old JPEG copies are removed', array_column( PodPub_Details::get( $pid )['image_sizes'], 'width' ) === array( 300 ) && is_file( "$folder/cover-300.png" ) && ! is_file( "$folder/cover-300.jpg" ) && ! is_file( "$folder/cover.jpg" ) && ev( $x, 'string(/rss/channel/podcast:images/@srcset)' ) !== '' );
$r = PodPub_Feeds::save_details( $pid, array_merge( $base, array( 'explicit' => 'no' ) ), $up( $gif, 'g.gif' ) );
$x = feed_dom( "$folder/$pdir.xml" );
check( 'show art: a GIF is not resized, no srcset, the old copies are gone, and the user is told', PodPub_Details::get( $pid )['image_sizes'] === array() && ! is_file( "$folder/cover-300.png" ) && ev( $x, 'count(/rss/channel/podcast:images)' ) == 0 && ev( $x, 'string(/rss/channel/itunes:image/@href)' ) !== '' && count( array_filter( $r['warnings'], function ( $w ) { return false !== strpos( $w, 'not made' ); } ) ) === 1 );

// ---- trailers ----
$e = array(); $t = PodPub_P20::trailers( array( 'trailers' => array( array( 'title' => 'Season 2 trailer', 'url' => 'https://cdn.example/t.mp3', 'pubdate' => '2026-03-01T10:00', 'length' => '12345', 'type' => 'audio/mpeg', 'season' => '2' ), array( 'title' => '' ) ) ), $e );
check( 'trailer: good row kept (date parsed), blank dropped', $e === array() && count( $t ) === 1 && is_int( $t[0]['pubdate'] ) );
foreach ( array( 'no title' => array( 'url' => 'https://c.example/t.mp3', 'pubdate' => '2026-03-01T10:00' ), 'http address' => array( 'title' => 'T', 'url' => 'http://c.example/t.mp3', 'pubdate' => '2026-03-01T10:00' ), 'no date' => array( 'title' => 'T', 'url' => 'https://c.example/t.mp3' ), 'bad size' => array( 'title' => 'T', 'url' => 'https://c.example/t.mp3', 'pubdate' => '2026-03-01T10:00', 'length' => '5MB' ), 'bad type' => array( 'title' => 'T', 'url' => 'https://c.example/t.mp3', 'pubdate' => '2026-03-01T10:00', 'type' => 'mp3' ) ) as $l => $row ) {
	$e = array(); PodPub_P20::trailers( array( 'trailers' => array( $row ) ), $e ); check( "trailer: $l refused", count( $e ) === 1 );
}

// ---- live items ----
$lv = array( 'title' => 'Launch party', 'status' => 'live', 'start' => '2026-06-01T20:00', 'end' => '2026-06-01 22:00', 'url' => 'https://live.example/stream', 'type' => '', 'link' => 'https://example.com/join' );
$e = array(); $l = PodPub_P20::live( array( 'live' => array( $lv ) ), $e );
check( 'live: good row kept, space in a date accepted, type defaults to audio/mpeg', $e === array() && $l[0]['type'] === 'audio/mpeg' && $l[0]['end'] - $l[0]['start'] === 7200 );
foreach ( array( 'bad status' => array( 'status' => 'soon' ), 'no start' => array( 'start' => '' ), 'end before start' => array( 'end' => '2026-06-01T19:00' ), 'http stream' => array( 'url' => 'http://live.example/s' ), 'no title' => array( 'title' => '' ), 'bad link' => array( 'link' => 'nope' ) ) as $l2 => $o ) {
	$e = array(); PodPub_P20::live( array( 'live' => array( array_merge( $lv, $o ) ) ), $e ); check( "live: $l2 refused", count( $e ) === 1 );
}

// ---- alternate file checksum ----
$ae = array( 'uri' => 'https://c.example/v.mp4', 'type' => 'video/mp4' );
$e = array(); $a = PodPub_P20::enclosures( array( 'enclosures' => array( array_merge( $ae, array( 'integrity_type' => 'sri', 'integrity_value' => 'sha384-abc' ) ) ) ), $e );
check( 'alternate file: checksum kind and value kept', $e === array() && $a[0]['integrity_type'] === 'sri' );
$e = array(); PodPub_P20::enclosures( array( 'enclosures' => array( array_merge( $ae, array( 'integrity_type' => 'sri' ) ) ) ), $e ); check( 'alternate file: kind without a value refused', count( $e ) === 1 );
$e = array(); PodPub_P20::enclosures( array( 'enclosures' => array( array_merge( $ae, array( 'integrity_value' => 'x' ) ) ) ), $e ); check( 'alternate file: value without a kind refused', count( $e ) === 1 );
$e = array(); PodPub_P20::enclosures( array( 'enclosures' => array( array_merge( $ae, array( 'integrity_type' => 'md5', 'integrity_value' => 'x' ) ) ) ), $e ); check( 'alternate file: unknown kind refused', count( $e ) === 1 );

// ---- the podcast page: trailers and live items in the feed ----
$r = PodPub_Feeds::save_p20( $pid, array(
	'trailers' => array( array( 'title' => 'Trailer', 'url' => 'https://cdn.example/t.mp3', 'pubdate' => '2026-03-01T10:00', 'type' => 'audio/mpeg' ) ),
	'live' => array( $lv ),
) );
$x = feed_dom( "$folder/$pdir.xml" );
check( 'channel: trailer with url, pubdate, type and its title as text', $r['errors'] === array() && ev( $x, 'string(/rss/channel/podcast:trailer)' ) === 'Trailer' && ev( $x, 'string(/rss/channel/podcast:trailer/@url)' ) === 'https://cdn.example/t.mp3' && ev( $x, 'string(/rss/channel/podcast:trailer/@type)' ) === 'audio/mpeg' && 1 === preg_match( '/^\w{3}, \d\d \w{3} \d{4} \d\d:\d\d:\d\d \+0000$/', ev( $x, 'string(/rss/channel/podcast:trailer/@pubdate)' ) ) && ev( $x, 'count(/rss/channel/podcast:trailer/@season | /rss/channel/podcast:trailer/@length)' ) == 0 );
check( 'channel: liveItem with status, start and end in UTC, title, guid, enclosure of length 0, content link', ev( $x, 'string(/rss/channel/podcast:liveItem/@status)' ) === 'live' && 1 === preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', ev( $x, 'string(/rss/channel/podcast:liveItem/@start)' ) ) && ev( $x, 'string(/rss/channel/podcast:liveItem/title)' ) === 'Launch party' && ev( $x, 'string(/rss/channel/podcast:liveItem/guid/@isPermaLink)' ) === 'false' && ev( $x, 'string(/rss/channel/podcast:liveItem/enclosure/@url)' ) === 'https://live.example/stream' && ev( $x, 'string(/rss/channel/podcast:liveItem/enclosure/@length)' ) === '0' && ev( $x, 'string(/rss/channel/podcast:liveItem/podcast:contentLink/@href)' ) === 'https://example.com/join' );
$g1 = ev( $x, 'string(/rss/channel/podcast:liveItem/guid)' );
PodPub_Feeds::save_p20( $pid, array( 'live' => array( array_merge( $lv, array( 'status' => 'ended' ) ) ) ) );
$x = feed_dom( "$folder/$pdir.xml" );
check( 'channel: the live item keeps its guid when its status changes', ev( $x, 'string(/rss/channel/podcast:liveItem/@status)' ) === 'ended' && ev( $x, 'string(/rss/channel/podcast:liveItem/guid)' ) === $g1 && $g1 !== '' );
$r = PodPub_Feeds::save_p20( $pid, array( 'live' => array( array_merge( $lv, array( 'start' => 'x' ) ) ) ) );
check( 'channel: a bad live row saves nothing', count( $r['errors'] ) === 1 );

// ---- episodes: numbers, extras, picture, transcripts, copy ----
$ep = array( 'title' => 'First', 'description' => 'D', 'published' => '2026-01-10T09:00', 'status' => 'published', 'audio_source' => 'external', 'audio_url' => 'https://cdn.example.net/one.mp3', 'audio_size' => '1000', 'duration' => '10:00' );
$r1 = esave( $pid, 0, $ep );
check( 'number: the first new full episode gets number 1 automatically', PodPub_Episodes::details( $r1['id'] )['episode'] === 1 );
$r2 = esave( $pid, 0, array_merge( $ep, array( 'title' => 'Second', 'published' => '2026-01-17T09:00' ) ) );
check( 'number: the next gets 2', PodPub_Episodes::details( $r2['id'] )['episode'] === 2 );
$r3 = esave( $pid, 0, array_merge( $ep, array( 'title' => 'Bonus bit', 'type' => 'bonus', 'published' => '2026-01-18T09:00' ) ) );
check( 'number: a bonus or trailer episode is not numbered', PodPub_Episodes::details( $r3['id'] )['episode'] === 0 );
$r4 = esave( $pid, 0, array_merge( $ep, array( 'title' => 'Season two', 'season' => '2', 'published' => '2026-02-01T09:00' ) ) );
check( 'number: a new season starts again at 1', PodPub_Episodes::details( $r4['id'] )['episode'] === 1 );
$r5 = esave( $pid, 0, array_merge( $ep, array( 'title' => 'Typed', 'episode' => '10', 'published' => '2026-02-08T09:00' ) ) );
$r6 = esave( $pid, 0, array_merge( $ep, array( 'title' => 'After typed', 'published' => '2026-02-15T09:00' ) ) );
check( 'number: a number typed by hand is kept, and the next one carries on from the highest in the season', PodPub_Episodes::details( $r5['id'] )['episode'] === 10 && PodPub_Episodes::details( $r6['id'] )['episode'] === 11 );
check( 'number: next_number follows the highest number in that season', PodPub_Episodes::next_number( $pid, 0 ) === 12 && PodPub_Episodes::next_number( $pid, 2 ) === 2 );
$r7 = esave( $pid, $r1['id'], array_merge( $ep, array( 'episode' => '' ) ) );
check( 'number: clearing the number of a saved episode leaves it empty (not renumbered)', PodPub_Episodes::details( $r1['id'] )['episode'] === 0 );
esave( $pid, $r1['id'], array_merge( $ep, array( 'episode' => '1' ) ) );

// extras and picture
$e1 = $r1['id']; $edir = PodPub_Episodes::dir_name( $e1 ); $efolder = "$folder/$edir";
$ex = array_merge( $ep, array( 'episode' => '1', 'explicit' => 'yes', 'subtitle' => 'A line', 'summary' => 'A longer summary', 'keywords' => 'a, b') );
$r = esave( $pid, $e1, $ex, null, $up( $jpg, 'ep art.jpg' ) );
$x = feed_dom( "$folder/$pdir.xml" );
$item = "//item[title='First']";
$esrc = ev( $x, "string($item/podcast:images/@srcset)" );
check( 'episode extras: explicit, subtitle, summary, keywords in the item', $r['errors'] === array() && ev( $x, "string($item/itunes:explicit)" ) === 'true' && ev( $x, "string($item/itunes:subtitle)" ) === 'A line' && ev( $x, "string($item/itunes:summary)" ) === 'A longer summary' && ev( $x, "string($item/itunes:keywords)" ) === 'a, b' );
check( 'episode picture: image.jpg in the episode folder, itunes:image, copies, srcset', is_file( "$efolder/image.jpg" ) && is_file( "$efolder/image-300.jpg" ) && ev( $x, "string($item/itunes:image/@href)" ) === "https://example.com/wp-content/uploads/podpublisher/$pdir/$edir/image.jpg" && 0 === strpos( $esrc, "https://example.com/wp-content/uploads/podpublisher/$pdir/$edir/image-300.jpg 300w" ) && substr( $esrc, -5 ) === '1600w' );
check( 'episode: an episode with explicit unset writes no explicit tag', ev( $x, "count(//item[title='Second']/itunes:explicit)" ) == 0 && ev( $x, "count(//item[title='Second']/itunes:image)" ) == 0 );
$r = esave( $pid, $e1, array_merge( $ex, array( 'remove_image' => '1' ) ) );
$x = feed_dom( "$folder/$pdir.xml" );
check( 'episode picture: removing deletes the file and its copies and the tags', ! is_file( "$efolder/image.jpg" ) && ! is_file( "$efolder/image-300.jpg" ) && ev( $x, "count($item/itunes:image | $item/podcast:images)" ) == 0 );
$r = esave( $pid, $e1, $ex, null, $up( __FILE__, 'notpic.jpg' ) );
check( 'episode picture: a file that is not a picture is refused and nothing is saved', count( $r['errors'] ) === 1 );
$r = esave( $pid, $e1, array_merge( $ex, array( 'explicit' => 'perhaps' ) ) );
check( 'episode: explicit other than yes, no or same refused', count( $r['errors'] ) === 1 );

// transcripts: upload and pick
file_put_contents( "$tmp/in/My Talk.vtt", "WEBVTT\n\n00:00.000 --> 00:02.000\nHi\n" );
$r = esave( $pid, $e1, $ex, null, null, $up( "$tmp/in/My Talk.vtt", 'My Talk.vtt' ) );
$tr = PodPub_Episodes::details( $e1 )['transcripts'];
check( 'transcript upload: stored under a safe name and added to the list with its type', $r['errors'] === array() && is_file( "$efolder/My_Talk.vtt" ) && count( $tr ) === 1 && $tr[0]['type'] === 'text/vtt' && $tr[0]['url'] === "https://example.com/wp-content/uploads/podpublisher/$pdir/$edir/My_Talk.vtt" );
$x = feed_dom( "$folder/$pdir.xml" );
check( 'transcript upload: in the feed', ev( $x, "string($item/podcast:transcript/@type)" ) === 'text/vtt' );
$r = esave( $pid, $e1, array_merge( $ex, array( 'transcripts' => $tr ) ), null, null, $up( "$tmp/in/My Talk.vtt", 'My Talk.vtt' ) );
check( 'transcript upload: the same name again gets a number, not an overwrite', is_file( "$efolder/My_Talk_2.vtt" ) && count( PodPub_Episodes::details( $e1 )['transcripts'] ) === 2 );
file_put_contents( "$efolder/notes.srt", "1\n00:00:00,000 --> 00:00:02,000\nHi\n" );
file_put_contents( "$efolder/bad name.txt", 'x' );
check( 'transcript pick: lists transcript files (also ones with spaces), not chapters.json, the empty index.html or other types', PodPub_Episodes::list_folder_transcripts( $pid, $e1 ) === array( 'My_Talk.vtt', 'My_Talk_2.vtt', 'bad name.txt', 'notes.srt' ) );
$r = esave( $pid, $e1, array_merge( $ex, array( 'transcripts' => PodPub_Episodes::details( $e1 )['transcripts'], 'transcript_pick' => 'bad name.txt' ) ) );
check( 'transcript pick: a name that needs renaming is refused without the tick, naming both names, and the file is untouched', count( $r['errors'] ) === 1 && false !== strpos( $r['errors'][0], 'bad name.txt' ) && false !== strpos( $r['errors'][0], 'bad_name.txt' ) && is_file( "$efolder/bad name.txt" ) && ! file_exists( "$efolder/bad_name.txt" ) );
$r = esave( $pid, $e1, array_merge( $ex, array( 'transcripts' => PodPub_Episodes::details( $e1 )['transcripts'], 'transcript_pick' => 'bad name.txt', 'rename_ok' => '1' ) ) );
check( 'transcript pick: with the tick, a name with a space is renamed with underscores when picked, and the list holds the safe URL', $r['errors'] === array() && is_file( "$efolder/bad_name.txt" ) && ! file_exists( "$efolder/bad name.txt" ) && false !== strpos( end( PodPub_Episodes::details( $e1 )['transcripts'] )['url'], '/bad_name.txt' ) );
$tr = PodPub_Episodes::details( $e1 )['transcripts'];
$r = esave( $pid, $e1, array_merge( $ex, array( 'transcripts' => $tr, 'transcript_pick' => 'notes.srt' ) ) );
check( 'transcript pick: added with the SubRip type', $r['errors'] === array() && end( PodPub_Episodes::details( $e1 )['transcripts'] )['type'] === 'application/x-subrip' );
$r = esave( $pid, $e1, array_merge( $ex, array( 'transcript_pick' => '../../x.txt' ) ) );
check( 'transcript pick: a file that is not in the folder list refused', count( $r['errors'] ) === 1 );
file_put_contents( "$tmp/in/x.exe", 'x' ); file_put_contents( "$tmp/in/empty.txt", '' );
$r = esave( $pid, $e1, $ex, null, null, $up( "$tmp/in/x.exe", 'x.exe' ) ); check( 'transcript upload: wrong kind of file refused', count( $r['errors'] ) === 1 );
$r = esave( $pid, $e1, $ex, null, null, $up( "$tmp/in/empty.txt", 'empty.txt' ) ); check( 'transcript upload: empty file refused', count( $r['errors'] ) === 1 );

// copy from the latest episode
$latest = PodPub_Episodes::all( $pid )[0];
esave( $pid, $latest->ID, array_merge( $ep, array( 'title' => $latest->post_title, 'published' => PodPub_Episodes::format_date( PodPub_Episodes::details( $latest->ID )['published'] ), 'explicit' => 'yes', 'subtitle' => 'Sub1', 'summary' => 'Sum1', 'show_notes' => 'Notes1', 'keywords' => 'k1', 'season' => '2', 'episode' => '1', 'persons' => array( array( 'name' => 'Jo', 'role' => 'host' ) ), 'funding' => array( array( 'url' => 'https://f.example', 'message' => 'Fund' ) ), 'soundbites' => array( array( 'start' => '5', 'duration' => '30' ) ) ) ) );
$cd = PodPub_Episodes::copy_defaults( $pid );
check( 'copy: keeps title, description, show notes, subtitle, summary, keywords, season, people and funding; next number carries on', $cd['title'] === $latest->post_title && $cd['description'] === 'D' && $cd['show_notes'] === 'Notes1' && $cd['subtitle'] === 'Sub1' && $cd['summary'] === 'Sum1' && $cd['season'] === 2 && $cd['keywords'] === 'k1' && $cd['persons'][0]['name'] === 'Jo' && $cd['funding'][0]['url'] === 'https://f.example' && $cd['episode'] === PodPub_Episodes::next_number( $pid, 2 ) );
check( 'copy: leaves out guid, date, explicit, audio, soundbites, chapters, picture; status draft', $cd['guid'] === '' && $cd['explicit'] === '' && $cd['published'] > time() - 5 && $cd['audio'] === '' && $cd['external_url'] === '' && $cd['soundbites'] === array() && $cd['chapters'] === array() && $cd['image'] === '' && $cd['status'] === 'draft' );
$empty_pid = PodPub_Feeds::create( 'Empty Show' );
check( 'copy: a podcast with no episodes has nothing to copy', PodPub_Episodes::copy_defaults( $empty_pid ) === null );

// ---- people taxonomy ----
$tax = PodPub_P20::taxonomy();
check( 'people: eleven groups and sixty-two different roles from the taxonomy (editor is in two groups)', count( $tax ) === 11 && count( array_unique( array_merge( ...array_values( $tax ) ) ) ) === 62 && in_array( 'host', $tax['cast'], true ) && in_array( 'cover art designer', $tax['visuals'], true ) );
$pp = function ( $row ) { $e = array(); $r = PodPub_P20::persons( array( 'persons' => array( array_merge( array( 'name' => 'Jo' ), $row ) ) ), $e ); return array( $e, $r ); };
list( $e, $r ) = $pp( array( 'role' => 'Host' ) );
check( 'people: role is matched in any case; the group cast is the default so is not written', $e === array() && $r[0]['role'] === 'host' && $r[0]['group'] === '' );
list( $e, $r ) = $pp( array( 'role' => 'sound designer' ) );
check( 'people: group is worked out from the role when left on Automatic', $e === array() && $r[0]['group'] === 'audio post-production' );
list( $e, $r ) = $pp( array( 'role' => 'editor', 'group' => 'video post-production' ) );
check( 'people: a role in two groups takes the group chosen', $e === array() && $r[0]['group'] === 'video post-production' );
list( $e, $r ) = $pp( array( 'role' => 'editor' ) );
check( 'people: editor with no group is read as writing (first group that has it)', $e === array() && $r[0]['group'] === 'writing' );
list( $e, $r ) = $pp( array( 'role' => 'host', 'group' => 'visuals' ) ); check( 'people: a role that is not in the chosen group refused', count( $e ) === 1 );
list( $e, $r ) = $pp( array( 'role' => 'wizard' ) ); check( 'people: a role that is not in the list refused', count( $e ) === 1 );
list( $e, $r ) = $pp( array( 'role' => 'host', 'group' => 'wizards' ) ); check( 'people: a group that is not in the list refused', count( $e ) === 1 );
list( $e, $r ) = $pp( array( 'group' => 'cast' ) ); check( 'people: a group with no role refused', count( $e ) === 1 );
list( $e, $r ) = $pp( array() ); check( 'people: a name alone is fine', $e === array() && $r[0]['role'] === '' );

$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { ( $f->isDir() && ! $f->isLink() ) ? rmdir( $f ) : unlink( $f ); }
rmdir( $tmp );
exit( $fail );
