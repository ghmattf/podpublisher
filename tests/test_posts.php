<?php
// WordPress posts for episodes and the podcast page: when posts are made, what they hold, feed link, page, deletes.
// Not a real WordPress: the few WordPress functions used are stood in for.
define( 'ABSPATH', __DIR__ );
define( 'PODPUB_VERSION', 'test' );
$tmp = sys_get_temp_dir() . '/podpub_posts_' . getmypid();
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
function wp_update_post( $a ) { foreach ( $a as $k => $v ) { $GLOBALS['posts'][ $a['ID'] ]->$k = $v; } return $a['ID']; }
function wp_trash_post( $id ) { $GLOBALS['posts'][ $id ]->post_status = 'trash'; $GLOBALS['trashed'][] = $id; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['meta'][ $id ][ $k ] ); }
function get_permalink( $id ) { return 'https://example.com/?p=' . $id; }
function wp_date( $f, $t ) { return gmdate( $f, $t ); }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_url( $s ) { return $s; }
function get_users( $a ) { return array( (object) array( 'ID' => 3, 'display_name' => 'Ann' ), (object) array( 'ID' => 4, 'display_name' => 'Bob' ) ); }
function get_categories( $a ) { return array( (object) array( 'term_id' => 7, 'name' => 'News' ), (object) array( 'term_id' => 8, 'name' => 'Extra' ) ); }
function current_user_can( $c ) { return ! in_array( $c, $GLOBALS['deny'], true ); }
$GLOBALS['deny'] = array(); $GLOBALS['trashed'] = array();
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


foreach ( array( 'settings', 'storage', 'p20', 'images', 'details', 'posts', 'chunks', 'episodes', 'feedxml', 'feeds' ) as $c ) { require __DIR__ . "/../plugin/includes/class-podpub-$c.php"; }
$fail = 0;
function check( $name, $ok ) { global $fail; echo ( $ok ? 'ok   ' : 'FAIL ' ) . $name . "\n"; if ( ! $ok ) { $fail = 1; } }
function put( $path, $bytes ) { file_put_contents( $path, $bytes ); return $path; }
function up( $path, $name ) { return array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => $path, 'name' => $name ); }
function wp_posts( $type ) { return array_values( array_filter( $GLOBALS['posts'], function ( $p ) use ( $type ) { return $p->post_type === $type && 'trash' !== $p->post_status; } ) ); }

mkdir( "$tmp/in", 0755, true );
$MP3 = "ID3\x04\x00\x00\x00\x00\x00\x00" . str_repeat( "\0", 100 );
$pdir = PodPub_Storage::create_podcast_dir( 'My Show' );
update_post_meta( 5, '_podpub_dir', $pdir );
PodPub_Feeds::save_details( 5, array( 'title' => 'My Show', 'description' => 'About the show', 'language' => 'en', 'explicit' => 'no' ) );
$pf  = "$tmp/uploads/podpublisher/$pdir";
$ep  = array( 'title' => 'Episode One', 'description' => 'First one', 'show_notes' => '<p>Notes <a href="https://x.example">here</a></p>', 'explicit' => 'no', 'published' => '2026-01-15T09:30', 'status' => 'published', 'duration' => '1:00' );
$aud = function () use ( $tmp, $MP3 ) { return up( put( "$tmp/in/a.mp3", $MP3 ), 'a.mp3' ); };

// Defaults.
$s = PodPub_Posts::settings( 5 );
check( 'settings: posts on by default, no page, no categories', $s['enabled'] && ! $s['page'] && $s['categories'] === array() && 0 === $s['author'] );

// No answer, or a draft: no post.
$r = PodPub_Episodes::save( 5, 0, $ep, $aud() );
check( 'published save with no answer makes no post', $r['errors'] === array() && ! wp_posts( 'post' ) );
$e1 = $r['id'];
$r = PodPub_Episodes::save( 5, 0, array_merge( $ep, array( 'title' => 'Draft', 'status' => 'draft', 'wp_post' => 'yes' ) ) );
check( 'draft with yes still makes no post', $r['errors'] === array() && ! wp_posts( 'post' ) );

// Errors: no post.
$r = PodPub_Episodes::save( 5, $e1, array_merge( $ep, array( 'title' => '', 'wp_post' => 'yes' ) ) );
check( 'an error creates no post', count( $r['errors'] ) > 0 && ! wp_posts( 'post' ) );

