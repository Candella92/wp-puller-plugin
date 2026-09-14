<?php
/**
 * Plugin Updater class for WP Puller Plugin.
 *
 * Pulls a plugin from GitHub and installs it into WP_PLUGIN_DIR.
 * The target plugin slug (folder name) must be configured in settings.
 *
 * @package WP_Puller_Plugin
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPP_Plugin_Updater Class.
 */
class WPP_Plugin_Updater {

	/** @var WPP_Plugin_GitHub_API */
	private $github_api;

	/** @var WPP_Plugin_Backup */
	private $backup;

	/** @var WPP_Plugin_Logger */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param WPP_Plugin_GitHub_API $github_api GitHub API instance.
	 * @param WPP_Plugin_Backup     $backup     Backup instance.
	 * @param WPP_Plugin_Logger     $logger     Logger instance.
	 */
	public function __construct( $github_api, $backup, $logger ) {
		$this->github_api = $github_api;
		$this->backup     = $backup;
		$this->logger     = $logger;
	}

	/**
	 * Update the plugin from GitHub.
	 *
	 * @param string $source Update source (webhook, manual).
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function update( $source = 'manual' ) {
		if ( ! $this->acquire_update_lock() ) {
			$error = new WP_Error(
				'update_locked',
				__( 'An update is already in progress. Please try again shortly.', 'wp-puller-plugin' )
			);
			$this->logger->log_update_error( $error->get_error_message(), $source );
			return $error;
		}

		$result = $this->do_update( $source );

		$this->release_update_lock();

		return $result;
	}

	/**
	 * Acquire the update lock.
	 *
	 * @return bool
	 */
	private function acquire_update_lock() {
		if ( get_transient( 'wpp_plugin_update_lock' ) ) {
			return false;
		}
		set_transient( 'wpp_plugin_update_lock', 1, 5 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Release the update lock.
	 */
	private function release_update_lock() {
		delete_transient( 'wpp_plugin_update_lock' );
	}

	/**
	 * Internal update implementation.
	 *
	 * @param string $source Update source.
	 * @return bool|WP_Error
	 */
	private function do_update( $source ) {
		$repo_url    = get_option( 'wpp_plugin_repo_url', '' );
		$branch      = get_option( 'wpp_plugin_branch', 'main' );
		$plugin_slug = get_option( 'wpp_plugin_slug', '' );

		if ( empty( $repo_url ) ) {
			$error = new WP_Error(
				'no_repo',
				__( 'No GitHub repository configured.', 'wp-puller-plugin' )
			);
			$this->logger->log_update_error( $error->get_error_message(), $source );
			return $error;
		}

		if ( empty( $plugin_slug ) ) {
			$error = new WP_Error(
				'no_slug',
				__( 'No plugin slug configured. Enter the folder name of the plugin to update.', 'wp-puller-plugin' )
			);
			$this->logger->log_update_error( $error->get_error_message(), $source );
			return $error;
		}

		$parsed = $this->github_api->parse_repo_url( $repo_url );

		if ( ! $parsed ) {
			$error = new WP_Error(
				'invalid_repo',
				__( 'Invalid GitHub repository URL.', 'wp-puller-plugin' )
			);
			$this->logger->log_update_error( $error->get_error_message(), $source );
			return $error;
		}

		$latest_commit = $this->github_api->get_latest_commit( $parsed['owner'], $parsed['repo'], $branch );

		if ( is_wp_error( $latest_commit ) ) {
			$this->logger->log_update_error( $latest_commit->get_error_message(), $source );
			return $latest_commit;
		}

		$backup_path = $this->backup->create_backup( $plugin_slug );

		if ( is_wp_error( $backup_path ) ) {
			$this->logger->log_update_error( $backup_path->get_error_message(), $source );
			return $backup_path;
		}

		$this->logger->log_backup_created( $backup_path );

		$zip_file = $this->github_api->download_archive( $parsed['owner'], $parsed['repo'], $branch );

		if ( is_wp_error( $zip_file ) ) {
			$this->logger->log_update_error( $zip_file->get_error_message(), $source );
			return $zip_file;
		}

		$result = $this->install_plugin( $zip_file, $parsed['repo'], $branch, $plugin_slug );

		unlink( $zip_file );

		if ( is_wp_error( $result ) ) {
			$this->logger->log_update_error( $result->get_error_message(), $source );

			// Attempt auto-restore.
			$restore = $this->backup->restore_backup( basename( $backup_path ), $plugin_slug );

			if ( is_wp_error( $restore ) ) {
				$this->logger->log(
					sprintf(
						/* translators: %s: error message */
						__( 'Auto-restore failed after update error: %s', 'wp-puller-plugin' ),
						$restore->get_error_message()
					),
					WPP_Plugin_Logger::STATUS_ERROR,
					WPP_Plugin_Logger::SOURCE_SYSTEM
				);
			} else {
				$this->logger->log(
					__( 'Plugin auto-restored from backup after failed update.', 'wp-puller-plugin' ),
					WPP_Plugin_Logger::STATUS_INFO,
					WPP_Plugin_Logger::SOURCE_SYSTEM
				);
			}

			return $result;
		}

		update_option( 'wpp_plugin_latest_commit', $latest_commit['sha'] );
		update_option( 'wpp_plugin_last_check', time() );

		$this->logger->log_update_success( $latest_commit['short_sha'], $source, array(
			'commit_sha'     => $latest_commit['sha'],
			'commit_message' => substr( $latest_commit['message'], 0, 100 ),
		) );

		do_action( 'wpp_plugin_updated', $latest_commit, $source );

		return true;
	}

	/**
	 * Check if an update is available.
	 *
	 * @return array|WP_Error
	 */
	public function check_for_updates() {
		$repo_url = get_option( 'wpp_plugin_repo_url', '' );
		$branch   = get_option( 'wpp_plugin_branch', 'main' );

		if ( empty( $repo_url ) ) {
			return new WP_Error(
				'no_repo',
				__( 'No GitHub repository configured.', 'wp-puller-plugin' )
			);
		}

		$parsed = $this->github_api->parse_repo_url( $repo_url );

		if ( ! $parsed ) {
			return new WP_Error(
				'invalid_repo',
				__( 'Invalid GitHub repository URL.', 'wp-puller-plugin' )
			);
		}

		$this->github_api->clear_cache();

		$latest_commit = $this->github_api->get_latest_commit( $parsed['owner'], $parsed['repo'], $branch );

		if ( is_wp_error( $latest_commit ) ) {
			return $latest_commit;
		}

		$current_commit = get_option( 'wpp_plugin_latest_commit', '' );

		update_option( 'wpp_plugin_last_check', time() );

		return array(
			'update_available' => ! empty( $current_commit ) && $current_commit !== $latest_commit['sha'],
			'current_commit'   => $current_commit,
			'latest_commit'    => $latest_commit,
			'is_new_setup'     => empty( $current_commit ),
		);
	}

	/**
	 * Install plugin from ZIP file.
	 *
	 * @param string $zip_file    ZIP file path.
	 * @param string $repo        Repository name.
	 * @param string $branch      Branch name.
	 * @param string $plugin_slug Target plugin folder name.
	 * @return bool|WP_Error
	 */
	private function install_plugin( $zip_file, $repo, $branch, $plugin_slug ) {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$plugin_dir = WP_PLUGIN_DIR . '/' . sanitize_file_name( $plugin_slug );
		$temp_dir   = get_temp_dir() . 'wpp-plugin-' . uniqid();

		$result = unzip_file( $zip_file, $temp_dir );

		if ( is_wp_error( $result ) ) {
			$wp_filesystem->delete( $temp_dir, true );
			return new WP_Error(
				'unzip_failed',
				__( 'Failed to extract plugin archive.', 'wp-puller-plugin' )
			);
		}

		// Find the extracted root directory.
		$extracted_dir = $temp_dir . '/' . $repo . '-' . $branch;

		if ( ! is_dir( $extracted_dir ) ) {
			$dirs = glob( $temp_dir . '/*', GLOB_ONLYDIR );

			if ( ! empty( $dirs ) ) {
				$extracted_dir = $dirs[0];
			} else {
				$wp_filesystem->delete( $temp_dir, true );
				return new WP_Error(
					'invalid_archive',
					__( 'Invalid plugin archive structure.', 'wp-puller-plugin' )
				);
			}
		}

		// Handle plugin in subdirectory of the repo.
		$plugin_path = get_option( 'wpp_plugin_subdir', '' );
		if ( ! empty( $plugin_path ) ) {
			if ( false !== strpos( $plugin_path, '..' ) ) {
				$wp_filesystem->delete( $temp_dir, true );
				return new WP_Error(
					'invalid_path',
					__( 'Invalid plugin subdirectory path.', 'wp-puller-plugin' )
				);
			}

			$extracted_dir = $extracted_dir . '/' . $plugin_path;

			if ( ! is_dir( $extracted_dir ) ) {
				$wp_filesystem->delete( $temp_dir, true );
				return new WP_Error(
					'path_not_found',
					sprintf(
						/* translators: %s: subdirectory path */
						__( 'Plugin subdirectory "%s" not found in repository.', 'wp-puller-plugin' ),
						$plugin_path
					)
				);
			}
		}

		// Validate: find the main plugin file (must contain "Plugin Name:" header).
		$main_file = $this->find_main_plugin_file( $extracted_dir, $plugin_slug );

		if ( is_wp_error( $main_file ) ) {
			$wp_filesystem->delete( $temp_dir, true );
			return $main_file;
		}

		// Clear the existing plugin directory and copy new files in.
		$this->clear_plugin_directory( $plugin_dir );

		$copy_result = copy_dir( $extracted_dir, $plugin_dir );

		$wp_filesystem->delete( $temp_dir, true );

		if ( is_wp_error( $copy_result ) ) {
			return new WP_Error(
				'copy_failed',
				__( 'Failed to copy plugin files.', 'wp-puller-plugin' )
			);
		}

		$this->clear_plugin_cache();

		return true;
	}

	/**
	 * Find and validate the main plugin file in the extracted directory.
	 *
	 * Looks for a PHP file with a "Plugin Name:" header, preferring
	 * {plugin-slug}.php at the root level.
	 *
	 * @param string $dir         Extracted directory.
	 * @param string $plugin_slug Expected plugin slug.
	 * @return string|WP_Error Main plugin file path on success, WP_Error on failure.
	 */
	private function find_main_plugin_file( $dir, $plugin_slug ) {
		// Check preferred name first: {slug}.php
		$preferred = $dir . '/' . $plugin_slug . '.php';

		if ( file_exists( $preferred ) ) {
			$data = get_file_data( $preferred, array( 'Name' => 'Plugin Name' ) );
			if ( ! empty( $data['Name'] ) ) {
				return $preferred;
			}
		}

		// Scan all PHP files in the root for a Plugin Name header.
		$php_files = glob( $dir . '/*.php' );

		if ( $php_files ) {
			foreach ( $php_files as $file ) {
				$data = get_file_data( $file, array( 'Name' => 'Plugin Name' ) );
				if ( ! empty( $data['Name'] ) ) {
					return $file;
				}
			}
		}

		// Provide a helpful hint if subdirs contain a valid plugin.
		$subdirs = glob( $dir . '/*', GLOB_ONLYDIR );
		$hint    = '';
		foreach ( (array) $subdirs as $subdir ) {
			$sub_files = glob( $subdir . '/*.php' );
			foreach ( (array) $sub_files as $file ) {
				$data = get_file_data( $file, array( 'Name' => 'Plugin Name' ) );
				if ( ! empty( $data['Name'] ) ) {
					$hint = sprintf(
						/* translators: %s: subdirectory name */
						__( ' Found plugin in subdirectory "%s" — set this as Plugin Subdirectory in settings.', 'wp-puller-plugin' ),
						basename( $subdir )
					);
					break 2;
				}
			}
		}

		return new WP_Error(
			'not_a_plugin',
			__( 'The repository does not contain a valid WordPress plugin (no PHP file with Plugin Name header found).', 'wp-puller-plugin' ) . $hint
		);
	}

	/**
	 * Clear plugin directory contents.
	 *
	 * @param string $dir Directory path.
	 */
	private function clear_plugin_directory( $dir ) {
		global $wp_filesystem;

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );

		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;

			if ( is_dir( $path ) ) {
				$wp_filesystem->delete( $path, true );
			} else {
				$wp_filesystem->delete( $path );
			}
		}
	}

