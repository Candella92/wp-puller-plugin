<?php
/**
 * Uninstall WP Puller Plugin
 *
 * Removes all plugin data when uninstalled.
 *
 * @package WP_Puller_Plugin
 * @since 1.0.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Resolve backup directory path before options are deleted.
$backup_dir_suffix = get_option( 'wpp_plugin_backup_dir_suffix', '' );
$backup_dir        = '';
if ( ! empty( $backup_dir_suffix ) ) {
	$backup_dir = WP_CONTENT_DIR . '/wpp-plugin-backups-' . $backup_dir_suffix;
}

$options = array(
	'wpp_plugin_repo_url',
	'wpp_plugin_branch',
	'wpp_plugin_slug',
	'wpp_plugin_subdir',
	'wpp_plugin_pat',
	'wpp_plugin_webhook_secret',
	'wpp_plugin_last_check',
	'wpp_plugin_latest_commit',
	'wpp_plugin_auto_update',
	'wpp_plugin_update_log',
	'wpp_plugin_backup_count',
	'wpp_plugin_encryption_key',
	'wpp_plugin_backup_dir_suffix',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

global $wpdb;

// Cache transients.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		'_transient_wpp_plugin_cache_%'
	)
);
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		'_transient_timeout_wpp_plugin_cache_%'
	)
);

// Rate-limit transients.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		'_transient_wpp_plugin_rl_%'
	)
);
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		'_transient_timeout_wpp_plugin_rl_%'
	)
);

// Cloudflare IP cache.
delete_transient( 'wpp_plugin_cloudflare_ips' );

// Update lock transient.
delete_transient( 'wpp_plugin_update_lock' );

// Remove backup directory from filesystem.
if ( ! empty( $backup_dir ) && is_dir( $backup_dir ) ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $backup_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $file ) {
		if ( $file->isDir() ) {
			rmdir( $file->getRealPath() );
		} else {
			unlink( $file->getRealPath() );
		}
	}
	rmdir( $backup_dir );
}
