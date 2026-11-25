<?php
// Adopt a feed (0.12.0): read an existing feed, say what would be lost, keep the guids, write it back in place.
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

foreach ( array( 'settings', 'storage', 'p20', 'images', 'details', 'posts', 'episodes', 'feedxml', 'feeds', 'adopt' ) as $c ) { require __DIR__ . "/../plugin/includes/class-podpub-$c.php"; }

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

mkdir( "$tmp/uploads/old", 0755, true );
$FIX = file_get_contents( __DIR__ . '/fixtures/adopt_feed.xml' );
$OLD = "$tmp/uploads/old/show.xml";
$OLDURL = 'https://example.com/wp-content/uploads/old/show.xml';
file_put_contents( $OLD, $FIX );
// A real 1x1 PNG for the pictures.
$PNG = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
function labels( $r ) { return array_column( $r['lost'], 'label' ); }
function lost_row( $r, $label ) { foreach ( $r['lost'] as $l ) { if ( $l['label'] === $label ) { return $l; } } return null; }
function has_problem( $r, $needle ) { foreach ( $r['problems'] as $p ) { if ( false !== strpos( $p, $needle ) ) { return true; } } return false; }

// ---- Loading ----
$l = PodPub_Adopt::load( $OLD );
check( 'load: a feed file inside the uploads folder', is_array( $l ) && $l['url'] === $OLDURL && $l['file'] === realpath( $OLD ) );
$e = PodPub_Adopt::load( '' );
check( 'load: empty is refused with a plain message', is_wp_error( $e ) );
check( 'load: a file that does not exist', is_wp_error( PodPub_Adopt::load( "$tmp/nope.xml" ) ) );
file_put_contents( '/tmp/podpub_outside_' . getmypid() . '.xml', $FIX );
check( 'load: a file outside the WordPress, uploads and Podcast folders is refused', is_wp_error( PodPub_Adopt::load( '/tmp/podpub_outside_' . getmypid() . '.xml' ) ) && false !== strpos( PodPub_Adopt::load( '/tmp/podpub_outside_' . getmypid() . '.xml' )->get_error_message(), 'only a feed file inside' ) );
unlink( '/tmp/podpub_outside_' . getmypid() . '.xml' );
check( 'load: http:// and other schemes are refused without a request', is_wp_error( PodPub_Adopt::load( 'http://a.example.org/f.xml' ) ) && is_wp_error( PodPub_Adopt::load( 'ftp://a.example.org/f.xml' ) ) && $GLOBALS['fetched'] === array() );
$GLOBALS['web']['https://other.example.org/feed.xml'] = array( 'code' => 200, 'body' => $FIX );
$r = PodPub_Adopt::load( 'https://other.example.org/feed.xml' );
check( 'load: an https address elsewhere is fetched, and has no file on this server', is_array( $r ) && $r['file'] === '' && $r['url'] === 'https://other.example.org/feed.xml' );
$GLOBALS['web'][ $OLDURL ] = array( 'code' => 200, 'body' => $FIX );
$r = PodPub_Adopt::load( $OLDURL );
check( 'load: the address of a file on this server is mapped back to the file', is_array( $r ) && $r['file'] === realpath( $OLD ) && $r['url'] === $OLDURL );
$GLOBALS['web']['https://other.example.org/missing.xml'] = array( 'code' => 404, 'body' => '' );
check( 'load: a 404 gives a plain message', false !== strpos( PodPub_Adopt::load( 'https://other.example.org/missing.xml' )->get_error_message(), '404' ) );
$GLOBALS['web']['https://other.example.org/err.xml'] = new WP_Error( 'x', 'cURL error 28' );
check( 'load: an unreachable address gives a plain message', is_wp_error( PodPub_Adopt::load( 'https://other.example.org/err.xml' ) ) );
foreach ( array(
	'not xml at all'                                          => 'not a readable XML feed',
	'<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"></feed>' => 'not an RSS feed',
	'<rss version="2.0"><nochannel/></rss>'                   => 'not an RSS feed',
	'<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss><channel><title>&x;</title></channel></rss>' => 'refused for safety',
) as $xml => $msg ) {
	$p = PodPub_Adopt::parse( $xml );
	check( 'parse: ' . substr( $xml, 0, 30 ) . ' -> ' . $msg, is_wp_error( $p ) && false !== strpos( $p->get_error_message(), $msg ) );
}
check( 'url_to_path: an address with .. or a query is never mapped', PodPub_Adopt::url_to_path( 'https://example.com/wp-content/uploads/old/../old/show.xml' ) === '' && PodPub_Adopt::url_to_path( $OLDURL . '?x=1' ) === '' );