	/**
	 * Clear plugin-related caches.
	 */
	private function clear_plugin_cache() {
		wp_clean_plugins_cache();

		delete_transient( 'dirsize_cache' );

		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		do_action( 'wpp_plugin_cache_cleared' );
	}

	/**
	 * Get info about the configured target plugin.
	 *
	 * @return array
	 */
	public function get_target_plugin_info() {
		$plugin_slug = get_option( 'wpp_plugin_slug', '' );

		if ( empty( $plugin_slug ) ) {
			return array(
				'name'    => __( 'Not configured', 'wp-puller-plugin' ),
				'version' => '',
				'slug'    => '',
				'dir'     => '',
				'exists'  => false,
			);
		}

		$plugin_dir  = WP_PLUGIN_DIR . '/' . sanitize_file_name( $plugin_slug );
		$plugin_file = $plugin_dir . '/' . $plugin_slug . '.php';

		// Try to locate the main plugin file.
		if ( ! file_exists( $plugin_file ) ) {
			$php_files = glob( $plugin_dir . '/*.php' );
			foreach ( (array) $php_files as $file ) {
				$data = get_file_data( $file, array( 'Name' => 'Plugin Name' ) );
				if ( ! empty( $data['Name'] ) ) {
					$plugin_file = $file;
					break;
				}
			}
		}

		if ( file_exists( $plugin_file ) ) {
			$data = get_file_data( $plugin_file, array(
				'Name'    => 'Plugin Name',
				'Version' => 'Version',
				'Author'  => 'Author',
			) );

			return array(
				'name'    => ! empty( $data['Name'] ) ? $data['Name'] : $plugin_slug,
				'version' => ! empty( $data['Version'] ) ? $data['Version'] : '',
				'author'  => ! empty( $data['Author'] ) ? $data['Author'] : '',
				'slug'    => $plugin_slug,
				'dir'     => $plugin_dir,
				'exists'  => true,
			);
		}

		return array(
			'name'    => $plugin_slug,
			'version' => '',
			'slug'    => $plugin_slug,
			'dir'     => $plugin_dir,
			'exists'  => is_dir( $plugin_dir ),
		);
	}

