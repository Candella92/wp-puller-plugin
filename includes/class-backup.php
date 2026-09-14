<?php
/**
 * Backup class for WP Puller Plugin.
 *
 * Backs up and restores plugin directories.
 *
 * @package WP_Puller_Plugin
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPP_Plugin_Backup Class.
 */
class WPP_Plugin_Backup {

	/**
	 * Backup directory base name.
	 *
	 * @var string
	 */
	const BACKUP_DIR = 'wpp-plugin-backups';

	/**
	 * Get the backup directory path.
	 *
	 * Uses a random suffix so the directory is not guessable.
	 *
	 * @return string
	 */
	public function get_backup_dir() {
		$suffix = get_option( 'wpp_plugin_backup_dir_suffix', '' );

		if ( empty( $suffix ) ) {
			$suffix = wp_generate_password( 16, false );
			update_option( 'wpp_plugin_backup_dir_suffix', $suffix, false );
		}

		return WP_CONTENT_DIR . '/' . self::BACKUP_DIR . '-' . $suffix;
	}

	/**
	 * Ensure backup directory exists and is protected.
	 *
	 * @return bool|WP_Error
	 */
	public function ensure_backup_dir() {
		$backup_dir = $this->get_backup_dir();

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $wp_filesystem->is_dir( $backup_dir ) ) {
			if ( ! $wp_filesystem->mkdir( $backup_dir, 0755 ) ) {
				return new WP_Error(
					'mkdir_failed',
					__( 'Failed to create backup directory.', 'wp-puller-plugin' )
				);
			}
		}

		$htaccess = $backup_dir . '/.htaccess';
		if ( ! $wp_filesystem->exists( $htaccess ) ) {
			$wp_filesystem->put_contents( $htaccess, "Deny from all\n" );
		}

		$index = $backup_dir . '/index.php';
		if ( ! $wp_filesystem->exists( $index ) ) {
			$wp_filesystem->put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		return true;
	}

	/**
	 * Create a backup of the target plugin directory.
	 *
	 * @param string $plugin_slug Plugin folder name.
	 * @return string|WP_Error Backup directory path on success, WP_Error on failure.
	 */
	public function create_backup( $plugin_slug ) {
		$result = $this->ensure_backup_dir();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$plugin_dir = WP_PLUGIN_DIR . '/' . sanitize_file_name( $plugin_slug );

		// If the plugin directory doesn't exist yet (first install), skip backup.
		if ( ! is_dir( $plugin_dir ) ) {
			return $this->get_backup_dir() . '/' . $plugin_slug . '_' . gmdate( 'Y-m-d_H-i-s' ) . '_empty';
		}

		$timestamp   = gmdate( 'Y-m-d_H-i-s' );
		$backup_name = $plugin_slug . '_' . $timestamp;
		$backup_path = $this->get_backup_dir() . '/' . $backup_name;

		if ( ! $this->recursive_copy( $plugin_dir, $backup_path ) ) {
			return new WP_Error(
				'backup_failed',
				__( 'Failed to create plugin backup.', 'wp-puller-plugin' )
			);
		}

		$this->cleanup_old_backups( $plugin_slug );

		return $backup_path;
	}

	/**
	 * Restore plugin from a backup.
	 *
	 * @param string $backup_name Backup directory name.
	 * @param string $plugin_slug Plugin folder name.
	 * @return bool|WP_Error
	 */
	public function restore_backup( $backup_name, $plugin_slug = '' ) {
		$backup_path = $this->get_backup_dir() . '/' . sanitize_file_name( $backup_name );

		if ( ! is_dir( $backup_path ) ) {
			return new WP_Error(
				'backup_not_found',
				__( 'Backup not found.', 'wp-puller-plugin' )
			);
		}

		if ( empty( $plugin_slug ) ) {
			$plugin_slug = get_option( 'wpp_plugin_slug', '' );
		}

		if ( empty( $plugin_slug ) ) {
			return new WP_Error(
				'no_slug',
				__( 'Plugin slug not configured.', 'wp-puller-plugin' )
			);
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$plugin_dir = WP_PLUGIN_DIR . '/' . sanitize_file_name( $plugin_slug );
		$parent     = dirname( $plugin_dir );
		$suffix     = wp_generate_password( 8, false );

		$staging = $parent . '/.wpp-restore-' . $suffix;
		$old_dir = $parent . '/.wpp-old-' . $suffix;

		// 1. Build restored copy in staging first.
		if ( ! $this->recursive_copy( $backup_path, $staging ) ) {
			$this->recursive_delete( $staging );
			return new WP_Error(
				'restore_failed',
				__( 'Failed to stage backup for restore.', 'wp-puller-plugin' )
			);
		}

		// 2. Move current plugin aside.
		if ( is_dir( $plugin_dir ) && ! $wp_filesystem->move( $plugin_dir, $old_dir ) ) {
			$this->recursive_delete( $staging );
			return new WP_Error(
				'restore_failed',
				__( 'Failed to set the current plugin aside for restore.', 'wp-puller-plugin' )
			);
		}

		// 3. Move staging into place.
		if ( ! $wp_filesystem->move( $staging, $plugin_dir ) ) {
			if ( is_dir( $old_dir ) ) {
				$wp_filesystem->move( $old_dir, $plugin_dir );
			}
			$this->recursive_delete( $staging );
			return new WP_Error(
				'restore_failed',
				__( 'Failed to activate the restored plugin; the original was kept.', 'wp-puller-plugin' )
			);
		}

		// 4. Discard old copy.
		$this->recursive_delete( $old_dir );

		return true;
	}

	/**
	 * Get list of available backups.
	 *
	 * @param string $plugin_slug Optional plugin slug to filter.
	 * @return array
	 */
	public function get_backups( $plugin_slug = '' ) {
		$backup_dir = $this->get_backup_dir();

		if ( ! is_dir( $backup_dir ) ) {
			return array();
		}

		$backups = array();
		$dirs    = glob( $backup_dir . '/*', GLOB_ONLYDIR );

		if ( ! $dirs ) {
			return array();
		}

		foreach ( $dirs as $dir ) {
			$name = basename( $dir );

			if ( ! empty( $plugin_slug ) && strpos( $name, $plugin_slug . '_' ) !== 0 ) {
				continue;
			}

			$timestamp = filemtime( $dir );

			$backups[] = array(
				'name'      => $name,
				'path'      => $dir,
				'timestamp' => $timestamp,
				'datetime'  => gmdate( 'Y-m-d H:i:s', $timestamp ),
				'size'      => $this->get_directory_size( $dir ),
			);
		}

		usort( $backups, function( $a, $b ) {
			return $b['timestamp'] - $a['timestamp'];
		} );

		return $backups;
	}

	/**
	 * Delete a backup.
	 *
	 * @param string $backup_name Backup directory name.
	 * @return bool|WP_Error
	 */
	public function delete_backup( $backup_name ) {
		$backup_path = $this->get_backup_dir() . '/' . sanitize_file_name( $backup_name );

		if ( ! is_dir( $backup_path ) ) {
			return new WP_Error(
				'backup_not_found',
				__( 'Backup not found.', 'wp-puller-plugin' )
			);
		}

		if ( ! $this->recursive_delete( $backup_path ) ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete backup.', 'wp-puller-plugin' )
			);
		}

		return true;
	}

