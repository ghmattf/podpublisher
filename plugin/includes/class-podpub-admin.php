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
		wp_safe_redirect( add_query_arg( 'page', self::SLUG_FEED . $id, admin_url( 'admin.php' ) ) );
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
			} else {
				echo '<div class="notice notice-success"><p>' . ( ! empty( $notice['written'] ) ? 'Saved. The feed file was written.' : 'Saved.' ) . '</p></div>';
			}
			foreach ( ! empty( $notice['warnings'] ) ? $notice['warnings'] : array() as $m ) {
				echo '<div class="notice notice-warning"><p>' . esc_html( $m ) . '</p></div>';
			}
		}

		$dir  = PodPub_Feeds::dir_name( $id );
		$d    = PodPub_Details::get( $id );
		$base = PodPub_Storage::base_url();
		if ( '' !== $dir ) {
			echo '<p>Files folder: <code>' . esc_html( PodPub_Storage::base_path() . '/' . $dir ) . '</code></p>';
			if ( '' !== $base ) {
				$feed_url = $base . '/' . $dir . '/' . $dir . '.xml';
				echo '<p>Feed address: <code>' . esc_html( $feed_url ) . '</code>';
				if ( is_file( PodPub_Storage::base_path() . '/' . $dir . '/' . $dir . '.xml' ) ) {
					echo ' <a href="' . esc_url( $feed_url ) . '" target="_blank" rel="noopener">Open feed</a>';
				} else {
					echo ' (not written yet: save the details below)';
				}
				echo '</p>';
			}
		}

		$f = function ( $name, $value ) {
			return 'name="podpub[' . esc_attr( $name ) . ']" value="' . esc_attr( $value ) . '"';
		};
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="podpub_save_details"><input type="hidden" name="podpub_id" value="' . (int) $id . '">';
		wp_nonce_field( 'podpub_save_details' );
		echo '<h2>Podcast details</h2><table class="form-table" role="presentation">';
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
		echo '<tr><th scope="row">Explicit</th><td><label><input type="checkbox" name="podpub[explicit]" value="1"' . checked( ! empty( $d['explicit'] ), true, false ) . '> This podcast contains explicit content</label></td></tr>';
		echo '<tr><th scope="row"><label for="pp_type">Type</label></th><td><select id="pp_type" name="podpub[type]"><option value="episodic"' . selected( $d['type'], 'episodic', false ) . '>Episodic (newest first)</option><option value="serial"' . selected( $d['type'], 'serial', false ) . '>Serial (in order)</option></select></td></tr>';
		echo '<tr><th scope="row"><label for="pp_author">Author</label></th><td><input type="text" id="pp_author" class="regular-text" ' . $f( 'author', $d['author'] ) . '><p class="description">Shown to listeners in podcast apps.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th scope="row"><label for="pp_link">Website</label></th><td><input type="url" id="pp_link" class="regular-text" placeholder="https://" ' . $f( 'link', $d['link'] ) . '></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th scope="row"><label for="pp_copy">Copyright</label></th><td><input type="text" id="pp_copy" class="regular-text" ' . $f( 'copyright', $d['copyright'] ) . '></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th scope="row"><label for="pp_on">Owner name</label></th><td><input type="text" id="pp_on" class="regular-text" ' . $f( 'owner_name', $d['owner_name'] ) . '><p class="description">A contact for podcast directories. Not shown to listeners.</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th scope="row"><label for="pp_oe">Owner email</label></th><td><input type="email" id="pp_oe" class="regular-text" ' . $f( 'owner_email', $d['owner_email'] ) . '></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th scope="row"><label for="pp_img">Show art</label></th><td>';
		if ( '' !== $d['image'] && '' !== $base && '' !== $dir ) {
			echo '<p><img src="' . esc_url( $base . '/' . $dir . '/' . $d['image'] ) . '" alt="" style="max-width:150px;height:auto"></p>';
		}
		echo '<input type="file" id="pp_img" name="podpub_image" accept="image/jpeg,image/png,image/gif"><p class="description">JPEG, PNG or GIF, square, between 1400 x 1400 and 3000 x 3000 pixels, up to 10 MB. Saved in the podcast folder as cover.jpg, cover.png or cover.gif. Some directories (such as Apple Podcasts and Spotify) only take JPEG or PNG.</p></td></tr>';
		echo '</table>';
		submit_button( 'Save and write feed' );
		echo '</form>';
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
