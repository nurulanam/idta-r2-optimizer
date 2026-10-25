<?php
/**
 * WooCommerce order asset metadata indexer.
 *
 * Scans MySQL order storage (both WooCommerce HPOS tables and legacy wp_postmeta)
 * to build a fast indexed lookup table of orders mapped to their R2 asset folders.
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

namespace IDTA\R2Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Indexes orders having `_idp_assets` meta populated.
 */
class Order_Indexer {

	/**
	 * Transient key storing the cached order index.
	 */
	public const TRANSIENT_KEY = 'idta_r2_order_index';

	/**
	 * Cache TTL in seconds (1 hour).
	 */
	public const CACHE_TTL = 3600;

	/**
	 * In-memory index cache.
	 *
	 * @var array<string, Order_Asset_Record>|null
	 */
	private ?array $memory_index = null;

	/**
	 * Multi-order index cache for folders referenced by more than one order.
	 *
	 * @var array<string, array<Order_Asset_Record>>|null
	 */
	private ?array $multi_index = null;

	/**
	 * Check whether WooCommerce High-Performance Order Storage (HPOS) is active.
	 *
	 * @return bool
	 */
	public function is_hpos_enabled(): bool {
		if (
			class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) &&
			method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' )
		) {
			return (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		return false;
	}

	/**
	 * Retrieve all indexed orders mapped by normalized asset folder path.
	 *
	 * @param bool $force_refresh Whether to bypass transient cache and query database directly.
	 * @return array<string, Order_Asset_Record> Map of folder path => Order_Asset_Record.
	 */
	public function get_index( bool $force_refresh = false ): array {
		if ( ! $force_refresh && null !== $this->memory_index ) {
			return $this->memory_index;
		}

		if ( ! $force_refresh ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				$this->memory_index = $this->hydrate_records( $cached );
				return $this->memory_index;
			}
		}

		$raw_rows = $this->query_orders_with_assets();

		$index       = array();
		$multi_index = array();
		$for_cache   = array();

		foreach ( $raw_rows as $row ) {
			$folder_raw = (string) ( $row['asset_folder'] ?? '' );
			$folder     = trim( str_replace( array( '\\/', '\\' ), '/', $folder_raw ), '/' );

			if ( '' === $folder ) {
				continue;
			}

			$record = new Order_Asset_Record(
				(int) ( $row['order_id'] ?? 0 ),
				(string) ( $row['status'] ?? '' ),
				(string) ( $row['customer_name'] ?? '' ),
				(string) ( $row['billing_email'] ?? '' ),
				(string) ( $row['order_from'] ?? 'idta' ),
				$folder,
				(string) ( $row['date_created_gmt'] ?? '' )
			);

			// Track in multi-index array.
			if ( ! isset( $multi_index[ $folder ] ) ) {
				$multi_index[ $folder ] = array();
			}
			$multi_index[ $folder ][] = $record;

			// Store latest in primary index (first occurrence since query is ORDER BY id DESC).
			if ( ! isset( $index[ $folder ] ) ) {
				$index[ $folder ]     = $record;
				$for_cache[ $folder ] = $record->to_array();
			}
		}

		$this->memory_index = $index;
		$this->multi_index  = $multi_index;

		set_transient( self::TRANSIENT_KEY, $for_cache, self::CACHE_TTL );

		return $this->memory_index;
	}

	/**
	 * Query the database for orders having `_idp_assets` meta populated.
	 *
	 * @return array<int, array<string, mixed>> Raw query rows.
	 */
	protected function query_orders_with_assets(): array {
		global $wpdb;

		if ( $this->is_hpos_enabled() ) {
			return $this->query_hpos_orders( $wpdb );
		}

		return $this->query_legacy_orders( $wpdb );
	}

