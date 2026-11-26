<?php
/**
 * WordPress posts for episodes and a page for the podcast.
 *
 * An episode can have an ordinary WordPress post (title, show notes, date, author, categories).
 * A podcast can have a page that lists its published episodes. Posts and the page are only ever
 * made or changed when the owner asks (the episode form's "Create/update WordPress post" answer,
 * or the podcast's WordPress posts tab). Nothing here writes the feed; the feed item's link
 * picks up the post address the next time the podcast-level "Save and write feed" is used.
 *
 * @package PodPublisher
 */

defined( 'ABSPATH' ) || exit;

class PodPub_Posts {

	const META_POST     = '_podpub_wp_post';  // On an episode: the id of its WordPress post.
	const META_SETTINGS = '_podpub_posts';    // On a podcast: settings for posts and the page.
	const META_BACK     = '_podpub_episode';  // On the post: the episode it came from.
	const META_THUMB    = '_podpub_thumb';    // On the post: picture the featured image was made from.

	/**
	 * Settings for a podcast.
	 *
	 * @param int $podcast_id Podcast record id.
	 * @return array enabled (bool), author (int, 0 = whoever saves), categories (int[]), page (bool), page_id (int).
	 */
	public static function settings( $podcast_id ) {
		$saved = get_post_meta( (int) $podcast_id, self::META_SETTINGS, true );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'enabled'    => isset( $saved['enabled'] ) ? (bool) $saved['enabled'] : true,
			'author'     => isset( $saved['author'] ) ? (int) $saved['author'] : 0,
			'categories' => isset( $saved['categories'] ) && is_array( $saved['categories'] ) ? array_values( array_map( 'intval', $saved['categories'] ) ) : array(),
			'page'       => isset( $saved['page'] ) ? (bool) $saved['page'] : true,
			'page_id'    => isset( $saved['page_id'] ) ? (int) $saved['page_id'] : 0,
		);
	}

	private static function store( $podcast_id, $s ) {
		update_post_meta( (int) $podcast_id, self::META_SETTINGS, $s );
	}

	/**
	 * Check and keep the podcast's posts settings. Creates the page when asked and none exists.
	 *
	 * @param int   $podcast_id Podcast record id.
	 * @param array $in         Form values: enabled, author, categories[], page.
	 * @return array errors, warnings.
	 */
	public static function save_settings( $podcast_id, $in ) {
		$out = array( 'errors' => array(), 'warnings' => array() );
		if ( ! PodPub_Feeds::get( $podcast_id ) ) {
			$out['errors'][] = 'This podcast was not found.';
			return $out;
		}
		$s            = self::settings( $podcast_id );
		$s['enabled'] = ! empty( $in['enabled'] );
		$s['page']    = ! empty( $in['page'] );

		$author = isset( $in['author'] ) ? (int) $in['author'] : 0;
		if ( $author > 0 ) {
			$ok = false;
			foreach ( self::authors() as $u ) {
				if ( (int) $u->ID === $author ) {
					$ok = true;
				}
			}
			if ( ! $ok ) {
				$out['errors'][] = 'That author is not a WordPress user who can publish posts.';
			}
		}
		$cats = array();
		if ( isset( $in['categories'] ) && is_array( $in['categories'] ) ) {
			$valid = array();
			foreach ( self::categories() as $c ) {
				$valid[ (int) $c->term_id ] = true;
			}
			foreach ( $in['categories'] as $c ) {
				if ( isset( $valid[ (int) $c ] ) ) {
					$cats[] = (int) $c;
				} else {
					$out['errors'][] = 'One of the chosen categories does not exist.';
					break;
				}
			}
		}
		if ( $s['page'] && ! self::page_exists( $s['page_id'] ) && ! current_user_can( 'publish_pages' ) ) {
			$out['errors'][] = 'You are not allowed to publish pages, so the podcast page cannot be created.';
		}
		if ( $out['errors'] ) {
			return $out;
		}
		$s['author']     = $author;
		$s['categories'] = array_values( array_unique( $cats ) );
		self::store( $podcast_id, $s );
		if ( $s['page'] ) {
			$out['warnings'] = array_merge( $out['warnings'], self::sync_page( $podcast_id, true ) );
		}
		return $out;
	}

	/** WordPress users who can publish posts. */
	public static function authors() {
		$u = get_users( array( 'capability' => 'publish_posts', 'orderby' => 'display_name', 'fields' => array( 'ID', 'display_name' ) ) );
		return is_array( $u ) ? $u : array();
	}

	/** WordPress categories. */
	public static function categories() {
		$c = get_categories( array( 'hide_empty' => false ) );
		return is_array( $c ) ? $c : array();
	}

	/**
	 * The WordPress post of an episode, or 0 when there is none (never made, or deleted in WordPress).
	 *
	 * @param int $episode_id Episode record id.
	 * @return int
	 */
	public static function post_for( $episode_id ) {
		$id = (int) get_post_meta( (int) $episode_id, self::META_POST, true );
		if ( $id <= 0 ) {
			return 0;
		}
		$p = get_post( $id );
		return ( $p && 'post' === $p->post_type && 'trash' !== $p->post_status ) ? $id : 0;
	}

	/** Address of the episode's post once it is published, else ''. */
	public static function post_url( $episode_id ) {
		$id = self::post_for( $episode_id );
		if ( ! $id ) {
			return '';
		}
		$p = get_post( $id );
		if ( 'publish' !== $p->post_status ) {
			return '';
		}
		$u = get_permalink( $id );
		return is_string( $u ) ? $u : '';
	}

	private static function page_exists( $id ) {
		if ( (int) $id <= 0 ) {
			return false;
		}
		$p = get_post( (int) $id );
		return $p && 'page' === $p->post_type && 'trash' !== $p->post_status;
	}

	/**
	 * Create or update the WordPress post of a published episode. Call it only after the
	 * episode has been saved successfully and the owner has answered yes.
	 *
	 * @param int $podcast_id Podcast record id.
	 * @param int $episode_id Episode record id.
	 * @return array post (id, 0 when none made), created (bool), warnings.
	 */
	public static function sync_episode( $podcast_id, $episode_id ) {
		$out = array( 'post' => 0, 'created' => false, 'warnings' => array() );
		$s   = self::settings( $podcast_id );
		$ep  = PodPub_Episodes::get( $episode_id, $podcast_id );
		if ( ! $s['enabled'] || ! $ep ) {
			return $out;
		}
		$d = PodPub_Episodes::details( $episode_id );
		if ( 'published' !== $d['status'] ) {
			return $out;
		}
		if ( ! current_user_can( 'publish_posts' ) ) {
			$out['warnings'][] = 'The episode was saved, but you are not allowed to publish WordPress posts, so no post was made or changed.';
			return $out;
		}
		$author = $s['author'];
		if ( $author > 0 ) {
			$found = false;
			foreach ( self::authors() as $u ) {
				if ( (int) $u->ID === $author ) {
					$found = true;
				}
			}
			if ( ! $found ) {
				$out['warnings'][] = 'The author chosen for the posts can no longer publish posts, so the post is attributed to you.';
				$author            = 0;
			}
		}
		$content = '' !== trim( $d['show_notes'] ) ? $d['show_notes'] : '<p>' . esc_html( $d['description'] ) . '</p>';
		$args    = array(
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_title'    => $ep->post_title,
			'post_content'  => $content,
			'post_date'     => wp_date( 'Y-m-d H:i:s', (int) $d['published'] ),
			'post_date_gmt' => gmdate( 'Y-m-d H:i:s', (int) $d['published'] ),
		);
		if ( $author > 0 ) {
			$args['post_author'] = $author;
		}
		if ( $s['categories'] ) {
			$args['post_category'] = $s['categories'];
		}
		$existing = self::post_for( $episode_id );
		if ( $existing ) {
			$args['ID'] = $existing;
			$id         = wp_update_post( $args, true );
		} else {
			$id = wp_insert_post( $args, true );
		}
		if ( is_wp_error( $id ) || ! $id ) {
			$out['warnings'][] = 'The episode was saved, but the WordPress post could not be ' . ( $existing ? 'updated' : 'created' ) . '.';
			return $out;
		}
		$id = (int) $id;
		update_post_meta( (int) $episode_id, self::META_POST, $id );
		update_post_meta( $id, self::META_BACK, (int) $episode_id );
		$out['post']    = $id;
		$out['created'] = ! $existing;
		$out['warnings'] = array_merge( $out['warnings'], self::set_picture( $podcast_id, $episode_id, $id, $d ) );
		return $out;
	}

	/**
	 * Use the episode picture as the post's featured image (once per picture).
	 *
	 * @return string[] warnings.
	 */
	private static function set_picture( $podcast_id, $episode_id, $post_id, $d ) {
		if ( '' === $d['image'] || ! function_exists( 'media_handle_sideload' ) ) {
			return array();
		}
		$ep_dir = PodPub_Episodes::dir_name( $episode_id );
		$pdir   = PodPub_Feeds::dir_name( $podcast_id );
		$path   = PodPub_Storage::base_path() . '/' . $pdir . '/' . $ep_dir . '/' . $d['image'];
		if ( '' === $ep_dir || ! is_file( $path ) ) {
			return array();
		}
		$stamp = $d['image'] . ':' . filemtime( $path );
		if ( get_post_meta( $post_id, self::META_THUMB, true ) === $stamp ) {
			return array();
		}
		$tmp = wp_tempnam( $d['image'] );
		if ( ! $tmp || ! @copy( $path, $tmp ) ) { // phpcs:ignore
			return array( 'The post was made, but its featured image could not be copied.' );
		}
		$att = media_handle_sideload( array( 'name' => $d['image'], 'tmp_name' => $tmp ), $post_id );
		if ( is_wp_error( $att ) ) {
			@unlink( $tmp ); // phpcs:ignore
			return array( 'The post was made, but its featured image could not be added: ' . $att->get_error_message() );
		}
		set_post_thumbnail( $post_id, $att );
		update_post_meta( $post_id, self::META_THUMB, $stamp );
		return array();
	}

	/**
	 * Move an episode's post to the trash (never a permanent delete) and forget it.
	 *
	 * @param int $episode_id Episode record id.
	 * @return bool True when a post was trashed.
	 */
	public static function trash_for_episode( $episode_id ) {
		$id = self::post_for( $episode_id );
		if ( ! $id ) {
			return false;
		}
		wp_trash_post( $id );
		delete_post_meta( (int) $episode_id, self::META_POST );
		return true;
	}

	/**
	 * Make or refresh the podcast page. Without $create, only a page that already exists
	 * (and is switched on) is refreshed, so ordinary episode saves never create one.
	 *
	 * @param int  $podcast_id Podcast record id.
	 * @param bool $create     Make the page if there is none.
	 * @return string[] warnings.
	 */
	public static function sync_page( $podcast_id, $create = false ) {
		$s = self::settings( $podcast_id );
		if ( ! $s['page'] ) {
			return array();
		}
		$exists = self::page_exists( $s['page_id'] );
		if ( ! $exists && ! $create ) {
			return array();
		}
		$feed = PodPub_Feeds::get( $podcast_id );
		if ( ! $feed ) {
			return array();
		}
		$args = array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $feed->post_title,
			'post_content' => self::page_content( $podcast_id ),
		);
		if ( $exists ) {
			$args['ID'] = $s['page_id'];
			$id         = wp_update_post( $args, true );
		} else {
			if ( ! current_user_can( 'publish_pages' ) ) {
				return array( 'You are not allowed to publish pages, so the podcast page was not created.' );
			}
			$id = wp_insert_post( $args, true );
		}
		if ( is_wp_error( $id ) || ! $id ) {
			return array( 'The podcast page could not be ' . ( $exists ? 'updated' : 'created' ) . '.' );
		}
		if ( (int) $id !== $s['page_id'] ) {
			$s['page_id'] = (int) $id;
			self::store( $podcast_id, $s );
		}
		return array();
	}

	/** Is a podcast page wanted: posts on and the page switched on. */
	public static function page_wanted( $podcast_id ) {
		$s = self::settings( $podcast_id );
		return $s['enabled'] && $s['page'];
	}

	/** Does the podcast page exist already (so the question is update, not create)? */
	public static function page_present( $podcast_id ) {
		return self::page_exists( self::settings( $podcast_id )['page_id'] );
	}

	/** Address of the podcast page, or ''. */
	public static function page_url( $podcast_id ) {
		$s = self::settings( $podcast_id );
		if ( ! self::page_exists( $s['page_id'] ) ) {
			return '';
		}
		$u = get_permalink( $s['page_id'] );
		return is_string( $u ) ? $u : '';
	}

	/**
	 * The page's content: show art and description, then the published episodes, newest first.
	 * Everything is escaped.
	 */
	public static function page_content( $podcast_id ) {
		$d    = PodPub_Details::get( $podcast_id );
		$base = PodPub_Storage::base_url();
		$dir  = PodPub_Feeds::dir_name( $podcast_id );
		$h    = '';
		if ( '' !== $base && '' !== $d['image'] && '' !== $dir ) {
			$h .= '<p><img src="' . esc_url( $base . '/' . $dir . '/' . $d['image'] ) . '" alt="" width="300" height="300"></p>' . "\n";
		}
		if ( '' !== trim( $d['description'] ) ) {
			$h .= '<p>' . esc_html( $d['description'] ) . '</p>' . "\n";
		}
		$rows = array();
		foreach ( PodPub_Episodes::all( $podcast_id ) as $ep ) {
			$e = PodPub_Episodes::details( $ep->ID );
			if ( 'published' !== $e['status'] || $e['published'] > time() ) {
				continue;
			}
			$url = self::post_url( $ep->ID );
			if ( '' === $url ) { // Only episodes that have a WordPress post are listed.
				continue;
			}
			$rows[] = array( 'when' => (int) $e['published'], 'title' => $ep->post_title, 'desc' => $e['description'], 'url' => $url );
		}
		usort( $rows, function ( $a, $b ) { return $b['when'] <=> $a['when']; } );
		if ( $rows ) {
			$h .= '<ul class="podpub-episodes">' . "\n";
			foreach ( $rows as $r ) {
				$t  = esc_html( $r['title'] );
				$t  = '' !== $r['url'] ? '<a href="' . esc_url( $r['url'] ) . '">' . $t . '</a>' : $t;
				$dt = function_exists( 'wp_date' ) ? wp_date( 'j F Y', $r['when'] ) : gmdate( 'j F Y', $r['when'] );
				$h .= '<li><strong>' . $t . '</strong> <em>' . esc_html( $dt ) . '</em><br>' . esc_html( $r['desc'] ) . '</li>' . "\n";
			}
			$h .= '</ul>';
		}
		return $h;
	}

	/**
	 * Trash every episode post and the page of a podcast (used when the podcast is deleted and the owner asks).
	 *
	 * @param int $podcast_id Podcast record id.
	 * @return int Number of posts and pages moved to the trash.
	 */
	public static function trash_all( $podcast_id ) {
		$n = 0;
		foreach ( PodPub_Episodes::all( $podcast_id ) as $ep ) {
			if ( self::trash_for_episode( $ep->ID ) ) {
				++$n;
			}
		}
		$s = self::settings( $podcast_id );
		if ( self::page_exists( $s['page_id'] ) ) {
			wp_trash_post( $s['page_id'] );
			++$n;
		}
		return $n;
	}
}
