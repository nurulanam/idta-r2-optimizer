<?php
/**
 * IDTA PDF integration and Source-to-Bucket resolver.
 *
 * Interacts with companion plugin idta-pdf (\IDTA\PDF\Order_Data::sources())
 * to map order sources (_idp_order_from) to corresponding Cloudflare R2 buckets.
 *
 * @package IDTA\R2Optimizer
 */

declare( strict_types=1 );

namespace IDTA\R2Optimizer;

defined( 'ABSPATH' ) || exit;

/**
 * Handles source detection, bucket resolution, and asset file specifications.
 */
class Source_Mapper {

	/**
	 * Default fallback sources when idta-pdf is not active or unconfigured.
	 */
	public const DEFAULT_SOURCES = array(
		'idta' => 'https://idta-upload.shamim66ewu.workers.dev/files/',
		'idpa' => 'https://pub-1c2e77688359483ca692ff2d8369b41b.r2.dev/',
	);

	/**
	 * Canonical verification document filenames expected inside each order asset folder.
	 *
	 * Corresponds to \IDTA\PDF\Order_Data::ASSET_FILES.
	 */
	public const DEFAULT_ASSET_FILES = array(
		'passport_photo' => 'portrait.jpg',
		'license_front'  => 'license-front.jpg',
		'license_back'   => 'license-back.jpg',
		'signature'      => 'signature.png',
	);

	/**
	 * Check whether the companion IDTA PDF plugin is currently loaded.
	 *
	 * @return bool True if \IDTA\PDF\Order_Data is available.
	 */
	public function is_idta_pdf_active(): bool {
		return class_exists( '\IDTA\PDF\Order_Data' );
	}

	/**
	 * Retrieve all detected front-end order sources.
	 *
	 * Pulls live sources from \IDTA\PDF\Order_Data::sources() when available,
	 * falling back to database options or hardcoded defaults.
	 *
	 * @return array<string,string> Array of base URLs keyed by lowercase source slug (e.g. 'idta', 'idpa').
	 */
	public function get_detected_sources(): array {
		$sources = array();

		if ( $this->is_idta_pdf_active() && method_exists( '\IDTA\PDF\Order_Data', 'sources' ) ) {
			try {
				$sources = \IDTA\PDF\Order_Data::sources();
			} catch ( \Throwable ) {
				$sources = array();
			}
		}

		if ( empty( $sources ) ) {
			// Check database option stored by idta-pdf verification settings.
			$opt = get_option( 'idta_pdf_verification', array() );
			if ( is_array( $opt ) && ! empty( $opt['asset_sources'] ) && is_array( $opt['asset_sources'] ) ) {
				foreach ( $opt['asset_sources'] as $row ) {
					if ( ! empty( $row['source'] ) && ! empty( $row['url'] ) ) {
						$sources[ strtolower( trim( (string) $row['source'] ) ) ] = trailingslashit( trim( (string) $row['url'] ) );
					}
				}
			}
		}

		if ( empty( $sources ) ) {
			$sources = self::DEFAULT_SOURCES;
		}

		/**
		 * Filters the detected order sources in the R2 Optimizer.
		 *
		 * @param array<string,string> $sources Array of base URLs keyed by source identifier.
		 */
		return (array) apply_filters( 'idta_r2_detected_sources', $sources );
	}

	/**
	 * Resolve the Cloudflare R2 bucket name for a given order source.
	 *
	 * Precedence:
	 * 1. Explicit mapping configured in plugin settings (`source_buckets`).
	 * 2. Automatic heuristic resolution (e.g. known default 'idta' -> 'idta-html').
	 * 3. Default fallback bucket configured in plugin settings (`default_bucket`).
	 *
	 * @param string|null $source Source identifier string (e.g. 'idta', 'idpa').
	 * @return string Resolved R2 bucket name, or empty string if unresolvable.
	 */
	public function resolve_bucket_for_source( ?string $source ): string {
		$clean_source = strtolower( trim( (string) $source ) );
		$settings     = plugin()->settings();

		// 1. Check explicit manual mapping in settings.
		$source_buckets = $settings['source_buckets'] ?? array();
		if ( is_array( $source_buckets ) && ! empty( $source_buckets[ $clean_source ] ) ) {
			return trim( (string) $source_buckets[ $clean_source ] );
		}

		// 2. Automatic heuristic matching based on known sources and URLs.
		$heuristic = $this->guess_bucket_from_source( $clean_source );
		if ( '' !== $heuristic ) {
			return $heuristic;
		}

		// 3. Fallback to default bucket in settings.
		$default_bucket = (string) ( $settings['default_bucket'] ?? '' );
		if ( '' !== trim( $default_bucket ) ) {
			return trim( $default_bucket );
		}

		return '';
	}

	/**
	 * Resolve the R2 bucket for a WooCommerce order.
	 *
	 * Reads the `_idp_order_from` metadata from the order and resolves its bucket.
	 *
	 * @param \WC_Order|int $order WooCommerce order object or ID.
	 * @return string Resolved bucket name.
	 */
	public function resolve_bucket_for_order( \WC_Order|int $order ): string {
		if ( is_numeric( $order ) ) {
			$order_obj = wc_get_order( (int) $order );
			if ( ! $order_obj instanceof \WC_Order ) {
				return $this->resolve_bucket_for_source( null );
			}
			$order = $order_obj;
		}

		$source = (string) $order->get_meta( '_idp_order_from', true );

		return $this->resolve_bucket_for_source( $source );
	}

