<?php
/**
 * Admin dashboard and settings page controller.
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

namespace IDTA\R2Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Controller for the dedicated R2 Optimizer administrative screens.
 */
class Admin_Page {

	/**
	 * Administrative screen slug.
	 */
	public const SLUG = 'idta-r2-optimizer';

	/**
	 * Admin hook suffix returned by add_menu_page.
	 *
	 * @var string|null
	 */
	private ?string $hook_suffix = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_save_settings' ) );

		// Register inspection AJAX endpoint.
		add_action( 'wp_ajax_idta_r2_inspect_folder', array( $this, 'ajax_inspect_folder' ) );
	}

	/**
	 * Register the dedicated top-level administrative menu entry in the WordPress dashboard.
	 */
	public function register_menu(): void {
		$page_title = __( 'IDTA R2 Optimizer', 'idta-r2-optimizer' );
		$menu_title = __( 'R2 Optimizer', 'idta-r2-optimizer' );
		$capability = Plugin::REQUIRED_CAPABILITY;

		$this->hook_suffix = add_menu_page(
			$page_title,
			$menu_title,
			$capability,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-cloud',
			56
		);

		// Submenu: Folder Explorer (defaults to main page).
		add_submenu_page(
			self::SLUG,
			__( 'Folder Explorer - IDTA R2 Optimizer', 'idta-r2-optimizer' ),
			__( 'Folder Explorer', 'idta-r2-optimizer' ),
			$capability,
			self::SLUG,
			array( $this, 'render' )
		);

		// Submenu: Settings & Bucket Mapping.
		add_submenu_page(
			self::SLUG,
			__( 'Settings - IDTA R2 Optimizer', 'idta-r2-optimizer' ),
			__( 'Settings', 'idta-r2-optimizer' ),
			$capability,
			self::SLUG . '-settings',
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Render the settings view when accessed via the dedicated submenu item.
	 */
	public function render_settings(): void {
		$_GET['tab'] = 'settings';
		$this->render();
	}

	/**
	 * Enqueue scoped administrative styles and scripts on the plugin screen.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( $hook !== $this->hook_suffix && false === strpos( $hook, self::SLUG ) ) {
			return;
		}

		$plugin_url = plugin_dir_url( PLUGIN_FILE );
		$version    = VERSION;

		// Enqueue styles.
		wp_enqueue_style(
			'idta-r2-admin',
			$plugin_url . 'assets/css/admin.css',
			array(),
			$version
		);

		// Enqueue scripts.
		wp_enqueue_script(
			'idta-r2-admin',
			$plugin_url . 'assets/js/admin.js',
			array( 'jquery' ),
			$version,
			true
		);

		// Localize script with AJAX endpoints, nonces, and translations.
		wp_localize_script(
			'idta-r2-admin',
			'idtaR2Data',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonces'  => array(
					'testConnection' => plugin()->create_nonce( 'test_connection' ),
					'scan'           => plugin()->create_nonce( 'scan' ),
					'inspect'        => plugin()->create_nonce( 'inspect' ),
					'delete'         => plugin()->create_nonce( 'delete' ),
					'remap'          => plugin()->create_nonce( 'remap' ),
					'logs'           => plugin()->create_nonce( 'logs' ),
					'exportLogs'     => plugin()->create_nonce( 'export_logs' ),
				),
				'i18n'    => array(
					'testingConnection'      => __( 'Connecting to Cloudflare R2...', 'idta-r2-optimizer' ),
					'connectionSuccess'      => __( 'Connection successful!', 'idta-r2-optimizer' ),
					'connectionFailed'       => __( 'Connection failed', 'idta-r2-optimizer' ),
					'scanning'               => __( 'Scanning...', 'idta-r2-optimizer' ),
					'scanComplete'           => __( 'Scan complete!', 'idta-r2-optimizer' ),
					'scanCancelled'          => __( 'Scan reset', 'idta-r2-optimizer' ),
					'foldersFound'           => __( 'folders discovered', 'idta-r2-optimizer' ),
					'noFolders'              => __( 'No folders match the selected filters.', 'idta-r2-optimizer' ),
					'inspectError'           => __( 'Failed to load folder details from R2.', 'idta-r2-optimizer' ),
					'missingFile'            => __( 'Missing File', 'idta-r2-optimizer' ),
					'download'               => __( 'Download', 'idta-r2-optimizer' ),
					'confirmDelete'          => __( 'Are you sure you want to delete this folder from Cloudflare R2? This action cannot be undone.', 'idta-r2-optimizer' ),
					'confirmConnectedDelete' => __( 'WARNING: This folder is attached to an active WooCommerce order. Deleting it will permanently remove customer verification documents. Are you sure you want to force delete?', 'idta-r2-optimizer' ),
					'confirmBulkDelete'      => __( 'Are you sure you want to delete the selected orphaned folders from Cloudflare R2?', 'idta-r2-optimizer' ),
					'deleteSuccess'          => __( 'Folder successfully deleted from R2.', 'idta-r2-optimizer' ),
					'remapSuccess'           => __( 'Folder successfully linked to WooCommerce order!', 'idta-r2-optimizer' ),
					'enterOrderId'           => __( 'Enter the WooCommerce Order ID to link this folder to:', 'idta-r2-optimizer' ),
					'deleting'               => __( 'Deleting...', 'idta-r2-optimizer' ),
					'remapping'              => __( 'Linking...', 'idta-r2-optimizer' ),
				),
			)
		);
	}

	/**
	 * Handle administrative settings form submission.
	 */
	public function handle_save_settings(): void {
		if (
			! isset( $_POST['action'], $_POST['idta_r2_settings_nonce'] ) ||
			'idta_r2_save_settings' !== $_POST['action']
		) {
			return;
		}

		if ( ! current_user_can( Plugin::REQUIRED_CAPABILITY ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'idta-r2-optimizer' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'idta_r2_save_settings', 'idta_r2_settings_nonce' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_data = isset( $_POST['idta_r2'] ) && is_array( $_POST['idta_r2'] ) ? wp_unslash( $_POST['idta_r2'] ) : array();

		$current = plugin()->settings();

		// Update fields.
		$account_id     = sanitize_text_field( (string) ( $raw_data['account_id'] ?? '' ) );
		$access_key_id  = sanitize_text_field( (string) ( $raw_data['access_key_id'] ?? '' ) );
		$default_bucket = sanitize_text_field( (string) ( $raw_data['default_bucket'] ?? '' ) );
		$endpoint       = esc_url_raw( (string) ( $raw_data['custom_endpoint'] ?? '' ) );

		// Handle Secret Key (keep existing if empty).
		$secret_key = sanitize_text_field( (string) ( $raw_data['secret_access_key'] ?? '' ) );
		if ( '' === $secret_key && ! empty( $current['secret_access_key'] ) ) {
			$secret_key = $current['secret_access_key'];
		}

		// Handle source mappings.
		$source_buckets = array();
		if ( isset( $raw_data['source_buckets'] ) && is_array( $raw_data['source_buckets'] ) ) {
			foreach ( $raw_data['source_buckets'] as $src => $bkt ) {
				$clean_src = strtolower( sanitize_key( (string) $src ) );
				$clean_bkt = sanitize_text_field( (string) $bkt );
				if ( '' !== $clean_bkt ) {
					$source_buckets[ $clean_src ] = $clean_bkt;
				}
			}
		}

		$schedule_frequency = sanitize_key( (string) ( $raw_data['schedule_frequency'] ?? 'daily' ) );
		$alert_threshold_gb = (float) ( $raw_data['alert_threshold_gb'] ?? 5.0 );
		$auto_purge_days    = (int) ( $raw_data['auto_purge_days'] ?? 0 );

		$updated = array(
			'account_id'         => $account_id,
			'access_key_id'      => $access_key_id,
			'secret_access_key'  => $secret_key,
			'default_bucket'     => $default_bucket,
			'custom_endpoint'    => $endpoint,
			'source_buckets'     => $source_buckets,
			'schedule_frequency' => $schedule_frequency,
			'alert_threshold_gb' => $alert_threshold_gb,
			'auto_purge_days'    => $auto_purge_days,
		);

		plugin()->update_settings( $updated );

		// Redirect with notice flag.
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::SLUG,
					'tab'     => 'settings',
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * AJAX endpoint to retrieve detailed previews and metadata for a single folder.
	 */
	public function ajax_inspect_folder(): void {
		if ( ! plugin()->user_can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'idta-r2-optimizer' ) ), 403 );
		}

		check_ajax_referer( Plugin::NONCE_ACTION_PREFIX . 'inspect', 'nonce' );

		$bucket = isset( $_POST['bucket'] ) ? sanitize_text_field( wp_unslash( $_POST['bucket'] ) ) : '';
		$prefix = isset( $_POST['prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['prefix'] ) ) : '';

		if ( '' === $bucket || '' === $prefix ) {
			wp_send_json_error( array( 'message' => __( 'Invalid bucket or folder prefix.', 'idta-r2-optimizer' ) ) );
		}

		$folder_data = plugin()->folder_scanner()->scan_single_folder( $bucket, $prefix );
		$record      = plugin()->connection_analyzer()->analyze_folder( $bucket, $folder_data );

		// If no linked order was cached, check if an order was recently placed/updated in database.
		if ( null === $record->order ) {
			$clean_path  = trim( str_replace( array( '\\/', '\\' ), '/', $prefix ), '/' );
			$fresh_order = plugin()->order_indexer()->find_order_by_folder( $clean_path, true );
			if ( null !== $fresh_order ) {
				$record = plugin()->connection_analyzer()->analyze_folder( $bucket, $folder_data );
			}
		}

		// Generate signed preview URLs for document images.
		$signer   = new R2_Signer(
			(string) plugin()->get_setting( 'access_key_id' ),
			(string) plugin()->get_setting( 'secret_access_key' )
		);
		$endpoint = plugin()->r2()->get_endpoint_base();

		$previews = array();
		$expected = plugin()->source_mapper()->get_expected_asset_files();

		foreach ( $expected as $slot => $filename ) {
			$file_key   = rtrim( $prefix, '/' ) . '/' . $filename;
			$is_present = false;
			$file_meta  = null;

			foreach ( $folder_data['files'] as $f ) {
				if ( strtolower( basename( (string) $f['key'] ) ) === strtolower( $filename ) ) {
					$is_present = true;
					$file_meta  = $f;
					break;
				}
			}

			$preview_url = '';
			if ( $is_present ) {
				$full_obj_url = $endpoint . '/' . $bucket . '/' . $file_key;
				try {
					$preview_url = $signer->get_presigned_url( 'GET', $full_obj_url, 3600 );
				} catch ( \Throwable ) {
					$preview_url = '';
				}
			}

			$previews[ $slot ] = array(
				'slot'        => $slot,
				'filename'    => $filename,
				'is_present'  => $is_present,
				'preview_url' => $preview_url,
				'size'        => $file_meta ? (int) $file_meta['size'] : 0,
			);
		}

		wp_send_json_success(
			array(
				'folder'   => $record->to_array(),
				'previews' => $previews,
			)
		);
	}

	/**
	 * Render the administrative page view.
	 */
	public function render(): void {
		plugin()->check_capability();

		// Determine active tab.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab = isset( $_GET['tab'] ) && 'settings' === $_GET['tab'] ? 'settings' : 'explorer';

		$settings = plugin()->settings();
		?>
		<div class="wrap idta-r2-wrap">
			<h1 class="wp-heading-inline">
				<span class="dashicons dashicons-cloud"></span>
				<?php esc_html_e( 'IDTA R2 Optimizer', 'idta-r2-optimizer' ); ?>
			</h1>

			<!-- Settings Saved Notice -->
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $_GET['updated'] ) ) :
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved successfully.', 'idta-r2-optimizer' ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Navigation Tabs -->
			<nav class="nav-tab-wrapper wp-clearfix">
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'explorer' ), admin_url( 'admin.php' ) ) ); ?>"
					class="nav-tab <?php echo 'explorer' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-search"></span>
					<?php esc_html_e( 'Folder Explorer', 'idta-r2-optimizer' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'settings' ), admin_url( 'admin.php' ) ) ); ?>"
					class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<span class="dashicons dashicons-admin-settings"></span>
					<?php esc_html_e( 'Settings & Bucket Mapping', 'idta-r2-optimizer' ); ?>
				</a>
			</nav>

			<div class="idta-r2-tab-content">
				<?php
				if ( 'settings' === $active_tab ) {
					$source_mappings   = plugin()->source_mapper()->get_source_bucket_mappings();
					$available_buckets = array();
					require_once PLUGIN_DIR . '/templates/admin/settings.php';
				} else {
					$active_bucket     = (string) ( $settings['default_bucket'] ?? 'idta-html' );
					$available_buckets = array( $active_bucket );
					$available_years   = plugin()->folder_scanner()->get_available_years( $active_bucket );
					if ( empty( $available_years ) ) {
						$available_years = array( (string) gmdate( 'Y' ) );
					}
					$scan_state = plugin()->connection_analyzer()->get_scan_progress();
					$metrics    = $scan_state['metrics'] ?? array();
					require_once PLUGIN_DIR . '/templates/admin/dashboard.php';
				}
				?>
			</div>
		</div>
		<?php
	}
}

