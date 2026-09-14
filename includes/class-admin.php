<?php
/**
 * Admin class for WP Puller Plugin.
 *
 * @package WP_Puller_Plugin
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPP_Plugin_Admin Class.
 */
class WPP_Plugin_Admin {

	/** @var WPP_Plugin_GitHub_API */
	private $github_api;

	/** @var WPP_Plugin_Updater */
	private $updater;

	/** @var WPP_Plugin_Backup */
	private $backup;

	/** @var WPP_Plugin_Logger */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param WPP_Plugin_GitHub_API $github_api GitHub API instance.
	 * @param WPP_Plugin_Updater    $updater    Updater instance.
	 * @param WPP_Plugin_Backup     $backup     Backup instance.
	 * @param WPP_Plugin_Logger     $logger     Logger instance.
	 */
	public function __construct( $github_api, $updater, $backup, $logger ) {
		$this->github_api = $github_api;
		$this->updater    = $updater;
		$this->backup     = $backup;
		$this->logger     = $logger;

		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_wpp_plugin_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_wpp_plugin_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_wpp_plugin_check_updates', array( $this, 'ajax_check_updates' ) );
		add_action( 'wp_ajax_wpp_plugin_update_now', array( $this, 'ajax_update_now' ) );
		add_action( 'wp_ajax_wpp_plugin_restore_backup', array( $this, 'ajax_restore_backup' ) );
		add_action( 'wp_ajax_wpp_plugin_delete_backup', array( $this, 'ajax_delete_backup' ) );
		add_action( 'wp_ajax_wpp_plugin_regenerate_secret', array( $this, 'ajax_regenerate_secret' ) );
		add_action( 'wp_ajax_wpp_plugin_clear_logs', array( $this, 'ajax_clear_logs' ) );
	}

	/**
	 * Add admin menu.
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'WP Puller Plugin', 'wp-puller-plugin' ),
			__( 'WP Puller Plugin', 'wp-puller-plugin' ),
			'manage_options',
			'wp-puller-plugin',
			array( $this, 'render_admin_page' ),
			'dashicons-update',
			81
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_wp-puller-plugin' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wpp-plugin-admin',
			WPP_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			WPP_PLUGIN_VERSION
		);

		wp_enqueue_script(
			'wpp-plugin-admin',
			WPP_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			WPP_PLUGIN_VERSION,
			true
		);

		wp_localize_script( 'wpp-plugin-admin', 'wppPlugin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'wpp_plugin_nonce' ),
			'strings' => array(
				'saving'            => __( 'Saving...', 'wp-puller-plugin' ),
				'saved'             => __( 'Settings saved!', 'wp-puller-plugin' ),
				'testing'           => __( 'Testing connection...', 'wp-puller-plugin' ),
				'connected'         => __( 'Connected successfully!', 'wp-puller-plugin' ),
				'checking'          => __( 'Checking for updates...', 'wp-puller-plugin' ),
				'updating'          => __( 'Updating plugin...', 'wp-puller-plugin' ),
				'updated'           => __( 'Plugin updated successfully!', 'wp-puller-plugin' ),
				'restoring'         => __( 'Restoring backup...', 'wp-puller-plugin' ),
				'restored'          => __( 'Backup restored successfully!', 'wp-puller-plugin' ),
				'deleting'          => __( 'Deleting backup...', 'wp-puller-plugin' ),
				'deleted'           => __( 'Backup deleted!', 'wp-puller-plugin' ),
				'regenerating'      => __( 'Regenerating secret...', 'wp-puller-plugin' ),
				'regenerated'       => __( 'Secret regenerated!', 'wp-puller-plugin' ),
				'error'             => __( 'An error occurred.', 'wp-puller-plugin' ),
				'confirmRestore'    => __( 'Are you sure you want to restore this backup? The current plugin files will be replaced.', 'wp-puller-plugin' ),
				'confirmDelete'     => __( 'Are you sure you want to delete this backup?', 'wp-puller-plugin' ),
				'confirmRegenerate' => __( 'Are you sure? You will need to update the secret in GitHub.', 'wp-puller-plugin' ),
			),
		) );
	}

	/**
	 * Render admin page.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-puller-plugin' ) );
		}

		$plugin_slug = get_option( 'wpp_plugin_slug', '' );

		$data = array(
			'status'           => $this->updater->get_status(),
			'plugin_info'      => $this->updater->get_target_plugin_info(),
			'installed_plugins' => self::get_installed_plugins(),
			'webhook_info'     => WPP_Plugin_Webhook_Handler::get_setup_instructions(),
			'backups'          => $this->backup->get_backups( $plugin_slug ),
			'logs'             => $this->logger->get_recent_logs( 10 ),
			'backup_class'     => $this->backup,
		);

		include WPP_PLUGIN_DIR . 'templates/admin-page.php';
	}

	/**
	 * Get all installed plugins as a flat list keyed by folder slug.
	 *
	 * Uses get_plugins() which returns every plugin regardless of whether it
	 * is active or not — because for our purposes the plugin just needs to
	 * exist on disk so we can update its files.
	 *
	 * @return array  Associative array: slug => plugin name.
	 *                e.g. [ 'woocommerce' => 'WooCommerce', ... ]
	 */
	public static function get_installed_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all     = get_plugins();
		$plugins = array();