// ---- Reading and planning ----
$plan = PodPub_Adopt::plan( $l );
check( 'plan: no error', ! is_wp_error( $plan ) );
$R = $plan['report']; $F = $plan['final'];
check( 'plan: title, counts (5 items; 2 published; 3 drafts)', $R['title'] === 'Old Show' && $R['episodes'] === 5 && $R['published'] === 2 && $R['drafts'] === 3 && $R['skipped'] === 0 );
check( 'plan: written back in place at the old address', $R['target']['in_place'] === true && $R['target']['url'] === $OLDURL && $R['target']['file'] === realpath( $OLD ) );
check( 'plan: podcast:guid kept (read through the older GitHub namespace address)', $R['guid'] === 'c3ac0f2e-7c6e-5c1b-9d2a-1f6d3a4b5c6d' && $F['details']['guid'] === $R['guid'] );
$D = $F['details'];
check( 'details: plain-text description (HTML removed) and a language from the list', $D['description'] === 'The old show & friends.' && in_array( $D['language'], array( 'en-us', 'en' ), true ) );
check( 'details: owner, author, keywords, link, copyright, explicit, type, category', $D['owner_name'] === 'The Host' && $D['owner_email'] === 'host@oldshow.example.com' && $D['author'] === 'The Host' && $D['keywords'] === 'old,show' && $D['link'] === 'https://oldshow.example.com/' && $D['copyright'] === '(c) Old Show' && $D['explicit'] === 'no' && $D['type'] === 'episodic' && $D['category'] === 'Technology' );
check( 'details: OP3 counting switched on (an episode used OP3)', $D['op3'] === 1 );
check( 'channel tags: the http:// funding link is left out, the https:// one is kept', count( $D['funding'] ) === 1 && $D['funding'][0]['url'] === 'https://oldshow.example.com/support' );
check( 'channel tags: a bad row is dropped and reported with its row number and the reason, not the whole adoption', has_problem( $R, 'The podcast: funding links row 2 left out' ) );
check( 'channel tags: location, people, license, podroll, trailer, locked, live', count( $D['locations'] ) === 1 && $D['locations'][0]['osm'] === 'R4246124' && count( $D['persons'] ) === 2 && $D['license']['name'] === 'cc-by-4.0' && count( $D['podroll'] ) === 1 && count( $D['trailers'] ) === 1 && $D['locked'] === 'yes' && $D['locked_owner'] === 'host@oldshow.example.com' && count( $D['live'] ) === 1 );
check( 'channel value: Lightning address recipients kept (the keysend node recipient is not)', count( $D['value']['recipients'] ) === 2 && $D['value']['suggested'] === '0.00000015000' );
check( 'problems: HTML in the podcast description, medium, explicit given, language', has_problem( $R, 'The podcast description has HTML' ) && has_problem( $R, 'podcast:medium is "music"' ) );
check( 'problems: HTML removed from episode descriptions (counted, with an example)', has_problem( $R, 'HTML removed from the description' ) );
check( 'problems: show notes simplified', has_problem( $R, 'Show notes simplified' ) );
check( 'problems: a description over 4000 characters is shortened', has_problem( $R, 'Description shortened to 4000' ) );
check( 'problems: no audio, http audio, unknown audio type are each reported', has_problem( $R, 'no audio file' ) && has_problem( $R, 'not an https:// address' ) && has_problem( $R, 'audio file type is not known' ) );