// Yes: post made.
PodPub_Posts::save_settings( 5, array( 'enabled' => 1, 'author' => 4, 'categories' => array( 7 ) ) );
$r = PodPub_Episodes::save( 5, $e1, array_merge( $ep, array( 'wp_post' => 'yes' ) ) );
$made = wp_posts( 'post' );
check( 'yes: one post made after a good save', $r['errors'] === array() && count( $made ) === 1 && $r['post'] === (int) $made[0]->ID );
$p = $made[0];
check( 'post: title, notes, publish status', $p->post_title === 'Episode One' && false !== strpos( $p->post_content, 'Notes <a href="https://x.example">here</a>' ) && $p->post_status === 'publish' );
check( 'post: author and categories from the podcast settings', $p->post_author === 4 && $p->post_category === array( 7 ) );
check( 'post: dated with the episode (UTC)', $p->post_date_gmt === '2026-01-14 22:30:00' );
check( 'post id stored with the episode', PodPub_Posts::post_for( $e1 ) === (int) $p->ID );

// Feed link.
$items = PodPub_Episodes::feed_items( 5, PodPub_Storage::base_url(), time() );
check( 'feed item link is the post address', $items[0]['post_url'] === 'https://example.com/?p=' . $p->ID );
PodPub_FeedXml::write( 5 );
$d = new DOMDocument(); $d->load( "$pf/$pdir.xml" ); $x = new DOMXPath( $d );
check( 'feed xml: item link written, show notes still in the feed', $x->evaluate( 'string(//item/link)' ) === 'https://example.com/?p=' . $p->ID && $x->evaluate( 'count(//item/*[local-name()="encoded"])' ) === 1.0 );

// Second save: no leaves post alone, yes updates the same post.
wp_update_post( array( 'ID' => $p->ID, 'post_title' => 'Edited in WordPress', 'post_content' => 'my edits' ) );
$r = PodPub_Episodes::save( 5, $e1, array_merge( $ep, array( 'title' => 'Episode One v2', 'wp_post' => 'no' ) ) );
check( 'no leaves the post alone', $GLOBALS['posts'][ $p->ID ]->post_title === 'Edited in WordPress' && count( wp_posts( 'post' ) ) === 1 );
$r = PodPub_Episodes::save( 5, $e1, array_merge( $ep, array( 'title' => 'Episode One v2', 'wp_post' => 'yes' ) ) );
check( 'yes updates the same post and overwrites WordPress edits', count( wp_posts( 'post' ) ) === 1 && $GLOBALS['posts'][ $p->ID ]->post_title === 'Episode One v2' && false !== strpos( $GLOBALS['posts'][ $p->ID ]->post_content, 'Notes' ) );

// Post deleted in WordPress: yes makes a new one.
$GLOBALS['posts'][ $p->ID ]->post_status = 'trash';
check( 'trashed post counts as none', 0 === PodPub_Posts::post_for( $e1 ) && '' === PodPub_Posts::post_url( $e1 ) );
PodPub_Episodes::save( 5, $e1, array_merge( $ep, array( 'wp_post' => 'yes' ) ) );
check( 'yes after the post was trashed makes a new post', count( wp_posts( 'post' ) ) === 1 && PodPub_Posts::post_for( $e1 ) !== (int) $p->ID );

// Posts off.
PodPub_Posts::save_settings( 5, array( 'author' => 4 ) );
$before = count( wp_posts( 'post' ) );
$r = PodPub_Episodes::save( 5, 0, array_merge( $ep, array( 'title' => 'Two', 'wp_post' => 'yes', 'explicit' => 'no', 'published' => '2026-02-15T09:30' ) ), $aud() );
check( 'posts turned off: no post even when asked', count( wp_posts( 'post' ) ) === $before && 0 === PodPub_Posts::post_for( $r['id'] ) );
$e2 = $r['id'];

// Settings checks.
$r = PodPub_Posts::save_settings( 5, array( 'enabled' => 1, 'author' => 99 ) );
check( 'settings: unknown author refused', count( $r['errors'] ) === 1 );
$r = PodPub_Posts::save_settings( 5, array( 'enabled' => 1, 'categories' => array( 123 ) ) );
check( 'settings: unknown category refused', count( $r['errors'] ) === 1 );
check( 'settings: refusal saved nothing', ! PodPub_Posts::settings( 5 )['enabled'] );

