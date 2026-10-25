<?php
/**
 * Folder record DTO representing an analyzed R2 folder.
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

namespace IDTA\R2Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable value object holding metrics and connection status for an R2 asset folder.
 */
final class Folder_Record {

	/**
	 * Full folder prefix in R2 (e.g. '2026/08/01/063701-213/').
	 *
	 * @var string
	 */
	public readonly string $prefix;

	/**
	 * Clean normalized folder path without trailing slash (e.g. '2026/08/01/063701-213').
	 *
	 * @var string
	 */
	public readonly string $path;

	/**
	 * R2 Bucket name.
	 *
	 * @var string
	 */
	public readonly string $bucket;

	/**
	 * Health and connection status (CONNECTED, ORPHANED, BROKEN).
	 *
	 * @var Connection_Status
	 */
	public readonly Connection_Status $status;

	/**
	 * Number of files detected inside this folder.
	 *
	 * @var int
	 */
	public readonly int $file_count;

	/**
	 * Total size of all files in bytes.
	 *
	 * @var int
	 */
	public readonly int $total_size;

	/**
	 * ISO 8601 timestamp of the most recently modified file in this folder.
	 *
	 * @var string
	 */
	public readonly string $last_modified;

	/**
	 * Linked WooCommerce order record, or null if orphaned.
	 *
	 * @var Order_Asset_Record|null
	 */
	public readonly ?Order_Asset_Record $order;

	/**
	 * List of required asset filenames that are missing in R2 (populated when status is BROKEN).
	 *
	 * @var array<string,string>
	 */
	public readonly array $missing_files;

	/**
	 * Discovered files metadata list.
	 *
	 * @var array<int, array{key: string, size: int, etag: string, last_modified: string}>
	 */
	public readonly array $files;

	/**
	 * Constructor.
	 *
	 * @param string                  $prefix        Folder prefix in R2.
	 * @param string                  $bucket        Bucket name.
	 * @param Connection_Status       $status        Connection health status.
	 * @param int                     $file_count    File count.
	 * @param int                     $total_size    Total size in bytes.
	 * @param string                  $last_modified Latest modification timestamp.
	 * @param Order_Asset_Record|null $order         Linked order if present.
	 * @param array<string,string>    $missing_files Missing asset filenames.
	 * @param array                   $files         List of file items.
	 */
	public function __construct(
		string $prefix,
		string $bucket,
		Connection_Status $status,
		int $file_count = 0,
		int $total_size = 0,
		string $last_modified = '',
		?Order_Asset_Record $order = null,
		array $missing_files = array(),
		array $files = array()
	) {
		$this->prefix        = rtrim( $prefix, '/' ) . '/';
		$this->path          = trim( $prefix, '/' );
		$this->bucket        = trim( $bucket );
		$this->status        = $status;
		$this->file_count    = max( 0, $file_count );
		$this->total_size    = max( 0, $total_size );
		$this->last_modified = trim( $last_modified );
		$this->order         = $order;
		$this->missing_files = $missing_files;
		$this->files         = $files;
	}

	/**
	 * Human-readable formatted byte size (e.g. '1.84 MB', '420 KB').
	 *
	 * @return string Formatted size.
	 */
	public function formatted_size(): string {
		if ( $this->total_size <= 0 ) {
			return '0 B';
		}

		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$bytes = max( $this->total_size, 0 );
		$pow   = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow   = min( $pow, count( $units ) - 1 );

		$bytes /= ( 1 << ( 10 * $pow ) );

		return round( $bytes, 2 ) . ' ' . $units[ $pow ];
	}

	/**
	 * Human-readable localized last modified date.
	 *
	 * @return string Formatted date string.
	 */
	public function formatted_last_modified(): string {
		if ( '' === $this->last_modified ) {
			return '';
		}

		$timestamp = strtotime( $this->last_modified );
		if ( false === $timestamp ) {
			return $this->last_modified;
		}

		$date_format = get_option( 'date_format', 'Y-m-d' );
		$time_format = get_option( 'time_format', 'H:i' );

		if ( function_exists( 'wp_date' ) ) {
			return wp_date( $date_format . ' ' . $time_format, $timestamp );
		}

		return gmdate( $date_format . ' ' . $time_format, $timestamp );
	}

	/**
	 * Serialize record to an associative array for JSON payloads.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'prefix'                  => $this->prefix,
			'path'                    => $this->path,
			'bucket'                  => $this->bucket,
			'status'                  => $this->status->value,
			'status_label'            => $this->status->label(),
			'status_color'            => $this->status->color(),
			'status_badge'            => $this->status->badge_class(),
			'file_count'              => $this->file_count,
			'total_size'              => $this->total_size,
			'formatted_size'          => $this->formatted_size(),
			'last_modified'           => $this->last_modified,
			'formatted_last_modified' => $this->formatted_last_modified(),
			'order'                   => $this->order?->to_array(),
			'missing_files'           => $this->missing_files,
			'files'                   => $this->files,
		);
	}
}