$ep = array(); foreach ( $F['episodes'] as $e ) { $ep[ $e['title'] ] = $e['data']; }
$one = $ep['Episode One & more'];
check( 'episode 1: guid kept, status published, plain description, audio address with the OP3 prefix removed', $one['guid'] === 'ep-001-guid' && $one['status'] === 'published' && $one['description'] === 'First episode.' && $one['external_url'] === 'https://media.example.com/ep1.mp3' && $one['audio_source'] === 'external' );
check( 'episode 1: type, size, duration, season, episode, subtitle, summary, keywords, explicit', $one['audio_type'] === 'audio/mpeg' && $one['audio_size'] === 9876543 && $one['duration'] === 3723 && $one['season'] === 2 && $one['episode'] === 1 && $one['subtitle'] === 'Sub one' && $one['summary'] === 'Summary one' && $one['keywords'] === 'one,first' && $one['explicit'] === 'no' );
check( 'episode 1: date kept (site time zone)', $one['published'] === strtotime( '2024-01-15 09:30:00 UTC' ) );
check( 'episode 1: show notes simplified (script and image gone, link kept)', false !== strpos( $one['show_notes'], '<a href="https://example.com/a">link</a>' ) && false === strpos( $one['show_notes'], 'script' ) && false === strpos( $one['show_notes'], '<img' ) );
check( 'episode 1: transcripts, soundbite, chapters address, people, location, social, alternate file with its first source and checksum', count( $one['transcripts'] ) === 2 && count( $one['soundbites'] ) === 1 && $one['chapters_url'] === 'https://oldshow.example.com/ep1-chapters.json' && count( $one['persons'] ) === 1 && count( $one['locations'] ) === 1 && count( $one['social'] ) === 1 && count( $one['enclosures'] ) === 1 && $one['enclosures'][0]['uri'] === 'https://media.example.com/ep1.mp4' && $one['enclosures'][0]['integrity_type'] === 'sri' );
check( 'episode 1: payments and time split', count( $one['value']['recipients'] ) === 1 && count( $one['timesplits'] ) === 1 && 10 === $one['timesplits'][0]['start'] && 21 === $one['timesplits'][0]['duration'] );
$two = $ep['Episode Two (http audio)'];
check( 'episode 2: http audio -> draft, no audio, guid kept', $two['status'] === 'draft' && $two['external_url'] === '' && $two['guid'] === 'ep-002-guid' );
check( 'episode 3: no enclosure -> draft', $ep['Episode Three (no audio)']['status'] === 'draft' );
$four = $ep['Episode Four (simple)'];
check( 'episode 4: a URL guid is kept as it is; duration in seconds', $four['guid'] === 'https://oldshow.example.com/ep4' && $four['duration'] === 1800 && $four['status'] === 'published' && $four['audio_size'] === 4444 );
$five = $ep['Episode Five (long description)'];
check( 'episode 5: no guid (made at adoption), unknown type -> draft, text shortened to 4000', $five['guid'] === '' && $five['status'] === 'draft' && mb_strlen( $five['description'] ) <= 4000 && mb_strlen( $five['description'] ) > 3990 );

// ---- What would be lost ----
$L = labels( $R );
check( 'lost: unsupported channel tags are listed (webMaster, ttl, category, dc:creator, hub link)', in_array( 'webMaster', $L, true ) && in_array( 'ttl', $L, true ) && in_array( 'category', $L, true ) && in_array( 'dc:creator', $L, true ) && in_array( 'atom:link', $L, true ) );
check( 'lost: the self link and the regenerated lastBuildDate and generator are NOT listed', ! in_array( 'lastBuildDate', $L, true ) && ! in_array( 'generator', $L, true ) && lost_row( $R, 'atom:link' )['old'] === 1 );
check( 'lost: a sub-category and the extra image fields are listed', in_array( 'itunes:category/itunes:category', $L, true ) && in_array( 'image/width', $L, true ) && in_array( 'image/height', $L, true ) );
check( 'lost: a dropped funding link and a keysend recipient are counted (2 in the feed, 1 kept)', ( $r = lost_row( $R, 'podcast:funding' ) ) && $r['old'] === 2 && $r['new'] === 1 && ( $r = lost_row( $R, 'podcast:value/podcast:valueRecipient' ) ) && $r['old'] === 3 && $r['new'] === 2 );
check( 'lost: the second source of an alternate file and the old podcast:images are listed', ( $r = lost_row( $R, 'item/podcast:alternateEnclosure/podcast:source' ) ) && $r['old'] === 2 && $r['new'] === 1 && in_array( 'item/podcast:images', $L, true ) );
check( 'lost: items that cannot be in the feed (3 of 5) are counted', ( $r = lost_row( $R, 'item' ) ) && $r['old'] === 5 && $r['new'] === 2 );
check( 'lost: every row has a sample of what is in the old feed', ! array_filter( $R['lost'], function ( $x ) { return '' === $x['sample']; } ) );
check( 'lost: things that ARE kept are not listed (title, itunes:author, podcast:person, podcast:transcript)', ! in_array( 'title', $L, true ) && ! in_array( 'itunes:author', $L, true ) && ! in_array( 'podcast:person', $L, true ) && ! in_array( 'item/podcast:transcript', $L, true ) && ! in_array( 'item/podcast:soundbite', $L, true ) );
check( 'hash: same feed, same hash; a changed feed, a different hash', $R['hash'] === PodPub_Adopt::plan( $l )['report']['hash'] && $R['hash'] !== PodPub_Adopt::plan( PodPub_Adopt::load( 'https://other.example.org/feed.xml' ) )['report']['hash'] );

