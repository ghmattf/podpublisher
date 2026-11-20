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

		return array(
			'delete_data_on_uninstall' => empty( $input['delete_data_on_uninstall'] ) ? 0 : 1,
			'storage_path'             => $path,
			'storage_url'              => $url,
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
	 * Main page: list of podcasts, New podcast, Import podcast.
	 */
	public static function render_list() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		echo '<div class="wrap"><h1 class="wp-heading-inline">PodPublisher</h1>';
		echo ' <a href="' . esc_url( self::list_url( array( 'action' => 'new' ) ) ) . '" class="page-title-action">New podcast</a>';
		echo ' <a href="' . esc_url( self::list_url( array( 'action' => 'import' ) ) ) . '" class="page-title-action">Import podcast</a>';
		echo '<hr class="wp-header-end">';

		if ( 'new' === $action ) {
			self::render_new();
		} elseif ( 'import' === $action ) {
			self::render_import();
		} else {
			self::render_table();
		}
		echo '</div>';
	}

	private static function render_table() {
		$feeds = PodPub_Feeds::all();
		if ( ! $feeds ) {
			echo '<p>No podcasts yet. Use <strong>New podcast</strong> to start one, or <strong>Import podcast</strong> to bring in an existing one.</p>';
			return;
		}
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Podcast</th></tr></thead><tbody>';
		foreach ( $feeds as $feed ) {
			$url = add_query_arg( 'page', self::SLUG_FEED . (int) $feed->ID, admin_url( 'admin.php' ) );
			echo '<tr><td><strong><a href="' . esc_url( $url ) . '">' . esc_html( $feed->post_title ) . '</a></strong></td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_new() {
		echo '<h2>New podcast</h2>';
		if ( isset( $_GET['podpub_error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['podpub_error'] ) ) ) ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="podpub_create_feed">';
		wp_nonce_field( 'podpub_create_feed' );
		echo '<table class="form-table"><tr><th scope="row"><label for="podpub_title">Title</label></th>';
		echo '<td><input type="text" id="podpub_title" name="podpub_title" class="regular-text" required></td></tr></table>';
		submit_button( 'Create podcast' );
		echo '</form>';
	}

	private static function render_import() {
		echo '<h2>Import podcast</h2>';
		echo '<p>Importing an existing podcast is not built yet.</p>';
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
		echo '<div class="wrap">';
		if ( ! $feed ) {
			echo '<h1>Podcast not found</h1><p><a href="' . esc_url( self::list_url() ) . '">Back to all podcasts</a></p></div>';
			return;
		}
		echo '<h1>' . esc_html( $feed->post_title ) . '</h1>';
		$dir = PodPub_Feeds::dir_name( $id );
		if ( '' !== $dir ) {
			echo '<p>Files folder: <code>' . esc_html( PodPub_Storage::base_path() . '/' . $dir ) . '</code></p>';
			$url = PodPub_Storage::base_url();
			if ( '' !== $url ) {
				echo '<p>Public address: <code>' . esc_html( $url . '/' . $dir . '/' ) . '</code></p>';
			}
		}
		echo '<p>Managing this podcast is not built yet.</p>';
		echo '<p><a href="' . esc_url( self::list_url() ) . '">Back to all podcasts</a></p></div>';
	}

	public static function render_settings() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$checked = PodPub_Settings::get( 'delete_data_on_uninstall' ) ? ' checked' : '';
		echo '<div class="wrap"><h1>PodPublisher settings</h1>';
		settings_errors( 'podpub_settings' );
		echo '<form method="post" action="options.php">';
		settings_fields( 'podpub_settings_group' );
		$opt = esc_attr( PodPub_Settings::OPTION );
		echo '<table class="form-table">';
		echo '<tr><th scope="row"><label for="podpub_storage_path">Podcast folder</label></th><td>';
		echo '<input type="text" id="podpub_storage_path" class="large-text code" name="' . $opt . '[storage_path]" value="' . esc_attr( (string) PodPub_Settings::get( 'storage_path' ) ) . '" placeholder="' . esc_attr( PodPub_Storage::default_path() ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="description">Where podcast files are written. Leave empty for the default shown. Each podcast gets its own folder here, with a folder for each episode inside it. Folder names are made from titles with spaces replaced by _ and anything else unsafe removed.</p></td></tr>';
		echo '<tr><th scope="row"><label for="podpub_storage_url">Public address</label></th><td>';
		echo '<input type="text" id="podpub_storage_url" class="large-text code" name="' . $opt . '[storage_url]" value="' . esc_attr( (string) PodPub_Settings::get( 'storage_url' ) ) . '" placeholder="' . esc_attr( PodPub_Storage::derive_url( PodPub_Storage::base_path() ) ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="description">The web address that points to the folder above. Only needed when the folder is outside the WordPress uploads folder.</p></td></tr>';
		echo '<tr><th scope="row">Uninstall</th><td><label>';
		echo '<input type="checkbox" name="' . esc_attr( PodPub_Settings::OPTION ) . '[delete_data_on_uninstall]" value="1"' . $checked . '> '; // phpcs:ignore WordPress.Security.EscapeOutput
		echo 'Delete podcasts and settings when the plugin is deleted</label></td></tr></table>';
		submit_button();
		echo '</form></div>';
	}
}
