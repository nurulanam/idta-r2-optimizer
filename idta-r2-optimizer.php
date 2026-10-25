<?php
/**
 * Plugin Name:       IDTA R2 Optimizer
 * Plugin URI:        https://webclyde.com/
 * Description:       Audits, synchronizes, cleans, and optimizes customer verification document assets stored in Cloudflare R2 object storage for WooCommerce orders.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Webclyde LLC
 * Author URI:        https://webclyde.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       idta-r2-optimizer
 * Domain Path:       /languages
 *
 * WC requires at least: 7.0
 * WC tested up to:      9.9
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

namespace IDTA\R2Optimizer;

defined( 'ABSPATH' ) || exit;

const VERSION     = '1.0.0';
const PLUGIN_FILE = __FILE__;

require_once __DIR__ . '/includes/class-autoloader.php';

Autoloader::register( __DIR__ . '/includes' );

/**
 * Retrieve the central plugin service container.
 *
 * @return Plugin
 */
function plugin(): Plugin {
	return Plugin::instance();
}

/**
 * Declare full compatibility with WooCommerce High-Performance Order Storage (HPOS).
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				PLUGIN_FILE,
				true
			);
		}
	}
);

/**
 * Boot the plugin services once all active plugins are loaded.
 *
 * Priority 20 ensures WooCommerce and companion plugins (idta-pdf) are initialized.
 */
add_action( 'plugins_loaded', static fn() => plugin()->boot(), 20 );

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

