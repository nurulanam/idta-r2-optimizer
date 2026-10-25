<?php
/**
 * Main plugin coordinator and service container.
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

namespace IDTA\R2Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton service container managing lifecycle, dependencies, and capability checks.
 */
final class Plugin {

	/**
	 * Minimum PHP version required to execute the plugin.
	 */
	public const MIN_PHP_VERSION = '8.1';

	/**
	 * Minimum WooCommerce version supported.
	 */
	public const MIN_WC_VERSION = '7.0';

	/**
	 * WordPress option key storing plugin configuration.
	 */
	public const SETTINGS_OPTION = 'idta_r2_settings';

	/**
	 * Capability required to access any plugin features or execute actions.
	 */
	public const REQUIRED_CAPABILITY = 'manage_woocommerce';

	/**
	 * Nonce action prefix for CSRF verification.
	 */
	public const NONCE_ACTION_PREFIX = 'idta_r2_optimizer_';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether the plugin has already booted.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Cached settings array.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cached_settings = null;

	/**
	 * Private constructor to enforce singleton pattern.
	 */
	private function __construct() {}

	/**
	 * Get the singleton instance of the plugin container.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boot the plugin during the `plugins_loaded` action.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		if ( ! $this->check_environment() ) {
			return;
		}

		$this->init_hooks();
	}

	/**
	 * Verify that PHP version and WooCommerce dependencies are met.
	 *
	 * @return bool True if environment is capable; false otherwise.
	 */
	private function check_environment(): bool {
		if ( version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '<' ) ) {
			add_action( 'admin_notices', array( $this, 'render_php_version_notice' ) );

			return false;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'render_woocommerce_missing_notice' ) );

			return false;
		}

		return true;
	}

	/**
	 * Register core WordPress hooks and actions.
	 */
	private function init_hooks(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Load internationalization text domain.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'idta-r2-optimizer',
			false,
			dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Verify that the current user has the administrative capability.
	 *
	 * @return bool
	 */
	public function user_can_manage(): bool {
		return current_user_can( self::REQUIRED_CAPABILITY );
	}

	/**
	 * Check capability and terminate with 403 response if unauthorized.
	 *
	 * @throws \WP_Die_Exception If current user lacks permissions.
	 */
	public function check_capability(): void {
		if ( ! $this->user_can_manage() ) {
			wp_die(
				esc_html__( 'You do not have sufficient permissions to access the IDTA R2 Optimizer.', 'idta-r2-optimizer' ),
				esc_html__( 'Unauthorized Access', 'idta-r2-optimizer' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Create a standardized nonce for a specific action.
	 *
	 * @param string $action Specific action name.
	 * @return string Nonce token.
	 */
	public function create_nonce( string $action ): string {
		return wp_create_nonce( self::NONCE_ACTION_PREFIX . $action );
	}

	/**
	 * Verify a standardized nonce for a specific action.
	 *
	 * @param string $nonce  Nonce token to verify.
	 * @param string $action Specific action name.
	 * @return bool True if valid; false otherwise.
	 */
	public function verify_nonce( string $nonce, string $action ): bool {
		return (bool) wp_verify_nonce( $nonce, self::NONCE_ACTION_PREFIX . $action );
	}

	/**
	 * Retrieve all stored plugin settings, merged with sensible defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		if ( null !== $this->cached_settings ) {
			return $this->cached_settings;
		}

		$defaults = array(
			'account_id'        => '',
			'access_key_id'     => '',
			'secret_access_key' => '',
			'default_bucket'    => '',
			'custom_endpoint'   => '',
			'source_buckets'    => array(),
			'auto_purge_days'   => 0, // 0 = disabled
		);

		$stored = get_option( self::SETTINGS_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		// Support wp-config.php constant overrides for enterprise environments.
		if ( defined( 'IDTA_R2_ACCOUNT_ID' ) && is_string( IDTA_R2_ACCOUNT_ID ) ) {
			$stored['account_id'] = IDTA_R2_ACCOUNT_ID;
		}

		if ( defined( 'IDTA_R2_ACCESS_KEY' ) && is_string( IDTA_R2_ACCESS_KEY ) ) {
			$stored['access_key_id'] = IDTA_R2_ACCESS_KEY;
		}

		if ( defined( 'IDTA_R2_SECRET_KEY' ) && is_string( IDTA_R2_SECRET_KEY ) ) {
			$stored['secret_access_key'] = IDTA_R2_SECRET_KEY;
		}

		$this->cached_settings = wp_parse_args( $stored, $defaults );

		return $this->cached_settings;
	}

	/**
	 * Retrieve a single setting value.
	 *
	 * @param string $key     Setting key name.
	 * @param mixed  $default Fallback value if setting not found.
	 * @return mixed
	 */
	public function get_setting( string $key, mixed $default = null ): mixed {
		$all = $this->settings();

		return $all[ $key ] ?? $default;
	}

	/**
	 * Update plugin settings.
	 *
	 * @param array<string, mixed> $new_settings Settings to update.
	 * @return bool True if value changed; false if same or error.
	 */
	public function update_settings( array $new_settings ): bool {
		$this->cached_settings = null;

		return update_option( self::SETTINGS_OPTION, $new_settings );
	}

	/**
	 * Activation hook callback.
	 */
	public static function activate(): void {
		if ( version_compare( PHP_VERSION, self::MIN_PHP_VERSION, '<' ) ) {
			deactivate_plugins( plugin_basename( PLUGIN_FILE ) );
			wp_die(
				sprintf(
					/* translators: %s: Minimum PHP version */
					esc_html__( 'IDTA R2 Optimizer requires PHP %s or higher. Your server is running PHP %s.', 'idta-r2-optimizer' ),
					esc_html( self::MIN_PHP_VERSION ),
					esc_html( PHP_VERSION )
				)
			);
		}

		// Initialize default settings option if not existing.
		if ( false === get_option( self::SETTINGS_OPTION ) ) {
			add_option(
				self::SETTINGS_OPTION,
				array(
					'account_id'        => '',
					'access_key_id'     => '',
					'secret_access_key' => '',
					'default_bucket'    => '',
					'custom_endpoint'   => '',
					'source_buckets'    => array(),
					'auto_purge_days'   => 0,
				)
			);
		}
	}

	/**
	 * Deactivation hook callback.
	 */
	public static function deactivate(): void {
		delete_transient( 'idta_r2_scan_progress' );
		delete_transient( 'idta_r2_buckets_cache' );
	}

	/**
	 * Render notice if PHP version does not satisfy requirements.
	 */
	public function render_php_version_notice(): void {
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'IDTA R2 Optimizer is inactive:', 'idta-r2-optimizer' ); ?></strong>
				<?php
				printf(
					/* translators: 1: Required PHP version, 2: Current PHP version */
					esc_html__( 'This plugin requires PHP %1$s or higher. Your server is running PHP %2$s. Please update PHP to use this plugin.', 'idta-r2-optimizer' ),
					esc_html( self::MIN_PHP_VERSION ),
					esc_html( PHP_VERSION )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render notice if WooCommerce is inactive.
	 */
	public function render_woocommerce_missing_notice(): void {
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'IDTA R2 Optimizer is inactive:', 'idta-r2-optimizer' ); ?></strong>
				<?php esc_html_e( 'WooCommerce must be installed and active to use IDTA R2 Optimizer.', 'idta-r2-optimizer' ); ?>
			</p>
		</div>
		<?php
	}
}

