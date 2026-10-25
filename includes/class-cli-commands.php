<?php
/**
 * WP-CLI integration and command suite for Cloudflare R2 operations.
 *
 * Provides command-line automation for connectivity testing, bucket listing,
 * folder scanning, batch cleanup with dry-run safety, and order remapping.
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

namespace IDTA\R2Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Cloudflare R2 storage optimization and WooCommerce order document associations.
 */
class CLI_Commands {

	/**
	 * Test Cloudflare R2 API connection and credentials.
	 *
	 * ## EXAMPLES
	 *
	 *     wp r2-optimizer test
	 *
	 * @subcommand test
	 */
	public function test( array $args, array $assoc_args ): void {
		\WP_CLI::line( 'Testing Cloudflare R2 connectivity...' );

		$client = plugin()->r2();
		$result = $client->test_connection();

		if ( $result['success'] ) {
			\WP_CLI::success(
				sprintf(
					'Connection successful! Account ID: %s. Found %d bucket(s).',
					$client->get_account_id(),
					$result['bucket_count'] ?? 0
				)
			);
		} else {
			\WP_CLI::error( 'Connection failed: ' . ( $result['message'] ?? 'Unknown error' ) );
		}
	}

	/**
	 * List all Cloudflare R2 buckets in the account.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output format (table, json, csv, yaml).
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp r2-optimizer list-buckets
	 *     wp r2-optimizer list-buckets --format=json
	 *
	 * @subcommand list-buckets
	 */
	public function list_buckets( array $args, array $assoc_args ): void {
		$client  = plugin()->r2();
		$buckets = $client->list_buckets();

		if ( empty( $buckets ) ) {
			\WP_CLI::warning( 'No buckets found or credentials lack permissions.' );

			return;
		}

		$items = array();
		foreach ( $buckets as $b ) {
			$items[] = array(
				'Bucket Name'  => $b->name,
				'Created Date' => $b->formatted_creation_date(),
			);
		}

		$format = $assoc_args['format'] ?? 'table';
		\WP_CLI\Utils\format_items( $format, $items, array( 'Bucket Name', 'Created Date' ) );
	}

	/**
	 * Scan an R2 bucket and analyze connection status against WooCommerce orders.
	 *
	 * ## OPTIONS
	 *
	 * [--bucket=<name>]
	 * : Target R2 bucket name. Defaults to configured default bucket.
	 *
	 * [--prefix=<prefix>]
	 * : Optional date prefix to filter scan (e.g. 2026/ or 2026/08/).
	 *
	 * [--format=<format>]
	 * : Render output format for folder items (table, json, csv, count).
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp r2-optimizer scan
	 *     wp r2-optimizer scan --bucket=idta-html --prefix=2026/
	 *
	 * @subcommand scan
	 */
	public function scan( array $args, array $assoc_args ): void {
		$bucket = $assoc_args['bucket'] ?? (string) plugin()->get_setting( 'default_bucket', '' );
		if ( '' === $bucket ) {
			$buckets = plugin()->r2()->list_buckets();
			if ( ! empty( $buckets ) ) {
				$bucket = $buckets[0]->name;
			}
		}

		if ( '' === $bucket ) {
			\WP_CLI::error( 'Target bucket not specified and no default bucket configured.' );
		}

		$prefix = isset( $assoc_args['prefix'] ) ? (string) $assoc_args['prefix'] : '';

		\WP_CLI::line( sprintf( 'Scanning bucket "%s" (prefix: "%s")...', $bucket, $prefix ?: 'All' ) );

		$analyzer = plugin()->connection_analyzer();
		$folders  = $analyzer->scan_and_analyze( $bucket, $prefix );
		$metrics  = $analyzer->calculate_metrics( $folders );

		// Output summary KPI
		\WP_CLI::line( '----------------------------------------' );
		\WP_CLI::line( sprintf( 'Total Folders Scanned: %d (%s)', $metrics['total_folders'], $metrics['formatted_total_size'] ) );
		\WP_CLI::line( sprintf( 'Connected Orders:      %d (%s)', $metrics['connected_count'], $metrics['formatted_connected_size'] ) );
		\WP_CLI::line( sprintf( 'Orphaned Folders:      %d (%s)', $metrics['orphaned_count'], $metrics['formatted_orphaned_size'] ) );
		\WP_CLI::line( sprintf( 'Incomplete Folders:    %d (%s)', $metrics['broken_count'], $metrics['formatted_broken_size'] ) );
		\WP_CLI::line( sprintf( 'Reclaimable Savings:   $%s / month', number_format( (float) $metrics['estimated_monthly_savings'], 2 ) ) );
		\WP_CLI::line( '----------------------------------------' );

		$format = $assoc_args['format'] ?? 'table';
		if ( 'count' === $format ) {
			return;
		}

		$items = array();
		foreach ( $folders as $f ) {
			$items[] = array(
				'Status'        => strtoupper( $f->status->value ),
				'Folder Prefix' => $f->prefix,
				'Order ID'      => $f->order ? '#' . $f->order->order_id : '—',
				'Customer'      => $f->order ? $f->order->customer_name : '—',
				'Files'         => $f->file_count,
				'Size'          => $f->formatted_size(),
				'Last Modified' => $f->formatted_last_modified(),
			);
		}

		\WP_CLI\Utils\format_items(
			$format,
			$items,
			array( 'Status', 'Folder Prefix', 'Order ID', 'Customer', 'Files', 'Size', 'Last Modified' )
		);
	}

