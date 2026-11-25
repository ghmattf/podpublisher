<?php
/**
 * WordPress admin: the top-level PodPublisher menu, the podcast list, one
 * sub-item per managed podcast, and a Settings sub-item.
 *
 * @package PodPublisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PodPub_Admin {

	const CAP          = 'manage_options';
	const SLUG         = 'podpublisher';
	const SLUG_FEED    = 'podpublisher-feed-';
	const SLUG_SETTING = 'podpublisher-settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_podpub_create_feed', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_podpub_save_details', array( __CLASS__, 'handle_save_details' ) );
		add_action( 'admin_post_podpub_save_episode', array( __CLASS__, 'handle_save_episode' ) );
		add_action( 'admin_post_podpub_save_p20', array( __CLASS__, 'handle_save_p20' ) );
		add_action( 'admin_post_podpub_save_live', array( __CLASS__, 'handle_save_live' ) );
		add_action( 'admin_post_podpub_save_posts', array( __CLASS__, 'handle_save_posts' ) );
		add_action( 'admin_post_podpub_delete_episode', array( __CLASS__, 'handle_delete_episode' ) );
		add_action( 'admin_post_podpub_delete_podcast', array( __CLASS__, 'handle_delete_podcast' ) );
		add_action( 'admin_post_podpub_write_feed_anyway', array( __CLASS__, 'handle_write_feed_anyway' ) );
		add_action( 'admin_post_podpub_adopt_confirm', array( __CLASS__, 'handle_adopt_confirm' ) );
		add_action( 'wp_ajax_podpub_geo_search', array( __CLASS__, 'handle_geo_search' ) );
	}

	/**
	 * Menu: PodPublisher (list), then one item per podcast, then Settings.
	 */
	public static function register_menu() {
		add_menu_page( 'PodPublisher', 'PodPublisher', self::CAP, self::SLUG, array( __CLASS__, 'render_list' ), 'dashicons-microphone', 26 );
		// Rename the automatic first sub-item.
		add_submenu_page( self::SLUG, 'All podcasts', 'All podcasts', self::CAP, self::SLUG, array( __CLASS__, 'render_list' ) );

		foreach ( PodPub_Feeds::all() as $feed ) {
			$id = (int) $feed->ID;
			add_submenu_page(
				self::SLUG,
				$feed->post_title,
				$feed->post_title,
				self::CAP,
				self::SLUG_FEED . $id,
				function () use ( $id ) {
					PodPub_Admin::render_feed( $id );
				}
			);
		}

		add_submenu_page( self::SLUG, 'PodPublisher settings', 'Settings', self::CAP, self::SLUG_SETTING, array( __CLASS__, 'render_settings' ) );
	}

	public static function register_settings() {
		register_setting(
			'podpub_settings_group',
			PodPub_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => PodPub_Settings::defaults(),
			)
		);
	}

	/**
	 * @param mixed $input Submitted settings.
	 * @return array
	 */
	public static function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();
		$old   = array(
			'storage_path' => (string) PodPub_Settings::get( 'storage_path' ),
			'storage_url'  => (string) PodPub_Settings::get( 'storage_url' ),
			'geo_url'      => (string) PodPub_Settings::get( 'geo_url' ),
		);
		$path = isset( $input['storage_path'] ) ? trim( sanitize_text_field( $input['storage_path'] ) ) : '';
		$path = '' === $path ? '' : rtrim( str_replace( '\\', '/', $path ), '/' );
		$url  = isset( $input['storage_url'] ) ? trim( esc_url_raw( trim( $input['storage_url'] ) ) ) : '';
		$url  = rtrim( $url, '/' );

		if ( '' !== $path && ! PodPub_Storage::valid_path( $path ) ) {
			add_settings_error( 'podpub_settings', 'podpub_path', 'The podcast folder was not saved. It must be a full path with no spaces or special characters: only letters, numbers, dots, underscores and hyphens in each folder name.', 'error' );
			$path = $old['storage_path'];
			$url  = $old['storage_url'];
		}
		if ( '' !== $url && ! PodPub_Storage::valid_url( $url ) ) {
			add_settings_error( 'podpub_settings', 'podpub_url', 'The public address was not saved. It must start with http:// or https:// and use only letters, numbers, dots, underscores, hyphens and slashes after the domain.', 'error' );
			$url = $old['storage_url'];
		}
		if ( '' !== $path && '' === $url && '' === PodPub_Storage::derive_url( $path ) ) {
			add_settings_error( 'podpub_settings', 'podpub_url_needed', 'That folder is outside the WordPress uploads folder, so also enter the public address that points to it. The folder and address were not saved.', 'error' );
			$path = $old['storage_path'];
			$url  = $old['storage_url'];
		}
		if ( '' !== $path && ! PodPub_Storage::ensure_dir( $path ) ) {
			add_settings_error( 'podpub_settings', 'podpub_writable', 'Saved, but the web server cannot write to that folder yet. Create it or change its permissions before adding podcasts.', 'warning' );
		}

		$geo_url = isset( $input['geo_url'] ) ? rtrim( trim( sanitize_text_field( $input['geo_url'] ) ), '/' ) : '';
		if ( '' !== $geo_url && ! PodPub_Geo::valid_url( $geo_url ) ) {
			add_settings_error( 'podpub_settings', 'podpub_geo_url', 'The location search address was not saved. It must start with https:// and have no spaces, query or other special characters. (Addresses on this server or a private network are refused by WordPress for safety.)', 'error' );
			$geo_url = $old['geo_url'];
		}

		return array(
			'delete_data_on_uninstall' => empty( $input['delete_data_on_uninstall'] ) ? 0 : 1,
			'storage_path'             => $path,
			'storage_url'              => $url,
			'geo_enabled'              => empty( $input['geo_enabled'] ) ? 0 : 1,
			'geo_url'                  => $geo_url,
		);
	}

	private static function list_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Create-podcast form handler.
	 */
	public static function handle_create() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'podpublisher' ), 403 );
		}
		check_admin_referer( 'podpub_create_feed' );
		$title = isset( $_POST['podpub_title'] ) ? wp_unslash( $_POST['podpub_title'] ) : '';
		$id    = PodPub_Feeds::create( $title );
		if ( is_wp_error( $id ) ) {
			wp_safe_redirect( self::list_url( array( 'action' => 'new', 'podpub_error' => rawurlencode( $id->get_error_message() ) ) ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'page', self::SLUG_FEED . (int) $id, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Podcast details form handler.
	 */
	public static function handle_save_details() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'podpublisher' ), 403 );
		}
		check_admin_referer( 'podpub_save_details' );
		$id    = isset( $_POST['podpub_id'] ) ? (int) $_POST['podpub_id'] : 0;
		$input = isset( $_POST['podpub'] ) && is_array( $_POST['podpub'] ) ? wp_unslash( $_POST['podpub'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$file  = null;
		if ( isset( $_FILES['podpub_image'] ) && is_array( $_FILES['podpub_image'] ) ) {
			$file = $_FILES['podpub_image']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( UPLOAD_ERR_OK === (int) $file['error'] && ! is_uploaded_file( $file['tmp_name'] ) ) {
				$file = null;
			}
		}
		$result = PodPub_Feeds::save_details( $id, $input, $file );
		set_transient( 'podpub_notice_' . get_current_user_id(), $result, 120 );
		wp_safe_redirect( self::feed_page_url( $id, array( 'tab' => 'details' ) ) );
		exit;
	}

	private static function feed_page_url( $podcast_id, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG_FEED . (int) $podcast_id ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Episode form handler.
	 */
	public static function handle_save_episode() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'podpublisher' ), 403 );
		}
		check_admin_referer( 'podpub_save_episode' );
		$pid   = isset( $_POST['podpub_id'] ) ? (int) $_POST['podpub_id'] : 0;
		$eid   = isset( $_POST['podpub_episode_id'] ) ? (int) $_POST['podpub_episode_id'] : 0;
		$input = isset( $_POST['podpub_ep'] ) && is_array( $_POST['podpub_ep'] ) ? wp_unslash( $_POST['podpub_ep'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$file  = null;
		if ( isset( $_FILES['podpub_audio'] ) && is_array( $_FILES['podpub_audio'] ) ) {
			$file = $_FILES['podpub_audio']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( UPLOAD_ERR_OK === (int) $file['error'] && ! is_uploaded_file( $file['tmp_name'] ) ) {
				$file = null;
			}
		}
		$upload = function ( $field ) {
			if ( isset( $_FILES[ $field ] ) && is_array( $_FILES[ $field ] ) ) {
				$f = $_FILES[ $field ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( UPLOAD_ERR_OK !== (int) $f['error'] || is_uploaded_file( $f['tmp_name'] ) ) {
					return $f;
				}
			}
			return null;
		};
		$result          = PodPub_Episodes::save( $pid, $eid, $input, $file, $upload( 'podpub_ep_image' ), $upload( 'podpub_transcript' ), $upload( 'podpub_chapters_file' ), $upload( 'podpub_splits_file' ) );
		$result['input'] = $result['errors'] ? $input : null;
		set_transient( 'podpub_notice_' . get_current_user_id(), $result, 120 );
		$go = $result['errors'] ? ( $eid ? (string) $eid : 'new' ) : (string) $result['id'];
		$tab = isset( $_POST['podpub_tab'] ) ? sanitize_key( wp_unslash( $_POST['podpub_tab'] ) ) : '';
		wp_safe_redirect( self::feed_page_url( $pid, array_merge( array( 'episode' => $go ), '' !== $tab ? array( 'tab' => $tab ) : array() ) ) );
		exit;
	}

	/**
	 * Live episodes handler (Episodes tab): saves the live items and writes the feed.
	 */
	public static function handle_save_live() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'podpublisher' ), 403 );
		}
		check_admin_referer( 'podpub_save_live' );
		$id     = isset( $_POST['podpub_id'] ) ? (int) $_POST['podpub_id'] : 0;
		$input  = isset( $_POST['podpub_p20'] ) && is_array( $_POST['podpub_p20'] ) ? wp_unslash( $_POST['podpub_p20'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$result = PodPub_Feeds::save_live( $id, $input );
		$result['form'] = 'live';
		if ( $result['errors'] ) {
			$result['input'] = $input;
		}
		set_transient( 'podpub_notice_' . get_current_user_id(), $result, 120 );
		wp_safe_redirect( self::feed_page_url( $id, array( 'tab' => 'episodes' ) ) );
		exit;
	}

	/**
	 * WordPress posts tab handler (author, categories, page). Never writes the feed.
	 */
	public static function handle_save_posts() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'podpublisher' ), 403 );
		}
		check_admin_referer( 'podpub_save_posts' );
		$id     = isset( $_POST['podpub_id'] ) ? (int) $_POST['podpub_id'] : 0;
		$input  = isset( $_POST['podpub_posts'] ) && is_array( $_POST['podpub_posts'] ) ? wp_unslash( $_POST['podpub_posts'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$result = PodPub_Posts::save_settings( $id, $input );
		$result['form']  = 'posts';
		$result['posts_saved'] = ! $result['errors'];
		set_transient( 'podpub_notice_' . get_current_user_id(), $result, 120 );
		wp_safe_redirect( self::feed_page_url( $id, array( 'tab' => 'posts' ) ) );
		exit;
	}

	/**
	 * Episode delete handler.
	 */
	public static function handle_delete_episode() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'podpublisher' ), 403 );
		}
		check_admin_referer( 'podpub_delete_episode' );
		$pid    = isset( $_POST['podpub_id'] ) ? (int) $_POST['podpub_id'] : 0;
		$eid    = isset( $_POST['podpub_episode_id'] ) ? (int) $_POST['podpub_episode_id'] : 0;
		$result = PodPub_Episodes::delete( $pid, $eid, ! empty( $_POST['podpub_delete_post'] ) );
		set_transient( 'podpub_notice_' . get_current_user_id(), $result, 120 );
		wp_safe_redirect( self::feed_page_url( $pid, array( 'tab' => 'episodes' ) ) );
		exit;
	}

	/**
	 * Location search (admin-ajax): administrators only, nonce checked, one search per request.
	 */
	public static function handle_geo_search() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'You do not have permission to do that.' ), 403 );
		}
		check_ajax_referer( 'podpub_geo', 'nonce' );
		$q = isset( $_POST['q'] ) && is_string( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		$r = PodPub_Geo::search( $q );
		if ( $r['ok'] ) {
			wp_send_json_success( array( 'results' => $r['results'], 'message' => $r['message'] ) );
		}
		wp_send_json_error( array( 'message' => $r['message'] ) );
	}

	/**
	 * Podcast delete handler. The folder is only deleted when the box was ticked.
	 */
	public static function handle_delete_podcast() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'podpublisher' ), 403 );
		}
		check_admin_referer( 'podpub_delete_podcast' );
		$pid    = isset( $_POST['podpub_id'] ) ? (int) $_POST['podpub_id'] : 0;
		$files  = ! empty( $_POST['podpub_delete_files'] );
		$result = PodPub_Feeds::delete( $pid, $files, ! empty( $_POST['podpub_delete_posts'] ) );
		$result['deleted_podcast'] = ! $result['errors'];
		set_transient( 'podpub_notice_' . get_current_user_id(), $result, 120 );
		wp_safe_redirect( $result['errors'] ? self::feed_page_url( $pid ) : self::list_url() );
		exit;
	}

	/**
	 * Podcasting 2.0 tags form handler (podcast level).
	 */
	public static function handle_save_p20() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'podpublisher' ), 403 );
		}
		check_admin_referer( 'podpub_save_p20' );
		$id    = isset( $_POST['podpub_id'] ) ? (int) $_POST['podpub_id'] : 0;
		$input = isset( $_POST['podpub_p20'] ) && is_array( $_POST['podpub_p20'] ) ? wp_unslash( $_POST['podpub_p20'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$result          = PodPub_Feeds::save_p20( $id, $input );
		$result['form']  = 'p20';
		$result['input'] = $result['errors'] ? $input : null;
		set_transient( 'podpub_notice_' . get_current_user_id(), $result, 120 );
		wp_safe_redirect( self::feed_page_url( $id, array( 'tab' => 'p20' ) ) );
		exit;
	}

	/**
	 * A table of rows for a list of tags (funding links, people, recipients, ...).
	 * Shows the saved rows plus three empty ones.
	 *
	 * @param string $name Field name prefix, such as podpub_p20[funding].
	 * @param array  $rows Rows to show.
	 * @param array  $cols Columns: key, label, type (text, select, check), options, ph, class.
	 * @param int    $max  Most rows allowed.
	 */
	/**
	 * Styles and the small script for tabs, copy buttons and cards. Each admin screen calls this
	 * once, at the top of its page.
	 */
	private static function assets() {
		echo '<style id="podpub-css">' . self::css() . '</style>';
		echo '<script id="podpub-js">' . self::js() . '</script>';
	}

	private static function css() {
		return <<<'CSS'
.podpub{--pp-accent:#2271b1;--pp-border:#c3c4c7;--pp-soft:#f6f7f7;--pp-text:#1d2327;--pp-muted:#646970;--pp-ok:#00a32a;--pp-warn:#dba617;--pp-bad:#d63638;max-width:1100px}
.podpub h1{margin-bottom:8px}
.podpub-back{display:inline-block;margin:0 0 10px;text-decoration:none;color:var(--pp-muted)}
.podpub-back:hover{color:var(--pp-accent)}
.podpub-hero{display:flex;gap:16px;align-items:center;background:#fff;border:1px solid var(--pp-border);border-radius:8px;padding:14px 18px;margin:12px 0 18px}
.podpub-hero-art{flex:0 0 72px;width:72px;height:72px;border-radius:8px;overflow:hidden;background:var(--pp-soft);border:1px solid var(--pp-border)}
.podpub-hero-art img{width:100%;height:100%;object-fit:cover;display:block}
.podpub-hero-main{flex:1;min-width:0}
.podpub-feedline{margin:8px 0 2px;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.podpub-feedline code{background:var(--pp-soft);padding:3px 8px;border-radius:4px;word-break:break-all}
.podpub-meta{color:var(--pp-muted);margin-top:4px}
.podpub-pill{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600;line-height:20px;background:#f0f0f1;color:var(--pp-text);border:1px solid transparent}
.podpub-pill--ok{background:#edfaef;color:#005c12;border-color:#b8e6bf}
.podpub-pill--warn{background:#fcf9e8;color:#614a00;border-color:#f0d980}
.podpub-pill--bad{background:#fcf0f1;color:#8a2424;border-color:#f1b1b4}
.podpub-pill--draft{background:#f0f0f1;color:var(--pp-muted);border-color:#dcdcde}
.podpub-tabs .nav-tab-wrapper{margin:0;padding-top:0;border-bottom:1px solid var(--pp-border);overflow-x:auto;white-space:nowrap;display:flex;flex-wrap:nowrap}
.podpub-tabs .nav-tab{float:none;margin-bottom:-1px;flex:0 0 auto;padding:10px 16px;font-size:14px}
.podpub-tabs .nav-tab:focus{box-shadow:0 0 0 2px var(--pp-accent);outline:2px solid transparent}
.podpub-panel{background:#fff;border:1px solid var(--pp-border);border-top:0;border-radius:0 0 8px 8px;padding:6px 22px 4px}
.podpub-panel[hidden]{display:none}
.podpub-panel .form-table{margin-top:6px}
.podpub-panel .form-table th{padding-left:0;width:190px}
.podpub-panel h2{margin:18px 0 6px}
.podpub-panel .description{max-width:70em}
.podpub-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:16px 0 4px}
.podpub-card{border:1px solid var(--pp-border);border-radius:8px;background:#fff;margin:12px 0}
.podpub-card>summary{cursor:pointer;padding:12px 16px;font-size:14px;font-weight:600;list-style:none;display:flex;align-items:center;gap:8px;border-radius:8px}
.podpub-card>summary::-webkit-details-marker{display:none}
.podpub-card>summary::before{content:"\25B8";color:var(--pp-muted);transition:transform .12s}
.podpub-card[open]>summary::before{transform:rotate(90deg)}
.podpub-card>summary:hover{background:var(--pp-soft)}
.podpub-card>summary:focus-visible{outline:2px solid var(--pp-accent)}
.podpub-card[open]>summary{border-bottom:1px solid var(--pp-border);border-radius:8px 8px 0 0}
.podpub-card-body{padding:6px 16px 12px}
.podpub-card .podpub-badge{margin-left:auto;font-weight:400;font-size:12px;color:var(--pp-muted)}
.podpub-savebar{position:sticky;bottom:0;z-index:20;margin:18px -22px 0;padding:12px 22px;background:rgba(246,247,247,.96);border-top:1px solid var(--pp-border);border-radius:0 0 8px 8px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.podpub-savebar .submit{margin:0;padding:0}
.podpub-savebar .podpub-hint{color:var(--pp-muted);font-size:12px}
.podpub-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-top:16px}
.podpub-podcast{display:flex;gap:14px;align-items:center;background:#fff;border:1px solid var(--pp-border);border-radius:8px;padding:14px;text-decoration:none;color:inherit;transition:box-shadow .12s,border-color .12s}
.podpub-podcast:hover,.podpub-podcast:focus{border-color:var(--pp-accent);box-shadow:0 1px 6px rgba(0,0,0,.08);color:inherit}
.podpub-podcast .podpub-hero-art{flex-basis:64px;width:64px;height:64px}
.podpub-podcast strong{display:block;font-size:15px;margin-bottom:2px;color:var(--pp-accent)}
.podpub-empty{background:#fff;border:1px dashed var(--pp-border);border-radius:8px;padding:28px;text-align:center;color:var(--pp-muted);margin-top:16px}
.podpub-box{background:#fff;border:1px solid var(--pp-border);border-radius:8px;padding:6px 22px 14px;margin:14px 0;max-width:900px}
.podpub-box h2{margin-top:16px}
.podpub-danger{border:1px solid #f1b1b4;background:#fffafa;border-radius:8px;padding:6px 18px 14px;margin:18px 0 12px}
.podpub-danger h2{color:#8a2424;margin-top:12px}
.podpub-table{border-radius:6px;overflow:hidden}
.podpub-table td,.podpub-table th{vertical-align:middle}
.podpub .widefat input[type=text],.podpub .widefat select{max-width:100%}
.podpub .podpub-rows{overflow-x:auto;max-width:100%}
@media (max-width:782px){
.podpub-hero{flex-direction:column;align-items:flex-start}
.podpub-panel{padding:4px 14px}
.podpub-savebar{margin:16px -14px 0;padding:10px 14px}
.podpub-panel .form-table th{width:auto}
.podpub-tabs .nav-tab{padding:9px 12px}
.podpub-card-body{padding:4px 10px 10px}
}
@media (prefers-reduced-motion:reduce){.podpub-card>summary::before{transition:none}}
.podpub-listmode .podpub-table-wrap,.podpub-listmode .podpub-rows-note{display:none}
.podpub-items{display:grid;gap:8px;margin:8px 0}
.podpub-item{display:flex;justify-content:space-between;align-items:center;gap:12px;background:#fff;border:1px solid var(--pp-border);border-radius:6px;padding:10px 12px}
.podpub-item-main{min-width:0;display:flex;flex-direction:column;gap:2px}
.podpub-item-main strong{overflow-wrap:anywhere}
.podpub-item-meta{color:var(--pp-muted);font-size:12px;overflow-wrap:anywhere}
.podpub-item-actions{display:flex;gap:6px;flex:0 0 auto}
.podpub-remove{color:var(--pp-bad)!important;border-color:var(--pp-bad)!important}
.podpub-none{color:var(--pp-muted);font-style:italic;margin:4px 0}
.podpub-itembar{margin:8px 0}
.podpub-dialog{border:0;border-radius:8px;padding:0;width:min(640px,calc(100vw - 24px));max-height:calc(100vh - 40px);box-shadow:0 10px 40px rgba(0,0,0,.35)}
.podpub-dialog::backdrop{background:rgba(0,0,0,.5)}
.podpub-dialog-box{padding:18px 20px;overflow:auto;max-height:calc(100vh - 40px)}
.podpub-dialog-box h2{margin-top:0}
.podpub-dialog-fields{display:grid;gap:12px}
.podpub-field label{display:block;font-weight:600;margin-bottom:3px}
.podpub-field input[type=text],.podpub-field input[type=datetime-local],.podpub-field select{width:100%;max-width:100%}
.podpub-field-check{display:flex;align-items:center;gap:8px}
.podpub-field-check label{margin:0}
.podpub-dialog-err{color:var(--pp-bad);min-height:1.2em;margin:10px 0 0}
.podpub-dialog-foot{display:flex;gap:8px;margin-top:10px}
.podpub-dialog .podpub-geo{background:var(--pp-soft);border:1px solid var(--pp-border);border-radius:6px;padding:10px 12px;margin-bottom:14px}
.podpub-dialog .podpub-geo h3{margin:0 0 8px}
.podpub-dialog .podpub-geo-q{width:min(320px,100%)}
.podpub-dialog .podpub-geo-list{margin:8px 0;padding:0;list-style:none}
.podpub-dialog .podpub-geo-list li{margin:4px 0}
.podpub-dialog .podpub-geo-or{margin:10px 0 0;font-weight:600}
.podpub-tabs [hidden]{display:none!important}
.podpub-livebox{margin-top:22px}
CSS;
	}

	private static function js() {
		return <<<'JS'
(function(){
function ready(f){if(document.readyState!=="loading")f();else document.addEventListener("DOMContentLoaded",f);}
ready(function(){
document.querySelectorAll("[data-podpub-tabs]").forEach(function(root){
var tabs=[].slice.call(root.querySelectorAll('[role="tab"]')),panels=[].slice.call(root.querySelectorAll('[role="tabpanel"]'));
if(!tabs.length)return;
root.classList.add("podpub-js");
function show(k,focus){var found=false;tabs.forEach(function(t){var on=t.getAttribute("data-tab")===k;if(on)found=true;t.classList.toggle("nav-tab-active",on);t.setAttribute("aria-selected",on?"true":"false");t.tabIndex=on?0:-1;if(on&&focus)t.focus();});
if(!found)return false;panels.forEach(function(p){p.hidden=p.getAttribute("data-tab")!==k;});return true;}
var h=(location.hash||"").replace("#podpub-tab-","");
if(!show(h,false))show(root.getAttribute("data-default")||tabs[0].getAttribute("data-tab"),false);
window.addEventListener("hashchange",function(){var k=(location.hash||"").replace("#podpub-tab-","");if(k)show(k,false);});
tabs.forEach(function(t,i){
t.addEventListener("click",function(e){e.preventDefault();var k=t.getAttribute("data-tab");show(k,false);if(history.replaceState)history.replaceState(null,"","#podpub-tab-"+k);});
t.addEventListener("keydown",function(e){var n=null;if(e.key==="ArrowRight")n=tabs[(i+1)%tabs.length];else if(e.key==="ArrowLeft")n=tabs[(i-1+tabs.length)%tabs.length];else if(e.key==="Home")n=tabs[0];else if(e.key==="End")n=tabs[tabs.length-1];
if(n){e.preventDefault();show(n.getAttribute("data-tab"),true);}});
});
root.addEventListener("invalid",function(e){var p=e.target.closest&&e.target.closest('[role="tabpanel"]');if(p&&p.hidden){show(p.getAttribute("data-tab"),false);}},true);
});
document.querySelectorAll("[data-podpub-tabfield]").forEach(function(f){if(!f.form)return;f.form.addEventListener("submit",function(){var a=f.form.querySelector('[role="tab"][aria-selected="true"]');if(a)f.value=a.getAttribute("data-tab");});});
document.querySelectorAll("[data-podpub-copy]").forEach(function(b){b.addEventListener("click",function(){var v=b.getAttribute("data-podpub-copy"),old=b.textContent;
function done(){b.textContent="Copied";setTimeout(function(){b.textContent=old;},1500);}
if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(v).then(done,function(){});}else{var t=document.createElement("textarea");t.value=v;document.body.appendChild(t);t.select();try{document.execCommand("copy");done();}catch(x){}document.body.removeChild(t);}
});});
document.querySelectorAll("[data-podpub-list]").forEach(function(root){
if(!window.HTMLDialogElement||!root.querySelector("table"))return;
var table=root.querySelector("table"),tbody=table.querySelector("tbody"),heads=[].slice.call(table.querySelectorAll("thead th"));
var noun=root.getAttribute("data-noun")||"item",addText=root.getAttribute("data-add")||"Add",max=parseInt(root.getAttribute("data-max"),10)||50;
var titleKeys=(root.getAttribute("data-title")||"").split(",").filter(Boolean);
var none=root.getAttribute("data-none")||"Nothing here yet.";
root.classList.add("podpub-listmode");
var list=document.createElement("div"),bar=document.createElement("p"),note=document.createElement("p"),addBtn=document.createElement("button");
list.className="podpub-items";bar.className="podpub-itembar";note.className="description";
addBtn.type="button";addBtn.className="button";addBtn.textContent=addText;bar.appendChild(addBtn);
note.textContent="Changes to this list are kept when you press the Save button below.";
root.appendChild(list);root.appendChild(bar);root.appendChild(note);
function T(e,t,c){var n=document.createElement(e);if(t)n.textContent=t;if(c)n.className=c;return n;}
function rows(){return [].slice.call(tbody.querySelectorAll("tr"));}
function ctl(row,i){return row.children[i].querySelector("input,select");}
function keyOf(i){return heads[i].getAttribute("data-key");}
function val(c){return c.type==="checkbox"?(c.checked?"1":""):c.value;}
function isEmpty(row){for(var i=0;i<heads.length;i++){if(val(ctl(row,i)).trim()!=="")return false;}return true;}
function shown(c){if(c.type==="checkbox")return c.checked?"Yes":"";if(c.tagName==="SELECT")return c.selectedIndex>=0&&c.value!==""?c.options[c.selectedIndex].text:"";return c.value.trim();}
function render(){
list.textContent="";var n=0;
rows().forEach(function(row){
if(isEmpty(row))return;n++;
var title="",meta=[];
for(var t=0;t<titleKeys.length&&!title;t++){for(var i=0;i<heads.length;i++){if(keyOf(i)===titleKeys[t]){title=shown(ctl(row,i));}}}
for(var i=0;i<heads.length;i++){var s=shown(ctl(row,i));if(s!==""&&s!==title)meta.push(heads[i].textContent+": "+s);}
var it=T("div",null,"podpub-item"),m=T("div",null,"podpub-item-main"),a=T("div",null,"podpub-item-actions");
m.appendChild(T("strong",title||"(no "+heads[0].textContent.toLowerCase()+")"));
if(meta.length)m.appendChild(T("span",meta.join(" · "),"podpub-item-meta"));
var e=T("button","Edit","button button-small"),r=T("button","Remove","button button-small podpub-remove");e.type=r.type="button";
e.addEventListener("click",function(){openDialog(row);});
r.addEventListener("click",function(){if(!confirm("Remove this "+noun+"? It is removed from the feed when you save."))return;clear(row);render();});
a.appendChild(e);a.appendChild(r);it.appendChild(m);it.appendChild(a);list.appendChild(it);
});
if(!n)list.appendChild(T("p",none,"podpub-none"));
addBtn.disabled=n>=max;addBtn.title=n>=max?"The most allowed ("+max+") is already here.":"";
}
function clear(row){for(var i=0;i<heads.length;i++){var c=ctl(row,i);if(c.type==="checkbox")c.checked=false;else if(c.tagName==="SELECT")c.selectedIndex=0;else c.value="";}}
function freeRow(){var rs=rows();for(var i=0;i<rs.length;i++){if(isEmpty(rs[i]))return rs[i];}
var last=rs[rs.length-1],c=last.cloneNode(true),idx=rs.length;
[].forEach.call(c.querySelectorAll("input,select"),function(e){e.name=e.name.replace(/\[\d+\]\[([^\]]+)\]$/,"["+idx+"][$1]");});
tbody.appendChild(c);clear(c);return c;}
function openDialog(row){
var adding=!row,dlg=document.createElement("dialog"),box=T("div",null,"podpub-dialog-box"),fields={};
dlg.className="podpub-dialog";
box.appendChild(T("h2",(adding?"Add ":"Edit ")+noun));
if(root.getAttribute("data-geo-ajax"))box.appendChild(geoBlock(fields));
var grid=T("div",null,"podpub-dialog-fields");
var proto=rows()[0];
heads.forEach(function(h,i){
var c=ctl(proto,i).cloneNode(true),src=row?ctl(row,i):null,id="ppd-"+Math.random().toString(36).slice(2);
c.removeAttribute("name");c.id=id;
if(c.type==="checkbox")c.checked=src?src.checked:false;else if(c.tagName==="SELECT")c.selectedIndex=src?src.selectedIndex:0;else c.value=src?src.value:"";
var w=T("div",null,"podpub-field"),l=T("label",h.textContent+(h.getAttribute("data-req")?" *":""));l.setAttribute("for",id);
if(c.type==="checkbox"){w.appendChild(c);w.appendChild(l);w.className+=" podpub-field-check";}else{w.appendChild(l);w.appendChild(c);}
grid.appendChild(w);fields[h.getAttribute("data-key")]=c;
});
box.appendChild(grid);
var err=T("p",null,"podpub-dialog-err");err.setAttribute("role","alert");box.appendChild(err);
var foot=T("div",null,"podpub-dialog-foot"),ok=T("button",adding?"Add":"Save "+noun,"button button-primary"),no=T("button","Cancel","button");ok.type=no.type="button";
foot.appendChild(ok);foot.appendChild(no);box.appendChild(foot);dlg.appendChild(box);document.body.appendChild(dlg);
function close(){if(dlg.open)dlg.close();if(dlg.parentNode)dlg.parentNode.removeChild(dlg);}
no.addEventListener("click",close);dlg.addEventListener("cancel",function(){setTimeout(close,0);});
function save(){
for(var i=0;i<heads.length;i++){var k=heads[i].getAttribute("data-key"),c=fields[k];if(heads[i].getAttribute("data-req")&&val(c).trim()===""){err.textContent=heads[i].textContent+" is required.";c.focus();return;}}
var any=false;for(var k2 in fields){if(val(fields[k2]).trim()!=="")any=true;}
if(!any){err.textContent="Fill in at least one field.";return;}
var target=row||freeRow();
for(var j=0;j<heads.length;j++){var d=ctl(target,j),s=fields[heads[j].getAttribute("data-key")];if(d.type==="checkbox")d.checked=s.checked;else if(d.tagName==="SELECT")d.selectedIndex=s.selectedIndex;else d.value=s.value;}
close();render();}
ok.addEventListener("click",save);
dlg.addEventListener("keydown",function(e){if(e.key==="Enter"&&e.target.tagName==="INPUT"&&e.target.type!=="checkbox"&&!e.target.classList.contains("podpub-geo-q")){e.preventDefault();save();}});
dlg.showModal();
var first=grid.querySelector("input,select");if(root.getAttribute("data-geo-ajax")){var q=box.querySelector(".podpub-geo-q");if(q)q.focus();}else if(first)first.focus();
}
function geoBlock(fields){
var b=T("div",null,"podpub-geo"),q=T("input",null,"regular-text podpub-geo-q"),go=T("button","Search","button"),st=T("span",null,"podpub-geo-status"),ul=T("ul",null,"podpub-geo-list"),cr=T("p","Search results © OpenStreetMap contributors. Pick Use to fill in the fields below; you can still change anything before adding.","description");
b.appendChild(T("h3","Search for a place"));q.type="text";q.maxLength=120;q.placeholder="For example Melbourne";go.type="button";st.setAttribute("role","status");cr.hidden=true;
var line=T("div");line.appendChild(q);line.appendChild(document.createTextNode(" "));line.appendChild(go);line.appendChild(document.createTextNode(" "));line.appendChild(st);
b.appendChild(line);b.appendChild(ul);b.appendChild(cr);b.appendChild(T("p","Or fill in the details yourself:","podpub-geo-or"));
var busy=false;
function use(r){["name","geo","osm","country"].forEach(function(k){if(fields[k])fields[k].value=r[k]||"";});st.textContent="Filled in below.";}
function run(){if(busy)return;var v=q.value.trim();if(v.length<3){st.textContent="Type at least 3 characters.";return;}
busy=true;go.disabled=true;st.textContent="Searching…";ul.textContent="";cr.hidden=true;
var d=new FormData();d.append("action","podpub_geo_search");d.append("nonce",root.getAttribute("data-geo-nonce"));d.append("q",v);
fetch(root.getAttribute("data-geo-ajax"),{method:"POST",credentials:"same-origin",body:d}).then(function(r){return r.json();}).then(function(j){
if(!j||!j.success){st.textContent=(j&&j.data&&j.data.message)||"The search failed. Fill in the fields by hand.";return;}
var l=j.data.results||[];st.textContent=l.length?"":(j.data.message||"No places were found.");
l.forEach(function(r){var li=T("li"),u=T("button","Use","button button-small");u.type="button";u.addEventListener("click",function(){use(r);});li.appendChild(u);li.appendChild(T("span"," "+r.label));ul.appendChild(li);});if(l.length)cr.hidden=false;
}).catch(function(){st.textContent="The search failed. Fill in the fields by hand.";}).then(function(){busy=false;go.disabled=false;});}
go.addEventListener("click",run);q.addEventListener("keydown",function(e){if(e.key==="Enter"){e.preventDefault();run();}});
return b;}
addBtn.addEventListener("click",function(){openDialog(null);});
render();
});
});
})();
JS;
	}

	/**
	 * Print a set of tabs. The first tab is shown by default unless $default names another;
	 * the other panels start hidden (no flash of every section before the script runs).
	 *
	 * @param array  $tabs    key => array( 'label' => text, 'html' => panel content ).
	 * @param string $default Key of the tab to show first.
	 */
	private static function render_tabs( $tabs, $default ) {
		if ( ! isset( $tabs[ $default ] ) ) {
			$keys    = array_keys( $tabs );
			$default = $keys[0];
		}
		echo '<div class="podpub-tabs" data-podpub-tabs data-default="' . esc_attr( $default ) . '">';
		echo '<nav class="nav-tab-wrapper" role="tablist" aria-label="Sections">';
		foreach ( $tabs as $k => $t ) {
			echo '<a class="nav-tab' . ( $k === $default ? ' nav-tab-active' : '' ) . '" role="tab" id="podpub-t-' . esc_attr( $k ) . '" href="#podpub-tab-' . esc_attr( $k ) . '" aria-controls="podpub-tab-' . esc_attr( $k ) . '" aria-selected="' . ( $k === $default ? 'true' : 'false' ) . '" data-tab="' . esc_attr( $k ) . '">' . esc_html( $t['label'] ) . '</a>';
		}
		echo '</nav>';
		foreach ( $tabs as $k => $t ) {
			echo '<section class="podpub-panel" role="tabpanel" id="podpub-tab-' . esc_attr( $k ) . '" aria-labelledby="podpub-t-' . esc_attr( $k ) . '" data-tab="' . esc_attr( $k ) . '"' . ( $k === $default ? '' : ' hidden' ) . '>' . $t['html'] . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
	}

	/**
	 * The bar with the save button, kept in view at the bottom of a form.
	 *
	 * @param string $label Button text.
	 * @param string $hint  Optional small text beside the button.
	 */
	private static function render_savebar( $label, $hint = '' ) {
		echo '<div class="podpub-savebar">';
		submit_button( $label, 'primary', 'submit', false );
		if ( '' !== $hint ) {
			echo '<span class="podpub-hint">' . esc_html( $hint ) . '</span>';
		}
		echo '</div>';
	}

	/** A small status label. */
	private static function pill( $text, $kind = 'draft' ) {
		return '<span class="podpub-pill podpub-pill--' . esc_attr( $kind ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Turn the "<h3>Title</h3> ..." sections in some Podcasting 2.0 form HTML into cards that open
	 * and close. A card is open when it already holds something.
	 *
	 * @param string $html Form HTML from render_p20_fields().
	 * @return string
	 */
	private static function cards( $html ) {
		$parts = preg_split( '#(?=<h3>)#', $html );
		$out   = array_shift( $parts );
		foreach ( $parts as $chunk ) {
			if ( ! preg_match( '#^<h3>(.*?)</h3>(.*)$#s', $chunk, $m ) ) {
				$out .= $chunk;
				continue;
			}
			$full = ( preg_match( '#<input(?![^>]*type="(?:hidden|checkbox|file|button|submit)")[^>]*\svalue="[^"]+"#', $m[2] ) || preg_match( '#<textarea[^>]*>[^<\s]#', $m[2] ) || preg_match( '#<input[^>]*type="checkbox"[^>]*\schecked#', $m[2] ) );
			$out .= '<details class="podpub-card"' . ( $full ? ' open' : '' ) . '><summary>' . $m[1] . ( $full ? '<span class="podpub-badge">in use</span>' : '' ) . '</summary><div class="podpub-card-body">' . $m[2] . '</div></details>';
		}
		return $out;
	}

	private static function render_rows( $name, $rows, $cols, $max, $ui = array() ) {
		$rows = array_values( $rows );
		$n    = min( $max, count( $rows ) + 3 );
		$list = ! empty( $ui );
		$ui   = array_merge( array( 'noun' => 'item', 'add' => 'Add', 'title' => '', 'none' => '', 'geo' => false ), $ui );
		echo '<div class="podpub-rows"';
		if ( $list ) {
			echo ' data-podpub-list data-noun="' . esc_attr( $ui['noun'] ) . '" data-add="' . esc_attr( $ui['add'] ) . '" data-title="' . esc_attr( $ui['title'] ) . '" data-none="' . esc_attr( $ui['none'] ) . '" data-max="' . (int) $max . '"';
			if ( $ui['geo'] && PodPub_Geo::enabled() ) {
				echo ' data-geo-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-geo-nonce="' . esc_attr( wp_create_nonce( 'podpub_geo' ) ) . '"';
			}
		}
		echo '><div class="podpub-table-wrap"><table class="widefat striped podpub-table"><thead><tr>';
		foreach ( $cols as $c ) {
			echo '<th data-key="' . esc_attr( $c['key'] ) . '"' . ( ! empty( $c['req'] ) ? ' data-req="1"' : '' ) . '>' . esc_html( $c['label'] ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		for ( $i = 0; $i < $n; $i++ ) {
			$r = isset( $rows[ $i ] ) && is_array( $rows[ $i ] ) ? $rows[ $i ] : array();
			echo '<tr>';
			foreach ( $cols as $c ) {
				$k   = $c['key'];
				$val = isset( $r[ $k ] ) ? $r[ $k ] : '';
				$nm  = esc_attr( $name . '[' . $i . '][' . $k . ']' );
				echo '<td>';
				if ( 'select' === $c['type'] ) {
					echo '<select name="' . $nm . '">';
					foreach ( $c['options'] as $ov => $ol ) {
						echo '<option value="' . esc_attr( $ov ) . '"' . selected( (string) $val, (string) $ov, false ) . '>' . esc_html( $ol ) . '</option>';
					}
					echo '</select>';
				} elseif ( 'datetime' === $c['type'] ) {
					$when = is_numeric( $val ) && (int) $val > 0 ? PodPub_Episodes::format_date( (int) $val ) : ( is_scalar( $val ) ? (string) $val : '' );
					echo '<input type="datetime-local" name="' . $nm . '" value="' . esc_attr( $when ) . '">';
				} elseif ( 'check' === $c['type'] ) {
					echo '<input type="checkbox" name="' . $nm . '" value="1"' . checked( ! empty( $val ), true, false ) . '>';
				} else {
					echo '<input type="text" class="' . esc_attr( isset( $c['class'] ) ? $c['class'] : 'regular-text' ) . '" name="' . $nm . '" value="' . esc_attr( is_scalar( $val ) ? (string) $val : '' ) . '"' . ( isset( $c['ph'] ) ? ' placeholder="' . esc_attr( $c['ph'] ) . '"' : '' ) . '>';
				}
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div><p class="description podpub-rows-note">Empty rows are ignored. Save to get more empty rows.</p></div>';
	}

	/**
	 * The Podcasting 2.0 sections, for a podcast (channel) or an episode (item).
	 *
	 * @param string     $prefix Field name prefix: podpub_p20 or podpub_ep.
	 * @param array      $d      Saved details.
	 * @param array|null $in     What was typed before an error (refills the form), or null.
	 * @param bool       $item   True for an episode.
	 * @param array|null $ctx    For a saved episode: { podcast, episode } ids (to list files in its folder).
	 */
	/**
	 * When a folder file list holds names with spaces or special characters: say they will be
	 * renamed and ask the owner to tick a box first (saving refuses without it).
	 *
	 * @param string[] $files File names in the list.
	 */
	private static function render_rename_ok( $files ) {
		$bad = array();
		foreach ( $files as $f ) {
			if ( ! PodPub_Episodes::safe_file_name( $f ) ) {
				$bad[ $f ] = PodPub_Storage::file_name( $f );
			}
		}
		if ( ! $bad ) {
			return;
		}
		echo '<div class="notice notice-warning inline"><p><strong>These file names have spaces or special characters, which can break their web address:</strong></p><ul style="list-style:disc;margin-left:20px">';
		foreach ( $bad as $old => $new ) {
			echo '<li><code>' . esc_html( $old ) . '</code> would be renamed to <code>' . esc_html( $new ) . '</code></li>';
		}
		echo '</ul><p>The old name will no longer exist, so anything linking to it will break. If you pick one of these files, tick the box to allow the rename, or rename it yourself first (by FTP).</p>';
		echo '<p><label><input type="checkbox" name="podpub_ep[rename_ok]" value="1"> Rename it to the safe name when I save</label></p></div>';
	}

	/**
	 * The Live episodes section, shown on the Episodes tab of a podcast in its own form.
	 *
	 * @param int        $id Podcast id.
	 * @param array      $d  Saved podcast details.
	 * @param array|null $in What was typed before an error, or null.
	 */
	private static function render_live_form( $id, $d, $in ) {
		$live_rows = null !== $in && isset( $in['live'] ) && is_array( $in['live'] ) ? $in['live'] : $d['live'];
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="podpub-livebox">';
		echo '<input type="hidden" name="action" value="podpub_save_live"><input type="hidden" name="podpub_id" value="' . (int) $id . '">';
		wp_nonce_field( 'podpub_save_live' );
		ob_start();
		echo '<h3>Live episodes</h3><p class="description">A live stream that apps can announce before it starts and show while it runs. Give the stream address (https://) and its type (usually audio/mpeg); set the status to Pending until it is on air, Live while it is, and Ended afterwards. Change the status and save to update the feed. The link (optional) is a page where people can join.</p>';
		self::render_rows( 'podpub_p20[live]', $live_rows, array(
			array( 'key' => 'title', 'label' => 'Title', 'type' => 'text', 'req' => true ),
			array( 'key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => array( '' => '', 'pending' => 'Pending', 'live' => 'Live', 'ended' => 'Ended' ) ),
			array( 'key' => 'start', 'label' => 'Starts', 'type' => 'datetime' ),
			array( 'key' => 'end', 'label' => 'Ends (optional)', 'type' => 'datetime' ),
			array( 'key' => 'url', 'label' => 'Stream address', 'type' => 'text', 'ph' => 'https://' ),
			array( 'key' => 'type', 'label' => 'Stream type', 'type' => 'text', 'ph' => 'audio/mpeg', 'class' => 'small-text' ),
			array( 'key' => 'link', 'label' => 'Link', 'type' => 'text', 'ph' => 'https://' ),
		), PodPub_P20::MAX_LIVE, array( 'noun' => 'live episode', 'add' => 'Add live episode', 'title' => 'title', 'none' => 'No live episodes yet.' ) );
		echo self::cards( (string) ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput
		self::render_savebar( 'Save and write feed', 'Saves the live episodes and writes the feed file (the old file is kept as a backup).' );
		echo '</form>';
	}

	private static function render_p20_fields( $prefix, $d, $in, $item, $ctx = null ) {
		ob_start();
		self::render_p20_fields_raw( $prefix, $d, $in, $item, $ctx );
		echo self::cards( (string) ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function render_p20_fields_raw( $prefix, $d, $in, $item, $ctx = null ) {
		$v    = function ( $k, $fallback ) use ( $in ) {
			return null !== $in && isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) ? (string) $in[ $k ] : (string) $fallback;
		};
		$rows = function ( $key, $saved ) use ( $in ) {
			return null !== $in && isset( $in[ $key ] ) && is_array( $in[ $key ] ) ? $in[ $key ] : $saved;
		};
		$inherit = $item ? ' Leave this empty to use the podcast\'s.' : '';
		$in_text = function ( $k, $label, $saved, $ph = '', $class = 'regular-text' ) use ( $prefix, $v ) {
			echo '<tr><th scope="row"><label>' . esc_html( $label ) . '</label></th><td><input type="text" class="' . esc_attr( $class ) . '" name="' . esc_attr( $prefix . '[' . $k . ']' ) . '" value="' . esc_attr( $v( $k, $saved ) ) . '" placeholder="' . esc_attr( $ph ) . '"></td></tr>';
		};

		echo '<h3>Funding</h3><p class="description">One link where listeners can support the show. Leave the message empty to use &ldquo;' . esc_html( PodPub_P20::FUNDING_DEFAULT ) . '&rdquo;.' . esc_html( $inherit ) . '</p>';
		$fr = $rows( 'funding', $d['funding'] );
		$f0 = isset( $fr[0] ) && is_array( $fr[0] ) ? $fr[0] : array();
		echo '<table class="form-table" role="presentation"><tr><th scope="row"><label>Web address</label></th><td><input type="text" class="regular-text" name="' . esc_attr( $prefix . '[funding][0][url]' ) . '" value="' . esc_attr( isset( $f0['url'] ) && is_scalar( $f0['url'] ) ? (string) $f0['url'] : '' ) . '" placeholder="https://"></td></tr>';
		echo '<tr><th scope="row"><label>Message (up to 128 characters)</label></th><td><input type="text" class="regular-text" maxlength="128" name="' . esc_attr( $prefix . '[funding][0][message]' ) . '" value="' . esc_attr( isset( $f0['message'] ) && is_scalar( $f0['message'] ) ? (string) $f0['message'] : '' ) . '" placeholder="' . esc_attr( PodPub_P20::FUNDING_DEFAULT ) . '"></td></tr></table>';

		echo '<h3>Location</h3><p class="description">Where the ' . ( $item ? 'episode' : 'podcast' ) . ' is about (subject) or was made (creator). Use Add location to search for a place or type its details yourself. The name is required; the rest is recommended.' . esc_html( $inherit ) . '</p>';
		self::render_rows( $prefix . '[locations]', $rows( 'locations', $d['locations'] ), array(
			array( 'key' => 'name', 'label' => 'Place name', 'type' => 'text', 'ph' => 'Melbourne', 'req' => true ),
			array( 'key' => 'rel', 'label' => 'Place is', 'type' => 'select', 'options' => array( '' => 'The subject (default)', 'creator' => 'Where it was made' ) ),
			array( 'key' => 'geo', 'label' => 'Coordinates', 'type' => 'text', 'ph' => '-37.8136,144.9631', 'class' => 'regular-text code' ),
			array( 'key' => 'osm', 'label' => 'OpenStreetMap id', 'type' => 'text', 'ph' => 'R2316741 or an openstreetmap.org address', 'class' => 'regular-text code' ),
			array( 'key' => 'country', 'label' => 'Country', 'type' => 'text', 'ph' => 'AU', 'class' => 'small-text' ),
		), PodPub_P20::MAX_LOCATIONS, array( 'noun' => 'location', 'add' => 'Add location', 'title' => 'name', 'none' => 'No locations yet.', 'geo' => true ) );

		$prows      = $rows( 'persons', $d['persons'] );
		$role_opts  = array( '' => 'None' );
		$group_opts = array( '' => 'Automatic (from the role)' );
		foreach ( PodPub_P20::taxonomy() as $g => $roles ) {
			$group_opts[ $g ] = PodPub_P20::label( $g );
		}
		foreach ( PodPub_P20::all_roles() as $r ) {
			$role_opts[ $r ] = PodPub_P20::label( $r );
		}
		foreach ( $prows as $pr ) { // A value saved before these lists existed stays visible (and is refused until changed).
			foreach ( array( 'role' => 'role_opts', 'group' => 'group_opts' ) as $k => $var ) {
				if ( is_array( $pr ) && ! empty( $pr[ $k ] ) && is_scalar( $pr[ $k ] ) && ! isset( ${$var}[ strtolower( (string) $pr[ $k ] ) ] ) ) {
					${$var}[ strtolower( (string) $pr[ $k ] ) ] = (string) $pr[ $k ] . ' (not in the list)';
				}
			}
		}
		echo '<h3>People</h3><p class="description">Hosts, guests and others, shown by podcast apps.' . esc_html( $inherit ) . ' Role and group come from the Podcast namespace people taxonomy. Choose a role and the group is filled in for you (hosts and guests are in the group Cast, which is the default); choose a group only to put a role that appears in two groups (such as Editor) in the one you mean.</p>';
		self::render_rows( $prefix . '[persons]', $prows, array(
			array( 'key' => 'name', 'label' => 'Name', 'type' => 'text', 'req' => true ),
			array( 'key' => 'role', 'label' => 'Role', 'type' => 'select', 'options' => $role_opts ),
			array( 'key' => 'group', 'label' => 'Group', 'type' => 'select', 'options' => $group_opts ),
			array( 'key' => 'img', 'label' => 'Picture address', 'type' => 'text', 'ph' => 'https://' ),
			array( 'key' => 'href', 'label' => 'Link', 'type' => 'text', 'ph' => 'https://' ),
		), PodPub_P20::MAX_PERSONS, array( 'noun' => 'person', 'add' => 'Add person', 'title' => 'name', 'none' => 'No people yet.' ) );

		echo '<h3>License</h3><table class="form-table" role="presentation">';
		$in_text( 'license_name', 'License', $d['license']['name'], 'cc-by-4.0' );
		$in_text( 'license_url', 'Address of the license', $d['license']['url'], 'https://creativecommons.org/licenses/by/4.0/' );
		echo '</table>';

		$lnrows = array_values( array_filter( $d['value']['recipients'], function ( $r ) {
			return isset( $r['type'] ) && 'lnaddress' === $r['type'];
		} ) );
		echo '<h3>Payments (value)</h3><p class="description">Listeners\' apps can send payments to the recipients while they listen. Each recipient is a Lightning address, which looks like name@example.com. Splits are shares, in proportion to each other (they do not have to add up to 100). Tick Fee for a recipient that takes a fee off the top.' . esc_html( $inherit ) . ' Check the addresses carefully: a wrong address sends money to the wrong place.</p>';
		echo '<table class="form-table" role="presentation">';
		$in_text( 'value_suggested', 'Suggested amount', $d['value']['suggested'], '0.00000015000', 'regular-text code' );
		echo '</table>';
		self::render_rows( $prefix . '[recipients]', $rows( 'recipients', $lnrows ), array(
			array( 'key' => 'name', 'label' => 'Name', 'type' => 'text', 'class' => 'regular-text' ),
			array( 'key' => 'address', 'label' => 'Lightning address', 'type' => 'text', 'ph' => 'name@example.com', 'class' => 'regular-text code' ),
			array( 'key' => 'split', 'label' => 'Split', 'type' => 'text', 'class' => 'small-text' ),
			array( 'key' => 'fee', 'label' => 'Fee', 'type' => 'check' ),
		), PodPub_P20::MAX_RECIPIENTS );

		if ( $item ) {
			echo '<h3>Value time splits</h3><p class="description">For part of this episode, send a share of the payment to another podcast (for example a song or a guest\'s show). Times are seconds, minutes:seconds or hours:minutes:seconds. Needs the payments above. Remote start is where in the other episode the part begins; percentage is the share of the payment that goes to the other podcast (empty means 100).</p>';
			self::render_rows( $prefix . '[timesplits]', $rows( 'timesplits', $d['timesplits'] ), array(
				array( 'key' => 'start', 'label' => 'Start', 'type' => 'text', 'class' => 'small-text' ),
				array( 'key' => 'duration', 'label' => 'Length', 'type' => 'text', 'class' => 'small-text' ),
				array( 'key' => 'remote_start', 'label' => 'Remote start', 'type' => 'text', 'class' => 'small-text' ),
				array( 'key' => 'remote_percent', 'label' => 'Percentage', 'type' => 'text', 'class' => 'small-text' ),
				array( 'key' => 'feed_guid', 'label' => 'Other podcast\'s feed GUID', 'type' => 'text', 'class' => 'regular-text code' ),
				array( 'key' => 'item_guid', 'label' => 'Other episode\'s GUID (optional)', 'type' => 'text', 'class' => 'regular-text code' ),
				array( 'key' => 'feed_url', 'label' => 'Other feed address (optional)', 'type' => 'text', 'ph' => 'https://' ),
			), PodPub_P20::MAX_SPLITS, array( 'noun' => 'time split', 'add' => 'Add time split', 'title' => 'start', 'none' => 'No time splits yet.' ) );
			echo '<h4>Import time splits</h4><p class="description">Paste time splits here or upload a file. The usual input is ready-made <code>&lt;podcast:valueTimeSplit&gt;</code> tags copied from a feed, but a whole value block, JSON, or lines separated by commas, tabs, semicolons or spaces (start, length, feed GUID, episode GUID, remote start, percentage, feed address) also work, with or without a header row. Decimals of a second are rounded to whole seconds. Nothing is kept until you save.</p>';
			echo '<p><textarea name="' . esc_attr( $prefix ) . '[splits_import]" rows="6" class="large-text code" placeholder="&lt;podcast:valueTimeSplit startTime=&quot;76.645988&quot; duration=&quot;270.916411&quot; remotePercentage=&quot;99&quot;&gt;&#10;  &lt;podcast:remoteItem feedGuid=&quot;...&quot; itemGuid=&quot;...&quot;/&gt;&#10;&lt;/podcast:valueTimeSplit&gt;">' . esc_textarea( isset( $in['splits_import'] ) && is_string( $in['splits_import'] ) ? $in['splits_import'] : '' ) . '</textarea></p>';
			echo '<p><label>Or upload a file (.txt, .xml, .csv or .json, up to 1 MB): <input type="file" name="podpub_splits_file" accept=".txt,.xml,.csv,.tsv,.json,text/plain,text/xml,application/json"></label> &nbsp; <label>When saving: <select name="' . esc_attr( $prefix ) . '[splits_mode]"><option value="replace">replace the time splits above</option><option value="add">add to the time splits above</option></select></label></p>';
		} else {
			echo '<h3>Block</h3><p class="description">Ask podcast apps not to list this podcast. Choose yes to block, no to say it is not blocked. To block or allow just one service, enter its id.</p>';
			self::render_rows( $prefix . '[block]', $rows( 'block', $d['block'] ), array(
				array( 'key' => 'id', 'label' => 'Service id (optional)', 'type' => 'text', 'class' => 'regular-text' ),
				array( 'key' => 'value', 'label' => 'Block', 'type' => 'select', 'options' => array( '' => '', 'yes' => 'Yes', 'no' => 'No' ) ),
			), PodPub_P20::MAX_BLOCK, array( 'noun' => 'block', 'add' => 'Add block', 'title' => 'value', 'none' => 'Nothing is blocked or allowed.' ) );

			echo '<h3>Podroll</h3><p class="description">Other podcasts you recommend. The feed GUID is the podcast:guid in the other podcast\'s feed.</p>';
			self::render_rows( $prefix . '[podroll]', $rows( 'podroll', $d['podroll'] ), array(
				array( 'key' => 'feed_guid', 'label' => 'Feed GUID', 'type' => 'text', 'class' => 'regular-text code' ),
				array( 'key' => 'feed_url', 'label' => 'Feed address (optional)', 'type' => 'text', 'ph' => 'https://' ),
				array( 'key' => 'title', 'label' => 'Title (optional)', 'type' => 'text' ),
			), PodPub_P20::MAX_PODROLL, array( 'noun' => 'recommended podcast', 'add' => 'Add podcast', 'title' => 'title,feed_guid', 'none' => 'No podcasts in the podroll yet.' ) );
		}

		if ( $item ) {
			echo '<h3>Chapters</h3><p class="description">Chapter marks that apps show on the timeline. Type them here and PodPublisher writes <code>chapters.json</code> into the episode folder and points the feed at it. Start is seconds, m:ss or h:mm:ss. The picture and link are optional.</p>';
			self::render_rows( $prefix . '[chapters]', $rows( 'chapters', $d['chapters'] ), array(
				array( 'key' => 'start', 'label' => 'Start', 'type' => 'text', 'class' => 'small-text' ),
				array( 'key' => 'title', 'label' => 'Title', 'type' => 'text' ),
				array( 'key' => 'img', 'label' => 'Picture address', 'type' => 'text', 'ph' => 'https://' ),
				array( 'key' => 'url', 'label' => 'Link', 'type' => 'text', 'ph' => 'https://' ),
			), PodPub_P20::MAX_CHAPTERS, array( 'noun' => 'chapter', 'add' => 'Add chapter', 'title' => 'title', 'none' => 'No chapters yet.' ) );
			echo '<table class="form-table" role="presentation">';
			$in_text( 'chapters_url', 'Or: a chapters file hosted elsewhere', $d['chapters_url'], 'https://', 'regular-text' );
			echo '</table><p class="description">Use the list above or this address, not both.</p>';
			echo '<p><label>Or upload a <code>chapters.json</code> file (JSON chapters, up to 1 MB): <input type="file" name="podpub_chapters_file" accept=".json,application/json"></label> &nbsp; It replaces the list above and any address when you save. Fields PodPublisher does not keep are named in a warning.</p>';

			echo '<h3>Transcripts</h3><p class="description">Links to transcript or caption files (hosted elsewhere or copied into the episode folder). Language is optional (the podcast\'s language is assumed). Put captions in Rel if the file has timecodes and is meant as closed captions.</p>';
			self::render_rows( $prefix . '[transcripts]', $rows( 'transcripts', $d['transcripts'] ), array(
				array( 'key' => 'url', 'label' => 'Web address', 'type' => 'text', 'ph' => 'https://' ),
				array( 'key' => 'type', 'label' => 'File type', 'type' => 'select', 'options' => array( '' => '' ) + PodPub_P20::transcript_types() ),
				array( 'key' => 'language', 'label' => 'Language', 'type' => 'text', 'ph' => 'en', 'class' => 'small-text' ),
				array( 'key' => 'rel', 'label' => 'Rel', 'type' => 'text', 'ph' => 'captions', 'class' => 'small-text' ),
			), PodPub_P20::MAX_TRANSCRIPTS, array( 'noun' => 'transcript', 'add' => 'Add transcript', 'title' => 'url', 'none' => 'No transcripts yet.' ) );
			echo '<p><label>Or upload a transcript file (' . esc_html( implode( ', ', array_keys( PodPub_Episodes::transcript_exts() ) ) ) . ', up to 5 MB): <input type="file" name="podpub_transcript"></label> &nbsp; It is added to the list above when you save.</p>';
			if ( $ctx ) {
				$files = PodPub_Episodes::list_folder_transcripts( $ctx['podcast'], $ctx['episode'] );
				if ( $files ) {
					echo '<p><label>Or pick a transcript file you copied into the episode folder: <select name="' . esc_attr( $prefix . '[transcript_pick]' ) . '"><option value="">Choose a file&hellip;</option>';
					foreach ( $files as $f ) {
						echo '<option value="' . esc_attr( $f ) . '">' . esc_html( $f ) . '</option>';
					}
					echo '</select></label></p>';
					self::render_rename_ok( $files );
				}
			}

			echo '<h3>Soundbites</h3><p class="description">Short highlights of the episode (15 to 120 seconds works best). Start and length are in seconds, for example 73 and 60; the title is optional.</p>';
			self::render_rows( $prefix . '[soundbites]', $rows( 'soundbites', $d['soundbites'] ), array(
				array( 'key' => 'start', 'label' => 'Start (seconds)', 'type' => 'text', 'class' => 'small-text' ),
				array( 'key' => 'duration', 'label' => 'Length (seconds)', 'type' => 'text', 'class' => 'small-text' ),
				array( 'key' => 'title', 'label' => 'Title (optional)', 'type' => 'text' ),
			), PodPub_P20::MAX_SOUNDBITES, array( 'noun' => 'soundbite', 'add' => 'Add soundbite', 'title' => 'title,start', 'none' => 'No soundbites yet.' ) );

			echo '<h3>Alternate files</h3><p class="description">Other versions of the episode (for example a video or a different quality), hosted elsewhere. Tick Default if it is the same as the main audio and preferred. Size, bitrate and height are whole numbers; the title is best at 32 characters or fewer. A checksum (kind and value together) lets apps check the file has not been changed.</p>';
			self::render_rows( $prefix . '[enclosures]', $rows( 'enclosures', $d['enclosures'] ), array(
				array( 'key' => 'uri', 'label' => 'Web address', 'type' => 'text', 'ph' => 'https://' ),
				array( 'key' => 'type', 'label' => 'File type', 'type' => 'text', 'ph' => 'video/mp4', 'class' => 'small-text' ),
				array( 'key' => 'length', 'label' => 'Size (bytes)', 'type' => 'text', 'class' => 'small-text' ),
				array( 'key' => 'bitrate', 'label' => 'Bitrate', 'type' => 'text', 'class' => 'small-text' ),
				array( 'key' => 'height', 'label' => 'Height', 'type' => 'text', 'class' => 'small-text' ),
				array( 'key' => 'lang', 'label' => 'Language', 'type' => 'text', 'class' => 'small-text' ),
				array( 'key' => 'title', 'label' => 'Title', 'type' => 'text', 'class' => 'small-text' ),
				array( 'key' => 'rel', 'label' => 'Group', 'type' => 'text', 'class' => 'small-text' ),
				array( 'key' => 'codecs', 'label' => 'Codecs', 'type' => 'text', 'class' => 'small-text' ),
				array( 'key' => 'default', 'label' => 'Default', 'type' => 'check' ),
				array( 'key' => 'integrity_type', 'label' => 'Checksum kind', 'type' => 'select', 'options' => array( '' => 'None', 'sri' => 'sri', 'pgp' => 'pgp' ) ),
				array( 'key' => 'integrity_value', 'label' => 'Checksum value', 'type' => 'text', 'ph' => 'sha384-...' ),
			), PodPub_P20::MAX_ENCLOSURES, array( 'noun' => 'alternate file', 'add' => 'Add alternate file', 'title' => 'title,uri', 'none' => 'No alternate files yet.' ) );
		} else {
			echo '<h3>Status and ownership</h3><table class="form-table" role="presentation">';
			echo '<tr><th scope="row"><label>Complete</label></th><td><label><input type="checkbox" name="' . esc_attr( $prefix . '[complete]' ) . '" value="1"' . checked( null !== $in ? ! empty( $in['complete'] ) : ! empty( $d['complete'] ), true, false ) . '> This podcast is finished (no more episodes will be published)</label></td></tr>';
			echo '<tr><th scope="row"><label>Locked</label></th><td><select name="' . esc_attr( $prefix . '[locked]' ) . '">';
			foreach ( array( '' => 'Not set', 'yes' => 'Yes: ask other platforms to refuse imports of this feed', 'no' => 'No: this feed may be imported' ) as $k => $label ) {
				echo '<option value="' . esc_attr( $k ) . '"' . selected( $v( 'locked', $d['locked'] ), $k, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td></tr>';
			$in_text( 'locked_owner', 'Owner email for moves', $d['locked_owner'], 'owner@example.com' );
			echo '</table>';

			echo '<h3>Trailers</h3><p class="description">A short promo for the podcast: its title, the address of the audio or video (https://), and when it was published. Size, type and season are optional.</p>';
			self::render_rows( $prefix . '[trailers]', $rows( 'trailers', $d['trailers'] ), array(
				array( 'key' => 'title', 'label' => 'Title', 'type' => 'text' ),
				array( 'key' => 'url', 'label' => 'Web address', 'type' => 'text', 'ph' => 'https://' ),
				array( 'key' => 'pubdate', 'label' => 'Published', 'type' => 'datetime' ),
				array( 'key' => 'length', 'label' => 'Size (bytes)', 'type' => 'text', 'class' => 'small-text' ),
				array( 'key' => 'type', 'label' => 'File type', 'type' => 'text', 'ph' => 'audio/mpeg', 'class' => 'small-text' ),
				array( 'key' => 'season', 'label' => 'Season', 'type' => 'text', 'class' => 'small-text' ),
			), PodPub_P20::MAX_TRAILERS );

		}

		echo '<h3>Comments (cross-app)</h3><p class="description">A social post whose replies apps can show as comments on this ' . ( $item ? 'episode' : 'podcast' ) . '. Protocol is for example activitypub; the address is the post\'s address. To say you do not want comments shown, enter the protocol disabled and nothing else.' . esc_html( $inherit ) . '</p>';
		self::render_rows( $prefix . '[social]', $rows( 'social', $d['social'] ), array(
			array( 'key' => 'protocol', 'label' => 'Protocol', 'type' => 'text', 'ph' => 'activitypub', 'class' => 'small-text' ),
			array( 'key' => 'uri', 'label' => 'Address of the post', 'type' => 'text', 'ph' => 'https://' ),
			array( 'key' => 'account_id', 'label' => 'Account id', 'type' => 'text', 'class' => 'small-text' ),
			array( 'key' => 'account_url', 'label' => 'Account address', 'type' => 'text', 'ph' => 'https://' ),
			array( 'key' => 'priority', 'label' => 'Priority', 'type' => 'text', 'class' => 'small-text' ),
		), PodPub_P20::MAX_SOCIAL, array( 'noun' => 'comment link', 'add' => 'Add comment link', 'title' => 'uri,protocol', 'none' => 'No comment links yet.' ) );
	}

	/**
	 * Episode list under the details form.
	 *
	 * @param int $id Podcast record id.
	 */
	private static function render_episode_list( $id ) {
		$eps = PodPub_Episodes::all( $id );
		echo '<div class="podpub-actions"><a href="' . esc_url( self::feed_page_url( $id, array( 'episode' => 'new' ) ) ) . '" class="button button-primary">Add episode</a>';
		if ( $eps ) {
			echo ' <a href="' . esc_url( self::feed_page_url( $id, array( 'episode' => 'new', 'copy' => 1 ) ) ) . '" class="button">Add episode, copying details from the latest</a>';
		}
		echo '</div>';
		if ( ! $eps ) {
			echo '<div class="podpub-empty">No episodes yet. Use <strong>Add episode</strong> to create the first one.</div>';
			return;
		}
		echo '<table class="wp-list-table widefat fixed striped podpub-table"><thead><tr><th>Episode</th><th style="width:12em">Date</th><th style="width:11em">Status</th></tr></thead><tbody>';
		foreach ( $eps as $e ) {
			$d    = PodPub_Episodes::details( $e->ID );
			$link = self::feed_page_url( $id, array( 'episode' => (int) $e->ID ) );
			if ( 'published' !== $d['status'] ) {
				$stat = self::pill( 'Draft', 'draft' );
			} elseif ( ! PodPub_Episodes::has_audio( $d ) ) {
				$stat = self::pill( 'Published (no audio)', 'warn' );
			} else {
				$stat = self::pill( 'Published', 'ok' );
			}
			$num = '';
			if ( $d['episode'] ) {
				$num = ( $d['season'] ? 'S' . (int) $d['season'] . ' ' : '' ) . 'E' . (int) $d['episode'] . ' &middot; ';
			}
			echo '<tr><td>' . $num . '<strong><a href="' . esc_url( $link ) . '">' . esc_html( $e->post_title ) . '</a></strong></td><td>' . esc_html( PodPub_Episodes::format_date( $d['published'] ) ) . '</td><td>' . $stat . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table><p class="description" style="margin:10px 0 14px">Saving an episode stores it on the server. The feed file changes only when you use Save and write feed on the Details or Podcasting 2.0 tab.</p>';
	}

	/**
	 * Episode form (new or edit).
	 *
	 * @param int        $id      Podcast record id.
	 * @param WP_Post    $feed    Podcast record.
	 * @param string     $which   'new' or an episode id.
	 * @param array|null $notice  The notice just shown (its input refills the form after an error).
	 */
	private static function render_episode( $id, $feed, $which, $notice ) {
		$eid  = 'new' === $which ? 0 : (int) $which;
		$post = $eid ? PodPub_Episodes::get( $eid, $id ) : null;
		echo '<a class="podpub-back" href="' . esc_url( self::feed_page_url( $id, array( 'tab' => 'episodes' ) ) ) . '">&larr; Back to ' . esc_html( $feed->post_title ) . '</a>';
		if ( $eid && ! $post ) {
			echo '<h2>Episode not found</h2>';
			return;
		}
		$copied = false;
		$title  = $post ? $post->post_title : '';
		if ( $post ) {
			$d = PodPub_Episodes::details( $eid );
		} else {
			$d = array_merge( PodPub_Episodes::defaults(), array( 'published' => time() ) );
			if ( ! empty( $_GET['copy'] ) && null !== ( $cd = PodPub_Episodes::copy_defaults( $id ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.CodeAnalysis.AssignmentInCondition
				$d      = $cd;
				$copied = true;
				$title  = $cd['title'];
			} else {
				// Starting fresh: the season and number carry on from the latest episode.
				$all = PodPub_Episodes::all( $id );
				if ( $all ) {
					$d['season'] = PodPub_Episodes::details( $all[0]->ID )['season'];
				}
				$d['episode'] = PodPub_Episodes::next_number( $id, $d['season'] );
			}
		}
		$in    = is_array( $notice ) && ! empty( $notice['input'] ) ? $notice['input'] : null;
		$v     = function ( $k, $fallback ) use ( $in ) {
			return null !== $in && isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) ? (string) $in[ $k ] : (string) $fallback;
		};
		echo '<h2 style="margin:6px 0 12px">' . ( $post ? 'Edit episode' : 'Add episode' );
		if ( $post ) {
			echo ' ' . ( 'published' === $d['status'] ? self::pill( 'Published', 'ok' ) : self::pill( 'Draft', 'draft' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</h2>';
		if ( $copied ) {
			echo '<p class="description">Started from the latest episode: the title, description, show notes, subtitle, summary, keywords, season, people, funding, location, licence, payments and comments are copied. Not copied: the guid (each episode gets its own), the date and time, the explicit choice, the audio and anything tied to the audio (chapters, transcripts, soundbites, alternate files, picture, time splits). Change the title and anything else that differs.</p>';
		} elseif ( ! $post ) {
			echo '<p class="description">Starting empty. The season and episode number carry on from the latest episode.</p>';
		}
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="podpub_save_episode"><input type="hidden" name="podpub_id" value="' . (int) $id . '"><input type="hidden" name="podpub_episode_id" value="' . (int) $eid . '">';
		wp_nonce_field( 'podpub_save_episode' );
		echo '<input type="hidden" name="podpub_tab" value="" data-podpub-tabfield>';
		ob_start();
		echo '<tr><th scope="row"><label for="ep_title">Title</label></th><td><input type="text" id="ep_title" class="regular-text" required name="podpub_ep[title]" value="' . esc_attr( $v( 'title', $title ) ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="ep_desc">Description</label></th><td><textarea id="ep_desc" name="podpub_ep[description]" rows="3" class="large-text" required>' . esc_textarea( $v( 'description', $d['description'] ) ) . '</textarea><p class="description">Plain text summary shown in podcast apps.</p></td></tr>';
		echo '<tr><th scope="row"><label for="ep_sub">Subtitle</label></th><td><input type="text" id="ep_sub" class="large-text" maxlength="255" name="podpub_ep[subtitle]" value="' . esc_attr( $v( 'subtitle', $d['subtitle'] ) ) . '"><p class="description">Optional. One short line.</p></td></tr>';
		echo '<tr><th scope="row"><label for="ep_sum">Summary</label></th><td><textarea id="ep_sum" name="podpub_ep[summary]" rows="3" class="large-text">' . esc_textarea( $v( 'summary', $d['summary'] ) ) . '</textarea><p class="description">Optional. A longer plain text summary for apps that show one.</p></td></tr>';
		echo '<tr><th scope="row"><label for="ep_kw">Keywords</label></th><td><input type="text" id="ep_kw" class="regular-text" maxlength="255" name="podpub_ep[keywords]" value="' . esc_attr( $v( 'keywords', $d['keywords'] ) ) . '"><p class="description">Optional, separated by commas.</p></td></tr>';
		$ex = $v( 'explicit', $d['explicit'] );
		echo '<tr><th scope="row"><label for="ep_ex">Explicit *</label></th><td><select id="ep_ex" name="podpub_ep[explicit]" required><option value=""' . selected( $ex, '', false ) . '>Choose&hellip;</option><option value="yes"' . selected( $ex, 'yes', false ) . '>Yes, this episode is explicit</option><option value="no"' . selected( $ex, 'no', false ) . '>No, it is not</option></select></td></tr>';
		echo '<tr><th scope="row"><label for="ep_img">Episode picture</label></th><td>';
		if ( $post && '' !== $d['image'] && '' !== PodPub_Storage::base_url() ) {
			echo '<p><img src="' . esc_url( PodPub_Storage::base_url() . '/' . PodPub_Feeds::dir_name( $id ) . '/' . PodPub_Episodes::dir_name( $eid ) . '/' . $d['image'] ) . '" alt="" style="max-width:120px;height:auto"> <label><input type="checkbox" name="podpub_ep[remove_image]" value="1"> Remove it</label></p>';
		}
		echo '<input type="file" id="ep_img" name="podpub_ep_image" accept="image/jpeg,image/png,image/gif"><p class="description">Optional. JPEG, PNG or GIF, square, 1400 to 3000 pixels. Smaller copies are made automatically for apps to use (not for a GIF). Without one, apps use the podcast\'s show art.</p></td></tr>';
		echo '<tr><th scope="row"><label for="ep_notes">Show notes</label></th><td><textarea id="ep_notes" name="podpub_ep[show_notes]" rows="8" class="large-text code">' . esc_textarea( $v( 'show_notes', $d['show_notes'] ) ) . '</textarea><p class="description">Optional. Links and simple formatting only (a, b, strong, i, em, br, p, ul, ol, li, h2-h4, blockquote); anything else is removed.</p></td></tr>';
		// Audio: three ways to give the episode its audio file.
		$pd       = PodPub_Feeds::dir_name( $id );
		$ed       = PodPub_Episodes::dir_name( $eid );
		$url      = PodPub_Storage::base_url();
		$src_now  = $v( 'audio_source', $d['audio_source'] ? $d['audio_source'] : ( '' !== $d['audio'] ? 'upload' : 'upload' ) );
		echo '<tr><th scope="row"><label for="ep_src">Audio</label></th><td>';
		if ( PodPub_Episodes::has_audio( $d ) ) {
			if ( '' !== $d['external_url'] ) {
				echo '<p>Current audio, hosted elsewhere: <code>' . esc_html( $d['external_url'] ) . '</code>';
			} else {
				echo '<p>Current file: <code>' . esc_html( $d['audio'] ) . '</code>';
			}
			echo ' (' . esc_html( $d['audio_size'] ? size_format( $d['audio_size'] ) : 'size unknown' ) . ', ' . esc_html( $d['audio_type'] ) . ')';
			if ( '' !== $d['audio'] && '' !== $url ) {
				echo ' <a href="' . esc_url( $url . '/' . $pd . '/' . $ed . '/' . $d['audio'] ) . '" target="_blank" rel="noopener">Open</a>';
			}
			echo '</p>';
		}
		echo '<select id="ep_src" name="podpub_ep[audio_source]">';
		foreach ( array( 'upload' => 'Upload a file', 'folder' => 'Pick a file I copied into the episode folder', 'external' => 'Use a file hosted elsewhere' ) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $src_now, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">Choosing a new file, or entering a new address, replaces the current audio. Leave the fields empty to keep it.</p>';

		echo '<div data-podpub-src="upload"><p><input type="file" id="ep_audio" name="podpub_audio" accept=".' . esc_attr( implode( ',.', array_keys( PodPub_Episodes::audio_types() ) ) ) . '"></p><p class="description">' . esc_html( strtoupper( implode( ', ', array_keys( PodPub_Episodes::audio_types() ) ) ) ) . '. Your server allows uploads up to ' . esc_html( size_format( wp_max_upload_size() ) ) . '. For a bigger file use the other options.</p></div>';

		echo '<div data-podpub-src="folder">';
		if ( $eid ) {
			$files = PodPub_Episodes::list_folder_audio( $id, $eid );
			echo '<p>Copy the audio file (for example by FTP) into this folder: <code>' . esc_html( PodPub_Storage::base_path() . '/' . $pd . '/' . $ed ) . '</code></p>';
			if ( $files ) {
				echo '<p><select name="podpub_ep[audio_pick]"><option value="">Choose a file&hellip;</option>';
				foreach ( $files as $f ) {
					echo '<option value="' . esc_attr( $f ) . '"' . selected( $v( 'audio_pick', '' ), $f, false ) . '>' . esc_html( $f . ( PodPub_Episodes::safe_file_name( $f ) ? '' : ' (will be renamed to remove spaces and special characters)' ) ) . '</option>';
				}
				echo '</select></p>';
				self::render_rename_ok( $files );
			} else {
				echo '<p class="description">No audio files found in that folder yet. Copy one in, then reload this page.</p>';
			}
		} else {
			echo '<p class="description">Save the episode first (as a draft). Its folder is made when it is saved; then copy the audio file into it and come back here to pick it.</p>';
		}
		echo '</div>';

		echo '<div data-podpub-src="external"><p><input type="url" class="large-text code" name="podpub_ep[audio_url]" placeholder="https://" value="' . esc_attr( $v( 'audio_url', $d['external_url'] ) ) . '"></p>';
		echo '<p>Size in bytes <input type="text" class="small-text" name="podpub_ep[audio_size]" value="' . esc_attr( $v( 'audio_size', $d['external_url'] && $d['audio_size'] ? $d['audio_size'] : '' ) ) . '"> &nbsp; Type <select name="podpub_ep[audio_ext_type]"><option value="">Work it out</option>';
		foreach ( PodPub_Episodes::audio_types() as $k => $mime ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $v( 'audio_ext_type', '' ), $k, false ) . '>' . esc_html( strtoupper( $k ) ) . '</option>';
		}
		echo '</select></p><p class="description">The address must start with https://. The size and type are asked from the other server when you leave them empty. The file itself is not checked, so make sure the address plays in a browser.</p></div>';
		echo '<script>(function(){var s=document.getElementById("ep_src");if(!s)return;function f(){document.querySelectorAll("[data-podpub-src]").forEach(function(e){e.hidden=e.getAttribute("data-podpub-src")!==s.value;});}s.addEventListener("change",f);f();})();</script>';
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="ep_date">Date and time</label></th><td><input type="datetime-local" id="ep_date" name="podpub_ep[published]" required value="' . esc_attr( $v( 'published', PodPub_Episodes::format_date( $d['published'] ) ) ) . '"><p class="description">In the site time zone.</p></td></tr>';
		echo '<tr><th scope="row"><label for="ep_dur">Duration</label></th><td><input type="text" id="ep_dur" class="small-text" name="podpub_ep[duration]" placeholder="1:02:30" value="' . esc_attr( $v( 'duration', $d['duration'] ? PodPub_Episodes::format_duration( $d['duration'] ) : '' ) ) . '"><p class="description">Hours:minutes:seconds. Leave empty to read it from the audio file when possible.</p></td></tr>';
		echo '<tr><th scope="row"><label for="ep_season">Season</label></th><td><input type="number" min="1" id="ep_season" class="small-text" name="podpub_ep[season]" value="' . esc_attr( $v( 'season', $d['season'] ? $d['season'] : '' ) ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="ep_num">Episode number</label></th><td><input type="number" min="1" id="ep_num" class="small-text" name="podpub_ep[episode]" value="' . esc_attr( $v( 'episode', $d['episode'] ? $d['episode'] : '' ) ) . '"></td></tr>';
		$type = $v( 'type', $d['type'] );
		echo '<tr><th scope="row"><label for="ep_type">Type</label></th><td><select id="ep_type" name="podpub_ep[type]">';
		foreach ( array( 'full' => 'Full episode', 'trailer' => 'Trailer', 'bonus' => 'Bonus' ) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $type, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
		$status = $v( 'status', $d['status'] );
		echo '<tr><th scope="row"><label for="ep_status">Status</label></th><td><select id="ep_status" name="podpub_ep[status]"><option value="draft"' . selected( $status, 'draft', false ) . '>Draft (not in the feed)</option><option value="published"' . selected( $status, 'published', false ) . '>Published (in the feed; needs audio)</option></select></td></tr>';
		$ps = PodPub_Posts::settings( (int) $id );
		if ( $ps['enabled'] ) {
			$has_post = $eid ? PodPub_Posts::post_for( $eid ) : 0;
			$answer   = $v( 'wp_post', $has_post ? 'no' : 'yes' );
			echo '<tr id="ep_post_row"><th scope="row"><label for="ep_post">WordPress post</label></th><td>';
			echo '<label><input type="radio" id="ep_post" name="podpub_ep[wp_post]" value="yes"' . checked( $answer, 'yes', false ) . '> ' . ( $has_post ? 'Update the WordPress post' : 'Create a WordPress post' ) . '</label> &nbsp; ';
			echo '<label><input type="radio" name="podpub_ep[wp_post]" value="no"' . checked( $answer, 'no', false ) . '> No, leave ' . ( $has_post ? 'the post as it is' : 'WordPress alone' ) . '</label>';
			if ( $has_post ) {
				echo '<p class="description"><strong>Choosing update replaces the post\'s title and content</strong> with this episode\'s title and show notes; any changes made to that post in WordPress will be lost. <a href="' . esc_url( get_edit_post_link( $has_post ) ) . '">Open the post</a></p>';
			} else {
				echo '<p class="description">Asked only when the episode is published. The post is made after the episode has saved, using the author and categories set on the WordPress posts tab of the podcast.</p>';
			}
			echo '<script>(function(){var s=document.getElementById("ep_status"),r=document.getElementById("ep_post_row");if(!s||!r)return;function f(){r.hidden=s.value!=="published";}s.addEventListener("change",f);f();})();</script>';
			echo '</td></tr>';
		}
		$all_rows = ob_get_clean();
		preg_match_all( '#<tr[^>]*>.*?</tr>#s', $all_rows, $mm );
		$by = array();
		foreach ( $mm[0] as $row ) {
			if ( preg_match( '#for="(ep_\w+)"#', $row, $idm ) ) {
				$by[ $idm[1] ] = $row;
			}
		}
		$placed = array( 'ep_title', 'ep_desc', 'ep_date', 'ep_status', 'ep_post', 'ep_type', 'ep_season', 'ep_num', 'ep_ex', 'ep_src', 'ep_dur', 'ep_notes', 'ep_sub', 'ep_sum', 'ep_kw', 'ep_img' );
		$group  = function ( $ids ) use ( $by, $placed ) {
			$h = '<table class="form-table" role="presentation">';
			foreach ( $ids as $k ) {
				if ( isset( $by[ $k ] ) ) {
					$h .= $by[ $k ];
				}
			}
			if ( 'ep_title' === $ids[0] ) { // A row nobody placed is never dropped: it goes on the first tab.
				foreach ( array_diff( array_keys( $by ), $placed ) as $k ) {
					$h .= $by[ $k ];
				}
			}
			return $h . '</table>';
		};
		$tabs = array(
			'episode' => array( 'label' => 'Episode', 'html' => $group( array( 'ep_title', 'ep_desc', 'ep_date', 'ep_status', 'ep_post', 'ep_type', 'ep_season', 'ep_num', 'ep_ex' ) ) ),
			'audio'   => array( 'label' => 'Audio', 'html' => $group( array( 'ep_src', 'ep_dur' ) ) ),
			'notes'   => array( 'label' => 'Notes and picture', 'html' => $group( array( 'ep_notes', 'ep_sub', 'ep_sum', 'ep_kw', 'ep_img' ) ) ),
		);
		ob_start();
		echo '<p class="description" style="margin-top:14px">Optional extras for apps that support Podcasting 2.0. Sections that already hold something are open.</p>';
		self::render_p20_fields( 'podpub_ep', $d, $in, true, $eid ? array( 'podcast' => (int) $id, 'episode' => $eid ) : null );
		$tabs['p20'] = array( 'label' => 'Podcasting 2.0', 'html' => ob_get_clean() );
		$want = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		self::render_tabs( $tabs, isset( $tabs[ $want ] ) ? $want : 'episode' );
		echo '<div class="podpub-savebar" style="margin:0;border:1px solid var(--pp-border);border-radius:8px;margin-top:14px">';
		submit_button( 'Save episode', 'primary', 'submit', false );
		echo '<span class="podpub-hint">Stores the episode on the server. The feed file is updated when you use Save and write feed on the podcast page.</span></div>';
		echo '</form>';
		if ( $post ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Delete this episode and its audio file? This cannot be undone.\');">';
			echo '<input type="hidden" name="action" value="podpub_delete_episode"><input type="hidden" name="podpub_id" value="' . (int) $id . '"><input type="hidden" name="podpub_episode_id" value="' . (int) $eid . '">';
			wp_nonce_field( 'podpub_delete_episode' );
			echo '<div class="podpub-danger" style="margin-top:18px">';
			if ( PodPub_Posts::post_for( $eid ) ) {
				echo '<p><label><input type="checkbox" name="podpub_delete_post" value="1"> Also move this episode\'s WordPress post to the trash</label></p>';
			}
			submit_button( 'Delete episode', 'delete', 'submit', false );
			echo '</div></form>';
		}
	}

	/**
	 * Main page: list of podcasts, New podcast, Adopt a feed.
	 */
	public static function render_list() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		echo '<div class="wrap podpub">';
		self::assets();
		echo '<h1 class="wp-heading-inline">PodPublisher</h1>';
		echo ' <a href="' . esc_url( self::list_url( array( 'action' => 'new' ) ) ) . '" class="page-title-action">New podcast</a>';
		echo ' <a href="' . esc_url( self::list_url( array( 'action' => 'import' ) ) ) . '" class="page-title-action">Adopt a feed</a>';
		echo '<hr class="wp-header-end">';
		self::render_delete_notice();

		if ( 'new' === $action ) {
			self::render_new();
		} elseif ( 'import' === $action ) {
			self::render_import();
		} else {
			self::render_table();
		}
		echo '</div>';
	}

	/**
	 * After a podcast was deleted: say what happened (shown on the list page).
	 */
	private static function render_delete_notice() {
		$key    = 'podpub_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) && ! empty( $notice['adopt_error'] ) ) {
			delete_transient( $key );
			echo '<div class="notice notice-error"><p>' . esc_html( $notice['adopt_error'] ) . '</p></div>';
			return;
		}
		if ( ! is_array( $notice ) || empty( $notice['deleted_podcast'] ) ) {
			return;
		}
		delete_transient( $key );
		$n    = (int) $notice['episodes'];
		$text = 'Podcast deleted (' . $n . ( 1 === $n ? ' episode' : ' episodes' ) . ' removed). ';
		$text .= ! empty( $notice['files_deleted'] )
			? 'Its folder and files were deleted too.'
			: 'Its files were left on the server' . ( '' !== $notice['folder'] ? ' in ' . $notice['folder'] : '' ) . ', so the feed address still works.';
		if ( ! empty( $notice['posts_trashed'] ) ) {
			$text .= ' ' . (int) $notice['posts_trashed'] . ' WordPress ' . ( 1 === (int) $notice['posts_trashed'] ? 'post or page was' : 'posts and pages were' ) . ' moved to the trash.';
		}
		echo '<div class="notice notice-success"><p>' . esc_html( $text ) . '</p></div>';
		foreach ( $notice['warnings'] as $m ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $m ) . '</p></div>';
		}
	}

	/**
	 * "Delete podcast" section at the bottom of a podcast's page.
	 *
	 * @param int     $id   Podcast record id.
	 * @param WP_Post $feed Podcast record.
	 */
	/** The WordPress posts tab: whether episodes get posts, who they are by, categories, and the podcast page. */
	private static function render_posts_tab( $id ) {
		$s    = PodPub_Posts::settings( $id );
		$page = PodPub_Posts::page_url( $id );
		echo '<h2>WordPress posts</h2>';
		echo '<p class="description">Each published episode can have an ordinary WordPress post with its title and show notes. The feed item then links to that post. These settings are only used when you answer yes on an episode; nothing here changes the feed file.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="podpub_save_posts"><input type="hidden" name="podpub_id" value="' . (int) $id . '">';
		wp_nonce_field( 'podpub_save_posts' );
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">Posts</th><td><label><input type="checkbox" name="podpub_posts[enabled]" value="1"' . checked( $s['enabled'], true, false ) . '> Offer to create a WordPress post for each episode</label></td></tr>';
		echo '<tr><th scope="row"><label for="pp_post_author">Author</label></th><td><select id="pp_post_author" name="podpub_posts[author]"><option value="0"' . selected( $s['author'], 0, false ) . '>Whoever saves the episode</option>';
		foreach ( PodPub_Posts::authors() as $u ) {
			echo '<option value="' . (int) $u->ID . '"' . selected( $s['author'], (int) $u->ID, false ) . '>' . esc_html( $u->display_name ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row">Categories</th><td>';
		$cats = PodPub_Posts::categories();
		if ( ! $cats ) {
			echo '<span class="description">There are no categories yet.</span>';
		}
		foreach ( $cats as $c ) {
			echo '<label style="display:block"><input type="checkbox" name="podpub_posts[categories][]" value="' . (int) $c->term_id . '"' . checked( in_array( (int) $c->term_id, $s['categories'], true ), true, false ) . '> ' . esc_html( $c->name ) . '</label>';
		}
		echo '</td></tr>';
		echo '<tr><th scope="row">Podcast page</th><td><label><input type="checkbox" name="podpub_posts[page]" value="1"' . checked( $s['page'], true, false ) . '> Keep a WordPress page for this podcast that lists its published episodes</label>';
		echo '<p class="description">The page shows the show art and description, then each published episode (newest first) with a link to its post. It is made when you save this tab and kept up to date when episodes are saved or deleted. Unticking it leaves the page as it is.</p>';
		if ( '' !== $page ) {
			echo '<p><a href="' . esc_url( $page ) . '" target="_blank" rel="noopener">View the page</a></p>';
		}
		echo '</td></tr></table>';
		self::render_savebar( 'Save WordPress post settings', 'Does not change the feed file.' );
		echo '</form>';
	}

	private static function render_delete_podcast( $id, $feed ) {
		$n   = count( PodPub_Episodes::all( $id ) );
		$dir = PodPub_Feeds::dir_name( $id );
		$msg = 'Delete this podcast and its ' . $n . ( 1 === $n ? ' episode' : ' episodes' ) . ' from PodPublisher? This cannot be undone. If you ticked the box to delete the files, the feed, show art and audio files are deleted from the server too, and apps or directories that use this feed will stop working.';
		echo '<h2>Delete podcast</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(' . esc_attr( wp_json_encode( $msg ) ) . ');">';
		echo '<input type="hidden" name="action" value="podpub_delete_podcast"><input type="hidden" name="podpub_id" value="' . (int) $id . '">';
		wp_nonce_field( 'podpub_delete_podcast' );
		echo '<p>Removes this podcast and all of its episodes from PodPublisher.</p>';
		if ( '' !== $dir ) {
			echo '<p><label><input type="checkbox" name="podpub_delete_files" value="1"> Also delete its folder on the server: <code>' . esc_html( PodPub_Storage::base_path() . '/' . $dir ) . '</code> (the feed file, show art, and every episode folder with its audio, chapters and other files)</label></p>';
			echo '<p class="description">Left unticked, the files stay on the server and the feed address keeps working, so listeners are not cut off. Ticked, they are gone for good.</p>';
		}
		echo '<p><label><input type="checkbox" name="podpub_delete_posts" value="1"> Also move the WordPress posts of its episodes, and its page, to the trash</label></p>';
		submit_button( 'Delete podcast', 'delete', 'submit', false );
		echo '</form>';
	}

	private static function render_table() {
		$feeds = PodPub_Feeds::all();
		if ( ! $feeds ) {
			echo '<div class="podpub-empty"><p style="font-size:15px;margin-top:0"><strong>No podcasts yet.</strong></p><p>Use <strong>New podcast</strong> to start one, or <strong>Adopt a feed</strong> to bring in one already on your server.</p></div>';
			return;
		}
		$base = PodPub_Storage::base_url();
		echo '<div class="podpub-grid">';
		foreach ( $feeds as $feed ) {
			$url = add_query_arg( 'page', self::SLUG_FEED . (int) $feed->ID, admin_url( 'admin.php' ) );
			$d   = PodPub_Details::get( $feed->ID );
			$dir = PodPub_Feeds::dir_name( $feed->ID );
			$n   = count( PodPub_Episodes::all( $feed->ID ) );
			echo '<a class="podpub-podcast" href="' . esc_url( $url ) . '"><span class="podpub-hero-art">';
			if ( '' !== $d['image'] && '' !== $base && '' !== $dir ) {
				echo '<img src="' . esc_url( $base . '/' . $dir . '/' . $d['image'] ) . '" alt="">';
			}
			echo '</span><span><strong>' . esc_html( $feed->post_title ) . '</strong><span class="podpub-meta">' . (int) $n . ( 1 === $n ? ' episode' : ' episodes' ) . '</span></span></a>';
		}
		echo '</div>';
	}

	private static function render_new() {
		echo '<div class="podpub-box"><h2>New podcast</h2>';
		if ( isset( $_GET['podpub_error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['podpub_error'] ) ) ) ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="podpub_create_feed">';
		wp_nonce_field( 'podpub_create_feed' );
		echo '<table class="form-table"><tr><th scope="row"><label for="podpub_title">Title</label></th>';
		echo '<td><input type="text" id="podpub_title" name="podpub_title" class="regular-text" required></td></tr></table>';
		submit_button( 'Create podcast' );
		echo '</form></div>';
	}

	/**
	 * Adopt a feed already on this server (or on the web): check it, show what would be lost, confirm.
	 */
	private static function render_import() {
		echo '<div class="podpub-box"><h2>Adopt a feed</h2>';
		echo '<p>Bring an existing podcast feed under PodPublisher. Give the feed address (https://) or the full path of the feed file on this server. Nothing is changed until you have read the report and confirmed.</p>';
		$source = '';
		$report = null;
		if ( isset( $_GET['podpub_source'] ) && '' !== trim( (string) wp_unslash( $_GET['podpub_source'] ) ) ) {
			check_admin_referer( 'podpub_adopt_check' );
			$source = trim( (string) wp_unslash( $_GET['podpub_source'] ) );
			$loaded = PodPub_Adopt::load( $source );
			$plan   = is_wp_error( $loaded ) ? $loaded : PodPub_Adopt::plan( $loaded );
			if ( is_wp_error( $plan ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $plan->get_error_message() ) . '</p></div>';
			} else {
				$report = $plan['report'];
			}
		}
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="action" value="import">';
		wp_nonce_field( 'podpub_adopt_check' );
		echo '<p><label for="podpub_source"><strong>Feed address or file path</strong></label><br>';
		echo '<input type="text" id="podpub_source" name="podpub_source" class="large-text" value="' . esc_attr( $source ) . '" placeholder="https://example.com/podcast/feed.xml" required> ';
		echo '</p>';
		submit_button( 'Check feed', 'secondary', 'submit', false );
		echo '</form>';
		if ( $report ) {
			self::render_adopt_report( $source, $report );
		}
		echo '</div>';
	}

	/**
	 * The report for a checked feed, with the confirm form.
	 *
	 * @param string $source Where the feed came from.
	 * @param array  $r      Report from PodPub_Adopt::plan().
	 */
	private static function render_adopt_report( $source, $r ) {
		echo '<hr><h3>' . esc_html( $r['title'] ) . '</h3><ul style="list-style:disc;margin-left:20px">';
		echo '<li>' . (int) $r['episodes'] . ' episodes found: ' . (int) $r['published'] . ' will be published, ' . (int) $r['drafts'] . ' will be drafts (no https audio address).</li>';
		if ( ! empty( $r['skipped'] ) ) {
			echo '<li>' . (int) $r['skipped'] . ' items skipped.</li>';
		}
		echo '<li>The podcast and episode ids (guids) are kept, so directories and apps see the same show.</li></ul>';
		if ( ! empty( $r['target']['in_place'] ) ) {
			echo '<p>The feed will be rewritten <strong>in place</strong> at <code>' . esc_html( $r['target']['url'] ) . '</code>, so its address does not change. A backup copy of the old file (.bak) is made first.</p>';
		} else {
			echo '<p>The feed cannot be rewritten where it is (it is on another site or the file is not writable). A new feed will be written in the Podcast folder; you will need to point listeners to the new address.</p>';
		}
		$needs = ! empty( $r['problems'] ) || ! empty( $r['lost'] );
		if ( ! empty( $r['problems'] ) ) {
			echo '<h4>Things that will change</h4><ul style="list-style:disc;margin-left:20px">';
			foreach ( $r['problems'] as $m ) {
				echo '<li>' . esc_html( $m ) . '</li>';
			}
			echo '</ul>';
		}
		if ( ! empty( $r['lost'] ) ) {
			echo '<h4>Tags in the old feed that would be lost</h4><table class="widefat striped podpub-table" style="max-width:900px"><thead><tr><th>Tag</th><th>In old feed</th><th>In new feed</th><th>Example</th></tr></thead><tbody>';
			foreach ( $r['lost'] as $l ) {
				echo '<tr><td>' . esc_html( $l['label'] ) . '</td><td>' . (int) $l['old'] . '</td><td>' . (int) $l['new'] . '</td><td>' . esc_html( (string) $l['sample'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Nothing in the old feed would be lost.</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
		echo '<input type="hidden" name="action" value="podpub_adopt_confirm"><input type="hidden" name="podpub_source" value="' . esc_attr( $source ) . '"><input type="hidden" name="podpub_hash" value="' . esc_attr( $r['hash'] ) . '">';
		wp_nonce_field( 'podpub_adopt_confirm' );
		if ( $needs ) {
			echo '<p><label><input type="checkbox" name="podpub_confirm" value="1" required> I have read the list above and accept that these changes will happen.</label></p>';
		} else {
			echo '<input type="hidden" name="podpub_confirm" value="1">';
		}
		submit_button( 'Adopt this feed', 'primary', 'submit', false );
		echo '</form>';
	}

	/**
	 * "Write feed anyway": the owner has read what would be lost. Back up the file, then write.
	 */
	public static function handle_write_feed_anyway() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'podpublisher' ), 403 );
		}
		check_admin_referer( 'podpub_write_feed_anyway' );
		$pid    = isset( $_POST['podpub_id'] ) ? (int) $_POST['podpub_id'] : 0;
		$result = array( 'errors' => array(), 'warnings' => array(), 'written' => false );
		if ( ! PodPub_Feeds::get( $pid ) ) {
			$result['errors'][] = 'This podcast was not found.';
		} else {
			$w = PodPub_FeedXml::write( $pid, true );
			if ( is_wp_error( $w ) ) {
				$result['errors'][] = $w->get_error_message();
			} else {
				$result['written'] = true;
			}
		}
		set_transient( 'podpub_notice_' . get_current_user_id(), $result, 120 );
		wp_safe_redirect( self::feed_page_url( $pid ) );
		exit;
	}

	/**
	 * Confirm handler: re-reads the feed and adopts it only if it is unchanged since the report.
	 */
	public static function handle_adopt_confirm() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'podpublisher' ), 403 );
		}
		check_admin_referer( 'podpub_adopt_confirm' );
		$key = 'podpub_notice_' . get_current_user_id();
		if ( empty( $_POST['podpub_confirm'] ) ) {
			set_transient( $key, array( 'adopt_error' => 'Tick the box to confirm before adopting.' ), 120 );
			wp_safe_redirect( self::list_url( array( 'action' => 'import' ) ) );
			exit;
		}
		$source = isset( $_POST['podpub_source'] ) ? trim( (string) wp_unslash( $_POST['podpub_source'] ) ) : '';
		$hash   = isset( $_POST['podpub_hash'] ) ? sanitize_key( wp_unslash( $_POST['podpub_hash'] ) ) : '';
		$loaded = PodPub_Adopt::load( $source );
		$result = is_wp_error( $loaded ) ? $loaded : PodPub_Adopt::commit( $loaded, $hash );
		if ( is_wp_error( $result ) ) {
			set_transient( $key, array( 'adopt_error' => $result->get_error_message() ), 120 );
			wp_safe_redirect( self::list_url( array( 'action' => 'import' ) ) );
			exit;
		}
		set_transient( $key, array( 'adopted' => $result ), 120 );
		wp_safe_redirect( self::feed_page_url( $result['id'] ) );
		exit;
	}

	/**
	 * One podcast's page.
	 *
	 * @param int $id Record id.
	 */
	public static function render_feed( $id ) {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$feed = PodPub_Feeds::get( $id );
		echo '<div class="wrap podpub">';
		self::assets();
		if ( ! $feed ) {
			echo '<h1>Podcast not found</h1><p><a href="' . esc_url( self::list_url() ) . '">Back to all podcasts</a></p></div>';
			return;
		}
		echo '<h1>' . esc_html( $feed->post_title ) . '</h1>';

		$key    = 'podpub_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) ) {
			delete_transient( $key );
			if ( ! empty( $notice['errors'] ) ) {
				echo '<div class="notice notice-error"><p>Nothing was saved:</p><ul>';
				foreach ( $notice['errors'] as $m ) {
					echo '<li>' . esc_html( $m ) . '</li>';
				}
				echo '</ul></div>';
			} elseif ( ! empty( $notice['adopted'] ) ) {
				$a = $notice['adopted'];
				echo '<div class="notice notice-success"><p>Feed adopted: ' . (int) $a['episodes'] . ' episodes imported.' . ( ! empty( $a['written'] ) ? ' The feed file was written.' : '' ) . ( ! empty( $a['backup'] ) ? ' Backup of the old file: ' . esc_html( $a['backup'] ) : '' ) . '</p></div>';
				foreach ( ! empty( $a['warnings'] ) ? $a['warnings'] : array() as $m ) {
					echo '<div class="notice notice-warning"><p>' . esc_html( $m ) . '</p></div>';
				}
			} elseif ( ! empty( $notice['posts_saved'] ) ) {
				echo '<div class="notice notice-success"><p>WordPress post settings saved. The feed file has not been changed.</p></div>';
			} else {
				echo '<div class="notice notice-success"><p>' . ( ! empty( $notice['written'] ) ? 'Saved. The feed file was written (the previous file was kept as a backup).' : 'Saved. The feed file has not been changed; press Save and write feed on the podcast page to publish.' ) . '</p></div>';
			}
			foreach ( ! empty( $notice['warnings'] ) ? $notice['warnings'] : array() as $m ) {
				echo '<div class="notice notice-warning"><p>' . esc_html( $m ) . '</p></div>';
			}
		}

		$st = PodPub_FeedXml::status( $id );
		if ( 'held' === $st['state'] ) {
			echo '<div class="notice notice-warning"><p><strong>Check before saving.</strong> ' . esc_html( $st['message'] ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="podpub_write_feed_anyway"><input type="hidden" name="podpub_id" value="' . (int) $id . '">';
			wp_nonce_field( 'podpub_write_feed_anyway' );
			submit_button( 'Write feed anyway', 'secondary', 'submit', false );
			echo '</form><p></p></div>';
		} elseif ( 'pending' === $st['state'] || 'unwritten' === $st['state'] ) {
			echo '<div class="notice notice-info"><p>' . esc_html( $st['message'] ) . ( isset( $_GET['episode'] ) ? ' <a href="' . esc_url( self::feed_page_url( $id ) ) . '">Go to the podcast page</a>' : '' ) . '</p></div>';
		}

		$which = isset( $_GET['episode'] ) ? sanitize_text_field( wp_unslash( $_GET['episode'] ) ) : '';
		if ( '' !== $which && ( 'new' === $which || ctype_digit( $which ) ) ) {
			self::render_episode( $id, $feed, $which, is_array( $notice ) ? $notice : null );
			echo '</div>';
			return;
		}

		$dir  = PodPub_Feeds::dir_name( $id );
		$d    = PodPub_Details::get( $id );
		$base = PodPub_Storage::base_url();
		$tgt  = PodPub_Feeds::feed_target( $id );
		$eps  = PodPub_Episodes::all( $id );
		$pub  = 0;
		foreach ( $eps as $e ) {
			if ( 'published' === PodPub_Episodes::details( $e->ID )['status'] ) {
				++$pub;
			}
		}
		$pills = array(
			'current'   => array( 'Feed is up to date', 'ok' ),
			'pending'   => array( 'Changes not in the feed yet', 'warn' ),
			'unwritten' => array( 'Feed not written yet', 'warn' ),
			'held'      => array( 'Needs your attention', 'bad' ),
			'none'      => array( 'Fill in the details to create the feed', 'draft' ),
		);
		$pill = $pills[ isset( $pills[ $st['state'] ] ) ? $st['state'] : 'none' ];
		echo '<div class="podpub-hero">';
		if ( '' !== $d['image'] && '' !== $base && '' !== $dir ) {
			echo '<div class="podpub-hero-art"><img src="' . esc_url( $base . '/' . $dir . '/' . $d['image'] ) . '" alt=""></div>';
		}
		echo '<div class="podpub-hero-main">' . self::pill( $pill[0], $pill[1] ); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( '' !== $dir && '' !== $base ) {
			echo '<div class="podpub-feedline"><code>' . esc_html( $tgt['url'] ) . '</code>';
			if ( is_file( $tgt['file'] ) ) {
				echo ' <a class="button button-small" href="' . esc_url( $tgt['url'] ) . '" target="_blank" rel="noopener">Open feed</a>';
			}
			echo ' <button type="button" class="button button-small" data-podpub-copy="' . esc_attr( $tgt['url'] ) . '">Copy address</button></div>';
		}
		echo '<div class="podpub-meta">' . (int) count( $eps ) . ( 1 === count( $eps ) ? ' episode' : ' episodes' ) . ' &middot; ' . (int) $pub . ' published</div>';
		echo '</div></div>';

		$f = function ( $name, $value ) {
			return 'name="podpub[' . esc_attr( $name ) . ']" value="' . esc_attr( $value ) . '"';
		};
		$tabs = array();

		// Episodes.
		ob_start();
		self::render_episode_list( $id );
		$live_in = is_array( $notice ) && isset( $notice['form'] ) && 'live' === $notice['form'] && ! empty( $notice['input'] ) ? $notice['input'] : null;
		self::render_live_form( $id, $d, $live_in );
		$tabs['episodes'] = array( 'label' => 'Episodes' . ( $eps ? ' (' . count( $eps ) . ')' : '' ), 'html' => ob_get_clean() );

		// Details.
		ob_start();
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="podpub_save_details"><input type="hidden" name="podpub_id" value="' . (int) $id . '">';
		wp_nonce_field( 'podpub_save_details' );
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="pp_title">Title</label></th><td><input type="text" id="pp_title" class="regular-text" required ' . $f( 'title', $feed->post_title ) . '></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th scope="row"><label for="pp_desc">Description</label></th><td><textarea id="pp_desc" name="podpub[description]" rows="5" class="large-text" required>' . esc_textarea( $d['description'] ) . '</textarea></td></tr>';
		echo '<tr><th scope="row"><label for="pp_lang">Language</label></th><td><select id="pp_lang" name="podpub[language]">';
		foreach ( PodPub_Details::languages() as $code => $label ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $d['language'], $code, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="pp_cat">Category</label></th><td><select id="pp_cat" name="podpub[category]"><option value="">None</option>';
		foreach ( PodPub_Details::categories() as $c ) {
			echo '<option value="' . esc_attr( $c ) . '"' . selected( $d['category'], $c, false ) . '>' . esc_html( $c ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="pp_explicit">This feed contains explicit content</label></th><td><select id="pp_explicit" name="podpub[explicit]" required><option value=""' . selected( $d['explicit'], '', false ) . '>Choose&hellip;</option><option value="yes"' . selected( $d['explicit'], 'yes', false ) . '>Yes</option><option value="no"' . selected( $d['explicit'], 'no', false ) . '>No</option></select><p class="description">Required. Podcast apps and directories use this.</p></td></tr>';
		echo '<tr><th scope="row"><label for="pp_type">Type</label></th><td><select id="pp_type" name="podpub[type]"><option value="episodic"' . selected( $d['type'], 'episodic', false ) . '>Episodic (newest first)</option><option value="serial"' . selected( $d['type'], 'serial', false ) . '>Serial (in order)</option></select></td></tr>';
		echo '<tr><th scope="row"><label for="pp_author">Author</label></th><td><input type="text" id="pp_author" class="regular-text" ' . $f( 'author', $d['author'] ) . '><p class="description">Shown to listeners in podcast apps.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th scope="row"><label for="pp_link">Website</label></th><td><input type="url" id="pp_link" class="regular-text" placeholder="https://" ' . $f( 'link', $d['link'] ) . '></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th scope="row"><label for="pp_copy">Copyright</label></th><td><input type="text" id="pp_copy" class="regular-text" ' . $f( 'copyright', $d['copyright'] ) . '></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th scope="row"><label for="pp_on">Owner name</label></th><td><input type="text" id="pp_on" class="regular-text" ' . $f( 'owner_name', $d['owner_name'] ) . '><p class="description">A contact for podcast directories. Not shown to listeners. Also written as the managing editor of the feed (email and name).</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th scope="row"><label for="pp_oe">Owner email</label></th><td><input type="email" id="pp_oe" class="regular-text" ' . $f( 'owner_email', $d['owner_email'] ) . '></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th scope="row"><label for="pp_kw">Keywords</label></th><td><input type="text" id="pp_kw" class="regular-text" ' . $f( 'keywords', $d['keywords'] ) . '><p class="description">Optional, separated by commas.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th scope="row">Download statistics</th><td><label><input type="checkbox" name="podpub[op3]" value="1"' . checked( ! empty( $d['op3'] ), true, false ) . '> Count downloads with OP3 (op3.dev)</label><p class="description">OP3 is a free, open download counter. When this is ticked the audio addresses in this podcast&rsquo;s feed start with <code>https://op3.dev/e/</code>, so a listener&rsquo;s app contacts OP3 first and is then sent on to your file. OP3 needs no account, hashes listeners&rsquo; IP addresses and does not store them. The addresses you enter for your episodes are not changed, so you can untick this at any time. It only counts audio that can be reached on the internet.';
		if ( ! empty( $d['op3'] ) && '' !== $d['guid'] ) {
			echo ' <a href="' . esc_url( 'https://op3.dev/show/' . $d['guid'] ) . '" target="_blank" rel="noopener">View this podcast&rsquo;s statistics</a> (they appear after a few days of downloads) &middot; <a href="https://op3.dev/setup" target="_blank" rel="noopener">Check my feed with OP3</a>';
		}
		echo '</p></td></tr>';
		echo '<tr><th scope="row"><label for="pp_img">Show art</label></th><td>';
		if ( '' !== $d['image'] && '' !== $base && '' !== $dir ) {
			echo '<p><img src="' . esc_url( $base . '/' . $dir . '/' . $d['image'] ) . '" alt="" style="max-width:150px;height:auto"></p>';
		}
		echo '<input type="file" id="pp_img" name="podpub_image" accept="image/jpeg,image/png,image/gif"><p class="description">JPEG, PNG or GIF, square, between 1400 x 1400 and 3000 x 3000 pixels, up to 10 MB. Saved in the podcast folder as cover.jpg, cover.png or cover.gif; smaller copies (300, 600, 1000 and 1500 pixels wide) are made automatically for apps to use (not for a GIF). Some directories (such as Apple Podcasts and Spotify) only take JPEG or PNG.</p></td></tr>';
		echo '</table>';
		self::render_savebar( 'Save and write feed', 'Saves the details and writes the feed file (the old file is kept as a backup).' );
		echo '</form>';
		$tabs['details'] = array( 'label' => 'Details', 'html' => ob_get_clean() );

		// Podcasting 2.0.
		$p20_in = is_array( $notice ) && isset( $notice['form'] ) && 'p20' === $notice['form'] && ! empty( $notice['input'] ) ? $notice['input'] : null;
		ob_start();
		echo '<p class="description" style="margin-top:14px">Optional extras for apps that support Podcasting 2.0. Sections that already hold something are open.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="podpub_save_p20"><input type="hidden" name="podpub_id" value="' . (int) $id . '">';
		wp_nonce_field( 'podpub_save_p20' );
		self::render_p20_fields( 'podpub_p20', $d, $p20_in, false );
		self::render_savebar( 'Save and write feed', 'Saves these settings and writes the feed file.' );
		echo '</form>';
		$tabs['p20'] = array( 'label' => 'Podcasting 2.0', 'html' => ob_get_clean() );

		// WordPress posts and page.
		ob_start();
		self::render_posts_tab( $id );
		$tabs['posts'] = array( 'label' => 'WordPress posts', 'html' => ob_get_clean() );

		// Feed and files, and deleting.
		ob_start();
		echo '<h2>Feed and files</h2><table class="form-table" role="presentation">';
		if ( '' !== $dir ) {
			echo '<tr><th scope="row">Files folder</th><td><code>' . esc_html( PodPub_Storage::base_path() . '/' . $dir ) . '</code></td></tr>';
			if ( '' !== $base ) {
				echo '<tr><th scope="row">Feed address</th><td><code>' . esc_html( $tgt['url'] ) . '</code>' . ( is_file( $tgt['file'] ) ? ' <a href="' . esc_url( $tgt['url'] ) . '" target="_blank" rel="noopener">Open feed</a>' : ' (not written yet: use Save and write feed)' ) . '</td></tr>';
			}
			echo '<tr><th scope="row">Feed file</th><td><code>' . esc_html( $tgt['file'] ) . '</code><p class="description">Each Save and write feed keeps the three most recent older copies next to it as <code>.bak</code> (newest), <code>.bak2</code> and <code>.bak3</code>.</p></td></tr>';
		}
		echo '</table>';
		echo '<div class="podpub-danger">';
		self::render_delete_podcast( $id, $feed );
		echo '</div>';
		$tabs['feed'] = array( 'label' => 'Feed and files', 'html' => ob_get_clean() );

		if ( is_array( $notice ) && isset( $notice['form'] ) && 'live' === $notice['form'] ) {
			$default = 'episodes';
		} elseif ( is_array( $notice ) && isset( $notice['form'] ) && 'p20' === $notice['form'] && ! empty( $notice['errors'] ) ) {
			$default = 'p20';
		} elseif ( is_array( $notice ) && isset( $notice['form'] ) && 'posts' === $notice['form'] ) {
			$default = 'posts';
		} elseif ( is_array( $notice ) && ! empty( $notice['errors'] ) ) {
			$default = 'details';
		} elseif ( isset( $_GET['tab'] ) && isset( $tabs[ sanitize_key( wp_unslash( $_GET['tab'] ) ) ] ) ) {
			$default = sanitize_key( wp_unslash( $_GET['tab'] ) );
		} else {
			$default = '' === trim( $d['description'] ) ? 'details' : 'episodes';
		}
		self::render_tabs( $tabs, $default );
		echo '<p style="margin-top:16px"><a href="' . esc_url( self::list_url() ) . '">&larr; All podcasts</a></p></div>';
	}

	public static function render_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$checked = PodPub_Settings::get( 'delete_data_on_uninstall' ) ? ' checked' : '';
		echo '<div class="wrap podpub"><h1>PodPublisher settings</h1>';
		self::assets();
		settings_errors( 'podpub_settings' );
		echo '<div class="podpub-box"><form method="post" action="options.php">';
		settings_fields( 'podpub_settings_group' );
		$opt = esc_attr( PodPub_Settings::OPTION );
		echo '<table class="form-table">';
		echo '<tr><th scope="row"><label for="podpub_storage_path">Podcast folder</label></th><td>';
		echo '<input type="text" id="podpub_storage_path" class="large-text code" name="' . $opt . '[storage_path]" value="' . esc_attr( (string) PodPub_Settings::get( 'storage_path' ) ) . '" placeholder="' . esc_attr( PodPub_Storage::default_path() ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="description">Where podcast files are written. Leave empty for the default shown. Each podcast gets its own folder here, with a folder for each episode inside it. Folder names are made from titles with spaces replaced by _ and anything else unsafe removed.</p></td></tr>';
		echo '<tr><th scope="row"><label for="podpub_storage_url">Public address</label></th><td>';
		echo '<input type="text" id="podpub_storage_url" class="large-text code" name="' . $opt . '[storage_url]" value="' . esc_attr( (string) PodPub_Settings::get( 'storage_url' ) ) . '" placeholder="' . esc_attr( PodPub_Storage::derive_url( PodPub_Storage::base_path() ) ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="description">The web address that points to the folder above. Only needed when the folder is outside the WordPress uploads folder.</p></td></tr>';
		echo '<tr><th scope="row">Location search</th><td><label><input type="checkbox" name="' . $opt . '[geo_enabled]" value="1"' . checked( PodPub_Geo::enabled(), true, false ) . '> Show a place search on the Location rows</label>';
		echo '<p><label for="podpub_geo_url">Search service address</label><br><input type="text" id="podpub_geo_url" class="large-text code" name="' . $opt . '[geo_url]" value="' . esc_attr( (string) PodPub_Settings::get( 'geo_url' ) ) . '" placeholder="' . esc_attr( PodPub_Geo::DEFAULT_URL ) . '"></p>';
		echo '<p class="description">Leave the address empty to use the public OpenStreetMap Nominatim service. Its usage policy allows one request per second for the whole site, no search-as-you-type and an identifying User-Agent, so PodPublisher searches only when you press Search, waits a second between searches, keeps results for 30 days and sends <code>PodPublisher/version (your site address)</code> as its User-Agent. Heavy use should go to your own Nominatim or a compatible service: enter its https:// address here. Results are &copy; OpenStreetMap contributors.</p></td></tr>';
		echo '<tr><th scope="row">Uninstall</th><td><label>';
		echo '<input type="checkbox" name="' . esc_attr( PodPub_Settings::OPTION ) . '[delete_data_on_uninstall]" value="1"' . $checked . '> '; // phpcs:ignore WordPress.Security.EscapeOutput
		echo 'Delete podcasts and settings when the plugin is deleted</label></td></tr></table>';
		submit_button();
		echo '</form></div></div>';
	}
}
