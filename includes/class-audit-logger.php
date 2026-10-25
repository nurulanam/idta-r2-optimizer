<?php
/**
 * Audit trail and safety logging service for R2 data manipulation.
 *
 * Persists all deletion, remapping, and configuration actions to MySQL,
 * maintaining a secure audit trail with CSV export capabilities.
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

namespace IDTA\R2Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Handles database logging of all destructive and modification operations.
 */
class Audit_Logger {

	/**
	 * Log table name without database prefix.
	 */
	public const TABLE_NAME = 'idta_r2_logs';

	/**
	 * Database version for table schema upgrades.
	 */
	public const DB_VERSION = '1.0.0';

	/**
	 * Option key storing the installed database version.
	 */
	public const DB_VERSION_OPTION = 'idta_r2_db_version';

	/**
	 * Action types.
	 */
	public const ACTION_DELETE_ORPHAN = 'DELETE_ORPHAN';
	public const ACTION_FORCE_DELETE  = 'FORCE_DELETE';
	public const ACTION_BATCH_CLEANUP = 'BATCH_CLEANUP';
	public const ACTION_REMAP_ORDER   = 'REMAP_ORDER';
	public const ACTION_UPDATE_PATH   = 'UPDATE_PATH';

	/**
	 * Get the full prefixed table name.
	 *
	 * @return string Full table name.
	 */
	public static function get_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Ensure the audit log database table exists with the current schema.
	 */
	public static function create_table_if_needed(): void {
		global $wpdb;

		$table_name = self::get_table_name();
		$installed  = get_option( self::DB_VERSION_OPTION, '0.0.0' );

		if ( version_compare( (string) $installed, self::DB_VERSION, '>=' ) ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_display_name varchar(250) NOT NULL DEFAULT '',
			action_type varchar(50) NOT NULL,
			bucket varchar(100) NOT NULL DEFAULT '',
			folder_path text NOT NULL,
			order_id bigint(20) unsigned DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'success',
			details longtext DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY action_type (action_type),
			KEY order_id (order_id),
			KEY timestamp (timestamp)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Record an entry into the audit trail log.
	 *
	 * @param string               $action_type Action type constant.
	 * @param string               $folder_path R2 folder prefix or multiple paths.
	 * @param string               $bucket      Target R2 bucket name.
	 * @param int|null             $order_id    Linked WooCommerce Order ID if applicable.
	 * @param string               $status      Outcome status ('success', 'failed', 'dry_run').
	 * @param array<string, mixed> $details      Additional structured metadata.
	 * @return int Inserted row ID, or 0 on failure.
	 */
	public function log(
		string $action_type,
		string $folder_path,
		string $bucket = '',
		?int $order_id = null,
		string $status = 'success',
		array $details = array()
	): int {
		global $wpdb;

		self::create_table_if_needed();

		$current_user = wp_get_current_user();
		$user_id      = $current_user->exists() ? (int) $current_user->ID : 0;
		$user_name    = $current_user->exists() ? (string) $current_user->display_name : 'System';

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table_name,
			array(
				'timestamp'         => current_time( 'mysql', true ),
				'user_id'           => $user_id,
				'user_display_name' => $user_name,
				'action_type'       => sanitize_key( $action_type ),
				'bucket'            => sanitize_text_field( $bucket ),
				'folder_path'       => sanitize_text_field( $folder_path ),
				'order_id'          => $order_id ? (int) $order_id : null,
				'status'            => sanitize_key( $status ),
				'details'           => ! empty( $details ) ? wp_json_encode( $details ) : null,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return false !== $result ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Query recent audit log entries.
	 *
	 * @param int         $limit       Number of rows to fetch.
	 * @param int         $offset      Pagination offset.
	 * @param string|null $action_type Optional filter by action type.
	 * @return array<int, array<string, mixed>> List of log row arrays.
	 */
	public function get_logs( int $limit = 50, int $offset = 0, ?string $action_type = null ): array {
		global $wpdb;

		self::create_table_if_needed();

		$table_name = self::get_table_name();
		$limit      = min( max( 1, $limit ), 500 );
		$offset     = max( 0, $offset );

		if ( ! empty( $action_type ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table_name} WHERE action_type = %s ORDER BY id DESC LIMIT %d OFFSET %d",
					sanitize_key( $action_type ),
					$limit,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table_name} ORDER BY id DESC LIMIT %d OFFSET %d",
					$limit,
					$offset
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get total count of log entries.
	 *
	 * @param string|null $action_type Optional action filter.
	 * @return int Total records count.
	 */
	public function get_total_count( ?string $action_type = null ): int {
		global $wpdb;

		self::create_table_if_needed();

		$table_name = self::get_table_name();

		if ( ! empty( $action_type ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table_name} WHERE action_type = %s",
					sanitize_key( $action_type )
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
	}

	/**
	 * Export all audit logs as a downloadable CSV.
	 */
	public function export_csv(): void {
		if ( ! current_user_can( Plugin::REQUIRED_CAPABILITY ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'idta-r2-optimizer' ), '', array( 'response' => 403 ) );
		}

		$filename = sprintf( 'idta-r2-audit-logs-%s.csv', gmdate( 'Y-m-d-His' ) );

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			exit;
		}

		// UTF-8 BOM for Excel compatibility.
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		// Header row.
		fputcsv(
			$output,
			array(
				'Log ID',
				'Timestamp (UTC)',
				'User ID',
				'User Display Name',
				'Action Type',
				'Bucket',
				'Folder Path',
				'Linked Order ID',
				'Status',
				'Details (JSON)',
			)
		);

		$batch_size = 500;
		$offset     = 0;

		while ( true ) {
			$logs = $this->get_logs( $batch_size, $offset );
			if ( empty( $logs ) ) {
				break;
			}

			foreach ( $logs as $log ) {
				fputcsv(
					$output,
					array(
						$log['id'],
						$log['timestamp'],
						$log['user_id'],
						$log['user_display_name'],
						$log['action_type'],
						$log['bucket'],
						$log['folder_path'],
						$log['order_id'] ?? '',
						$log['status'],
						$log['details'] ?? '',
					)
				);
			}

			$offset += $batch_size;
		}

		fclose( $output );
		exit;
	}
}