	/**
	 * Clean orphaned folders with dry-run safety and age-based retention filtering.
	 *
	 * ## OPTIONS
	 *
	 * [--bucket=<name>]
	 * : Target R2 bucket name. Defaults to configured default bucket.
	 *
	 * [--days=<n>]
	 * : Only target orphaned folders older than N days.
	 *
	 * [--dry-run]
	 * : Simulate deletion and report reclaimable space without deleting.
	 * ---
	 * default: true
	 * ---
	 *
	 * [--yes]
	 * : Bypass interactive confirmation when running destructive cleanup.
	 *
	 * ## EXAMPLES
	 *
	 *     wp r2-optimizer clean --dry-run
	 *     wp r2-optimizer clean --no-dry-run --days=60
	 *     wp r2-optimizer clean --no-dry-run --yes
	 *
	 * @subcommand clean
	 */
	public function clean( array $args, array $assoc_args ): void {
		$bucket = $assoc_args['bucket'] ?? (string) plugin()->get_setting( 'default_bucket', '' );
		if ( '' === $bucket ) {
			$buckets = plugin()->r2()->list_buckets();
			if ( ! empty( $buckets ) ) {
				$bucket = $buckets[0]->name;
			}
		}

		if ( '' === $bucket ) {
			\WP_CLI::error( 'Target bucket not specified and no default bucket configured.' );
		}

		$is_dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', true );
		$days_limit = isset( $assoc_args['days'] ) ? (int) $assoc_args['days'] : 0;

		\WP_CLI::line( sprintf( 'Discovering orphaned folders in bucket "%s"...', $bucket ) );

		$analyzer = plugin()->connection_analyzer();
		$folders  = $analyzer->scan_and_analyze( $bucket );

		$orphaned_candidates = array();
		$cutoff_time         = $days_limit > 0 ? ( time() - ( $days_limit * DAY_IN_SECONDS ) ) : 0;

		foreach ( $folders as $folder ) {
			if ( Connection_Status::ORPHANED !== $folder->status ) {
				continue;
			}

			if ( $cutoff_time > 0 ) {
				$mod_time = strtotime( $folder->last_modified );
				if ( false !== $mod_time && $mod_time >= $cutoff_time ) {
					continue; // Newer than cutoff, skip.
				}
			}

			$orphaned_candidates[] = $folder->prefix;
		}

		if ( empty( $orphaned_candidates ) ) {
			\WP_CLI::success( 'No orphaned folders matching criteria found to clean.' );

			return;
		}

		\WP_CLI::line( sprintf( 'Found %d candidate orphaned folder(s).', count( $orphaned_candidates ) ) );

		if ( $is_dry_run ) {
			\WP_CLI::line( 'Executing in DRY-RUN mode (no files will be deleted)...' );
			$res = plugin()->folder_manager()->bulk_delete_orphaned( $bucket, $orphaned_candidates, true );

			$mb_reclaimed = number_format( $res['bytes_reclaimed'] / ( 1024 * 1024 ), 2 );
			\WP_CLI::success(
				sprintf(
					'Dry-run complete: %d folder(s), %d file(s) would be deleted, reclaiming %s MB.',
					$res['folders_count'],
					$res['files_count'],
					$mb_reclaimed
				)
			);
			\WP_CLI::line( 'Run with "--no-dry-run" to permanently delete.' );

			return;
		}

		// Destructive Deletion
		if ( ! isset( $assoc_args['yes'] ) ) {
			\WP_CLI::confirm(
				sprintf(
					'Are you sure you want to PERMANENTLY delete %d orphaned folder(s) from Cloudflare R2?',
					count( $orphaned_candidates )
				)
			);
		}

		\WP_CLI::line( 'Deleting orphaned objects from Cloudflare R2...' );
		$res = plugin()->folder_manager()->bulk_delete_orphaned( $bucket, $orphaned_candidates, false );

		$mb_reclaimed = number_format( $res['bytes_reclaimed'] / ( 1024 * 1024 ), 2 );
		\WP_CLI::success(
			sprintf(
				'Cleanup complete: %d orphaned folder(s) deleted, %d file(s) removed, %s MB reclaimed.',
				$res['folders_count'],
				$res['files_count'],
				$mb_reclaimed
			)
		);
	}

