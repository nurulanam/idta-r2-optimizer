<?php
/**
 * Folder data manipulation, cleanup, and remapping suite.
 *
 * Provides single folder deletion with safety guardrails, bulk cleanup
 * with dry-run protection, and order remapping/metadata updating.
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

namespace IDTA\R2Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Executes deletion, batch cleanup, and order remapping actions.
 */
class Folder_Manager {

	/**
	 * R2 client.
	 *
	 * @var R2_Client
	 */
	protected R2_Client $client;

	/**
	 * Order indexer.
	 *
	 * @var Order_Indexer
	 */
	protected Order_Indexer $indexer;

	/**
	 * Folder scanner.
	 *
	 * @var Folder_Scanner
	 */
	protected Folder_Scanner $scanner;

	/**
	 * Connection analyzer.
	 *
	 * @var Connection_Analyzer
	 */
	protected Connection_Analyzer $analyzer;

	/**
	 * Audit logger.
	 *
	 * @var Audit_Logger
	 */
	protected Audit_Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param R2_Client|null           $client   R2 client instance.
	 * @param Order_Indexer|null       $indexer  Order indexer instance.
	 * @param Folder_Scanner|null      $scanner  Folder scanner instance.
	 * @param Connection_Analyzer|null $analyzer Connection analyzer instance.
	 * @param Audit_Logger|null        $logger   Audit logger instance.
	 */
	public function __construct(
		?R2_Client $client = null,
		?Order_Indexer $indexer = null,
		?Folder_Scanner $scanner = null,
		?Connection_Analyzer $analyzer = null,
		?Audit_Logger $logger = null
	) {
		$this->client   = $client ?? plugin()->r2();
		$this->indexer  = $indexer ?? plugin()->order_indexer();
		$this->scanner  = $scanner ?? plugin()->folder_scanner();
		$this->analyzer = $analyzer ?? plugin()->connection_analyzer();
		$this->logger   = $logger ?? plugin()->audit_logger();
	}

