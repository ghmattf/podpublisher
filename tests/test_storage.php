<?php
// Folder and file naming rules, and the podcast/episode folder layout, on a real temporary folder.
// Not a real WordPress: only the few WordPress functions the storage class calls are stood in for.
define( 'ABSPATH', __DIR__ );
$tmp = sys_get_temp_dir() . '/podpub_test_' . getmypid();
$GLOBALS['opts'] = array();

function wp_upload_dir() { global $tmp; return array( 'basedir' => $tmp . '/uploads', 'baseurl' => 'https://example.com/wp-content/uploads' ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0755, true ); }
function get_option( $k, $d = false ) { return isset( $GLOBALS['opts'][ $k ] ) ? $GLOBALS['opts'][ $k ] : $d; }
class WP_Error { public $m; function __construct( $c, $m ) { $this->m = $m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }

require __DIR__ . '/../plugin/includes/class-podpub-settings.php';
require __DIR__ . '/../plugin/includes/class-podpub-storage.php';

$fail = 0;
function check( $name, $ok ) { global $fail; echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n"; if ( ! $ok ) { $fail = 1; } }

// slug(): spaces -> _, nothing unsafe survives.
check( 'slug: spaces become underscores', PodPub_Storage::slug( 'My Great Show' ) === 'My_Great_Show' );
check( 'slug: special characters removed', PodPub_Storage::slug( 'Q&A: What? #1 / 100% "real"' ) === 'Q_and_A_What_1_100_real' );
check( 'slug: repeated spaces/tabs collapse', PodPub_Storage::slug( "a   b\t\tc" ) === 'a_b_c' );
check( 'slug: no leading/trailing _ or -', PodPub_Storage::slug( '  --Hi there--  ' ) === 'Hi_there' );
check( 'slug: path tricks neutralised', PodPub_Storage::slug( '../../etc/passwd' ) === 'etcpasswd' );
check( 'slug: nothing usable -> fallback', PodPub_Storage::slug( '???', 'podcast' ) === 'podcast' );
check( 'slug: only A-Z a-z 0-9 _ -', (bool) preg_match( '/^[A-Za-z0-9_-]+$/', PodPub_Storage::slug( "Ünï cödé – 日本語 show ✓ [v2]" ) ) );
check( 'slug: max length', strlen( PodPub_Storage::slug( str_repeat( 'abc ', 50 ) ) ) <= PodPub_Storage::MAX_NAME );

// valid_path / valid_url
check( 'path: plain absolute ok', PodPub_Storage::valid_path( '/var/www/wp-content/uploads/podpublisher' ) );
check( 'path: space refused', ! PodPub_Storage::valid_path( '/var/www/my files/podcasts' ) );
check( 'path: special char refused', ! PodPub_Storage::valid_path( '/var/www/pod&casts' ) );
check( 'path: relative refused', ! PodPub_Storage::valid_path( 'podcasts/files' ) );
check( 'path: .. refused', ! PodPub_Storage::valid_path( '/var/www/../etc' ) );
check( 'path: windows drive ok', PodPub_Storage::valid_path( 'C:/sites/podcasts' ) );
check( 'url: https ok', PodPub_Storage::valid_url( 'https://example.com/podcasts' ) );
check( 'url: space refused', ! PodPub_Storage::valid_url( 'https://example.com/my podcasts' ) );
check( 'url: query refused', ! PodPub_Storage::valid_url( 'https://example.com/p?x=1' ) );
check( 'url: ftp refused', ! PodPub_Storage::valid_url( 'ftp://example.com/p' ) );

// Default path and URL.
check( 'default path is inside uploads', PodPub_Storage::base_path() === $tmp . '/uploads/podpublisher' );
check( 'default url derived from uploads', PodPub_Storage::base_url() === 'https://example.com/wp-content/uploads/podpublisher' );
check( 'outside uploads: no derived url', PodPub_Storage::derive_url( '/srv/other' ) === '' );

// Layout: podcast folder, then episode folders inside it, duplicates get _2, _3.
$a = PodPub_Storage::create_podcast_dir( 'My Great Show' );
check( 'podcast folder created', $a === 'My_Great_Show' && is_dir( "$tmp/uploads/podpublisher/My_Great_Show" ) );
check( 'podcast folder has index.html', is_file( "$tmp/uploads/podpublisher/My_Great_Show/index.html" ) );
$b = PodPub_Storage::create_podcast_dir( 'My Great  Show!' );
check( 'same name gets _2', $b === 'My_Great_Show_2' && is_dir( "$tmp/uploads/podpublisher/My_Great_Show_2" ) );
$e1 = PodPub_Storage::create_episode_dir( $a, 'Episode 1: The Start' );
$e2 = PodPub_Storage::create_episode_dir( $a, 'Episode 1: The Start' );
check( 'episode folder inside podcast folder', $e1 === 'Episode_1_The_Start' && is_dir( "$tmp/uploads/podpublisher/$a/$e1" ) );
check( 'duplicate episode gets _2', $e2 === 'Episode_1_The_Start_2' );
check( 'episode refused for bad podcast folder', is_wp_error( PodPub_Storage::create_episode_dir( '../x', 'ep' ) ) );
check( 'episode refused for missing podcast folder', is_wp_error( PodPub_Storage::create_episode_dir( 'Nope', 'ep' ) ) );

// Custom folder from settings is used.
$GLOBALS['opts']['podpub_settings'] = array( 'storage_path' => "$tmp/custom_pods/", 'storage_url' => 'https://cdn.example.com/pods/' );
check( 'custom path used (trailing slash trimmed)', PodPub_Storage::base_path() === "$tmp/custom_pods" );
check( 'custom url used', PodPub_Storage::base_url() === 'https://cdn.example.com/pods' );
check( 'podcast made in custom folder', PodPub_Storage::create_podcast_dir( 'Other' ) === 'Other' && is_dir( "$tmp/custom_pods/Other" ) );

// Clean up.
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $it as $f ) { $f->isDir() ? rmdir( $f ) : unlink( $f ); }
rmdir( $tmp );
// ---- Safe file names for uploaded files (TODO 25) ----
foreach ( array(
	'My Show (final) v2.MP3'      => 'My_Show_final_v2.mp3',
	'ep 1 & more!.mp3'            => 'ep_1_more.mp3',
	'a  b---c.m4a'                => 'a_b---c.m4a',
	'  spaced name  .TXT'         => 'spaced_name.txt',
	"caf\xc3\xa9 d\xc3\xa9j\xc3\xa0.vtt" => 'cafe_deja.vtt',
	'..hidden/..\\x y.srt'        => 'x_y.srt',
	'../../etc/passwd'            => 'passwd',
	'name.tar.gz'                 => 'name_tar.gz',
	'%20encoded%2Fslash.json'     => '20encoded_2Fslash.json',
	'###.mp3'                     => 'audio.mp3',
	'no_extension'                => 'no_extension',
) as $in => $want ) {
	$got = PodPub_Storage::file_name( $in, 'audio' );
	check( 'file_name: ' . json_encode( $in ) . ' -> ' . $want, $got === $want && (bool) preg_match( "/^[A-Za-z0-9][A-Za-z0-9._-]*\$/", $got ) );
}
check( 'file_name: a very long name is cut and still safe', strlen( PodPub_Storage::file_name( str_repeat( 'long name ', 40 ) . '.mp3' ) ) <= 105 );
exit( $fail );