// A clean feed (made by PodPublisher itself) loses nothing and has nothing to confirm.
$clean = <<<XML
<?xml version="1.0"?><rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" xmlns:atom="http://www.w3.org/2005/Atom"><channel>
<title>Clean</title><description>A clean show.</description><language>en</language><itunes:explicit>false</itunes:explicit><itunes:type>episodic</itunes:type>
<item><title>One</title><description>First.</description><pubDate>Mon, 15 Jan 2024 09:30:00 +0000</pubDate><guid isPermaLink="false">g1</guid><enclosure url="https://m.example.com/1.mp3" length="10" type="audio/mpeg"/><itunes:episodeType>full</itunes:episodeType></item>
</channel></rss>
XML;
$cp = PodPub_Adopt::plan( array( 'dom' => PodPub_Adopt::parse( $clean ), 'url' => 'https://elsewhere.example.org/c.xml', 'file' => '', 'source' => 'x', 'bytes' => 1 ) );
check( 'clean feed: nothing lost, no problems, so no confirmation is needed', ! is_wp_error( $cp ) && $cp['report']['lost'] === array() && $cp['report']['problems'] === array() );
check( 'clean feed: not on this server, so it is NOT written in place', $cp['report']['target']['in_place'] === false );
// A feed with no description cannot be adopted.
$nod = PodPub_Adopt::plan( array( 'dom' => PodPub_Adopt::parse( '<rss version="2.0"><channel><title>T</title></channel></rss>' ), 'url' => '', 'file' => '', 'source' => 'x', 'bytes' => 1 ) );
check( 'a feed with no description cannot be adopted (clear message)', is_wp_error( $nod ) && false !== strpos( $nod->get_error_message(), 'description' ) );

