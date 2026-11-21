<?php
// Episodes: audio checks, dates and durations, create/edit/delete on a real temporary folder, and the feed items.
// Not a real WordPress: the few WordPress functions used are stood in for.
define( 'ABSPATH', __DIR__ );
define( 'PODPUB_VERSION', 'test' );
$tmp = sys_get_temp_dir() . '/podpub_ep_' . getmypid();
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
class WP_Error { public $m; function __construct( $c, $m ) { $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }

foreach ( array( 'settings', 'storage', 'details', 'episodes', 'feedxml', 'feeds' ) as $c ) { require __DIR__ . "/../plugin/includes/class-podpub-$c.php"; }

$fail = 0;
function check( $name, $ok ) { global $fail; echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n"; if ( ! $ok ) { $fail = 1; } }
function put( $path, $bytes ) { file_put_contents( $path, $bytes ); return $path; }
function up( $path, $name ) { return array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'name' => $name ); }
function feed_dom( $file ) { $d = new DOMDocument(); $d->load( $file ); $x = new DOMXPath( $d ); foreach ( array( 'itunes' => 'http://www.itunes.com/dtds/podcast-1.0.dtd', 'podcast' => 'https://podcastindex.org/namespace/1.0', 'content' => 'http://purl.org/rss/1.0/modules/content/' ) as $p => $u ) { $x->registerNamespace( $p, $u ); } return $x; }

mkdir( "$tmp/in", 0755, true );
$MP3 = "ID3\x04\x00\x00\x00\x00\x00\x00" . str_repeat( "\0", 100 );

// Audio checks: by extension and first bytes, never by the browser's claim.
$ok = function ( $bytes, $name ) use ( $tmp ) { $r = PodPub_Episodes::inspect_audio( put( "$tmp/in/t.bin", $bytes ), $name ); return ! is_wp_error( $r ) ? $r : false; };
check( 'audio: mp3 with ID3 tag ok', ( $r = $ok( $MP3, 'a.mp3' ) ) && $r['type'] === 'audio/mpeg' && $r['ext'] === 'mp3' );
check( 'audio: mp3 with frame sync ok', (bool) $ok( "\xFF\xFB\x90\x00" . str_repeat( "\0", 50 ), 'a.MP3' ) );
check( 'audio: m4a ok', ( $r = $ok( "\0\0\0\x20ftypM4A \0\0\0\0", 'a.m4a' ) ) && $r['type'] === 'audio/mp4' );
check( 'audio: ogg ok', (bool) $ok( "OggS\0\x02" . str_repeat( "\0", 30 ), 'a.ogg' ) );
check( 'audio: opus ok', ( $r = $ok( "OggS\0\x02" . str_repeat( "\0", 30 ), 'a.opus' ) ) && $r['type'] === 'audio/opus' );
check( 'audio: wav ok', (bool) $ok( "RIFF\x24\0\0\0WAVEfmt " , 'a.wav' ) );
check( 'audio: flac ok', (bool) $ok( "fLaC\0\0\0\x22" . str_repeat( "\0", 30 ), 'a.flac' ) );
check( 'audio: text renamed .mp3 refused', ! $ok( '<?php echo 1; ?>' . str_repeat( ' ', 50 ), 'a.mp3' ) );
check( 'audio: mp3 content with .m4a name refused', ! $ok( $MP3, 'a.m4a' ) );
check( 'audio: .php refused', ! $ok( $MP3, 'a.php' ) );
check( 'audio: double extension a.mp3.php refused', ! $ok( $MP3, 'a.mp3.php' ) );
check( 'audio: empty file refused', ! $ok( '', 'a.mp3' ) );
check( 'audio: missing file refused', is_wp_error( PodPub_Episodes::inspect_audio( "$tmp/in/none", 'a.mp3' ) ) );

// Durations and dates.
check( 'duration: seconds', PodPub_Episodes::parse_duration( '754' ) === 754 );
check( 'duration: m:ss', PodPub_Episodes::parse_duration( '12:34' ) === 754 );
check( 'duration: h:mm:ss', PodPub_Episodes::parse_duration( '1:02:30' ) === 3750 );
check( 'duration: empty is 0', PodPub_Episodes::parse_duration( ' ' ) === 0 );
check( 'duration: junk and 1:75 refused', PodPub_Episodes::parse_duration( 'abc' ) === null && PodPub_Episodes::parse_duration( '1:75' ) === null && PodPub_Episodes::parse_duration( '-5' ) === null );
check( 'duration: formatted', PodPub_Episodes::format_duration( 3750 ) === '1:02:30' && PodPub_Episodes::format_duration( 59 ) === '0:00:59' );
$ts = PodPub_Episodes::parse_date( '2026-01-15T09:30' );
check( 'date: site time zone (Melbourne is UTC+11 in January)', $ts === gmmktime( 22, 30, 0, 1, 14, 2026 ) );
check( 'date: round trip', PodPub_Episodes::format_date( $ts ) === '2026-01-15T09:30' );
check( 'date: impossible and junk refused', PodPub_Episodes::parse_date( '2026-02-30T10:00' ) === null && PodPub_Episodes::parse_date( 'tomorrow' ) === null && PodPub_Episodes::parse_date( '' ) === null );

// Form checking.
$good = array( 'title' => 'Episode 1: The Start', 'description' => 'Hello', 'show_notes' => '<p>Notes <a href="https://x.example">link</a></p><script>alert(1)</script>', 'published' => '2026-01-15T09:30', 'season' => '2', 'episode' => '1', 'type' => 'bonus', 'duration' => '1:00:00', 'status' => 'draft' );
$r = PodPub_Episodes::sanitize( $good, PodPub_Episodes::defaults(), false );
check( 'form: good draft has no errors', $r['errors'] === array() );
check( 'form: script tag removed from show notes, links kept', false === strpos( $r['data']['show_notes'], '<script' ) && false !== strpos( $r['data']['show_notes'], '<a href=' ) );
check( 'form: values kept', $r['data']['season'] === 2 && $r['data']['episode'] === 1 && $r['data']['type'] === 'bonus' && $r['data']['duration'] === 3600 );
$r = PodPub_Episodes::sanitize( array(), PodPub_Episodes::defaults(), false );
check( 'form: empty gives title, description, date errors', count( $r['errors'] ) === 3 );
$r = PodPub_Episodes::sanitize( array_merge( $good, array( 'season' => '0', 'episode' => 'x', 'duration' => 'zz' ) ), PodPub_Episodes::defaults(), false );
check( 'form: bad season, episode, duration each refused', count( $r['errors'] ) === 3 );
$r = PodPub_Episodes::sanitize( array_merge( $good, array( 'status' => 'published' ) ), PodPub_Episodes::defaults(), false );
check( 'form: publishing without audio refused', count( $r['errors'] ) === 1 );
$r = PodPub_Episodes::sanitize( array_merge( $good, array( 'status' => 'published' ) ), PodPub_Episodes::defaults(), true );
check( 'form: publishing with audio ok', $r['errors'] === array() );
$r = PodPub_Episodes::sanitize( array_merge( $good, array( 'audio' => 'evil.php', 'guid' => 'x', 'audio_size' => 9 ) ), PodPub_Episodes::defaults(), false );
check( 'form: audio fields and guid cannot be set from the form', $r['data']['audio'] === '' && $r['data']['guid'] === '' && $r['data']['audio_size'] === 0 );

// Set up the podcast (folder + details so the feed can be written).
$pdir = PodPub_Storage::create_podcast_dir( 'My Show' );
update_post_meta( 5, '_podpub_dir', $pdir );
$pf = "$tmp/uploads/podpublisher/$pdir";
$sd = PodPub_Feeds::save_details( 5, array( 'title' => 'My Show', 'description' => 'About', 'language' => 'en' ) );
check( 'setup: podcast feed written with no items', $sd['written'] && feed_dom( "$pf/$pdir.xml" )->evaluate( 'count(/rss/channel/item)' ) === 0.0 );
$odir = PodPub_Storage::create_podcast_dir( 'Other Show' );
update_post_meta( 6, '_podpub_dir', $odir );

// Create a published episode with audio.
$pub = array_merge( $good, array( 'status' => 'published' ) );
$r = PodPub_Episodes::save( 5, 0, $pub, up( put( "$tmp/in/up1.mp3", $MP3 ), 'My Final Mix (v2).mp3' ) );
$e1 = $r['id'];
check( 'create: no errors, feed written', $r['errors'] === array() && $r['written'] && $e1 > 0 );
$ef = "$pf/Episode_1_The_Start";
check( 'create: episode folder inside the podcast folder', is_dir( $ef ) && PodPub_Episodes::dir_name( $e1 ) === 'Episode_1_The_Start' );
check( 'create: audio stored as {folder}/{folder}.mp3, uploaded name unused', is_file( "$ef/Episode_1_The_Start.mp3" ) && ! file_exists( "$ef/My Final Mix (v2).mp3" ) );
check( 'create: folder has index.html', is_file( "$ef/index.html" ) );
$x = feed_dom( "$pf/$pdir.xml" );
check( 'feed: one item', $x->evaluate( 'count(/rss/channel/item)' ) === 1.0 );
check( 'feed: title, description, pubDate', $x->evaluate( 'string(//item/title)' ) === 'Episode 1: The Start' && $x->evaluate( 'string(//item/description)' ) === 'Hello' && $x->evaluate( 'string(//item/pubDate)' ) === 'Wed, 14 Jan 2026 22:30:00 +0000' );
check( 'feed: enclosure url, length, type', $x->evaluate( 'string(//item/enclosure/@url)' ) === "https://example.com/wp-content/uploads/podpublisher/$pdir/Episode_1_The_Start/Episode_1_The_Start.mp3" && $x->evaluate( 'string(//item/enclosure/@length)' ) === (string) strlen( $MP3 ) && $x->evaluate( 'string(//item/enclosure/@type)' ) === 'audio/mpeg' );
check( 'feed: guid is a permanent id, not a link', $x->evaluate( 'string(//item/guid/@isPermaLink)' ) === 'false' && (bool) preg_match( '/^[0-9a-f-]{36}$/', $x->evaluate( 'string(//item/guid)' ) ) );
check( 'feed: duration, season, episode (podcast and itunes tags), type', $x->evaluate( 'string(//item/itunes:duration)' ) === '1:00:00' && $x->evaluate( 'string(//item/podcast:season)' ) === '2' && $x->evaluate( 'string(//item/podcast:episode)' ) === '1' && $x->evaluate( 'string(//item/itunes:episode)' ) === '1' && $x->evaluate( 'string(//item/itunes:episodeType)' ) === 'bonus' );
check( 'feed: show notes in content:encoded', false !== strpos( $x->evaluate( 'string(//item/content:encoded)' ), 'Notes <a href="https://x.example">link</a>' ) );
$guid = $x->evaluate( 'string(//item/guid)' );

// Edit: title changes, folder does not; duration blank is read from the file; guid stays.
$r = PodPub_Episodes::save( 5, $e1, array_merge( $pub, array( 'title' => 'Renamed Episode', 'duration' => '', 'show_notes' => "Tricky ]]> text" ) ) );
check( 'edit: saved without a new upload', $r['errors'] === array() && $r['id'] === $e1 );
check( 'edit: folder not renamed with the title', is_dir( $ef ) && ! is_dir( "$pf/Renamed_Episode" ) );
$x = feed_dom( "$pf/$pdir.xml" );
check( 'edit: feed has the new title, same guid, still one item', $x->evaluate( 'string(//item/title)' ) === 'Renamed Episode' && $x->evaluate( 'string(//item/guid)' ) === $guid && $x->evaluate( 'count(//item)' ) === 1.0 );
check( 'edit: show notes containing ]]> keep the feed well-formed and intact', false !== strpos( $x->evaluate( 'string(//item/content:encoded)' ), 'Tricky ]]> text' ) );
$r = PodPub_Episodes::save( 5, $e1, array_merge( $pub, array( 'duration' => '' ) ), up( put( "$tmp/in/up2.ogg", "OggS\0\x02" . str_repeat( "\0", 30 ) ), 'x.ogg' ) );
check( 'edit: new audio of another type replaces the old file', is_file( "$ef/Episode_1_The_Start.ogg" ) && ! is_file( "$ef/Episode_1_The_Start.mp3" ) );
check( 'edit: blank duration read from the file', PodPub_Episodes::details( $e1 )['duration'] === 61 );
$r = PodPub_Episodes::save( 5, $e1, array_merge( $pub, array( 'duration' => '5:00' ) ), up( put( "$tmp/in/bad.mp3", 'not audio at all, just text' ), 'x.mp3' ) );
check( 'edit: bad audio refused, nothing changed', count( $r['errors'] ) === 1 && is_file( "$ef/Episode_1_The_Start.ogg" ) && PodPub_Episodes::details( $e1 )['duration'] === 61 );
$r = PodPub_Episodes::save( 5, $e1, $pub, array( 'error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '', 'name' => 'big.mp3' ) );
check( 'edit: too-large upload reported with the server limit', count( $r['errors'] ) === 1 && false !== strpos( $r['errors'][0], '67108864' ) );

// Rules about what is created or shown.
$before = count( $GLOBALS['posts'] );
$r = PodPub_Episodes::save( 5, 0, array_merge( $good, array( 'title' => 'No Audio', 'status' => 'published' ) ) );
check( 'create: publishing without audio refused, no record and no folder made', count( $r['errors'] ) === 1 && count( $GLOBALS['posts'] ) === $before && ! is_dir( "$pf/No_Audio" ) );
$r = PodPub_Episodes::save( 5, 0, array_merge( $good, array( 'title' => 'Draft Idea' ) ), up( put( "$tmp/in/up3.mp3", $MP3 ), 'd.mp3' ) );
check( 'create: draft with audio is saved but not in the feed', $r['errors'] === array() && feed_dom( "$pf/$pdir.xml" )->evaluate( 'count(//item)' ) === 1.0 );
$r = PodPub_Episodes::save( 5, 0, array_merge( $pub, array( 'title' => 'From The Future', 'published' => '2099-01-01T00:00' ) ), up( put( "$tmp/in/up4.mp3", $MP3 ), 'f.mp3' ) );
check( 'create: future-dated episode left out of the feed with a warning', $r['errors'] === array() && count( $r['warnings'] ) === 1 && feed_dom( "$pf/$pdir.xml" )->evaluate( 'count(//item)' ) === 1.0 );
$r = PodPub_Episodes::save( 5, 0, array_merge( $pub, array( 'title' => 'Older One', 'published' => '2025-06-01T08:00' ) ), up( put( "$tmp/in/up5.mp3", $MP3 ), 'o.mp3' ) );
$x = feed_dom( "$pf/$pdir.xml" );
check( 'feed: newest first', $x->evaluate( 'count(//item)' ) === 2.0 && $x->evaluate( 'string(//item[1]/title)' ) === 'Episode 1: The Start' && $x->evaluate( 'string(//item[2]/title)' ) === 'Older One' );
$r = PodPub_Episodes::save( 5, 0, array_merge( $good, array( 'title' => 'Episode 1: The Start' ) ) );
check( 'create: same title gets its own folder (_2)', is_dir( "$pf/Episode_1_The_Start_2" ) );
$r = PodPub_Episodes::save( 6, $e1, $good );
check( 'edit: an episode of another podcast cannot be edited through this one', count( $r['errors'] ) === 1 && PodPub_Episodes::get( $e1, 6 ) === null );
$r = PodPub_Episodes::delete( 6, $e1 );
check( 'delete: cannot delete through another podcast', count( $r['errors'] ) === 1 && is_dir( $ef ) );

// Delete: folder, record and feed item go; a link inside the folder is not followed.
mkdir( "$tmp/outside", 0755, true );
put( "$tmp/outside/keep.txt", 'keep me' );
symlink( "$tmp/outside", "$ef/link_out" );
$r = PodPub_Episodes::delete( 5, $e1 );
check( 'delete: no errors, feed rewritten', $r['errors'] === array() && $r['written'] );
check( 'delete: folder and record gone', ! file_exists( $ef ) && PodPub_Episodes::get( $e1, 5 ) === null );
check( 'delete: file behind a symbolic link was not touched', is_file( "$tmp/outside/keep.txt" ) );
$x = feed_dom( "$pf/$pdir.xml" );
check( 'delete: item gone from the feed', $x->evaluate( 'count(//item)' ) === 1.0 && $x->evaluate( 'string(//item/title)' ) === 'Older One' );
check( 'delete: other episodes untouched', is_dir( "$pf/Older_One" ) );
check( 'delete: unknown episode refused', count( PodPub_Episodes::delete( 5, 99999 )['errors'] ) === 1 );

// Clean up.
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { ( $f->isDir() && ! $f->isLink() ) ? rmdir( $f ) : unlink( $f ); }
rmdir( $tmp );
exit( $fail );
