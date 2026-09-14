<?php
/**
 * Main WP Puller Plugin core class.
 *
 * @package WP_Puller_Plugin
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WPP_Plugin_Core Class.
 */
final class WPP_Plugin_Core {

	/**
	 * Version.
	 *
	 * @var string
	 */
	public $version = '1.0.8';

	/**
	 * Singleton instance.
	 *
	 * @var WPP_Plugin_Core
	 */
	protected static $instance = null;

	/** @var WPP_Plugin_GitHub_API */
	public $github_api = null;

	/** @var WPP_Plugin_Webhook_Handler */
	public $webhook = null;

	/** @var WPP_Plugin_Updater */
	public $updater = null;

	/** @var WPP_Plugin_Backup */
	public $backup = null;

	/** @var WPP_Plugin_Logger */
	public $logger = null;

	/** @var WPP_Plugin_Admin */
	public $admin = null;

	/**
	 * Main instance.
	 *
	 * @return WPP_Plugin_Core
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->includes();
		$this->init_hooks();
	}

	/**
	 * Include required files.
	 */
	private function includes() {
		require_once WPP_PLUGIN_DIR . 'includes/class-logger.php';
		require_once WPP_PLUGIN_DIR . 'includes/class-client-ip.php';
		require_once WPP_PLUGIN_DIR . 'includes/class-github-api.php';
		require_once WPP_PLUGIN_DIR . 'includes/class-backup.php';
		require_once WPP_PLUGIN_DIR . 'includes/class-updater.php';
		require_once WPP_PLUGIN_DIR . 'includes/class-webhook-handler.php';

		if ( is_admin() ) {
			require_once WPP_PLUGIN_DIR . 'includes/class-admin.php';
		}
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		add_action( 'init', array( $this, 'init' ), 0 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Init when WordPress initializes.
	 */
	public function init() {
		$this->load_textdomain();
		$this->init_classes();

		do_action( 'wpp_plugin_init' );
	}

	/**
	 * Load text domain.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'wp-puller-plugin',
			false,
			dirname( WPP_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Initialize plugin classes.
	 */
	private function init_classes() {
		$this->logger     = new WPP_Plugin_Logger();
		$this->github_api = new WPP_Plugin_GitHub_API();
		$this->backup     = new WPP_Plugin_Backup();
		$this->updater    = new WPP_Plugin_Updater( $this->github_api, $this->backup, $this->logger );
		$this->webhook    = new WPP_Plugin_Webhook_Handler( $this->updater, $this->logger );

		if ( is_admin() ) {
			$this->admin = new WPP_Plugin_Admin( $this->github_api, $this->updater, $this->backup, $this->logger );
		}
	}

	/**
	 * Register REST routes.
	 */
	public function register_rest_routes() {
		if ( $this->webhook ) {
			$this->webhook->register_routes();
		}
	}

	/**
	 * Encrypt a value using WordPress salts.
	 *
	 * @param string $value Value to encrypt.
	 * @return string
	 */
	public static function encrypt( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		$key    = self::get_encryption_key();
		$iv     = openssl_random_pseudo_bytes( 16 );
		$cipher = openssl_encrypt( $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $cipher ) {
			return '';
		}

		$payload = $iv . $cipher;
		$hmac    = hash_hmac( 'sha256', $payload, $key, true );

		return 'v2:' . base64_encode( $payload . $hmac );
	}

	/**
	 * Decrypt a value using WordPress salts.
	 *
	 * @param string $value Value to decrypt.
	 * @return string
	 */
	public static function decrypt( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		$key = self::get_encryption_key();

		if ( strpos( $value, 'v2:' ) === 0 ) {
			$data = base64_decode( substr( $value, 3 ) );

			if ( false === $data || strlen( $data ) < 49 ) {
				return '';
			}

			$hmac          = substr( $data, -32 );
			$payload       = substr( $data, 0, -32 );
			$expected_hmac = hash_hmac( 'sha256', $payload, $key, true );

			if ( ! hash_equals( $expected_hmac, $hmac ) ) {
				return '';
			}

			$iv     = substr( $payload, 0, 16 );
			$cipher = substr( $payload, 16 );
		} else {
			// Legacy plaintext fallback.
			$data = base64_decode( $value );

			if ( false === $data || strlen( $data ) < 17 ) {
				return '';
			}

			$iv     = substr( $data, 0, 16 );
			$cipher = substr( $data, 16 );
		}

		$decrypted = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

		return false === $decrypted ? '' : $decrypted;
	}

	/**
	 * Get encryption key from WordPress salts.
	 *
	 * @return string
	 */
	private static function get_encryption_key() {
		$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : self::get_or_create_fallback_key();
		return hash( 'sha256', $salt, true );
	}

	/**
	 * Get or create a site-specific fallback encryption key.
	 *
	 * @return string
	 */
	private static function get_or_create_fallback_key() {
		$key = get_option( 'wpp_plugin_encryption_key', '' );

		if ( empty( $key ) ) {
			$key = wp_generate_password( 64, true, true );
			update_option( 'wpp_plugin_encryption_key', $key, false );
		}

		return $key;
	}
}