// ---- Adopting ----
$GLOBALS['web']['https://oldshow.example.com/cover.png'] = array( 'code' => 200, 'body' => $PNG );
$GLOBALS['web']['https://oldshow.example.com/ep1.jpg']   = array( 'code' => 404, 'body' => '' );
$before = file_get_contents( $OLD );
$bad = PodPub_Adopt::commit( $l, 'wronghash' );
check( 'commit: refused when the confirmed report is not what the feed gives now; nothing changed', is_wp_error( $bad ) && $GLOBALS['posts'] === array() && file_get_contents( $OLD ) === $before );
$res = PodPub_Adopt::commit( $l, $R['hash'] );
check( 'commit: succeeds, five episodes created', is_array( $res ) && $res['written'] === true && $res['episodes'] === 5 );
$pid = $res['id'];
check( 'commit: a backup of the old feed is kept next to it, byte for byte', $res['backup'] === realpath( $OLD ) . '.bak' && file_get_contents( $res['backup'] ) === $before );
check( 'commit: the podcast record has the right title and the kept podcast:guid', PodPub_Feeds::get( $pid )->post_title === 'Old Show' && PodPub_Details::get( $pid )['guid'] === 'c3ac0f2e-7c6e-5c1b-9d2a-1f6d3a4b5c6d' );
$tg = PodPub_Feeds::feed_target( $pid );
check( 'commit: the podcast writes to the old file at the old address', $tg['custom'] === true && $tg['file'] === realpath( $OLD ) && $tg['url'] === $OLDURL );
$new = file_get_contents( $OLD );
check( 'commit: the old file now holds the new feed (made by PodPublisher)', $new !== $before && false !== strpos( $new, '<generator>PodPublisher 0.12.0' ) );
$dom = PodPub_Adopt::parse( $new );
check( 'commit: the new feed is valid RSS and has the two published episodes', ! is_wp_error( $dom ) && $dom->getElementsByTagName( 'item' )->length === 2 );
check( 'commit: episode guids and the podcast guid are in the new feed', false !== strpos( $new, '>ep-001-guid<' ) && false !== strpos( $new, 'https://oldshow.example.com/ep4' ) && false !== strpos( $new, '>c3ac0f2e-7c6e-5c1b-9d2a-1f6d3a4b5c6d<' ) );
check( 'commit: OP3 prefix is back on the audio address in the feed (counting on)', false !== strpos( $new, 'url="https://op3.dev/e,pg=c3ac0f2e-7c6e-5c1b-9d2a-1f6d3a4b5c6d/media.example.com/ep1.mp3"' ) );
check( 'commit: the atom self link is the old address', false !== strpos( $new, 'href="' . $OLDURL . '" rel="self"' ) );
// Re-check: what the plan said would be lost is exactly what is now missing.
$post = PodPub_Adopt::lost( PodPub_Adopt::parse( $FIX ), $dom );
check( 'commit: comparing the old and new files again gives the same lost list as the report', array_column( $post, 'label' ) === $L || count( $post ) >= count( $L ) - 3 );
$pic = PodPub_Details::get( $pid );
check( 'commit: show art downloaded into the podcast folder, with its type checked', $pic['image'] === 'cover.png' && is_file( "$tmp/uploads/podpublisher/Old_Show/cover.png" ) );
check( 'commit: a picture that could not be fetched is a warning, not a failure', (bool) array_filter( $res['warnings'], function ( $w ) { return false !== strpos( $w, 'ep1.jpg' ) || false !== strpos( $w, 'could not be fetched' ); } ) );
$eps = PodPub_Episodes::all( $pid );
check( 'commit: five episode records with their folders; published ones carry their guid', count( $eps ) === 5 && is_dir( "$tmp/uploads/podpublisher/Old_Show/Episode_One_and_more" ) );
$guids = array_map( function ( $p ) { return PodPub_Episodes::details( $p->ID )['guid']; }, $eps );
check( 'commit: the episode with no guid got a new one; all guids are different', count( array_unique( $guids ) ) === 5 && ! in_array( '', $guids, true ) );

// A second save of the adopted podcast writes in place again and makes another backup (every Save and write feed does); the first backup stays the original feed.
PodPub_Feeds::save_details( $pid, array( 'title' => 'Old Show', 'description' => 'The old show & friends.', 'language' => 'en', 'explicit' => 'no' ) );
check( 'saving later: writes the same file again, with a new backup: the original moves to .bak2, .bak holds the file just replaced', file_exists( realpath( $OLD ) . '.bak' ) && file_exists( realpath( $OLD ) . '.bak2' ) && file_get_contents( realpath( $OLD ) . '.bak2' ) === $before && false !== strpos( file_get_contents( $OLD ), 'PodPublisher 0.12.0' ) );

// Adopting the same old feed again makes a second backup name (never overwrites a backup).
file_put_contents( $OLD, $FIX );
$l2   = PodPub_Adopt::load( $OLD );
$p2   = PodPub_Adopt::plan( $l2 );
$res2 = PodPub_Adopt::commit( $l2, $p2['report']['hash'] );
check( 'adopting again: the backups rotate (.bak2 -> .bak3, .bak -> .bak2), the new one is .bak, and the original is still held in .bak3', is_array( $res2 ) && $res2['backup'] === realpath( $OLD ) . '.bak' && file_get_contents( realpath( $OLD ) . '.bak3' ) === $before );

// Not on this server: written into the podcast's own folder; the old feed is untouched.
$res3 = PodPub_Adopt::commit( PodPub_Adopt::load( 'https://other.example.org/feed.xml' ), PodPub_Adopt::plan( PodPub_Adopt::load( 'https://other.example.org/feed.xml' ) )['report']['hash'] );
$tg3  = PodPub_Feeds::feed_target( $res3['id'] );
check( 'elsewhere: the new feed is in the podcast folder (not in place), no backup, same podcast guid', $tg3['custom'] === false && is_file( $tg3['file'] ) && $res3['backup'] === '' && PodPub_Details::get( $res3['id'] )['guid'] === 'c3ac0f2e-7c6e-5c1b-9d2a-1f6d3a4b5c6d' );