		foreach ( $all as $plugin_file => $plugin_data ) {
			// $plugin_file is "folder/main-file.php" (or "main-file.php" for
			// single-file plugins at the plugins root).
			$parts = explode( '/', $plugin_file );
			$slug  = count( $parts ) > 1 ? $parts[0] : basename( $plugin_file, '.php' );

			// Skip WP Puller Plugin itself so it can't accidentally update itself.
			if ( $slug === dirname( WPP_PLUGIN_BASENAME ) ) {
				continue;
			}

			// Deduplicate: if a folder has multiple plugin files, we only need
			// one entry per slug.
			if ( ! isset( $plugins[ $slug ] ) ) {
				$plugins[ $slug ] = $plugin_data['Name'];
			}
		}

		// Sort by plugin name for a friendlier dropdown.
		asort( $plugins );

		return $plugins;
	}

	/**
	 * AJAX: Save settings.
	 */
	public function ajax_save_settings() {
		$this->verify_ajax_request();

		$repo_url    = isset( $_POST['repo_url'] ) ? esc_url_raw( wp_unslash( $_POST['repo_url'] ) ) : '';
		$branch      = isset( $_POST['branch'] ) ? sanitize_text_field( wp_unslash( $_POST['branch'] ) ) : 'main';
		$plugin_slug = isset( $_POST['plugin_slug'] ) ? sanitize_file_name( wp_unslash( $_POST['plugin_slug'] ) ) : '';
		$plugin_subdir = isset( $_POST['plugin_subdir'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_subdir'] ) ) : '';
		$pat         = isset( $_POST['pat'] ) ? sanitize_text_field( wp_unslash( $_POST['pat'] ) ) : '';
		$auto_update = isset( $_POST['auto_update'] ) && 'true' === $_POST['auto_update'];
		$backup_count = isset( $_POST['backup_count'] ) ? absint( $_POST['backup_count'] ) : 3;

		// Clean up subdir — no leading/trailing slashes.
		$plugin_subdir = trim( $plugin_subdir, '/' );

		// Reject path traversal attempts.
		if ( false !== strpos( $plugin_subdir, '..' ) ) {
			wp_send_json_error( array(
				'message' => __( 'Invalid plugin subdirectory path.', 'wp-puller-plugin' ),
			) );
		}

		if ( false !== strpos( $plugin_slug, '..' ) || false !== strpos( $plugin_slug, '/' ) ) {
			wp_send_json_error( array(
				'message' => __( 'Invalid plugin slug.', 'wp-puller-plugin' ),
			) );
		}

		update_option( 'wpp_plugin_repo_url', $repo_url );
		update_option( 'wpp_plugin_branch', $branch );
		update_option( 'wpp_plugin_slug', $plugin_slug );
		update_option( 'wpp_plugin_subdir', $plugin_subdir );
		update_option( 'wpp_plugin_auto_update', $auto_update );
		update_option( 'wpp_plugin_backup_count', max( 1, min( 10, $backup_count ) ) );

		if ( ! empty( $pat ) && '*****' !== substr( $pat, 0, 5 ) ) {
			update_option( 'wpp_plugin_pat', WPP_Plugin_Core::encrypt( $pat ) );
		}

		$this->github_api->clear_cache();

		$this->logger->log(
			__( 'Settings updated', 'wp-puller-plugin' ),
			WPP_Plugin_Logger::STATUS_INFO,
			WPP_Plugin_Logger::SOURCE_MANUAL
		);

		wp_send_json_success( array(
			'message' => __( 'Settings saved successfully.', 'wp-puller-plugin' ),
		) );
	}

	/**
	 * AJAX: Test connection.
	 */
	public function ajax_test_connection() {
		$this->verify_ajax_request();

		$repo_url = isset( $_POST['repo_url'] ) ? esc_url_raw( wp_unslash( $_POST['repo_url'] ) ) : '';

		if ( empty( $repo_url ) ) {
			wp_send_json_error( array(
				'message' => __( 'Please enter a repository URL.', 'wp-puller-plugin' ),
			) );
		}

		$result = $this->github_api->test_connection( $repo_url );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message' => __( 'Connection successful!', 'wp-puller-plugin' ),
			'repo'    => array(
				'name'           => isset( $result['name'] ) ? $result['name'] : '',
				'full_name'      => isset( $result['full_name'] ) ? $result['full_name'] : '',
				'description'    => isset( $result['description'] ) ? $result['description'] : '',
				'private'        => isset( $result['private'] ) ? $result['private'] : false,
				'default_branch' => isset( $result['default_branch'] ) ? $result['default_branch'] : 'main',
			),
		) );
	}

	/**
	 * AJAX: Check for updates.
	 */
	public function ajax_check_updates() {
		$this->verify_ajax_request();

		$result = $this->updater->check_for_updates();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Update plugin now.
	 */
	public function ajax_update_now() {
		$this->verify_ajax_request();

		$result = $this->updater->update( WPP_Plugin_Logger::SOURCE_MANUAL );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'message' => __( 'Plugin updated successfully!', 'wp-puller-plugin' ),
			'status'  => $this->updater->get_status(),
		) );
	}

	/**
	 * AJAX: Restore backup.
	 */
	public function ajax_restore_backup() {
		$this->verify_ajax_request();

		$backup_name = isset( $_POST['backup_name'] ) ? sanitize_file_name( wp_unslash( $_POST['backup_name'] ) ) : '';

		if ( empty( $backup_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid backup name.', 'wp-puller-plugin' ) ) );
		}

		$result = $this->backup->restore_backup( $backup_name );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$this->logger->log_restore_success( $backup_name );

		wp_send_json_success( array( 'message' => __( 'Backup restored successfully!', 'wp-puller-plugin' ) ) );
	}

	/**
	 * AJAX: Delete backup.
	 */
	public function ajax_delete_backup() {
		$this->verify_ajax_request();

		$backup_name = isset( $_POST['backup_name'] ) ? sanitize_file_name( wp_unslash( $_POST['backup_name'] ) ) : '';

		if ( empty( $backup_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid backup name.', 'wp-puller-plugin' ) ) );
		}

		$result = $this->backup->delete_backup( $backup_name );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Backup deleted successfully!', 'wp-puller-plugin' ) ) );
	}

	/**
	 * AJAX: Regenerate webhook secret.
	 */
	public function ajax_regenerate_secret() {
		$this->verify_ajax_request();

		$new_secret = WPP_Plugin_Webhook_Handler::generate_secret();
		WPP_Plugin_Webhook_Handler::store_secret( $new_secret );

		$this->logger->log(
			__( 'Webhook secret regenerated', 'wp-puller-plugin' ),
			WPP_Plugin_Logger::STATUS_INFO,
			WPP_Plugin_Logger::SOURCE_MANUAL
		);

		wp_send_json_success( array(
			'message' => __( 'Secret regenerated. Update it in GitHub.', 'wp-puller-plugin' ),
			'secret'  => $new_secret,
		) );
	}

	/**
	 * AJAX: Clear logs.
	 */
	public function ajax_clear_logs() {
		$this->verify_ajax_request();

		$this->logger->clear_logs();

		wp_send_json_success( array( 'message' => __( 'Logs cleared.', 'wp-puller-plugin' ) ) );
	}

	/**
	 * Verify AJAX request nonce and capability.
	 */
	private function verify_ajax_request() {
		if ( ! check_ajax_referer( 'wpp_plugin_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'wp-puller-plugin' ) ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'wp-puller-plugin' ) ) );
		}
	}

	/**
	 * Get masked PAT for display.
	 *
	 * @return string
	 */
	public static function get_masked_pat() {
		$encrypted = get_option( 'wpp_plugin_pat', '' );

		if ( empty( $encrypted ) ) {
			return '';
		}

		$decrypted = WPP_Plugin_Core::decrypt( $encrypted );

		if ( empty( $decrypted ) ) {
			return '';
		}

		return str_repeat( '*', min( strlen( $decrypted ), 24 ) );
	}

	/**
	 * Get PAT status for debugging.
	 *
	 * @return array
	 */
	public static function get_pat_status() {
		$encrypted = get_option( 'wpp_plugin_pat', '' );

		if ( empty( $encrypted ) ) {
			return array(
				'stored'   => false,
				'decrypts' => false,
				'type'     => 'none',
				'message'  => 'No token saved',
			);
		}

		$decrypted = WPP_Plugin_Core::decrypt( $encrypted );

		if ( empty( $decrypted ) ) {
			return array(
				'stored'   => true,
				'decrypts' => false,
				'type'     => 'unknown',
				'message'  => 'Token stored but decryption failed',
			);
		}

		$type = 'classic';
		if ( strpos( $decrypted, 'github_pat_' ) === 0 ) {
			$type = 'fine-grained';
		} elseif ( strpos( $decrypted, 'ghp_' ) === 0 ) {
			$type = 'classic';
		}

		return array(
			'stored'   => true,
			'decrypts' => true,
			'type'     => $type,
			'length'   => strlen( $decrypted ),
			'message'  => sprintf( 'Token OK (%s, %d chars)', $type, strlen( $decrypted ) ),
		);
	}
}