	/**
	 * Cleanup old backups, keeping only the most recent N.
	 *
	 * @param string $plugin_slug Plugin slug.
	 */
	private function cleanup_old_backups( $plugin_slug ) {
		$max_backups = absint( get_option( 'wpp_plugin_backup_count', 3 ) );
		$backups     = $this->get_backups( $plugin_slug );

		if ( count( $backups ) <= $max_backups ) {
			return;
		}

		$to_delete = array_slice( $backups, $max_backups );

		foreach ( $to_delete as $backup ) {
			$this->recursive_delete( $backup['path'] );
		}
	}

	/**
	 * Recursively copy a directory.
	 *
	 * @param string $source      Source directory.
	 * @param string $destination Destination directory.
	 * @return bool
	 */
	private function recursive_copy( $source, $destination ) {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! is_dir( $source ) ) {
			return false;
		}

		if ( ! $wp_filesystem->is_dir( $destination ) ) {
			$wp_filesystem->mkdir( $destination, 0755 );
		}

		$dir = opendir( $source );

		if ( ! $dir ) {
			return false;
		}

		while ( false !== ( $file = readdir( $dir ) ) ) {
			if ( '.' === $file || '..' === $file ) {
				continue;
			}

			$src_path  = $source . '/' . $file;
			$dest_path = $destination . '/' . $file;

			if ( is_dir( $src_path ) ) {
				if ( ! $this->recursive_copy( $src_path, $dest_path ) ) {
					closedir( $dir );
					return false;
				}
			} else {
				if ( ! $wp_filesystem->copy( $src_path, $dest_path ) ) {
					closedir( $dir );
					return false;
				}
			}
		}

		closedir( $dir );

		return true;
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $path Directory path.
	 * @return bool
	 */
	private function recursive_delete( $path ) {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! is_dir( $path ) ) {
			return $wp_filesystem->delete( $path );
		}

		$dir = opendir( $path );

		if ( ! $dir ) {
			return false;
		}

		while ( false !== ( $file = readdir( $dir ) ) ) {
			if ( '.' === $file || '..' === $file ) {
				continue;
			}

			$full_path = $path . '/' . $file;

			if ( is_dir( $full_path ) ) {
				$this->recursive_delete( $full_path );
			} else {
				$wp_filesystem->delete( $full_path );
			}
		}

		closedir( $dir );

		return $wp_filesystem->rmdir( $path );
	}

	/**
	 * Get total size of a directory.
	 *
	 * @param string $path Directory path.
	 * @return int Size in bytes.
	 */
	private function get_directory_size( $path ) {
		$size = 0;

		if ( ! is_dir( $path ) ) {
			return $size;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$size += $file->getSize();
			}
		}

		return $size;
	}

	/**
	 * Format bytes to human readable string.
	 *
	 * @param int $bytes    Size in bytes.
	 * @param int $decimals Decimal places.
	 * @return string
	 */
	public static function format_size( $bytes, $decimals = 2 ) {
		if ( $bytes < 1024 ) {
			return $bytes . ' B';
		}

		$units = array( 'B', 'KB', 'MB', 'GB' );
		$bytes = (float) $bytes;

		for ( $i = 0; $bytes >= 1024 && $i < count( $units ) - 1; $i++ ) {
			$bytes /= 1024;
		}

		return round( $bytes, $decimals ) . ' ' . $units[ $i ];
	}
}
