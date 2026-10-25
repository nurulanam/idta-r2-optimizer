<?php
/**
 * Connection analyzer and folder health classification engine.
 *
 * Cross-references scanned R2 folder packages against indexed WooCommerce orders,
 * classifying each into Connected (Green), Orphaned (Red), or Incomplete (Amber),
 * and managing chunked background scan state.
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

namespace IDTA\R2Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Classifies R2 folders by connection status and tracks scan progress.
 */
class Connection_Analyzer {

	/**
	 * Transient key storing ongoing or completed scan progress and results.
	 */
	public const PROGRESS_TRANSIENT = 'idta_r2_scan_progress';

	/**
	 * R2 storage cost per gigabyte-month (Cloudflare standard $0.015/GB).
	 */
	public const R2_COST_PER_GB_MONTH = 0.015;

	/**
	 * Order indexer instance.
	 *
	 * @var Order_Indexer
	 */
	protected Order_Indexer $indexer;

	/**
	 * Folder scanner instance.
	 *
	 * @var Folder_Scanner
	 */
	protected Folder_Scanner $scanner;

	/**
	 * Source mapper instance.
	 *
	 * @var Source_Mapper
	 */
	protected Source_Mapper $mapper;

	/**
	 * Constructor.
	 *
	 * @param Order_Indexer|null  $indexer Order indexer instance.
	 * @param Folder_Scanner|null $scanner Folder scanner instance.
	 * @param Source_Mapper|null  $mapper  Source mapper instance.
	 */
	public function __construct(
		?Order_Indexer $indexer = null,
		?Folder_Scanner $scanner = null,
		?Source_Mapper $mapper = null
	) {
		$this->indexer = $indexer ?? new Order_Indexer();
		$this->scanner = $scanner ?? new Folder_Scanner();
		$this->mapper  = $mapper ?? plugin()->source_mapper();
	}

	/**
	 * Analyze a raw scanned folder array and construct a classified Folder_Record.
	 *
	 * @param string               $bucket      R2 bucket name.
	 * @param array<string, mixed> $raw_folder Scanned folder data.
	 * @return Folder_Record
	 */
	public function analyze_folder( string $bucket, array $raw_folder ): Folder_Record {
		$prefix        = (string) ( $raw_folder['prefix'] ?? '' );
		$path          = trim( str_replace( array( '\\/', '\\' ), '/', $prefix ), '/' );
		$files         = (array) ( $raw_folder['files'] ?? array() );
		$total_size    = (int) ( $raw_folder['total_size'] ?? 0 );
		$last_modified = (string) ( $raw_folder['last_modified'] ?? '' );

		// Check if an order in MySQL is linked to this folder path.
		$linked_order = $this->indexer->find_order_by_folder( $path );

		if ( null === $linked_order ) {
			// Red: Orphaned folder with files but no matching WooCommerce order.
			return new Folder_Record(
				$prefix,
				$bucket,
				Connection_Status::ORPHANED,
				count( $files ),
				$total_size,
				$last_modified,
				null,
				array(),
				$files
			);
		}

		// Order is linked — verify asset file completeness.
		$detected_names = array_map( static fn( $f ) => (string) ( $f['key'] ?? '' ), $files );
		$validation     = $this->mapper->validate_folder_assets( $detected_names );

		if ( $validation['is_complete'] ) {
			// Green: Connected with all required assets present.
			return new Folder_Record(
				$prefix,
				$bucket,
				Connection_Status::CONNECTED,
				count( $files ),
				$total_size,
				$last_modified,
				$linked_order,
				array(),
				$files
			);
		}

		// Amber: Incomplete / Broken — order exists, but required verification documents are missing.
		return new Folder_Record(
			$prefix,
			$bucket,
			Connection_Status::BROKEN,
			count( $files ),
			$total_size,
			$last_modified,
			$linked_order,
			$validation['missing'],
			$files
		);
	}

