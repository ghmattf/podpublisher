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
		add_action( 'admin_post_podpub_delete_episode', array( __CLASS__, 'handle_delete_episode' ) );
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
		$result          = PodPub_Episodes::save( $pid, $eid, $input, $file );
		$result['input'] = $result['errors'] ? $input : null;
		set_transient( 'podpub_notice_' . get_current_user_id(), $result, 120 );
		$go = $result['errors'] ? ( $eid ? (string) $eid : 'new' ) : (string) $result['id'];
		wp_safe_redirect( self::feed_page_url( $pid, array( 'episode' => $go ) ) );
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
		$result = PodPub_Episodes::delete( $pid, $eid );
		set_transient( 'podpub_notice_' . get_current_user_id(), $result, 120 );
		wp_safe_redirect( self::feed_page_url( $pid ) );
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
		wp_safe_redirect( self::feed_page_url( $id ) );
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
	private static function render_rows( $name, $rows, $cols, $max ) {
		$rows = array_values( $rows );
		$n    = min( $max, count( $rows ) + 3 );
		echo '<table class="widefat striped" style="max-width:70em"><thead><tr>';
		foreach ( $cols as $c ) {
			echo '<th>' . esc_html( $c['label'] ) . '</th>';
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
				} elseif ( 'check' === $c['type'] ) {
					echo '<input type="checkbox" name="' . $nm . '" value="1"' . checked( ! empty( $val ), true, false ) . '>';
				} else {
					echo '<input type="text" class="' . esc_attr( isset( $c['class'] ) ? $c['class'] : 'regular-text' ) . '" name="' . $nm . '" value="' . esc_attr( is_scalar( $val ) ? (string) $val : '' ) . '"' . ( isset( $c['ph'] ) ? ' placeholder="' . esc_attr( $c['ph'] ) . '"' : '' ) . '>';
				}
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table><p class="description">Empty rows are ignored. Save to get more empty rows.</p>';
	}

	/**
	 * The Podcasting 2.0 sections, for a podcast (channel) or an episode (item).
	 *
	 * @param string     $prefix Field name prefix: podpub_p20 or podpub_ep.
	 * @param array      $d      Saved details.
	 * @param array|null $in     What was typed before an error (refills the form), or null.
	 * @param bool       $item   True for an episode.
	 */
	private static function render_p20_fields( $prefix, $d, $in, $item ) {
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

		echo '<h3>Funding</h3><p class="description">Links where listeners can support the show.' . esc_html( $inherit ) . '</p>';
		self::render_rows( $prefix . '[funding]', $rows( 'funding', $d['funding'] ), array(
			array( 'key' => 'url', 'label' => 'Web address', 'type' => 'text', 'ph' => 'https://' ),
			array( 'key' => 'message', 'label' => 'Message (up to 128 characters)', 'type' => 'text' ),
		), PodPub_P20::MAX_FUNDING );

		echo '<h3>Location</h3><p class="description">Where the ' . ( $item ? 'episode' : 'podcast' ) . ' is about (subject) or was made (creator). Add one row for each place. The name is required; the rest is recommended.' . esc_html( $inherit ) . '</p>';
		self::render_rows( $prefix . '[locations]', $rows( 'locations', $d['locations'] ), array(
			array( 'key' => 'name', 'label' => 'Place name', 'type' => 'text', 'ph' => 'Melbourne' ),
			array( 'key' => 'rel', 'label' => 'Place is', 'type' => 'select', 'options' => array( '' => 'The subject (default)', 'creator' => 'Where it was made' ) ),
			array( 'key' => 'geo', 'label' => 'Coordinates', 'type' => 'text', 'ph' => '-37.8136,144.9631', 'class' => 'regular-text code' ),
			array( 'key' => 'osm', 'label' => 'OpenStreetMap id', 'type' => 'text', 'ph' => 'R2316741', 'class' => 'small-text code' ),
			array( 'key' => 'country', 'label' => 'Country', 'type' => 'text', 'ph' => 'AU', 'class' => 'small-text' ),
		), PodPub_P20::MAX_LOCATIONS );

		echo '<h3>People</h3><p class="description">Hosts, guests and others, shown by podcast apps.' . esc_html( $inherit ) . ' The role and group follow the Podcast namespace people taxonomy (for example host or guest, in the group cast); other words are kept but may not be recognised by apps.</p>';
		self::render_rows( $prefix . '[persons]', $rows( 'persons', $d['persons'] ), array(
			array( 'key' => 'name', 'label' => 'Name', 'type' => 'text' ),
			array( 'key' => 'role', 'label' => 'Role', 'type' => 'text', 'ph' => 'host', 'class' => 'small-text' ),
			array( 'key' => 'group', 'label' => 'Group', 'type' => 'text', 'ph' => 'cast', 'class' => 'small-text' ),
			array( 'key' => 'img', 'label' => 'Picture address', 'type' => 'text', 'ph' => 'https://' ),
			array( 'key' => 'href', 'label' => 'Link', 'type' => 'text', 'ph' => 'https://' ),
		), PodPub_P20::MAX_PERSONS );

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
			), PodPub_P20::MAX_SPLITS );
		} else {
			echo '<h3>Block</h3><p class="description">Ask podcast apps not to list this podcast. Choose yes to block, no to say it is not blocked. To block or allow just one service, enter its id.</p>';
			self::render_rows( $prefix . '[block]', $rows( 'block', $d['block'] ), array(
				array( 'key' => 'id', 'label' => 'Service id (optional)', 'type' => 'text', 'class' => 'regular-text' ),
				array( 'key' => 'value', 'label' => 'Block', 'type' => 'select', 'options' => array( '' => '', 'yes' => 'Yes', 'no' => 'No' ) ),
			), PodPub_P20::MAX_BLOCK );

			echo '<h3>Podroll</h3><p class="description">Other podcasts you recommend. The feed GUID is the podcast:guid in the other podcast\'s feed.</p>';
			self::render_rows( $prefix . '[podroll]', $rows( 'podroll', $d['podroll'] ), array(
				array( 'key' => 'feed_guid', 'label' => 'Feed GUID', 'type' => 'text', 'class' => 'regular-text code' ),
				array( 'key' => 'feed_url', 'label' => 'Feed address (optional)', 'type' => 'text', 'ph' => 'https://' ),
				array( 'key' => 'title', 'label' => 'Title (optional)', 'type' => 'text' ),
			), PodPub_P20::MAX_PODROLL );
		}

		if ( $item ) {
			echo '<h3>Chapters</h3><p class="description">Chapter marks that apps show on the timeline. Type them here and PodPublisher writes <code>chapters.json</code> into the episode folder and points the feed at it. Start is seconds, m:ss or h:mm:ss. The picture and link are optional.</p>';
			self::render_rows( $prefix . '[chapters]', $rows( 'chapters', $d['chapters'] ), array(
				array( 'key' => 'start', 'label' => 'Start', 'type' => 'text', 'class' => 'small-text' ),
				array( 'key' => 'title', 'label' => 'Title', 'type' => 'text' ),
				array( 'key' => 'img', 'label' => 'Picture address', 'type' => 'text', 'ph' => 'https://' ),
				array( 'key' => 'url', 'label' => 'Link', 'type' => 'text', 'ph' => 'https://' ),
			), PodPub_P20::MAX_CHAPTERS );
			echo '<table class="form-table" role="presentation">';
			$in_text( 'chapters_url', 'Or: a chapters file hosted elsewhere', $d['chapters_url'], 'https://', 'regular-text' );
			echo '</table><p class="description">Use the list above or this address, not both.</p>';

			echo '<h3>Transcripts</h3><p class="description">Links to transcript or caption files (hosted elsewhere or copied into the episode folder). Language is optional (the podcast\'s language is assumed). Put captions in Rel if the file has timecodes and is meant as closed captions.</p>';
			self::render_rows( $prefix . '[transcripts]', $rows( 'transcripts', $d['transcripts'] ), array(
				array( 'key' => 'url', 'label' => 'Web address', 'type' => 'text', 'ph' => 'https://' ),
				array( 'key' => 'type', 'label' => 'File type', 'type' => 'select', 'options' => array( '' => '' ) + PodPub_P20::transcript_types() ),
				array( 'key' => 'language', 'label' => 'Language', 'type' => 'text', 'ph' => 'en', 'class' => 'small-text' ),
				array( 'key' => 'rel', 'label' => 'Rel', 'type' => 'text', 'ph' => 'captions', 'class' => 'small-text' ),
			), PodPub_P20::MAX_TRANSCRIPTS );

			echo '<h3>Soundbites</h3><p class="description">Short highlights of the episode (15 to 120 seconds works best). Start and length are in seconds, for example 73 and 60; the title is optional.</p>';
			self::render_rows( $prefix . '[soundbites]', $rows( 'soundbites', $d['soundbites'] ), array(
				array( 'key' => 'start', 'label' => 'Start (seconds)', 'type' => 'text', 'class' => 'small-text' ),
				array( 'key' => 'duration', 'label' => 'Length (seconds)', 'type' => 'text', 'class' => 'small-text' ),
				array( 'key' => 'title', 'label' => 'Title (optional)', 'type' => 'text' ),
			), PodPub_P20::MAX_SOUNDBITES );

			echo '<h3>Alternate files</h3><p class="description">Other versions of the episode (for example a video or a different quality), hosted elsewhere. Tick Default if it is the same as the main audio and preferred. Size, bitrate and height are whole numbers; the title is best at 32 characters or fewer.</p>';
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
			), PodPub_P20::MAX_ENCLOSURES );
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

			echo '<h3>Text records</h3><p class="description">Free text for services that ask for it (for example a code that proves you own the feed). Purpose is optional, such as verify.</p>';
			self::render_rows( $prefix . '[txt]', $rows( 'txt', $d['txt'] ), array(
				array( 'key' => 'purpose', 'label' => 'Purpose', 'type' => 'text', 'class' => 'small-text' ),
				array( 'key' => 'text', 'label' => 'Text (up to 4000 characters)', 'type' => 'text', 'class' => 'large-text' ),
			), PodPub_P20::MAX_TXT );
		}

		echo '<h3>Comments (cross-app)</h3><p class="description">A social post whose replies apps can show as comments on this ' . ( $item ? 'episode' : 'podcast' ) . '. Protocol is for example activitypub; the address is the post\'s address. To say you do not want comments shown, enter the protocol disabled and nothing else.' . esc_html( $inherit ) . '</p>';
		self::render_rows( $prefix . '[social]', $rows( 'social', $d['social'] ), array(
			array( 'key' => 'protocol', 'label' => 'Protocol', 'type' => 'text', 'ph' => 'activitypub', 'class' => 'small-text' ),
			array( 'key' => 'uri', 'label' => 'Address of the post', 'type' => 'text', 'ph' => 'https://' ),
			array( 'key' => 'account_id', 'label' => 'Account id', 'type' => 'text', 'class' => 'small-text' ),
			array( 'key' => 'account_url', 'label' => 'Account address', 'type' => 'text', 'ph' => 'https://' ),
			array( 'key' => 'priority', 'label' => 'Priority', 'type' => 'text', 'class' => 'small-text' ),
		), PodPub_P20::MAX_SOCIAL );
	}

	/**
	 * Episode list under the details form.
	 *
	 * @param int $id Podcast record id.
	 */
	private static function render_episode_list( $id ) {
		echo '<hr><h2>Episodes <a href="' . esc_url( self::feed_page_url( $id, array( 'episode' => 'new' ) ) ) . '" class="page-title-action">Add episode</a></h2>';
		$eps = PodPub_Episodes::all( $id );
		if ( ! $eps ) {
			echo '<p>No episodes yet.</p>';
			return;
		}
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Episode</th><th style="width:12em">Date</th><th style="width:8em">Status</th></tr></thead><tbody>';
		foreach ( $eps as $e ) {
			$d    = PodPub_Episodes::details( $e->ID );
			$link = self::feed_page_url( $id, array( 'episode' => (int) $e->ID ) );
			$stat = 'published' === $d['status'] ? ( ! PodPub_Episodes::has_audio( $d ) ? 'Published (no audio)' : 'Published' ) : 'Draft';
			echo '<tr><td><strong><a href="' . esc_url( $link ) . '">' . esc_html( $e->post_title ) . '</a></strong></td><td>' . esc_html( PodPub_Episodes::format_date( $d['published'] ) ) . '</td><td>' . esc_html( $stat ) . '</td></tr>';
		}
		echo '</tbody></table>';
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
		echo '<p><a href="' . esc_url( self::feed_page_url( $id ) ) . '">&larr; Back to ' . esc_html( $feed->post_title ) . '</a></p>';
		if ( $eid && ! $post ) {
			echo '<h2>Episode not found</h2>';
			return;
		}
		$d     = $post ? PodPub_Episodes::details( $eid ) : array_merge( PodPub_Episodes::defaults(), array( 'published' => time() ) );
		$title = $post ? $post->post_title : '';
		$in    = is_array( $notice ) && ! empty( $notice['input'] ) ? $notice['input'] : null;
		$v     = function ( $k, $fallback ) use ( $in ) {
			return null !== $in && isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) ? (string) $in[ $k ] : (string) $fallback;
		};
		echo '<h2>' . ( $post ? 'Edit episode' : 'Add episode' ) . '</h2>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="podpub_save_episode"><input type="hidden" name="podpub_id" value="' . (int) $id . '"><input type="hidden" name="podpub_episode_id" value="' . (int) $eid . '">';
		wp_nonce_field( 'podpub_save_episode' );
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="ep_title">Title</label></th><td><input type="text" id="ep_title" class="regular-text" required name="podpub_ep[title]" value="' . esc_attr( $v( 'title', $title ) ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="ep_desc">Description</label></th><td><textarea id="ep_desc" name="podpub_ep[description]" rows="3" class="large-text" required>' . esc_textarea( $v( 'description', $d['description'] ) ) . '</textarea><p class="description">Plain text summary shown in podcast apps.</p></td></tr>';
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
		echo '</table>';
		echo '<hr><h2>Podcasting 2.0</h2>';
		self::render_p20_fields( 'podpub_ep', $d, $in, true );
		submit_button( 'Save episode and write feed' );
		echo '</form>';
		if ( $post ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Delete this episode and its audio file? This cannot be undone.\');">';
			echo '<input type="hidden" name="action" value="podpub_delete_episode"><input type="hidden" name="podpub_id" value="' . (int) $id . '"><input type="hidden" name="podpub_episode_id" value="' . (int) $eid . '">';
			wp_nonce_field( 'podpub_delete_episode' );
			submit_button( 'Delete episode', 'delete', 'submit', false );
			echo '</form>';
		}
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

		$which = isset( $_GET['episode'] ) ? sanitize_text_field( wp_unslash( $_GET['episode'] ) ) : '';
		if ( '' !== $which && ( 'new' === $which || ctype_digit( $which ) ) ) {
			self::render_episode( $id, $feed, $which, is_array( $notice ) ? $notice : null );
			echo '</div>';
			return;
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
		$p20_in = is_array( $notice ) && isset( $notice['form'] ) && 'p20' === $notice['form'] && ! empty( $notice['input'] ) ? $notice['input'] : null;
		echo '<hr><h2>Podcasting 2.0</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="podpub_save_p20"><input type="hidden" name="podpub_id" value="' . (int) $id . '">';
		wp_nonce_field( 'podpub_save_p20' );
		self::render_p20_fields( 'podpub_p20', $d, $p20_in, false );
		submit_button( 'Save and write feed' );
		echo '</form>';
		self::render_episode_list( $id );
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
