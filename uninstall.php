<?php
/**
 * Plugin uninstallation routine for IDTA R2 Optimizer.
 *
 * Removes stored options, cached transients, and the audit log table,
 * but only when the administrator has explicitly requested data removal via
 * the IDTA_R2_DELETE_DATA constant.
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * Keep data intact during standard updates and re-installations.
 *
 * Deleting the plugin via the WordPress admin screen is frequently performed
 * when upgrading or re-uploading a plugin zip archive. To permanently purge
 * settings, transients, and logs on uninstall, define the following constant
 * in wp-config.php before deleting:
 *
 *     define( 'IDTA_R2_DELETE_DATA', true );
 */
if ( ! defined( 'IDTA_R2_DELETE_DATA' ) || true !== IDTA_R2_DELETE_DATA ) {
	return;
}

global $wpdb;

// Remove audit logs table if present.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'idta_r2_logs' );

// Delete stored options.
$options = array(
	'idta_r2_settings',
	'idta_r2_version',
	'idta_r2_db_version',
);

foreach ( $options as $option_name ) {
	delete_option( $option_name );
}

// Clear temporary transients.
delete_transient( 'idta_r2_scan_progress' );
delete_transient( 'idta_r2_buckets_cache' );
delete_transient( 'idta_r2_order_index' );