	/**
	 * Calculate storage and financial metrics across a collection of Folder_Records.
	 *
	 * @param array<Folder_Record> $records Analyzed folder records.
	 * @return array<string, mixed> Aggregated KPI metrics.
	 */
	public function calculate_metrics( array $records ): array {
		$total_folders   = count( $records );
		$connected_count = 0;
		$connected_size  = 0;
		$orphaned_count  = 0;
		$orphaned_size   = 0;
		$broken_count    = 0;
		$broken_size     = 0;
		$total_size      = 0;

		foreach ( $records as $record ) {
			$total_size += $record->total_size;

			match ( $record->status ) {
				Connection_Status::CONNECTED => ( function () use ( &$connected_count, &$connected_size, $record ) {
					$connected_count++;
					$connected_size += $record->total_size;
				} )(),
				Connection_Status::ORPHANED => ( function () use ( &$orphaned_count, &$orphaned_size, $record ) {
					$orphaned_count++;
					$orphaned_size += $record->total_size;
				} )(),
				Connection_Status::BROKEN => ( function () use ( &$broken_count, &$broken_size, $record ) {
					$broken_count++;
					$broken_size += $record->total_size;
				} )(),
			};
		}

		// Calculate estimated monthly reclaimable storage cost in USD.
		$orphaned_gb      = $orphaned_size / ( 1024 * 1024 * 1024 );
		$monthly_savings  = round( $orphaned_gb * self::R2_COST_PER_GB_MONTH, 4 );

		return array(
			'total_folders'             => $total_folders,
			'total_size'                => $total_size,
			'formatted_total_size'      => size_format( $total_size, 2 ),
			'connected_count'           => $connected_count,
			'connected_size'            => $connected_size,
			'formatted_connected_size'  => size_format( $connected_size, 2 ),
			'orphaned_count'            => $orphaned_count,
			'orphaned_size'             => $orphaned_size,
			'formatted_orphaned_size'   => size_format( $orphaned_size, 2 ),
			'broken_count'              => $broken_count,
			'broken_size'               => $broken_size,
			'formatted_broken_size'     => size_format( $broken_size, 2 ),
			'estimated_monthly_savings' => $monthly_savings,
		);
	}

	/**
	 * Execute one chunked batch step of an R2 bucket scan.
	 *
	 * Reads up to 1,000 objects, updates accumulated folder records, calculates metrics,
	 * and persists state to transients for live administrative progress reporting.
	 *
	 * @param string      $bucket        R2 bucket name.
	 * @param string      $prefix_filter Optional date prefix filter (e.g. '2026/08/').
	 * @param string|null $reset         If true, clears previous scan state.
	 * @return array<string, mixed> Current scan state.
	 */
	public function run_scan_step(
		string $bucket,
		string $prefix_filter = '',
		bool $reset = false
	): array {
		$state = $this->get_scan_progress();

		if ( $reset || empty( $state['bucket'] ) || $state['bucket'] !== $bucket || ( $state['prefix_filter'] ?? '' ) !== $prefix_filter ) {
			// Initialize fresh scan state.
			$state = array(
				'status'             => 'scanning',
				'bucket'             => $bucket,
				'prefix_filter'      => $prefix_filter,
				'continuation_token' => null,
				'folders'            => array(),
				'metrics'            => array(),
				'started_at'         => time(),
				'updated_at'         => time(),
				'is_complete'        => false,
			);
		}

		if ( $state['is_complete'] ) {
			return $state;
		}

		// Ensure order index is fresh when starting a new scan or resetting.
		if ( $reset ) {
			$this->indexer->invalidate_cache();
			$this->indexer->get_index( true );
		} else {
			$this->indexer->get_index();
		}

		try {
			$batch = $this->scanner->scan_batch(
				$bucket,
				$prefix_filter,
				$state['continuation_token']
			);

			$existing_folders = $state['folders'];

			// Analyze each folder in the batch and merge.
			foreach ( $batch['folders'] as $prefix => $raw_folder ) {
				$record = $this->analyze_folder( $bucket, $raw_folder );
				$existing_folders[ $prefix ] = $record->to_array();
			}

			$state['folders']            = $existing_folders;
			$state['continuation_token'] = $batch['next_token'];
			$state['is_complete']        = ! $batch['is_truncated'];
			$state['status']             = $batch['is_truncated'] ? 'scanning' : 'complete';
			$state['updated_at']         = time();

			// Recalculate metrics from current folder array.
			$hydrated_records = array();
			foreach ( $existing_folders as $f_data ) {
				$hydrated_records[] = new Folder_Record(
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

			$state['metrics'] = $this->calculate_metrics( $hydrated_records );

			set_transient( self::PROGRESS_TRANSIENT, $state, 86400 );

			return $state;
		} catch ( \Throwable $e ) {
			$state['status']        = 'error';
			$state['error_message'] = $e->getMessage();
			$state['updated_at']    = time();

			set_transient( self::PROGRESS_TRANSIENT, $state, 86400 );

			return $state;
		}
	}

	/**
	 * Retrieve current scan progress state from transient.
	 *
	 * @return array<string, mixed>
	 */
	public function get_scan_progress(): array {
		$state = get_transient( self::PROGRESS_TRANSIENT );
		if ( ! is_array( $state ) ) {
			return array(
				'status'             => 'idle',
				'bucket'             => '',
				'prefix_filter'      => '',
				'continuation_token' => null,
				'folders'            => array(),
				'metrics'            => array(),
				'started_at'         => 0,
				'updated_at'         => 0,
				'is_complete'        => false,
			);
		}

		return $state;
	}

	/**
	 * Cancel or reset the current scan.
	 */
	public function reset_scan(): void {
		delete_transient( self::PROGRESS_TRANSIENT );
	}
}

