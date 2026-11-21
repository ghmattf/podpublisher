<?php
// Podcast details form, show art, feed XML and the save flow, on a real temporary folder.
// Not a real WordPress: the few WordPress functions used are stood in for.
define( 'ABSPATH', __DIR__ );
define( 'PODPUB_VERSION', 'test' );
$tmp = sys_get_temp_dir() . '/podpub_fd_' . getmypid();
$GLOBALS['opts'] = array();
$GLOBALS['meta'] = array();
$GLOBALS['posts'] = array( 5 => (object) array( 'ID' => 5, 'post_type' => 'podpub_feed', 'post_status' => 'publish', 'post_title' => 'My Great Show' ) );

function wp_upload_dir() { global $tmp; return array( 'basedir' => $tmp . '/uploads', 'baseurl' => 'https://example.com/wp-content/uploads' ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0755, true ); }
function get_option( $k, $d = false ) { return isset( $GLOBALS['opts'][ $k ] ) ? $GLOBALS['opts'][ $k ] : $d; }
function get_locale() { return 'en_AU'; }
function get_post( $id ) { return isset( $GLOBALS['posts'][ $id ] ) ? $GLOBALS['posts'][ $id ] : null; }
function get_post_meta( $id, $k ) { return isset( $GLOBALS['meta'][ $id ][ $k ] ) ? $GLOBALS['meta'][ $id ][ $k ] : ''; }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; }
function get_posts() { return array(); }
function wp_update_post( $a ) { $GLOBALS['posts'][ $a['ID'] ]->post_title = $a['post_title']; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function esc_url_raw( $u ) { return $u; }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
class WP_Error { public $m; function __construct( $c, $m ) { $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }

foreach ( array( 'settings', 'storage', 'p20', 'details', 'episodes', 'feedxml', 'feeds' ) as $c ) { require __DIR__ . "/../plugin/includes/class-podpub-$c.php"; }

$fail = 0;
function check( $name, $ok ) { global $fail; echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n"; if ( ! $ok ) { $fail = 1; } }

// A picture file with the right header (getimagesize reads only the header).
function make_png( $path, $w, $h ) {
	file_put_contents( $path, "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . pack( 'NN', $w, $h ) . "\x08\x02\x00\x00\x00" . pack( 'N', 0 ) );
}

mkdir( $tmp . '/in', 0755, true );

// podcast:guid. The podcast namespace specification gives this example.
check( 'guid: spec example', PodPub_FeedXml::guid_for_url( 'https://mp3s.nashownotes.com/pc20rss.xml' ) === '917393e3-1b1e-5cef-ace4-edaa54e1f810' );
check( 'guid: scheme and trailing slash ignored', PodPub_FeedXml::guid_for_url( 'http://mp3s.nashownotes.com/pc20rss.xml/' ) === '917393e3-1b1e-5cef-ace4-edaa54e1f810' );
check( 'guid: random is a v4 uuid', (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', PodPub_FeedXml::random_guid() ) );

// Form checking.
$cur = PodPub_Details::defaults();
check( 'defaults: language from locale', $cur['language'] === 'en-au' );
$good = array( 'title' => 'My Great Show', 'description' => 'About <b>things</b>', 'language' => 'en-us', 'category' => 'Music', 'explicit' => '1', 'type' => 'serial', 'author' => 'Jo', 'link' => 'https://example.com', 'owner_name' => 'Jo B', 'owner_email' => 'jo@example.com', 'copyright' => '(c) Jo' );
$r = PodPub_Details::sanitize( $good, $cur );
check( 'form: good input has no errors', $r['errors'] === array() );
check( 'form: html stripped from description', $r['data']['description'] === 'About things' );
check( 'form: values kept', $r['data']['language'] === 'en-us' && $r['data']['category'] === 'Music' && $r['data']['explicit'] === 1 && $r['data']['type'] === 'serial' );
$r = PodPub_Details::sanitize( array(), $cur );
check( 'form: empty gives title, description and language errors', count( $r['errors'] ) === 3 );
$r = PodPub_Details::sanitize( array_merge( $good, array( 'language' => 'xx', 'category' => 'Nope', 'link' => 'javascript:alert(1)', 'owner_email' => 'bad' ) ), $cur );
check( 'form: bad language, category, link, email each refused', count( $r['errors'] ) === 4 );
$r = PodPub_Details::sanitize( array_merge( $good, array( 'description' => str_repeat( 'a', 4001 ) ) ), $cur );
check( 'form: description over 4000 refused', count( $r['errors'] ) === 1 );
$r = PodPub_Details::sanitize( array_merge( $good, array( 'title' => "Bad\x01Title\x0B" ) ), $cur );
check( 'form: control characters removed from title', $r['title'] === 'BadTitle' );
$keep = array_merge( $cur, array( 'image' => 'cover.png', 'guid' => 'abc' ) );
$r = PodPub_Details::sanitize( array_merge( $good, array( 'image' => 'evil.php', 'guid' => 'zzz' ) ), $keep );
check( 'form: image and guid cannot be set from the form', $r['data']['image'] === 'cover.png' && $r['data']['guid'] === 'abc' );

// Show art checks.
make_png( "$tmp/in/ok.png", 3000, 3000 );
$i = PodPub_Details::inspect_image( "$tmp/in/ok.png" );
check( 'image: 3000x3000 png ok, no warnings', ! is_wp_error( $i ) && $i['ext'] === 'png' && $i['warnings'] === array() );
make_png( "$tmp/in/small.png", 600, 600 );
$i = PodPub_Details::inspect_image( "$tmp/in/small.png" );
check( 'image: 600x600 accepted with a size warning', ! is_wp_error( $i ) && count( $i['warnings'] ) === 1 );
make_png( "$tmp/in/wide.png", 2000, 1000 );
$i = PodPub_Details::inspect_image( "$tmp/in/wide.png" );
check( 'image: not square accepted with a warning', ! is_wp_error( $i ) && count( $i['warnings'] ) === 1 );
file_put_contents( "$tmp/in/fake.png", '<?php echo 1;' );
check( 'image: php disguised as png refused', is_wp_error( PodPub_Details::inspect_image( "$tmp/in/fake.png" ) ) );
function make_gif( $path, $w, $h ) { file_put_contents( $path, 'GIF89a' . pack( 'vv', $w, $h ) . "\x00\x00\x00;" ); }
make_gif( "$tmp/in/a.gif", 1400, 1400 );
$i = PodPub_Details::inspect_image( "$tmp/in/a.gif" );
check( 'image: gif accepted, with a warning that some directories refuse it', ! is_wp_error( $i ) && $i['ext'] === 'gif' && count( $i['warnings'] ) === 1 && false !== strpos( $i['warnings'][0], 'GIF' ) );
make_gif( "$tmp/in/small.gif", 100, 100 );
$i = PodPub_Details::inspect_image( "$tmp/in/small.gif" );
check( 'image: small gif gets both warnings', ! is_wp_error( $i ) && count( $i['warnings'] ) === 2 );
file_put_contents( "$tmp/in/a.bmp", 'BM' . str_repeat( "\0", 60 ) );
check( 'image: bmp still refused', is_wp_error( PodPub_Details::inspect_image( "$tmp/in/a.bmp" ) ) );
file_put_contents( "$tmp/in/fake.gif", '<?php echo 1;' );
check( 'image: php disguised as gif refused', is_wp_error( PodPub_Details::inspect_image( "$tmp/in/fake.gif" ) ) );
check( 'image: missing file refused', is_wp_error( PodPub_Details::inspect_image( "$tmp/in/none.png" ) ) );

// Feed XML.
$xml = PodPub_FeedXml::build( array(
	'title' => 'Fish & Chips <live>', 'feed_url' => 'https://example.com/p/Show/Show.xml', 'image_url' => 'https://example.com/p/Show/cover.jpg', 'now' => 1700000000,
	'details' => array_merge( PodPub_Details::defaults(), array( 'description' => 'Tom & "Jerry"', 'language' => 'en', 'category' => 'Music', 'explicit' => 1, 'author' => 'Jo', 'link' => 'https://example.com', 'owner_name' => 'Jo', 'owner_email' => 'jo@example.com', 'copyright' => '(c)', 'guid' => '917393e3-1b1e-5cef-ace4-edaa54e1f810' ) ),
) );
$dom = new DOMDocument();
check( 'xml: well-formed', $dom->loadXML( $xml ) );
$x = new DOMXPath( $dom );
$x->registerNamespace( 'itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd' );
$x->registerNamespace( 'podcast', 'https://podcastindex.org/namespace/1.0' );
$x->registerNamespace( 'atom', 'http://www.w3.org/2005/Atom' );
check( 'xml: title with & and < round-trips', $x->evaluate( 'string(/rss/channel/title)' ) === 'Fish & Chips <live>' );
check( 'xml: description with quotes round-trips', $x->evaluate( 'string(/rss/channel/description)' ) === 'Tom & "Jerry"' );
check( 'xml: lastBuildDate', $x->evaluate( 'string(/rss/channel/lastBuildDate)' ) === 'Tue, 14 Nov 2023 22:13:20 +0000' );
check( 'xml: atom self link', $x->evaluate( 'string(/rss/channel/atom:link[@rel="self"]/@href)' ) === 'https://example.com/p/Show/Show.xml' );
check( 'xml: itunes image, category, explicit, type', $x->evaluate( 'string(/rss/channel/itunes:image/@href)' ) === 'https://example.com/p/Show/cover.jpg' && $x->evaluate( 'string(/rss/channel/itunes:category/@text)' ) === 'Music' && $x->evaluate( 'string(/rss/channel/itunes:explicit)' ) === 'true' && $x->evaluate( 'string(/rss/channel/itunes:type)' ) === 'episodic' );
check( 'xml: podcast guid and medium', $x->evaluate( 'string(/rss/channel/podcast:guid)' ) === '917393e3-1b1e-5cef-ace4-edaa54e1f810' && $x->evaluate( 'string(/rss/channel/podcast:medium)' ) === 'podcast' );
check( 'xml: owner', $x->evaluate( 'string(/rss/channel/itunes:owner/itunes:email)' ) === 'jo@example.com' );
$min = PodPub_FeedXml::build( array( 'title' => 'T', 'details' => array_merge( PodPub_Details::defaults(), array( 'description' => 'D' ) ) ) );
$d2  = new DOMDocument();
check( 'xml: minimal feed well-formed, no empty optional tags', $d2->loadXML( $min ) && false === strpos( $min, 'itunes:image' ) && false === strpos( $min, 'itunes:owner' ) && false === strpos( $min, 'podcast:guid' ) && false === strpos( $min, 'atom:link' ) );

// Save flow on disk.
$dir = PodPub_Storage::create_podcast_dir( 'My Great Show' );
update_post_meta( 5, '_podpub_dir', $dir );
$folder = "$tmp/uploads/podpublisher/$dir";
$r = PodPub_Feeds::save_details( 5, array( 'title' => '', 'description' => '' ) );
check( 'save: errors save nothing', count( $r['errors'] ) === 3 && ! is_file( "$folder/$dir.xml" ) && get_post_meta( 5, '_podpub_details' ) === '' );
make_png( "$tmp/in/cover_up.png", 1400, 1400 );
$file = array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => "$tmp/in/cover_up.png", 'name' => 'My Cover (final).png' );
$r = PodPub_Feeds::save_details( 5, array_merge( $good, array( 'title' => 'Renamed Show' ) ), $file );
check( 'save: no errors, feed written', $r['errors'] === array() && $r['written'] === true );
check( 'save: feed file is {folder}/{folder}.xml', is_file( "$folder/$dir.xml" ) );
check( 'save: cover stored as cover.png, original name unused', is_file( "$folder/cover.png" ) && ! file_exists( "$folder/My Cover (final).png" ) );
$saved = file_get_contents( "$folder/$dir.xml" );
$dd = new DOMDocument();
check( 'save: written feed is well-formed with the new title', $dd->loadXML( $saved ) && ( new DOMXPath( $dd ) )->evaluate( 'string(/rss/channel/title)' ) === 'Renamed Show' );
check( 'save: image url in feed', strpos( $saved, 'https://example.com/wp-content/uploads/podpublisher/My_Great_Show/cover.png' ) !== false );
check( 'save: folder name not renamed with the title', is_dir( $folder ) && ! is_dir( "$tmp/uploads/podpublisher/Renamed_Show" ) );
$g = PodPub_Details::get( 5 )['guid'];
check( 'save: guid made from the feed address', $g === PodPub_FeedXml::guid_for_url( 'https://example.com/wp-content/uploads/podpublisher/My_Great_Show/My_Great_Show.xml' ) );
PodPub_Feeds::save_details( 5, $good );
check( 'save: guid and image unchanged on a second save', PodPub_Details::get( 5 )['guid'] === $g && PodPub_Details::get( 5 )['image'] === 'cover.png' );
$jpg = "$tmp/in/c.jpg";
$im = function_exists( 'imagecreatetruecolor' ) ? imagecreatetruecolor( 1400, 1400 ) : null;
if ( $im ) {
	imagejpeg( $im, $jpg );
	$r = PodPub_Feeds::save_details( 5, $good, array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $jpg, 'name' => 'x.jpg' ) );
	check( 'save: new jpg replaces old png cover', is_file( "$folder/cover.jpg" ) && ! is_file( "$folder/cover.png" ) && PodPub_Details::get( 5 )['image'] === 'cover.jpg' );
} else {
	echo "skip save: jpg replace (GD not installed)\n";
}
$r = PodPub_Feeds::save_details( 5, $good, array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => "$tmp/in/a.gif", 'name' => 'Funny (1).GIF' ) );
check( 'save: gif cover stored as cover.gif, old covers removed, warning shown', $r['errors'] === array() && is_file( "$folder/cover.gif" ) && ! is_file( "$folder/cover.jpg" ) && ! is_file( "$folder/cover.png" ) && PodPub_Details::get( 5 )['image'] === 'cover.gif' && count( $r['warnings'] ) === 1 );
check( 'save: feed points at cover.gif', false !== strpos( file_get_contents( "$folder/$dir.xml" ), '/My_Great_Show/cover.gif' ) );
$r = PodPub_Feeds::save_details( 5, $good, array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => "$tmp/in/cover_up.png", 'name' => 'x.png' ) );
check( 'save: png after gif removes the gif', is_file( "$folder/cover.png" ) && ! is_file( "$folder/cover.gif" ) );
$r = PodPub_Feeds::save_details( 5, $good, array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => "$tmp/in/fake.png", 'name' => 'x.png' ) );
check( 'save: bad image refused and nothing changed', count( $r['errors'] ) === 1 );
$r = PodPub_Feeds::save_details( 5, $good, array( 'error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '', 'name' => 'x.png' ) );
check( 'save: failed upload reported', count( $r['errors'] ) === 1 );
$r = PodPub_Feeds::save_details( 99, $good );
check( 'save: unknown podcast refused', count( $r['errors'] ) === 1 );

// Clean up.
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { $f->isDir() ? rmdir( $f ) : unlink( $f ); }
rmdir( $tmp );
exit( $fail );