	/**
	 * Delete a single folder prefix and all contained objects from Cloudflare R2.
	 *
	 * Includes hard safety guardrail: refuses deletion if folder is linked to an active order
	 * unless $force is explicitly set to true by an administrator.
	 *
	 * @param string $bucket        Target R2 bucket name.
	 * @param string $folder_prefix Folder prefix path (e.g. '2026/08/01/063701-213/').
	 * @param bool   $force         Whether to bypass safety check on connected orders.
	 * @return array{success: bool, deleted_count: int, bytes_reclaimed: int, keys: string[]}
	 *
	 * @throws \RuntimeException If safety check fails or R2 deletion errors.
	 */
	public function delete_folder( string $bucket, string $folder_prefix, bool $force = false ): array {
		$clean_prefix = rtrim( $folder_prefix, '/' ) . '/';
		$clean_path   = trim( $folder_prefix, '/' );

		// Safety Guardrail: Check for connected WooCommerce order.
		$linked_order = $this->indexer->find_order_by_folder( $clean_path );
		if ( null !== $linked_order && ! $force ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: WooCommerce Order ID */
					__( 'Safety Guardrail Blocked Deletion: Folder is linked to Order #%d. To force deletion, check the admin override option.', 'idta-r2-optimizer' ),
					$linked_order->order_id
				)
			);
		}

		// Discover all files inside folder prefix.
		$folder_data = $this->scanner->scan_single_folder( $bucket, $clean_prefix );
		$files       = $folder_data['files'];
		$keys        = array_map( static fn( $f ) => (string) $f['key'], $files );
		$bytes       = $folder_data['total_size'];

		if ( ! empty( $keys ) ) {
			$batch_res = $this->client->delete_objects_batch( $bucket, $keys );
			if ( ! empty( $batch_res['errors'] ) ) {
				$first_err = $batch_res['errors'][0];
				throw new \RuntimeException(
					sprintf( 'R2 Deletion error on key %s: %s', $first_err['key'], $first_err['message'] )
				);
			}
		}

		// Log destructive operation.
		$action_type = null !== $linked_order ? Audit_Logger::ACTION_FORCE_DELETE : Audit_Logger::ACTION_DELETE_ORPHAN;
		$this->logger->log(
			$action_type,
			$clean_prefix,
			$bucket,
			$linked_order?->order_id,
			'success',
			array(
				'keys_deleted'    => count( $keys ),
				'bytes_reclaimed' => $bytes,
				'forced'          => $force,
			)
		);

		// Invalidate / update scan state in transients.
		$this->remove_folder_from_scan_state( $clean_prefix );

		return array(
			'success'         => true,
			'deleted_count'   => count( $keys ),
			'bytes_reclaimed' => $bytes,
			'keys'            => $keys,
		);
	}

	/**
	 * Bulk delete orphaned folders with dry-run preview and strict order safety verification.
	 *
	 * @param string        $bucket          Target R2 bucket name.
	 * @param array<string> $folder_prefixes Array of folder prefixes to delete.
	 * @param bool          $dry_run         If true, simulates deletion without making destructive changes.
	 * @return array{success: bool, dry_run: bool, folders_count: int, files_count: int, bytes_reclaimed: int, folders: array}
	 */
	public function bulk_delete_orphaned(
		string $bucket,
		array $folder_prefixes,
		bool $dry_run = false
	): array {
		$valid_orphans   = array();
		$total_bytes     = 0;
		$total_files     = 0;
		$queued_keys_map = array();

		// Strict Safety Check: verify each folder against the order index.
		foreach ( $folder_prefixes as $prefix ) {
			$clean_prefix = rtrim( (string) $prefix, '/' ) . '/';
			$clean_path   = trim( (string) $prefix, '/' );

			if ( null !== $this->indexer->find_order_by_folder( $clean_path ) ) {
				// Skip connected folder completely — never delete in bulk orphaned action!
				continue;
			}

			$folder_data = $this->scanner->scan_single_folder( $bucket, $clean_prefix );
			$files       = $folder_data['files'];
			$keys        = array_map( static fn( $f ) => (string) $f['key'], $files );

			$valid_orphans[]                = $clean_prefix;
			$queued_keys_map[ $clean_prefix ] = $keys;
			$total_bytes                   += (int) $folder_data['total_size'];
			$total_files                   += count( $keys );
		}

		// Dry-Run Mode: return preview without deleting.
		if ( $dry_run ) {
			$this->logger->log(
				Audit_Logger::ACTION_BATCH_CLEANUP,
				implode( ', ', $valid_orphans ),
				$bucket,
				null,
				'dry_run',
				array(
					'folders_count'   => count( $valid_orphans ),
					'files_count'     => $total_files,
					'bytes_reclaimed' => $total_bytes,
				)
			);

			return array(
				'success'         => true,
				'dry_run'         => true,
				'folders_count'   => count( $valid_orphans ),
				'files_count'     => $total_files,
				'bytes_reclaimed' => $total_bytes,
				'folders'         => $valid_orphans,
			);
		}

		// Destructive Deletion: execute deletion in batches.
		$all_keys = array();
		foreach ( $queued_keys_map as $p => $keys ) {
			$all_keys = array_merge( $all_keys, $keys );
		}

		// Delete objects in S3 chunks of up to 1,000 keys.
		$chunks = array_chunk( $all_keys, 1000 );
		foreach ( $chunks as $chunk ) {
			$this->client->delete_objects_batch( $bucket, $chunk );
		}

		// Log batch cleanup in audit trail.
		$this->logger->log(
			Audit_Logger::ACTION_BATCH_CLEANUP,
			sprintf( '%d orphaned folders deleted', count( $valid_orphans ) ),
			$bucket,
			null,
			'success',
			array(
				'folders_count'   => count( $valid_orphans ),
				'files_count'     => $total_files,
				'bytes_reclaimed' => $total_bytes,
				'folders'         => $valid_orphans,
			)
		);

		// Remove all deleted folders from scan cache.
		foreach ( $valid_orphans as $p ) {
			$this->remove_folder_from_scan_state( $p );
		}

		return array(
			'success'         => true,
			'dry_run'         => false,
			'folders_count'   => count( $valid_orphans ),
			'files_count'     => $total_files,
			'bytes_reclaimed' => $total_bytes,
			'folders'         => $valid_orphans,
		);
	}

	/**
	 * Connect an orphaned or misconfigured folder to a WooCommerce order.
	 *
	 * Updates the `_idp_assets` meta key on the order and invalidates cache
	 * so the folder immediately reflects the Green [Connected] status in the UI.
	 *
	 * @param string $folder_prefix   R2 folder prefix to associate.
	 * @param int    $target_order_id Target WooCommerce Order ID.
	 * @return array{success: bool, order_id: int, folder_path: string, customer_name: string, previous_path: string}
	 *
	 * @throws \RuntimeException If order does not exist or save fails.
	 */
	public function remap_folder( string $folder_prefix, int $target_order_id ): array {
		if ( ! function_exists( 'wc_get_order' ) ) {
			throw new \RuntimeException( __( 'WooCommerce order functions are not available.', 'idta-r2-optimizer' ) );
		}

		$order = wc_get_order( $target_order_id );
		if ( ! $order instanceof \WC_Order ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: Order ID */
					__( 'WooCommerce Order #%d was not found.', 'idta-r2-optimizer' ),
					$target_order_id
				)
			);
		}

		$clean_path    = trim( $folder_prefix, '/' );
		$clean_prefix  = $clean_path . '/';
		$previous_path = (string) $order->get_meta( '_idp_assets', true );

		// Update order metadata.
		$order->update_meta_data( '_idp_assets', $clean_path );
		$order->save();

		// Invalidate order index cache.
		$this->indexer->invalidate_cache();

		// Log remapping in audit trail.
		$customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$this->logger->log(
			Audit_Logger::ACTION_REMAP_ORDER,
			$clean_prefix,
			'',
			$target_order_id,
			'success',
			array(
				'previous_path' => $previous_path,
				'new_path'      => $clean_path,
				'customer'      => $customer_name,
			)
		);

		// Update folder record in scan state transient so UI reflects connected status immediately.
		$this->update_folder_order_in_scan_state( $clean_prefix, $order );

		$admin_url = '';
		if ( method_exists( $order, 'get_edit_order_url' ) ) {
			$admin_url = $order->get_edit_order_url();
		} elseif ( function_exists( 'admin_url' ) ) {
			$admin_url = admin_url( 'post.php?post=' . $target_order_id . '&action=edit' );
		}

		return array(
			'success'       => true,
			'order_id'      => $target_order_id,
			'folder_path'   => $clean_path,
			'customer_name' => $customer_name ?: __( 'Guest', 'idta-r2-optimizer' ),
			'admin_url'     => $admin_url,
			'previous_path' => $previous_path,
		);
	}

	/**
	 * Helper: Remove a deleted folder from the cached scan progress transient.
	 *
	 * @param string $prefix Folder prefix to remove.
	 */
	private function remove_folder_from_scan_state( string $prefix ): void {
		$state = get_transient( Connection_Analyzer::PROGRESS_TRANSIENT );
		if ( ! is_array( $state ) || empty( $state['folders'] ) ) {
			return;
		}

		if ( isset( $state['folders'][ $prefix ] ) ) {
			unset( $state['folders'][ $prefix ] );

			// Recalculate metrics.
			$hydrated = array();
			foreach ( $state['folders'] as $f_data ) {
				$hydrated[] = new Folder_Record(
					$f_data['prefix'],
					$f_data['bucket'],
					Connection_Status::from( $f_data['status'] ),
					(int) $f_data['file_count'],
					(int) $f_data['total_size'],
					(string) $f_data['last_modified'],
					null,
					(array) ( $f_data['missing_files'] ?? array() ),
					(array) ( $f_data['files'] ?? array() )
				);
			}

			$state['metrics'] = $this->analyzer->calculate_metrics( $hydrated );
			set_transient( Connection_Analyzer::PROGRESS_TRANSIENT, $state, 86400 );
		}
	}

	/**
	 * Helper: Update a remapped folder in the cached scan progress transient.
	 *
	 * @param string    $prefix Folder prefix.
	 * @param \WC_Order $order  Target order.
	 */
	private function update_folder_order_in_scan_state( string $prefix, \WC_Order $order ): void {
		$state = get_transient( Connection_Analyzer::PROGRESS_TRANSIENT );
		if ( ! is_array( $state ) || empty( $state['folders'] ) || ! isset( $state['folders'][ $prefix ] ) ) {
			return;
		}

		$f_data = $state['folders'][ $prefix ];
		$files  = (array) ( $f_data['files'] ?? array() );

		// Re-analyze folder with new order attachment.
		$record = $this->analyzer->analyze_folder( (string) $f_data['bucket'], $f_data );
		$state['folders'][ $prefix ] = $record->to_array();

		// Recalculate metrics.
		$hydrated = array();
		foreach ( $state['folders'] as $row ) {
			$hydrated[] = new Folder_Record(
				$row['prefix'],
				$row['bucket'],
				Connection_Status::from( $row['status'] ),
				(int) $row['file_count'],
				(int) $row['total_size'],
				(string) $row['last_modified'],
				null,
				(array) ( $row['missing_files'] ?? array() ),
				(array) ( $row['files'] ?? array() )
			);
		}

		$state['metrics'] = $this->analyzer->calculate_metrics( $hydrated );
		set_transient( Connection_Analyzer::PROGRESS_TRANSIENT, $state, 86400 );
	}
}

