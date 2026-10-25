<?php
/**
 * Cloudflare R2 folder prefix scanner and traversal engine.
 *
 * Traverses dated folder structures (YYYY/MM/DD/HHMMSS-XXX/) inside R2 buckets,
 * grouping objects into cohesive folder asset packages with file metrics.
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

namespace IDTA\R2Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Traverses R2 object hierarchies and groups files into asset folder packages.
 */
class Folder_Scanner {

	/**
	 * R2 REST client instance.
	 *
	 * @var R2_Client
	 */
	protected R2_Client $client;

	/**
	 * Constructor.
	 *
	 * @param R2_Client|null $client Optional client instance; defaults to plugin container.
	 */
	public function __construct( ?R2_Client $client = null ) {
		$this->client = $client ?? plugin()->r2();
	}

	/**
	 * Discover all top-level year prefixes in a bucket for filtering (e.g. ['2026', '2025']).
	 *
	 * @param string $bucket R2 bucket name.
	 * @return array<string> List of years.
	 */
	public function get_available_years( string $bucket ): array {
		try {
			$res   = $this->client->list_objects( $bucket, '', '/' );
			$years = array();

			foreach ( $res['prefixes'] as $prefix ) {
				$clean = trim( $prefix, '/' );
				if ( preg_match( '/^\d{4}$/', $clean ) ) {
					$years[] = $clean;
				}
			}

			rsort( $years );

			return $years;
		} catch ( \Throwable ) {
			return array();
		}
	}

	/**
	 * Discover all month prefixes under a given year (e.g. ['01', '02', ..., '12']).
	 *
	 * @param string $bucket R2 bucket name.
	 * @param string $year   4-digit year.
	 * @return array<string> List of months.
	 */
	public function get_available_months( string $bucket, string $year ): array {
		$clean_year = trim( $year, '/' );
		if ( ! preg_match( '/^\d{4}$/', $clean_year ) ) {
			return array();
		}

		try {
			$res    = $this->client->list_objects( $bucket, $clean_year . '/', '/' );
			$months = array();

			foreach ( $res['prefixes'] as $prefix ) {
				// e.g. 2026/08/ -> 08
				$parts = explode( '/', trim( $prefix, '/' ) );
				$month = end( $parts );
				if ( is_string( $month ) && preg_match( '/^\d{2}$/', $month ) ) {
					$months[] = $month;
				}
			}

			sort( $months );

			return $months;
		} catch ( \Throwable ) {
			return array();
		}
	}

	/**
	 * Scan a batch of objects from R2 and group them into folder structures.
	 *
	 * Utilizes high-performance flat pagination (up to 1,000 objects per S3 call),
	 * extracting unique folder paths (YYYY/MM/DD/session-id/) and calculating metrics.
	 *
	 * @param string      $bucket             Bucket name.
	 * @param string      $prefix_filter      Optional prefix constraint (e.g. '2026/08/').
	 * @param string|null $continuation_token Token for next batch.
	 * @param int         $max_keys           Keys per page (default 1000).
	 * @return array{folders: array<string, array{prefix: string, path: string, files: array, total_size: int, last_modified: string}>, is_truncated: bool, next_token: ?string}
	 */
	public function scan_batch(
		string $bucket,
		string $prefix_filter = '',
		?string $continuation_token = null,
		int $max_keys = 1000
	): array {
		// Use empty delimiter to pull raw objects in bulk.
		$result = $this->client->list_objects(
			$bucket,
			$prefix_filter,
			'', // No delimiter for recursive object streaming
			$continuation_token,
			$max_keys
		);

		$folders = array();

		foreach ( $result['objects'] as $obj ) {
			$key = $obj['key'];

			// Extract standard folder prefix (e.g. '2026/08/01/063701-213/').
			$folder_prefix = $this->extract_folder_prefix( $key );
			if ( null === $folder_prefix ) {
				continue;
			}

			if ( ! isset( $folders[ $folder_prefix ] ) ) {
				$folders[ $folder_prefix ] = array(
					'prefix'        => $folder_prefix,
					'path'          => trim( $folder_prefix, '/' ),
					'files'         => array(),
					'total_size'    => 0,
					'last_modified' => '',
				);
			}

			$folders[ $folder_prefix ]['files'][]     = $obj;
			$folders[ $folder_prefix ]['total_size'] += (int) $obj['size'];

			// Update latest modified timestamp.
			$obj_time = (string) $obj['last_modified'];
			if (
				'' === $folders[ $folder_prefix ]['last_modified'] ||
				strtotime( $obj_time ) > strtotime( $folders[ $folder_prefix ]['last_modified'] )
			) {
				$folders[ $folder_prefix ]['last_modified'] = $obj_time;
			}
		}

		return array(
			'folders'      => $folders,
			'is_truncated' => $result['is_truncated'],
			'next_token'   => $result['next_token'],
		);
	}

	/**
	 * Retrieve all objects and metrics for a specific single folder prefix.
	 *
	 * @param string $bucket        Bucket name.
	 * @param string $folder_prefix Folder prefix (e.g. '2026/08/01/063701-213/').
	 * @return array{prefix: string, path: string, files: array, total_size: int, last_modified: string}
	 */
	public function scan_single_folder( string $bucket, string $folder_prefix ): array {
		$clean_prefix = rtrim( $folder_prefix, '/' ) . '/';
		$result       = $this->client->list_objects( $bucket, $clean_prefix, '', null, 100 );

		$files         = $result['objects'];
		$total_size    = 0;
		$last_modified = '';

		foreach ( $files as $obj ) {
			$total_size += (int) $obj['size'];
			$obj_time    = (string) $obj['last_modified'];

			if ( '' === $last_modified || strtotime( $obj_time ) > strtotime( $last_modified ) ) {
				$last_modified = $obj_time;
			}
		}

		return array(
			'prefix'        => $clean_prefix,
			'path'          => trim( $clean_prefix, '/' ),
			'files'         => $files,
			'total_size'    => $total_size,
			'last_modified' => $last_modified,
		);
	}

	/**
	 * Extract the canonical folder prefix from an object key.
	 *
	 * Handles standard dated folder formats:
	 *   '2026/08/01/063701-213/portrait.jpg' -> '2026/08/01/063701-213/'
	 *
	 * Also handles custom arbitrary depths by returning the directory containing the file.
	 *
	 * @param string $key Full S3 object key.
	 * @return string|null Folder prefix or null if root level object.
	 */
	public function extract_folder_prefix( string $key ): ?string {
		// Pattern 1: Standard YYYY/MM/DD/session-id/
		if ( preg_match( '#^(\d{4}/\d{2}/\d{2}/[^/]+/)#', $key, $matches ) ) {
			return $matches[1];
		}

		// Pattern 2: Generic directory prefix
		$last_slash = strrpos( $key, '/' );
		if ( false !== $last_slash && $last_slash > 0 ) {
			return substr( $key, 0, $last_slash + 1 );
		}

		return null;
	}
}