// No right to publish posts.
PodPub_Posts::save_settings( 5, array( 'enabled' => 1, 'author' => 3 ) );
$GLOBALS['deny'] = array( 'publish_posts' );
$r = PodPub_Episodes::save( 5, $e2, array_merge( $ep, array( 'title' => 'Two', 'explicit' => 'no', 'published' => '2026-02-15T09:30', 'wp_post' => 'yes' ) ) );
check( 'without publish_posts: episode saved, warning, no post', $r['errors'] === array() && count( $r['warnings'] ) === 1 && 0 === PodPub_Posts::post_for( $e2 ) );
$GLOBALS['deny'] = array();

// The page.
check( 'page: none until asked', ! wp_posts( 'page' ) );
$r = PodPub_Posts::save_settings( 5, array( 'enabled' => 1, 'author' => 3, 'page' => 1 ) );
$pages = wp_posts( 'page' );
check( 'page: made when asked', $r['errors'] === array() && count( $pages ) === 1 && $pages[0]->post_title === 'My Show' && PodPub_Posts::settings( 5 )['page_id'] === (int) $pages[0]->ID );
$c = $pages[0]->post_content;
check( 'page: description and both episodes, newest first, escaped', false !== strpos( $c, 'About the show' ) && strpos( $c, 'Two' ) < strpos( $c, 'Episode One' ) && false !== strpos( $c, 'First one' ) );
check( 'page: episode with a post is linked, one without is not', false !== strpos( $c, '<a href="https://example.com/?p=' . PodPub_Posts::post_for( $e1 ) . '">Episode One</a>' ) && false === strpos( $c, '>Two</a>' ) );
PodPub_Posts::save_settings( 5, array( 'enabled' => 1, 'author' => 3, 'page' => 1 ) );
check( 'page: saving again updates, never duplicates', count( wp_posts( 'page' ) ) === 1 );
PodPub_Episodes::save( 5, $e2, array_merge( $ep, array( 'title' => 'Two <b>&</b>', 'explicit' => 'no', 'published' => '2026-02-15T09:30' ) ) );
check( 'page: kept up to date by an episode save, titles escaped', false !== strpos( $GLOBALS['posts'][ $pages[0]->ID ]->post_content, 'Two &lt;b&gt;&amp;&lt;/b&gt;' ) || false !== strpos( $GLOBALS['posts'][ $pages[0]->ID ]->post_content, 'Two &amp;' ) );
$GLOBALS['deny'] = array( 'publish_pages' );
PodPub_Posts::save_settings( 6, array( 'page' => 1 ) );
check( 'page: without publish_pages, refused', count( wp_posts( 'page' ) ) === 1 );
$GLOBALS['deny'] = array();

// Draft episodes are not on the page.
$r = PodPub_Episodes::save( 5, 0, array_merge( $ep, array( 'title' => 'Secret draft', 'status' => 'draft' ) ) );
check( 'page: drafts not listed', false === strpos( $GLOBALS['posts'][ $pages[0]->ID ]->post_content, 'Secret draft' ) );

// Deleting an episode.
$pid1 = PodPub_Posts::post_for( $e1 );
PodPub_Episodes::delete( 5, $e2, false );
check( 'delete episode without the post question ticked leaves posts alone', $GLOBALS['trashed'] === array() );
check( 'delete: page no longer lists the deleted episode', false === strpos( $GLOBALS['posts'][ $pages[0]->ID ]->post_content, 'Two' ) );
PodPub_Episodes::delete( 5, $e1, true );
check( 'delete episode with the box ticked trashes its post (not a permanent delete)', in_array( $pid1, $GLOBALS['trashed'], true ) && isset( $GLOBALS['posts'][ $pid1 ] ) );

// Deleting the podcast.
$e3 = PodPub_Episodes::save( 5, 0, array_merge( $ep, array( 'title' => 'Three', 'wp_post' => 'yes' ) ), $aud() )['id'];
$pid3 = PodPub_Posts::post_for( $e3 );
$GLOBALS['trashed'] = array();
$pageid = PodPub_Posts::settings( 5 )['page_id'];
$r = PodPub_Feeds::delete( 5, false, true );
check( 'delete podcast with the box ticked trashes its posts and page', in_array( $pid3, $GLOBALS['trashed'], true ) && in_array( $pageid, $GLOBALS['trashed'], true ) && 2 === $r['posts_trashed'] );
echo $fail ? "\nSOME FAILED\n" : "\nAll passed\n";
exit( $fail );