	/**
	 * Execute HPOS custom orders table query.
	 *
	 * @param \wpdb $wpdb Database instance.
	 * @return array<int, array<string, mixed>>
	 */
	private function query_hpos_orders( \wpdb $wpdb ): array {
		$orders_table = $wpdb->prefix . 'wc_orders';
		$meta_table   = $wpdb->prefix . 'wc_orders_meta';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			"SELECT 
				m.order_id,
				m.meta_value AS asset_folder,
				o.status,
				o.date_created_gmt,
				o.billing_email,
				CONCAT(COALESCE(fn.meta_value, ''), ' ', COALESCE(ln.meta_value, '')) AS customer_name,
				src.meta_value AS order_from
			FROM {$meta_table} m
			INNER JOIN {$orders_table} o ON o.id = m.order_id
			LEFT JOIN {$meta_table} fn ON fn.order_id = m.order_id AND fn.meta_key = '_billing_first_name'
			LEFT JOIN {$meta_table} ln ON ln.order_id = m.order_id AND ln.meta_key = '_billing_last_name'
			LEFT JOIN {$meta_table} src ON src.order_id = m.order_id AND src.meta_key = '_idp_order_from'
			WHERE m.meta_key = '_idp_assets'
			  AND m.meta_value IS NOT NULL
			  AND m.meta_value != ''
			ORDER BY o.id DESC",
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Execute legacy posts/postmeta table query.
	 *
	 * @param \wpdb $wpdb Database instance.
	 * @return array<int, array<string, mixed>>
	 */
	private function query_legacy_orders( \wpdb $wpdb ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			"SELECT 
				m.post_id AS order_id,
				m.meta_value AS asset_folder,
				p.post_status AS status,
				p.post_date_gmt AS date_created_gmt,
				em.meta_value AS billing_email,
				CONCAT(COALESCE(fn.meta_value, ''), ' ', COALESCE(ln.meta_value, '')) AS customer_name,
				src.meta_value AS order_from
			FROM {$wpdb->postmeta} m
			INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'shop_order'
			LEFT JOIN {$wpdb->postmeta} em ON em.post_id = m.post_id AND em.meta_key = '_billing_email'
			LEFT JOIN {$wpdb->postmeta} fn ON fn.post_id = m.post_id AND fn.meta_key = '_billing_first_name'
			LEFT JOIN {$wpdb->postmeta} ln ON ln.post_id = m.post_id AND ln.meta_key = '_billing_last_name'
			LEFT JOIN {$wpdb->postmeta} src ON src.post_id = m.post_id AND src.meta_key = '_idp_order_from'
			WHERE m.meta_key = '_idp_assets'
			  AND m.meta_value IS NOT NULL
			  AND m.meta_value != ''
			ORDER BY p.ID DESC",
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Find an order linked to a specific folder path.
	 *
	 * @param string $folder_path   R2 prefix or folder path.
	 * @param bool   $force_refresh Whether to force refresh index from database.
	 * @return Order_Asset_Record|null Order record if found, or null.
	 */
	public function find_order_by_folder( string $folder_path, bool $force_refresh = false ): ?Order_Asset_Record {
		$clean_folder = trim( str_replace( array( '\\/', '\\' ), '/', $folder_path ), '/' );
		$index        = $this->get_index( $force_refresh );

		return $index[ $clean_folder ] ?? null;
	}

	/**
	 * Invalidate order asset index cache.
	 */
	public function invalidate_cache(): void {
		$this->memory_index = null;
		$this->multi_index  = null;
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Hydrate serialized arrays back into Order_Asset_Record objects.
	 *
	 * @param array<string, array<string, mixed>> $cached Cached array.
	 * @return array<string, Order_Asset_Record>
	 */
	private function hydrate_records( array $cached ): array {
		$records = array();

		foreach ( $cached as $folder => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}

			$records[ $folder ] = new Order_Asset_Record(
				(int) ( $data['order_id'] ?? 0 ),
				(string) ( $data['status'] ?? '' ),
				(string) ( $data['customer_name'] ?? '' ),
				(string) ( $data['billing_email'] ?? '' ),
				(string) ( $data['order_from'] ?? 'idta' ),
				(string) ( $data['asset_folder'] ?? $folder ),
				(string) ( $data['date_created'] ?? '' )
			);
		}

		return $records;
	}
}