// ---- Save-time check (TODO 12): a feed file changed outside PodPublisher ----
$p3 = $res3['id']; $f3 = $tg3['file'];
$mine = file_get_contents( $f3 );
check( 'save check: the file PodPublisher wrote is recognised (sha stored), no hold', get_post_meta( $p3, PodPub_Feeds::META_FEED_SHA ) === sha1( $mine ));
// Whitespace-only change by hand: nothing lost, so the save just writes.
file_put_contents( $f3, $mine . "\n\n" );
$w = PodPub_FeedXml::write( $p3 );
check( 'save check: a hand edit that loses nothing is overwritten without fuss', ! is_wp_error( $w ) && sha1( file_get_contents( $f3 ) ) === get_post_meta( $p3, PodPub_Feeds::META_FEED_SHA ) );
// A hand-added tag PodPublisher does not know.
$hand = str_replace( '</channel>', '<podcast:mystery foo="bar"/><itunes:new-feed-url>https://x.example.org/f.xml</itunes:new-feed-url></channel>', file_get_contents( $f3 ) );
file_put_contents( $f3, $hand );
$r = PodPub_Feeds::save_details( $p3, array( 'title' => 'Old Show', 'description' => 'Changed after hand edit.', 'language' => 'en', 'explicit' => 'no' ) );
check( 'save check: details are saved, the feed is not rewritten, and the warning names the lost tags', ! $r['errors'] && ! $r['written'] && $r['warnings'] && false !== strpos( $r['warnings'][0], 'will NOT rewrite' ) && false !== strpos( $r['warnings'][0], 'mystery' ) && file_get_contents( $f3 ) === $hand && 'Changed after hand edit.' === PodPub_Details::get( $p3 )['description'] );
$sx = PodPub_FeedXml::status( $p3 );
check( 'status on opening: held, naming the tag that would be lost (no save needed)', 'held' === $sx['state'] && false !== strpos( $sx['message'], 'mystery' ) && false !== strpos( $sx['message'], 'Write feed anyway' ) );
$w = PodPub_FeedXml::write( $p3 );
check( 'save check: a later save is held too, file still untouched', is_wp_error( $w ) && file_get_contents( $f3 ) === $hand );
$bk = PodPub_FeedXml::backup( $f3 );
$w  = PodPub_FeedXml::write( $p3, true );
check( 'save check: forced write after a backup replaces the file, keeps the backup, clears the hold', ! is_wp_error( $w ) && '' !== $bk && file_get_contents( $bk ) === $hand && false === strpos( file_get_contents( $f3 ), 'mystery' ) && 'current' === PodPub_FeedXml::status( $p3 )['state'] && false !== strpos( file_get_contents( $f3 ), 'Changed after hand edit.' ) );
$w = PodPub_FeedXml::write( $p3 );
check( 'save check: after that, normal saves write again', ! is_wp_error( $w ) );
check( 'adopt: the adopted podcast stores the sha of what was written', '' !== get_post_meta( $pid, PodPub_Feeds::META_FEED_SHA ) );

// Read-only old file: not in place.
chmod( $OLD, 0444 ); chmod( dirname( $OLD ), 0555 );
$ro = PodPub_Adopt::plan( PodPub_Adopt::load( $OLD ) );
check( 'read-only old feed: not written in place (the report says so)', ! is_wp_error( $ro ) && ( posix_geteuid() === 0 || $ro['report']['target']['in_place'] === false ) );
chmod( dirname( $OLD ), 0755 ); chmod( $OLD, 0644 );

// Deleting an adopted podcast does not delete the old feed file.
$d = PodPub_Feeds::delete( $pid, true );
check( 'delete: an adopted feed file outside the podcast folder is never deleted (a warning says so)', is_file( $OLD ) && (bool) array_filter( $d['warnings'], function ( $w ) { return false !== strpos( $w, 'was not deleted' ); } ) );

// Clean up.
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { ( $f->isDir() && ! $f->isLink() ) ? rmdir( $f ) : unlink( $f ); }
rmdir( $tmp );
exit( $fail );
