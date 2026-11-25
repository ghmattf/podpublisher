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
$GLOBALS['probe_calls'] = 0;
$GLOBALS['probe'] = array( 'code' => 200, 'headers' => array( 'content-length' => '5555', 'content-type' => 'audio/mpeg' ) );
function wp_safe_remote_head( $u, $a = array() ) { $GLOBALS['probe_calls']++; return $GLOBALS['probe'] ? array( 'code' => $GLOBALS['probe']['code'], 'headers' => $GLOBALS['probe']['headers'] ) : new WP_Error( 'x', 'unreachable' ); }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_header( $r, $h ) { return isset( $r['headers'][ $h ] ) ? $r['headers'][ $h ] : ''; }
class WP_Error { public $m; function __construct( $c, $m ) { $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }

foreach ( array( 'settings', 'storage', 'p20', 'images', 'details', 'posts', 'episodes', 'feedxml', 'feeds' ) as $c ) { require __DIR__ . "/../plugin/includes/class-podpub-$c.php"; }

$fail = 0;
function check( $name, $ok ) { global $fail; echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n"; if ( ! $ok ) { $fail = 1; } }
function esave() { $a = func_get_args(); $r = call_user_func_array( array( 'PodPub_Episodes', 'save' ), $a ); if ( ! $r['errors'] ) { $w = PodPub_FeedXml::write( $a[0] ); $r['written'] = ! is_wp_error( $w ); } return $r; } // stands in for an episode save followed by the podcast-level Save and write feed
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
$good = array( 'title' => 'Episode 1: The Start', 'description' => 'Hello', 'show_notes' => '<p>Notes <a href="https://x.example">link</a></p><script>alert(1)</script>', 'explicit' => 'no', 'published' => '2026-01-15T09:30', 'season' => '2', 'episode' => '1', 'type' => 'bonus', 'duration' => '1:00:00', 'status' => 'draft' );
$r = PodPub_Episodes::sanitize( $good, PodPub_Episodes::defaults(), false );
check( 'form: good draft has no errors', $r['errors'] === array() );
check( 'form: script tag removed from show notes, links kept', false === strpos( $r['data']['show_notes'], '<script' ) && false !== strpos( $r['data']['show_notes'], '<a href=' ) );
check( 'form: values kept', $r['data']['season'] === 2 && $r['data']['episode'] === 1 && $r['data']['type'] === 'bonus' && $r['data']['duration'] === 3600 );
$r = PodPub_Episodes::sanitize( array(), PodPub_Episodes::defaults(), false );
check( 'form: empty gives title, description, date errors', count( $r['errors'] ) === 4 );
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
$sd = PodPub_Feeds::save_details( 5, array( 'title' => 'My Show', 'description' => 'About', 'language' => 'en', 'explicit' => 'no' ) );
check( 'setup: podcast feed written with no items', $sd['written'] && feed_dom( "$pf/$pdir.xml" )->evaluate( 'count(/rss/channel/item)' ) === 0.0 );
$odir = PodPub_Storage::create_podcast_dir( 'Other Show' );
update_post_meta( 6, '_podpub_dir', $odir );

// Create a published episode with audio.
$pub = array_merge( $good, array( 'status' => 'published' ) );
$r = esave( 5, 0, $pub, up( put( "$tmp/in/up1.mp3", $MP3 ), 'My Final Mix (v2).mp3' ) );
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
$r = esave( 5, $e1, array_merge( $pub, array( 'title' => 'Renamed Episode', 'duration' => '', 'show_notes' => "Tricky ]]> text" ) ) );
check( 'edit: saved without a new upload', $r['errors'] === array() && $r['id'] === $e1 );
check( 'edit: folder not renamed with the title', is_dir( $ef ) && ! is_dir( "$pf/Renamed_Episode" ) );
$x = feed_dom( "$pf/$pdir.xml" );
check( 'edit: feed has the new title, same guid, still one item', $x->evaluate( 'string(//item/title)' ) === 'Renamed Episode' && $x->evaluate( 'string(//item/guid)' ) === $guid && $x->evaluate( 'count(//item)' ) === 1.0 );
check( 'edit: show notes containing ]]> keep the feed well-formed and intact', false !== strpos( $x->evaluate( 'string(//item/content:encoded)' ), 'Tricky ]]> text' ) );
$r = esave( 5, $e1, array_merge( $pub, array( 'duration' => '' ) ), up( put( "$tmp/in/up2.ogg", "OggS\0\x02" . str_repeat( "\0", 30 ) ), 'x.ogg' ) );
check( 'edit: new audio of another type replaces the old file', is_file( "$ef/Episode_1_The_Start.ogg" ) && ! is_file( "$ef/Episode_1_The_Start.mp3" ) );
check( 'edit: blank duration read from the file', PodPub_Episodes::details( $e1 )['duration'] === 61 );
$r = esave( 5, $e1, array_merge( $pub, array( 'duration' => '5:00' ) ), up( put( "$tmp/in/bad.mp3", 'not audio at all, just text' ), 'x.mp3' ) );
check( 'edit: bad audio refused, nothing changed', count( $r['errors'] ) === 1 && is_file( "$ef/Episode_1_The_Start.ogg" ) && PodPub_Episodes::details( $e1 )['duration'] === 61 );
$r = esave( 5, $e1, $pub, array( 'error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '', 'name' => 'big.mp3' ) );
check( 'edit: too-large upload reported with the server limit', count( $r['errors'] ) === 1 && false !== strpos( $r['errors'][0], '67108864' ) );

// Rules about what is created or shown.
$before = count( $GLOBALS['posts'] );
$r = esave( 5, 0, array_merge( $good, array( 'title' => 'No Audio', 'status' => 'published' ) ) );
check( 'create: publishing without audio refused, no record and no folder made', count( $r['errors'] ) === 1 && count( $GLOBALS['posts'] ) === $before && ! is_dir( "$pf/No_Audio" ) );
$r = esave( 5, 0, array_merge( $good, array( 'title' => 'Draft Idea' ) ), up( put( "$tmp/in/up3.mp3", $MP3 ), 'd.mp3' ) );
check( 'create: draft with audio is saved but not in the feed', $r['errors'] === array() && feed_dom( "$pf/$pdir.xml" )->evaluate( 'count(//item)' ) === 1.0 );
$r = esave( 5, 0, array_merge( $pub, array( 'title' => 'From The Future', 'explicit' => 'no', 'published' => '2099-01-01T00:00' ) ), up( put( "$tmp/in/up4.mp3", $MP3 ), 'f.mp3' ) );
check( 'create: future-dated episode left out of the feed with a warning', $r['errors'] === array() && count( $r['warnings'] ) === 1 && feed_dom( "$pf/$pdir.xml" )->evaluate( 'count(//item)' ) === 1.0 );
$r = esave( 5, 0, array_merge( $pub, array( 'title' => 'Older One', 'explicit' => 'no', 'published' => '2025-06-01T08:00' ) ), up( put( "$tmp/in/up5.mp3", $MP3 ), 'o.mp3' ) );
$x = feed_dom( "$pf/$pdir.xml" );
check( 'feed: newest first', $x->evaluate( 'count(//item)' ) === 2.0 && $x->evaluate( 'string(//item[1]/title)' ) === 'Episode 1: The Start' && $x->evaluate( 'string(//item[2]/title)' ) === 'Older One' );
$r = esave( 5, 0, array_merge( $good, array( 'title' => 'Episode 1: The Start' ) ) );
check( 'create: same title gets its own folder (_2)', is_dir( "$pf/Episode_1_The_Start_2" ) );
$r = esave( 6, $e1, $good );
check( 'edit: an episode of another podcast cannot be edited through this one', count( $r['errors'] ) === 1 && PodPub_Episodes::get( $e1, 6 ) === null );
$r = PodPub_Episodes::delete( 6, $e1 );
check( 'delete: cannot delete through another podcast', count( $r['errors'] ) === 1 && is_dir( $ef ) );

// Delete: folder, record and feed item go; a link inside the folder is not followed.
mkdir( "$tmp/outside", 0755, true );
put( "$tmp/outside/keep.txt", 'keep me' );
symlink( "$tmp/outside", "$ef/link_out" );
$x_before = file_get_contents( "$pf/$pdir.xml" );
$r = PodPub_Episodes::delete( 5, $e1 );
check( 'delete: no errors, and the feed file is NOT changed by it', $r['errors'] === array() && ! $r['written'] && $x_before === file_get_contents( "$pf/$pdir.xml" ) );
check( 'delete: folder and record gone', ! file_exists( $ef ) && PodPub_Episodes::get( $e1, 5 ) === null );
check( 'delete: file behind a symbolic link was not touched', is_file( "$tmp/outside/keep.txt" ) );
check( 'delete: the feed still lists it until Save and write feed', feed_dom( "$pf/$pdir.xml" )->evaluate( 'count(//item)' ) === 2.0 );
PodPub_FeedXml::write( 5 );
$x = feed_dom( "$pf/$pdir.xml" );
check( 'delete: item gone from the feed after the podcast-level write', $x->evaluate( 'count(//item)' ) === 1.0 && $x->evaluate( 'string(//item/title)' ) === 'Older One' );
check( 'delete: other episodes untouched', is_dir( "$pf/Older_One" ) );
check( 'delete: unknown episode refused', count( PodPub_Episodes::delete( 5, 99999 )['errors'] ) === 1 );

// ---- Audio hosted elsewhere ----
$cur0 = PodPub_Episodes::defaults();
$ce = function ( $url, $size = '', $type = '' ) use ( $cur0 ) { return PodPub_Episodes::check_external( $url, $size, $type, $cur0 ); };
$GLOBALS['probe_calls'] = 0;
$r = $ce( 'https://cdn.example.net/ep/one.mp3', '1234' );
check( 'external: https address with a known extension and a size ok', ! is_wp_error( $r ) && $r['type'] === 'audio/mpeg' && $r['size'] === 1234 );
check( 'external: http refused', is_wp_error( $ce( 'http://cdn.example.net/a.mp3' ) ) );
check( 'external: address with a space or quote refused', is_wp_error( $ce( 'https://cdn.example.net/my file.mp3' ) ) && is_wp_error( $ce( 'https://cdn.example.net/a"b.mp3' ) ) );
check( 'external: javascript: and ftp: refused', is_wp_error( $ce( 'javascript:alert(1)' ) ) && is_wp_error( $ce( 'ftp://example.net/a.mp3' ) ) );
check( 'external: size must be whole bytes', is_wp_error( $ce( 'https://cdn.example.net/a.mp3', '12.5' ) ) && is_wp_error( $ce( 'https://cdn.example.net/a.mp3', '-1' ) ) );
check( 'external: unknown type chosen is refused', is_wp_error( $ce( 'https://cdn.example.net/a.mp3', '100', 'exe' ) ) );
$r = $ce( 'https://cdn.example.net/download?id=7', '', '' );
check( 'external: no extension, type and size come from the other server', ! is_wp_error( $r ) && $r['type'] === 'audio/mpeg' && $r['size'] === 5555 );
$GLOBALS['probe']['headers']['content-type'] = 'text/html';
check( 'external: no extension and server does not say audio: asked to choose', is_wp_error( $ce( 'https://cdn.example.net/download?id=7', '100' ) ) );
check( 'external: type chosen by hand is accepted', ( $r = $ce( 'https://cdn.example.net/download?id=7', '100', 'm4a' ) ) && ! is_wp_error( $r ) && $r['type'] === 'audio/mp4' );
$GLOBALS['probe'] = false;
$r = $ce( 'https://cdn.example.net/a.mp3', '100' );
check( 'external: unreachable server is a warning, not an error', ! is_wp_error( $r ) && count( $r['warnings'] ) === 1 );
$r = $ce( 'https://cdn.example.net/a.mp3', '' );
check( 'external: unknown size warns', ! is_wp_error( $r ) && $r['size'] === 0 && count( $r['warnings'] ) === 2 );
$GLOBALS['probe'] = array( 'code' => 404, 'headers' => array() );
$r = $ce( 'https://cdn.example.net/a.mp3', '100' );
check( 'external: a 404 counts as could not check', ! is_wp_error( $r ) && count( $r['warnings'] ) === 1 );
$GLOBALS['probe'] = array( 'code' => 200, 'headers' => array( 'content-length' => '5555', 'content-type' => 'audio/mpeg' ) );

$ext = array_merge( $pub, array( 'title' => 'Hosted Elsewhere', 'audio_source' => 'external', 'audio_url' => 'https://cdn.example.net/ep/one.mp3', 'audio_size' => '', 'audio_ext_type' => '' ) );
$GLOBALS['probe_calls'] = 0;
$r = esave( 5, 0, $ext );
$h = $r['id'];
check( 'external save: no file needed, published, feed written', $r['errors'] === array() && $r['written'] && $h > 0 );
$d = PodPub_Episodes::details( $h );
check( 'external save: fields stored, size read from the other server', $d['audio_source'] === 'external' && $d['external_url'] === 'https://cdn.example.net/ep/one.mp3' && $d['audio'] === '' && $d['audio_size'] === 5555 && $d['audio_type'] === 'audio/mpeg' && $GLOBALS['probe_calls'] === 1 );
$x = feed_dom( "$pf/$pdir.xml" );
check( 'external save: enclosure points at the other server', $x->evaluate( 'string(//item[title="Hosted Elsewhere"]/enclosure/@url)' ) === 'https://cdn.example.net/ep/one.mp3' && $x->evaluate( 'string(//item[title="Hosted Elsewhere"]/enclosure/@length)' ) === '5555' );
check( 'external save: episode folder made but holds no audio', is_dir( "$pf/Hosted_Elsewhere" ) && count( glob( "$pf/Hosted_Elsewhere/*.{mp3,ogg,m4a}", GLOB_BRACE ) ) === 0 );
esave( 5, $h, array_merge( $ext, array( 'audio_size' => '5555' ) ) );
check( 'external save: unchanged address is not checked again', $GLOBALS['probe_calls'] === 1 );
esave( 5, $h, array_merge( $ext, array( 'audio_url' => 'https://cdn.example.net/ep/two.mp3', 'audio_size' => '5555' ) ) );
check( 'external save: a new address is checked', $GLOBALS['probe_calls'] === 2 && PodPub_Episodes::details( $h )['external_url'] === 'https://cdn.example.net/ep/two.mp3' );
$r = esave( 5, $h, array_merge( $ext, array( 'audio_url' => 'http://cdn.example.net/ep/three.mp3' ) ) );
check( 'external save: bad address refused, old one kept', count( $r['errors'] ) === 1 && PodPub_Episodes::details( $h )['external_url'] === 'https://cdn.example.net/ep/two.mp3' );
$r = esave( 5, $h, array_merge( $ext, array( 'audio_url' => '', 'status' => 'published' ) ) );
check( 'external save: empty address keeps the current audio', $r['errors'] === array() && PodPub_Episodes::details( $h )['external_url'] === 'https://cdn.example.net/ep/two.mp3' );
$r = esave( 5, 0, array_merge( $ext, array( 'title' => 'Hosted Nothing', 'audio_url' => '' ) ) );
check( 'external save: published with no address and no upload is refused', count( $r['errors'] ) === 1 );
$r = esave( 5, $h, array_merge( $pub, array( 'title' => 'Hosted Elsewhere', 'audio_source' => 'upload' ) ), up( put( "$tmp/in/sw.mp3", $MP3 ), 's.mp3' ) );
$d = PodPub_Episodes::details( $h );
check( 'switch: uploading replaces the hosted address', $r['errors'] === array() && $d['audio_source'] === 'upload' && $d['external_url'] === '' && is_file( "$pf/Hosted_Elsewhere/Hosted_Elsewhere.mp3" ) );
check( 'switch: feed now points at the local file', false !== strpos( feed_dom( "$pf/$pdir.xml" )->evaluate( 'string(//item[title="Hosted Elsewhere"]/enclosure/@url)' ), '/Hosted_Elsewhere/Hosted_Elsewhere.mp3' ) );
esave( 5, $h, array_merge( $ext, array( 'audio_url' => 'https://cdn.example.net/ep/back.mp3', 'audio_size' => '5555' ) ) );
check( 'switch: going back to a hosted address clears the file name', PodPub_Episodes::details( $h )['audio'] === '' && PodPub_Episodes::details( $h )['external_url'] === 'https://cdn.example.net/ep/back.mp3' );

// ---- Audio copied into the episode folder (for example by FTP) ----
$r = esave( 5, 0, array_merge( $good, array( 'title' => 'Big File', 'status' => 'draft', 'audio_source' => 'folder', 'audio_pick' => 'x.mp3' ) ) );
check( 'folder: a new episode cannot pick yet (it has no folder)', count( $r['errors'] ) === 1 && false !== strpos( $r['errors'][0], 'Save the episode first' ) );
$r = esave( 5, 0, array_merge( $good, array( 'title' => 'Big File', 'status' => 'draft' ) ) );
$b = $r['id'];
$bf = "$pf/Big_File";
check( 'folder: draft episode created with its folder', $r['errors'] === array() && is_dir( $bf ) );
check( 'folder: nothing to pick yet', PodPub_Episodes::list_folder_audio( 5, $b ) === array() );
put( "$bf/My Big File (final).MP3", $MP3 );
put( "$bf/notes.txt", 'hello' );
put( "$bf/.hidden.mp3", $MP3 );
put( "$bf/fake.mp3", 'this is not audio, just text padding padding' );
mkdir( "$bf/sub", 0755 );
symlink( "$tmp/outside_audio.mp3", "$bf/link.mp3" );
check( 'folder: list shows audio files only (no index.html, notes, hidden files, folders, links)', PodPub_Episodes::list_folder_audio( 5, $b ) === array( 'My Big File (final).MP3', 'fake.mp3' ) );
$pick = array_merge( $pub, array( 'title' => 'Big File', 'audio_source' => 'folder' ) );
foreach ( array( '../Hosted_Elsewhere/Hosted_Elsewhere.mp3', 'sub', 'nothere.mp3', '.hidden.mp3', 'link.mp3', 'fake.mp3', 'notes.txt' ) as $bad ) {
	$r = esave( 5, $b, array_merge( $pick, array( 'audio_pick' => $bad ) ) );
	check( "folder: refuses '$bad'", count( $r['errors'] ) >= 1 && PodPub_Episodes::details( $b )['audio'] === '' );
}
$r = esave( 5, $b, array_merge( $pick, array( 'audio_pick' => 'My Big File (final).MP3' ) ) );
check( 'folder: a name that needs renaming is refused without the tick, with a warning naming both names, and nothing is renamed or saved', count( $r['errors'] ) >= 1 && false !== strpos( $r['errors'][0], 'My Big File (final).MP3' ) && false !== strpos( $r['errors'][0], 'My_Big_File_final.mp3' ) && false !== strpos( $r['errors'][0], 'Nothing was saved' ) && is_file( "$bf/My Big File (final).MP3" ) && PodPub_Episodes::details( $b )['audio'] === '' );
$r = esave( 5, $b, array_merge( $pick, array( 'audio_pick' => 'My Big File (final).MP3', 'rename_ok' => '1' ) ) );
$d = PodPub_Episodes::details( $b );
check( 'folder: pick saved, file renamed to a safe name', $r['errors'] === array() && $d['audio'] === 'My_Big_File_final.mp3' && is_file( "$bf/My_Big_File_final.mp3" ) && ! file_exists( "$bf/My Big File (final).MP3" ) );
check( 'folder: type, size and source stored', $d['audio_source'] === 'folder' && $d['audio_type'] === 'audio/mpeg' && $d['audio_size'] === strlen( $MP3 ) );
check( 'folder: feed points at the picked file', false !== strpos( feed_dom( "$pf/$pdir.xml" )->evaluate( 'string(//item[title="Big File"]/enclosure/@url)' ), '/Big_File/My_Big_File_final.mp3' ) );
put( "$bf/Second One.mp3", $MP3 );
put( "$bf/Second_One.mp3", $MP3 );
$r = esave( 5, $b, array_merge( $pick, array( 'audio_pick' => 'Second One.mp3', 'rename_ok' => '1' ) ) );
check( 'folder: a safe name that already exists gets _2, nothing overwritten', PodPub_Episodes::details( $b )['audio'] === 'Second_One_2.mp3' && is_file( "$bf/Second_One.mp3" ) );
put( "$bf/clean-name.mp3", $MP3 );
esave( 5, $b, array_merge( $pick, array( 'audio_pick' => 'clean-name.mp3' ) ) );
check( 'folder: an already-safe name is kept as it is', PodPub_Episodes::details( $b )['audio'] === 'clean-name.mp3' );
check( 'folder: files picked earlier are left in place', is_file( "$bf/My_Big_File_final.mp3" ) );
check( 'folder: safe_file_name rules', PodPub_Episodes::safe_file_name( 'a-b_c.1.mp3' ) && ! PodPub_Episodes::safe_file_name( 'a b.mp3' ) && ! PodPub_Episodes::safe_file_name( '.x.mp3' ) && ! PodPub_Episodes::safe_file_name( 'a..b.mp3' ) && ! PodPub_Episodes::safe_file_name( 'é.mp3' ) );
PodPub_Episodes::delete( 5, $b );
check( 'folder: deleting the episode removes its folder and everything in it', ! file_exists( $bf ) );
PodPub_Episodes::delete( 5, $h );

// Clean up.
// ---- Episode saves store only; the podcast-level write publishes (TODO 24) ----
PodPub_FeedXml::write( 5 );
$before = file_get_contents( "$pf/$pdir.xml" );
$st = PodPub_FeedXml::status( 5 );
check( 'status: right after a write the feed is current', 'current' === $st['state'] );
$r = PodPub_Episodes::save( 5, 0, array_merge( $good, array( 'title' => 'Stored Only', 'status' => 'draft' ) ) );
check( 'episode save: stores the episode, does not write or touch the feed file', $r['errors'] === array() && ! $r['written'] && $before === file_get_contents( "$pf/$pdir.xml" ) );
$bak_before = count( glob( "$pf/$pdir.xml.bak*" ) );
$r2 = PodPub_Episodes::save( 5, 0, array_merge( $good, array( 'title' => 'Stored Second', 'status' => 'draft' ) ) );
check( 'episode save: makes no backup either', count( glob( "$pf/$pdir.xml.bak*" ) ) === $bak_before );
$dd = PodPub_Details::get( 5 ); $dd['description'] = 'Changed in storage only.'; PodPub_Details::save( 5, $dd );
$st = PodPub_FeedXml::status( 5 );
check( 'status: stored data that differs from the file is pending, with a message', 'pending' === $st['state'] && false !== strpos( $st['message'], 'Save and write feed' ) );
$w = PodPub_FeedXml::write( 5 );
$baks = glob( "$pf/$pdir.xml.bak*" ); usort( $baks, function ( $a, $b ) { return (int) substr( strrchr( $a, 'k' ), 1 ) - (int) substr( strrchr( $b, 'k' ), 1 ); } );
check( 'podcast-level write: always makes a backup (.bak), holding the file as it was', ! is_wp_error( $w ) && sha1( file_get_contents( "$pf/$pdir.xml.bak" ) ) === sha1( $before ) );
check( 'status: current again after the write', 'current' === PodPub_FeedXml::status( 5 )['state'] );
$n = count( glob( "$pf/$pdir.xml.bak*" ) );
PodPub_FeedXml::write( 5 );
check( 'backup: a second write rotates: .bak -> .bak2', ( function () use ( $pf, $pdir, $before ) { $old = file_get_contents( "$pf/$pdir.xml.bak" ); PodPub_FeedXml::write( 5 ); return file_get_contents( "$pf/$pdir.xml.bak2" ) === $old && file_exists( "$pf/$pdir.xml.bak" ); } )() );
// Only the newest 19 extra backups are kept, and the first one is never removed.
$feed = "$pf/$pdir.xml";
file_put_contents( $feed . '.bak', 'FIRST' ); file_put_contents( $feed . '.bak7', 'OLD7' );
for ( $i = 0; $i < 30; $i++ ) { file_put_contents( $feed, "<x>$i</x>" ); PodPub_FeedXml::backup( $feed ); }
$left = glob( $feed . '.bak*' );
check( 'backup: only three are kept (.bak newest, .bak2, .bak3 oldest); .bak2 replaces .bak3, .bak replaces .bak2; older numbers removed', count( $left ) === 3 && '<x>29</x>' === file_get_contents( $feed . '.bak' ) && '<x>28</x>' === file_get_contents( $feed . '.bak2' ) && '<x>27</x>' === file_get_contents( $feed . '.bak3' ) && ! file_exists( $feed . '.bak4' ) && ! file_exists( $feed . '.bak31' ) );
// A backup that cannot be made stops the write.
@mkdir( "$tmp/rodir" );
check( 'backup: returns an empty string when the copy cannot be made', '' === PodPub_FeedXml::backup( "$tmp/does_not_exist.xml" ) );

$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { ( $f->isDir() && ! $f->isLink() ) ? rmdir( $f ) : unlink( $f ); }
rmdir( $tmp );
exit( $fail );
