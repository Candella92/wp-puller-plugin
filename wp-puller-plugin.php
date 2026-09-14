<?php
/**
 * Plugin Name: WP Puller Plugin
 * Plugin URI: https://github.com/codician-team/wp-puller-plugin
 * Description: Automatically update any WordPress plugin from GitHub. Supports public and private repositories with webhook-based real-time updates.
 * Version: 1.0.8
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: Developer
 * Author URI: https://github.com/developer
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: wp-puller-plugin
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPP_PLUGIN_VERSION', '1.0.8' );
define( 'WPP_PLUGIN_FILE', __FILE__ );
define( 'WPP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once WPP_PLUGIN_DIR . 'includes/class-core.php';

/**
 * Returns the main instance of WPP_Plugin_Core.
 *
 * @since 1.0.0
 * @return WPP_Plugin_Core
 */
function wpp_plugin() {
	return WPP_Plugin_Core::instance();
}

/**
 * Activation hook.
 *
 * @since 1.0.0
 */
function wpp_plugin_activate() {
	if ( ! get_option( 'wpp_plugin_webhook_secret' ) ) {
		update_option( 'wpp_plugin_webhook_secret', WPP_Plugin_Core::encrypt( wp_generate_password( 32, false ) ) );
	}

	if ( false === get_option( 'wpp_plugin_branch' ) ) {
		update_option( 'wpp_plugin_branch', 'main' );
	}

	if ( false === get_option( 'wpp_plugin_auto_update' ) ) {
		update_option( 'wpp_plugin_auto_update', true );
	}

	if ( false === get_option( 'wpp_plugin_backup_count' ) ) {
		update_option( 'wpp_plugin_backup_count', 3 );
	}

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'wpp_plugin_activate' );

/**
 * Deactivation hook.
 *
 * @since 1.0.0
 */
function wpp_plugin_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'wpp_plugin_deactivate' );

wpp_plugin();