	/**
	 * Get update status.
	 *
	 * @return array
	 */
	public function get_status() {
		$repo_url       = get_option( 'wpp_plugin_repo_url', '' );
		$branch         = get_option( 'wpp_plugin_branch', 'main' );
		$plugin_slug    = get_option( 'wpp_plugin_slug', '' );
		$plugin_subdir  = get_option( 'wpp_plugin_subdir', '' );
		$current_commit = get_option( 'wpp_plugin_latest_commit', '' );
		$last_check     = get_option( 'wpp_plugin_last_check', 0 );
		$auto_update    = get_option( 'wpp_plugin_auto_update', true );

		$parsed = $this->github_api->parse_repo_url( $repo_url );

		return array(
			'is_configured'  => ! empty( $repo_url ) && ! empty( $plugin_slug ) && false !== $parsed,
			'repo_url'       => $repo_url,
			'branch'         => $branch,
			'plugin_slug'    => $plugin_slug,
			'plugin_subdir'  => $plugin_subdir,
			'current_commit' => $current_commit,
			'short_commit'   => ! empty( $current_commit ) ? substr( $current_commit, 0, 7 ) : '',
			'last_check'     => $last_check,
			'auto_update'    => $auto_update,
			'repo_owner'     => $parsed ? $parsed['owner'] : '',
			'repo_name'      => $parsed ? $parsed['repo'] : '',
		);
	}
}