	/**
	 * Perform heuristic guess of bucket name based on source slug or URL.
	 *
	 * @param string $source Lowercase source slug.
	 * @return string Guessed bucket name, or empty string.
	 */
	private function guess_bucket_from_source( string $source ): string {
		if ( 'idta' === $source ) {
			// In the IDTA ecosystem, the primary idta worker writes to 'idta-html'.
			return 'idta-html';
		}

		$sources = $this->get_detected_sources();
		if ( isset( $sources[ $source ] ) ) {
			$url = $sources[ $source ];

			// Check for public R2 dev domain pattern: https://pub-<hash>.r2.dev/
			if ( str_contains( $url, '.r2.dev' ) ) {
				// Often named with the source slug prefix, e.g. 'idpa-uploads'.
				return $source . '-uploads';
			}

			// Check for Worker URL with bucket hint.
			if ( str_contains( $url, 'idta' ) ) {
				return 'idta-html';
			}
		}

		return '';
	}

	/**
	 * Retrieve current mapping table of all detected sources to their configured buckets.
	 *
	 * @return array<string, array{source: string, url: string, bucket: string, is_manual: bool}>
	 */
	public function get_source_bucket_mappings(): array {
		$sources  = $this->get_detected_sources();
		$settings = plugin()->settings();
		$manual   = $settings['source_buckets'] ?? array();
		if ( ! is_array( $manual ) ) {
			$manual = array();
		}

		$mappings = array();

		foreach ( $sources as $slug => $url ) {
			$is_manual = ! empty( $manual[ $slug ] );
			$bucket    = $this->resolve_bucket_for_source( $slug );

			$mappings[ $slug ] = array(
				'source'    => $slug,
				'url'       => $url,
				'bucket'    => $bucket,
				'is_manual' => $is_manual,
			);
		}

		return $mappings;
	}

	/**
	 * Save an explicit bucket mapping for a source slug.
	 *
	 * @param string $source Source slug (e.g. 'idta').
	 * @param string $bucket Target R2 bucket name.
	 * @return bool True on success.
	 */
	public function save_source_bucket_mapping( string $source, string $bucket ): bool {
		$clean_source = strtolower( trim( $source ) );
		$clean_bucket = trim( $bucket );

		$settings = plugin()->settings();
		$mappings = $settings['source_buckets'] ?? array();
		if ( ! is_array( $mappings ) ) {
			$mappings = array();
		}

		if ( '' === $clean_bucket ) {
			unset( $mappings[ $clean_source ] );
		} else {
			$mappings[ $clean_source ] = $clean_bucket;
		}

		$settings['source_buckets'] = $mappings;

		return plugin()->update_settings( $settings );
	}

	/**
	 * Retrieve list of canonical verification document filenames.
	 *
	 * @return array<string,string> Associative array of file keys to filenames.
	 */
	public function get_expected_asset_files(): array {
		$files = self::DEFAULT_ASSET_FILES;

		// Merge with idta-pdf if available.
		if ( $this->is_idta_pdf_active() && method_exists( '\IDTA\PDF\Order_Data', 'asset_filenames' ) ) {
			try {
				$pdf_files = \IDTA\PDF\Order_Data::asset_filenames();
				if ( is_array( $pdf_files ) && ! empty( $pdf_files ) ) {
					$files = $pdf_files;
				}
			} catch ( \Throwable ) {
				// Fallback to default files.
			}
		}

		/**
		 * Filters the expected verification document files inside an order folder.
		 *
		 * @param array<string,string> $files Associative array of asset keys to filenames.
		 */
		$filtered = (array) apply_filters( 'idta_r2_expected_asset_files', $files );

		// Deduplicate: ensure only one preview slot per unique canonical filename.
		$deduped = array();
		foreach ( $filtered as $slot => $filename ) {
			$lower_file = strtolower( trim( (string) $filename ) );
			if ( ! in_array( $lower_file, array_map( 'strtolower', $deduped ), true ) ) {
				$deduped[ $slot ] = $filename;
			}
		}

		return $deduped;
	}

	/**
	 * Validate a list of detected filenames against expected asset files.
	 *
	 * @param array<string> $detected_filenames List of filenames discovered inside the folder prefix.
	 * @return array{is_complete: bool, missing: string[], present: string[]} Validation result breakdown.
	 */
	public function validate_folder_assets( array $detected_filenames ): array {
		$expected = $this->get_expected_asset_files();

		// Normalize discovered filenames (strip leading paths, keep basename).
		$discovered_basenames = array();
		foreach ( $detected_filenames as $f ) {
			$discovered_basenames[] = strtolower( trim( basename( $f ) ) );
		}

		$missing = array();
		$present = array();

		foreach ( $expected as $key => $filename ) {
			$clean_name = strtolower( trim( $filename ) );
			if ( in_array( $clean_name, $discovered_basenames, true ) ) {
				$present[ $key ] = $filename;
			} else {
				$missing[ $key ] = $filename;
			}
		}

		return array(
			'is_complete' => empty( $missing ),
			'missing'     => $missing,
			'present'     => $present,
		);
	}
}