	/**
	 * Link/remap an orphaned R2 folder prefix to a WooCommerce order.
	 *
	 * ## OPTIONS
	 *
	 * --folder=<path>
	 * : The R2 folder prefix path (e.g. 2026/08/01/063701-213/).
	 *
	 * --order-id=<id>
	 * : The WooCommerce Order ID to link.
	 *
	 * ## EXAMPLES
	 *
	 *     wp r2-optimizer remap --folder=2026/08/01/063701-213/ --order-id=12345
	 *
	 * @subcommand remap
	 */
	public function remap( array $args, array $assoc_args ): void {
		$folder   = (string) ( $assoc_args['folder'] ?? '' );
		$order_id = (int) ( $assoc_args['order-id'] ?? 0 );

		if ( '' === $folder || $order_id <= 0 ) {
			\WP_CLI::error( 'Both --folder and --order-id parameters are required.' );
		}

		try {
			$res = plugin()->folder_manager()->remap_folder( $folder, $order_id );
			\WP_CLI::success(
				sprintf(
					'Folder "%s" successfully linked to Order #%d (%s).',
					$res['folder_path'],
					$res['order_id'],
					$res['customer_name']
				)
			);
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * View recent operations audit trail logs.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Number of log rows to retrieve.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--action=<action>]
	 * : Filter by action type (DELETE_ORPHAN, FORCE_DELETE, BATCH_CLEANUP, REMAP_ORDER).
	 *
	 * [--format=<format>]
	 * : Render output format (table, json, csv).
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp r2-optimizer logs
	 *     wp r2-optimizer logs --limit=50 --action=BATCH_CLEANUP
	 *
	 * @subcommand logs
	 */
	public function logs( array $args, array $assoc_args ): void {
		$limit  = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 20;
		$action = isset( $assoc_args['action'] ) ? (string) $assoc_args['action'] : null;

		$logger = plugin()->audit_logger();
		$logs   = $logger->get_logs( $limit, 0, $action );

		if ( empty( $logs ) ) {
			\WP_CLI::line( 'No audit logs found.' );

			return;
		}

		$items = array();
		foreach ( $logs as $l ) {
			$items[] = array(
				'ID'        => $l['id'],
				'Timestamp' => $l['timestamp'],
				'User'      => $l['user_display_name'],
				'Action'    => $l['action_type'],
				'Path'      => $l['folder_path'],
				'Order'     => $l['order_id'] ? '#' . $l['order_id'] : '—',
				'Status'    => $l['status'],
			);
		}

		$format = $assoc_args['format'] ?? 'table';
		\WP_CLI\Utils\format_items(
			$format,
			$items,
			array( 'ID', 'Timestamp', 'User', 'Action', 'Path', 'Order', 'Status' )
		);
	}
}

